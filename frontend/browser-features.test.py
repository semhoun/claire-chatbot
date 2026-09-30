"""Synthetic browser checks against built bundles; no backend or provider calls.

Run after npm run build with a loopback static server at CLAIRE_TEST_URL
(default http://127.0.0.1:4179), e.g. the webapp-testing with_server.py helper.
Requires Playwright Python and Chromium; artifacts stay under /tmp/kilo.
"""

import json
import os
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import expect, sync_playwright


BASE = os.environ.get("CLAIRE_TEST_URL", "http://127.0.0.1:4179").rstrip("/")
assert urlparse(BASE).hostname in {"127.0.0.1", "localhost"}
ROOT = Path(__file__).resolve().parent.parent
MANIFEST = json.loads((ROOT / "public/build/.vite/manifest.json").read_text())
CSS = MANIFEST["frontend/main.ts"]["css"][0]
ARTIFACTS = Path("/tmp/kilo/claire-browser-artifacts")
ARTIFACTS.mkdir(exist_ok=True)

INIT = """
const token = 'header.' + btoa(JSON.stringify({aud: 'session', exp: Date.now()/1000+3600})) + '.signature';
sessionStorage.setItem('claire_session_token', JSON.stringify({token, expiresAt: Date.now()+3600000}));
window.streams = [];
window.EventSource = class {
  listeners = new Map();
  constructor() { window.streams.push(this); }
  addEventListener(type, fn) { this.listeners.set(type, fn); }
  close() { this.closed = true; }
};
window.emit = (type, payload) => window.streams.at(-1).listeners.get(type)?.({data: JSON.stringify(payload)});
"""


def message(identity, text, sent=False):
    return {"id": identity, "message": text, "sent": sent,
            "time": "2026-09-30T10:00:00Z", "files": [], "toolsCall": []}


