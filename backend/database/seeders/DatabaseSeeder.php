<?php

namespace Database\Seeders;

use App\Models\GoldRate;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\PlatformAdmin;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Local demo data. Model events must run (they fill tenant_id), so this seeder
 * deliberately does not use WithoutModelEvents.
 *
 *   Staff app   acme  owner@acme.test / password   (also metal@acme.test)
 *   Portal            shop@sharma.test / password
 *   Super-admin       /app/admin  admin@platform.test / password
 *   Kaarigar app      9876500001 (Ramesh), 9876500002 (Imran), 9876500003 (Suresh); OTP in the log
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        PlatformAdmin::firstOrCreate(['email' => 'admin@platform.test'], ['name' => 'Platform Admin', 'password' => 'password']);

        if (Tenant::where('subdomain', 'acme')->exists()) {
            return;
        }

        $tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Acme Gold Manufacturers',
            'subdomain' => 'acme',
            'gstin' => '27AAACA1234A1Z5',
            'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@acme.test',
            'owner_password' => 'password',
        ]);

        app(TenantContext::class)->runAs($tenant, function () {
            User::create(['name' => 'Metal Manager', 'email' => 'metal@acme.test', 'password' => 'password'])
                ->assignRole('metal_manager');

            foreach ([['Ramesh', '9876500001'], ['Imran', '9876500002'], ['Suresh', '9876500003']] as [$name, $phone]) {
                Kaarigar::create(['name' => $name, 'phone' => $phone, 'type' => Kaarigar::TYPE_IN_HOUSE])
                    ->appUsers()->create(['name' => $name, 'phone' => $phone, 'language' => 'hi']);
            }

            $retailer = Retailer::create([
                'shop_name' => 'Sharma Jewellers', 'contact_name' => 'R. Sharma', 'city' => 'Pune',
                'phone' => '9822000000', 'status' => Retailer::STATUS_ACTIVE, 'metal_account_enabled' => true,
            ]);
            $retailer->users()->create(['name' => 'R. Sharma', 'email' => 'shop@sharma.test', 'password' => 'password']);

            Retailer::create(['shop_name' => 'Verma Gold House', 'city' => 'Nagpur', 'status' => Retailer::STATUS_PENDING]);

            // Gold ledger demo: opening stock, one closed job card, one still on the bench.
            $ops = app(\App\Services\Metal\MetalOperations::class);
            $k22 = Karat::where('label', '22K')->first();
            $making = \App\Models\Process::where('name', 'Making')->first();
            $ops->purchase([['karat_id' => $k22->id, 'gross' => '1000.000']], ['party_name' => 'Opening stock'], opening: true);
            // Labour is billed automatically when a job card closes.
            \App\Models\LabourRate::create(['process_id' => $making->id, 'basis' => 'per_gram', 'rate' => '150']);

            [$ramesh, , $suresh] = Kaarigar::orderBy('id')->get()->all();
            $card = $ops->openJobCard(['kaarigar_id' => $ramesh->id, 'process_id' => $making->id, 'karat_id' => $k22->id, 'description' => '50 Classic kade']);
            $ops->issue($card, [['karat_id' => $k22->id, 'gross' => '100.000']], 'vault', []);
            $ops->receive($card, ['gross' => '98.400', 'pieces' => 50], ['gross' => '0.200'], 'finished', []);
            $ops->closeJobCard($card);

            $card = $ops->openJobCard(['kaarigar_id' => $suresh->id, 'process_id' => $making->id, 'karat_id' => $k22->id, 'description' => '20 Temple bangles', 'due_date' => today()->addDays(3)->toDateString()]);
            $ops->issue($card, [['karat_id' => $k22->id, 'gross' => '60.000']], 'vault', []);

            // Production demo: an AHC and a confirmed order with its work order on the board.
            \App\Models\Ahc::create(['name' => 'Pune Hallmarking Centre', 'city' => 'Pune', 'charge_per_piece' => '45']);
            $kada = \App\Models\Design::create([
                'code' => 'KD-101', 'name' => 'Classic kada', 'is_published' => true,
                'category_id' => \App\Models\Category::where('name', 'Kada')->value('id'),
                'approx_weight_min' => '18', 'approx_weight_max' => '22', 'sizes' => ['2.4', '2.6', '2.8'],
                'making_charge' => '450', 'making_type' => 'per_gram',
            ]);
            $kada->karats()->sync([$k22->id]);
            $production = app(\App\Services\Production\Production::class);
            $order = $production->createOrder($retailer, [
                ['design_id' => $kada->id, 'karat_id' => $k22->id, 'size' => '2.4', 'qty' => 10, 'target_wt_min' => '18', 'target_wt_max' => '22'],
                ['design_id' => $kada->id, 'karat_id' => $k22->id, 'size' => '2.6', 'qty' => 15, 'target_wt_min' => '18', 'target_wt_max' => '22'],
            ], ['due_date' => today()->addDays(12)->toDateString()]);
            $production->confirm($order);
            $production->createWorkOrders($order, [$order->lines->pluck('id')->all()]);

            foreach (['24K' => '7850.00', '22K' => '7200.00', '18K' => '5890.00'] as $label => $rate) {
                GoldRate::create([
                    'date' => today()->toDateString(),
                    'karat_id' => Karat::where('label', $label)->value('id'),
                    'rate_per_g' => $rate,
                ]);
            }
        });
    }
}
