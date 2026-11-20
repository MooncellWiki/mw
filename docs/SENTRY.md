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
`LocalSettings.php` queues the extension with `wfLoadExtension( 'MooncellSentry' )`
and reads the site configuration. MediaWiki validates the queued manifests and
then runs the extension's registration callback, which initializes Sentry.
For manifest or dependency validation failures, check the PHP logs. An empty
DSN preserves a client supplied by the legacy site configuration.

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

The dedicated DSN takes precedence over the SDK options. The SDK applies its own
integration rules, including site replacements for default integrations such as
`RequestIntegration`. Its PHP handlers are active from the registration callback.
After MediaWiki installs its handlers, the extension captures unsuppressed
`LogException` events.
The SDK's fatal shutdown listener covers engine failures such as memory exhaustion.

## Request lifecycle

```text
LocalSettings.php → wfLoadExtension( 'MooncellSentry' ) → site post-config.php
MediaWiki setup
  validate manifests → registration callback initializes the SDK and tags the request ID
  install MW handlers → LogException captures errors during setup
  ExtensionFunctions → start and bind the web request transaction
Request, output and DeferredUpdates
  capture exceptions and collect SDK logs on the active trace
PHP shutdown
  remember the initial PHP error type → SDK fatal listener captures engine fatals
  finish transaction/profile → flush SDK logs → restore scope → flush transport
```

The transaction uses the final HTTP status. A recoverable warning on a successful
response produces an error event and an `ok` transaction. An engine fatal marks
the transaction `internal_error`, including after response headers have been sent.
When a web job temporarily adopts its originating request ID, its error retains
the HTTP transaction's `mw_request_id` and records the job's ID in
`mw_job_request_id`. CLI errors carry the current job's request ID, including
native fatals captured directly by the SDK. CLI workers flush logs on shutdown.
A setup failure after registration also flushes logs collected up to that point.

Profiling starts in late setup. A sampled profile carries the transaction event ID
and trace ID. The request scope covers post-send updates and shutdown reporters
registered before the finalizer. Hard FPM termination can interrupt delivery.

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
- The manifest registration callback runs before MediaWiki installs its error
  handlers. `ExtensionFunctions` runs later and provides the request context for
  the transaction.
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

The web transaction continues the trace used by SDK logs and captured errors
during bootstrap. Error events and transactions also carry `mw_request_id`.
Unsampled requests keep the trace ID on errors and logs. Stored transactions
and profiles depend on sampling. For focused investigations, temporarily
increase sampling for a chosen population using the options setting.

Start from an issue event's trace link and inspect its transaction/profile, or
search errors/transactions by request ID. Transaction names are entry points
such as `index` or `api`. Every web transaction records `http.request.method`.
The `index` and `api` entry points also record `mw.action`, defaulting to `view`
and `help` respectively. Views filtered to `transaction=run` need to change.
Historical errors may have different trace IDs from their transactions.
Browser-to-backend tracing and ingestion of ordinary MediaWiki logs require
separate integrations.

## Regression checks

Run `vendor/bin/phpunit extensions/MooncellSentry/tests/phpunit/unit/RequestTraceTest.php`
with the project PHP runtime. If local settings require an unavailable database,
set `MW_CONFIG_FILE` to minimal test-only settings that load `MooncellSentry`.
The lifecycle tests run isolated PHP processes with a recording SDK transport.
They exercise boot failures immediately after extension registration and during
`SetupAfterCache`, a native OOM, suppression, HTTP status, deferred updates,
job request IDs, custom integrations and log delivery at shutdown. Web,
unsampled, CLI, legacy and disabled configurations share the same fixture.
The correlation tests also check caller scope restoration and real Excimer
profile IDs when the profiler is installed.
