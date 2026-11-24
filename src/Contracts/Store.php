<?php

namespace IshanShah\SafeRetry\Contracts;

interface Store
{
    /**
     * Get a stored record, or null if none exists or it has expired.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * Store a record for the given number of seconds.
     *
     * @param  array<string, mixed>  $record
     */
    public function put(string $key, array $record, int $ttl): void;

    /**
     * Remove a stored record.
     */
    public function forget(string $key): void;

    /**
     * Try to acquire the in-flight lock for a key.
     *
     * Returns an owner token on success, or null if the lock is held.
     */
    public function acquireLock(string $key, int $seconds): ?string;

    /**
     * Release a lock, but only if it is still held by the given owner.
     */
    public function releaseLock(string $key, string $owner): void;
}
