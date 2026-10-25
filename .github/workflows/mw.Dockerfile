# syntax=docker/dockerfile:1.7
ARG PHP_IMAGE=ghcr.io/mooncellwiki/php:latest

# ---------------------------------------------------------------------------
# 依赖阶段：输入只有 composer 相关文件，改 LocalSettings.php 不会让 vendor 失效。
# composer.local.json 通过 merge-plugin 引了 66 个 extensions/*/composer.json，
# pre/post-install hook 的类在 includes/composer/ 下，所以这三份都要先进来。
#
# 这一阶段有两份产出：vendor/，以及 extensions/。SemanticMediaWiki、
# SemanticResultFormats、DynamicPageList3、AWS 不是 submodule，是 composer 装的，
# composer/installers 按 mediawiki-extension 类型把它们落到 extensions/<Name>/。
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS deps
WORKDIR /srv
# OAuth 扩展引入的 league/oauth2-server 是 wikimedia 的 git 仓库按提交固定的，锁文件里只有 git 源、没有 zip 包，
# composer 要用 git 拉。PHP 镜像里没有 git，只在这个阶段装，运行镜像里不带。
RUN apt-get update \
 && apt-get install -y --no-install-recommends git \
 && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock composer.local.json ./
COPY includes/composer ./includes/composer
COPY extensions ./extensions
# 依赖严格按 composer.lock 装。vendor 为空时 merge-plugin 是这次 install 中途才装上的：
# 它在随后的 pre-autoload-dump 里合并 extensions/*/composer.json，记下合并进来的依赖，
# install 结束时因为“首次安装”对这些依赖再跑一次 composer update（MergePlugin::onPostInstallOrUpdate），
# SMW、sentry 等会升到约束允许的最新版、不按锁文件，工作目录里的锁文件也被改写。
# 所以先 --no-autoloader 装：没有 autoload dump，插件不合并、记不下依赖，也就不跑那次 update；
# 再单独 dump-autoload，这时插件已在 vendor 里，照常合并各扩展的 autoload。
# 最后拿装之前存下的锁文件逐个包核对 vendor 的版本，不一致就让构建失败。
RUN cp composer.lock /tmp/composer.lock \
 && composer install --no-dev --no-interaction --no-progress --no-autoloader \
 && composer dump-autoload --no-dev --no-interaction --optimize \
 && php -r ' \
      $versions = fn ( $packages ) => array_column( $packages, "version", "name" ); \
      $locked = $versions( json_decode( file_get_contents( "/tmp/composer.lock" ), true )["packages"] ); \
      $installed = $versions( json_decode( file_get_contents( "vendor/composer/installed.json" ), true )["packages"] ); \
      ksort( $locked ); ksort( $installed ); \
      if ( $locked !== $installed ) { \
        foreach ( array_unique( array_merge( array_keys( $locked ), array_keys( $installed ) ) ) as $name ) { \
          if ( ( $locked[$name] ?? null ) !== ( $installed[$name] ?? null ) ) { \
            fprintf( STDERR, "%s: composer.lock %s, vendor %s\n", $name, $locked[$name] ?? "-", $installed[$name] ?? "-" ); \
          } \
        } \
        exit( 1 ); \
      }'

# ---------------------------------------------------------------------------
# 运行阶段：按变更频率从冷到热分层，LocalSettings.php 落在最后一层。
# 不再用 `chown -R *`——那会把整棵树 copy-up 到新层，白白多出 100MB+。
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE}
WORKDIR /srv

# 冷层：MediaWiki core，只有升版本时才动。
# images/ 仓库里只有 README 和 .htaccess，scripts/ 只有一个运维脚本，都常年不动，
# 排在 vendor 前面——`composer install --optimize-autoloader` 生成的 classmap 含
# 扩展类，bump 一个 extension submodule 就可能让 vendor 层失效并连带作废后面所有层。
COPY --chown=www-data:www-data languages   ./languages
COPY --chown=www-data:www-data includes    ./includes
COPY --chown=www-data:www-data resources   ./resources
COPY --chown=www-data:www-data maintenance ./maintenance
COPY --chown=www-data:www-data mw-config   ./mw-config
COPY --chown=www-data:www-data images      ./images
COPY --chown=www-data:www-data scripts     ./scripts
COPY --from=deps --chown=www-data:www-data /srv/vendor ./vendor

# 温层：皮肤和扩展，submodule 一 bump 就变。skins 只有 4 个 submodule，
# extensions 有 70 个，动得勤得多，所以排在后面。
# extensions 必须从 deps 取：仓库里的 extensions/ 只有 submodule，缺 composer
# 装的那 4 个，直接从上下文 COPY 会让 LocalSettings.php 的 wfLoadExtension() 挂掉。
COPY --chown=www-data:www-data skins       ./skins
COPY --from=deps --chown=www-data:www-data /srv/extensions ./extensions

# 热层：入口脚本 + LocalSettings.php，改一次只重传几百 KB。
# CREDITS / COPYING 被 SpecialVersion.php 读取，composer.lock 被 ComposerLock 读取。
COPY --chown=www-data:www-data \
     *.php robots.txt CREDITS COPYING \
     composer.json composer.lock composer.local.json \
     ./

RUN install -d -o www-data -g www-data tmp cache

# 放在最后：VOLUME 之后的 RUN 对该路径的写入会被丢弃
VOLUME [ "/srv/etc" ]
