<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\JobCard;
use App\Services\Metal\MetalOperations;
use App\Support\Metal\Weight;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobCardController extends Controller
{
    public function __construct(private MetalOperations $ops) {}

    public function index(Request $request): JsonResponse
    {
        $cards = JobCard::query()
            ->with(['kaarigar:id,name', 'process:id,name', 'karat:id,label,purity_pct', 'design:id,code,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('kaarigar_id'), fn ($q) => $q->where('kaarigar_id', $request->integer('kaarigar_id')))
            ->when($request->boolean('alerts'), fn ($q) => $q->where('excess_loss_fine', '>', 0)->whereNull('excess_resolution'))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', 'open')->where('due_date', '<', today()->toDateString()))
            ->when($request->filled('search'), fn ($q) => $q->where('card_no', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        $cards->getCollection()->transform(fn (JobCard $c) => MetalController::presentCard(
            $c, $c->isOpen() ? $this->live($c) : null,
        ));

        return response()->json($cards);
    }

    public function show(JobCard $jobCard): JsonResponse
    {
        $jobCard->load(['kaarigar', 'process', 'karat', 'design', 'transactions.lines.from', 'transactions.lines.to', 'transactions.lines.karat']);

        return response()->json([
            'data' => MetalController::presentCard($jobCard, $this->live($jobCard)),
            'transactions' => $jobCard->transactions->sortBy('id')->values()->map(fn ($tx) => MetalController::present($tx)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kaarigar_id' => ['required', TenantRules::exists('kaarigars')],
            'process_id' => ['required', TenantRules::exists('processes')],
            'karat_id' => ['required', TenantRules::exists('karats')],
            'design_id' => ['nullable', TenantRules::exists('designs')],
            'description' => ['nullable', 'string', 'max:255'],
            'pieces' => ['nullable', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
        ]);

        $card = $this->ops->openJobCard($data);

        return response()->json(['data' => MetalController::presentCard($card->load(['kaarigar', 'process', 'karat', 'design']))], 201);
    }

    public function close(JobCard $jobCard): JsonResponse
    {
        $card = $this->ops->closeJobCard($jobCard)->load(['kaarigar', 'process', 'karat', 'design']);

        return response()->json([
            'data' => MetalController::presentCard($card),
            'alert' => Weight::isPositive($card->excess_loss_fine)
                ? "Loss {$card->loss_fine} g is above the allowed {$card->allowed_wastage_pct}%. {$card->excess_loss_fine} g stays on {$card->kaarigar->name}'s balance."
                : null,
        ]);
    }

    public function writeOffExcess(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $tx = $this->ops->writeOffExcess($jobCard, $data['reason']);

        return response()->json(['data' => MetalController::present($tx->load(['lines.from', 'lines.to', 'lines.karat']))], 201);
    }

    /** @return array{issued: string, returned: string, scrap: string, outstanding: string} */
    private function live(JobCard $card): array
    {
        $f = $this->ops->cardFigures($card);

        return $f + ['outstanding' => Weight::sub($f['issued'], Weight::add($f['returned'], $f['scrap']))];
    }
}
