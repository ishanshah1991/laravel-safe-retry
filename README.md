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

Optionally publish the config (add `--database` to also publish the migration for the database store):

```bash
php artisan safe-retry:install
```

## Usage

Add the `safe-retry` middleware to any route that has side effects:

```php
Route::post('/orders', [OrderController::class, 'store'])->middleware(['auth:sanctum', 'safe-retry']);
```

Put it **after** your auth middleware, so keys can be scoped to the user.

Or use the route macro:

```php
Route::post('/orders', [OrderController::class, 'store'])->safeRetry();
Route::post('/payments', [PaymentController::class, 'store'])->safeRetry('required', 'ttl=3600');

// For groups, build the middleware string:
use IshanShah\SafeRetry\Http\Middleware\SafeRetry;

Route::middleware(['auth:sanctum', SafeRetry::using('required')])->group(function () {
    // ...
});
```

Inside your controller, `$request->idempotencyKey()` returns the key the client sent, for example to store it on the order you create.

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

### Calling other APIs: `Http::withIdempotencyKey()`

The package also helps when *your* app is the client. `withIdempotencyKey()` adds an `Idempotency-Key` header (a fresh UUID unless you pass one), and Laravel sends the same header on every `retry()`:

```php
Http::withIdempotencyKey()->retry(3, 200)->post('https://api.stripe.com/v1/charges', $data);

// Your own key, so it is also the same across queue job retries
Http::withIdempotencyKey("order-{$order->id}")->post($url, $data);

// A different header name
Http::withIdempotencyKey($key, 'X-Idempotency-Key')->post($url, $data);
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

## Encryption

Stored responses can contain personal or payment data. Encrypt them at rest with your `APP_KEY`:

```env
SAFE_RETRY_ENCRYPT=true
```

This works with every store. If `APP_KEY` changes, old records can't be read, and those keys behave as new.

## Queued jobs: `SafeRetryJob` middleware

Queues deliver at least once, so a job can run twice. Add the job middleware to make its work happen only once per key:

```php
use IshanShah\SafeRetry\Jobs\SafeRetryJob;

class ChargeOrder implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public function middleware(): array
    {
        return [new SafeRetryJob("charge-order:{$this->order->id}")];
    }
}
```

- After a job with the key finishes, later jobs with that key are skipped (removed from the queue without running).
- While one is running, a duplicate is released back onto the queue (after `jobs.release_after` seconds, 10 by default).
- If the job throws, fails or releases itself, it is **not** marked done, so its retry runs again.
- Without a key, it uses the job's `safeRetryKey()` method if it has one, otherwise the queued message's UUID (which guards against the same message being delivered twice).
- Options: `SafeRetryJob::make($key)->ttl(3600)->releaseAfter(30)->store('redis')`.
- `SafeRetry::hasJob($key)` and `SafeRetry::forgetJob($key)` check or reset a key.

## Any code: `SafeRetry::once()`

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
- `SafeRetry::hasOnce($key)` tells you whether it has run; `SafeRetry::forgetOnce($key)` makes it run again.

## Scoping keys

By default a key belongs to the logged-in user (and to nobody for guests). To scope by something else, like a tenant or API client, register a resolver in a service provider's `boot()` method:

```php
use IshanShah\SafeRetry\Facades\SafeRetry;

SafeRetry::scopeUsing(fn (Request $request) => $request->header('X-Tenant-Id'));
```

Set `scope_by_user` to `false` to share keys across all users.

## Inspecting and resetting keys

```php
SafeRetry::findRequest($key, $user);    // the stored record, or null
SafeRetry::forgetRequest($key, $user);  // the next request with this key runs again
SafeRetry::forgetRequest($key, 'tenant-42');  // with a custom scope
```

From the command line:

```bash
php artisan safe-retry:forget 7f3a-key --user=15
php artisan safe-retry:forget 7f3a-key --scope=tenant-42
php artisan safe-retry:forget charge-order:15 --once
php artisan safe-retry:forget charge-order:15 --job
```

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
| `max_key_length` | `255` | Longer keys are rejected with 400 |
| `key_pattern` | `null` | Regex keys must match (e.g. UUIDs only) |
| `scope_by_user` | `true` | Scope keys to the authenticated user |
| `store_server_errors` | `false` | Replay 5xx responses too |
| `excluded_headers` | `set-cookie, date` | Headers never stored or replayed |
| `max_response_size` | `null` | Larger bodies (bytes) are not stored |
| `encrypt` | `false` | Encrypt stored records with `APP_KEY` |
| `echo_key` | `false` | Send the key back in the response's `Idempotency-Key` header |
| `fingerprinter` | `DefaultFingerprinter` | Decides what counts as "the same request" |
| `fingerprint.ignore` | `[]` | Body fields left out of the fingerprint |
| `fingerprint.headers` | `[]` | Request headers included in the fingerprint |
| `jobs.ttl` | `86400` | Seconds a finished job key is remembered |
| `jobs.release_after` | `10` | Seconds before a running duplicate job is retried |

### What counts as the same request

The default fingerprinter hashes the method, path, query string and body. JSON and form bodies are compared by value, so key order and whitespace don't matter; for multipart requests the uploaded files' contents count.

```php
'fingerprint' => [
    'ignore' => ['meta.sent_at'],   // a client timestamp that changes on each retry
    'headers' => ['X-Currency'],    // a header that changes what the request means
],
```

For full control, implement `IshanShah\SafeRetry\Contracts\Fingerprinter` and set `fingerprinter` in the config.

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

All in `IshanShah\SafeRetry\Events`, useful for logging and metrics:

| Event | When | Properties |
| --- | --- | --- |
| `ResponseStored` | A response was saved for replay | `request`, `response`, `idempotencyKey` |
| `ResponseReplayed` | A retry was answered from the stored response | `request`, `response`, `idempotencyKey` |
| `IdempotencyKeyInUse` | A duplicate was rejected with 409 | `request`, `idempotencyKey` |
| `IdempotencyKeyMismatched` | A key was reused for a different request (422) | `request`, `idempotencyKey` |
| `DuplicateJobSkipped` | `SafeRetryJob` skipped a job that already ran | `job`, `key` |

## Testing your app

The package adds assertions to Laravel's test responses:

```php
$this->postJson('/orders', $data, ['Idempotency-Key' => 'k'])->assertCreated()->assertNotReplayed();
$this->postJson('/orders', $data, ['Idempotency-Key' => 'k'])->assertCreated()->assertReplayed();
```

In tests, `SAFE_RETRY_STORE=cache` with the `array` cache driver needs no setup.

## Notes

- Streamed and file download responses are never stored; retries of those run again.
- Cookies (`Set-Cookie`) are not replayed by default.
- Pick `lock_timeout` longer than your slowest request. If a request crashes, its lock expires after this time and the key can be used again.

## Running the package's tests

```bash
composer test
```

Redis tests run against `127.0.0.1:6379`, database 15, and are skipped if Redis is unavailable.

## License

MIT. See [LICENSE](LICENSE).
