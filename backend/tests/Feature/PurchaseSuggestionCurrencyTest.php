<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
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
 * Purchasing suggestions state the last price paid in KES at the reporting
 * rate (128 KES/USD). Before, a USD 9.00 buy sat beside a KES 1,300 buy as if
 * both were shillings: the price-rise alert called it +14,344%, and a draft
 * PO (always KES) was pre-filled at KES 9. A currency with no reporting rate
 * (EUR here) is skipped, never compared at face value.
 *
 * Fixture, by hand: buys of one material —
 *   40 days ago  USD 9.00   → KES 1,152.00
 *   20 days ago  EUR 11.00  → no rate: skipped
 *    5 days ago  KES 1,300  → latest
 *   Drift: (1,300 − 1,152) / 1,152 = 12.85% → 12.8, above the 10% threshold.
 */
class PurchaseSuggestionCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->buyer = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $this->buyer->assignRole(Role::findByName('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-SUG', 'name' => 'Brocade House', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€',
            'exchange_rate' => 0.0085, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();
    }

    private function buy(Material $m, string $currency, float $price, int $daysAgo): void
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-SUG-' . uniqid(), 'supplier_id' => $this->supplierId,
            'order_date' => now()->subDays($daysAgo)->toDateString(), 'status' => 'received',
            'currency_code' => $currency, 'subtotal' => $price, 'total_amount' => $price,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_type' => 'material', 'material_id' => $m->id,
            'description' => $m->name, 'quantity' => 1, 'quantity_received' => 1, 'unit_price' => $price, 'total_price' => $price,
        ]);
    }

    private function material(): Material
    {
        // reorder_point 10 with nothing in stock, so it is suggested.
        return Material::create([
            'code' => 'MAT-' . uniqid(), 'name' => 'Gold brocade', 'category' => 'fabric', 'unit_of_measure' => 'm',
            'unit_cost' => 1300, 'reorder_point' => 10, 'is_active' => true,
        ]);
    }

    public function test_the_price_rise_alert_compares_in_shillings_and_skips_unrated_currencies(): void
    {
        $m = $this->material();
        $this->buy($m, 'USD', 9.00, 40);
        $this->buy($m, 'EUR', 11.00, 20);
        $this->buy($m, 'KES', 1300, 5);

        $drift = collect(MetricEngine::for($this->buyer)->supplierPriceDrift())->firstWhere('material_id', $m->id);
        $this->assertNotNull($drift);
        $this->assertEquals(1300, (float) $drift->last_price);
        $this->assertEquals(1152, (float) $drift->avg_prior, 'USD 9 × 128; the EUR buy is left out');
        $this->assertEquals(12.8, (float) $drift->drift_pct);
    }

    public function test_a_steady_price_paid_in_dollars_is_not_a_price_change(): void
    {
        $m = $this->material();
        $this->buy($m, 'KES', 1280, 30);
        $this->buy($m, 'USD', 10.00, 3);   // 10 × 128 = 1,280: the same price

        $this->assertNull(collect(MetricEngine::for($this->buyer)->supplierPriceDrift())->firstWhere('material_id', $m->id));
    }

    public function test_suggestions_and_the_kes_draft_carry_the_last_price_in_shillings(): void
    {
        $m = $this->material();
        $this->buy($m, 'KES', 1200, 30);
        $this->buy($m, 'USD', 9.50, 10);   // 9.50 × 128 = 1,216.00
        $this->buy($m, 'EUR', 11.00, 2);   // newest, but no rate: skipped

        $row = collect(MetricEngine::for($this->buyer)->purchaseSuggestions())->firstWhere('material_id', $m->id);
        $this->assertEquals(1216.0, $row['last_price']);

        Sanctum::actingAs($this->buyer);
        $this->postJson('/api/v1/admin/purchase-orders/from-suggestions', ['material_ids' => [$m->id]])->assertSuccessful();
        $draft = PurchaseOrder::where('status', 'draft')->latest('id')->firstOrFail();
        $this->assertSame('KES', $draft->currency_code);
        $this->assertSame('1216.00', (string) PurchaseOrderItem::where('purchase_order_id', $draft->id)->value('unit_price'));
    }
}
