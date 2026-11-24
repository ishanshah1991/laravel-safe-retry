<?php

namespace IshanShah\SafeRetry\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Base class for every safe-retry error. These are HTTP exceptions, so Laravel
 * renders them with the right status code; customise them in your exception
 * handler like any other exception.
 */
abstract class SafeRetryException extends HttpException {}
