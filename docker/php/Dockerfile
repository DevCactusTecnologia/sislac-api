FROM php:8.4-fpm-alpine

ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN apk add --no-cache \
      bash \
      curl \
      git \
      icu-dev \
      libpq-dev \
      libzip-dev \
      oniguruma-dev \
      freetype-dev \
      libjpeg-turbo-dev \
      libpng-dev \
      linux-headers \
      postgresql-client \
      shadow \
      su-exec \
      tzdata \
      $PHPIZE_DEPS \
 && cp /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime \
 && echo "America/Sao_Paulo" > /etc/timezone

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
      pdo_pgsql \
      pgsql \
      intl \
      bcmath \
      gd \
      zip \
      opcache \
      pcntl \
      sockets \
 && apk del $PHPIZE_DEPS

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

RUN if [ "${WWWUSER}" != "82" ]; then \
      usermod -u ${WWWUSER} www-data && \
      groupmod -g ${WWWGROUP} www-data; \
    fi

COPY docker/php/php.ini /usr/local/etc/php/conf.d/sislac.ini

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/vendor \
 && chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000
CMD ["php-fpm", "-F"]
