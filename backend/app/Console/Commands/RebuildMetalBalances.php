<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Metal\Ledger;
use App\Support\Metal\Weight;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly: rebuild location_balances from the ledger lines for every tenant, and
 * report any cached figure that disagreed with the lines (should never happen) and
 * any tenant whose ledger does not sum to zero (would mean a broken posting).
 */
class RebuildMetalBalances extends Command
{
    protected $signature = 'metal:rebuild-balances';

    protected $description = 'Rebuild cached gold balances from ledger lines and check them';

    public function handle(TenantContext $context, Ledger $ledger): int
    {
        $problems = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($context, $ledger, &$problems) {
            $context->runAs($tenant, function () use ($tenant, $ledger, &$problems) {
                $rows = $ledger->balanceRows();
                $cached = DB::table('location_balances')->where('tenant_id', $tenant->id)->get()
                    ->keyBy(fn ($r) => $r->location_id.':'.$r->karat_id);

                $total = '0.000';
                foreach ($rows as $r) {
                    $total = Weight::add($total, $r['fine']);
                    $old = $cached[$r['location_id'].':'.$r['karat_id']] ?? null;
                    if ($old && Weight::compare(Weight::fromDb($old->fine_wt), $r['fine']) !== 0) {
                        Log::warning('Cached gold balance differed from ledger', ['tenant' => $tenant->id] + $r + ['cached' => $old->fine_wt]);
                    }
                }

                if (Weight::compare($total, '0') !== 0) {
                    $problems++;
                    Log::critical('Gold ledger does not balance', ['tenant' => $tenant->id, 'sum' => $total]);
                    $this->error("{$tenant->subdomain}: ledger sums to {$total} g, expected 0");
                }

                DB::transaction(function () use ($tenant, $rows) {
                    DB::table('location_balances')->where('tenant_id', $tenant->id)->delete();
                    $now = now();
                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('location_balances')->insert(array_map(fn ($r) => [
                            'tenant_id' => $tenant->id, 'location_id' => $r['location_id'], 'karat_id' => $r['karat_id'],
                            'fine_wt' => $r['fine'], 'gross_wt' => $r['gross'], 'as_of' => $now,
                        ], $chunk));
                    }
                });
            });
        });

        $this->info($problems ? "{$problems} tenant(s) need attention." : 'All ledgers balance.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
