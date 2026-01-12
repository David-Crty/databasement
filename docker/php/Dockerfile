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
    pdo_sqlsrv \
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
        tzdata \
        shadow \
        findutils \
        bash \
        curl

# Install go-sqlcmd (Microsoft SQL Server CLI tool)
RUN ARCH=$(uname -m) && \
    if [ "$ARCH" = "x86_64" ]; then ARCH="amd64"; elif [ "$ARCH" = "aarch64" ]; then ARCH="arm64"; fi && \
    curl -L "https://github.com/microsoft/go-sqlcmd/releases/download/v1.8.2/sqlcmd-linux-${ARCH}.tar.bz2" -o /tmp/sqlcmd.tar.bz2 && \
    tar -xjf /tmp/sqlcmd.tar.bz2 -C /usr/local/bin sqlcmd && \
    chmod +x /usr/local/bin/sqlcmd && \
    rm /tmp/sqlcmd.tar.bz2

COPY supervisord.conf /config/supervisord.conf
COPY php-custom-production.ini /usr/local/etc/php/php-custom-production.ini
COPY php-custom.ini /usr/local/etc/php/conf.d/zz-php-custom.ini

# Default PUID/PGID for the application user (can be overridden at runtime)
ENV PUID=1000
ENV PGID=1000

# Create application user with default PUID/PGID
# This user will be recreated at runtime if PUID/PGID differs
RUN addgroup -g ${PGID} application && \
    adduser -u ${PUID} -G application -D -h /home/application application && \
    setcap -r /usr/local/bin/frankenphp && \
    chmod -R 777 /config

# Create directories with correct ownership
RUN mkdir -p /app && chown application:application /app
RUN mkdir -p /data && chown application:application /data

COPY scripts/ /usr/local/bin/
RUN chmod 755 /usr/local/bin/*.sh

WORKDIR /app

ENV WEB_DOCUMENT_ROOT=/app/public
ENV SERVER_NAME=":2226"
ENV MYSQL_CLI_TYPE="mariadb"
ENV ENABLE_QUEUE_WORKER="false"
ENV ENABLE_DATABASE_MIGRATION="true"
ENV TZ="UTC"

HEALTHCHECK NONE

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/local/bin/start.sh"]
