#!/bin/bash

set -e

cp /opt/conf/php/*  ${PHP_INI_DIR}/conf.d/
if [ "${DEBUG_MODE}" == "true" ]; then
  cp  ${PHP_INI_DIR}/php.ini-development  ${PHP_INI_DIR}/php.ini
  cat > ${PHP_INI_DIR}/conf.d/99-debug.ini << 'EOF'
display_errors = On
display_startup_errors = On
EOF
else
  cp ${PHP_INI_DIR}/php.ini-production ${PHP_INI_DIR}/php.ini
fi

# Check and create $VAR_PATH and subfolders
for dir in "${DATA_PATH}" "${DATA_PATH}/filer" "/www/var/cache" "/www/var/tmp"; do
  if [ ! -d "$dir" ]; then
    mkdir -p "$dir"
  fi
  chown www-data:www-data "$dir"
done

if [ ! -d "/www/var/cache/proxy" ]; then
  cd /www
  su www-data -c "./console app:generate-proxies"
fi

mkdir -p /etc/caddy
TRACING_BLOCK=''
if [ -n "${OTEL_EXPORTER_OTLP_ENDPOINT}" ]; then
TRACING_BLOCK='  tracing {
      span "{method} {uri}"
    }
    request_header X-Trace-Id {http.vars.trace_id}
'
fi
SITE_ADDRESS=':80'
CADDY_GLOBAL_OPTIONS=''
if [ "${ENABLE_LETSENCRYPT}" = "true" ]; then
  SITE_ADDRESS="${SERVER_NAME}"
  if [ -n "${ACME_EMAIL}" ]; then
    CADDY_GLOBAL_OPTIONS="  email ${ACME_EMAIL}
${CADDY_GLOBAL_OPTIONS}"
  fi
else
  CADDY_GLOBAL_OPTIONS="  auto_https off
${CADDY_GLOBAL_OPTIONS}"
fi
cat > /etc/caddy/Caddyfile << EOF
{
  frankenphp
  ${CADDY_GLOBAL_OPTIONS}
  log {
    output stderr
  }
}

${SITE_ADDRESS} {
  root * /www/public
  encode zstd gzip

  php_server {
    index index.php
  }
  file_server

  log {
    output stdout
    format formatted "{common_log}"
  }
  handle /.well-known/health {
  }
  handle /* {
${TRACING_BLOCK}
  }

  @health path /.well-known/health
  header @health Cache-Control "no-store"
}
EOF

# Configure queue workers count
sed -i "s/numprocs=1/numprocs=${QUEUE_WORKERS:-1}/" /etc/supervisor/conf.d/php.conf

exec "$@"
