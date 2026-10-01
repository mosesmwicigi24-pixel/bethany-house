<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Page gaps: order outcomes and lost sales (Sales), profit with its limits
 * stated (Executive), stock aging by last movement (Inventory). Each is
 * reconciled with the figure beside it.
 */
class ReportGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();
        $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'K']);
    }

    private function actingWith(array $perms): void
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function order(float $total, string $status, string $pay, ?User $by = null): Order
    {
        return Order::create([
            'order_number' => 'GP-' . uniqid(), 'order_type' => 'pos', 'status' => $status, 'payment_status' => $pay,
            'currency_code' => 'KES', 'subtotal' => $total, 'total_amount' => $total, 'created_by' => $by?->id,
            'customer_phone' => '07' . random_int(10000000, 99999999),
        ]);
    }

    public function test_every_order_lands_in_one_outcome_and_sold_is_the_sold_tile(): void
    {
        $this->actingWith(['reports.view', 'orders.view']);
        $this->order(10_000, 'completed', 'paid');
        $this->order(4_000, 'pending', 'paid');          // paid: a sale, not a cart
        $this->order(7_000, 'pending', 'pending');       // a cart
        $this->order(3_000, 'cancelled', 'pending', $this->ann);
        $this->order(2_000, 'voided', 'pending', $this->ann);
        $this->order(1_500, 'refunded', 'paid');

        $o = $this->getJson('/api/v1/admin/reports/outcomes?period=this_month')->assertOk()->json();
        $sold = (float) $this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('kpis.sales.revenue.current');

        $this->assertSame($sold, (float) $o['sold']['value'], 'sold IS the Sold tile');
        $this->assertSame(14_000.0, (float) $o['sold']['value']);
        $this->assertSame(7_000.0, (float) $o['unconfirmed']['value']);
        $this->assertSame(5_000.0, (float) $o['lost']['value']);
        $this->assertSame(1_500.0, (float) $o['refunded']['value']);
        $this->assertSame(0, $o['other']['orders']);
        $this->assertSame(6, array_sum(array_map(fn ($b) => $o[$b]['orders'], ['sold', 'unconfirmed', 'lost', 'refunded', 'other'])));
        $this->assertSame('Ann K', $o['lost']['by_salesperson'][0]['name']);

        $drill = $this->getJson('/api/v1/admin/reports/drill/lost?period=this_month')->assertOk()->json();
        $this->assertSame(2, $drill['total']);
        $this->assertSame(5_000.0, round(array_sum(array_column($drill['rows'], 'amount')), 2), 'the drill is the figure');
        $this->assertStringStartsWith('/sales/orders/', $drill['rows'][0]['links']['order']);
    }

    public function test_executive_profit_states_what_it_leaves_out(): void
    {
        $this->actingWith(['reports.view', 'reports.financial']);
        $o = $this->order(4_000, 'completed', 'paid');
        $product = Product::factory()->create();     // no cost anywhere
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $product->id, 'sku' => 'GP', 'product_name' => 'Line',
            'quantity' => 1, 'unit_price' => 4_000, 'total_price' => 4_000, 'created_at' => now(), 'updated_at' => now()]);
        Payment::create(['order_id' => $o->id, 'amount' => 4_000, 'currency_code' => 'KES', 'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);

        $earned = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk()->json('kpis.financial.earned');
        $finance = $this->getJson('/api/v1/admin/reports/financial-intelligence?period=this_month')->json('pnl');

        $this->assertSame($finance['net_profit'], $earned['net_profit'], 'the Finance page\'s figure, not a new one');
        $this->assertCount(1, $earned['limits']);
        $this->assertStringContainsString('1 line sold with no cost', $earned['limits'][0]);

        $this->actingWith(['reports.view']);
        $this->assertNull($this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('kpis.financial'), 'profit stays behind reports.financial');
    }

    public function test_stock_aging_buckets_add_up_to_the_stock_value_and_cost_needs_financial(): void
    {
        $this->actingWith(['reports.view', 'reports.financial']);
        $outlet = Outlet::factory()->create();
        $make = function (int $units, ?int $daysAgo, float $cost) use ($outlet) {
            $product = Product::factory()->create();
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
            DB::table('product_prices')->insert([
                ['product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES', 'regular_price' => $cost * 2, 'cost_price' => $cost, 'created_at' => now(), 'updated_at' => now()],
                // An empty variant row: must not hide the product's cost.
                ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'currency_code' => 'KES', 'regular_price' => $cost * 2, 'cost_price' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);
            $item = InventoryItem::factory()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id,
                'outlet_id' => $outlet->id, 'quantity_on_hand' => $units]);
            if ($daysAgo !== null) {
                DB::table('inventory_transactions')->insert(['inventory_item_id' => $item->id, 'transaction_type' => 'sale',
                    'quantity_change' => -2, 'quantity_before' => $units + 2, 'quantity_after' => $units, 'created_at' => now()->subDays($daysAgo)]);
            }
        };
        $make(10, 5, 100);      // 0–30, sold 2 inside 90 days
        $make(4, 45, 250);      // 31–60
        $make(3, 120, 1_000);   // over 90
        $make(2, null, 50);     // never moved

        $aging  = $this->getJson('/api/v1/admin/reports/inventory/aging')->assertOk()->json();
        $health = $this->getJson('/api/v1/admin/reports/inventory-intelligence?period=this_month')->assertOk()->json('health');

        $byKey = collect($aging['buckets'])->keyBy('key');
        $this->assertSame([1, 1, 0, 1, 1], $byKey->pluck('lines')->values()->all());
        $this->assertSame(5_100.0, (float) $aging['totals']['cost_value'], '1,000 + 1,000 + 3,000 + 100 — every product cost found');
        $this->assertSame((float) $health['cost_value'], (float) $aging['totals']['cost_value'], 'the buckets ARE the overview\'s stock value');
        $this->assertSame(0, (int) $health['unpriced']);
        $this->assertSame(4, $aging['turnover']['sold_90_days'], 'the 120-day-old sale is outside the 90 days');
        $this->assertSame(3, count($aging['slow_items']), 'everything not moved in 30 days is listed');

        $this->actingWith(['reports.view']);
        $plain = $this->getJson('/api/v1/admin/reports/inventory/aging')->assertOk()->json();
        $this->assertNull($plain['totals']['cost_value'], 'stock at cost is a financial figure');
        $this->assertNull($plain['buckets'][0]['cost_value']);
        $this->assertNull($plain['slow_items'][0]['amount']);
        $this->assertSame(19, $plain['totals']['units'], 'units stay');
    }
}
