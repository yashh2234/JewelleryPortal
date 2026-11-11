<?php

namespace App\Jobs;

use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @param list<int> $userIds */
    public function __construct(public int $tenantId, public string $userType, public array $userIds, public array $payload) {}

    public function handle(PushSender $sender): void
    {
        $subs = DB::table('push_subscriptions')
            ->where('tenant_id', $this->tenantId)
            ->where('user_type', $this->userType)
            ->whereIn('user_id', $this->userIds)
            ->get(['endpoint', 'public_key', 'auth_token', 'content_encoding'])
            ->all();

        $gone = $sender->send($subs, $this->payload);

        if ($gone) {
            DB::table('push_subscriptions')->whereIn('endpoint_hash', array_map(fn ($e) => hash('sha256', $e), $gone))->delete();
        }
    }
}
