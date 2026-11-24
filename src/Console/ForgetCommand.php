<?php

namespace IshanShah\SafeRetry\Console;

use Illuminate\Console\Command;
use IshanShah\SafeRetry\SafeRetryManager;

class ForgetCommand extends Command
{
    protected $signature = 'safe-retry:forget
        {key : The idempotency key, or the once() / job key}
        {--user= : ID of the user the key belongs to (uses the default auth provider\'s model)}
        {--scope= : Raw scope string, if you use a custom scope resolver}
        {--once : The key was used with SafeRetry::once()}
        {--job : The key was used with the SafeRetryJob middleware}
        {--store= : The store to use (defaults to the configured store)}';

    protected $description = 'Forget a stored idempotency key so the next request with it runs again';

    public function handle(SafeRetryManager $manager): int
    {
        $key = (string) $this->argument('key');
        $store = $this->option('store');

        if ($this->option('once')) {
            $manager->forgetOnce($key, $store);
        } elseif ($this->option('job')) {
            $manager->forgetJob($key, $store);
        } else {
            $manager->forgetRequest($key, $this->scope(), $store);
        }

        $this->components->info("Forgot [{$key}].");

        return self::SUCCESS;
    }

    protected function scope(): ?string
    {
        if ($scope = $this->option('scope')) {
            return $scope;
        }

        if (($user = $this->option('user')) !== null) {
            $provider = config('auth.guards.'.config('auth.defaults.guard').'.provider', 'users');
            $model = config("auth.providers.{$provider}.model");

            return $model.':'.$user;
        }

        return null;
    }
}
