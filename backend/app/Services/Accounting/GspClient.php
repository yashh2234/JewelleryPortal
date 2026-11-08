<?php

namespace App\Services\Accounting;

/** A GST Suvidha Provider (GSP) connection that returns IRN, ack no. and signed QR. */
interface GspClient
{
    /** @return array{irn: string, ack_no: string, ack_date: string, signed_qr: string} */
    public function generateIrn(array $payload): array;
}
