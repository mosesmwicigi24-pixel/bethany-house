<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\FreezesClockMidMonth;
use Tests\TestCase;

/**
 * Stock-out revenue loss — reconstructed zero-stock windows, honest velocity.
 *
 * The promises pinned here:
 *  - Zero windows are reconstructed by walking inventory_transactions
 *    backwards from the live quantity_on_hand; a 10-day gap between a
 *    sale-to-zero and the restock is exactly 10 out-days.
 *  - Velocity divides units sold by IN-stock days only, and
 *    est_lost_revenue = out_days × velocity × avg unit price.
 *  - A currently-out product reports its live streak and its KES/day bleed.
 *  - An item with NO ledger rows at all is 'low' confidence: only its
 *    current out-streak (from updated_at) is claimed, and its KES never
 *    enter the measured summary totals.
 *  - Products that sold but were never out do not appear at all.
 *  - The attention feed's 'stockout_loss' item fires only while money is
 *    bleeding, at high severity from KES 2,000/day.
 *  - The endpoint sits behind reports.view.
 */
class StockoutLossTest extends TestCase
{
    use RefreshDatabase;
    use FreezesClockMidMonth;

    /** A named product with an English translation, like the real catalogue. */
    private function product(string $name): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /** A live stock row; created long ago so pre-ledger history has a floor. */
    private function stockRow(Outlet $outlet, Product $product, int $onHand, ?int $updatedDaysAgo = null): int
    {
        return DB::table('inventory_items')->insertGetId([
            'product_id' => $product->id, 'outlet_id' => $outlet->id,
            'quantity_on_hand' => $onHand, 'quantity_reserved' => 0, 'reorder_point' => 0,
            'created_at' => now()->subDays(120),
            'updated_at' => $updatedDaysAgo !== null ? now()->subDays($updatedDaysAgo) : now(),
        ]);
    }

