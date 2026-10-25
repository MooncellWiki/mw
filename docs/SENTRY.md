# Sentry request correlation

The PHP SDK is initialized by the deployment's `etc/post-config.php`. That file
is outside version control; local development intentionally disables reporting.
Do not copy production credentials into this repository.

## Data flow

```text
MediaWikiEntryPoint::run (transaction, op=http.server, name=MW_ENTRY_POINT)
  mw.setup
  mw.execute
  mw.prepare_output
  mw.post_output
    DeferredUpdates (including SemanticMediaWiki)
```

The transaction is bound to a dedicated Sentry scope throughout the request,
including post-send updates. Each phase temporarily becomes the active span.
Exceptions and SDK logs captured in that scope inherit its trace context.
`mw_request_id` is set before setup, so errors and the transaction have the same
request tag. Request method and action are transaction data; page titles and
arbitrary URLs are deliberately not transaction names.

The SDK starts profiling when a transaction is selected for profiling, and
`finish()` stops it and attaches the profile to the transaction event. The profile
payload contains the transaction event ID and trace ID. No separate profiler or
independent trace ID should be created. The transaction finishes in `finally`,
including when setup throws, and the caller's scope is restored afterwards.
PHP process termination or a hard FPM kill cannot be recovered by `finally`.

## Sampling and investigation

Production configuration verified on 2026-10-25:

- `sample_rate = 1.0`: report errors in full, subject to filtering and server quotas.
- `traces_sample_rate = 0.01`: sample 1% of transactions.
- `profiles_sample_rate = 0.1`: profile 10% of sampled transactions (nominally 0.1%
  of requests, subject to profiler support and SDK eligibility).

These rates are unchanged by request correlation. An unsampled request still
has a shared trace ID on its errors, but no stored transaction/profile is promised.
For a focused investigation, temporarily increase tracing/profile sampling for
an explicitly chosen request population; do not expect missing history to return.

Start from an issue event's trace link, then inspect phase durations and any
attached profile. Alternatively search errors and transactions using the same
`mw_request_id`. A profile is useful for PHP call-stack attribution; phase spans
separate request work from post-send work. Neither implies per-query SQL timing.

Previously the transaction was named `run` and was never bound to the scope.
Historical errors may therefore have different trace IDs from their transactions.
New transaction names use the entry point (e.g. `index` or `api`); saved searches
and profile views filtered to `transaction=run` need to be updated. This change
does not ingest or relink historical data, instrument individual SQL/cache calls,
or join browser traces to backend traces.

## Regression check

Run `vendor/bin/phpunit tests/phpunit/unit/includes/MediaWikiEntryPointSentryTest.php`
with the project's PHP runtime. On installations whose settings require a database,
set `MW_CONFIG_FILE` to a minimal test-only settings file to avoid loading local
extensions. The tests discard events in SDK callbacks and send no network data.
They verify sampled and unsampled error correlation, post-send phase association,
request tags, transaction completion on setup failures, and caller scope restoration.
