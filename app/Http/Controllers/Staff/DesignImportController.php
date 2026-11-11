<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Design;
use App\Models\Karat;
use App\Services\Media\ImageStore;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk design upload for the catalogue manager.
 *
 * CSV columns (header row required, extra columns ignored):
 *   code, name, category, karats (22K|18K), weight_min, weight_max, sizes (2.4|2.6),
 *   making_charge, making_type (per_gram|per_piece|percent), collections (Temple|Bridal), published (yes/no)
 * Existing codes are updated; new codes are created. Unknown categories/collections are created.
 *
 * Photos: upload many files named by design code: KD-101.jpg, KD-101_2.jpg, KD-101-3.webp.
 */
class DesignImportController extends Controller
{
    public function csv(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => Str::snake(strtolower(trim((string) $h))), fgetcsv($handle) ?: []);
        if (! in_array('code', $header, true) || ! in_array('name', $header, true)) {
            abort(422, 'The CSV needs at least "code" and "name" columns.');
        }

        $karats = Karat::pluck('id', 'label')->mapWithKeys(fn ($id, $label) => [strtoupper($label) => $id]);
        $created = $updated = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, $karats, &$created, &$updated, &$errors, &$line) {
            while (($cols = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $row = array_combine($header, array_pad(array_slice($cols, 0, count($header)), count($header), ''));
                $code = trim($row['code'] ?? '');
                $name = trim($row['name'] ?? '');

                if ($code === '' || $name === '') {
                    $errors[] = "Line {$line}: code and name are required.";
                    continue;
                }

                $list = fn (string $key) => array_values(array_filter(array_map('trim', preg_split('/[|;]/', (string) ($row[$key] ?? '')))));
                $num = fn (string $key) => is_numeric($row[$key] ?? null) ? (string) $row[$key] : null;

                $karatIds = [];
                foreach ($list('karats') as $label) {
                    if (! isset($karats[strtoupper($label)])) {
                        $errors[] = "Line {$line}: unknown karat {$label}.";
                        continue 2;
                    }
                    $karatIds[] = $karats[strtoupper($label)];
                }

                $makingType = strtolower(trim($row['making_type'] ?? ''));
                $attrs = array_filter([
                    'name' => $name,
                    'category_id' => ($cat = trim($row['category'] ?? '')) !== '' ? Category::firstOrCreate(['name' => $cat])->id : null,
                    'approx_weight_min' => $num('weight_min'),
                    'approx_weight_max' => $num('weight_max'),
                    'sizes' => $list('sizes') ?: null,
                    'making_charge' => $num('making_charge'),
                    'making_type' => in_array($makingType, ['per_gram', 'per_piece', 'percent'], true) ? $makingType : null,
                ], fn ($v) => $v !== null);
                if (isset($row['published']) && trim($row['published']) !== '') {
                    $attrs['is_published'] = in_array(strtolower(trim($row['published'])), ['1', 'yes', 'y', 'true'], true);
                }

                $design = Design::where('code', $code)->first();
                if ($design) {
                    $design->update($attrs);
                    $updated++;
                } else {
                    PlanLimits::ensureRoomFor('designs');
                    $design = Design::create(['code' => $code] + $attrs);
                    $created++;
                }

                if ($karatIds) {
                    $design->karats()->sync($karatIds);
                }
                if ($names = $list('collections')) {
                    $design->collections()->syncWithoutDetaching(collect($names)->map(
                        fn ($n) => Collection::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n])->id,
                    ));
                }
            }
        });
        fclose($handle);

        return response()->json(['created' => $created, 'updated' => $updated, 'errors' => $errors]);
    }

    /** Photos matched to designs by file name (code, optionally followed by _2, -3 …). */
    public function images(Request $request, ImageStore $images): JsonResponse
    {
        $request->validate(['images' => ['required', 'array', 'max:100'], 'images.*' => ['image', 'max:8192']]);

        $codes = Design::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id]);
        $matched = 0;
        $unmatched = [];

        foreach ($request->file('images') as $file) {
            $base = strtoupper(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
            $code = isset($codes[$base]) ? $base : preg_replace('/[_\-\s]\d+$/', '', $base);

            if (! isset($codes[$code])) {
                $unmatched[] = $file->getClientOriginalName();
                continue;
            }

            $design = Design::find($codes[$code]);
            $paths = $images->store($file, $design->tenant_id, "designs/{$design->id}");
            $design->images()->create($paths + ['sort' => (int) $design->images()->max('sort') + 1]);
            $matched++;
        }

        return response()->json(['matched' => $matched, 'unmatched' => $unmatched]);
    }
}
