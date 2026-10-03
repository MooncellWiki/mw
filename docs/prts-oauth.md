# PRTS OAuth 身份提供方

PRTS 使用 MediaWiki OAuth 扩展，为 AK Asset MCP 的服务端授权桥接层提供身份认证。
这不是 MCP 授权服务器：Wiki token 只用于获取 Wiki 身份，桥接层另行签发 MCP token。
现有 OATHAuth 是双因素认证扩展，与 OAuth 同时保留。

## 镜像与启用开关

OAuth 子模块固定到 REL1_43 的提交；依赖通过根目录的 Composer merge-plugin 安装，
与其他扩展共用 `composer.lock`。CI 已递归检出子模块，现有镜像构建会包含 OAuth。
PHP 运行环境必须包含 `openssl` 和 `sodium`（后者由 JWT 依赖要求）；
本地 phpbrew 环境也需启用这两个扩展，不应通过忽略平台要求来安装。

```sh
git submodule update --init --recursive
composer install --no-dev --no-interaction
```

共享镜像默认不加载 OAuth。只有在该站点的 `etc/config.php` 设置
`$mcOAuthEnabled = true;` 才会加载；其他 Wiki 不需要同步配置或迁移。
密钥、站点配置、客户端密钥不得提交到仓库。

## PRTS 部署

1. 备份 PRTS 数据库。在已挂载到 PHP 容器的私有 `etc/` 下创建 `oauth/` 目录：

   ```sh
   umask 077
   mkdir -p etc/oauth
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out etc/oauth/private.pem
   openssl pkey -in etc/oauth/private.pem -pubout -out etc/oauth/public.pem
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > etc/oauth/secret
   ```

   将目录和文件交给实际 PHP-FPM 运行用户读取；私钥和 secret 保持 `0600`，
   目录保持 `0700`。不要在每次部署时重新生成。现有 Caddy 禁止访问 `/etc/*`，
   仍须确保密钥没有通过其他静态服务暴露。

2. 在 PRTS 的 `etc/config.php` 配置：

   ```php
   $mcOAuthEnabled = true;
   $wgOAuth2PrivateKey = 'file://' . __DIR__ . '/oauth/private.pem';
   $wgOAuth2PublicKey = 'file://' . __DIR__ . '/oauth/public.pem';
   $wgOAuthSecretKey = trim( file_get_contents( __DIR__ . '/oauth/secret' ) );
   // 放在生产 $wgSessionCacheType 配置之后，所有 PHP 实例使用同一个缓存。
   $wgMWOAuthSessionCacheType = $wgSessionCacheType;
   $wgOAuth2GrantExpirationInterval = 'PT1H';
   $wgOAuth2RefreshTokenTTL = 'P1M';
   ```

   CanonicalServer 必须为 `https://prts.wiki`。保持 HTTPS token 传输保护开启。
   缓存不能是 CACHE_NONE 或仅进程内缓存；本地独立测试可用 CACHE_DB。

3. 在维护窗口内，用新镜像、上述配置执行数据库迁移，再开放流量：

   ```sh
   php maintenance/run.php update --quick --skip-external-dependencies
   ```

   迁移会安装 OAuth 表。仅准备镜像或合并 PR 不会自动执行迁移。
   `Special:Version` 应列出 OAuth，普通 Wiki 页面和 OATHAuth 登录应继续正常工作。

4. 按下节注册并审批应用，然后与桥接层联调。

## 注册 AK Asset MCP 应用

由 `sysop` 在 `Special:OAuthConsumerRegistration/propose` 注册：

| 字段 | 值 |
| --- | --- |
| 应用名 | AK Asset MCP |
| OAuth 版本 | OAuth 2.0 |
| 客户端类型 | Confidential（服务端保存 secret） |
| 回调 | `https://torappu.prts.wiki/oauth/callback/prts` |
| 回调匹配 | 精确匹配，不使用前缀 |
| 授权类型 | authorization_code、refresh_token |
| 权限 | 仅身份验证，`mwoauth-authonly`，不含私有信息 |
| Owner-only | 否，必须支持其他 Wiki 用户授权 |

