<?php

namespace IshanShah\SafeRetry\Jobs;

use Closure;
use Illuminate\Container\Container;
use IshanShah\SafeRetry\Events\DuplicateJobSkipped;
use IshanShah\SafeRetry\Exceptions\IdempotencyKeyInUseException;
use IshanShah\SafeRetry\SafeRetryManager;

/**
 * Queue job middleware: a job with a given key does its work only once.
 *
 *   public function middleware(): array
 *   {
 *       return [new SafeRetryJob("charge-order:{$this->order->id}")];
 *   }
 *
 * - Once a job with the key finishes, later jobs with the same key are
 *   skipped (removed from the queue without running).
 * - While one is running, a duplicate is released back onto the queue.
 * - If the job throws, is released or fails, it is not marked done, so a
 *   retry runs it again.
 *
 * Without a key, the job's own key is used: its safeRetryKey() method, or
 * else the queued payload's UUID (which guards against the same message
 * being delivered twice).
 */
class SafeRetryJob
{
    public function __construct(
        public ?string $key = null,
        public ?int $ttl = null,
        public ?int $releaseAfter = null,
        public ?string $store = null,
    ) {}

    public static function make(?string $key = null): static
    {
        return new static($key);
    }

    public function ttl(int $seconds): static
    {
        $this->ttl = $seconds;

        return $this;
    }

    public function releaseAfter(int $seconds): static
    {
        $this->releaseAfter = $seconds;

        return $this;
    }

    public function store(string $store): static
    {
        $this->store = $store;

        return $this;
    }

    public function handle(object $job, Closure $next): mixed
    {
        $container = Container::getInstance();
        $config = $container->make('config');
        $key = $this->resolveKey($job);

        if ($key === null) {
            return $next($job); // nothing to key on (e.g. a sync job without a key)
        }

        $store = $container->make(SafeRetryManager::class)->store($this->store);
        $storageKey = hash('sha256', 'job|'.$key);

        if ($store->get($storageKey) !== null) {
            $container->make('events')->dispatch(new DuplicateJobSkipped($job, $key));

            return null;
        }

        $lockSeconds = (int) (property_exists($job, 'timeout') && $job->timeout
            ? $job->timeout + 30
            : $config->get('safe-retry.lock_timeout', 60));

        $owner = $store->acquireLock($storageKey, $lockSeconds);

        if ($owner === null) {
            // Jobs using InteractsWithQueue go back on the queue; for anything
            // else throw, so the work is retried rather than silently dropped.
            if (! method_exists($job, 'release') || ! isset($job->job)) {
                throw new IdempotencyKeyInUseException("The job [{$key}] is already running.");
            }

            $job->release($this->releaseAfter ?? (int) $config->get('safe-retry.jobs.release_after', 10));

            return null;
        }

        try {
            if ($store->get($storageKey) !== null) {
                $container->make('events')->dispatch(new DuplicateJobSkipped($job, $key));

                return null;
            }

            $result = $next($job);

            if (! $this->wasReleasedOrFailed($job)) {
                $store->put(
                    $storageKey,
                    ['type' => 'job', 'completed_at' => now()->getTimestamp()],
                    $this->ttl ?? (int) $config->get('safe-retry.jobs.ttl', 86400),
                );
            }

            return $result;
        } finally {
            $store->releaseLock($storageKey, $owner);
        }
    }

    protected function resolveKey(object $job): ?string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        if (method_exists($job, 'safeRetryKey')) {
            return get_class($job).':'.$job->safeRetryKey();
        }

        $uuid = isset($job->job) && method_exists($job->job, 'uuid') ? $job->job->uuid() : null;

        return $uuid ? get_class($job).':'.$uuid : null;
    }

    protected function wasReleasedOrFailed(object $job): bool
    {
        $queueJob = $job->job ?? null;

        if (! is_object($queueJob)) {
            return false;
        }

        return (method_exists($queueJob, 'isReleased') && $queueJob->isReleased())
            || (method_exists($queueJob, 'hasFailed') && $queueJob->hasFailed());
    }
}
