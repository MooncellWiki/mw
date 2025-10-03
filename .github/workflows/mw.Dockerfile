FROM ghcr.io/mooncellwiki/php:latest

VOLUME [ "/srv/etc" ]

COPY . /srv/

WORKDIR /srv/

RUN composer install --no-dev && \
	mkdir tmp &&\
	chown -R www-data:www-data *
