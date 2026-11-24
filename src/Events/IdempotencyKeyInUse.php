<?php

namespace IshanShah\SafeRetry\Events;

use Illuminate\Http\Request;

/**
 * Fired when a request is rejected with 409 because another request with the
 * same key is still running. Frequent events point at clients retrying too fast.
 */
class IdempotencyKeyInUse
{
    public function __construct(
        public readonly Request $request,
        public readonly string $idempotencyKey,
    ) {}
}
