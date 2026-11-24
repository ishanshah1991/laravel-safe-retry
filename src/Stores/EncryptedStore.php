<?php

namespace IshanShah\SafeRetry\Stores;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use IshanShah\SafeRetry\Contracts\Store;

/**
 * Wraps any store so records are encrypted at rest with your APP_KEY.
 * Turn on with the "encrypt" config option.
 */
class EncryptedStore implements Store
{
    public function __construct(
        protected Store $store,
        protected Encrypter $encrypter,
    ) {}

    public function get(string $key): ?array
    {
        $record = $this->store->get($key);

        if (! is_string($record['encrypted'] ?? null)) {
            return null;
        }

        try {
            $decrypted = json_decode($this->encrypter->decryptString($record['encrypted']), true);
        } catch (DecryptException) {
            return null; // e.g. APP_KEY rotated: treat as missing
        }

        return is_array($decrypted) ? $decrypted : null;
    }

    public function put(string $key, array $record, int $ttl): void
    {
        $this->store->put($key, ['encrypted' => $this->encrypter->encryptString((string) json_encode($record))], $ttl);
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }

    public function acquireLock(string $key, int $seconds): ?string
    {
        return $this->store->acquireLock($key, $seconds);
    }

    public function releaseLock(string $key, string $owner): void
    {
        $this->store->releaseLock($key, $owner);
    }

    public function inner(): Store
    {
        return $this->store;
    }
}
