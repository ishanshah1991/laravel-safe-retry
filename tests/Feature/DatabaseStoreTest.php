<?php

namespace IshanShah\SafeRetry\Tests\Feature;

use Illuminate\Support\Facades\DB;
use IshanShah\SafeRetry\SafeRetryManager;

class DatabaseStoreTest extends MiddlewareTestCase
{
    protected function storeName(): string
    {
        return 'database';
    }

    public function test_expired_lock_can_be_taken_over(): void
    {
        $store = $this->app->make(SafeRetryManager::class)->store();

        $this->assertNotNull($store->acquireLock('k', 5));
        $this->assertNull($store->acquireLock('k', 5));

        $this->travel(6)->seconds();

        $this->assertNotNull($store->acquireLock('k', 5));
    }

    public function test_release_only_removes_own_lock(): void
    {
        $store = $this->app->make(SafeRetryManager::class)->store();

        $owner = $store->acquireLock('k', 60);
        $store->releaseLock('k', 'someone-else');
        $this->assertNull($store->acquireLock('k', 60));

        $store->releaseLock('k', $owner);
        $this->assertNotNull($store->acquireLock('k', 60));
    }

    public function test_prune_command_removes_expired_rows(): void
    {
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'p']);
        $this->assertSame(1, DB::table('safe_retry_keys')->count());

        $this->artisan('safe-retry:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('safe_retry_keys')->count());

        $this->travel(2)->days();

        $this->artisan('safe-retry:prune')->expectsOutputToContain('Pruned 1')->assertSuccessful();
        $this->assertSame(0, DB::table('safe_retry_keys')->count());
    }
}
