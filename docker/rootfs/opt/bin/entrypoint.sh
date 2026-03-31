#!/bin/bash

set -e

if [ "${DEBUG_MODE}" == "true" ]; then
	cat > /usr/local/etc/php/conf.d/99-debug.ini << 'EOF'
display_errors = On
display_startup_errors = On
EOF
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
cat > /etc/caddy/Caddyfile << EOF
{
	auto_https off
	frankenphp
}

:80 {
	root * /www/public
	encode zstd gzip
	php_server {
		index index.php
	}
	file_server

	@health path /health
	header @health Cache-Control "no-store"
}
EOF

exec "$@"
