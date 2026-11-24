<?php

namespace IshanShah\SafeRetry\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use IshanShah\SafeRetry\Contracts\Store;

class DatabaseStore implements Store
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table = 'safe_retry_keys',
        protected string $lockTable = 'safe_retry_locks',
        protected string $prefix = 'safe-retry',
    ) {}

    public function get(string $key): ?array
    {
        $row = $this->records()
            ->where('key', $this->prefixed($key))
            ->where('expires_at', '>', $this->now())
            ->first();

        if (! $row) {
            return null;
        }

        $record = json_decode($row->payload, true);

        return is_array($record) ? $record : null;
    }

    public function put(string $key, array $record, int $ttl): void
    {
        $this->records()->upsert([
            'key' => $this->prefixed($key),
            'payload' => json_encode($record),
            'expires_at' => $this->now() + $ttl,
        ], ['key'], ['payload', 'expires_at']);
    }

    public function forget(string $key): void
    {
        $this->records()->where('key', $this->prefixed($key))->delete();
    }

    public function acquireLock(string $key, int $seconds): ?string
    {
        $owner = Str::random(32);
        $name = $this->prefixed($key);
        $expiration = $this->now() + $seconds;

        try {
            $this->locks()->insert(['key' => $name, 'owner' => $owner, 'expires_at' => $expiration]);

            return $owner;
        } catch (QueryException) {
            // Already locked: take it over only if the previous holder's lock expired.
            $updated = $this->locks()
                ->where('key', $name)
                ->where('expires_at', '<=', $this->now())
                ->update(['owner' => $owner, 'expires_at' => $expiration]);

            return $updated >= 1 ? $owner : null;
        }
    }

    public function releaseLock(string $key, string $owner): void
    {
        $this->locks()->where('key', $this->prefixed($key))->where('owner', $owner)->delete();
    }

    /**
     * Delete expired records and stale locks. Returns the number of rows removed.
     */
    public function prune(): int
    {
        $now = $this->now();

        return $this->records()->where('expires_at', '<=', $now)->delete()
            + $this->locks()->where('expires_at', '<=', $now)->delete();
    }

    protected function records(): Builder
    {
        return $this->connection->table($this->table);
    }

    protected function locks(): Builder
    {
        return $this->connection->table($this->lockTable);
    }

    protected function prefixed(string $key): string
    {
        return $this->prefix.':'.$key;
    }

    protected function now(): int
    {
        return now()->getTimestamp();
    }
}
