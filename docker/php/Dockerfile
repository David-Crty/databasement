FROM dunglas/frankenphp:1.12-php8.5-alpine

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
    sqlsrv \
    intl \
    pcntl \
    opcache \
    pcov \
    ftp \
    sockets \
    mongodb

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

# Add tools
RUN apk add --no-cache git \
        mariadb-client mariadb-connector-c \
        postgresql18-client \
        supervisor \
        gzip zstd 7zip \
        tzdata \
        shadow \
        findutils \
        bash \
        openssh-client \
        sshpass \
        redis \
        sqlite \
        curl \
        unzip \
        icu-libs krb5-libs libgcc libssl3 libstdc++ zlib \
        gcompat

# Install MongoDB tools (mongodump, mongorestore) from Alpine edge community repo
RUN apk add --no-cache --repository=https://dl-cdn.alpinelinux.org/alpine/edge/community mongodb-tools

# Install .NET 8 ASP.NET runtime (required by sqlpackage). Pulled from edge/community
# because Alpine stable repos lag behind the .NET LTS Microsoft supports for musl.
RUN apk add --no-cache --repository=https://dl-cdn.alpinelinux.org/alpine/edge/community aspnetcore8-runtime

# Install sqlpackage (Microsoft SQL Server export tool). Pinned for reproducible
# builds; refresh from https://aka.ms/sqlpackage-linux when bumping versions.
# Relies on the .NET 8 runtime installed above.
ARG SQLPACKAGE_URL=https://download.microsoft.com/download/c149d463-e758-4207-bf98-3ee0e2547faa/sqlpackage-linux-x64-en-170.3.93.6.zip
RUN curl -fsSL -o /tmp/sqlpackage.zip "${SQLPACKAGE_URL}" \
    && mkdir -p /opt/sqlpackage \
    && unzip -q /tmp/sqlpackage.zip -d /opt/sqlpackage \
    && chmod +x /opt/sqlpackage/sqlpackage \
    && ln -s /opt/sqlpackage/sqlpackage /usr/local/bin/sqlpackage \
    && rm /tmp/sqlpackage.zip

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
    setcap -r /usr/local/bin/frankenphp

# Create directories with correct ownership
RUN mkdir -p /app /data
RUN chmod -R 777 /config /data /app && \
    chown -R application:application /app /config /data

COPY scripts/ /usr/local/bin/
RUN chmod 755 /usr/local/bin/*.sh

WORKDIR /app

ENV WEB_DOCUMENT_ROOT=/app/public
ENV SERVER_NAME=":2226"
ENV ENABLE_QUEUE_WORKER="false"
ENV ENABLE_DATABASE_MIGRATION="true"
ENV TZ="UTC"

HEALTHCHECK NONE
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/local/bin/start.sh"]
