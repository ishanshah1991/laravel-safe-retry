<?php

namespace IshanShah\SafeRetry\Stores;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use IshanShah\SafeRetry\Contracts\Store;

class CacheStore implements Store
{
    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'safe-retry',
    ) {
        if (! $cache->getStore() instanceof LockProvider) {
            throw new InvalidArgumentException(
                'The ['.get_class($cache->getStore()).'] cache store does not support atomic locks, so it cannot be used by safe-retry.'
            );
        }
    }

    public function get(string $key): ?array
    {
        $record = $this->cache->get($this->recordKey($key));

        return is_array($record) ? $record : null;
    }

    public function put(string $key, array $record, int $ttl): void
    {
        $this->cache->put($this->recordKey($key), $record, $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->recordKey($key));
    }

    public function acquireLock(string $key, int $seconds): ?string
    {
        $owner = Str::random(32);

        return $this->locks()->lock($this->lockKey($key), $seconds, $owner)->get() ? $owner : null;
    }

    public function releaseLock(string $key, string $owner): void
    {
        $this->locks()->restoreLock($this->lockKey($key), $owner)->release();
    }

    protected function locks(): LockProvider
    {
        /** @var LockProvider */
        return $this->cache->getStore();
    }

    protected function recordKey(string $key): string
    {
        return $this->prefix.':record:'.$key;
    }

    protected function lockKey(string $key): string
    {
        return $this->prefix.':lock:'.$key;
    }
}
