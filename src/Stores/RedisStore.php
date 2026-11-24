<?php

namespace IshanShah\SafeRetry\Stores;

use Illuminate\Cache\RedisLock;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use IshanShah\SafeRetry\Contracts\Store;

class RedisStore implements Store
{
    public function __construct(
        protected Connection $redis,
        protected string $prefix = 'safe-retry',
    ) {}

    public function get(string $key): ?array
    {
        $value = $this->redis->command('get', [$this->recordKey($key)]);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $record = json_decode($value, true);

        return is_array($record) ? $record : null;
    }

    public function put(string $key, array $record, int $ttl): void
    {
        $this->redis->command('setex', [$this->recordKey($key), max(1, $ttl), json_encode($record)]);
    }

    public function forget(string $key): void
    {
        $this->redis->command('del', [$this->recordKey($key)]);
    }

    public function acquireLock(string $key, int $seconds): ?string
    {
        $owner = Str::random(32);

        return (new RedisLock($this->redis, $this->lockKey($key), $seconds, $owner))->get() ? $owner : null;
    }

    public function releaseLock(string $key, string $owner): void
    {
        // RedisLock::release() only deletes the key if it still holds our owner token.
        (new RedisLock($this->redis, $this->lockKey($key), 0, $owner))->release();
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
