<?php

namespace IshanShah\SafeRetry\Facades;

use Illuminate\Support\Facades\Facade;
use IshanShah\SafeRetry\SafeRetryManager;

/**
 * @method static \IshanShah\SafeRetry\Contracts\Store store(?string $name = null)
 * @method static mixed once(string $key, \Closure $callback, ?int $ttl = null, ?string $store = null)
 * @method static void forgetOnce(string $key, ?string $store = null)
 * @method static bool hasOnce(string $key, ?string $store = null)
 * @method static void forgetJob(string $key, ?string $store = null)
 * @method static bool hasJob(string $key, ?string $store = null)
 * @method static array|null findRequest(string $key, \Illuminate\Contracts\Auth\Authenticatable|string|null $scope = null, ?string $store = null)
 * @method static void forgetRequest(string $key, \Illuminate\Contracts\Auth\Authenticatable|string|null $scope = null, ?string $store = null)
 * @method static \IshanShah\SafeRetry\SafeRetryManager scopeUsing(?\Closure $resolver)
 * @method static string|null resolveScope(\Illuminate\Http\Request $request)
 * @method static string requestKey(string $key, ?string $scope = null)
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
