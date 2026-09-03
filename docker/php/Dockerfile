FROM php:8.4-fpm-alpine

# Argumentos para casar UID/GID do container com o host (evita permission denied
# ao editar arquivos do bind mount em Linux). No Mac/Windows Docker Desktop cuida.
ARG WWWUSER=1000
ARG WWWGROUP=1000

# Dependências do sistema
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

# Extensões PHP
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
      sockets

# Extensões PECL
RUN pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Ajusta o usuário www-data (uid 82 no Alpine) para o UID/GID do host.
RUN if [ "${WWWUSER}" != "82" ]; then \
      usermod -u ${WWWUSER} www-data && \
      groupmod -g ${WWWGROUP} www-data; \
    fi

# Config do PHP para produção segura (reforçada mais adiante nas fases)
COPY docker/php/php.ini /usr/local/etc/php/conf.d/sislac.ini

WORKDIR /var/www/html

# Volume próprio para o vendor evita conflito com bind mount em Linux
RUN mkdir -p /var/www/html/vendor \
 && chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000
CMD ["php-fpm", "-F"]
