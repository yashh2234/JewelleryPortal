<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business messages through Meta's Cloud API.
 *
 * Business-initiated messages must use an approved template. Create one template
 * (e.g. "erp_update", category Utility) with a single body variable {{1}} and set
 * WHATSAPP_TEMPLATE to its name. With WHATSAPP_DRIVER=log (the default) messages
 * are only written to the log, so nothing is sent until the account is set up.
 */
class WhatsApp
{
    public function enabled(): bool
    {
        return config('services.whatsapp.driver') === 'meta'
            && config('services.whatsapp.token') && config('services.whatsapp.phone_number_id');
    }

    /** Indian 10-digit mobiles get the 91 prefix; anything else is sent as given. */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        return strlen($digits) >= 11 ? $digits : null;
    }

    public function send(string $phone, string $text): void
    {
        $to = self::normalise($phone);
        if (! $to) {
            return;
        }

        if (! $this->enabled()) {
            Log::info('whatsapp (log driver)', ['to' => $to, 'text' => $text]);

            return;
        }

        $base = 'https://graph.facebook.com/'.config('services.whatsapp.api_version').'/'.config('services.whatsapp.phone_number_id').'/messages';
        $template = config('services.whatsapp.template');

        $payload = $template
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $template,
                'language' => ['code' => config('services.whatsapp.language')],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($text, 0, 1000)]]]],
            ]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text]];

        Http::withToken(config('services.whatsapp.token'))->timeout(15)->post($base, $payload)->throw();
    }
}
