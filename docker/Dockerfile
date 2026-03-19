FROM debian:trixie-slim AS base

ENV DEBIAN_FRONTEND=noninteractive
ENV TERM=linux

RUN apt-get update -y \
  && apt-get install -y --no-install-recommends curl ca-certificates vim bash dos2unix wget curl git unzip gpg \
  && apt-get install -y apt-transport-https lsb-release ca-certificates \
  && wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg \
  && sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list' \
  && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
  && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_24.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
  && apt-get update \
  \
  && apt-get install -y supervisor \
  \
  && apt-get install -y nodejs \
  \
  && apt-get install -y \
    apache2 apache2-utils \
    imagemagick graphicsmagick exiftran \
    locales aspell-fr \
    php8.4-fpm php8.4 php8.4-cli php8.4-common php8.4-curl php8.4-gd php8.4-imagick \
    php8.4-mysql php8.4-sqlite3 php8.4-pgsql \
    php8.4-xsl php8.4-intl php8.4-mbstring php8.4-zip php8.4-bcmath php8.4-xml php8.4-redis php8.4-memcache \
    php8.4-dev php8.4-apcu php8.4-gmp php8.4-bz2 \
  \
  && apt-get install -y \
    autoconf build-essential docbook docbook-xsl docbook-xml docbook-utils manpages-dev \
  \
  # Fix for added by debfault
  && apt-get purge -y php7* php8.0* php8.1* php8.2* php8.3*\
  && ln -s /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm \
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
  && echo "extension=opentelemetry.so" > /etc/php/8.4/cli/conf.d/20-opentelemetry.ini \
  && echo "extension=opentelemetry.so" > /etc/php/8.4/fpm/conf.d/20-opentelemetry.ini \
  \
  \
# Install composer
  && cd /tmp \
  && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php composer-setup.php \
  && install composer.phar /usr/bin/composer \
  \
  \
# Configure Apache
  && a2enmod proxy_fcgi rewrite deflate alias actions headers \
  && a2disconf other-vhosts-access-log.conf \
  \
  \
# Install fcron
  && curl -L -o /tmp/fcron.tar.gz https://github.com/yo8192/fcron/archive/refs/tags/ver3_4_0.tar.gz \
  && mkdir -p /tmp/fcron \
  && cd /tmp/fcron \
  && tar xzf /tmp/fcron.tar.gz --strip 1 \
  && autoconf \
  && ./configure \
    --prefix=/usr \
    --sysconfdir=/etc \
    --localstatedir=/var \
    --with-sysfcrontab=no \
    --with-answer-all \
    --without-sendmail \
    --with-boot-install=no \
    --with-systemdsystemunitdir=no \
  && make \
  && make install \
  \
  \
# Clean
  && apt-get -y purge php8.4-dev \
    autoconf build-essential docbook docbook-xsl docbook-xml docbook-utils manpages-dev \
  && apt-get -y autoremove \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* /var/log/apache2

RUN mkdir -p /var/www \
  && chown -R www-data:www-data /var/www \
  && usermod www-data -s /bin/bash -d /var/www


FROM base AS build

COPY . /rootfs/www
RUN mv /rootfs/www/docker/rootfs/* /rootfs/ \
    && rm -rf /rootfs/www/docker

RUN cd /rootfs/www \
    && composer \
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
EXPOSE 80

# Docker starting params
CMD ["/usr/bin/supervisord","-c","/etc/supervisord.conf"]
ENTRYPOINT ["/opt/bin/entrypoint.sh"]
HEALTHCHECK --start-period=60s --interval=15s --timeout=5s --retries=3 CMD curl --fail http://localhost/health || exit 1
