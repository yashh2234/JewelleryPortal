<?php

namespace App\Services\Reports;

use App\Http\Controllers\Staff\GoldRateController;
use App\Models\Dispatch;
use App\Models\FinishedItem;
use App\Models\InvoiceLine;
use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\MetalTransactionLine;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\Voucher;
use App\Models\WorkOrder;
use App\Models\WoStep;
use App\Services\Accounting\Reports as AccountingReports;
use App\Services\Metal\Ledger;
use App\Support\Metal\Weight;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers for the owner dashboard (spec §3) and the downloadable reports (spec §12).
 * Grouping is done in PHP so the same code runs on MySQL and SQLite. Each report
 * returns ['title', 'columns', 'rows', 'totals'] for the Exporter.
 */
class Analytics
{
    public function __construct(private Ledger $ledger, private AccountingReports $accounts) {}

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function kpis(): array
    {
        $counts = Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn (array $s) => (int) collect($s)->sum(fn ($k) => $counts[$k] ?? 0);

        $gold = $this->ledger->totalsByType();
        $g = fn (string $t) => $gold[$t] ?? '0.000';
        $stock = Weight::add('0', ...array_map($g, MetalLocation::HELD));

        $monthStart = today()->startOfMonth()->toDateString();
        $monthSales = Money::round((string) Voucher::where('type', Voucher::SALES)->where('status', 'posted')->where('date', '>=', $monthStart)->sum('amount'));
        $lastMonthSales = Money::round((string) Voucher::where('type', Voucher::SALES)->where('status', 'posted')
            ->whereBetween('date', [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString()])->sum('amount'));

        $receivedMonth = MetalTransactionLine::whereHas('transaction', fn ($q) => $q->where('type', MetalTransaction::RECEIVE)->whereNull('reversed_by_id')->where('date', '>=', $monthStart))->sum('fine_wt');

        $finished = FinishedItem::whereIn('status', [FinishedItem::IN_STOCK]);

        return [
            'orders' => [
                'total' => (int) $counts->sum(),
                'new' => $n([Order::NEW]),
                'awaiting_approval' => $n([Order::NEW, Order::PENDING_APPROVAL]),
                'in_production' => $n([Order::APPROVED, Order::WORK_ORDER_CREATED, Order::MATERIAL_ISSUED, Order::IN_PRODUCTION, Order::UNDER_QC, Order::REWORK_REQUIRED, Order::PRODUCTION_COMPLETED]),
                'pending' => $n([Order::NEW, Order::PENDING_APPROVAL, ...Order::PRODUCTION, Order::APPROVED, Order::PARTIALLY_DISPATCHED, Order::ON_HOLD]),
                'completed' => $n([Order::DISPATCHED, Order::DELIVERED, Order::CLOSED]),
                'ready_for_dispatch' => $n([Order::READY_FOR_DISPATCH, Order::PARTIALLY_DISPATCHED]),
                'rework' => $n([Order::REWORK_REQUIRED]),
                'approved_without_wo' => Order::where('status', Order::APPROVED)->count(),
                'overdue' => Order::whereNotNull('due_date')->where('due_date', '<', today()->toDateString())
                    ->whereIn('status', [Order::NEW, Order::PENDING_APPROVAL, Order::APPROVED, ...Order::PRODUCTION])->count(),
            ],
            'gold' => [
                'total_stock' => $stock,
                'vault' => $g(MetalLocation::VAULT),
                'with_kaarigars' => $g(MetalLocation::KAARIGAR),
                'in_production' => Weight::add($g(MetalLocation::KAARIGAR), $g(MetalLocation::WO)),
                'received_this_month' => Weight::fromDb((string) $receivedMonth),
                'finished' => $g(MetalLocation::FINISHED),
                'scrap' => $g(MetalLocation::SCRAP),
                'wastage_to_date' => $g(MetalLocation::LOSS),
                'pending_reconciliation' => Weight::fromDb((string) JobCard::where('loss_status', JobCard::LOSS_PENDING)->sum('loss_fine')),
            ],
            'finished' => ['pieces' => (clone $finished)->count(), 'ready' => (clone $finished)->where('qc_status', 'passed')->count(), 'qc_pending' => (clone $finished)->where('qc_status', 'pending')->count()],
            'money' => [
                'outstanding' => $this->accounts->outstanding('retailer', today()->toDateString())['total'],
                'month_sales' => $monthSales,
                'last_month_sales' => $lastMonthSales,
            ],
            'efficiency' => $this->efficiency(),
        ];
    }

