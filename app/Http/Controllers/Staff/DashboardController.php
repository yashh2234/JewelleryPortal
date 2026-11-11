<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Reports\Analytics;
use App\Support\Permissions as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner dashboard (spec §3): KPIs and charts; each section only for those who may see it. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Analytics $analytics): JsonResponse
    {
        $u = $request->user('staff');
        $any = fn (string ...$p) => collect($p)->contains(fn ($x) => $u->can($x));

        $k = $analytics->kpis();
        $charts = $analytics->charts();

        $seeOrders = $any(P::ORDERS_MANAGE, P::ORDERS_APPROVE, P::PRODUCTION_MANAGE, P::REPORTS_VIEW, P::DISPATCH_MANAGE, P::QC_MANAGE);
        $seeGold = $any(P::KAARIGARS_VIEW, P::METAL_ISSUE, P::REPORTS_VIEW);
        $seeMoney = $any(P::ACCOUNTS_MANAGE, P::REPORTS_VIEW);

        return response()->json([
            'orders' => $seeOrders ? $k['orders'] : null,
            'gold' => $seeGold ? $k['gold'] : null,
            'finished' => $seeOrders || $seeGold ? $k['finished'] : null,
            'money' => $seeMoney ? $k['money'] : null,
            'efficiency' => $seeOrders || $seeGold ? $k['efficiency'] : null,
            'charts' => [
                'months' => collect($charts['months'])->map(fn ($m) => $seeMoney ? $m : array_merge($m, ['sales' => null])),
                'gold_by_location' => $seeGold ? $charts['gold_by_location'] : [],
                'orders_by_status' => $seeOrders ? $charts['orders_by_status'] : [],
            ],
        ]);
    }
}
