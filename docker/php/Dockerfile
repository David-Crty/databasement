FROM dunglas/frankenphp:1.11-php8.4-alpine

RUN install-php-extensions \
    ctype \
    curl \
    dom \
    fileinfo \
    filter \
    hash \
    mbstring \
    openssl \
    pcre \
    pdo \
    session \
    tokenizer \
    xml \
    pdo_mysql \
    pdo_pgsql \
    intl \
    pcntl \
    opcache \
    pcov

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

# Add tools
RUN apk add --no-cache git \
        mariadb-client mariadb-connector-c \
        postgresql18-client \
        supervisor \
        gzip zstd \
        tzdata

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php-custom-production.ini /usr/local/etc/php/php-custom-production.ini
COPY php-custom.ini /usr/local/etc/php/conf.d/zz-php-custom.ini

# Install su-exec for dropping privileges
RUN apk add --no-cache su-exec

# Default UID/GID for the application user (can be overridden at runtime)
ENV UID=1000
ENV GID=1000

# Create application user with default UID/GID
# This user will be recreated at runtime if UID/GID differs
RUN addgroup -g 1000 application && \
    adduser -u 1000 -G application -D -h /home/application application && \
    setcap -r /usr/local/bin/frankenphp && \
    chmod -R 777 /config/caddy

# Create directories with root ownership (entrypoint will fix permissions)
RUN mkdir -p /app && chmod -R 775 /app
RUN mkdir -p /data && chmod -R 777 /data
RUN mkdir -p /tmp/backups && chmod -R 777 /tmp/backups

COPY start-frankenphp.sh /usr/local/bin/
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/start-frankenphp.sh

WORKDIR /app
# Container starts as root, entrypoint handles privilege drop

ENV WEB_DOCUMENT_ROOT=/app/public
ENV SERVER_NAME=":2226"
ENV MYSQL_CLI_TYPE="mariadb"
ENV ENABLE_QUEUE_WORKER="false"
ENV ENABLE_DATABASE_MIGRATION="true"
ENV TZ="UTC"

HEALTHCHECK NONE

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