    /** On-time completion, average days and rework rate over the last 90 days. */
    public function efficiency(int $days = 90): array
    {
        $done = WorkOrder::where('status', WorkOrder::COMPLETED)->where('completed_at', '>=', now()->subDays($days))->get(['id', 'created_at', 'completed_at', 'due_date', 'rework_of_id']);
        $withDue = $done->filter(fn ($w) => $w->due_date);
        $onTime = $withDue->filter(fn ($w) => $w->completed_at->toDateString() <= $w->due_date->toDateString())->count();

        $cards = JobCard::where('status', JobCard::CLOSED)->where('closed_at', '>=', now()->subDays($days));
        $issued = Weight::fromDb((string) (clone $cards)->sum('issued_fine'));
        $loss = Weight::fromDb((string) (clone $cards)->sum('loss_fine'));
        $allowed = Weight::fromDb((string) (clone $cards)->sum('allowed_loss_fine'));

        return [
            'days' => $days,
            'completed' => $done->count(),
            'on_time_pct' => $withDue->count() ? round($onTime * 100 / $withDue->count(), 1) : null,
            'avg_days' => $done->count() ? round($done->avg(fn ($w) => $w->created_at->diffInHours($w->completed_at) / 24), 1) : null,
            'rework_pct' => $done->count() ? round($done->whereNotNull('rework_of_id')->count() * 100 / $done->count(), 1) : null,
            'wastage_pct' => Weight::isPositive($issued) ? round((float) $loss * 100 / (float) $issued, 3) : null,
            'allowed_wastage_pct' => Weight::isPositive($issued) ? round((float) $allowed * 100 / (float) $issued, 3) : null,
        ];
    }

    /** Charts: last 12 months of sales and orders, production per month, gold by location, orders by status. */
    public function charts(): array
    {
        $months = collect(range(11, 0))->map(fn ($i) => today()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'));
        $from = $months->first().'-01';
        $key = fn ($d) => Carbon::parse($d)->format('Y-m');

        $sales = Voucher::where('type', Voucher::SALES)->where('status', 'posted')->where('date', '>=', $from)->get(['date', 'amount'])
            ->groupBy(fn ($v) => $key($v->date))->map(fn ($g) => Money::add('0', ...$g->pluck('amount')->map(fn ($a) => (string) $a)->all()));
        $ordersIn = Order::where('created_at', '>=', $from)->get(['created_at'])->groupBy(fn ($o) => $key($o->created_at))->map->count();
        $dispatched = Dispatch::where('date', '>=', $from)->get(['date', 'pieces'])->groupBy(fn ($d) => $key($d->date));
        $woDone = WorkOrder::where('status', WorkOrder::COMPLETED)->where('completed_at', '>=', $from)->get(['completed_at', 'qty'])->groupBy(fn ($w) => $key($w->completed_at));
        $finished = FinishedItem::where('created_at', '>=', $from)->get(['created_at', 'fine_wt'])->groupBy(fn ($i) => $key($i->created_at));

        $gold = $this->ledger->totalsByType();
        $statusCounts = Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'months' => $months->map(fn ($m) => ['month' => $m,
                'sales' => (float) ($sales[$m] ?? 0),
                'orders' => (int) ($ordersIn[$m] ?? 0),
                'dispatched_pieces' => (int) ($dispatched[$m] ?? collect())->sum('pieces'),
                'wo_completed' => (int) ($woDone[$m] ?? collect())->count(),
                'pieces_finished' => (int) ($finished[$m] ?? collect())->count(),
                'fine_finished' => (float) ($finished[$m] ?? collect())->sum('fine_wt'),
            ])->values(),
            'gold_by_location' => collect(MetalLocation::HELD)->map(fn ($t) => ['location' => $t, 'fine' => (float) ($gold[$t] ?? 0)])->values(),
            'orders_by_status' => collect([...Order::FLOW, Order::PARTIALLY_DISPATCHED, Order::ON_HOLD, Order::CANCELLED])
                ->map(fn ($s) => ['status' => $s, 'count' => (int) ($statusCounts[$s] ?? 0)])->filter(fn ($r) => $r['count'] > 0)->values(),
        ];
    }

    // ── Reports (spec §12) ──────────────────────────────────────────────────

