<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the OTP to the log instead of sending an SMS. */
class LogSmsSender implements SmsSender
{
    public function sendOtp(string $phone, string $code): void
    {
        Log::info("[sms] OTP for {$phone}: {$code}");
    }
}
