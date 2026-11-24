<?php

namespace IshanShah\SafeRetry\Exceptions;

class IdempotencyKeyMismatchException extends SafeRetryException
{
    public function __construct()
    {
        parent::__construct(422, 'This idempotency key was already used for a different request.');
    }
}
