<?php

namespace IshanShah\SafeRetry\Events;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fired when a response is saved so later retries with the same key replay it.
 */
class ResponseStored
{
    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
        public readonly string $idempotencyKey,
    ) {}
}
