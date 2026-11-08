<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MSG91 "Flow" API. Needs a DLT-approved OTP template in MSG91 with an {{otp}} variable.
 * Set SMS_DRIVER=msg91, MSG91_AUTH_KEY and MSG91_OTP_TEMPLATE_ID.
 */
class Msg91SmsSender implements SmsSender
{
    public function __construct(private string $authKey, private string $templateId) {}

    public function sendOtp(string $phone, string $code): void
    {
        $response = Http::timeout(10)
            ->withHeaders(['authkey' => $this->authKey, 'accept' => 'application/json'])
            ->post('https://control.msg91.com/api/v5/flow', [
                'template_id' => $this->templateId,
                'short_url' => '0',
                'recipients' => [['mobiles' => '91'.$phone, 'otp' => $code]],
            ]);

        if ($response->failed() || $response->json('type') === 'error') {
            throw new RuntimeException('MSG91 send failed: '.$response->body());
        }
    }
}
