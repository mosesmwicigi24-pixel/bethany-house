<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Receiving goods sets the item's latest cost from the PO price. Those costs
 * (materials.unit_cost, legacy inventories.cost_per_unit) are shillings, so a
 * foreign-currency PO converts at the REPORTING rate (128 KES/USD), never the
 * customer pricing rate (100), and never not at all. A currency with no
 * reporting rate leaves the cost as it was and says so. Expected figures are
 * worked out by hand: USD 10.00 × 128 = KES 1,280.00.
 */
class PurchaseReceiptCostCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $approver;
    private int $supplierId;
    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->owner    = $this->person('super_admin');
        $this->approver = $this->person('admin');
        $this->shop     = Outlet::factory()->create();
        $this->supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-FX', 'name' => 'Overseas Mills', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        // Pricing rate 0.01 (100 KES/USD) and reporting rate 128 differ on purpose.
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€',
            'exchange_rate' => 0.0085, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();

        Sanctum::actingAs($this->owner);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function material(float $cost = 900): Material
    {
        return Material::create([
            'code' => 'MAT-' . uniqid(), 'name' => 'Imported brocade', 'category' => 'fabric',
            'unit_of_measure' => 'm', 'unit_cost' => $cost, 'is_active' => true,
        ]);
    }

    private function line(string $currency, string $type, int $itemId, float $qty, float $price): PurchaseOrderItem
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-FX-' . uniqid(), 'supplier_id' => $this->supplierId, 'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(3)->toDateString(), 'status' => 'approved', 'currency_code' => $currency,
            'subtotal' => $qty * $price, 'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => $qty * $price,
            'created_by' => $this->approver->id, 'approved_by' => $this->approver->id,
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_type' => $type,
            $type === 'product' ? 'product_id' : 'material_id' => $itemId,
            'description' => "Line {$itemId}", 'quantity' => $qty, 'quantity_received' => 0,
            'unit_price' => $price, 'total_price' => $qty * $price,
        ]);
    }

    private function receive(PurchaseOrderItem $line, float $qty): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/receive", [
            'location_type' => 'outlet', 'outlet_id' => $this->shop->id,
            'items' => [['po_item_id' => $line->id, 'quantity_received' => $qty]],
        ])->assertOk();
    }

    private function onHand(Material $m): float
    {
        return (float) MaterialInventory::where(['material_id' => $m->id, 'outlet_id' => $this->shop->id])->value('quantity_on_hand');
    }

    public function test_a_usd_material_receipt_sets_the_cost_in_shillings_at_the_reporting_rate(): void
    {
        $m = $this->material(900);
        $this->receive($this->line('USD', 'material', $m->id, 5, 10.00), 5)->assertJsonPath('cost_notes', []);

        // 10.00 × 128 = 1,280.00 — not 10.00 (no conversion), not 1,000.00 (pricing rate).
        $this->assertSame('1280.00', (string) $m->fresh()->unit_cost);
        $this->assertSame(5.0, $this->onHand($m));
    }

    public function test_a_kes_material_receipt_keeps_the_price_as_is(): void
    {
        $m = $this->material(900);
        $this->receive($this->line('KES', 'material', $m->id, 3, 1150.50), 3);

        $this->assertSame('1150.50', (string) $m->fresh()->unit_cost);
    }

    public function test_a_usd_product_receipt_sets_the_legacy_cost_in_shillings(): void
    {
        $p = Product::factory()->create();
        DB::table('inventories')->insert([
            'inventory_type' => 'product', 'product_id' => $p->id, 'outlet_id' => $this->shop->id,
            'quantity_on_hand' => 2, 'cost_per_unit' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->receive($this->line('USD', 'product', $p->id, 4, 7.25), 4);

        // 7.25 × 128 = 928.00; the quantity mirror moves by the 4 received.
        $row = DB::table('inventories')->where('product_id', $p->id)->first();
        $this->assertSame(928.0, (float) $row->cost_per_unit);
        $this->assertSame(6.0, (float) $row->quantity_on_hand);
    }

    public function test_a_currency_with_no_reporting_rate_receives_the_goods_but_leaves_the_cost_and_says_so(): void
    {
        $m = $this->material(900);
        $res = $this->receive($this->line('EUR', 'material', $m->id, 2, 12.00), 2);

        $this->assertSame('900.00', (string) $m->fresh()->unit_cost, 'never a guessed conversion');
        $this->assertSame(2.0, $this->onHand($m), 'the goods still come in');
        $res->assertJsonCount(1, 'cost_notes');
        $this->assertStringContainsString('no reporting rate for EUR', $res->json('cost_notes.0'));
        $this->assertStringContainsString('no reporting rate for EUR', $res->json('message'));

        $log = DB::table('activity_log')->where('action', 'grn_created')->latest('id')->value('properties');
        $this->assertStringContainsString('no reporting rate for EUR', (string) $log);
    }

    public function test_a_free_material_line_leaves_the_cost_alone(): void
    {
        $m = $this->material(900);
        $this->receive($this->line('USD', 'material', $m->id, 1, 0), 1)->assertJsonPath('cost_notes', []);

        $this->assertSame('900.00', (string) $m->fresh()->unit_cost);
    }

    public function test_a_purchase_order_is_raised_only_in_a_currency_with_a_reporting_rate(): void
    {
        Sanctum::actingAs($this->person('procurement_manager'));
        $m = $this->material(900);
        $make = fn (string $currency) => $this->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id' => $this->supplierId, 'expected_delivery_date' => now()->addWeek()->toDateString(),
            'currency' => $currency,
            'items' => [['type' => 'material', 'item_id' => $m->id, 'quantity' => 2, 'unit_price' => 10]],
        ]);

        $make('EUR')->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->assertStringContainsString('no reporting rate for EUR', $make('EUR')->json('errors.currency.0'));
        $this->assertSame(0, PurchaseOrder::where('currency_code', 'EUR')->count());
        $make('USD')->assertSuccessful();
        $make('KES')->assertSuccessful();

        $list = collect($this->getJson('/api/v1/admin/purchase-orders/currencies')->assertOk()->json('data'))->keyBy('code');
        $this->assertTrue($list['KES']['allowed']);
        $this->assertTrue($list['USD']['allowed']);
        $this->assertEquals(128, $list['USD']['rate_to_kes']);
        $this->assertFalse($list['EUR']['allowed']);
        $this->assertFalse($list['GBP']['allowed']);
    }

    public function test_the_owners_correction_of_a_received_usd_line_uses_the_same_conversion(): void
    {
        $m    = $this->material(900);
        $line = $this->line('USD', 'material', $m->id, 5, 10.00);
        $this->receive($line, 5);
        $this->assertSame('1280.00', (string) $m->fresh()->unit_cost);

        $this->putJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/revise", [
            'reason' => 'Supplier invoice says 9.50 a metre',
            'items'  => [['id' => $line->id, 'type' => 'material', 'item_id' => $m->id, 'quantity' => 5, 'unit_price' => 9.50]],
        ])->assertOk();

        // 9.50 × 128 = 1,216.00
        $this->assertSame('1216.00', (string) $m->fresh()->unit_cost);
    }
}
