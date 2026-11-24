# laravel-safe-retry

Make your Laravel API endpoints safe to retry. Prevents duplicate charges, double orders, and repeat side effects using idempotency keys - backed by Redis, Database, or Cache.

A client sends a unique `Idempotency-Key` header with a request. If the network drops and the client retries with the same key, your route does **not** run again: the client gets the response from the first attempt.

```
POST /orders   Idempotency-Key: 7f3a…   → 201 Created  (order created)
POST /orders   Idempotency-Key: 7f3a…   → 201 Created  (same response replayed, Idempotent-Replayed: true)
```

## What it does

| Situation | Response |
| --- | --- |
| First request with a key | Runs normally; the response is stored |
| Retry with the same key and same payload | Stored response replayed, with `Idempotent-Replayed: true` |
| Retry while the first request is still running | `409 Conflict` with `Retry-After` (or wait for it, see `wait`) |
| Same key reused with a different payload | `422 Unprocessable Entity` |
| Key missing on a route marked `required` | `400 Bad Request` |
| First request failed with a 5xx | Not stored, so the retry runs again |

Keys are scoped to the authenticated user by default, so two users can never collide or see each other's responses.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

```bash
composer require ishanshah1991/laravel-safe-retry
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=safe-retry-config
```

## Usage

Add the `safe-retry` middleware to any route that has side effects:

```php
Route::post('/orders', [OrderController::class, 'store'])->middleware(['auth:sanctum', 'safe-retry']);
```

Put it **after** your auth middleware, so keys can be scoped to the user.

### Per-route options

```php
// Reject requests that do not send a key
->middleware('safe-retry:required')

// Remember responses for 1 hour, use the redis store,
// and let a duplicate wait up to 5s for the original to finish
->middleware('safe-retry:required,ttl=3600,store=redis,wait=5')
```

| Option | Meaning |
| --- | --- |
| `required` / `optional` | Override the global `required` setting |
| `ttl=<seconds>` | How long the response is replayed |
| `store=<name>` | `cache`, `redis` or `database` |
| `wait=<seconds>` | Wait for an in-flight duplicate instead of answering 409 at once |
| `lock=<seconds>` | Longest time a request may hold the in-flight lock |

### Client side

Generate a fresh key (a UUID is ideal) for each *logical* operation, and send the same key on every retry of it:

```js
const key = crypto.randomUUID();
await fetch('/api/orders', {
  method: 'POST',
  headers: { 'Idempotency-Key': key, 'Content-Type': 'application/json' },
  body: JSON.stringify(order),
});
```

## Stores

Choose a store with `SAFE_RETRY_STORE`. It must be shared between all your app servers.

### Cache (default)

Uses any Laravel cache store that supports atomic locks: `redis`, `memcached`, `database`, `dynamodb` (and `array` in tests).

```env
SAFE_RETRY_STORE=cache
SAFE_RETRY_CACHE_STORE=redis   # leave empty for your default cache store
```

### Redis

Talks to a Redis connection from `config/database.php` directly.

```env
SAFE_RETRY_STORE=redis
SAFE_RETRY_REDIS_CONNECTION=default
```

### Database

Good when you want records to sit next to your data, or don't run Redis.

```bash
php artisan vendor:publish --tag=safe-retry-migrations
php artisan migrate
```

```env
SAFE_RETRY_STORE=database
```

Expired rows are not deleted on their own. Schedule the prune command:

```php
// routes/console.php
Schedule::command('safe-retry:prune')->hourly();
```

### Custom stores

Implement `IshanShah\SafeRetry\Contracts\Store` and register it:

```php
use IshanShah\SafeRetry\Facades\SafeRetry;

SafeRetry::extend('dynamo', fn ($app) => new MyDynamoStore(/* ... */));
```

## Idempotent jobs and other code: `SafeRetry::once()`

Not every side effect comes from an HTTP request. `once()` runs a callback at most once per key and returns the stored result after that:

```php
use IshanShah\SafeRetry\Facades\SafeRetry;

class ChargeOrder implements ShouldQueue
{
    public function handle(): void
    {
        $charge = SafeRetry::once("charge-order:{$this->order->id}", function () {
            return $this->stripe->charges->create([/* ... */])->id;
        });
    }
}
```

- If the callback throws, nothing is stored and the next call runs it again.
- If another process is running the same key right now, `IdempotencyKeyInUseException` is thrown. In a queued job, let it fail so the job is retried later.
- Results are stored with `serialize()`, so return plain values or serializable objects.
- `SafeRetry::once($key, $callback, ttl: 3600, store: 'redis')` overrides the TTL and store.
- `SafeRetry::forgetOnce($key)` makes it run again.

## Configuration

See [config/safe-retry.php](config/safe-retry.php) for every option. The main ones:

| Key | Default | Meaning |
| --- | --- | --- |
| `store` | `cache` | Default store |
| `header` | `Idempotency-Key` | Request header holding the key |
| `replayed_header` | `Idempotent-Replayed` | Set to `true` on replayed responses |
| `methods` | `POST, PUT, PATCH, DELETE` | Other methods pass through untouched |
| `required` | `false` | Reject requests without a key |
| `ttl` | `86400` | Seconds a response is replayed |
| `lock_timeout` | `60` | Seconds the in-flight lock lasts (should exceed your slowest request) |
| `wait` | `0` | Seconds a duplicate waits for the original |
| `scope_by_user` | `true` | Scope keys to the authenticated user |
| `store_server_errors` | `false` | Replay 5xx responses too |
| `excluded_headers` | `set-cookie, date` | Headers never stored or replayed |
| `fingerprinter` | `DefaultFingerprinter` | Decides what counts as "the same request" |

### What counts as the same request

The default fingerprinter hashes the method, path, query string and body (for form and multipart requests: the fields and uploaded file contents). To change it, for example to ignore a field that changes between retries, implement `IshanShah\SafeRetry\Contracts\Fingerprinter` and set `fingerprinter` in the config.

## Errors

Errors are thrown as HTTP exceptions, so Laravel renders them with the right status (JSON for API requests). All extend `IshanShah\SafeRetry\Exceptions\SafeRetryException`, so you can customise them in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(fn (IdempotencyKeyInUseException $e) => response()->json([
        'error' => 'request_in_progress',
    ], 409));
})
```

| Exception | Status |
| --- | --- |
| `MissingIdempotencyKeyException` | 400 |
| `InvalidIdempotencyKeyException` | 400 |
| `IdempotencyKeyInUseException` | 409 |
| `IdempotencyKeyMismatchException` | 422 |

## Events

`IshanShah\SafeRetry\Events\ResponseReplayed` fires whenever a response is replayed. It carries the `request`, `response` and `idempotencyKey`, which is useful for logging and metrics.

## Notes

- Streamed and file download responses are never stored; retries of those run again.
- Cookies (`Set-Cookie`) are not replayed by default.
- Pick `lock_timeout` longer than your slowest request. If a request crashes, its lock expires after this time and the key can be used again.

## Testing

```bash
composer test
```

Redis tests run against `127.0.0.1:6379`, database 15, and are skipped if Redis is unavailable.

## License

MIT. See [LICENSE](LICENSE).
