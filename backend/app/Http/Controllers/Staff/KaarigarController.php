<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Kaarigar;
use App\Models\KaarigarUser;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KaarigarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $kaarigars = Kaarigar::query()
            ->withCount('appUsers')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($kaarigars);
    }

    public function show(Kaarigar $kaarigar): JsonResponse
    {
        return response()->json(['data' => $kaarigar->load('appUsers'), 'profile' => $this->profile($kaarigar)]);
    }

    /**
     * Spec §6D profile: gold held, issued and returned to date, production done and
     * pending, labour and payments, and performance over the last 90 days.
     */
    private function profile(Kaarigar $kaarigar): array
    {
        $ledger = app(\App\Services\Metal\Ledger::class);
        $cards = \App\Models\JobCard::where('kaarigar_id', $kaarigar->id);
        $closed = (clone $cards)->where('status', 'closed');
        $recent = (clone $closed)->where('closed_at', '>=', now()->subDays(90))->get();
        $issued = \App\Support\Metal\Weight::fromDb((string) (clone $closed)->sum('issued_fine'));
        $loss = \App\Support\Metal\Weight::fromDb((string) (clone $closed)->sum('loss_fine'));
        $party = app(\App\Services\Accounting\Books::class)->party($kaarigar);
        $vouchers = \App\Models\Voucher::where('party_id', $party->id)->where('status', 'posted');

        $woIds = \App\Models\WoStep::whereIn('id', (clone $cards)->whereNotNull('wo_step_id')->select('wo_step_id'))->pluck('work_order_id')->unique();

        return [
            'gold_held' => $ledger->balance($ledger->kaarigar($kaarigar)),
            'limit' => $kaarigar->max_fine_balance,
            'issue_blockers' => app(\App\Services\Metal\IssueRules::class)->blockers($kaarigar),
            'totals' => [
                'issued_fine' => $issued,
                'returned_fine' => \App\Support\Metal\Weight::fromDb((string) (clone $closed)->selectRaw('sum(returned_fine + scrap_fine) as t')->value('t')),
                'loss_fine' => $loss,
                'wastage_pct' => \App\Support\Metal\Weight::isPositive($issued) ? round((float) $loss * 100 / (float) $issued, 3) : null,
                'unresolved_excess' => \App\Support\Metal\Weight::fromDb((string) (clone $closed)->whereNull('excess_resolution')->sum('excess_loss_fine')),
            ],
            'production' => [
                'cards_closed' => (clone $closed)->count(),
                'pieces_done' => (int) (clone $closed)->sum('pieces'),
                'cards_open' => (clone $cards)->where('status', 'open')->count(),
                'pieces_pending' => (int) (clone $cards)->where('status', 'open')->sum('pieces'),
                'overdue' => (clone $cards)->where('status', 'open')->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->count(),
            ],
            'performance_90d' => [
                'cards' => $recent->count(),
                'avg_days' => $recent->count() ? round($recent->avg(fn ($c) => $c->created_at->diffInHours($c->closed_at) / 24), 1) : null,
                'on_time_pct' => ($d = $recent->filter(fn ($c) => $c->due_date))->count()
                    ? round($d->filter(fn ($c) => $c->closed_at->toDateString() <= $c->due_date->toDateString())->count() * 100 / $d->count(), 1) : null,
                'over_limit' => $recent->filter(fn ($c) => (float) $c->excess_loss_fine > 0)->count(),
            ],
            'work_orders' => \App\Models\WorkOrder::with('design:id,code,name')->whereIn('id', $woIds)->latest('id')->limit(30)
                ->get(['id', 'wo_no', 'design_id', 'qty', 'status', 'due_date']),
            'money' => [
                'labour_total' => \App\Support\Money::round((string) (clone $vouchers)->where('type', \App\Models\Voucher::LABOUR)->sum('amount')),
                'paid_total' => \App\Support\Money::round((string) (clone $vouchers)->where('type', \App\Models\Voucher::PAYMENT)->sum('amount')),
                'balance' => app(\App\Services\Accounting\Books::class)->partyBalance($party)['money'],
                'recent_payments' => (clone $vouchers)->where('type', \App\Models\Voucher::PAYMENT)->latest('date')->limit(10)->get(['id', 'voucher_no', 'date', 'amount', 'narration']),
            ],
        ];
    }

    /** Creates the kaarigar and, if app_access is on, a phone login for the kaarigar app. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'type' => ['required', Rule::in([Kaarigar::TYPE_IN_HOUSE, Kaarigar::TYPE_OUTSIDE])],
            'address' => ['nullable', 'string', 'max:500'],
            'app_access' => ['boolean'],
            'language' => ['nullable', Rule::in(['hi', 'en', 'bn', 'gu'])],
            'max_fine_balance' => ['nullable', 'decimal:0,3', 'min:0'],
        ]);

        if (($data['app_access'] ?? false)) {
            $request->validate([
                'phone' => ['required', TenantRules::unique('kaarigar_users', 'phone')],
            ]);
        }

        $kaarigar = DB::transaction(function () use ($data) {
            $kaarigar = Kaarigar::create(Arr::only($data, ['name', 'phone', 'type', 'address']));

            if ($data['app_access'] ?? false) {
                $kaarigar->appUsers()->create([
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'is_master' => true,
                    'language' => $data['language'] ?? 'hi',
                ]);
            }

            return $kaarigar;
        });

        return response()->json(['data' => $kaarigar->load('appUsers')], 201);
    }

    public function update(Request $request, Kaarigar $kaarigar): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'type' => ['sometimes', Rule::in([Kaarigar::TYPE_IN_HOUSE, Kaarigar::TYPE_OUTSIDE])],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'max_fine_balance' => ['nullable', 'decimal:0,3', 'min:0'],
        ]);

        DB::transaction(function () use ($kaarigar, $data) {
            $kaarigar->update($data);

            // Deactivating a kaarigar also blocks app login.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $kaarigar->appUsers()->get()->each->update(['is_active' => false]);
            }
        });

        return response()->json(['data' => $kaarigar->load('appUsers')]);
    }

    /** Add a phone login (e.g. a worker under an outside karkhana's master login). */
    public function addAppUser(Request $request, Kaarigar $kaarigar): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/', TenantRules::unique('kaarigar_users', 'phone')],
            'is_master' => ['boolean'],
            'language' => ['nullable', Rule::in(['hi', 'en', 'bn', 'gu'])],
        ]);

        $user = $kaarigar->appUsers()->create(['language' => $data['language'] ?? 'hi'] + $data);

        return response()->json(['data' => $user], 201);
    }

    public function updateAppUser(Request $request, Kaarigar $kaarigar, KaarigarUser $appUser): JsonResponse
    {
        abort_unless($appUser->kaarigar_id === $kaarigar->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'regex:/^[6-9]\d{9}$/', TenantRules::unique('kaarigar_users', 'phone')->ignore($appUser->id)],
            'language' => ['sometimes', Rule::in(['hi', 'en', 'bn', 'gu'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $appUser->update($data);

        return response()->json(['data' => $appUser]);
    }
}
