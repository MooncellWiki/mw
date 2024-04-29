FROM ghcr.io/mooncellwiki/mw:latest as builder
FROM nginx:1.25
COPY --from=builder /var/www/html/ /var/www/html/
