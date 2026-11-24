<?php

namespace IshanShah\SafeRetry\Contracts;

use Illuminate\Http\Request;

interface Fingerprinter
{
    /**
     * Build a stable hash that identifies what the request is asking for.
     */
    public function fingerprint(Request $request): string;
}
