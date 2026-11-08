<?php

namespace App\Services\Push;

use App\Jobs\SendPush;
use App\Models\KaarigarUser;
use App\Models\RetailerUser;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;

/**
 * Who gets a push for what. Each call queues one job (sent by the cron-driven
 * queue worker); nothing here blocks the request.
 *
 *  - New gold issue          → the kaarigar's phone(s)
 *  - Wastage above the limit → owners
 *  - Order status change     → the retailer's users
 */
class Notify
{
    public function __construct(private TenantContext $context) {}

    public function kaarigar(int $kaarigarId, string $title, string $body, string $url = '/app/kaarigar'): void
    {
        $ids = KaarigarUser::where('kaarigar_id', $kaarigarId)->where('is_active', true)->pluck('id')->all();
        $this->queue('kaarigar', $ids, $title, $body, $url);
    }

    public function owners(string $title, string $body, string $url = '/app/staff'): void
    {
        $ids = User::role(Permissions::ROLE_OWNER)->where('is_active', true)->pluck('id')->all();
        $this->queue('staff', $ids, $title, $body, $url);
    }

    public function retailer(int $retailerId, string $title, string $body, string $url = '/app/portal'): void
    {
        $ids = RetailerUser::where('retailer_id', $retailerId)->where('is_active', true)->pluck('id')->all();
        $this->queue('retailer', $ids, $title, $body, $url);
    }

    /** Staff who hold a permission (e.g. sales for new portal orders). */
    public function staffWith(string $permission, string $title, string $body, string $url = '/app/staff'): void
    {
        $ids = User::permission($permission)->where('is_active', true)->pluck('id')->all();
        $this->queue('staff', $ids, $title, $body, $url);
    }

    /** Retailer hears about their order: push to phones + email to the shop's users. */
    public function orderStatus(\App\Models\Order $order, string $headline): void
    {
        $this->retailer($order->retailer_id, "Order {$order->order_no}", $headline, "/app/portal/orders/{$order->id}");

        $emails = RetailerUser::where('retailer_id', $order->retailer_id)->where('is_active', true)->pluck('email')->filter()->all();
        if ($emails && $this->context->check()) {
            $tenant = $this->context->get();
            $central = config('tenancy.central_domain');
            $url = (request()->getHost() && ! app()->runningInConsole() ? request()->getSchemeAndHttpHost() : "https://{$tenant->subdomain}.{$central}")."/app/portal/orders/{$order->id}";

            \Illuminate\Support\Facades\Notification::route('mail', $emails)
                ->notify(new \App\Notifications\OrderStatusChanged($tenant->name, $order->order_no, $headline, $url));
        }
    }

    private function queue(string $type, array $userIds, string $title, string $body, string $url): void
    {
        if ($userIds === [] || ! $this->context->check()) {
            return;
        }

        SendPush::dispatch($this->context->id(), $type, $userIds, [
            'title' => $title, 'body' => $body, 'url' => $url, 'tag' => $type.'-'.md5($title),
        ])->afterCommit();
    }
}
