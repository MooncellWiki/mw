# 生产镜像构建

`.github/workflows/build.yml` 在推送 `v*.*.*-*` 标签时，依次构建并发布：

1. `ghcr.io/mooncellwiki/php`：PHP 8.3.35 FPM、扩展、Composer 和 PHP/FPM 配置，构建上下文为 `docker/php/`。
2. `ghcr.io/mooncellwiki/mw`：通过 `PHP_IMAGE` 构建参数使用上一步得到的 PHP 镜像 digest。
3. `ghcr.io/mooncellwiki/caddy`：通过 `MW_IMAGE` 构建参数，从本次推送的 MediaWiki 镜像（按 digest）提取静态资源。

## PHP 镜像的标签

- `tree-<哈希>`：`docker/php/` 目录的 git tree 哈希。发版时先查这个标签，已存在就直接复用、不重新构建，
  所以 `docker/php/` 不变时，各次发布用的是同一个 PHP 镜像。
- `sha-<commit>`：构建这个 PHP 镜像的提交。只有真正构建时才打。
- `latest`：三个镜像都推送成功后，并且本次标签是仓库里最新的发布标签时，才指向本次用的 PHP 镜像。
  `mw.Dockerfile` 的默认 `PHP_IMAGE` 和 base-images 的 devcontainer 用的是它。

PHP 镜像不打 MediaWiki 的发布标签。某个发布用的是哪个 PHP 镜像，看 mw 镜像的
`org.opencontainers.image.base.name` / `org.opencontainers.image.base.digest` 标签。

base-images 时期的 `0.x` 标签（如 `0.2.0`、`0.2`）不再更新，需要固定版本时改用 `tree-<哈希>` 或 digest。

## 升级 PHP、扩展或系统包

`docker/php/Dockerfile` 里的版本都是写死的：基础镜像按 digest、PECL 扩展和 Composer 按版本号、
install-php-extensions 按 checksum。升级时改对应的值（取值方法见 Dockerfile 里的注释）。
只想拿 Debian 安全更新时，把基础镜像的 digest 换成同一标签的最新 digest 即可。
改了 `docker/php/` 下任何文件，tree 哈希就会变，下一次发版自动重新构建。

## PHP/FPM 配置和线上的关系

线上（docker-config 的 `k8s/base/web.yaml`）把各站的 `php.ini`、`php-fpm.conf` 以 ConfigMap 挂到
`/usr/local/etc/php/conf.d/php.ini`、`/usr/local/etc/php-fpm.d/zzz-docker.conf`，
和镜像里这两个文件是同一路径，整份替换。所以 `docker/php/config/` 只影响构建（`composer install`）、
devcontainer 等没挂配置的场景，改线上配置要去 docker-config。

## 迁移

PHP 构建及 `php/config/` 从 `MooncellWiki/base-images` 迁入，扩展列表保持一致，扩展版本固定为迁移时线上镜像里的版本。
切换发布仓库时，需要在 GitHub 的 `MooncellWiki/php` package 设置中，
通过 **Manage Actions access** 授予 `MooncellWiki/mw` **Write** 权限；
工作流已声明 `packages: write`。迁移完成后应停用旧仓库的 PHP 发布工作流，避免继续覆盖 `latest`。
参见 [GitHub 的工作流包权限说明](https://docs.github.com/en/packages/learn-github-packages/configuring-a-packages-access-control-and-visibility#ensuring-workflow-access-to-your-package)。

## 本地构建

在仓库根目录运行：

```bash
docker build -t prts-php:local docker/php
docker run --rm prts-php:local php -v
docker run --rm prts-php:local php-fpm -t

docker build --build-arg PHP_IMAGE=prts-php:local \
  -f .github/workflows/mw.Dockerfile -t prts-mw:local .
```

第二次构建要读本地镜像 `prts-php:local`，只有 docker 驱动的 builder 看得到本地镜像。
如果当前 builder 是 docker-container 驱动（例如执行过 `docker buildx create --use`），
会去 Docker Hub 拉 `prts-php:local` 而失败；这时用 `--builder` 指定 `docker buildx ls` 里驱动为 `docker` 的那个
（Docker Desktop 上一般是 `desktop-linux`，Linux 上是 `default`）。

只构建 PHP 镜像时，构建上下文不包含 wiki 源码及本地站点配置。
