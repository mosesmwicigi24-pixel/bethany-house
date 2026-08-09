<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Liturgical demand planning — the promises pinned here:
 *
 *  - seasonal:import-legacy upserts daily aggregates on (sale_date,
 *    product_name), matches products via --map first then exact
 *    case-insensitive en translation, reports unmatched names, and re-runs
 *    converge (idempotent).
 *  - Seasonal lift = seasonal units/day ÷ ordinary units/day from COMBINED
 *    history (hub + legacy); projection = trailing-60d hub velocity × lift ×
 *    season length; a product the hub has never sold projects straight from
 *    the historical rate (basis 'historical').
 *  - gap = projected − stock on hand; order_by = season start − the
 *    product's supplier lead time (PO→GRN), falling back to 14 days.
 *  - With NO history at all the report still returns the season timeline
 *    with history_depth_days 0 (the UI's "import to unlock" state).
 *  - attention() raises seasonal_order_window only when order windows are
 *    urgent, at high severity within 7 days.
 *  - The endpoint sits behind reports.view.
 *
 * The clock is pinned per-test (2027 dates) so lent 2027 (Ash Wednesday
 * 2027-02-10 → Holy Saturday 2027-03-27, 46 days) sits inside the 120-day
 * horizon while lent 2026 (2026-02-18 → 2026-04-04) supplies the history.
 */
class SeasonalDemandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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

    /** Seed legacy daily aggregates at a constant units/day across [$from, $to]. */
    private function seedLegacyDaily(Product $product, string $from, string $to, float $unitsPerDay, float $unitPrice = 0.0): void
    {
        $rows = [];
        $d = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($d->lte($end)) {
            $rows[] = [
                'sale_date'    => $d->toDateString(),
                'product_id'   => $product->id,
                'product_name' => 'legacy:' . $product->id,
                'units'        => $unitsPerDay,
                'revenue'      => round($unitsPerDay * $unitPrice, 2),
                'created_at'   => now(), 'updated_at' => now(),
            ];
            $d = $d->addDay();
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('legacy_sales_daily')->insert($chunk);
        }
    }

    private function hubSale(Product $product, int $qty, float $unitPrice, string $when): void
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $qty * $unitPrice, 'currency_code' => 'KES',
            'created_at' => Carbon::parse($when),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
            'quantity' => $qty, 'unit_price' => $unitPrice, 'total_price' => $qty * $unitPrice,
            'created_at' => Carbon::parse($when), 'updated_at' => Carbon::parse($when),
        ]);
    }

    private function stock(Outlet $outlet, Product $product, int $onHand): void
    {
        DB::table('inventory_items')->insert([
            'product_id' => $product->id, 'outlet_id' => $outlet->id,
            'quantity_on_hand' => $onHand, 'quantity_reserved' => 0, 'reorder_point' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A delivered PO for the product: order placed → GRN $leadDays later. */
    private function purchaseWithLead(Outlet $outlet, Product $product, int $leadDays, string $orderDate): void
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Legacy Vestments Ltd ' . uniqid(), 'code' => 'SUP' . random_int(1000, 999999),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-' . random_int(100000, 999999),
            'supplier_id' => $supplierId, 'outlet_id' => $outlet->id,
            'order_date' => $orderDate, 'status' => 'received',
            'subtotal' => 1000, 'total_amount' => 1000,
            'created_by' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId, 'item_type' => 'product',
            'product_id' => $product->id, 'description' => 'seasonal stock',
            'quantity' => 10, 'unit_price' => 100, 'total_price' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('goods_received_notes')->insert([
            'grn_number' => 'GRN-' . random_int(100000, 999999),
            'purchase_order_id' => $poId,
            'received_date' => Carbon::parse($orderDate)->addDays($leadDays)->toDateString(),
            'received_by' => User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
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

    // ── Import command ────────────────────────────────────────────────────────

    public function test_import_command_matches_maps_reports_unmatched_and_is_idempotent(): void
    {
        $wafers = $this->product('Communion Wafers');
        $wine   = $this->product('Altar Wine');

        $dir = sys_get_temp_dir();
        $file = $dir . '/legacy-' . uniqid() . '.json';
        $mapFile = $dir . '/map-' . uniqid() . '.json';

        file_put_contents($file, json_encode([
            // Exact case-insensitive translation match.
            ['date' => '2026-03-01', 'product_name' => 'communion WAFERS', 'units' => 12, 'revenue' => 1200],
            // Only matchable through the --map file.
            ['date' => '2026-03-01', 'product_name' => 'Old Wine SKU', 'units' => 4, 'revenue' => 2000],
            // Unmatched: kept with product_id NULL.
            ['date' => '2026-03-01', 'product_name' => 'Discontinued Censer', 'units' => 1, 'revenue' => 900],
            // Malformed: skipped.
            ['date' => 'not-a-date', 'product_name' => 'Junk', 'units' => 'x'],
        ]));
        file_put_contents($mapFile, json_encode(['old wine sku' => $wine->id]));

        $this->artisan('seasonal:import-legacy', ['file' => $file, '--map' => $mapFile])
            ->expectsOutputToContain('Imported 3 day-rows (1 skipped as malformed).')
            ->expectsOutputToContain('Product names matched: 2, unmatched: 1.')
            ->expectsOutputToContain('Discontinued Censer')
            ->assertExitCode(0);

        $this->assertDatabaseCount('legacy_sales_daily', 3);
        $this->assertDatabaseHas('legacy_sales_daily', [
            'sale_date' => '2026-03-01', 'product_name' => 'communion WAFERS', 'product_id' => $wafers->id,
        ]);
        $this->assertDatabaseHas('legacy_sales_daily', [
            'sale_date' => '2026-03-01', 'product_name' => 'Old Wine SKU', 'product_id' => $wine->id,
        ]);
        $this->assertDatabaseHas('legacy_sales_daily', [
            'sale_date' => '2026-03-01', 'product_name' => 'Discontinued Censer', 'product_id' => null,
        ]);

        // Re-run with a corrected figure: converges, never duplicates.
        file_put_contents($file, json_encode([
            ['date' => '2026-03-01', 'product_name' => 'communion WAFERS', 'units' => 15, 'revenue' => 1500],
        ]));
        $this->artisan('seasonal:import-legacy', ['file' => $file])->assertExitCode(0);

        $this->assertDatabaseCount('legacy_sales_daily', 3);
        $row = DB::table('legacy_sales_daily')->where('product_name', 'communion WAFERS')->first();
        $this->assertEqualsWithDelta(15.0, (float) $row->units, 0.001);

        @unlink($file);
        @unlink($mapFile);
    }

    // ── Projection math ───────────────────────────────────────────────────────

    /**
     * Seed the canonical scenario: history begins at Lent 2026.
     *   - lent 2026 (46 days): 10 units/day of legacy history
     *   - every observed ordinary day: 2 units/day
     * → lift = 10 ÷ 2 = 5.
     */
    private function seedLentScenario(Product $product, string $today): void
    {
        // Lent 2026: Ash Wednesday 2026-02-18 → Holy Saturday 2026-04-04.
        $this->seedLegacyDaily($product, '2026-02-18', '2026-04-04', 10);

        // Ordinary time 2026: Pentecost+1 (2026-05-25) → Advent eve (2026-11-28),
        // and Epiphany ordinary 2027 from Jan 6 up to yesterday.
        $this->seedLegacyDaily($product, '2026-05-25', '2026-11-28', 2);
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        if ($yesterday >= '2027-01-06') {
            $this->seedLegacyDaily($product, '2027-01-06', $yesterday, 2);
        }
    }

    public function test_lift_projection_gap_and_order_by_from_seeded_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00', 'Africa/Nairobi'));

        $outlet = Outlet::factory()->create();
        $p = $this->product('Communion Wafers');
        $this->seedLentScenario($p, '2027-01-15');

        // Hub baseline velocity: 180 units in the trailing 60 days (placed in
        // Advent so it cannot pollute the ordinary-time denominator) → 3/day
        // at KES 500.
        $this->hubSale($p, 180, 500, '2026-12-10 10:00:00');

        // 90 on the shelf; the last delivered PO's supplier took 10 days.
        $this->stock($outlet, $p, 90);
        $this->purchaseWithLead($outlet, $p, 10, '2026-06-01');

        $report = MetricEngine::unscoped()->seasonalDemand();

        $this->assertSame(331, $report['summary']['history_depth_days']); // 2026-02-18 → 2027-01-15
        $this->assertSame('lent', $report['summary']['next_season']['key']);
        $this->assertSame('2027-02-10', $report['summary']['next_season']['start']);

        $lent = collect($report['seasons'])->firstWhere('key', 'lent');
        $this->assertNotNull($lent);
        $this->assertSame('2027-02-10', $lent['start']);
        $this->assertSame('2027-03-27', $lent['end']); // Holy Saturday 2027
        $this->assertSame(46, $lent['length_days']);
        $this->assertSame(26, $lent['days_until_start']);

        $row = collect($lent['products'])->firstWhere('product_id', $p->id);
        $this->assertNotNull($row, 'A product with lent history must be projected');
        $this->assertSame('Communion Wafers', $row['name']);
        // lent 10/day ÷ ordinary 2/day.
        $this->assertEqualsWithDelta(5.0, $row['lift'], 0.05);
        // hub 3/day × lift 5 × 46 days.
        $this->assertEqualsWithDelta(690, $row['projected_units'], 2);
        $this->assertSame('hub', $row['basis']);
        $this->assertSame(90, $row['stock']);
        $this->assertEqualsWithDelta(600, $row['gap'], 2);
        // Order-by: season start − the supplier's 10-day lead.
        $this->assertSame(10, $row['lead_days']);
        $this->assertSame('2027-01-31', $row['order_by']);
        $this->assertSame(16, $row['days_until_order_by']);
        // Gap × the hub's trailing average price (KES 500).
        $this->assertEqualsWithDelta(600 * 500, $row['est_gap_value'], 1200);

        // Holy Week rides inside lent: same lift, its own 7-day window,
        // excluded from summary totals.
        $holyWeek = collect($report['seasons'])->firstWhere('key', 'holy_week');
        $this->assertSame('lent', $holyWeek['sub_of']);
        $hwRow = collect($holyWeek['products'])->firstWhere('product_id', $p->id);
        $this->assertEqualsWithDelta(5.0, $hwRow['lift'], 0.05);
        $this->assertEqualsWithDelta(105, $hwRow['projected_units'], 2); // 3 × 5 × 7

        // Summary counts lent only (holy_week would double it); order window
        // is 16 days out, so nothing is urgent yet.
        $this->assertEqualsWithDelta(600 * 500, $report['summary']['total_gap_value'], 1200);
        $this->assertSame(0, $report['summary']['urgent_orders']);
    }

    public function test_product_without_hub_sales_projects_from_historical_rate(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00', 'Africa/Nairobi'));

        $outlet = Outlet::factory()->create();
        $p = $this->product('Palm Branches');

        // Lent-only legacy history at 5/day, KES 100/unit; the hub has never
        // sold it and no ordinary-time history exists (baseline floors).
        $this->seedLegacyDaily($p, '2026-02-18', '2026-04-04', 5, 100);
        $this->stock($outlet, $p, 0);

        $report = MetricEngine::unscoped()->seasonalDemand();
        $lent = collect($report['seasons'])->firstWhere('key', 'lent');
        $row = collect($lent['products'])->firstWhere('product_id', $p->id);

        $this->assertNotNull($row);
        $this->assertSame('historical', $row['basis']);
        // Historical 5/day × 46-day season, no hub velocity involved.
        $this->assertEqualsWithDelta(230, $row['projected_units'], 2);
        $this->assertEqualsWithDelta(230, $row['gap'], 2);
        // Zero-baseline lift is capped, not infinite.
        $this->assertLessThanOrEqual(25.0, $row['lift']);
        // Price falls back to the legacy revenue/unit (KES 100).
        $this->assertEqualsWithDelta(230 * 100, $row['est_gap_value'], 500);
        // No PO history at all → the conservative 14-day default lead.
        $this->assertSame(14, $row['lead_days']);
        $this->assertSame('2027-01-27', $row['order_by']);
    }

    public function test_empty_history_returns_graceful_unlock_shape(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00', 'Africa/Nairobi'));

        $report = MetricEngine::unscoped()->seasonalDemand();

        $this->assertSame(0, $report['summary']['history_depth_days']);
        $this->assertSame(0, $report['summary']['urgent_orders']);
        $this->assertEqualsWithDelta(0.0, $report['summary']['total_gap_value'], 0.001);
        // The season timeline still renders: lent is coming regardless.
        $this->assertSame('lent', $report['summary']['next_season']['key']);
        $keys = collect($report['seasons'])->pluck('key');
        $this->assertTrue($keys->contains('lent'));
        foreach ($report['seasons'] as $season) {
            $this->assertSame([], $season['products']);
        }

        // And no attention item fires from nothing.
        $attention = MetricEngine::unscoped()->attention();
        $this->assertNull(collect($attention)->firstWhere('key', 'seasonal_order_window'));
    }

    // ── Attention feed ────────────────────────────────────────────────────────

    public function test_attention_fires_high_when_the_order_window_is_closing(): void
    {
        // Five days before Lent 2027: with the default 14-day lead the
        // order-by date (Jan 27) has already passed.
        Carbon::setTestNow(Carbon::parse('2027-02-05 12:00:00', 'Africa/Nairobi'));

        $outlet = Outlet::factory()->create();
        $p = $this->product('Communion Wafers');
        $this->seedLegacyDaily($p, '2026-02-18', '2026-04-04', 10, 50);
        $this->stock($outlet, $p, 0);

        $attention = MetricEngine::unscoped()->attention();
        $item = collect($attention)->firstWhere('key', 'seasonal_order_window');

        $this->assertNotNull($item, 'A closing seasonal order window must surface');
        $this->assertSame('high', $item['severity']);
        $this->assertSame(1, $item['count']);
        $this->assertSame('/reports/procurement?tab=seasonal', $item['link']);
        $this->assertStringContainsString('Lent', $item['detail']);
        $this->assertStringContainsString('2027-01-27', $item['detail']);
        $this->assertSame($p->id, $item['entities'][0]['product_id']);
        $this->assertSame('Communion Wafers', $item['entities'][0]['name']);
        $this->assertSame('2027-01-27', $item['entities'][0]['order_by']);
        $this->assertSame('navigate', $item['actions'][0]['type']);
        $this->assertSame('/reports/procurement?tab=seasonal', $item['actions'][0]['to']);
    }

    // ── Endpoint ──────────────────────────────────────────────────────────────

    public function test_endpoint_requires_reports_view(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00', 'Africa/Nairobi'));

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reports/seasonal-demand')->assertForbidden();

        $outlet = Outlet::factory()->create();
        $p = $this->product('Communion Wafers');
        $this->seedLentScenario($p, '2027-01-15');
        $this->hubSale($p, 180, 500, '2026-12-10 10:00:00');
        $this->stock($outlet, $p, 90);

        $this->reportsViewer();
        $res = $this->getJson('/api/v1/admin/reports/seasonal-demand')->assertOk()->json();

        $this->assertSame('lent', $res['summary']['next_season']['key']);
        $this->assertSame(331, $res['summary']['history_depth_days']);
        $lent = collect($res['seasons'])->firstWhere('key', 'lent');
        $this->assertSame($p->id, $lent['products'][0]['product_id']);
    }
}
