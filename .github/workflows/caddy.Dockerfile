ARG VERSION
FROM caddy:2.8.4-builder AS builder

RUN xcaddy build \
	--with github.com/caddyserver/cache-handler@v0.13.0

FROM ghcr.io/mooncellwiki/mw:$VERSION as mw
FROM caddy:2.8.4

COPY --from=builder /usr/bin/caddy /usr/bin/caddy
COPY --from=mw /srv/ /srv/
RUN chown -R root:root /srv
