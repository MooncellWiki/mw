FROM caddy:2.8.4-builder AS builder

RUN xcaddy build \
    --with github.com/caddyserver/cache-handler@v0.13.0

FROM caddy:2.8.4

COPY --from=builder /usr/bin/caddy /usr/bin/caddy

FROM ghcr.io/mooncellwiki/mw:$VERSION as mw

COPY --from=mw /var/www/html/ /srv/
