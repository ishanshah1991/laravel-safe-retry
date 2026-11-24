<?php

namespace IshanShah\SafeRetry\Exceptions;

class InvalidIdempotencyKeyException extends SafeRetryException
{
    public function __construct(string $header, int $maxLength)
    {
        parent::__construct(400, "The {$header} header must be between 1 and {$maxLength} characters.");
    }
}
