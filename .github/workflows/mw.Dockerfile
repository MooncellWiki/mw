FROM ghcr.io/mooncellwiki/php:latest

VOLUME [ "/var/www/html/etc" ]

COPY . /var/www/html/

WORKDIR /var/www/html/

RUN composer install --no-dev
