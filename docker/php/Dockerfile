FROM firebirdsql/firebird:5 AS firebird

# Build the sqlpackage CLI for the target arch. Microsoft only ships a self-contained
# x64 zip (no arm64 build), so for multi-arch we install the framework-dependent
# dotnet tool, which runs on the .NET runtime present in the final image. The Alpine
# SDK produces a musl apphost matching the final stage. Bump the version to update.
FROM mcr.microsoft.com/dotnet/sdk:8.0-alpine AS sqlpackage
ARG SQLPACKAGE_VERSION=170.3.93
RUN dotnet tool install --tool-path /opt/sqlpackage microsoft.sqlpackage --version "${SQLPACKAGE_VERSION}"

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
    mongodb \
    smbclient

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
        gcompat \
        libc6-compat \
        procps-ng \
        ncurses-libs

# Install MongoDB tools — only mongodump + mongorestore, the binaries the app
# uses. From MongoDB's official archive (newer than Alpine's edge package); the
# glibc builds run on Alpine via the gcompat layer, like the Firebird binaries.
# Refresh from https://www.mongodb.com/try/download/database-tools when bumping.
ARG MONGODB_TOOLS_VERSION=100.17.0
ARG TARGETARCH
RUN case "${TARGETARCH}" in \
        amd64) MONGO_ARCH=x86_64 ;; \
        arm64) MONGO_ARCH=arm64 ;; \
        *) echo "Unsupported TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && curl -fsSL -o /tmp/mtools.tgz \
        "https://fastdl.mongodb.org/tools/db/mongodb-database-tools-ubuntu2204-${MONGO_ARCH}-${MONGODB_TOOLS_VERSION}.tgz" \
    && tar xzf /tmp/mtools.tgz -C /tmp \
    && cp /tmp/mongodb-database-tools-*/bin/mongodump \
          /tmp/mongodb-database-tools-*/bin/mongorestore /usr/local/bin/ \
    && chmod +x /usr/local/bin/mongodump /usr/local/bin/mongorestore \
    && rm -rf /tmp/mtools.tgz /tmp/mongodb-database-tools-*

# Install .NET 8 runtime — required by the framework-dependent sqlpackage tool
# (base Microsoft.NETCore.App only; no ASP.NET Core needed). Pulled from
# edge/community because Alpine stable repos lag behind the .NET LTS Microsoft
# supports for musl.
RUN apk add --no-cache --repository=https://dl-cdn.alpinelinux.org/alpine/edge/community dotnet8-runtime

# sqlpackage (Microsoft SQL Server export tool) — the framework-dependent dotnet
# tool built in the sqlpackage stage above. Runs on the .NET 8 runtime installed
# above and works on both amd64 and arm64. Bump SQLPACKAGE_VERSION in that stage.
COPY --from=sqlpackage /opt/sqlpackage /opt/sqlpackage
RUN ln -s /opt/sqlpackage/sqlpackage /usr/local/bin/sqlpackage

# Firebird tooling: gbak (dump/restore) and isql (connection probe + test
# fixtures). The official Firebird image is Debian-based, so the binaries are
# linked against glibc — Alpine's musl+gcompat doesn't provide all the glibc
# symbols they need (__strftime_l, __register_atfork, __rawmemchr, __strdup).
# We therefore COPY both the Firebird payload and the glibc loader+libs from
# the firebird stage, then run the binaries via the bundled glibc loader.
# The /lib/*-linux-gnu/ glob keeps this multi-arch: amd64 resolves to
# x86_64-linux-gnu, arm64 to aarch64-linux-gnu. A symlink to ld.so gives the
# wrappers a stable path regardless of arch. Wrappers live in /usr/local/bin so
# they take precedence over unixodbc's /usr/bin/isql regardless of shell type.
COPY --from=firebird /opt/firebird /opt/firebird
COPY --from=firebird \
    /lib/*-linux-gnu/ld-linux-*.so.* \
    /lib/*-linux-gnu/libc.so.6 \
    /lib/*-linux-gnu/libdl.so.2 \
    /lib/*-linux-gnu/libgcc_s.so.1 \
    /lib/*-linux-gnu/libm.so.6 \
    /lib/*-linux-gnu/libpthread.so.0 \
    /lib/*-linux-gnu/libtommath.so.1 \
    /opt/firebird/compat/
RUN ln -s /opt/firebird/compat/ld-linux-*.so.* /opt/firebird/compat/ld.so

COPY scripts/firebird/gbak scripts/firebird/isql /usr/local/bin/
RUN chmod 755 /usr/local/bin/gbak /usr/local/bin/isql

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

# supervisord drops to `application` via setuid without rewriting the environment,
# so children would inherit root's HOME=/root (mode 0700). libpq treats a permission
# error on $HOME/.postgresql/postgresql.crt as fatal to the TLS handshake, breaking
# PostgreSQL connections over SSL.
ENV HOME=/home/application

HEALTHCHECK NONE
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/local/bin/start.sh"]
