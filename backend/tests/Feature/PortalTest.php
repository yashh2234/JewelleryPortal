<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Design;
use App\Models\Dispatch;
use App\Models\GoldRate;
use App\Models\Karat;
use App\Models\Retailer;
use App\Models\RetailerUser;
use App\Models\Tenant;
use App\Notifications\OrderStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Retailer $retailer;

    private RetailerUser $shop;

    private int $k22;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('acme');

        [$this->retailer, $this->shop, $this->k22] = $this->asTenant($this->tenant, function () {
            $k22 = Karat::where('label', '22K')->value('id');
            GoldRate::create(['date' => today()->toDateString(), 'karat_id' => $k22, 'rate_per_g' => '7200']);

            $temple = Collection::create(['name' => 'Temple', 'slug' => 'temple']);
            $bridal = Collection::create(['name' => 'Bridal', 'slug' => 'bridal']);
            foreach ([['KD-1', 'Temple kada', $temple], ['NK-1', 'Bridal necklace', $bridal]] as [$code, $name, $col]) {
                $d = Design::create(['code' => $code, 'name' => $name, 'is_published' => true, 'approx_weight_min' => '18', 'approx_weight_max' => '22',
                    'making_charge' => '500', 'making_type' => 'per_gram', 'sizes' => ['2.4', '2.6']]);
                $d->karats()->sync([$k22]);
                $d->collections()->sync([$col->id]);
            }
            Design::create(['code' => 'DRAFT', 'name' => 'Not published']);

            $r = Retailer::create(['shop_name' => 'Sharma', 'status' => 'active']);

            return [$r, $r->users()->create(['name' => 'R', 'email' => 'shop@sharma.test', 'password' => 'password']), $k22];
        });
    }

    private function portal(string $p): string
    {
        return $this->on($this->tenant, "api/portal/v1/{$p}");
    }

    private function as(): static
    {
        return $this->actingAs($this->shop, 'retailer');
    }

    public function test_catalogue_respects_published_and_collections_with_estimates(): void
    {
        $this->as()->getJson($this->portal('designs'))->assertOk()->assertJsonPath('total', 2);

        // Restrict this retailer to the Temple collection.
        $temple = $this->asTenant($this->tenant, fn () => Collection::where('slug', 'temple')->value('id'));
        $this->actingAs($this->owner($this->tenant), 'staff')
            ->putJson($this->on($this->tenant, "api/v1/retailers/{$this->retailer->id}/collections"), ['collection_ids' => [$temple]])->assertOk();

        $this->as()->getJson($this->portal('designs'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'KD-1')
            // mid weight 20 g × 7200 + making 20 × 500
            ->assertJsonPath('data.0.karats.0.estimate.total', '154000.00');
        $this->as()->getJson($this->portal('designs/NK-1'))->assertNotFound();
        $this->as()->getJson($this->portal('designs/DRAFT'))->assertNotFound();
        $this->as()->getJson($this->portal('catalogue/filters'))->assertOk()->assertJsonCount(1, 'collections');
        $this->as()->getJson($this->portal('gold-rate'))->assertOk()->assertJsonPath('data.0.rate_per_g', '7200.00');
    }

    public function test_cart_order_track_reorder(): void
    {
        Notification::fake();
        $kd = $this->asTenant($this->tenant, fn () => Design::where('code', 'KD-1')->value('id'));

        $this->as()->postJson($this->portal('cart'), ['design_id' => $kd, 'karat_id' => $this->k22, 'size' => '2.4', 'qty' => 10])->assertCreated();
        $cart = $this->as()->postJson($this->portal('cart'), ['design_id' => $kd, 'karat_id' => $this->k22, 'size' => '2.4', 'qty' => 5])
            ->assertCreated()->json();
        $this->assertCount(1, $cart['data']);                 // merged into one line
        $this->assertSame(15, $cart['totals']['pieces']);
        $this->assertSame('274.800', $cart['totals']['est_fine']);   // 15 × 20 g × 91.6 %

        $order = $this->as()->postJson($this->portal('orders'), ['remarks' => 'Wedding season'])->assertCreated()->json('data');
        $this->as()->getJson($this->portal('cart'))->assertJsonPath('totals.pieces', 0);

        $this->as()->getJson($this->portal("orders/{$order['id']}"))->assertOk()
            ->assertJsonPath('data.stage', 'received')->assertJsonPath('data.lines.0.qty', 15);

        // Staff confirm → retailer is emailed and the timeline moves.
        $this->actingAs($this->owner($this->tenant), 'staff')->getJson($this->on($this->tenant, "api/v1/orders/{$order['id']}"))
            ->assertJsonPath('data.source', 'portal')->assertJsonPath('position.outstanding', '0.00');
        $this->actingAs($this->owner($this->tenant), 'staff')->postJson($this->on($this->tenant, "api/v1/orders/{$order['id']}/confirm"))->assertOk();
        Notification::assertSentOnDemand(OrderStatusChanged::class);
        $this->as()->getJson($this->portal("orders/{$order['id']}"))->assertJsonPath('data.stage', 'approved')
            ->assertJsonMissingPath('data.lines.0.work_orders');   // no internal production data for retailers

        $this->as()->postJson($this->portal("orders/{$order['id']}/reorder"))->assertOk();
        $this->as()->getJson($this->portal('cart'))->assertJsonPath('totals.pieces', 15);
    }

    public function test_account_and_documents_are_private_to_the_retailer(): void
    {
        $this->as()->getJson($this->portal('account'))->assertOk()->assertJsonPath('outstanding', '0.00')->assertJsonPath('metal_balance', '0.000');

        $other = $this->asTenant($this->tenant, function () {
            $r = Retailer::create(['shop_name' => 'Other', 'status' => 'active']);

            return Dispatch::create(['challan_no' => 'DC/X/1', 'retailer_id' => $r->id, 'date' => today()->toDateString(), 'pieces' => 0, 'gross_wt' => '0', 'net_wt' => '0', 'fine_wt' => '0']);
        });
        $this->as()->get($this->portal("challans/{$other->id}/pdf"))->assertNotFound();
    }

    public function test_only_the_shop_owner_adds_users(): void
    {
        $this->as()->postJson($this->portal('team'), ['name' => 'Counter', 'email' => 'counter@sharma.test', 'password' => 'secret123'])
            ->assertCreated()->assertJsonPath('data.role', 'staff');

        $staff = $this->asTenant($this->tenant, fn () => RetailerUser::where('email', 'counter@sharma.test')->first());
        $this->actingAs($staff, 'retailer')->postJson($this->portal('team'), ['name' => 'X', 'email' => 'x@sharma.test', 'password' => 'secret123'])->assertForbidden();
        $this->actingAs($staff, 'retailer')->getJson($this->portal('designs'))->assertOk();
    }

    public function test_bulk_design_import_and_photos(): void
    {
        Storage::fake('public');
        $owner = $this->owner($this->tenant);
        $csv = "code,name,category,karats,weight_min,weight_max,sizes,making_charge,making_type,collections,published\n"
            ."KD-1,Temple kada v2,Kada,22K,19,23,2.4|2.6|2.8,520,per_gram,Temple,yes\n"
            ."CH-9,Rope chain,Chain,22K|18K,10,14,,8,percent,New arrivals,yes\n"
            ."BAD,Broken,,21K,,,,,,,\n";

        $this->actingAs($owner, 'staff')->post($this->on($this->tenant, 'api/v1/designs/import'), [
            'file' => UploadedFile::fake()->createWithContent('designs.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('created', 1)->assertJsonPath('updated', 1)->assertJsonPath('errors.0', 'Line 4: unknown karat 21K.');

        $this->asTenant($this->tenant, function () {
            $ch = Design::where('code', 'CH-9')->first();
            $this->assertCount(2, $ch->karats);
            $this->assertSame('percent', $ch->making_type);
            $this->assertSame('Temple kada v2', Design::where('code', 'KD-1')->value('name'));
        });

        $this->actingAs($owner, 'staff')->post($this->on($this->tenant, 'api/v1/designs/images/bulk'), [
            'images' => [UploadedFile::fake()->image('CH-9.jpg'), UploadedFile::fake()->image('ch-9_2.jpg'), UploadedFile::fake()->image('nobody.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('matched', 2)->assertJsonPath('unmatched.0', 'nobody.jpg');
    }
}
