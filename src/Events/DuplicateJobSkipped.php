<?php

namespace IshanShah\SafeRetry\Events;

/**
 * Fired when the SafeRetryJob middleware skips a job whose key already completed.
 */
class DuplicateJobSkipped
{
    public function __construct(
        public readonly object $job,
        public readonly string $key,
    ) {}
}