    public const REPORTS = [
        'production-summary' => 'Production summary',
        'production-by-kaarigar' => 'Production by kaarigar',
        'delayed-work-orders' => 'Delayed work orders',
        'gold-summary' => 'Gold summary',
        'gold-with-kaarigars' => 'Gold with each kaarigar',
        'gold-reconciliation' => 'Gold reconciliation differences',
        'gold-stock' => 'Current gold stock',
        'sales-by-retailer' => 'Sales by retailer',
        'sales-by-category' => 'Sales by product category',
        'monthly-revenue' => 'Monthly revenue',
        'outstanding' => 'Outstanding payments',
        'retailer-performance' => 'Retailer performance',
        'order-profitability' => 'Profitability by order',
    ];

    public function report(string $name, string $from, string $to): array
    {
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));
        abort_unless(isset(self::REPORTS[$name]) && method_exists($this, $method), 404);

        return ['title' => self::REPORTS[$name], 'subtitle' => Carbon::parse($from)->format('d M Y').' – '.Carbon::parse($to)->format('d M Y')] + $this->{$method}($from, $to);
    }

    private function productionSummary(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $orders = Order::whereBetween('created_at', $range)->get(['id', 'status']);
        $completedOrders = Order::whereIn('status', [Order::DISPATCHED, Order::DELIVERED, Order::CLOSED])->whereBetween('updated_at', $range)->count();
        $wos = WorkOrder::whereBetween('completed_at', $range)->where('status', WorkOrder::COMPLETED)->get();
        $qcd = FinishedItem::whereBetween('qc_at', $range)->get(['qc_status']);
        $eff = $this->efficiency(max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1));

        $rows = [
            ['metric' => 'Orders received', 'value' => (string) $orders->count()],
            ['metric' => 'Orders completed (dispatched / delivered / closed)', 'value' => (string) $completedOrders],
            ['metric' => 'Orders pending now', 'value' => (string) Order::whereNotIn('status', [Order::DISPATCHED, Order::DELIVERED, Order::CLOSED, Order::CANCELLED])->count()],
            ['metric' => 'Work orders completed', 'value' => (string) $wos->count()],
            ['metric' => 'Pieces produced', 'value' => (string) FinishedItem::whereBetween('created_at', $range)->count()],
            ['metric' => 'Average production time (days)', 'value' => $wos->count() ? (string) round($wos->avg(fn ($w) => $w->created_at->diffInHours($w->completed_at) / 24), 1) : '—'],
            ['metric' => 'Work orders on time', 'value' => $eff['on_time_pct'] !== null ? $eff['on_time_pct'].'%' : '—'],
            ['metric' => 'Rework work orders', 'value' => (string) $wos->whereNotNull('rework_of_id')->count().($wos->count() ? ' ('.round($wos->whereNotNull('rework_of_id')->count() * 100 / $wos->count(), 1).'%)' : '')],
            ['metric' => 'QC pass rate', 'value' => $qcd->count() ? round($qcd->where('qc_status', 'passed')->count() * 100 / $qcd->count(), 1).'%' : '—'],
            ['metric' => 'Delayed work orders open now', 'value' => (string) WorkOrder::whereIn('status', [WorkOrder::OPEN, WorkOrder::IN_PROGRESS])->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->count()],
        ];

        return ['columns' => [['key' => 'metric', 'label' => 'Measure'], ['key' => 'value', 'label' => 'Value', 'num' => true]], 'rows' => $rows];
    }

    private function productionByKaarigar(string $from, string $to): array
    {
        $cards = JobCard::with('kaarigar:id,name')->where('status', JobCard::CLOSED)
            ->whereBetween('closed_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $open = JobCard::where('status', JobCard::OPEN)->get(['kaarigar_id', 'pieces', 'due_date'])->groupBy('kaarigar_id');

        $rows = $cards->groupBy('kaarigar_id')->map(function (Collection $g, $kid) use ($open) {
            $issued = Weight::add('0', ...$g->pluck('issued_fine')->map(fn ($v) => (string) $v)->all());
            $loss = Weight::add('0', ...$g->pluck('loss_fine')->map(fn ($v) => (string) $v)->all());

            return [
                'kaarigar' => $g->first()->kaarigar->name,
                'cards' => (string) $g->count(),
                'pieces' => (string) $g->sum('pieces'),
                'issued_fine' => $issued,
                'handed_in_fine' => Weight::add('0', ...$g->pluck('handed_in_fine')->map(fn ($v) => (string) $v)->all()),
                'returned_fine' => Weight::add('0', ...$g->map(fn ($c) => Weight::add((string) $c->returned_fine, (string) $c->scrap_fine))->all()),
                'handed_out_fine' => Weight::add('0', ...$g->pluck('handed_out_fine')->map(fn ($v) => (string) $v)->all()),
                'loss_fine' => $loss,
                'wastage_pct' => Weight::isPositive($issued) ? number_format((float) $loss * 100 / (float) $issued, 3) : '0.000',
                'excess_fine' => Weight::add('0', ...$g->pluck('excess_loss_fine')->map(fn ($v) => (string) $v)->all()),
                'avg_days' => number_format($g->avg(fn ($c) => $c->created_at->diffInHours($c->closed_at) / 24), 1),
                'open_cards' => (string) ($open[$kid] ?? collect())->count(),
                'overdue' => (string) ($open[$kid] ?? collect())->filter(fn ($c) => $c->due_date && $c->due_date->toDateString() < today()->toDateString())->count(),
            ];
        })->sortBy('kaarigar')->values();

        return [
            'columns' => [
                ['key' => 'kaarigar', 'label' => 'Kaarigar'], ['key' => 'cards', 'label' => 'Job cards', 'num' => true], ['key' => 'pieces', 'label' => 'Pieces', 'num' => true],
                ['key' => 'issued_fine', 'label' => 'Issued fine g', 'num' => true], ['key' => 'returned_fine', 'label' => 'Returned fine g', 'num' => true],
                ['key' => 'loss_fine', 'label' => 'Wastage g', 'num' => true], ['key' => 'wastage_pct', 'label' => 'Wastage %', 'num' => true],
                ['key' => 'excess_fine', 'label' => 'Over limit g', 'num' => true], ['key' => 'avg_days', 'label' => 'Avg days', 'num' => true],
                ['key' => 'open_cards', 'label' => 'Open now', 'num' => true], ['key' => 'overdue', 'label' => 'Overdue', 'num' => true],
            ],
            'rows' => $rows,
            'totals' => ['kaarigar' => 'Total', 'cards' => (string) $rows->sum('cards'), 'pieces' => (string) $rows->sum('pieces'),
                'issued_fine' => Weight::add('0', ...$rows->pluck('issued_fine')->all()), 'returned_fine' => Weight::add('0', ...$rows->pluck('returned_fine')->all()),
                'loss_fine' => Weight::add('0', ...$rows->pluck('loss_fine')->all()), 'excess_fine' => Weight::add('0', ...$rows->pluck('excess_fine')->all())],
        ];
    }

    private function delayedWorkOrders(string $from, string $to): array
    {
        $rows = WorkOrder::with(['design:id,code,name', 'karat:id,label', 'orderLines.order:id,order_no,retailer_id', 'orderLines.order.retailer:id,shop_name', 'steps.process:id,name'])
            ->whereIn('status', [WorkOrder::OPEN, WorkOrder::IN_PROGRESS])->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())
            ->orderBy('due_date')->get()
            ->map(fn ($w) => [
                'wo_no' => $w->wo_no, 'design' => "{$w->design->code} {$w->design->name}", 'karat' => $w->karat->label, 'qty' => (string) $w->qty,
                'order' => $w->orderLines->first()?->order?->order_no ?? 'Stock', 'retailer' => $w->orderLines->first()?->order?->retailer?->shop_name ?? '',
                'stage' => $w->steps->firstWhere(fn ($s) => in_array($s->status, ['pending', 'in_progress'], true))?->process->name ?? '',
                'due' => $w->due_date->format('d M Y'), 'days_late' => (string) $w->due_date->diffInDays(today()),
                'priority' => $w->priority,
            ]);

        return ['columns' => [
            ['key' => 'wo_no', 'label' => 'Work order'], ['key' => 'design', 'label' => 'Design'], ['key' => 'karat', 'label' => 'Karat'], ['key' => 'qty', 'label' => 'Qty', 'num' => true],
            ['key' => 'order', 'label' => 'Order'], ['key' => 'retailer', 'label' => 'Retailer'], ['key' => 'stage', 'label' => 'Current stage'],
            ['key' => 'due', 'label' => 'Due'], ['key' => 'days_late', 'label' => 'Days late', 'num' => true], ['key' => 'priority', 'label' => 'Priority'],
        ], 'rows' => $rows];
    }

    private function goldSummary(string $from, string $to): array
    {
        $types = [
            MetalTransaction::OPENING => 'Opening stock entered', MetalTransaction::PURCHASE => 'Gold purchased', MetalTransaction::DEPOSIT => 'Retailer deposits',
            MetalTransaction::ISSUE => 'Issued to kaarigars', MetalTransaction::RECEIVE => 'Received back from kaarigars',
            MetalTransaction::FINISH => 'Converted into finished jewellery', MetalTransaction::DISPATCH => 'Dispatched to retailers',
            MetalTransaction::LOSS_WRITEOFF => 'Wastage written off', MetalTransaction::REFINE => 'Refining',
        ];

        $lines = MetalTransactionLine::with('transaction:id,type,date,reversed_by_id')
            ->whereHas('transaction', fn ($q) => $q->whereBetween('date', [$from, $to])->whereNull('reversed_by_id')->whereIn('type', array_keys($types)))->get();

        $scrap = $lines->filter(fn ($l) => $l->transaction->type === MetalTransaction::RECEIVE && $l->kind === 'scrap');

        $rows = collect($types)->map(function ($label, $type) use ($lines) {
            $g = $lines->filter(fn ($l) => $l->transaction->type === $type);

            return ['item' => $label, 'entries' => (string) $g->pluck('metal_transaction_id')->unique()->count(),
                'gross' => Weight::add('0', ...$g->pluck('gross_wt')->map(fn ($v) => (string) $v)->all()),
                'fine' => Weight::add('0', ...$g->pluck('fine_wt')->map(fn ($v) => (string) $v)->all())];
        })->values();

        $rows->push(['item' => '  of which scrap / filings recovered', 'entries' => (string) $scrap->count(),
            'gross' => Weight::add('0', ...$scrap->pluck('gross_wt')->map(fn ($v) => (string) $v)->all()),
            'fine' => Weight::add('0', ...$scrap->pluck('fine_wt')->map(fn ($v) => (string) $v)->all())]);

        $current = $this->ledger->totalsByType($to);
        $rows->push(['item' => 'Gold held at '.Carbon::parse($to)->format('d M Y').' (vault, kaarigars, production, finished, scrap)', 'entries' => '',
            'gross' => '', 'fine' => Weight::add('0', ...array_map(fn ($t) => $current[$t] ?? '0', MetalLocation::HELD))]);

        return ['columns' => [['key' => 'item', 'label' => 'Movement'], ['key' => 'entries', 'label' => 'Entries', 'num' => true], ['key' => 'gross', 'label' => 'Gross g', 'num' => true], ['key' => 'fine', 'label' => 'Fine g', 'num' => true]], 'rows' => $rows];
    }

    private function goldWithKaarigars(string $from, string $to): array
    {
        $rows = Kaarigar::orderBy('name')->get()->map(function ($k) use ($to) {
            $bal = $this->ledger->balance($this->ledger->kaarigar($k), null, $to);
            $open = JobCard::where('kaarigar_id', $k->id)->where('status', JobCard::OPEN)->count();

            return ['kaarigar' => $k->name, 'phone' => $k->phone, 'gross' => $bal['gross'] ?? '', 'fine' => $bal['fine'], 'open_cards' => (string) $open,
                'limit' => $k->max_fine_balance !== null ? (string) $k->max_fine_balance : ''];
        })->filter(fn ($r) => Weight::compare($r['fine'], '0') !== 0 || $r['open_cards'] !== '0')->values();

        return ['columns' => [['key' => 'kaarigar', 'label' => 'Kaarigar'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'fine', 'label' => 'Fine g held', 'num' => true],
            ['key' => 'open_cards', 'label' => 'Open job cards', 'num' => true], ['key' => 'limit', 'label' => 'Limit g', 'num' => true]],
            'rows' => $rows, 'totals' => ['kaarigar' => 'Total', 'fine' => Weight::add('0', ...$rows->pluck('fine')->all())]];
    }

    /**
     * Blueprint §3 reconciliation: per closed job card, gold in (issued + handed in)
     * against gold out (returned + scrap & dust + handed on), the difference, how
     * much of it the process allows, and whether it has been approved.
     */
    private function goldReconciliation(string $from, string $to): array
    {
        $rows = JobCard::with(['kaarigar:id,name', 'process:id,name', 'woStep.workOrder:id,wo_no', 'lossApprover:id,name'])->where('status', JobCard::CLOSED)
            ->whereBetween('closed_at', [$from.' 00:00:00', $to.' 23:59:59'])->where('loss_fine', '>', 0)
            ->orderByRaw("case when loss_status = 'pending' then 0 else 1 end")->orderByDesc('loss_fine')->get()
            ->map(fn ($c) => [
                'card' => $c->card_no, 'wo' => $c->woStep?->workOrder?->wo_no ?? '', 'kaarigar' => $c->kaarigar->name, 'process' => $c->process->name,
                'in' => Weight::add((string) $c->issued_fine, (string) $c->handed_in_fine),
                'out' => Weight::add((string) $c->returned_fine, (string) $c->scrap_fine, (string) $c->handed_out_fine),
                'difference' => (string) $c->loss_fine, 'allowed' => (string) $c->allowed_loss_fine, 'excess' => (string) $c->excess_loss_fine,
                'status' => $c->loss_status === JobCard::LOSS_PENDING ? 'PENDING APPROVAL'
                    : trim((JobCard::LOSS_REASONS[$c->loss_reason_code] ?? 'approved').($c->excess_resolution === 'recovered' ? ' · excess recovered' : '')),
                'approved_by' => $c->lossApprover?->name ?? '',
            ]);

        return ['columns' => [
            ['key' => 'card', 'label' => 'Job card'], ['key' => 'wo', 'label' => 'WO'], ['key' => 'kaarigar', 'label' => 'Kaarigar'], ['key' => 'process', 'label' => 'Stage'],
            ['key' => 'in', 'label' => 'Gold in (fine)', 'num' => true], ['key' => 'out', 'label' => 'Gold out (fine)', 'num' => true],
            ['key' => 'difference', 'label' => 'Difference', 'num' => true], ['key' => 'allowed', 'label' => 'Allowed', 'num' => true], ['key' => 'excess', 'label' => 'Above limit', 'num' => true],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'approved_by', 'label' => 'Approved by'],
        ], 'rows' => $rows, 'totals' => ['card' => 'Total', 'difference' => Weight::add('0', ...$rows->pluck('difference')->all())]];
    }

    private function goldStock(string $from, string $to): array
    {
        $karats = Karat::orderByDesc('purity_pct')->get();
        $rows = collect([...MetalLocation::HELD, MetalLocation::LOSS])->map(function ($type) use ($karats, $to) {
            $row = ['location' => ucfirst(str_replace('_', ' ', $type))];
            $total = '0';
            foreach ($karats as $k) {
                $f = $this->ledger->totalsByType($to, $k->id)[$type] ?? '0.000';
                $row['k'.$k->id] = $f;
                $total = Weight::add($total, $f);
            }

            return $row + ['total' => $total];
        });

        return ['columns' => [['key' => 'location', 'label' => 'Where'], ...$karats->map(fn ($k) => ['key' => 'k'.$k->id, 'label' => $k->label.' fine g', 'num' => true])->all(), ['key' => 'total', 'label' => 'Total fine g', 'num' => true]],
            'rows' => $rows];
    }

    private function salesQuery(string $from, string $to)
    {
        return Voucher::with('party:id,name,type,ref_id')->where('type', Voucher::SALES)->where('status', 'posted')->whereBetween('date', [$from, $to]);
    }

    private function salesByRetailer(string $from, string $to): array
    {
        $rows = $this->salesQuery($from, $to)->get()->groupBy('party_id')->map(fn ($g) => [
            'retailer' => $g->first()->party?->name ?? '—', 'invoices' => (string) $g->count(),
            'fine' => Weight::add('0', ...$g->pluck('fine_wt')->map(fn ($v) => (string) $v)->all()),
            'amount' => Money::add('0', ...$g->pluck('amount')->map(fn ($v) => (string) $v)->all()),
        ])->sortByDesc(fn ($r) => (float) $r['amount'])->values();

        return ['columns' => [['key' => 'retailer', 'label' => 'Retailer'], ['key' => 'invoices', 'label' => 'Invoices', 'num' => true], ['key' => 'fine', 'label' => 'Fine g billed', 'num' => true], ['key' => 'amount', 'label' => 'Sales ₹', 'num' => true]],
            'rows' => $rows, 'totals' => ['retailer' => 'Total', 'invoices' => (string) $rows->sum('invoices'), 'amount' => Money::add('0', ...$rows->pluck('amount')->all())]];
    }

    private function salesByCategory(string $from, string $to): array
    {
        $lines = InvoiceLine::with('finishedItem.design.category:id,name')
            ->whereHas('voucher', fn ($q) => $q->where('type', Voucher::SALES)->where('status', 'posted')->whereBetween('date', [$from, $to]))->get();

        $rows = $lines->groupBy(fn ($l) => $l->finishedItem?->design?->category?->name ?? 'Other')->map(fn ($g, $cat) => [
            'category' => $cat, 'pieces' => (string) $g->count(),
            'net' => Weight::add('0', ...$g->pluck('net_wt')->map(fn ($v) => (string) $v)->all()),
            'making' => Money::add('0', ...$g->pluck('making')->map(fn ($v) => (string) $v)->all()),
            'taxable' => Money::add('0', ...$g->pluck('taxable')->map(fn ($v) => (string) $v)->all()),
        ])->sortByDesc(fn ($r) => (float) $r['taxable'])->values();

        return ['columns' => [['key' => 'category', 'label' => 'Category'], ['key' => 'pieces', 'label' => 'Pieces', 'num' => true], ['key' => 'net', 'label' => 'Net g', 'num' => true],
            ['key' => 'making', 'label' => 'Making ₹', 'num' => true], ['key' => 'taxable', 'label' => 'Taxable ₹', 'num' => true]],
            'rows' => $rows, 'totals' => ['category' => 'Total', 'pieces' => (string) $rows->sum('pieces'), 'taxable' => Money::add('0', ...$rows->pluck('taxable')->all())]];
    }

    private function monthlyRevenue(string $from, string $to): array
    {
        $rows = $this->salesQuery($from, $to)->get()->groupBy(fn ($v) => Carbon::parse($v->date)->format('Y-m'))->sortKeys()->map(fn ($g, $m) => [
            'month' => Carbon::parse($m.'-01')->format('M Y'), 'invoices' => (string) $g->count(),
            'fine' => Weight::add('0', ...$g->pluck('fine_wt')->map(fn ($v) => (string) $v)->all()),
            'amount' => Money::add('0', ...$g->pluck('amount')->map(fn ($v) => (string) $v)->all()),
        ])->values();

        return ['columns' => [['key' => 'month', 'label' => 'Month'], ['key' => 'invoices', 'label' => 'Invoices', 'num' => true], ['key' => 'fine', 'label' => 'Fine g', 'num' => true], ['key' => 'amount', 'label' => 'Revenue ₹', 'num' => true]],
            'rows' => $rows, 'totals' => ['month' => 'Total', 'amount' => Money::add('0', ...$rows->pluck('amount')->all())]];
    }

    private function outstanding(string $from, string $to): array
    {
        $o = $this->accounts->outstanding('retailer', $to);
        $rows = collect($o['rows'])->map(fn ($r) => ['retailer' => $r['name'], 'amount' => $r['amount'], 'd0' => $r['ageing']['0_30'], 'd31' => $r['ageing']['31_60'], 'd61' => $r['ageing']['61_90'], 'd90' => $r['ageing']['90_plus'], 'fine' => $r['fine']]);

        return ['columns' => [['key' => 'retailer', 'label' => 'Retailer'], ['key' => 'amount', 'label' => 'Outstanding ₹', 'num' => true], ['key' => 'd0', 'label' => '0–30 d', 'num' => true],
            ['key' => 'd31', 'label' => '31–60 d', 'num' => true], ['key' => 'd61', 'label' => '61–90 d', 'num' => true], ['key' => 'd90', 'label' => '90+ d', 'num' => true], ['key' => 'fine', 'label' => 'Metal g', 'num' => true]],
            'rows' => $rows, 'totals' => ['retailer' => 'Total', 'amount' => $o['total']]];
    }

    private function retailerPerformance(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $sales = $this->salesQuery($from, $to)->get()->groupBy(fn ($v) => $v->party?->ref_id);

        $rows = Retailer::with('salesperson:id,name')->where('status', Retailer::STATUS_ACTIVE)->orderBy('shop_name')->get()->map(function ($r) use ($range, $sales) {
            $orders = Order::where('retailer_id', $r->id)->whereBetween('created_at', $range)->withSum('lines as qty', 'qty')->get();
            $all = Order::where('retailer_id', $r->id)->count();
            $s = $sales[$r->id] ?? collect();

            return [
                'retailer' => $r->shop_name, 'city' => $r->city, 'salesperson' => $r->salesperson?->name ?? '',
                'orders' => (string) $orders->count(), 'pieces' => (string) $orders->sum('qty'),
                'sales' => Money::add('0', ...$s->pluck('amount')->map(fn ($v) => (string) $v)->all()),
                'repeat' => $all > 1 ? 'Yes ('.$all.')' : ($all ? 'First order' : '—'),
                'last_order' => Order::where('retailer_id', $r->id)->latest('id')->value('created_at')?->format('d M Y') ?? '',
            ];
        })->filter(fn ($r) => $r['orders'] !== '0' || ! Money::isZero($r['sales']))->sortByDesc(fn ($r) => (float) $r['sales'])->values();

        return ['columns' => [['key' => 'retailer', 'label' => 'Retailer'], ['key' => 'city', 'label' => 'City'], ['key' => 'salesperson', 'label' => 'Salesperson'],
            ['key' => 'orders', 'label' => 'Orders', 'num' => true], ['key' => 'pieces', 'label' => 'Pieces', 'num' => true], ['key' => 'sales', 'label' => 'Sales ₹', 'num' => true],
            ['key' => 'repeat', 'label' => 'Repeat buyer'], ['key' => 'last_order', 'label' => 'Last order']], 'rows' => $rows];
    }

    /**
     * Estimated profit per order: taxable sales − fine gold at the 24K rate − kaarigar
     * labour on its job cards. Metal is valued at today's
     * purest-karat rate, so this is a management estimate, not an accounting figure.
     */
    private function orderProfitability(string $from, string $to): array
    {
        $purest = Karat::orderByDesc('purity_pct')->first();
        $rate = $purest ? (string) (GoldRateController::effectiveRates($to)->keyBy('karat_id')[$purest->id]->rate_per_g ?? '0') : '0';

        $orders = Order::with(['retailer:id,shop_name', 'dispatches:id,order_id', 'lines.workOrders:id'])
            ->whereHas('dispatches', fn ($q) => $q->whereBetween('date', [$from, $to]))->get();

        $rows = $orders->map(function (Order $o) use ($rate) {
            $invoiceIds = Voucher::where('type', Voucher::SALES)->where('status', 'posted')->where('source_type', 'dispatch')->whereIn('source_id', $o->dispatches->pluck('id'))->pluck('id');
            $lines = InvoiceLine::whereIn('voucher_id', $invoiceIds)->get();
            $revenue = Money::add('0', ...$lines->pluck('taxable')->map(fn ($v) => (string) $v)->all());
            $fine = Weight::add('0', ...$lines->pluck('fine_wt')->map(fn ($v) => (string) $v)->all());
            $metal = Money::mul($fine, $rate);

            $woIds = $o->lines->flatMap->workOrders->pluck('id')->unique();
            $cardIds = JobCard::whereIn('wo_step_id', WoStep::whereIn('work_order_id', $woIds)->select('id'))->pluck('id');
            $labour = Money::round((string) Voucher::where('type', Voucher::LABOUR)->where('status', 'posted')->where('source_type', 'job_card')->whereIn('source_id', $cardIds)->sum('amount'));
            $profit = Money::sub(Money::sub($revenue, $metal), $labour);

            return [
                'order' => $o->order_no, 'retailer' => $o->retailer->shop_name, 'fine' => $fine, 'revenue' => $revenue, 'metal' => $metal,
                'labour' => $labour, 'profit' => $profit,
                'margin' => Money::isZero($revenue) ? '' : number_format((float) $profit * 100 / (float) $revenue, 1),
            ];
        })->filter(fn ($r) => ! Money::isZero($r['revenue']))->values();

        return ['columns' => [
            ['key' => 'order', 'label' => 'Order'], ['key' => 'retailer', 'label' => 'Retailer'], ['key' => 'fine', 'label' => 'Fine g', 'num' => true],
            ['key' => 'revenue', 'label' => 'Taxable sales ₹', 'num' => true], ['key' => 'metal', 'label' => "Gold cost ₹ (@{$rate}/g)", 'num' => true],
            ['key' => 'labour', 'label' => 'Labour ₹', 'num' => true],
            ['key' => 'profit', 'label' => 'Est. profit ₹', 'num' => true], ['key' => 'margin', 'label' => 'Margin %', 'num' => true],
        ], 'rows' => $rows, 'totals' => ['order' => 'Total', 'revenue' => Money::add('0', ...$rows->pluck('revenue')->all()), 'profit' => Money::add('0', ...$rows->pluck('profit')->all())]];
    }
}
