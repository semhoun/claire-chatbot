#!/bin/bash

set -e

# One fresh container-local secret shared by FrankenPHP and the SSE daemon.
SSE_INTERNAL_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
export SSE_INTERNAL_SECRET

cp /opt/conf/php/*  "${PHP_INI_DIR}/conf.d/"
if [ "${DEBUG_MODE}" == "true" ]; then
  cp  "${PHP_INI_DIR}/php.ini-development"  "${PHP_INI_DIR}/php.ini"
  cat > "${PHP_INI_DIR}/conf.d/z99-debug.ini" << 'EOF'
display_errors = On
display_startup_errors = On

opcache.enable = Off
opcache.enable_cli = Off
EOF
else
  export FRANKENPHP_CONFIG="worker /opt/www/public/index.php"
  cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"
fi

# Check and create $VAR_PATH and subfolders
for dir in "${DATA_PATH}" "${DATA_PATH}/filer" "/opt/www/var/cache" "/opt/www/var/tmp"; do
  if [ ! -d "$dir" ]; then
    mkdir -p "$dir"
  fi
  chown www-data:www-data "$dir"
done
if [ ! -d "/opt/www/var/cache/proxy" ]; then
  cd /opt/www
  su www-data -c "./console app:generate-proxies"
fi

# Init addons if needed
if [ ! -d "${ADDONS_PATH}/agents" ]; then
  cp -R /opt/dist/addons/* "${ADDONS_PATH}/"
  chown -R www-data:www-data "${ADDONS_PATH}"
fi

TRACING_BLOCK=''
# Match the same base path used by stream capability validation without rewriting it.
BASE_PATH=$(php -r 'echo rtrim((string) parse_url(getenv("BASE_URL") ?: "", PHP_URL_PATH), "/");')
if [[ ! "$BASE_PATH" =~ ^(/[a-zA-Z0-9._~%-]+)*$ ]]; then
  printf '%s\n' 'BASE_URL contains an unsupported path' >&2
  exit 1
fi
if [ -n "${OTEL_EXPORTER_OTLP_ENDPOINT}" ]; then
TRACING_BLOCK='  tracing {
      span "{method} {http.request.uri.path}"
    }
    request_header X-Trace-Id {http.vars.trace_id}
'
fi
SITE_ADDRESS=':80'
CADDY_HTTPS_OPTIONS='  auto_https off'
if [ "${ENABLE_LETSENCRYPT}" = "true" ] && [ -n "${ACME_EMAIL}" ]; then
  SITE_ADDRESS="${SERVER_NAME}"
  CADDY_HTTPS_OPTIONS="  email ${ACME_EMAIL}"
fi
LOG_SKIP='log_skip /health'
if [ "${DEBUG_MODE}" == "true" ]; then
  LOG_SKIP=''
fi
cat > /etc/caddy/Caddyfile << EOF
{
  frankenphp
  order php_server before file_server
  metrics
  # Access-log filters do not apply to reverse-proxy error logs.
  log {
    output stderr
    format filter {
      wrap json
      fields {
        request>uri query {
          delete token
        }
        request>headers>X-Claire-Auth delete
        request>headers>X-Claire-Sse-Secret delete
        request>headers>Referer delete
        resp_headers>X-Claire-Auth delete
        resp_headers>X-Claire-Token delete
        resp_headers>X-Claire-Minitoken delete
      }
    }
  }
  ${CADDY_HTTPS_OPTIONS}
}

${SITE_ADDRESS} {
  root * /opt/www/public

  log {
    output stdout
    format filter {
      wrap json
      fields {
        request>uri query {
          delete token
        }
        request>headers>X-Claire-Auth delete
        request>headers>X-Claire-Sse-Secret delete
        request>headers>Referer delete
        resp_headers>X-Claire-Auth delete
        resp_headers>X-Claire-Token delete
        resp_headers>X-Claire-Minitoken delete
      }
    }
  }
  ${LOG_SKIP}

  route {
    @private path /sse-internal.php /sse-internal.php/*
    respond @private 404

    @stream path ${BASE_PATH}/brain/stream
    handle @stream {
      reverse_proxy 127.0.0.1:8081 {
        flush_interval -1
        transport http {
          compression off
          response_header_timeout 30s
        }
      }
    }
    handle {
      ${TRACING_BLOCK}
      encode zstd gzip
      php_server {
        index index.php
      }
      file_server
    }
  }
}

http://127.0.0.1:8082 {
  bind 127.0.0.1
  root * /opt/www/public
  route {
    @internal {
      method POST
      path /open /snapshot /close
    }
    handle @internal {
      rewrite * /sse-internal.php
      php_server {
        env SERVER_ADDR {http.request.local.host}
        env SERVER_PORT {http.request.local.port}
      }
    }
    respond 404
  }
}
EOF

# Configure queue workers count
sed -i "s/numprocs=1/numprocs=${QUEUE_WORKERS}/" /etc/supervisor/conf.d/queue_work.conf

exec "$@"
