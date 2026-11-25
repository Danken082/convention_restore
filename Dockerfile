# FROM php:8.4-apache

# RUN apt-get update && apt-get install -y \
#     git \
#     unzip \
#     libfreetype6-dev \
#     libjpeg62-turbo-dev \
#     libpng-dev \
#     libicu-dev \
#     libzip-dev \
#     libsqlite3-dev \
#     && docker-php-ext-configure gd \
#     --with-freetype \
#     --with-jpeg \
#     && docker-php-ext-install -j"$(nproc)" \
#     bcmath \
#     gd \
#     intl \
#     pcntl \
#     pdo_sqlite \
#     zip \
#     && a2enmod rewrite \
#     && rm -rf /var/lib/apt/lists/*

# COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WORKDIR /var/www/html

# COPY . .

# RUN composer install \
#     --no-dev \
#     --optimize-autoloader \
#     --no-interaction

# RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' \
#     /etc/apache2/sites-available/000-default.conf

# RUN printf '<Directory /var/www/html/public>\n\
#     AllowOverride All\n\
#     Require all granted\n\
#     </Directory>\n' \
#     > /etc/apache2/conf-available/laravel.conf \
#     && a2enconf laravel

# RUN mkdir -p \
#     storage/framework/cache \
#     storage/framework/sessions \
#     storage/framework/views \
#     storage/logs \
#     bootstrap/cache \
#     database \
#     && chown -R www-data:www-data \
#     storage \
#     bootstrap/cache \
#     database

# RUN touch database/database.sqlite \
#     && chown www-data:www-data database/database.sqlite

# RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
#     && sed -i 's/:80>/:10000>/' \
#     /etc/apache2/sites-available/000-default.conf

# EXPOSE 10000

# CMD ["apache2-foreground"]
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=4M\npost_max_size=4M\n' > "$PHP_INI_DIR/conf.d/uploads.ini" \
    && install-php-extensions gd intl pdo_pgsql pdo_sqlite zip

WORKDIR /app

FROM base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

COPY . .
RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    database \
    bootstrap/cache \
    && touch database/database.sqlite \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && APP_ENV=production \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    php artisan migrate --force

FROM base AS runtime

COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --chown=www-data:www-data Caddyfile /etc/frankenphp/Caddyfile

RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /config/caddy /data/caddy

ENV PORT=80 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CACHE_STORE=array \
    SESSION_DRIVER=file \
    QUEUE_CONNECTION=sync \
    DB_CONNECTION=pgsql \
    DB_SSLMODE=require \
    APP_URL=https://foursquareglobalyouthconferenceph.vercel.app \
    MAIL_MAILER=smtp \
    MAIL_SCHEME=smtp \
    MAIL_HOST=smtp.gmail.com \
    MAIL_PORT=587 \
    MAIL_FROM_NAME="${APP_NAME}" \
    GOOGLE_SHEETS_SPREADSHEET_ID=1Z-AL6I7OvSzpKkLGuXlMYDfMxMOfznh_-wiPl1LRjyk \
    GOOGLE_SHEETS_TAB_ID=0 \
    VITE_APP_NAME="${APP_NAME}"

USER www-data

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]