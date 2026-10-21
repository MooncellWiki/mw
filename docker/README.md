# 生产镜像构建

`.github/workflows/build.yml` 在推送 `v*.*.*-*` 标签时，依次构建并发布：

1. `ghcr.io/mooncellwiki/php`：PHP 8.3.35 FPM、扩展、Composer 和 PHP/FPM 配置，构建上下文为 `docker/php/`。
2. `ghcr.io/mooncellwiki/mw`：通过 `PHP_IMAGE` 构建参数使用上一步返回的 PHP 镜像 digest。
3. `ghcr.io/mooncellwiki/caddy`：从本次发布的 MediaWiki 镜像提取静态资源。

PHP 镜像保留 `latest`，同时打上本次发布标签（如 `v1.43.5-20`）和 `sha-<commit>` 标签。
这里的发布标签对应 MediaWiki 版本；PHP 本身的版本由 `php/Dockerfile` 的 `FROM` 指定。
三个镜像各自使用 GHCR 的 `buildcache`，PHP 文件未变化时可以复用缓存。

PHP 构建及 `php/config/` 从 `MooncellWiki/base-images` 迁入，扩展列表和运行配置保持一致。
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

只构建 PHP 镜像时，构建上下文不包含 wiki 源码及本地站点配置。
