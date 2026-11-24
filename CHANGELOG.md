# Changelog

## Unreleased

- `safe-retry` middleware: replays stored responses for repeated `Idempotency-Key`s, returns 409 while a request with the same key is in flight, and 422 when a key is reused with a different payload.
- Cache, Redis and Database stores.
- `SafeRetry::once()` for idempotent jobs and other non-HTTP code.
- `safe-retry:prune` command for the database store.
