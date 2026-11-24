<?php

namespace IshanShah\SafeRetry;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use IshanShah\SafeRetry\Console\ForgetCommand;
use IshanShah\SafeRetry\Console\InstallCommand;
use IshanShah\SafeRetry\Console\PruneCommand;
use IshanShah\SafeRetry\Http\Middleware\SafeRetry;

class SafeRetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/safe-retry.php', 'safe-retry');

        $this->app->singleton(SafeRetryManager::class, fn ($app) => new SafeRetryManager($app));
        $this->app->alias(SafeRetryManager::class, 'safe-retry');
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('safe-retry', SafeRetry::class);

        $this->registerMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/safe-retry.php' => config_path('safe-retry.php'),
            ], 'safe-retry-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_safe_retry_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_safe_retry_tables.php'),
            ], 'safe-retry-migrations');

            $this->commands([InstallCommand::class, PruneCommand::class, ForgetCommand::class]);
        }
    }

    protected function registerMacros(): void
    {
        // Route::post(...)->safeRetry('required', 'ttl=3600')
        Route::macro('safeRetry', function (string ...$options) {
            /** @var Route $this */
            return $this->middleware(SafeRetry::using(...$options));
        });

        // $request->idempotencyKey()
        Request::macro('idempotencyKey', function (): ?string {
            /** @var Request $this */
            $key = $this->attributes->get(SafeRetry::KEY_ATTRIBUTE)
                ?? $this->headers->get(config('safe-retry.header', 'Idempotency-Key'));

            return $key === '' ? null : $key;
        });

        // Http::withIdempotencyKey()->retry(3, 100)->post(...): the same key
        // is sent on every retry. Pass your own key to keep it across jobs.
        PendingRequest::macro('withIdempotencyKey', function (?string $key = null, string $header = 'Idempotency-Key') {
            /** @var PendingRequest $this */
            return $this->withHeaders([$header => $key ?? (string) Str::uuid()]);
        });

        if (class_exists(TestResponse::class)) {
            TestResponse::macro('assertReplayed', function () {
                /** @var TestResponse $this */
                $header = config('safe-retry.replayed_header', 'Idempotent-Replayed');
                \PHPUnit\Framework\Assert::assertSame(
                    'true',
                    $this->headers->get($header),
                    "Expected the response to be replayed, but the [{$header}] header is missing.",
                );

                return $this;
            });

            TestResponse::macro('assertNotReplayed', function () {
                /** @var TestResponse $this */
                $header = config('safe-retry.replayed_header', 'Idempotent-Replayed');
                \PHPUnit\Framework\Assert::assertFalse(
                    $this->headers->has($header),
                    'Expected the response not to be replayed, but it was.',
                );

                return $this;
            });
        }
    }
}
