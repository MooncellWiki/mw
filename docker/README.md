# 生产镜像构建

`.github/workflows/build.yml` 在推送 `v*.*.*-*` 标签时，依次构建并发布：

1. `ghcr.io/mooncellwiki/php`：PHP 8.3 FPM、扩展、Composer、PHP/FPM 配置和 Caddy，构建上下文为 `docker/php/`。
2. `ghcr.io/mooncellwiki/mw`：通过 `PHP_IMAGE` 构建参数使用上一步得到的 PHP 镜像 digest。

线上 web pod 里的 php-fpm 和 caddy 两个容器都用 mw 镜像（docker-config 的 `k8s/base/web.yaml`），发版只换一个标签。
caddy 容器看得到镜像里完整的 `/srv`，哪些文件能被直接访问由 docker-config 的 `k8s/base/Caddyfile` 里的白名单决定。
以前单独构建的 `ghcr.io/mooncellwiki/caddy` 不再更新，旧标签留着供回退。

## PHP 镜像的标签

- `tree-<哈希>`：`docker/php/` 目录的 git tree 哈希。发版时先查这个标签，已存在就直接复用、不重新构建，
  所以 `docker/php/` 不变时，各次发布用的是同一个 PHP 镜像。
- `sha-<commit>`：构建这个 PHP 镜像的提交。只有真正构建时才打。
- `latest`：两个镜像都推送成功后，并且本次标签是仓库里最新的发布标签时，才指向本次用的 PHP 镜像。
  `mw.Dockerfile` 的默认 `PHP_IMAGE` 用的是它。

PHP 镜像不打 MediaWiki 的发布标签。某个发布用的是哪个 PHP 镜像，看 mw 镜像的
`org.opencontainers.image.base.name` / `org.opencontainers.image.base.digest` 标签。

base-images 时期的 `0.x` 标签（如 `0.2.0`、`0.2`）不再更新，需要固定版本时改用 `tree-<哈希>` 或 digest。

## 升级 PHP、扩展、Caddy 或系统包

`docker/php/Dockerfile` 里的版本都是写死的：基础镜像和 Caddy 按 digest、PECL 扩展和 Composer 按版本号、
install-php-extensions 按 checksum。升级时改对应的值（取值方法见 Dockerfile 里的注释）。
只想拿 Debian 安全更新时，把基础镜像的 digest 换成同一标签的最新 digest 即可。
改了 `docker/php/` 下任何文件，tree 哈希就会变，下一次发版自动重新构建。

## PHP/FPM 配置和线上的关系

配置分两层，同名设置以后加载的为准：

| 文件 | 镜像里的路径 | 管什么 |
|---|---|---|
| `docker/php/config/php.ini` | `conf.d/50-mooncell.ini` | 三站通用、跟代码相关的设置：`disable_functions`、上传大小、`error_reporting` 等 |
| `docker/php/config/php-fpm.conf` | `php-fpm.d/zz-mooncell.conf` | 三站通用的 FPM 设置：日志、`[www]` 的超时 |
| docker-config 的 `k8s/overlays/<站>/php.ini` | 挂到 `conf.d/php.ini` | 各站的 APCu、OPcache 内存大小等 |
| docker-config 的 `k8s/overlays/<站>/php-fpm.conf` | 挂到 `php-fpm.d/zzz-docker.conf` | 进程数、监听地址、状态页、`[thumb]` / `[upload]` 池等跟部署相关的设置 |

两边的文件名决定了加载顺序（`50-mooncell.ini` 在 `php.ini` 之前，`zz-mooncell.conf` 在 `zzz-docker.conf` 之前），
改名时要保持这个顺序。改通用设置走这个仓库发版，改各站的容量走 docker-config。
没挂 docker-config 的场景（构建时的 `composer install`、本地 `docker run`）只有通用设置，其余是 PHP 和官方镜像的默认值。

## 迁移

PHP 构建从 `MooncellWiki/base-images` 迁入，扩展列表保持一致，扩展版本固定为迁移时线上镜像里的版本。
切换发布仓库时，需要在 GitHub 的 `MooncellWiki/php` package 设置中，
通过 **Manage Actions access** 授予 `MooncellWiki/mw` **Write** 权限；
工作流已声明 `packages: write`。迁移完成后删掉旧仓库的 PHP 构建和 php devcontainer（base-images 里另有 PR），避免继续覆盖 `latest`。
参见 [GitHub 的工作流包权限说明](https://docs.github.com/en/packages/learn-github-packages/configuring-a-packages-access-control-and-visibility#ensuring-workflow-access-to-your-package)。

## 本地构建

在仓库根目录运行：

```bash
docker build -t prts-php:local docker/php
docker run --rm prts-php:local php -v
docker run --rm prts-php:local php-fpm -t
docker run --rm prts-php:local caddy version

docker build --build-arg PHP_IMAGE=prts-php:local \
  -f .github/workflows/mw.Dockerfile -t prts-mw:local .
```

第二次构建要读本地镜像 `prts-php:local`，只有 docker 驱动的 builder 看得到本地镜像。
如果当前 builder 是 docker-container 驱动（例如执行过 `docker buildx create --use`），
会去 Docker Hub 拉 `prts-php:local` 而失败；这时用 `--builder` 指定 `docker buildx ls` 里驱动为 `docker` 的那个
（Docker Desktop 上一般是 `desktop-linux`，Linux 上是 `default`）。

只构建 PHP 镜像时，构建上下文不包含 wiki 源码及本地站点配置。
