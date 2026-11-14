# Development image for the app, worker and scheduler services.
# The source is bind-mounted at /app; nothing is copied in.
# The production image is a separate multi-stage Dockerfile (slice 1.3).
FROM dunglas/frankenphp:1.13-php8.4-bookworm

RUN install-php-extensions pdo_mysql intl zip bcmath pcntl \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

# Run as the host user so files written to the bind mount stay editable (WSL2/Linux).
ARG UID=1000
ARG GID=1000
RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid "${GID}" --create-home app \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config /app

COPY docker/dev/php.ini /usr/local/etc/php/conf.d/zz-dev.ini

USER app

# Plain HTTP on 8000 inside the container; compose maps it to the host.
ENV SERVER_NAME=":8000" \
    COMPOSER_HOME=/home/app/.composer

EXPOSE 8000
