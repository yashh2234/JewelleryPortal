<?php

namespace App\Models\Concerns;

use App\Services\Auth\LoginOtp;

/** Store Indian mobiles as plain 10 digits ("+91 98765-43210" → "9876543210") so OTP login finds them. */
trait NormalisesPhone
{
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value === null || $value === ''
            ? null
            : (LoginOtp::normalise($value) ?? trim($value));
    }
}
