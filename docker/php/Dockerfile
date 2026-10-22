# syntax=docker/dockerfile:1.7
# 基础镜像按 digest 固定：Docker Hub 会给同一个 8.3.36-fpm 标签重新推送带 Debian 更新的版本，
# 不固定的话同一份 Dockerfile 每次构建出来都不一样。升级（包括只为拿 Debian 安全更新）时改这里：
#   docker buildx imagetools inspect php:8.3.36-fpm --format '{{.Manifest.Digest}}'
FROM php:8.3.36-fpm@sha256:d0dea3f5d95314eac94a732469f4cf0ee658a22c2ce3f2612272b9d5b42d1a72
ENV TZ=Asia/Shanghai

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# 升级时一起改 checksum：curl -fsSL <url> | sha256sum
ADD --checksum=sha256:3f49c71fa66c79b8b2b96bc0ce92885dafd7946f3b5a46f545b390d6f1617e2c \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.12.0/install-php-extensions /usr/local/bin/

# PECL 扩展和 Composer 写死版本，不写的话每次构建装的是 PECL 上当时最新的版本。
# 其余扩展随 PHP 源码，版本跟着上面的 FROM 走。
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    apcu-5.1.28 \
    bcmath \
    calendar \
    gd \
    gettext \
    intl \
    luasandbox-4.1.3 \
    mysqli \
    memcached-3.4.0 \
    pcntl \
    pdo_mysql \
    redis-6.3.0 \
    shmop \
    soap \
    sockets \
    sysvsem \
    xmlrpc-1.0.0RC3 \
    xsl \
    zip \
    opcache \
    excimer-1.2.6 \
    @composer-2.10.3

# install-php-extensions 结束时会删掉 apt 的索引，所以这里要重新 update。
# ghostscript 和 fonts-droid-fallback（CJK 字体）原来是作为推荐依赖带进来的，PdfHandler 默认用 gs 渲染 PDF，
# 关掉推荐依赖以后要显式装。
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 telnet libvips-tools ghostscript fonts-droid-fallback && \
    rm -rf /var/lib/apt/lists/*

# 三站通用的设置。线上 docker-config 把各站自己的 php.ini、php-fpm.conf 挂到 conf.d/php.ini、php-fpm.d/zzz-docker.conf，
# 按文件名排在这两个文件后面加载，同名设置以各站为准。
COPY ./config/php.ini /usr/local/etc/php/conf.d/50-mooncell.ini
COPY ./config/php-fpm.conf /usr/local/etc/php-fpm.d/zz-mooncell.conf
