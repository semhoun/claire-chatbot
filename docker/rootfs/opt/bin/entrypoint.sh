#!/bin/bash

set -e

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

TRACING_BLOCK=''
if [ -n "${OTEL_EXPORTER_OTLP_ENDPOINT}" ]; then
TRACING_BLOCK='  tracing {
      span "{method} {uri}"
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
cat > /etc/caddy/Caddyfile << EOF
{
  frankenphp
  order php_server before file_server
  metrics
  log {
    output stderr
  }
  ${CADDY_HTTPS_OPTIONS}
}

${SITE_ADDRESS} {
  root * /opt/www/public
  encode zstd gzip

  php_server {
    index index.php
  }
  file_server

  log {
    output stdout
    format formatted "{common_log}"
  }
  log_skip /health

  handle /* {
    ${TRACING_BLOCK}
  }
  handle /health {
      header Cache-Control "no-store"
  }
}
EOF

# Configure queue workers count
sed -i "s/numprocs=1/numprocs=${QUEUE_WORKERS:-1}/" /etc/supervisor/conf.d/php.conf

exec "$@"
