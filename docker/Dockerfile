FROM dunglas/frankenphp:builder-php8.5-trixie AS frankenphp-builder

# Copy xcaddy in the builder image
COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

# CGO must be enabled to build FrankenPHP
RUN CGO_ENABLED=1 \
    XCADDY_SETCAP=1 \
    XCADDY_GO_BUILD_FLAGS="-ldflags='-w -s' -tags=nobadger,nomysql,nopgx" \
    CGO_CFLAGS=$(php-config --includes) \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    xcaddy build \
        --output /usr/local/bin/frankenphp \
        --with github.com/dunglas/frankenphp=./ \
        --with github.com/dunglas/frankenphp/caddy=./caddy/ \
        --with github.com/dunglas/caddy-cbrotli \
        # Mercure and Vulcain are included in the official build, but feel free to remove them
        --with github.com/dunglas/mercure/caddy \
        --with github.com/dunglas/vulcain/caddy \
        # Add extra Caddy modules here
        --with github.com/caddyserver/transform-encoder

FROM dunglas/frankenphp:php8.5-trixie AS base

COPY --from=frankenphp-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

ENV DEBIAN_FRONTEND=noninteractive
ENV TERM=linux

RUN apt-get update -y \
  && apt-get install -y --no-install-recommends curl wget ca-certificates bash dos2unix git unzip gpg \
    iputils-ping iproute2 less vim \
    locales aspell-fr \
    redis \
    supervisor \
  \
# Dev packages \
  && apt-get install -y zlib1g-dev libmagickwand-dev \
  \
  \
# Configure locales \
  && sed \
    -e 's/# fr_FR.UTF-8 UTF-8/fr_FR.UTF-8 UTF-8/' \
    -e 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/' \
    -i /etc/locale.gen \
  && /usr/sbin/locale-gen en_US.UTF-8 \
  \
  \
# Install php addons \
  && install-php-extensions bcmath bz2 curl exif gd gmp intl mbstring opcache pcntl \
    pdo_mysql pdo_pgsql pdo_sqlite redis \
    sodium xsl zip opentelemetry \
  \
  \
# Install composer \
  && cd /tmp \
  && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php composer-setup.php \
  && install composer.phar /usr/bin/composer \
  \
  \
# Install ember \
    && EMBER_LATEST_TAG=$(curl -fsSL https://api.github.com/repos/alexandre-daubois/ember/releases/latest | grep '"tag_name"' | head -1 | sed 's/.*"tag_name": *"//;s/".*//') \
    && curl -fsSL -o /tmp/ember.tar.gz https://github.com/alexandre-daubois/ember/releases/download/${EMBER_LATEST_TAG}/ember_${EMBER_LATEST_TAG#v}_linux_amd64.tar.gz \
    && tar -xzf  /tmp/ember.tar.gz -C /tmp \
    && install ember /usr/local/bin/ \
    \
    \
# Clean \
  && apt-get -y autoremove \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN mkdir -p /var/www \
  && chown -R www-data:www-data /var/www \
  && usermod www-data -s /bin/bash -d /var/www

FROM base AS build

COPY . /rootfs/www
RUN mv /rootfs/www/docker/rootfs/* /rootfs/ \
    && rm -rf /rootfs/www/docker

RUN cd /rootfs/www \
    && COMPOSER_ALLOW_SUPERUSER=1 php /usr/bin/composer \
        --no-cache \
        --optimize-autoloader \
        --apcu-autoloader \
        --no-dev \
        install

FROM base AS final
COPY --from=build /rootfs /

# Encoding fix
ENV LANG=en_US.UTF-8
ENV LANGUAGE=en_US:en
ENV LC_ALL=en_US.UTF-8

# Default env values
ENV DEBUG_MODE="false"
ENV ENABLE_LETSENCRYPT="false"
ENV ACME_EMAIL=""
ENV QUEUE_WORKERS="2"
ENV FRANKENPHP_CONFIG="worker /www/public/index.php"

# Manage permissions
RUN mkdir -p \
    /config/caddy \
    /data/caddy \
    /etc/caddy \
    /usr/share/caddy \
  && touch /etc/caddy/Caddyfile \
  && chown -R www-data:www-data \
    /config/caddy \
    /data/caddy \
    /etc/caddy \
    /usr/share/caddy \
  && setcap -r /usr/local/bin/frankenphp \
## Application directories \
  && mkdir -p /opt/data \
  && chown -R www-data:www-data /opt/data /www \
  && chmod +x /opt/bin/*

# DATA dir
ENV DATA_PATH=/opt/data
VOLUME ["/opt/data"]
WORKDIR "/www"

#Expose port
EXPOSE 80 443

# Docker starting params
CMD ["/usr/bin/supervisord","-c","/etc/supervisor/supervisord.conf"]
ENTRYPOINT ["/opt/bin/entrypoint.sh"]
HEALTHCHECK --start-period=30s --interval=15s --timeout=5s --retries=3 CMD curl --fail http://localhost/health || exit 1