管理员通过 `Special:OAuthManageConsumers` 审批应用。客户端 ID 和 secret 交给桥接层的
私有配置。普通用户可以同意应用及在 `Special:OAuthManageMyGrants` 撤销自己的授权；
本配置仅给 sysop 注册、修改自己应用及审批应用的权限，不开放自动审批。

桥接层应使用 `state` 和 PKCE S256，包括 confidential client；此版本扩展的强制
PKCE 开关只覆盖 public client。注册表单提供 authorization_code 和 refresh_token，
不提供 client_credentials；每个应用也必须只登记这两种 grant。

## 桥接层接口约定

| 用途 | 接口 |
| --- | --- |
| 浏览器授权 | `GET https://prts.wiki/rest.php/oauth2/authorize` |
| 换码、刷新 | `POST https://prts.wiki/rest.php/oauth2/access_token` |
| 获取身份 | `GET https://prts.wiki/rest.php/oauth2/resource/profile` |

Token 请求使用 `application/x-www-form-urlencoded`。Profile 使用
`Authorization: Bearer <wiki-access-token>`。身份主键使用 `(https://prts.wiki, sub)`，
其中 `sub` 是字符串形式的用户 ID；`username` 只用于展示。此固定版本还返回
`blocked`、`groups` 等字段，隐藏用户可能缺少用户名及用户组，调用方需要处理。
身份验证权限不应返回 `email` 或 `realname`。

不要把这里的 profile 当作 OIDC ID token，也不要把 Wiki access token 交给 MCP 客户端。
桥接层必须自行执行客户端授权、访问资格判断以及 MCP token 的刷新和撤销策略。

## 代理配置（docker-config 仓库中的后续部署工作）

上线前检查 `prts/varnish/default.vcl`、CDN 和 Caddy 的实际行为：

- `/rest.php/oauth2/*` 不缓存，保留 Authorization、Cookie 和查询参数，且不能被移动端域名跳转。
- 浏览器授权会经过 `Special:OAuth/approve` 和 `Special:OAuth/rest_redirect`，未登录时还涉及登录页；
  对实际 OAuth 路径配置不缓存并验证 Sisyphus 不破坏登录返回路径。
- token/profile 是机器接口，不能被反爬验证替换为 HTML。
- OAuth 特殊页可能使用 `/w/Special:…`、本地化标题或 `index.php?title=…`，精确规则需要覆盖实际路径。
- 保留 `rest.php` 的 PATH_INFO 转发；响应中的 token 和授权页面不能被 CDN 缓存。
- 访问日志须脱敏 OAuth code、token、secret；客户端密钥放在 POST body，禁止放在 URL。

本 PR 不修改部署仓库，也不创建生产密钥、应用或迁移生产数据库。

## 联调验收

- 未启用站点：OAuth 不加载，原有登录和页面正常。
- 启用站点：迁移成功，三个 REST 接口返回协议响应而非 404、500 或反爬 HTML。
- 管理员可创建并审批身份应用，普通用户不能注册或审批应用。
- 已登录和未登录用户都能完成授权；拒绝授权可正常返回客户端。
- 携带 PKCE 完成换码，profile 的 sub 稳定且无 email/realname。
- 错误 secret、错误 verifier、错误回调、授权码重复使用均失败。
- refresh_token 可用；用户撤销授权后，旧凭据不能继续获得有效身份。
- 手机浏览器完成同一流程，不跳到 m.prts.wiki 导致回调或会话错误。

参考：[MediaWiki OAuth 扩展](https://www.mediawiki.org/wiki/Extension:OAuth)。
接口与字段以上游固定的 REL1_43 源码为准。
