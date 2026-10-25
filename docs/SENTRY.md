# Sentry request correlation (MediaWiki 1.43)

## Integration boundary

`MooncellSentry` owns tracing in a local extension. `LocalSettings.php` loads it;
Sentry credentials, sampling and the existing `LogException` capture/filter remain
in the deployment's untracked `etc/post-config.php`. The extension does not call
`Sentry\init()` or capture exceptions a second time. A missing SDK client (as in
local development) or a CLI entry point leaves tracing inactive.

The extension declares `ExtensionFunctions` in its manifest, which MediaWiki
invokes after site configuration has loaded and RequestContext is initialized. This starts and binds the request transaction on a dedicated Sentry scope.
A PHP shutdown callback finishes it after normal post-send `DeferredUpdates` and
after previously registered error reporters. `LogException` marks the request as
failed even for errors caught during deferred updates. Shutdown finalization is
idempotent, finishes the existing SDK profiler and restores the caller's scope.

```text
MediaWiki Setup.php
  extension.json ExtensionFunctions
    MooncellSentry starts/binds transaction + mw_request_id
  normal request / output / DeferredUpdates
    errors and SDK logs inherit the active trace
PHP shutdown
  earlier error reporters (e.g. MediaWiki's fatal handler)
  MooncellSentry finishes transaction + existing SDK profile
```

This starts during late MediaWiki setup, before entry-point execution, and stays
active through post-send processing. Work before initialization is not profiled.
Shutdown callbacks registered later may run after tracing finishes, and hard PHP/
FPM termination cannot guarantee finalization. No core lifecycle wrapper is added:
`MediaWikiEntryPoint::run()` only loses the old Sentry calls.

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

## Sampling and investigation

Production configuration verified on 2026-10-25, unchanged by this integration:

- `sample_rate = 1.0`: errors in full, subject to filtering and server quotas.
- `traces_sample_rate = 0.01`: 1% of transactions.
- `profiles_sample_rate = 0.1`: 10% of sampled transactions (nominally 0.1% of
  requests, subject to profiler support and eligibility).

Errors, SDK logs and transactions share a trace ID. Error events and transactions
also carry the same `mw_request_id` tag. The SDK attaches a selected profile to its
transaction using the transaction event ID and trace ID; no second profiler or
independent trace is needed. An unsampled request still has a consistent trace ID
on errors/logs, but no stored transaction/profile is promised. For focused
investigations, temporarily increase sampling for a chosen request population.

Start from an issue event's trace link, then inspect its transaction and attached
profile, or search errors/transactions by `mw_request_id`. Transaction names are
entry points such as `index` or `api`, with request method/action in transaction
data. Views filtered to the old `transaction=run` need to be updated. Historical
errors may have different trace IDs from their transactions; this change does not
relink history, join browser/backend traces, or ingest ordinary MediaWiki logs.

## Regression checks

Run `vendor/bin/phpunit extensions/MooncellSentry/tests/phpunit/unit/RequestTraceTest.php`
with the project PHP runtime. If local settings require an unavailable database,
set `MW_CONFIG_FILE` to minimal test-only settings that load `MooncellSentry`.
SDK callbacks discard all test events, logs and transactions without network I/O.
Tests cover sampled/unsampled correlation, scope restoration, repeated finalization,
error status, CLI exclusion and real Excimer profile IDs when the extension exists.
An isolated subprocess also loads real MediaWiki setup and DeferredUpdates, then
asserts that execute/deferred/shutdown errors precede the matching transaction.
