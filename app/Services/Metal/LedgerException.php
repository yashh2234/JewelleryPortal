<?php

namespace App\Services\Metal;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A business-rule refusal (not enough stock, closed day…). Rendered as 422 with the message. */
class LedgerException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
