<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Reports\Analytics;
use App\Support\Reports\Exporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Production, gold and business reports (spec §12) on screen, in Excel, PDF or CSV. */
class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = [
            'Production' => ['production-summary', 'production-by-kaarigar', 'delayed-work-orders'],
            'Gold' => ['gold-summary', 'gold-stock', 'gold-with-kaarigars', 'gold-reconciliation'],
            'Business' => ['sales-by-retailer', 'sales-by-category', 'monthly-revenue', 'outstanding', 'retailer-performance', 'order-profitability'],
        ];

        return response()->json(['data' => collect($groups)->map(fn ($names, $group) => [
            'group' => $group,
            'reports' => collect($names)->map(fn ($n) => ['name' => $n, 'title' => Analytics::REPORTS[$n]])->values(),
        ])->values()]);
    }

    public function show(Request $request, string $name, Analytics $analytics): Response
    {
        $d = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'xlsx', 'pdf', 'csv'])],
        ]);

        $t = today();
        $from = $d['from'] ?? (($t->month >= 4 ? $t->year : $t->year - 1).'-04-01');   // financial year start
        $to = $d['to'] ?? $t->toDateString();
        $r = $analytics->report($name, $from, $to);

        return Exporter::respond($d['format'] ?? 'json', $r['title'], $r['columns'], $r['rows'], $r['totals'] ?? [], $r['subtitle'] ?? null);
    }
}
