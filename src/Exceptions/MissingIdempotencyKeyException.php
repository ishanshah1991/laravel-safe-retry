<?php

namespace IshanShah\SafeRetry\Exceptions;

class MissingIdempotencyKeyException extends SafeRetryException
{
    public function __construct(string $header)
    {
        parent::__construct(400, "The {$header} header is required for this request.");
    }
}
