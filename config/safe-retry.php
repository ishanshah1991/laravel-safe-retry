<?php

use IshanShah\SafeRetry\Support\DefaultFingerprinter;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    |
    | Where idempotency records and in-flight locks are kept. Supported:
    | "cache", "redis", "database". The store must be shared between all
    | your app servers, so the "array" or "file" cache is only good for tests.
    |
    */

    'store' => env('SAFE_RETRY_STORE', 'cache'),

    'stores' => [

        'cache' => [
            // Any cache store that supports atomic locks (redis, memcached,
            // database, dynamodb, array). Null uses your default cache store.
            'store' => env('SAFE_RETRY_CACHE_STORE'),
        ],

        'redis' => [
            'connection' => env('SAFE_RETRY_REDIS_CONNECTION', 'default'),
        ],

        'database' => [
            'connection' => env('SAFE_RETRY_DB_CONNECTION'),
            'table' => 'safe_retry_keys',
            'lock_table' => 'safe_retry_locks',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Key Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('SAFE_RETRY_PREFIX', 'safe-retry'),

    /*
    |--------------------------------------------------------------------------
    | Headers
    |--------------------------------------------------------------------------
    |
    | "header" is the request header clients send their key in. On a replayed
    | response, "replayed_header" is set to "true" so clients can tell.
    |
    */

    'header' => 'Idempotency-Key',

    'replayed_header' => 'Idempotent-Replayed',

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    // Only requests using these methods are made idempotent. Others pass through.
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    // When true, requests without a key are rejected with 400.
    // Can be overridden per route: ->middleware('safe-retry:required')
    'required' => false,

    // How long (seconds) a completed response is remembered and replayed.
    'ttl' => 60 * 60 * 24,

    // Maximum time (seconds) a request may hold the in-flight lock. Should be
    // longer than your slowest request, so a crash never blocks a key forever.
    'lock_timeout' => 60,

    // When a duplicate arrives while the original is still running, wait up
    // to this many seconds for it to finish and replay its response instead
    // of answering 409 Conflict straight away. 0 = answer 409 at once.
    'wait' => 0,

    // Keys longer than this are rejected with 400.
    'max_key_length' => 255,

    // Scope keys to the authenticated user, so two users sending the same
    // key never collide or see each other's responses.
    'scope_by_user' => true,

    // 5xx responses are not stored by default, so the client can retry and
    // the request runs again. Set true to replay server errors too.
    'store_server_errors' => false,

    // Response headers that are never stored or replayed.
    'excluded_headers' => ['set-cookie', 'date'],

    /*
    |--------------------------------------------------------------------------
    | Fingerprinter
    |--------------------------------------------------------------------------
    |
    | Builds a hash of the request. Reusing a key with a different fingerprint
    | (different method, path or payload) is rejected with 422. Must implement
    | IshanShah\SafeRetry\Contracts\Fingerprinter.
    |
    */

    'fingerprinter' => DefaultFingerprinter::class,

];
