<?php

namespace IshanShah\SafeRetry\Events;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fired when a retried request is answered from the stored response
 * instead of running the route again.
 */
class ResponseReplayed
{
    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
        public readonly string $idempotencyKey,
    ) {}
}
