#!/bin/bash

set -e

if [ ! -f "/etc/apache2/conf-docker/20-htdocs.conf" ]; then
	cat > /etc/apache2/conf-docker/15-location.conf << EOF
ServerName ${SERVER_NAME}
ServerAdmin ${SERVER_ADMIN}
EOF

	if [ "${DEBUG_MODE}" == "true" ]; then
		cat >  /etc/php/8.4/fpm/conf.d/99-debug.ini << 'EOF'
display_errors = On
display_startup_errors = On
EOF
	fi
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

rm -rf /var/spool/fcron/root
/usr/bin/fcrontab -n /etc/fcron/fcrontab-root root

rm -f /var/run/apache2.pid 
rm -f /var/run/php-fpm.sock

exec "$@"
