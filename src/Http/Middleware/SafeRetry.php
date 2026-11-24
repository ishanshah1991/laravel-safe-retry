<?php

namespace IshanShah\SafeRetry\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use InvalidArgumentException;
use IshanShah\SafeRetry\Contracts\Fingerprinter;
use IshanShah\SafeRetry\Contracts\Store;
use IshanShah\SafeRetry\Events\IdempotencyKeyInUse;
use IshanShah\SafeRetry\Events\IdempotencyKeyMismatched;
use IshanShah\SafeRetry\Events\ResponseReplayed;
use IshanShah\SafeRetry\Events\ResponseStored;
use IshanShah\SafeRetry\Exceptions\IdempotencyKeyInUseException;
use IshanShah\SafeRetry\Exceptions\IdempotencyKeyMismatchException;
use IshanShah\SafeRetry\Exceptions\InvalidIdempotencyKeyException;
use IshanShah\SafeRetry\Exceptions\MissingIdempotencyKeyException;
use IshanShah\SafeRetry\SafeRetryManager;
use IshanShah\SafeRetry\Support\ResponseSerializer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage:
 *   ->middleware('safe-retry')
 *   ->middleware('safe-retry:required')
 *   ->middleware('safe-retry:required,ttl=3600,store=redis,wait=5')
 *   ->safeRetry('required', 'ttl=3600')
 *
 * Options: required, optional, ttl=<seconds>, store=<name>, wait=<seconds>,
 * lock=<seconds>.
 */
class SafeRetry
{
    /** Request attribute holding the idempotency key, once validated. */
    public const KEY_ATTRIBUTE = 'safe_retry.key';

    public function __construct(
        protected SafeRetryManager $manager,
        protected Config $config,
        protected Container $container,
    ) {}

    /**
     * Build the middleware string for a route, e.g. SafeRetry::using('required', 'ttl=60').
     */
    public static function using(string ...$options): string
    {
        return $options === [] ? 'safe-retry' : 'safe-retry:'.implode(',', $options);
    }

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $options = $this->parseOptions($options);

        $methods = array_map('strtoupper', (array) $this->config->get('safe-retry.methods', []));

        if (! in_array(strtoupper($request->getMethod()), $methods, true)) {
            return $next($request);
        }

        $header = $this->header();
        $key = $request->headers->get($header);

        if ($key === null || $key === '') {
            if ($options['required']) {
                throw new MissingIdempotencyKeyException($header);
            }

            return $next($request);
        }

        $this->validateKey($key, $header);

        $request->attributes->set(self::KEY_ATTRIBUTE, $key);

        $store = $this->manager->store($options['store']);
        $storageKey = $this->manager->requestKey($key, $this->manager->resolveScope($request));
        $fingerprint = $this->fingerprinter()->fingerprint($request);

        if ($replay = $this->replay($store, $storageKey, $fingerprint, $request, $key)) {
            return $replay;
        }

        $owner = $this->acquireLock($store, $storageKey, $options);

        if ($owner === null) {
            // The original is still running. If we were allowed to wait, it
            // may have finished in the meantime.
            if ($replay = $this->replay($store, $storageKey, $fingerprint, $request, $key)) {
                return $replay;
            }

            $this->dispatch(new IdempotencyKeyInUse($request, $key));

            throw new IdempotencyKeyInUseException;
        }

