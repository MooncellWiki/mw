# Sentry request correlation (MediaWiki 1.43)

## Configuration and ownership

`MooncellSentry` owns SDK initialization, exception capture/filtering, request
tracing and profile finalization. `LocalSettings.php` loads the extension. Each
site's `mooncell-docker-config/k8s/overlays/{prts,fgo,mooncell}/post-config.php`
only needs its project DSN:

```php
$wgMooncellSentryDsn = 'http://<project-public-key>@sentry-relay:3000/<project-id>';
```

Kustomize puts that file in `wiki-config`, mounted at `/srv/etc/post-config.php`.
The extension starts through manifest `ExtensionFunctions`, after these settings
are loaded and RequestContext is initialized. An empty DSN leaves initialization
and capture disabled, so local development needs no reporting configuration.

Default PHP SDK options preserve the existing production policy:

- `sample_rate = 1.0`: errors in full, subject to filters/server quotas.
- `traces_sample_rate = 0.01`: 1% of transactions.
- `profiles_sample_rate = 0.1`: 10% of sampled transactions (nominally 0.1% of
  requests, subject to profiler support and eligibility).
- `enable_logs = true`: SDK structured logs enabled.

Sites can override SDK options with `$wgMooncellSentryOptions`, for example:

```php
$wgMooncellSentryOptions = [ 'traces_sample_rate' => 0.05 ];
```

The dedicated DSN setting takes precedence over an options `dsn`. The extension
removes SDK global PHP/exception/fatal listeners while retaining other default
integrations and site-provided integrations. MediaWiki already owns those error
handlers, so all exceptions flow through `LogException` once. Suppressed errors
are skipped; accepted errors mark the request failed and are captured inside
`withScope()` with `mw_request_id`. CLI errors are captured without starting a
request transaction/profile.

## Request lifecycle

```text
MediaWiki Setup.php
  extension.json ExtensionFunctions
    initialize SDK from site DSN/options
    bind request transaction + mw_request_id (web requests)
  normal request / output / DeferredUpdates
    LogException → suppressed filter → one scoped capture
    errors and SDK logs inherit the active trace
PHP shutdown
  earlier error reporters (e.g. MediaWiki's fatal handler)
  finish transaction + existing SDK profile
```

Tracing starts during late setup, stays active through post-send processing, and
finishes idempotently at PHP shutdown, restoring the caller's scope. The SDK
attaches a selected profile with the transaction event ID and trace ID. Work
before initialization is not profiled. Later registered shutdown callbacks and
hard PHP/FPM termination are not guaranteed to be covered. The core lifecycle is
unchanged; `MediaWikiEntryPoint::run()` only loses the old Sentry calls.

## Migration

Deploy the MW image containing this extension first. Until the overlays switch,
an empty extension DSN with a legacy externally initialized SDK client retains
tracing while the old config continues capturing exceptions. This avoids double
capture and allows the two repositories to deploy separately.

Then apply the docker-config change replacing `Sentry\init()` and the three
copied `LogException` closures with `$wgMooncellSentryDsn`. Do not enable the new
DSN while retaining an old capture closure. Merging the MW PR alone does not
publish/deploy an image: build a release and update the deployment's pinned image
before merging the configuration change. To roll back to an old image, restore
the old overlay configuration first; old images do not interpret the new setting.

## What 1.43 provides

The checked REL1_43-based source provides:

- `Wikimedia\Telemetry\TracerInterface`, `MediaWikiServices::getTracer()` and
  `$wgOpenTelemetryConfig`, all introduced in 1.43. They are a minimal OTLP tracing
  client, not a Sentry/profile adapter. In this branch no request/SQL code calls
  `createRootSpan()` or `getTracer()` to build a request span tree; enabling that
  configuration alone will not supply one or correlate Sentry profiles.
- The manifest's `ExtensionFunctions` callbacks, which provide
  a stable place to initialize this integration after site settings.
- `LogException`, intended for external exception aggregation integrations.
- `BeforeInitialize` (index.php) / `ApiBeforeMain` (api.php), which cover only
  particular entry points. `AfterFinalPageOutput` fires before output buffers
  are flushed and does not mean that post-send updates have completed.

There is no matching request-complete hook in this 1.43 source. We use PHP shutdown
for completion instead of patching core or treating a display hook as request end.
No phase spans are inferred from display hooks. Use sampled profiles for PHP stack attribution; this extension does not claim to
provide exact execution/post-send boundaries or individual SQL timings.

References:

- [Extension initialization functions](https://www.mediawiki.org/wiki/Manual:$wgExtensionFunctions)
- [LogException hook](https://www.mediawiki.org/wiki/Manual:Hooks/LogException)
- [AfterFinalPageOutput hook](https://www.mediawiki.org/wiki/Manual:Hooks/AfterFinalPageOutput)
- [OpenTelemetry configuration](https://www.mediawiki.org/wiki/Manual:$wgOpenTelemetryConfig)

## Investigation

Errors, SDK logs and transactions share a trace ID; error events and transactions
also carry `mw_request_id`. An unsampled request still has a consistent trace ID,
but no stored transaction/profile is promised. For focused investigations,
temporarily increase sampling for a chosen population using the options setting.

Start from an issue event's trace link and inspect its transaction/profile, or
search errors/transactions by request ID. Transaction names are entry points
such as `index` or `api`, with method/action in transaction data. Views filtered
to `transaction=run` need to change. Historical errors may have different trace
IDs from their transactions; this does not relink history, join browser/backend
traces, or ingest ordinary MediaWiki logs.

## Regression checks

Run `vendor/bin/phpunit extensions/MooncellSentry/tests/phpunit/unit/RequestTraceTest.php`
with the project PHP runtime. If local settings require an unavailable database,
set `MW_CONFIG_FILE` to minimal test-only settings that load `MooncellSentry`.
SDK callbacks discard test events/logs/transactions without network I/O.
Tests cover sampled/unsampled correlation, restoration, repeated finalization and
real Excimer profile IDs. Isolated processes load real MW setup/DeferredUpdates
for extension-managed, legacy, disabled and CLI modes, exercising actual PHP
warnings, suppressed filtering, deferred/shutdown errors and default options.
