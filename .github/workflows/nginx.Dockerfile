ARG VERSION
FROM ghcr.io/mooncellwiki/mw:$VERSION as builder
FROM nginx:1.25
COPY --from=builder /var/www/html/ /var/www/html/
