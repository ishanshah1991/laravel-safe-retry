<?php

namespace IshanShah\SafeRetry\Tests\Feature;

class CacheStoreTest extends MiddlewareTestCase
{
    protected function storeName(): string
    {
        return 'cache';
    }
}
