<?php

namespace IshanShah\SafeRetry\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use IshanShah\SafeRetry\Events\IdempotencyKeyInUse;
use IshanShah\SafeRetry\Events\IdempotencyKeyMismatched;
use IshanShah\SafeRetry\Events\ResponseStored;
use IshanShah\SafeRetry\Facades\SafeRetry;
use IshanShah\SafeRetry\Http\Middleware\SafeRetry as SafeRetryMiddleware;
use IshanShah\SafeRetry\SafeRetryManager;
use IshanShah\SafeRetry\Stores\EncryptedStore;
use IshanShah\SafeRetry\Tests\TestCase;

class FeaturesTest extends TestCase
{
    private int $calls = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    private function route(string $uri = '/pay', string ...$options): void
    {
        Route::post($uri, function (Request $request) {
            return ['n' => ++$this->calls, 'key' => $request->idempotencyKey()];
        })->safeRetry(...$options);
    }

    public function test_route_macro_and_request_macro(): void
    {
        $this->route('/pay', 'required');

        $this->postJson('/pay')->assertStatus(400);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'abc'])->assertJson(['n' => 1, 'key' => 'abc'])->assertNotReplayed();
        $this->postJson('/pay', [], ['Idempotency-Key' => 'abc'])->assertJson(['n' => 1])->assertReplayed();
    }

    public function test_using_builds_middleware_strings(): void
    {
        $this->assertSame('safe-retry', SafeRetryMiddleware::using());
        $this->assertSame('safe-retry:required,ttl=60', SafeRetryMiddleware::using('required', 'ttl=60'));
    }

    public function test_unknown_option_throws(): void
    {
        $this->withoutExceptionHandling();
        $this->route('/pay', 'nonsense');

        $this->expectException(\InvalidArgumentException::class);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'abc']);
    }

    public function test_key_pattern(): void
    {
        config(['safe-retry.key_pattern' => '/^[0-9a-f-]{36}$/']);
        $this->route();

        $this->postJson('/pay', [], ['Idempotency-Key' => 'not-a-uuid'])->assertStatus(400);
        $this->postJson('/pay', [], ['Idempotency-Key' => '0b0e4a3c-5d6f-4a1b-9c2d-3e4f5a6b7c8d'])->assertOk();
    }

    public function test_encryption_stores_unreadable_records_and_still_replays(): void
    {
        config(['safe-retry.encrypt' => true]);
        $this->route();

        $this->postJson('/pay', ['card' => '4242'], ['Idempotency-Key' => 'enc'])->assertJson(['n' => 1]);
        $this->postJson('/pay', ['card' => '4242'], ['Idempotency-Key' => 'enc'])->assertJson(['n' => 1])->assertReplayed();

        $store = $this->app->make(SafeRetryManager::class)->store();
        $this->assertInstanceOf(EncryptedStore::class, $store);

        $raw = $store->inner()->get($this->app->make(SafeRetryManager::class)->requestKey('enc'));
        $this->assertSame(['encrypted'], array_keys($raw));
        $this->assertStringNotContainsString('"n"', $raw['encrypted']);
    }

    public function test_max_response_size(): void
    {
        config(['safe-retry.max_response_size' => 5]);
        $this->route();

        $this->postJson('/pay', [], ['Idempotency-Key' => 'big'])->assertJson(['n' => 1]);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'big'])->assertJson(['n' => 2])->assertNotReplayed();
    }

    public function test_echo_key(): void
    {
        config(['safe-retry.echo_key' => true]);
        $this->route();

        $this->postJson('/pay', [], ['Idempotency-Key' => 'echo'])->assertHeader('Idempotency-Key', 'echo');
        $this->postJson('/pay', [], ['Idempotency-Key' => 'echo'])->assertHeader('Idempotency-Key', 'echo')->assertReplayed();
    }

    public function test_custom_scope_resolver(): void
    {
        SafeRetry::scopeUsing(fn (Request $r) => $r->header('X-Tenant'));
        $this->route();

        $this->postJson('/pay', [], ['Idempotency-Key' => 'k', 'X-Tenant' => 'a'])->assertJson(['n' => 1]);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'k', 'X-Tenant' => 'b'])->assertJson(['n' => 2]);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'k', 'X-Tenant' => 'a'])->assertJson(['n' => 1]);

        $this->assertNotNull(SafeRetry::findRequest('k', 'a'));
        SafeRetry::forgetRequest('k', 'a');
        $this->postJson('/pay', [], ['Idempotency-Key' => 'k', 'X-Tenant' => 'a'])->assertJson(['n' => 3]);

        SafeRetry::scopeUsing(null);
    }

    public function test_scope_by_user_can_be_disabled(): void
    {
        config(['safe-retry.scope_by_user' => false]);
        $this->route();

        $a = new User;
        $a->id = 1;
        $b = new User;
        $b->id = 2;

        $this->actingAs($a)->postJson('/pay', [], ['Idempotency-Key' => 'k'])->assertJson(['n' => 1]);
        $this->actingAs($b)->postJson('/pay', [], ['Idempotency-Key' => 'k'])->assertJson(['n' => 1]);
    }

    public function test_forget_request_for_a_user(): void
    {
        $this->route();
        $user = new User;
        $user->id = 7;

        $this->actingAs($user)->postJson('/pay', [], ['Idempotency-Key' => 'u'])->assertJson(['n' => 1]);
        $this->assertNotNull(SafeRetry::findRequest('u', $user));

        SafeRetry::forgetRequest('u', $user);
        $this->actingAs($user)->postJson('/pay', [], ['Idempotency-Key' => 'u'])->assertJson(['n' => 2]);
    }

    public function test_fingerprint_ignored_fields(): void
    {
        config(['safe-retry.fingerprint.ignore' => ['sent_at']]);
        $this->route();

        $this->postJson('/pay', ['amount' => 10, 'sent_at' => 1], ['Idempotency-Key' => 'f'])->assertJson(['n' => 1]);
        $this->postJson('/pay', ['amount' => 10, 'sent_at' => 2], ['Idempotency-Key' => 'f'])->assertJson(['n' => 1])->assertReplayed();
        $this->postJson('/pay', ['amount' => 11, 'sent_at' => 2], ['Idempotency-Key' => 'f'])->assertStatus(422);
    }

    public function test_fingerprint_headers(): void
    {
        config(['safe-retry.fingerprint.headers' => ['X-Currency']]);
        $this->route();

        $this->postJson('/pay', [], ['Idempotency-Key' => 'h', 'X-Currency' => 'USD'])->assertOk();
        $this->postJson('/pay', [], ['Idempotency-Key' => 'h', 'X-Currency' => 'EUR'])->assertStatus(422);
    }

    public function test_json_key_order_does_not_matter(): void
    {
        $this->route();

        $this->call('POST', '/pay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'o'], '{"a":1,"b":2}')->assertOk();
        $this->call('POST', '/pay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'o'], '{ "b": 2, "a": 1 }')
            ->assertOk()->assertReplayed();
    }

    public function test_events(): void
    {
        Event::fake([ResponseStored::class, IdempotencyKeyMismatched::class, IdempotencyKeyInUse::class]);
        $this->route();

        $this->postJson('/pay', ['a' => 1], ['Idempotency-Key' => 'ev']);
        Event::assertDispatched(ResponseStored::class);

        $this->postJson('/pay', ['a' => 2], ['Idempotency-Key' => 'ev']);
        Event::assertDispatched(IdempotencyKeyMismatched::class);

        $manager = $this->app->make(SafeRetryManager::class);
        $manager->store()->acquireLock($manager->requestKey('busy'), 60);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'busy'])->assertStatus(409);
        Event::assertDispatched(IdempotencyKeyInUse::class);
    }

    public function test_wait_gives_up_after_the_timeout(): void
    {
        $this->route('/pay', 'wait=1');
        $manager = $this->app->make(SafeRetryManager::class);
        $manager->store()->acquireLock($manager->requestKey('slow'), 60);

        $start = microtime(true);
        $this->postJson('/pay', [], ['Idempotency-Key' => 'slow'])->assertStatus(409);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $start);
    }

    public function test_has_once(): void
    {
        $this->assertFalse(SafeRetry::hasOnce('x'));
        SafeRetry::once('x', fn () => 1);
        $this->assertTrue(SafeRetry::hasOnce('x'));
    }

    public function test_http_client_macro_sends_the_same_key_on_every_retry(): void
    {
        $keys = [];
        Http::fake(function (ClientRequest $request) use (&$keys) {
            if (! $request->hasHeader('Idempotency-Key')) {
                return Http::response('ok');
            }

            $keys[] = $request->header('Idempotency-Key')[0];

            return count($keys) < 3 ? Http::response('', 500) : Http::response('ok');
        });

        Http::withIdempotencyKey()->retry(3, 0)->post('https://example.test/pay');

        $this->assertCount(3, $keys);
        $this->assertCount(1, array_unique($keys));
        $this->assertTrue(\Illuminate\Support\Str::isUuid($keys[0]));

        Http::withIdempotencyKey('mine', 'X-Key')->post('https://example.test/pay');
        Http::assertSent(fn (ClientRequest $r) => $r->header('X-Key') === ['mine']);
    }

    public function test_forget_command(): void
    {
        $this->route();
        $this->postJson('/pay', [], ['Idempotency-Key' => 'cmd'])->assertJson(['n' => 1]);

        $this->artisan('safe-retry:forget', ['key' => 'cmd'])->assertSuccessful();
        $this->postJson('/pay', [], ['Idempotency-Key' => 'cmd'])->assertJson(['n' => 2]);

        SafeRetry::once('op', fn () => 1);
        $this->artisan('safe-retry:forget', ['key' => 'op', '--once' => true])->assertSuccessful();
        $this->assertFalse(SafeRetry::hasOnce('op'));
    }

    public function test_forget_command_with_user(): void
    {
        config(['auth.providers.users.model' => User::class]);
        $this->route();
        $user = new User;
        $user->id = 9;

        $this->actingAs($user)->postJson('/pay', [], ['Idempotency-Key' => 'cu'])->assertJson(['n' => 1]);
        $this->artisan('safe-retry:forget', ['key' => 'cu', '--user' => 9])->assertSuccessful();
        $this->actingAs($user)->postJson('/pay', [], ['Idempotency-Key' => 'cu'])->assertJson(['n' => 2]);
    }

    public function test_install_command(): void
    {
        $this->artisan('safe-retry:install')->assertSuccessful();
        $this->assertFileExists(config_path('safe-retry.php'));
        @unlink(config_path('safe-retry.php'));
    }

    public function test_prune_command_on_non_database_store(): void
    {
        $this->artisan('safe-retry:prune')->expectsOutputToContain('Nothing to prune')->assertSuccessful();
    }
}
