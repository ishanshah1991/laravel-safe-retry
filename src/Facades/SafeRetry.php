<?php

namespace IshanShah\SafeRetry\Facades;

use Illuminate\Support\Facades\Facade;
use IshanShah\SafeRetry\SafeRetryManager;

/**
 * @method static \IshanShah\SafeRetry\Contracts\Store store(?string $name = null)
 * @method static mixed once(string $key, \Closure $callback, ?int $ttl = null, ?string $store = null)
 * @method static void forgetOnce(string $key, ?string $store = null)
 * @method static \IshanShah\SafeRetry\SafeRetryManager extend(string $driver, \Closure $callback)
 *
 * @see SafeRetryManager
 */
class SafeRetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SafeRetryManager::class;
    }
}
