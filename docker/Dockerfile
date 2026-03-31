FROM dunglas/frankenphp:1-php8.4-trixie AS base

ENV DEBIAN_FRONTEND=noninteractive
ENV TERM=linux
ENV FRANKENPHP_CONFIG="worker /www/public/index.php"

RUN apt-get update -y \
  && apt-get install -y --no-install-recommends curl wget ca-certificates bash dos2unix git unzip gpg \
    iputils-ping iproute2 less vim \
    locales aspell-fr \
    imagemagick graphicsmagick  exiftran \
    supervisor \
  \
# Dev packages \
  && apt-get install -y libmemcached-dev zlib1g-dev libmagickwand-dev \
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
  && install-php-extensions bcmath bz2 curl exif gd gmp intl mbstring opcache pcntl pdo_mysql pdo_pgsql pdo_sqlite redis sodium xsl zip \
  && pecl install opentelemetry \
  && docker-php-ext-enable opentelemetry \
  && pecl install imagick memcache \
  && docker-php-ext-enable imagick memcache \
  \
  \
# Install composer \
  && cd /tmp \
  && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php composer-setup.php \
  && install composer.phar /usr/bin/composer \
  \
  \
# Clean \
  && apt-get -y purge libmemcached-dev zlib1g-dev libmagickwand-dev \
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

RUN mkdir -p /rootfs/data \
  && chown -R www-data:www-data /rootfs/data /rootfs/www \
  && chmod +x /rootfs/opt/bin/*

FROM base AS final
COPY --from=build /rootfs /

# Encoding fix
ENV LANG=en_US.UTF-8
ENV LANGUAGE=en_US:en
ENV LC_ALL=en_US.UTF-8

# Default env values
ENV DEBUG_MODE="false"
ENV ENABLE_ACCESS_LOGS="true"
ENV ENABLE_LETSENCRYPT="false"
ENV ACME_EMAIL=""

# DATA dir
ENV DATA_PATH=/data
VOLUME ["/data"]
WORKDIR "/www"

#Expose port
EXPOSE 80 443

# Docker starting params
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
ENTRYPOINT ["/opt/bin/entrypoint.sh"]
HEALTHCHECK --start-period=60s --interval=15s --timeout=5s --retries=3 CMD curl --fail http://localhost/health || exit 1
