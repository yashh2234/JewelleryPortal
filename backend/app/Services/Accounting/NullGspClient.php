<?php

namespace App\Services\Accounting;

use App\Services\Metal\LedgerException;

/** Default until a GSP account is set up: explains what to do instead. */
class NullGspClient implements GspClient
{
    public function generateIrn(array $payload): array
    {
        throw new LedgerException('No GSP is connected yet. Download the e-invoice JSON and upload it on the GST e-invoice portal, or ask us to connect your GSP.');
    }
}
