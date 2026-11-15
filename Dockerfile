# syntax=docker/dockerfile:1
#
# Production image (slice 1.3). One image serves app, worker, scheduler and ssr;
# compose.prod.yaml gives each a different command. See DEPLOY.md.
#
#   php-base  FrankenPHP + the PHP extensions (same base as docker/dev/app.Dockerfile)
#   vendor    Composer dependencies without dev packages, optimized autoloader
#   assets    Vite client bundle (public/build) and self-contained SSR bundle (bootstrap/ssr)
#   runtime   the final image: source + vendor + bundles + the node binary for SSR

ARG PHP_IMAGE=dunglas/frankenphp:1.13-php8.4-bookworm
ARG NODE_IMAGE=node:24.22-bookworm-slim

# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS php-base

RUN install-php-extensions pdo_mysql intl zip bcmath pcntl opcache

# ---------------------------------------------------------------------------
FROM php-base AS vendor

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# Dependencies first, so source changes don't invalidate this layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader \
        --no-interaction --prefer-dist --no-progress

# Only what runs in production (an allowlist; .dockerignore is the second line of defence).
COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage
# pre-autoload-dump drops every AWS SDK service except S3 (composer.json "extra");
# post-autoload-dump (package:discover) writes bootstrap/cache/packages.php.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && rm composer.lock

# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
# The SSR bundle is self-contained (ssr.noExternal in vite.config.ts): no node_modules at runtime.
RUN npm run build \
    && find public/build bootstrap/ssr -name '*.map' -delete

# ---------------------------------------------------------------------------
FROM php-base AS runtime

# Only the node binary, for the ssr service (`node bootstrap/ssr/app.js`). No npm.
COPY --from=assets /usr/local/bin/node /usr/local/bin/node

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php.ini "$PHP_INI_DIR/conf.d/zz-production.ini"

ARG UID=1000
ARG GID=1000
RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid "${GID}" --no-create-home --shell /usr/sbin/nologin app \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config

WORKDIR /app

# Code is owned by root and read-only for `app`; only storage/ and bootstrap/cache are writable.
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY --from=assets /app/bootstrap/ssr /app/bootstrap/ssr
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
    && chown -R app:app storage bootstrap/cache

USER app

# Plain HTTP on 8000; Caddy (compose.prod.yaml) terminates TLS in front of it.
ENV SERVER_NAME=":8000" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    NODE_ENV=production

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1
