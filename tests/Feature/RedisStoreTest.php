<?php

namespace IshanShah\SafeRetry\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisStoreTest extends MiddlewareTestCase
{
    protected function storeName(): string
    {
        return 'redis';
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $prefix = config('safe-retry.prefix');

        try {
            $keys = Redis::connection()->keys($prefix.'*');
            foreach ($keys as $key) {
                Redis::connection()->del(preg_replace('/^'.preg_quote(config('database.redis.options.prefix', ''), '/').'/', '', $key));
            }
        } catch (Throwable) {
        }

        parent::tearDown();
    }

    public function test_route_options_override_ttl_and_store(): void
    {
        // Redis expiry runs on the server clock, so time travel cannot be used.
        // TTL handling is covered by the cache and database store tests.
        $this->markTestSkipped('Not applicable to Redis.');
    }
}
