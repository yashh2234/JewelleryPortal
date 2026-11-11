<?php

namespace App\Console\Commands;

use App\Models\JobCard;
use App\Models\MetalTransaction;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Push\Notify;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Morning alerts (spec §14 "production delayed"): overdue work orders and job
 * cards to production, overdue orders to sales, unacknowledged gold issues to the
 * owners. One summary message per group, not one per item.
 */
class DailyAlerts extends Command
{
    protected $signature = 'erp:daily-alerts';

    protected $description = 'Send the daily production-delay and gold-acknowledgement alerts';

    public function handle(TenantContext $context, Notify $notify): int
    {
        Tenant::whereIn('status', ['trial', 'active'])->each(function (Tenant $tenant) use ($context, $notify) {
            $context->runAs($tenant, function () use ($notify) {
                $today = today()->toDateString();

                $wos = WorkOrder::whereIn('status', [WorkOrder::OPEN, WorkOrder::IN_PROGRESS])->whereNotNull('due_date')->where('due_date', '<', $today)->pluck('wo_no');
                $cards = JobCard::where('status', JobCard::OPEN)->whereNotNull('due_date')->where('due_date', '<', $today)->count();
                if ($wos->isNotEmpty() || $cards) {
                    $notify->staffWith('production.manage', 'Production delayed',
                        trim(($wos->isNotEmpty() ? $wos->count().' work order(s) past due: '.$wos->take(5)->implode(', ').($wos->count() > 5 ? '…' : '').'. ' : '')
                            .($cards ? "{$cards} job card(s) overdue." : '')),
                        '/staff/reports/delayed-work-orders', 'production.delayed');
                }

                $orders = Order::whereNotNull('due_date')->where('due_date', '<', $today)
                    ->whereIn('status', [Order::NEW, Order::PENDING_APPROVAL, Order::APPROVED, ...Order::PRODUCTION])->count();
                if ($orders) {
                    $notify->staffWith('orders.manage', 'Orders past their due date', "{$orders} order(s) not yet dispatched.", '/staff/orders?overdue=1', 'order.delayed');
                }

                $unacked = MetalTransaction::where('type', MetalTransaction::ISSUE)->whereNull('kaarigar_ack')->whereNull('reversed_by_id')
                    ->where('created_at', '<', now()->subDay())->count();
                if ($unacked) {
                    $notify->owners('Gold issues not acknowledged', "{$unacked} issue(s) older than 24 h are not accepted or signed for.", '/staff/metal/transactions', 'gold.unacknowledged');
                }
            });
        });

        return self::SUCCESS;
    }
}
