# syntax=docker/dockerfile:1.7

FROM dunglas/frankenphp:builder-php8.5-trixie AS frankenphp-builder

COPY --from=caddy:builder /usr/bin/xcaddy /usr/bin/xcaddy

# CGO must be enabled to build FrankenPHP.
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
        --with github.com/caddyserver/transform-encoder

FROM node:24-bookworm-slim AS frontend-build

WORKDIR /src

COPY package.json package-lock.json tsconfig.json vite.config.ts vite.embed.config.ts ./
COPY frontend ./frontend
COPY public/css ./public/css

RUN --mount=type=cache,target=/root/.npm \
    npm ci \
    && npm run build

FROM dunglas/frankenphp:php8.5-trixie AS base

COPY --from=frankenphp-builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp

ENV DEBIAN_FRONTEND=noninteractive \
    TERM=linux

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        locales \
        supervisor \
        vim \
        wget \
    && sed \
        -e 's/# fr_FR.UTF-8 UTF-8/fr_FR.UTF-8 UTF-8/' \
        -e 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/' \
        -i /etc/locale.gen \
    && locale-gen en_US.UTF-8 \
    && install-php-extensions \
        bcmath \
        bz2 \
        curl \
        exif \
        gd \
        gmp \
        intl \
        mbstring \
        opcache \
        opentelemetry \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        redis \
        sodium \
        xsl \
        zip \
    && EMBER_LATEST_TAG=$(curl -fsSL \
        https://api.github.com/repos/alexandre-daubois/ember/releases/latest \
        | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' \
        | head -1) \
    && EMBER_RELEASE_URL="https://github.com/alexandre-daubois/ember/releases/download/${EMBER_LATEST_TAG}" \
    && wget -q -O /tmp/ember.tar.gz \
        "${EMBER_RELEASE_URL}/ember_${EMBER_LATEST_TAG#v}_linux_amd64.tar.gz" \
    && tar -xzf /tmp/ember.tar.gz -C /tmp \
    && install /tmp/ember /usr/local/bin/ember \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN mkdir -p /var/www \
    && chown www-data:www-data /var/www \
    && usermod www-data -s /bin/bash -d /var/www

FROM base AS php-dependencies

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/root/.composer/cache \
    COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

COPY src ./src
COPY migrations ./migrations

RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
    --no-dev \
    --optimize \
    --apcu

FROM base AS final

ARG VERSION=dev

COPY docker/rootfs /
COPY LICENSE composer.json composer.lock console /opt/www/
COPY config /opt/www/config
COPY migrations /opt/www/migrations
COPY public /opt/www/public
COPY src /opt/www/src
COPY tmpl /opt/www/tmpl
COPY --from=php-dependencies /app/vendor /opt/www/vendor
COPY --from=frontend-build /src/public/build /opt/www/public/build
COPY --from=frontend-build /src/public/js/embed.js /opt/www/public/js/embed.js

ENV LANG=en_US.UTF-8 \
    LANGUAGE=en_US:en \
    LC_ALL=en_US.UTF-8 \
    DEBUG_MODE=false \
    ENABLE_LETSENCRYPT=false \
    ACME_EMAIL="" \
    QUEUE_WORKERS=8 \
    CLAIRE_APP_VERSION=${VERSION} \
    DATA_PATH=/opt/data \
    ADDONS_PATH=/opt/addons

RUN mkdir -p \
        /config/caddy \
        /data/caddy \
        /etc/caddy \
        /usr/share/caddy \
        /opt/data \
        /opt/addons \
        /opt/www/var/cache \
        /opt/www/var/tmp \
    && touch /etc/caddy/Caddyfile \
    && chown -R www-data:www-data \
        /config/caddy \
        /data/caddy \
        /etc/caddy \
        /usr/share/caddy \
        /opt/data \
        /opt/addons \
        /opt/www/var \
    && setcap -r /usr/local/bin/frankenphp

VOLUME ["/opt/data", "/opt/addons"]
WORKDIR /opt/www

EXPOSE 80 443

HEALTHCHECK --start-period=30s --interval=15s --timeout=5s --retries=3 \
    CMD curl --fail http://localhost/health || exit 1

ENTRYPOINT ["/opt/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
