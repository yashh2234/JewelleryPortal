<?php

namespace App\Services\Push;

use App\Jobs\SendPush;
use App\Jobs\SendWhatsApp;
use App\Models\Kaarigar;
use App\Models\KaarigarUser;
use App\Models\Retailer;
use App\Models\RetailerUser;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Who hears about what, on which channel. Every message is:
 *   - saved as an in-app notification (the bell), always;
 *   - pushed to the person's devices (queued);
 *   - for retailers and kaarigars, also sent on WhatsApp when it matters
 *     (order status, new gold issue). Email for retailer order updates.
 *
 * Nothing here blocks the request: pushes and WhatsApp go through the queue.
 */
class Notify
{
    public function __construct(private TenantContext $context) {}

    public function kaarigar(int $kaarigarId, string $title, string $body, string $url = '/kaarigar', ?string $event = null, bool $whatsapp = false): void
    {
        $users = KaarigarUser::where('kaarigar_id', $kaarigarId)->where('is_active', true)->get(['id', 'phone']);
        $this->deliver('kaarigar', $users->pluck('id')->all(), $title, $body, $url, $event);

        if ($whatsapp) {
            $phones = $users->pluck('phone')->push(Kaarigar::whereKey($kaarigarId)->value('phone'))->filter()->values()->all();
            $this->whatsapp($phones, "{$title}\n{$body}");
        }
    }

    public function owners(string $title, string $body, string $url = '/staff', ?string $event = null): void
    {
        $ids = User::role(Permissions::ROLE_OWNER)->where('is_active', true)->pluck('id')->all();
        $this->deliver('staff', $ids, $title, $body, $url, $event);
    }

    public function retailer(int $retailerId, string $title, string $body, string $url = '/portal', ?string $event = null, bool $whatsapp = false): void
    {
        $users = RetailerUser::where('retailer_id', $retailerId)->where('is_active', true)->get(['id', 'phone']);
        $this->deliver('retailer', $users->pluck('id')->all(), $title, $body, $url, $event);

        if ($whatsapp) {
            $phones = $users->pluck('phone')->push(Retailer::whereKey($retailerId)->value('phone'))->filter()->values()->all();
            $this->whatsapp($phones, "{$title}\n{$body}");
        }
    }

    /** Staff who hold a permission (owners hold all of them). */
    public function staffWith(string $permission, string $title, string $body, string $url = '/staff', ?string $event = null): void
    {
        try {
            $ids = User::permission($permission)->where('is_active', true)->pluck('id')->all();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            $ids = [];
        }
        $ids = array_values(array_unique([...$ids, ...User::role(Permissions::ROLE_OWNER)->where('is_active', true)->pluck('id')->all()]));
        $this->deliver('staff', $ids, $title, $body, $url, $event);
    }

    /** One staff member (e.g. the retailer's salesperson). */
    public function staffUser(?int $userId, string $title, string $body, string $url = '/staff', ?string $event = null): void
    {
        if ($userId) {
            $this->deliver('staff', [$userId], $title, $body, $url, $event);
        }
    }

    /** Retailer hears about their order: in-app + push + WhatsApp + email to the shop's users. */
    public function orderStatus(\App\Models\Order $order, string $headline, string $event = 'order.status'): void
    {
        $tenantName = $this->context->check() ? $this->context->get()->name : '';
        $this->retailer($order->retailer_id, "Order {$order->order_no}", $headline, "/portal/orders/{$order->id}", $event, whatsapp: true);

        $emails = RetailerUser::where('retailer_id', $order->retailer_id)->where('is_active', true)->pluck('email')->filter()->all();
        if ($emails && $this->context->check()) {
            $tenant = $this->context->get();
            $central = config('tenancy.central_domain');
            $url = (request()->getHost() && ! app()->runningInConsole() ? request()->getSchemeAndHttpHost() : "https://{$tenant->subdomain}.{$central}")."/portal/orders/{$order->id}";

            \Illuminate\Support\Facades\Notification::route('mail', $emails)
                ->notify(new \App\Notifications\OrderStatusChanged($tenantName, $order->order_no, $headline, $url));
        }
    }

    private function deliver(string $type, array $userIds, string $title, string $body, string $url, ?string $event): void
    {
        if ($userIds === [] || ! $this->context->check()) {
            return;
        }

        $now = now();
        DB::table('app_notifications')->insert(array_map(fn ($id) => [
            'tenant_id' => $this->context->id(), 'user_type' => $type, 'user_id' => $id, 'event' => $event,
            'title' => mb_substr($title, 0, 255), 'body' => mb_substr($body, 0, 500), 'url' => $url, 'created_at' => $now,
        ], $userIds));

        SendPush::dispatch($this->context->id(), $type, $userIds, [
            'title' => $title, 'body' => $body, 'url' => $url, 'tag' => $type.'-'.md5($title),
        ])->afterCommit();
    }

    /** @param list<string> $phones */
    private function whatsapp(array $phones, string $text): void
    {
        if ($phones !== [] && $this->context->check()) {
            $name = $this->context->get()->name;
            SendWhatsApp::dispatch($phones, "{$name}: {$text}")->afterCommit();
        }
    }
}
