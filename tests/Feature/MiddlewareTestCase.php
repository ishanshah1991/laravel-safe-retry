<?php

namespace IshanShah\SafeRetry\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use IshanShah\SafeRetry\Events\ResponseReplayed;
use IshanShah\SafeRetry\SafeRetryManager;
use IshanShah\SafeRetry\Tests\TestCase;

/**
 * The same behaviour, run against every store. Subclasses pick the store.
 */
abstract class MiddlewareTestCase extends TestCase
{
    public static int $calls = 0;

    abstract protected function storeName(): string;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('safe-retry.store', $this->storeName());
        $app['config']->set('safe-retry.prefix', 'safe-retry-test-'.uniqid());
    }

    protected function defineRoutes($router): void
    {
        $router->post('/orders', function (Request $request) {
            static::$calls++;

            return response()->json(['order' => static::$calls, 'item' => $request->input('item')], 201)
                ->header('X-Order', (string) static::$calls)
                ->cookie('session_thing', 'abc');
        })->middleware('safe-retry');

        $router->post('/required', fn () => ['ok' => ++static::$calls])->middleware('safe-retry:required');

        $router->get('/orders', fn () => ['ok' => ++static::$calls])->middleware('safe-retry');

        $router->post('/fails', function () {
            static::$calls++;
            abort(503);
        })->middleware('safe-retry');

        $router->post('/throws', function () {
            static::$calls++;
            throw new \RuntimeException('boom');
        })->middleware('safe-retry');

        $router->post('/invalid', function (Request $request) {
            static::$calls++;
            $request->validate(['name' => 'required']);
        })->middleware('safe-retry');
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::$calls = 0;
    }

    public function test_replays_the_stored_response_for_the_same_key(): void
    {
        $first = $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'k1']);
        $second = $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'k1']);

        $first->assertCreated()->assertJson(['order' => 1])->assertHeaderMissing('Idempotent-Replayed');
        $second->assertCreated()
            ->assertJson(['order' => 1, 'item' => 'book'])
            ->assertHeader('X-Order', '1')
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, static::$calls);
    }

    public function test_does_not_replay_excluded_headers(): void
    {
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'k1'])->assertCookie('session_thing');
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'k1'])->assertCookieMissing('session_thing');
    }

    public function test_different_keys_run_separately(): void
    {
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'a'])->assertJson(['order' => 1]);
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'b'])->assertJson(['order' => 2]);
    }

    public function test_requests_without_a_key_pass_through(): void
    {
        $this->postJson('/orders', ['item' => 'book'])->assertJson(['order' => 1]);
        $this->postJson('/orders', ['item' => 'book'])->assertJson(['order' => 2]);
    }

    public function test_required_option_rejects_missing_key(): void
    {
        $this->postJson('/required')->assertStatus(400);
        $this->assertSame(0, static::$calls);

        $this->postJson('/required', [], ['Idempotency-Key' => 'x'])->assertOk();
    }

    public function test_global_required_config(): void
    {
        config(['safe-retry.required' => true]);

        $this->postJson('/orders', ['item' => 'book'])->assertStatus(400);
    }

    public function test_rejects_overlong_keys(): void
    {
        $this->postJson('/orders', [], ['Idempotency-Key' => str_repeat('a', 256)])->assertStatus(400);
        $this->assertSame(0, static::$calls);
    }

    public function test_ignores_methods_not_configured(): void
    {
        $this->getJson('/orders', ['Idempotency-Key' => 'g'])->assertJson(['ok' => 1]);
        $this->getJson('/orders', ['Idempotency-Key' => 'g'])->assertJson(['ok' => 2]);
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'k1'])->assertCreated();
        $this->postJson('/orders', ['item' => 'pen'], ['Idempotency-Key' => 'k1'])->assertStatus(422);
        $this->assertSame(1, static::$calls);
    }

    public function test_concurrent_request_with_same_key_gets_409(): void
    {
        $store = $this->app->make(SafeRetryManager::class)->store();
        $storageKey = hash('sha256', 'http|global|busy');
        $owner = $store->acquireLock($storageKey, 60);
        $this->assertNotNull($owner);

        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'busy'])
            ->assertStatus(409)
            ->assertHeader('Retry-After');
        $this->assertSame(0, static::$calls);

        $store->releaseLock($storageKey, $owner);
        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'busy'])->assertCreated();
    }

    public function test_server_errors_are_not_stored_so_the_client_can_retry(): void
    {
        $this->postJson('/fails', [], ['Idempotency-Key' => 'f'])->assertStatus(503);
        $this->postJson('/fails', [], ['Idempotency-Key' => 'f'])->assertStatus(503)->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, static::$calls);
    }

    public function test_server_errors_can_be_stored_when_configured(): void
    {
        config(['safe-retry.store_server_errors' => true]);

        $this->postJson('/fails', [], ['Idempotency-Key' => 'f'])->assertStatus(503);
        $this->postJson('/fails', [], ['Idempotency-Key' => 'f'])->assertStatus(503)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, static::$calls);
    }

    public function test_exceptions_release_the_lock(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/throws', [], ['Idempotency-Key' => 't']);
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
        }

        try {
            $this->postJson('/throws', [], ['Idempotency-Key' => 't']);
        } catch (\RuntimeException) {
        }

        $this->assertSame(2, static::$calls);
    }

    public function test_client_errors_are_replayed(): void
    {
        $this->postJson('/invalid', [], ['Idempotency-Key' => 'v'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/invalid', [], ['Idempotency-Key' => 'v'])->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, static::$calls);
    }

    public function test_keys_are_scoped_to_the_authenticated_user(): void
    {
        $alice = new User;
        $alice->id = 1;
        $bob = new User;
        $bob->id = 2;

        $this->actingAs($alice)->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'same'])->assertJson(['order' => 1]);
        $this->actingAs($bob)->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'same'])->assertJson(['order' => 2]);
        $this->actingAs($alice)->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'same'])->assertJson(['order' => 1]);
    }

    public function test_route_options_override_ttl_and_store(): void
    {
        Route::post('/short', fn () => ['n' => ++static::$calls])->middleware('safe-retry:ttl=1,store=cache');

        $this->postJson('/short', [], ['Idempotency-Key' => 's'])->assertJson(['n' => 1]);
        $this->postJson('/short', [], ['Idempotency-Key' => 's'])->assertJson(['n' => 1]);

        $this->travel(2)->seconds();

        $this->postJson('/short', [], ['Idempotency-Key' => 's'])->assertJson(['n' => 2]);
    }

    public function test_fires_event_on_replay(): void
    {
        Event::fake([ResponseReplayed::class]);

        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'e']);
        Event::assertNotDispatched(ResponseReplayed::class);

        $this->postJson('/orders', ['item' => 'book'], ['Idempotency-Key' => 'e']);
        Event::assertDispatched(ResponseReplayed::class, fn ($e) => $e->idempotencyKey === 'e');
    }

    public function test_once_runs_the_callback_a_single_time(): void
    {
        $manager = $this->app->make(SafeRetryManager::class);
        $runs = 0;

        $first = $manager->once('charge:42', function () use (&$runs) {
            $runs++;

            return ['charged' => true];
        });
        $second = $manager->once('charge:42', function () use (&$runs) {
            $runs++;

            return ['charged' => 'again'];
        });

        $this->assertSame(['charged' => true], $first);
        $this->assertSame(['charged' => true], $second);
        $this->assertSame(1, $runs);

        $manager->forgetOnce('charge:42');
        $manager->once('charge:42', function () use (&$runs) {
            $runs++;
        });
        $this->assertSame(2, $runs);
    }

    public function test_once_runs_again_after_the_callback_throws(): void
    {
        $manager = $this->app->make(SafeRetryManager::class);

        try {
            $manager->once('job', fn () => throw new \RuntimeException('nope'));
        } catch (\RuntimeException) {
        }

        $this->assertSame('done', $manager->once('job', fn () => 'done'));
    }
}
