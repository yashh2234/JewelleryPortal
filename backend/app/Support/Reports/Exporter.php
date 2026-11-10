<?php

namespace App\Support\Reports;

use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One table → JSON (for the screen), Excel, PDF or CSV (spec §12: downloadable,
 * Excel and PDF). Columns: [['key' => 'qty', 'label' => 'Qty', 'num' => true], …].
 * Weights and money arrive as decimal strings and are written as numbers in Excel.
 */
class Exporter
{
    /**
     * @param  list<array{key: string, label: string, num?: bool}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function respond(string $format, string $title, array $columns, iterable $rows, array $totals = [], ?string $subtitle = null): Response
    {
        $rows = collect($rows)->values()->all();
        $file = Str::slug($title).'-'.now()->format('Ymd');

        return match ($format) {
            'xlsx' => self::xlsx($file, $title, $columns, $rows, $totals),
            'pdf' => Pdf::loadView('pdf.report', [
                'tenant' => app(TenantContext::class)->get(), 'title' => $title, 'subtitle' => $subtitle,
                'columns' => $columns, 'rows' => $rows, 'totals' => $totals,
            ])->setPaper('a4', count($columns) > 7 ? 'landscape' : 'portrait')->download($file.'.pdf'),
            'csv' => self::csv($file, $columns, $rows, $totals),
            default => response()->json(['title' => $title, 'subtitle' => $subtitle, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals]),
        };
    }

    private static function xlsx(string $file, string $title, array $columns, array $rows, array $totals): StreamedResponse
    {
        return response()->streamDownload(function () use ($title, $columns, $rows, $totals) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\/?*\[\]:]/', ' ', $title), 0, 31));
            $bold = (new Style)->setFontBold();

            $writer->addRow(Row::fromValues(array_column($columns, 'label'), $bold));
            foreach ($rows as $r) {
                $writer->addRow(Row::fromValues(self::cells($columns, $r)));
            }
            if ($totals) {
                $writer->addRow(Row::fromValues(self::cells($columns, $totals), $bold));
            }
            $writer->close();
        }, $file.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private static function csv(string $file, array $columns, array $rows, array $totals): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // Excel reads UTF-8 (₹, Hindi) correctly with a BOM
            fputcsv($out, array_column($columns, 'label'));
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($c) => $r[$c['key']] ?? '', $columns));
            }
            if ($totals) {
                fputcsv($out, array_map(fn ($c) => $totals[$c['key']] ?? '', $columns));
            }
            fclose($out);
        }, $file.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Numeric columns become real numbers so Excel can sum them. */
    private static function cells(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $v = $row[$c['key']] ?? null;
            if (($c['num'] ?? false) && is_string($v) && is_numeric($v)) {
                return str_contains($v, '.') ? (float) $v : (int) $v;
            }

            return is_scalar($v) || $v === null ? $v : json_encode($v);
        }, $columns);
    }
}