        try {
            // Check again now that we hold the lock: another request may have
            // finished between our first check and getting the lock.
            if ($replay = $this->replay($store, $storageKey, $fingerprint, $request, $key)) {
                return $replay;
            }

            $response = $next($request);

            if ($this->shouldStore($response)) {
                $store->put(
                    $storageKey,
                    $this->serializer()->serialize($response, $fingerprint),
                    $options['ttl'],
                );

                $this->dispatch(new ResponseStored($request, $response, $key));
            }

            return $this->echoKey($response, $key);
        } finally {
            $store->releaseLock($storageKey, $owner);
        }
    }

    protected function validateKey(string $key, string $header): void
    {
        $maxLength = (int) $this->config->get('safe-retry.max_key_length', 255);
        $pattern = $this->config->get('safe-retry.key_pattern');

        if (strlen($key) > $maxLength || trim($key) === '' || ($pattern && ! preg_match($pattern, $key))) {
            throw new InvalidIdempotencyKeyException($header, $maxLength);
        }
    }

    protected function replay(Store $store, string $storageKey, string $fingerprint, Request $request, string $key): ?Response
    {
        $record = $store->get($storageKey);

        if ($record === null || ($record['type'] ?? null) !== 'http') {
            return null;
        }

        if (! hash_equals((string) $record['fingerprint'], $fingerprint)) {
            $this->dispatch(new IdempotencyKeyMismatched($request, $key));

            throw new IdempotencyKeyMismatchException;
        }

        $response = $this->serializer()->unserialize($record);
        $response->headers->set((string) $this->config->get('safe-retry.replayed_header', 'Idempotent-Replayed'), 'true');

        $this->dispatch(new ResponseReplayed($request, $response, $key));

        return $this->echoKey($response, $key);
    }

    /**
     * @param  array{wait: int, lock: int}  $options
     */
    protected function acquireLock(Store $store, string $storageKey, array $options): ?string
    {
        $deadline = microtime(true) + $options['wait'];

        do {
            if (($owner = $store->acquireLock($storageKey, $options['lock'])) !== null) {
                return $owner;
            }

            if ($options['wait'] > 0 && $store->get($storageKey) !== null) {
                return null; // finished while we waited; the caller replays it
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(100_000);
        } while (true);
    }

    protected function shouldStore(Response $response): bool
    {
        if ($response->getStatusCode() >= 500 && ! $this->config->get('safe-retry.store_server_errors', false)) {
            return false;
        }

        if (! $this->serializer()->canSerialize($response)) {
            return false;
        }

        $maxSize = $this->config->get('safe-retry.max_response_size');

        return $maxSize === null || strlen((string) $response->getContent()) <= (int) $maxSize;
    }

    protected function echoKey(Response $response, string $key): Response
    {
        if ($this->config->get('safe-retry.echo_key', false)) {
            $response->headers->set($this->header(), $key);
        }

        return $response;
    }

    protected function header(): string
    {
        return (string) $this->config->get('safe-retry.header', 'Idempotency-Key');
    }

    protected function dispatch(object $event): void
    {
        $this->container->make('events')->dispatch($event);
    }

    protected function fingerprinter(): Fingerprinter
    {
        return $this->container->make($this->config->get('safe-retry.fingerprinter'));
    }

    protected function serializer(): ResponseSerializer
    {
        return new ResponseSerializer((array) $this->config->get('safe-retry.excluded_headers', []));
    }

    /**
     * @param  array<int, string>  $options
     * @return array{required: bool, ttl: int, store: ?string, wait: int, lock: int}
     */
    protected function parseOptions(array $options): array
    {
        $parsed = [
            'required' => (bool) $this->config->get('safe-retry.required', false),
            'ttl' => (int) $this->config->get('safe-retry.ttl', 86400),
            'store' => null,
            'wait' => (int) $this->config->get('safe-retry.wait', 0),
            'lock' => (int) $this->config->get('safe-retry.lock_timeout', 60),
        ];

        foreach ($options as $option) {
            [$name, $value] = array_pad(explode('=', trim($option), 2), 2, null);

            match ($name) {
                'required' => $parsed['required'] = true,
                'optional' => $parsed['required'] = false,
                'ttl', 'wait', 'lock' => $parsed[$name] = (int) $value,
                'store' => $parsed['store'] = $value,
                default => throw new InvalidArgumentException("Unknown safe-retry middleware option [{$option}]."),
            };
        }

        return $parsed;
    }
}
