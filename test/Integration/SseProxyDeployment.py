#!/usr/bin/env python3
"""Opt-in: python3 test/Integration/SseProxyDeployment.py [local-image].

Uses only disposable containers, no published ports or deployment volumes.
The image must already exist and provide FrankenPHP, PHP and Python 3.
"""

import configparser
import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import uuid


ROOT = Path(__file__).resolve().parents[2]


def request(host, port, path, method="GET", headers=None):
    connection = http.client.HTTPConnection(host, port, timeout=3)
    try:
        connection.request(method, path, headers=headers or {})
        response = connection.getresponse()
        return response.status, dict(response.getheaders()), response.read()
    finally:
        connection.close()


def inside():
    # Exercise startup secret generation separately, never application initialization.
    entrypoint = Path("/repo/docker/rootfs/opt/bin/entrypoint.sh").read_text()
    startup = entrypoint.split('cp /opt/conf/php/', 1)[0]
    probe = startup + '\nphp -r \'echo getenv("SSE_INTERNAL_SECRET");\'\n' * 2
    clean_env = dict(os.environ)
    clean_env.pop("SSE_INTERNAL_SECRET", None)
    first = subprocess.check_output(["bash", "-c", probe], env=clean_env).decode()
    second = subprocess.check_output(
        ["bash", "-c", probe], env=dict(clean_env, SSE_INTERNAL_SECRET="must-be-replaced")
    ).decode()
    for value in (first, second):
        assert len(value) == 128 and set(value) <= set("0123456789abcdef")
        assert value[:64] == value[64:], "Child processes did not inherit the same secret"
    assert first != second, "Container startup did not rotate its secret"
    failed_php = Path("/artifacts/failed-php")
    failed_php.mkdir()
    (failed_php / "php").write_text("#!/bin/sh\nexit 1\n")
    (failed_php / "php").chmod(0o755)
    failed = subprocess.run(
        ["bash", "-c", startup + '\nprintf "continued"'],
        env=dict(clean_env, PATH=str(failed_php) + ":" + os.environ["PATH"]),
        capture_output=True,
    )
    assert failed.returncode != 0 and failed.stdout == b"", "Secret generation failure must abort startup"
    section = entrypoint.split("TRACING_BLOCK=''", 1)[1].split(
        "# Configure queue workers count", 1
    )[0]
    Path("/opt/www/public").mkdir(parents=True, exist_ok=True)
    Path("/opt/www/public/index.php").write_text(
        '<?php header("X-Claire-Auth: response-jwt-canary"); '
        'header("X-Claire-Token: renewed-jwt-canary"); '
        'header("X-Claire-Minitoken: mini-jwt-canary"); echo "PUBLIC_FIXTURE";'
    )
    Path("/opt/www/public/sse-internal.php").write_text(
        '<?php header("Content-Type: application/json"); '
        'echo json_encode(["private" => true, "uri" => $_SERVER["REQUEST_URI"], '
        '"address" => $_SERVER["SERVER_ADDR"], "port" => $_SERVER["SERVER_PORT"], '
        '"remote" => $_SERVER["REMOTE_ADDR"]]);'
    )
    env = dict(os.environ, DEBUG_MODE="false", ENABLE_LETSENCRYPT="false",
               OTEL_EXPORTER_OTLP_ENDPOINT="", FRANKENPHP_CONFIG="",
               OTEL_PHP_AUTOLOAD_ENABLED="false", GOMAXPROCS="2")
    env["BASE_URL"] = "http://example.test" + os.environ["SSE_PROXY_TEST_BASE_PATH"]
    for tracing in ("http://127.0.0.1:4318", ""):
        env["OTEL_EXPORTER_OTLP_ENDPOINT"] = tracing
        subprocess.run(["bash", "-c", "TRACING_BLOCK=''" + section], env=env, check=True)
        subprocess.run(["frankenphp", "validate", "--config", "/etc/caddy/Caddyfile"],
                       env=env, check=True)
    adapted = subprocess.check_output(
        ["frankenphp", "adapt", "--config", "/etc/caddy/Caddyfile"], env=env
    )
    Path("/artifacts/adapted.json").write_bytes(adapted)
    # A delayed upstream makes proxy buffering observable, without running the daemon.
    import http.server
    import threading

    class Upstream(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            if "fail-upstream" in self.path:
                self.connection.shutdown(socket.SHUT_RDWR)
                self.connection.close()
                return
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Upstream-URI-Preserved", str(
                self.path == os.environ["SSE_PROXY_TEST_BASE_PATH"]
                + "/brain/stream?token=capability-canary&token=second-canary"
            ))
            self.end_headers()
            self.wfile.write(b"data: FIRST\n\n")
            self.wfile.flush()
            time.sleep(1)
            self.wfile.write(b"data: LAST\n\n")

        def log_message(self, *_args):
            pass

    upstream = http.server.ThreadingHTTPServer(("127.0.0.1", 8081), Upstream)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    with open("/artifacts/caddy.log", "wb") as log:
        server = subprocess.Popen(
            ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"],
            env=env, stdout=log, stderr=log,
        )
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError("Caddy exited; see artifacts")
                try:
                    if request("127.0.0.1", 80, "/health")[0] == 200:
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Caddy startup timed out")
            for operation in ("open", "snapshot", "close"):
                path = "/" + operation + "?context=a%2Fb&x=1&x=2"
                status, _, body = request("127.0.0.1", 8082, path, "POST")
                assert status == 200, (path, status, body)
                assert json.loads(body) == {"private": True, "uri": path,
                                           "address": "127.0.0.1", "port": "8082",
                                           "remote": "127.0.0.1"}, body
                status, _, body = request("127.0.0.1", 8082, path, "POST", {"Host": "spoofed.test:80"})
                assert b'"private"' not in body, (status, body)
            for method, path in (("GET", "/open"), ("POST", "/other"),
                                 ("POST", "/sse-internal.php")):
                assert request("127.0.0.1", 8082, path, method)[0] == 404
            Path("/artifacts/ready").touch()
            # Host-side checks run outside this container's network namespace.
            for _ in range(300):
                if Path("/artifacts/finished").exists():
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("External checks timed out")
        finally:
            server.terminate()
            server.wait(timeout=10)
            upstream.shutdown()


