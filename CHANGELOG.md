# Changelog

## 1.0.0 - 2026-11-24

- `safe-retry` middleware: replays stored responses for repeated `Idempotency-Key`s, returns 409 while a request with the same key is in flight, and 422 when a key is reused with a different payload.
- Cache, Redis and Database stores.
- `SafeRetry::once()` for idempotent jobs and other non-HTTP code.
- `SafeRetryJob` queue middleware: a job's work runs once per key.
- Optional encryption of stored records.
- Custom scope resolver (`SafeRetry::scopeUsing()`), `findRequest()`, `forgetRequest()`, `hasOnce()`, `hasJob()`, `forgetJob()`.
- `key_pattern`, `max_response_size`, `echo_key`, `fingerprint.ignore` and `fingerprint.headers` options.
- JSON bodies are fingerprinted by value, so key order and whitespace don't matter.
- Events: `ResponseStored`, `ResponseReplayed`, `IdempotencyKeyInUse`, `IdempotencyKeyMismatched`, `DuplicateJobSkipped`.
- `->safeRetry()` route macro, `$request->idempotencyKey()`, `Http::withIdempotencyKey()` client macro.
- `assertReplayed()` / `assertNotReplayed()` test response assertions.
- Artisan: `safe-retry:install`, `safe-retry:prune`, `safe-retry:forget`.