def run_case(browser, mode, viewport):
    print(f"RUN {mode} {viewport}", flush=True)
    context = browser.new_context(viewport=viewport)
    context.add_init_script(INIT)
    page = context.new_page()
    errors = []
    page.on("pageerror", lambda error: errors.append(str(error)))
    requests = []
    config = {
        "mode": mode, "baseUrl": BASE, "acceptedExt": ".txt", "threadId": "thread-1", "sessionId": "session-1",
        "brainInfo": {"name": "Claire", "description": "Assistant", "avatar": "",
                      "theme": {"preset": "cyberpunk", "tokens": {}, "variants": {}}},
        "currentBrain": "claire", "brains": [], "comfyuiEnabled": False, "workflows": [], "currentWorkflow": "",
        "longTermMemoryEnabled": False, "layoutMode": "full", "audioAvailable": False, "audioEnabled": False,
        "audioAutoGenerate": False, "audioDictationMode": "review", "audioVoice": "", "audioVoices": [],
        "audioTranscriptionModel": "", "audioSpeechModel": "", "audioMaxRecordingSeconds": 60,
        "user": None, "refreshBeforeExpire": 120, "refreshMinInterval": 30,
        "stopAvailable": True, "semanticMemoryAvailable": True, "semanticMemoryEnabled": False,
    }

    def route_request(route):
        request = route.request
        url = urlparse(request.url)
        assert url.netloc == urlparse(BASE).netloc, "Unexpected external request"
        path = url.path
        if path.startswith("/public/"):
            asset = (ROOT / path.lstrip("/")).resolve()
            assert asset.is_relative_to(ROOT / "public")
            route.fulfill(path=asset, content_type="text/css" if asset.suffix == ".css" else "text/javascript")
        elif path == "/image/background.png":
            route.fulfill(status=204)
        elif path == "/synthetic":
            if mode == "normal":
                body = (f'<link rel="stylesheet" href="/public/build/{CSS}">'
                        '<div id="claire-vue-app"></div>'
                        '<script id="claire-page-data" type="application/json">'
                        + json.dumps({"page": "app", "baseUrl": BASE})
                        + '</script><script type="module" src="/public/build/js/app.js"></script>')
            else:
                body = ('<div id="host">Host page</div><script src="/public/js/embed.js"></script>'
                        '<script>window.claireEmbed({baseUrl: location.origin});</script>')
            route.fulfill(content_type="text/html", body='<meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8">' + body)
        elif path in {"/", "/embed"}:
            route.fulfill(json=config)
        elif path == "/auth/resource-token":
            route.fulfill(json={"token": "synthetic", "expiresAt": 9999999999})
        elif path == "/brain/stop":
            requests.append((path, request.post_data_json))
            route.fulfill(json={"status": "running"})
        elif path == "/config/semantic-memory":
            requests.append((path, request.post_data_json))
            route.fulfill(status=204)
        elif path == "/config/semantic-memory/clear":
            requests.append((path, None))
            route.fulfill(status=204)
        elif path.endswith("/count"):
            route.fulfill(body="0")
        else:
            raise AssertionError(f"Unexpected backend request: {path}")

    page.route("**/*", route_request)
    page.goto(BASE + "/synthetic")
    page.wait_for_load_state("networkidle")
    try:
        page.wait_for_function("window.streams.length === 1")
    except Exception:
        print({"errors": errors, "body": page.locator("body").inner_text()}, flush=True)
        raise
    if mode == "embed":
        assert page.locator("claire-chat-widget").evaluate("el => !!el.shadowRoot")
        page.get_by_role("button", name="Ouvrir la conversation avec Claire").click()

    def emit(kind, payload):
        page.evaluate("([kind, payload]) => window.emit(kind, payload)", [kind, payload])

    emit("chat.snapshot", {"responding": True, "activeMessageId": "welcome", "messages": []})
    expect(page.get_by_role("button", name="Arrêter la génération")).to_have_count(0)
    emit("chat.snapshot", {"responding": True, "activeMessageId": "generation-1", "submissionId": "submission-1",
                           "generationStatus": "running", "messages": [message("user-1", "Question", True), message("generation-1", "Réponse partielle")]})
    expect(page.get_by_role("button", name="Envoyer", exact=True)).to_have_count(0)
    expect(page.locator('.claire-chat-input__actions button[aria-label="Arrêter la génération"]')).to_be_visible()
    page.get_by_role("button", name="Arrêter la génération").focus()
    page.keyboard.press("Enter")
    expect(page.get_by_role("button", name="Arrêt demandé", exact=True)).to_be_disabled()
    expect(page.get_by_role("textbox", name="Votre message")).to_be_disabled()
    page.screenshot(path=str(ARTIFACTS / f"{mode}-{viewport['width']}-pending.png"))
    assert requests == [("/brain/stop", {"threadId": "thread-1", "generationId": "generation-1"})]
    if mode == "embed":
        page.get_by_role("button", name="Réduire la conversation avec Claire").click()
        page.get_by_role("button", name="Ouvrir la conversation avec Claire").click()
        assert len(requests) == 1
    emit("chat.assistant.stopped", {"messageId": "generation-1", "submissionId": "submission-1",
                                    "turnStatus": "stopped", "generationStatus": "stopped"})
    expect(page.get_by_role("textbox", name="Votre message")).to_be_enabled()
    expect(page.get_by_text("Réponse partielle", exact=True)).to_be_visible()
    expect(page.get_by_role("button", name="Envoyer", exact=True)).to_be_visible()

    # Reload uses only the durable snapshot, not prior in-memory stop state.
    page.reload(wait_until="networkidle")
    page.wait_for_function("window.streams.length === 1")
    emit("chat.snapshot", {"responding": False, "activeMessageId": None, "generationMessageId": "generation-1",
                           "submissionId": "submission-1", "turnStatus": "stopped", "generationStatus": "stopped",
                           "messages": [message("user-1", "Question", True), message("generation-1", "Réponse partielle")]})
    if mode == "embed":
        page.get_by_role("button", name="Ouvrir la conversation avec Claire").click()
    expect(page.get_by_role("textbox", name="Votre message")).to_be_enabled()
    expect(page.get_by_text("Réponse partielle", exact=True)).to_be_visible()
    page.get_by_role("button", name="Préférences" if mode == "embed" else "Ouvrir le menu", exact=True).click()
    preference = page.get_by_role("switch", name="Mémoire sémantique", exact=True)
    expect(preference).not_to_be_checked()
    preference.click()
    expect(preference).to_be_checked()
    preference.click()
    expect(preference).not_to_be_checked()
    page.get_by_role("button", name="Effacer la mémoire sémantique", exact=True).click()
    expect(page.get_by_role("dialog")).to_contain_text("profil textuel et les conversations restent inchangés")
    page.get_by_role("button", name="Effacer", exact=True).click()
    expect(page.get_by_role("dialog")).to_have_count(0)
    assert requests[1:] == [("/config/semantic-memory", {"enabled": True}),
                            ("/config/semantic-memory", {"enabled": False}),
                            ("/config/semantic-memory/clear", None)]
    page.screenshot(path=str(ARTIFACTS / f"{mode}-{viewport['width']}-preferences.png"))
    assert page.evaluate("document.documentElement.scrollWidth <= innerWidth")
    assert not errors, errors
    context.close()
    print(f"PASS {mode} {viewport['width']}x{viewport['height']}")


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(headless=True)
    try:
        for mode in ("normal", "embed"):
            for viewport in ({"width": 1440, "height": 1000}, {"width": 390, "height": 844}):
                run_case(browser, mode, viewport)
    finally:
        browser.close()
