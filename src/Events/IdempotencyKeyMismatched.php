<?php

namespace IshanShah\SafeRetry\Events;

use Illuminate\Http\Request;

/**
 * Fired when a key is reused for a different request and rejected with 422.
 * Usually a client bug: reusing one key for several operations.
 */
class IdempotencyKeyMismatched
{
    public function __construct(
        public readonly Request $request,
        public readonly string $idempotencyKey,
    ) {}
}