    private function txn(int $itemId, int $change, int $before, int $daysAgo): void
    {
        DB::table('inventory_transactions')->insert([
            'inventory_item_id' => $itemId,
            'transaction_type' => $change < 0 ? 'sale' : 'purchase',
            'quantity_change' => $change,
            'quantity_before' => $before,
            'quantity_after' => $before + $change,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function sell(Product $product, int $qty, float $unitPrice, int $daysAgo): void
    {
        $when  = now()->subDays($daysAgo);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $qty * $unitPrice, 'currency_code' => 'KES',
            'created_at' => $when,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
            'quantity' => $qty, 'unit_price' => $unitPrice, 'total_price' => $qty * $unitPrice,
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function reportsViewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_reconstructed_zero_window_velocity_and_loss_math(): void
    {
        $outlet = Outlet::factory()->create();
        $p = $this->product('Cassock Fabric Bolt');

        // Ledger, walked backwards from the live 40 on hand:
        //   restock +40 five days ago  → before it: 0   (out now−15d … now−5d)
        //   sale    −10 fifteen days ago → before it: 10 (stocked before that)
        // Exactly a 10-day zero window inside the 90-day window.
        $item = $this->stockRow($outlet, $p, 40);
        $this->txn($item, +40, 0, 5);
        $this->txn($item, -10, 10, 15);

        // 160 units at KES 500 across the window; 90 − 10 = 80 in-stock days
        // → velocity 2/day. est loss = 10 days × 2/day × 500 = 10,000.
        foreach ([80, 60, 30, 20] as $daysAgo) {
            $this->sell($p, 40, 500, $daysAgo);
        }

        $report = MetricEngine::for(User::factory()->create())->stockoutLoss();

        $row = collect($report['products'])->firstWhere('product_id', $p->id);
        $this->assertNotNull($row, 'Product with a reconstructed zero window must be listed');
        $this->assertSame('Cassock Fabric Bolt', $row['name']);
        $this->assertFalse($row['currently_out']);
        $this->assertEqualsWithDelta(0.0, $row['out_streak_days'], 0.01);
        $this->assertEqualsWithDelta(10.0, $row['out_days_window'], 0.05);
        $this->assertEqualsWithDelta(2.0, $row['velocity_per_day'], 0.01);
        $this->assertEqualsWithDelta(500.0, $row['avg_price'], 0.01);
        $this->assertEqualsWithDelta(10000.0, $row['est_lost_revenue'], 25.0);
        $this->assertSame('measured', $row['confidence']);
        $this->assertEqualsWithDelta(0.0, $row['est_daily_loss'], 0.01);

        $this->assertSame(0, $report['summary']['products_currently_out']);
        $this->assertEqualsWithDelta(0.0, $report['summary']['est_daily_loss_now'], 0.01);
        $this->assertEqualsWithDelta(10000.0, $report['summary']['est_lost_revenue_window'], 25.0);
        $this->assertSame(0, $report['summary']['low_confidence_count']);
    }

    public function test_currently_out_product_reports_streak_and_daily_bleed(): void
    {
        $outlet = Outlet::factory()->create();
        $p = $this->product('Clergy Collar');

        // Sold down to zero 7 days ago and never restocked: live streak = 7.
        $item = $this->stockRow($outlet, $p, 0);
        $this->txn($item, -5, 5, 7);

        // 83 units at KES 200 over 90 − 7 = 83 in-stock days → 1/day.
        $this->sell($p, 83, 200, 40);

        $report = MetricEngine::unscoped()->stockoutLoss();
        $row = collect($report['products'])->firstWhere('product_id', $p->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['currently_out']);
        $this->assertEqualsWithDelta(7.0, $row['out_streak_days'], 0.05);
        $this->assertEqualsWithDelta(7.0, $row['out_days_window'], 0.05);
        $this->assertEqualsWithDelta(1.0, $row['velocity_per_day'], 0.01);
        $this->assertEqualsWithDelta(200.0, $row['est_daily_loss'], 0.5);
        $this->assertEqualsWithDelta(1400.0, $row['est_lost_revenue'], 5.0);

        $this->assertSame(1, $report['summary']['products_currently_out']);
        $this->assertEqualsWithDelta(200.0, $report['summary']['est_daily_loss_now'], 0.5);
    }

    public function test_no_ledger_item_is_low_confidence_and_excluded_from_measured_totals(): void
    {
        $outlet = Outlet::factory()->create();
        $p = $this->product('Altar Candle');

        // At zero NOW with no inventory_transactions ever (legacy write
        // paths): history unknown — only the streak since updated_at counts.
        $this->stockRow($outlet, $p, 0, updatedDaysAgo: 12);
        $this->sell($p, 10, 300, 30);

        $report = MetricEngine::unscoped()->stockoutLoss();
        $row = collect($report['products'])->firstWhere('product_id', $p->id);

        $this->assertNotNull($row, 'A currently-out no-ledger product still surfaces (flagged)');
        $this->assertSame('low', $row['confidence']);
        $this->assertTrue($row['currently_out']);
        $this->assertEqualsWithDelta(12.0, $row['out_streak_days'], 0.05);
        $this->assertEqualsWithDelta(12.0, $row['out_days_window'], 0.05);

        // Counted, but its KES never enter the measured totals.
        $this->assertSame(1, $report['summary']['low_confidence_count']);
        $this->assertSame(1, $report['summary']['products_currently_out']);
        $this->assertEqualsWithDelta(0.0, $report['summary']['est_daily_loss_now'], 0.01);
        $this->assertEqualsWithDelta(0.0, $report['summary']['est_lost_revenue_window'], 0.01);

        // And with no MEASURED bleed there is no attention item.
        $attention = MetricEngine::unscoped()->attention();
        $this->assertNull(collect($attention)->firstWhere('key', 'stockout_loss'));
    }

    public function test_product_with_sales_but_never_out_is_absent(): void
    {
        $outlet = Outlet::factory()->create();

        // Ledger-backed and never near zero…
        $a = $this->product('Communion Bread');
        $itemA = $this->stockRow($outlet, $a, 50);
        $this->txn($itemA, -10, 60, 20); // before: 60, after: 50 — never ≤ 0
        $this->sell($a, 30, 100, 25);

        // …and ledger-less but comfortably stocked.
        $b = $this->product('Hymn Book');
        $this->stockRow($outlet, $b, 25);
        $this->sell($b, 5, 800, 10);

        $report = MetricEngine::unscoped()->stockoutLoss();

        $ids = collect($report['products'])->pluck('product_id');
        $this->assertFalse($ids->contains($a->id), 'Never-out product must not be listed');
        $this->assertFalse($ids->contains($b->id), 'Stocked no-ledger product must not be listed');
        $this->assertSame(0, $report['summary']['products_currently_out']);
    }

    public function test_attention_item_severity_follows_the_daily_bleed(): void
    {
        $outlet = Outlet::factory()->create();

        // KES 2,500/day bleeding → high severity.
        $p = $this->product('Bishop Mitre');
        $item = $this->stockRow($outlet, $p, 0);
        $this->txn($item, -3, 3, 4);
        $this->sell($p, 86, 2500, 50); // 86 units / (90−4=86 days) = 1/day @ 2,500

        $attention = MetricEngine::unscoped()->attention();
        $item = collect($attention)->firstWhere('key', 'stockout_loss');

        $this->assertNotNull($item, 'Bleeding stock-out must surface on the attention feed');
        $this->assertSame('high', $item['severity']);
        $this->assertSame(1, $item['count']);
        $this->assertSame('/reports/inventory?tab=intelligence', $item['link']);
        $this->assertSame($p->id, $item['entities'][0]['product_id']);
        $this->assertSame('Bishop Mitre', $item['entities'][0]['name']);
        $this->assertEqualsWithDelta(4.0, $item['entities'][0]['out_streak_days'], 0.05);
        $this->assertEqualsWithDelta(2500.0, $item['entities'][0]['daily_loss'], 5.0);
        $this->assertSame('navigate', $item['actions'][0]['type']);
        $this->assertSame('/reports/inventory?tab=intelligence', $item['actions'][0]['to']);
        $this->assertStringContainsString('Bishop Mitre', $item['detail']);
    }

    public function test_attention_item_is_medium_below_2000_per_day(): void
    {
        $outlet = Outlet::factory()->create();

        $p = $this->product('Clergy Collar');
        $item = $this->stockRow($outlet, $p, 0);
        $this->txn($item, -5, 5, 7);
        $this->sell($p, 83, 200, 40); // 1/day @ 200 → KES 200/day

        $attention = MetricEngine::unscoped()->attention();
        $item = collect($attention)->firstWhere('key', 'stockout_loss');

        $this->assertNotNull($item);
        $this->assertSame('medium', $item['severity']);
    }

    public function test_endpoint_requires_reports_view_and_validates_days(): void
    {
        $outlet = Outlet::factory()->create();
        $p = $this->product('Cassock');
        $item = $this->stockRow($outlet, $p, 0);
        $this->txn($item, -2, 2, 3);
        $this->sell($p, 10, 400, 20);

        // No permission → 403.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reports/stockout-loss')->assertForbidden();

        // reports.view → the full payload; days is clamped to 30..365.
        $this->reportsViewer();
        $res = $this->getJson('/api/v1/admin/reports/stockout-loss')->assertOk()->json();
        $this->assertSame(1, $res['summary']['products_currently_out']);
        $this->assertSame($p->id, $res['products'][0]['product_id']);

        $this->getJson('/api/v1/admin/reports/stockout-loss?days=10')->assertStatus(422);
        $this->getJson('/api/v1/admin/reports/stockout-loss?days=30')->assertOk();
    }
}
