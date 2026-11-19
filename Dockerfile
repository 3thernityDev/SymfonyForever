# Stage 1: Build TypeScript + Three.js production bundle with Vite
FROM node:22-alpine AS frontend
WORKDIR /app

COPY package.json package-lock.json tsconfig.json vite.config.ts ./
RUN npm ci
COPY assets ./assets
RUN npm run build

# Stage 2: FrankenPHP (PHP 8.4 + Caddy) production runtime for SymfonyForever
FROM dunglas/frankenphp:1-php8.4 AS backend
WORKDIR /app

RUN install-php-extensions \
    gd \
    pdo_sqlite \
    intl \
    zip \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV DATABASE_URL="sqlite:////data/symfonyforever.db"
ENV LOCK_DSN=flock
ENV SERVER_NAME=:8080

# Install PHP dependencies first for better Docker layer caching
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-autoloader --no-interaction --no-scripts

# Copy Symfony application source & compiled Vite assets
COPY . .
COPY --from=frontend /app/public/build ./public/build

# Generate authoritative autoloader + vendor/autoload_runtime.php (via symfony/runtime plugin)
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# Production PHP tuning
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    printf "opcache.enable=1\nopcache.memory_consumption=128\nopcache.max_accelerated_files=20000\nopcache.validate_timestamps=0\nmemory_limit=256M\n" \
    > "$PHP_INI_DIR/conf.d/symfonyforever.ini"

# Pre-generate La Réunion map binary/PNGs & warm Symfony prod cache
RUN mkdir -p /data /app/var/cache /app/var/log /app/var/map && \
    php bin/console app:world:generate-map --env=prod --no-interaction && \
    php bin/console cache:clear --env=prod --no-warmup && \
    php bin/console cache:warmup --env=prod

# Install Caddyfile and Linux-normalized entrypoint
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
