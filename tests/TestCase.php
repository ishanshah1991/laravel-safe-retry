<?php

namespace IshanShah\SafeRetry\Tests;

use IshanShah\SafeRetry\SafeRetryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SafeRetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 15),
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_safe_retry_tables.php.stub';
        $migration->up();
    }
}
