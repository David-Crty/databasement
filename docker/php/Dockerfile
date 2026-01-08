FROM dunglas/frankenphp:1.9-php8.4-alpine

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
        postgresql-client \
        supervisor \
        gzip zstd \
        tzdata

COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php-custom-production.ini /usr/local/etc/php/php-custom-production.ini
COPY php-custom.ini /usr/local/etc/php/conf.d/zz-php-custom.ini
ENV USER_ID=1000
ENV USER=application

RUN adduser -u ${USER_ID} -D -h /home/${USER} ${USER} && \
    setcap -r /usr/local/bin/frankenphp && \
    chown -R ${USER}:${USER} /data/caddy && chmod -R 777 /config/caddy

RUN mkdir -p /app && chown -R ${USER}:${USER} /app && chmod -R 775 /app
RUN mkdir -p /data && chown -R ${USER}:${USER} /data && chmod -R 777 /data
RUN mkdir -p /tmp/backups && chown -R ${USER}:${USER} /tmp/backups && chmod -R 777 /tmp/backups

COPY start-frankenphp.sh /usr/local/bin/
COPY docker-entrypoint.sh /usr/local/bin/

WORKDIR /app
USER 1000

ENV WEB_DOCUMENT_ROOT=/app/public
ENV SERVER_NAME=":2226"
ENV MYSQL_CLI_TYPE="mariadb"
ENV ENABLE_QUEUE_WORKER="false"
ENV ENABLE_DATABASE_MIGRATION="true"
ENV TZ="UTC"

HEALTHCHECK NONE

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
