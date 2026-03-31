FROM dunglas/frankenphp:1-php8.4-trixie AS base

ENV DEBIAN_FRONTEND=noninteractive
ENV TERM=linux
ENV FRANKENPHP_CONFIG="worker /www/public/index.php"

RUN apt-get update -y \
  && apt-get install -y --no-install-recommends curl ca-certificates vim bash dos2unix wget git unzip gpg \
  && install-php-extensions bcmath bz2 curl exif gd gmp intl mbstring opcache pcntl pdo_mysql pdo_pgsql pdo_sqlite redis sodium xsl zip \
  && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
  && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
  && apt-get update \
  \
  && apt-get install -y supervisor \
  \
  && apt-get install -y nodejs \
  \
  && apt-get install -y \
    imagemagick graphicsmagick libmagickwand-dev exiftran \
    locales aspell-fr libmemcached-dev zlib1g-dev \
  \
  && apt-get install -y \
    autoconf build-essential manpages-dev \
  \
  # Fix for added by debfault
  && apt-get purge -y php7* php8.0* php8.1* php8.2* php8.3*\
  \
  \
# Configure locales
  && sed \
    -e 's/# fr_FR.UTF-8 UTF-8/fr_FR.UTF-8 UTF-8/' \
    -e 's/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/' \
    -i /etc/locale.gen \
  && /usr/sbin/locale-gen en_US.UTF-8 \
  \
  \
# Install OpenTelemetry
  && pecl install opentelemetry \
  && docker-php-ext-enable opentelemetry \
  && pecl install imagick memcache \
  && docker-php-ext-enable imagick memcache \
  \
  \
# Install composer
  && cd /tmp \
  && frankenphp php-cli -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && frankenphp php-cli composer-setup.php \
  && install composer.phar /usr/bin/composer \
  \
  \
# Clean
  && apt-get -y purge autoconf build-essential manpages-dev \
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
    && COMPOSER_ALLOW_SUPERUSER=1 frankenphp php-cli /usr/bin/composer \
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
ENV SERVER_NAME="www.docker.test"
ENV SERVER_ADMIN="webmaster@docker.test"
ENV DEBUG_MODE="false"

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
