<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Web Push (VAPID). Needs WEBPUSH_PUBLIC_KEY / WEBPUSH_PRIVATE_KEY in .env. */
class WebPushSender implements PushSender
{
    public function send(array $subscriptions, array $payload): array
    {
        $public = config('services.webpush.public_key');
        $private = config('services.webpush.private_key');

        if (! $public || ! $private || $subscriptions === []) {
            return [];
        }

        $push = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => $public,
            'privateKey' => $private,
        ]], ['TTL' => 3600 * 12]);

        $json = json_encode($payload);
        foreach ($subscriptions as $s) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint,
                'publicKey' => $s->public_key,
                'authToken' => $s->auth_token,
                'contentEncoding' => $s->content_encoding,
            ]), $json);
        }

        $gone = [];
        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $gone[] = $report->getEndpoint();
            } elseif (! $report->isSuccess()) {
                Log::warning('Web push failed', ['reason' => $report->getReason()]);
            }
        }

        return $gone;
    }
}
