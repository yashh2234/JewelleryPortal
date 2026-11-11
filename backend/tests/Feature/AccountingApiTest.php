<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\GoldRate;
use App\Models\Kaarigar;
use App\Models\Karat;
use App\Models\Retailer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Metal\Ledger;
use App\Services\Metal\MetalOperations;
use App\Services\Production\Dispatching;
use App\Services\Production\Production;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');
        $this->tenant->update(['state_code' => '27', 'gstin' => '27AAACA1234A1Z5']);
        $this->owner = $this->owner($this->tenant);
    }

    private function api(string $p): string
    {
        return $this->on($this->tenant, "api/v1/{$p}");
    }

    private function as(?User $u = null): static
    {
        return $this->actingAs($u ?? $this->owner, 'staff');
    }

    public function test_invoice_receipt_reports_and_exports(): void
    {
        [$dispatch, $retailer] = $this->asTenant($this->tenant->fresh(), function () {
            $k22 = Karat::where('label', '22K')->first();
            GoldRate::create(['date' => today()->toDateString(), 'karat_id' => $k22->id, 'rate_per_g' => '7200']);
            $retailer = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active', 'gstin' => '27ABCDE1234F1Z5', 'state_code' => '27']);
            app(MetalOperations::class)->purchase([['karat_id' => $k22->id, 'gross' => '50']], []);
            $prod = app(Production::class);
            $ledger = app(Ledger::class);
            $wo = $prod->newWorkOrder(Design::create(['code' => 'C1', 'name' => 'Chain', 'making_charge' => '400', 'making_type' => 'per_gram']), $k22->id, 1);
            $ledger->post('transfer', ['work_order_id' => $wo->id], [['from' => $ledger->vault(), 'to' => $ledger->workOrder($wo), 'karat' => $k22, 'gross' => '10']]);
            [$item] = $prod->finish($wo, [['gross' => '10']]);
            $item->forceFill(['qc_status' => 'passed'])->save();

            return [app(Dispatching::class)->dispatch($retailer, [$item->id], null), $retailer];
        });

        $this->as()->getJson($this->api("dispatches/{$dispatch->id}/invoice-preview"))
            ->assertOk()->assertJsonPath('totals.metal_value', '72000.00')->assertJsonPath('totals.making', '4000.00');

        $inv = $this->as()->postJson($this->api("invoices/from-dispatch/{$dispatch->id}"))->assertCreated()->json('data');
        $this->assertSame('78280.00', $inv['amount']);   // 76000 + 3 %

        $this->as()->get($this->api("invoices/{$inv['id']}/pdf"))->assertOk()->assertHeader('content-type', 'application/pdf');
        // Auto-posted vouchers are reversed at their source, not here.
        $this->as()->postJson($this->api("vouchers/{$inv['id']}/cancel"), ['reason' => 'x'])->assertUnprocessable();

        $partyId = $this->as()->getJson($this->api('parties?type=retailer'))->assertOk()->json('data.0.id');
        $this->as()->postJson($this->api('vouchers/receipt'), ['party_id' => $partyId, 'amount' => '50000', 'mode' => 'upi'])->assertCreated();

        $this->as()->getJson($this->api("parties/{$partyId}/ledger"))->assertOk()->assertJsonPath('closing.money', '28280.00');
        $this->as()->getJson($this->api('accounting-reports/trial-balance'))->assertOk()->assertJsonPath('balanced', true);
        $this->as()->getJson($this->api('accounting-reports/balance-sheet'))->assertOk()->assertJsonPath('balanced', true);
        $this->as()->getJson($this->api('accounting-reports/pnl'))->assertOk()->assertJsonPath('total_income', '76000.00');
        $this->as()->getJson($this->api('accounting-reports/outstanding?type=retailer'))->assertOk()->assertJsonPath('rows.0.ageing.0_30', '28280.00');
        $this->as()->getJson($this->api('accounting-reports/cash-book?account=bank'))->assertOk()->assertJsonPath('closing', '50000.00');
        $this->as()->getJson($this->api('accounting-reports/gstr1'))->assertOk()->assertJsonPath('b2b.0.gstin', '27ABCDE1234F1Z5')->assertJsonPath('hsn.0.taxable', '76000.00');
        $this->as()->getJson($this->api('accounting-reports/gstr3b'))->assertOk()->assertJsonPath('outward_tax.cgst', '1140.00');
        $this->as()->getJson($this->api('accounting-reports/stock-valuation'))->assertOk();

        // Tally and e-invoice exports are not part of the workflow blueprint.
        $this->as()->get($this->api('accounting-reports/tally'))->assertNotFound();
        $this->as()->getJson($this->api("invoices/{$inv['id']}/einvoice.json"))->assertNotFound();
    }

    public function test_kaarigar_payment_seen_in_app(): void
    {
        [$partyId, $appUser] = $this->asTenant($this->tenant, function () {
            $k = Kaarigar::create(['name' => 'Ramesh']);

            return [app(Books::class)->party($k)->id, $k->appUsers()->create(['name' => 'Ramesh', 'phone' => '9876500001'])];
        });

        // Cash advances requested from the app are gone; the store pays the kaarigar directly.
        $this->actingAs($appUser, 'kaarigar')->postJson($this->on($this->tenant, 'api/kaarigar/v1/advances'), ['amount' => '2000'])->assertNotFound();
        $this->as()->postJson($this->api('vouchers/payment'), ['party_id' => $partyId, 'amount' => '2000', 'mode' => 'cash'])->assertCreated();

        $this->actingAs($appUser, 'kaarigar')->getJson($this->on($this->tenant, 'api/kaarigar/v1/earnings'))
            ->assertOk()->assertJsonPath('balance_due', '-2000.00')->assertJsonPath('rows.0.paid', '2000.00');
    }

    public function test_only_accounts_can_post_vouchers(): void
    {
        $sales = $this->staffWithRole($this->tenant, 'sales');
        $accounts = $this->staffWithRole($this->tenant, 'accounts');

        $partyId = $this->as($accounts)->postJson($this->api('parties'), ['type' => 'supplier', 'name' => 'Bullion Co'])->assertCreated()->json('data.id');
        $this->as($sales)->postJson($this->api('vouchers/payment'), ['party_id' => $partyId, 'amount' => '10', 'mode' => 'cash'])->assertForbidden();
        $this->as($accounts)->postJson($this->api('vouchers/purchase'), ['party_id' => $partyId, 'terms' => 'metal', 'fine' => '100', 'taxable' => '0'])
            ->assertCreated()->assertJsonPath('data.fine_wt', '100.000');
    }
}
