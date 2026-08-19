# syntax=docker/dockerfile:1.7
ARG VERSION
FROM ghcr.io/mooncellwiki/mw:${VERSION} AS mw

# ---------------------------------------------------------------------------
# 生产 Caddyfile 里 PHP 走的是裸 `reverse_proxy + transport fastcgi`（不是
# php_fastcgi），没有 try_files，Caddy 完全不 stat 磁盘上的 .php。真正读磁盘的
# 只有 @file 匹配器（静态资源）和 handle_errors 的 /public/404.html，
# 而 /srv/public 与 /srv/etc 都是宿主机 bind mount，不在镜像里。
#
# 剥离必须放在中间 stage：直接在最终 stage 里 RUN rm 只会产生 whiteout，
# 数据仍留在下层，镜像不会变小。
# ---------------------------------------------------------------------------
FROM mw AS static
RUN set -eux; \
    rm -rf /srv/includes /srv/languages /srv/vendor /srv/maintenance \
           /srv/mw-config /srv/scripts /srv/cache /srv/tmp /srv/etc; \
    find /srv -name '*.php' -delete; \
    find /srv -type d \( -name i18n -o -name tests -o -name docs \) \
         -prune -exec rm -rf {} +; \
    find /srv \( -name '*.md' -o -name '*.sql' -o -name '.gitignore' \
         -o -name '.gitattributes' -o -name '.gitreview' -o -name 'Gruntfile.js' \) -delete; \
    find /srv -mindepth 2 -type d -empty -delete; \
    mkdir -p /srv/resources /srv/extensions /srv/skins /srv/images

FROM caddy:2.11.4
WORKDIR /srv
COPY --from=static --chown=root:root /srv/resources   /srv/resources
COPY --from=static --chown=root:root /srv/extensions  /srv/extensions
COPY --from=static --chown=root:root /srv/skins       /srv/skins
COPY --from=static --chown=root:root /srv/images      /srv/images
COPY --from=static --chown=root:root /srv/robots.txt  /srv/robots.txt
