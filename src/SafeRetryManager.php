<?php

namespace IshanShah\SafeRetry;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Manager;
use IshanShah\SafeRetry\Contracts\Store;
use IshanShah\SafeRetry\Exceptions\IdempotencyKeyInUseException;
use IshanShah\SafeRetry\Stores\CacheStore;
use IshanShah\SafeRetry\Stores\DatabaseStore;
use IshanShah\SafeRetry\Stores\EncryptedStore;
use IshanShah\SafeRetry\Stores\RedisStore;

/**
 * @mixin Store
 */
class SafeRetryManager extends Manager
{
    /**
     * @var (Closure(Request): (string|int|null))|null
     */
    protected ?Closure $scopeResolver = null;

    public function getDefaultDriver(): string
    {
        return $this->config->get('safe-retry.store', 'cache');
    }

    public function store(?string $name = null): Store
    {
        return $this->driver($name);
    }

    /**
     * Run a callback at most once for a given key, and return its result.
     *
     * Later calls with the same key, until the TTL runs out, return the stored
     * result without running the callback. If the callback throws, nothing is
     * stored and the next call runs it again. Use it in queued jobs, webhooks
     * and anywhere else a side effect must not happen twice.
     *
     * The result is stored with serialize(), so return plain values or
     * serializable objects.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     *
     * @throws IdempotencyKeyInUseException when another process is running the same key right now
     */
    public function once(string $key, Closure $callback, ?int $ttl = null, ?string $store = null): mixed
    {
        $store = $this->store($store);
        $hashed = hash('sha256', 'once|'.$key);

        if ($record = $store->get($hashed)) {
            return unserialize($record['value']);
        }

        $owner = $store->acquireLock($hashed, (int) $this->config->get('safe-retry.lock_timeout', 60));

        if ($owner === null) {
            throw new IdempotencyKeyInUseException("The operation [{$key}] is already running.");
        }

        try {
            // Someone may have finished between our first check and getting the lock.
            if ($record = $store->get($hashed)) {
                return unserialize($record['value']);
            }

            $value = $callback();

            $store->put(
                $hashed,
                ['type' => 'value', 'value' => serialize($value)],
                $ttl ?? (int) $this->config->get('safe-retry.ttl', 86400),
            );

            return $value;
        } finally {
            $store->releaseLock($hashed, $owner);
        }
    }

    /**
     * Forget the stored result of a once() call, so it runs again next time.
     */
    public function forgetOnce(string $key, ?string $store = null): void
    {
        $this->store($store)->forget(hash('sha256', 'once|'.$key));
    }

    /**
     * Decide which "owner" a request's key belongs to, e.g. a tenant or API
     * client. Return null for no scope. Replaces the default (the logged-in user).
     *
     * @param  (Closure(Request): (string|int|null))|null  $resolver
     */
    public function scopeUsing(?Closure $resolver): static
    {
        $this->scopeResolver = $resolver;

        return $this;
    }

    public function resolveScope(Request $request): ?string
    {
        if ($this->scopeResolver) {
            $scope = ($this->scopeResolver)($request);

            return $scope === null || $scope === '' ? null : (string) $scope;
        }

        if (! $this->config->get('safe-retry.scope_by_user', true)) {
            return null;
        }

        $user = $request->user();

        return $user ? $this->userScope($user) : null;
    }

    public function userScope(Authenticatable $user): string
    {
        return get_class($user).':'.$user->getAuthIdentifier();
    }

    /**
     * The storage key used for an HTTP idempotency key within a scope.
     */
    public function requestKey(string $key, ?string $scope = null): string
    {
        return hash('sha256', 'http|'.($scope ?? 'global').'|'.$key);
    }

    /**
     * Get the stored HTTP response record for a key, if any.
     *
     * @return array<string, mixed>|null
     */
    public function findRequest(string $key, Authenticatable|string|null $scope = null, ?string $store = null): ?array
    {
        return $this->store($store)->get($this->requestKey($key, $this->normalizeScope($scope)));
    }

    /**
     * Forget a stored HTTP response, so the next request with this key runs again.
     */
    public function forgetRequest(string $key, Authenticatable|string|null $scope = null, ?string $store = null): void
    {
        $this->store($store)->forget($this->requestKey($key, $this->normalizeScope($scope)));
    }

    /**
     * Whether once() has already completed for this key.
     */
    public function hasOnce(string $key, ?string $store = null): bool
    {
        return $this->store($store)->get(hash('sha256', 'once|'.$key)) !== null;
    }

    /**
     * Forget that a SafeRetryJob key completed, so the next job with it runs.
     * Pass the same key you gave the middleware.
     */
    public function forgetJob(string $key, ?string $store = null): void
    {
        $this->store($store)->forget(hash('sha256', 'job|'.$key));
    }

    /**
     * Whether a SafeRetryJob with this key has completed.
     */
    public function hasJob(string $key, ?string $store = null): bool
    {
        return $this->store($store)->get(hash('sha256', 'job|'.$key)) !== null;
    }

    protected function normalizeScope(Authenticatable|string|null $scope): ?string
    {
        return $scope instanceof Authenticatable ? $this->userScope($scope) : $scope;
    }

    protected function createDriver($driver)
    {
        $store = parent::createDriver($driver);

        if ($this->config->get('safe-retry.encrypt', false) && ! $store instanceof EncryptedStore) {
            $store = new EncryptedStore($store, $this->container->make('encrypter'));
        }

        return $store;
    }

    protected function createCacheDriver(): Store
    {
        return new CacheStore(
            $this->container->make('cache')->store($this->config->get('safe-retry.stores.cache.store')),
            $this->prefix(),
        );
    }

    protected function createRedisDriver(): Store
    {
        return new RedisStore(
            $this->container->make('redis')->connection($this->config->get('safe-retry.stores.redis.connection', 'default')),
            $this->prefix(),
        );
    }

    protected function createDatabaseDriver(): Store
    {
        return new DatabaseStore(
            $this->container->make('db')->connection($this->config->get('safe-retry.stores.database.connection')),
            $this->config->get('safe-retry.stores.database.table', 'safe_retry_keys'),
            $this->config->get('safe-retry.stores.database.lock_table', 'safe_retry_locks'),
            $this->prefix(),
        );
    }

    protected function prefix(): string
    {
        return (string) $this->config->get('safe-retry.prefix', 'safe-retry');
    }
}