def outside(base_path):
    image = sys.argv[1] if len(sys.argv) > 1 else "claire-claire:latest"
    subprocess.run(["docker", "image", "inspect", image], check=True, stdout=subprocess.DEVNULL)
    supervisor = configparser.ConfigParser()
    supervisor.read(ROOT / "docker/rootfs/etc/supervisor/conf.d/sse.conf")
    config = supervisor["program:sse"]
    assert config["command"] == "/usr/local/bin/php /opt/www/bin/sse"
    assert config["user"] == "www-data"
    assert config["autorestart"] == "true" and config["stopsignal"] == "TERM"
    assert config["stopasgroup"] == "true" and config["killasgroup"] == "true"
    assert 0 < int(config["stopwaitsecs"]) <= 30
    assert config["stdout_logfile"] == "/dev/fd/1" and config["stderr_logfile"] == "/dev/fd/2"
    assert config["stdout_logfile_maxbytes"] == config["stderr_logfile_maxbytes"] == "0"
    artifacts = Path(tempfile.mkdtemp(prefix="sse-proxy-", dir="/tmp/kilo"))
    name = "claire-sse-validation-" + uuid.uuid4().hex[:12]
    print("Artifacts:", artifacts, flush=True)
    command = ["docker", "run", "--rm", "--pull=never", "--name", name,
               "--network", "bridge", "--no-healthcheck", "--cpus", "2",
               "--env", "SSE_PROXY_TEST_BASE_PATH=" + base_path,
               "--mount", f"type=bind,src={ROOT},dst=/repo,readonly",
               "--mount", f"type=bind,src={artifacts},dst=/artifacts",
               "--entrypoint", "python3", image,
               "/repo/test/Integration/SseProxyDeployment.py", "--inside"]
    # The fixture is scoped to this test run and always removed in finally.
    with open(artifacts / "container.log", "wb") as log:
        process = subprocess.Popen(command, stdout=log, stderr=log)
        try:
            for _ in range(300):
                if process.poll() is not None:
                    raise RuntimeError("Fixture failed; see " + str(artifacts))
                if (artifacts / "ready").exists():
                    break
                time.sleep(0.1)
            else:
                raise RuntimeError("Fixture startup timed out")
            host = subprocess.check_output([
                "docker", "inspect", "--format", "{{.NetworkSettings.Networks.bridge.IPAddress}}", name
            ], text=True).strip()
            for port in (8081, 8082):
                try:
                    with socket.create_connection((host, port), timeout=2):
                        raise AssertionError(f"Private port {port} exposed externally")
                except (ConnectionRefusedError, TimeoutError):
                    pass
            paths = ["/sse-internal.php", "/sse-internal.php/open",
                     "/sse-internal.php%2fopen", "/%73se-internal.php",
                     "/sse-internal%2ephp", "//sse-internal.php",
                     "/x/../sse-internal.php", "/x/%2e%2e/sse-internal.php",
                     "/sse-internal.php/../sse-internal.php", "/sse-internal.php;foo",
                     "/%2573se-internal.php", "/open", "/snapshot", "/close"]
            for path in paths:
                for spoof in (host, "127.0.0.1:8082", "localhost:8082"):
                    status, _, body = request(host, 80, path, "POST", {
                        "Host": spoof, "X-Original-URL": "/open",
                        "X-Rewrite-URL": "/sse-internal.php", "X-Forwarded-Host": "127.0.0.1:8082",
                        "X-Claire-Sse-Secret": "private-secret-canary",
                    })
                    assert b'"private"' not in body and b'<?php' not in body, (path, spoof, status, body)
            for path in ("/", base_path + "/brain/stream/", base_path + "/brain/stream/extra", "/other/brain/stream"):
                assert request(host, 80, path)[2] == b"PUBLIC_FIXTURE", path
            if base_path:
                assert request(host, 80, "/brain/stream")[2] == b"PUBLIC_FIXTURE"
            connection = http.client.HTTPConnection(host, 80, timeout=3)
            started = time.monotonic()
            connection.request("GET", base_path + "/brain/stream?token=capability-canary&token=second-canary", headers={
                "Accept-Encoding": "gzip, zstd", "X-Claire-Auth": "jwt-canary",
                "X-Claire-Sse-Secret": "private-secret-canary",
                "Referer": "https://example.test/brain/stream?token=referer-canary",
            })
            response = connection.getresponse()
            assert response.status == 200 and response.getheader("Content-Encoding") is None
            assert response.getheader("Content-Type") == "text/event-stream"
            assert response.getheader("X-Upstream-URI-Preserved") == "True"
            assert response.readline() == b"data: FIRST\n"
            assert time.monotonic() - started < 0.8, "SSE first event was buffered"
            assert b"data: LAST" in response.read()
            connection.close()
            status, _, _ = request(host, 80, base_path + "/brain/stream?token=error-canary&fail-upstream=1",
                                   headers={"X-Claire-Auth": "error-jwt-canary"})
            assert status == 502, status
            (artifacts / "finished").touch()
            assert process.wait(timeout=15) == 0
            logs = (artifacts / "caddy.log").read_text()
            assert '"request"' in logs, "No access logs collected"
            for secret in ("capability-canary", "second-canary", "jwt-canary", "private-secret-canary",
                            "referer-canary", "response-jwt-canary", "error-canary", "error-jwt-canary",
                            "renewed-jwt-canary", "mini-jwt-canary"):
                assert secret not in logs, "Credential leaked in Caddy log: " + secret
            print("PASS: Caddy validation, original URI, public isolation, loopback ports, streaming, logs; base=" + repr(base_path))
        finally:
            subprocess.run(["docker", "rm", "--force", name], stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=False)
            process.wait(timeout=15)


if __name__ == "__main__":
    if "--inside" in sys.argv:
        inside()
    else:
        for base_path in ("", "/claire"):
            outside(base_path)
        assert (ROOT / "bin/sse").is_file(), "Proxy checks passed, but Supervisor daemon entrypoint is missing"
        print("PASS: Supervisor command and shutdown configuration")
