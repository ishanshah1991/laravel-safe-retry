<?php

namespace IshanShah\SafeRetry;

use Closure;
use Illuminate\Support\Manager;
use IshanShah\SafeRetry\Contracts\Store;
use IshanShah\SafeRetry\Exceptions\IdempotencyKeyInUseException;
use IshanShah\SafeRetry\Stores\CacheStore;
use IshanShah\SafeRetry\Stores\DatabaseStore;
use IshanShah\SafeRetry\Stores\RedisStore;

/**
 * @mixin Store
 */
class SafeRetryManager extends Manager
{
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
