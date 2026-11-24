<?php

namespace IshanShah\SafeRetry\Exceptions;

class IdempotencyKeyInUseException extends SafeRetryException
{
    public function __construct(string $message = 'A request with this idempotency key is already being processed. Retry later.')
    {
        parent::__construct(409, $message, null, ['Retry-After' => '1']);
    }
}
