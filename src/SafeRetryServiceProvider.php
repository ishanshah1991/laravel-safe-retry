<?php

namespace IshanShah\SafeRetry;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/safe-retry.php' => config_path('safe-retry.php'),
            ], 'safe-retry-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_safe_retry_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_safe_retry_tables.php'),
            ], 'safe-retry-migrations');

            $this->commands([PruneCommand::class]);
        }
    }
}
