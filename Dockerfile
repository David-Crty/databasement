FROM davidcrty/databasement-php:latest AS backend-build

USER 1000

COPY --chown=1000:1000 . /app

RUN composer install --dev --no-interaction --no-progress --no-suggest --optimize-autoloader
RUN php artisan vendor:publish --force --tag=livewire:assets


FROM node:22-slim AS frontend-build

WORKDIR /app
COPY package.json .
RUN npm install

COPY --from=backend-build /app /app
RUN npm run build


FROM davidcrty/databasement-php:latest

ARG APP_COMMIT_HASH=""
ENV APP_ENV="production"
ENV APP_DEBUG="false"
ENV APP_COMMIT_HASH="${APP_COMMIT_HASH}"

COPY --from=backend-build /app /app
COPY --from=frontend-build /app/public/build /app/public/build

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
RUN cp /usr/local/etc/php/php-custom-production.ini /usr/local/etc/php/conf.d/zz-php-custom-production.ini

# fix permission for rootless
RUN chmod -R 777 /app/storage /app/bootstrap/cache
