<?php

namespace App\Services\Push;

interface PushSender
{
    /**
     * @param  list<object{endpoint: string, public_key: string, auth_token: string, content_encoding: string}>  $subscriptions
     * @return list<string> endpoints that are gone (expired / unsubscribed) and should be deleted
     */
    public function send(array $subscriptions, array $payload): array;
}
