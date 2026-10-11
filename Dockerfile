# Coolify / production app image for the mahfuz-ahmed-api (Laravel API).
#
# Topology: this image runs Nginx + PHP-FPM + the default queue + a media
# queue worker + a backups queue worker + tusd. Postgres/MySQL, Redis, and
# search engines stay external Coolify resources — never start them in this
# container. Only their client binaries (mysqldump/mysql) are installed.
#
# Horizon / Pulse: Supervisor stubs exist in docker/supervisord.conf but must
# stay commented until those Composer packages are installed. Enabling Horizon
# means disabling laravel-queue-worker so they never consume the same queues.

FROM php:8.4-fpm-bookworm AS build

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd mbstring pcntl pdo_mysql sockets zip \
    && pecl install redis \
    && docker-php-ext-enable opcache redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/ /usr/local/

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev --no-interaction \
    && npm run build

FROM php:8.4-fpm-bookworm AS production

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libfreetype6 \
        libjpeg62-turbo \
        libonig5 \
        libpng16-16 \
        libwebp7 \
        libzip4 \
        nginx \
        supervisor \
        webp \
        ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

# Official MySQL 8.4 client (mysqldump + mysql) for spatie/laravel-backup and
# wnx/laravel-backup-restore. Debian's default-mysql-client is MariaDB, whose
# mysqldump rejects MySQL-only flags such as --set-gtid-purged. Both Oracle
# signing keys are imported because the repository rotates them.
ARG MYSQL_APT_COMPONENT=mysql-8.4-lts
RUN apt-get update \
    && apt-get install -y --no-install-recommends gnupg \
    && install -d -m 0755 /etc/apt/keyrings \
    && export GNUPGHOME="$(mktemp -d)" \
    && for key in 2023 2025; do \
        curl -fsSL "https://repo.mysql.com/RPM-GPG-KEY-mysql-${key}" -o "${GNUPGHOME}/mysql-${key}.asc" \
        && gpg --batch --import "${GNUPGHOME}/mysql-${key}.asc"; \
    done \
    && gpg --batch --export > /etc/apt/keyrings/mysql.gpg \
    && rm -rf "$GNUPGHOME" \
    && echo "deb [signed-by=/etc/apt/keyrings/mysql.gpg] http://repo.mysql.com/apt/debian bookworm ${MYSQL_APT_COMPONENT}" \
        > /etc/apt/sources.list.d/mysql.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends mysql-client \
    && apt-get purge -y --auto-remove gnupg \
    && rm -rf /var/lib/apt/lists/* \
    && mysqldump --version

COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=build --chown=www-data:www-data /var/www /var/www

COPY docker/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/tus-origins.conf /etc/nginx/tus-origins.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/application-entrypoint

RUN chmod 0755 /usr/local/bin/application-entrypoint \
    && mkdir -p /run/nginx /var/log/supervisor \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

ARG TUSD_VERSION=2.10.1
ARG TARGETARCH
RUN arch="${TARGETARCH:-amd64}" \
    && curl -fsSL -o /tmp/tusd.tar.gz "https://github.com/tus/tusd/releases/download/v${TUSD_VERSION}/tusd_linux_${arch}.tar.gz" \
    && tar -xzf /tmp/tusd.tar.gz -C /tmp \
    && find /tmp -type f -name tusd -exec mv {} /usr/local/bin/tusd \; \
    && chmod 0755 /usr/local/bin/tusd \
    && rm -rf /tmp/tusd.tar.gz /tmp/tusd_linux_*

WORKDIR /var/www

ENV QUEUE_CONNECTION=redis \
    CACHE_STORE=redis \
    QUEUE_NAMES=default \
    QUEUE_SLEEP=3 \
    QUEUE_TRIES=3 \
    QUEUE_TIMEOUT=300 \
    QUEUE_MAX_TIME=3600 \
    QUEUE_MEMORY=128 \
    QUEUE_PROCESSES=2 \
    FFMPEG_BINARIES=/usr/bin/ffmpeg \
    FFPROBE_BINARIES=/usr/bin/ffprobe \
    MEDIA_DISK=public

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD ["curl", "--fail", "--silent", "--show-error", "--max-time", "5", "--output", "/dev/null", "http://127.0.0.1/up"]

ENTRYPOINT ["/usr/local/bin/application-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
