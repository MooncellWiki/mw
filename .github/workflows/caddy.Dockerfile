ARG VERSION
FROM ghcr.io/mooncellwiki/mw:$VERSION as mw
FROM caddy:2.10.2

COPY --from=mw /srv/ /srv/
RUN chown -R root:root /srv
