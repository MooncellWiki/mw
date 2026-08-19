# syntax=docker/dockerfile:1.7
ARG PHP_IMAGE=ghcr.io/mooncellwiki/php:latest

# ---------------------------------------------------------------------------
# 依赖阶段：输入只有 composer 相关文件，改 LocalSettings.php 不会让 vendor 失效。
# composer.local.json 通过 merge-plugin 引了 66 个 extensions/*/composer.json，
# pre/post-install hook 的类在 includes/composer/ 下，所以这三份都要先进来。
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS deps
WORKDIR /srv
COPY composer.json composer.lock composer.local.json ./
COPY includes/composer ./includes/composer
COPY extensions ./extensions
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# ---------------------------------------------------------------------------
# 运行阶段：按变更频率从冷到热分层，LocalSettings.php 落在最后一层。
# 不再用 `chown -R *`——那会把整棵树 copy-up 到新层，白白多出 100MB+。
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE}
WORKDIR /srv

# 冷层：MediaWiki core，只有升版本时才动
COPY --chown=www-data:www-data languages   ./languages
COPY --chown=www-data:www-data includes    ./includes
COPY --chown=www-data:www-data resources   ./resources
COPY --chown=www-data:www-data maintenance ./maintenance
COPY --chown=www-data:www-data mw-config   ./mw-config
COPY --from=deps --chown=www-data:www-data /srv/vendor ./vendor

# 温层：扩展 / 皮肤 / 上传目录 / 运维脚本
COPY --chown=www-data:www-data extensions ./extensions
COPY --chown=www-data:www-data skins      ./skins
COPY --chown=www-data:www-data images     ./images
COPY --chown=www-data:www-data scripts    ./scripts

# 热层：入口脚本 + LocalSettings.php，改一次只重传几百 KB。
# CREDITS / COPYING 被 SpecialVersion.php 读取，composer.lock 被 ComposerLock 读取。
COPY --chown=www-data:www-data \
     *.php robots.txt CREDITS COPYING \
     composer.json composer.lock composer.local.json \
     ./

RUN install -d -o www-data -g www-data tmp cache

# 放在最后：VOLUME 之后的 RUN 对该路径的写入会被丢弃
VOLUME [ "/srv/etc" ]
