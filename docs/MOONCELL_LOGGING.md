# 三站应用日志

`LocalSettings.php` 在读取站点 `etc/config.php` 后加载仓库内的 `LoggingSettings.php`。
三个站使用同一份 Monolog 配置，默认将所有 channel 的 WARNING 及以上写成单行 JSON 到 `php://stderr`。
不需要在宿主机 etc 下创建日志文件，也不需要增加日志目录挂载。

FPM 镜像已配置 `catch_workers_output = yes`、`decorate_workers_output = no`，将 worker 的 stderr
转到容器 stderr，现有 Alloy 会将其送进 Loki。CLI 维护命令的 stderr 仍由调用者收集；生产 cron 经
`logger` 写入 journal，不会出现在 FPM 主进程的容器日志中。
FPM 的 `log_limit` 从 8 KiB 提高到 64 KiB，避免常见的长 JSON/调用栈被切成多行；单条事件仍应保持在此限制以内。

JSON 包含 `@timestamp`、`level`、`channel`、`message`、`wiki`、`host`、`mwversion`、`reqId`、`process_id`。
PSR-3 context 使用 `context_` 前缀；异常在 `context_exception` 中，含类型、消息、位置、脱敏调用栈和前一个异常。
函数参数值替换成类型；CLI 参数不写入日志。业务 context 和异常消息仍由调用者负责，不能直接放入凭据或请求正文。

`exception-json` 关闭，避免同一异常重复输出；`silenced-error` 关闭，尊重 `@` 和被 error_reporting 排除的级别。
PHP 配置恢复 E_WARNING，继续屏蔽 notice/deprecation。`wgPropagateErrors = false` 防止 MW 处理的 warning
再被原生 PHP 输出一次；启动阶段的错误和 PHP fatal 仍由 `log_errors = On` 兜底。

数据库错误通过 `rdbms` / `wfLogDBError` 使用相同出口，不再依赖 `$wgDBerrorLog = true` 或旧文件路径。
站点 `etc/post-config.php` 不应再覆盖 logger 配置。Sentry 接入、采样、慢请求栈和跨层请求 ID 不在这次改动范围内。

部署需要发布新的 mw/PHP 镜像，再在 docker-config 更新三站镜像版本。配置文件为根目录 `*.php`，现有 mw Dockerfile 会自动打包。
不要将这里的默认日志等级改成 DEBUG 来长期排障；需要详细日志时，应仅对具体 channel 临时开启并监控日志量。

Loki 查询示例：

```logql
{namespace="prts", container="prts-php-1"} | json | level=~"WARNING|ERROR|CRITICAL|ALERT|EMERGENCY"
```

```logql
{namespace="prts", container="prts-php-1"} | json | reqId="<错误页或日志中的请求 ID>"
```
