<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * Send an OTP. Indian SMS routes need a DLT-registered sender ID and template,
     * so the provider driver receives the template variables, not free text.
     */
    public function sendOtp(string $phone, string $code): void;
}
