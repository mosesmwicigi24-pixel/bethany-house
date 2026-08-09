<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Replenishment Radar — per-(customer, product) reorder-cycle detection.
 *
 * A rhythmic buyer (3+ purchase dates at steady intervals) whose due window
 * has opened must surface with the detected cycle and expected value; erratic
 * intervals (CV > 0.6) and lapsed pairs (3+ cycles quiet) must not.
 */
class ReplenishmentRadarTest extends TestCase
{
    use RefreshDatabase;

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

    /** One sale of one product line, $daysAgo days ago. */
    private function sell(string $phone, string $name, Product $product, float $lineTotal, int $daysAgo): void
    {
        $when = now()->subDays($daysAgo);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $lineTotal, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => $name,
            'created_at' => $when,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
            'quantity' => 1, 'unit_price' => $lineTotal, 'total_price' => $lineTotal,
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_rhythmic_buyer_surfaces_with_detected_cycle(): void
    {
        $bread = $this->product('Holy Communion Bread');
        // Four purchases exactly 30 days apart, the last 35 days ago:
        // cycle 30, next_due 5 days ago → due (within the 25% grace).
        foreach ([125, 95, 65, 35] as $daysAgo) {
            $this->sell('0722000111', 'Grace', $bread, 4000, $daysAgo);
        }

        $radar = MetricEngine::for(User::factory()->create())->replenishmentRadar();

        $this->assertSame(1, $radar['summary']['due_pairs']);
        $this->assertSame(1, $radar['summary']['due_customers']);
        $this->assertEqualsWithDelta(4000.0, $radar['summary']['expected_revenue'], 0.01);

        $row = $radar['due'][0];
        $this->assertSame('Grace', $row['name']);
        $this->assertSame('0722000111', $row['phone']);
        $this->assertSame('Holy Communion Bread', $row['product_name']);
        $this->assertSame(4, $row['purchase_events']);
        $this->assertEqualsWithDelta(30, $row['cycle_days'], 1);
        $this->assertContains($row['status'], ['due_soon', 'overdue']);
        $this->assertEqualsWithDelta(5, $row['days_over'], 1);
        $this->assertEqualsWithDelta(4000.0, $row['avg_value'], 0.01);
    }

    public function test_irregular_buyer_is_excluded_by_interval_cv(): void
    {
        $wine = $this->product('Altar Wine');
        // Three purchases at wildly different gaps (5 days, then 100):
        // CV ≈ 1.28 — no rhythm, no radar row.
        foreach ([150, 145, 45] as $daysAgo) {
            $this->sell('0733000222', 'Ivan', $wine, 6000, $daysAgo);
        }

        $radar = MetricEngine::for(User::factory()->create())->replenishmentRadar();

        $this->assertSame(0, $radar['summary']['due_pairs']);
        $this->assertSame([], $radar['due']);
    }

    public function test_lapsed_buyer_is_excluded(): void
    {
        $cups = $this->product('Prefilled Communion Cups');
        // Perfect 30-day rhythm, but the last purchase is 150 days (5 cycles)
        // ago — win-back territory, not radar material.
        foreach ([240, 210, 180, 150] as $daysAgo) {
            $this->sell('0744000333', 'Lydia', $cups, 9000, $daysAgo);
        }

        $radar = MetricEngine::for(User::factory()->create())->replenishmentRadar();

        $this->assertSame(0, $radar['summary']['due_pairs']);
        $this->assertSame([], $radar['due']);
    }

    public function test_endpoint_returns_radar_shape_for_report_viewers(): void
    {
        $this->viewer();

        $bread = $this->product('Holy Communion Bread');
        foreach ([125, 95, 65, 35] as $daysAgo) {
            $this->sell('0722000444', 'Naomi', $bread, 3500, $daysAgo);
        }

        $res = $this->getJson('/api/v1/admin/reports/replenishment')->assertOk();

        $res->assertJsonStructure([
            'summary' => ['due_customers', 'due_pairs', 'expected_revenue'],
            'due' => [['ckey', 'name', 'phone', 'product_id', 'product_name', 'cycle_days',
                       'purchase_events', 'last_purchase_at', 'next_due_at', 'days_over',
                       'status', 'avg_value']],
        ]);
        $this->assertSame(1, $res->json('summary.due_pairs'));
        $this->assertSame('Holy Communion Bread', $res->json('due.0.product_name'));
    }

    public function test_endpoint_requires_reports_view(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/reports/replenishment')->assertStatus(403);
    }

    public function test_attention_feed_advertises_due_reorders(): void
    {
        $this->viewer();

        $bread = $this->product('Holy Communion Bread');
        // Last purchase 45 days into a 30-day cycle: 15 days over > 25% grace
        // (8 days) → overdue, and avg 8,000 >= 5,000 → severity high.
        foreach ([135, 105, 75, 45] as $daysAgo) {
            $this->sell('0722000555', 'Peter', $bread, 8000, $daysAgo);
        }

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'replenishment_due');

        $this->assertNotNull($item, 'due reorders must surface on the attention feed');
        $this->assertSame('high', $item['severity']);
        $this->assertSame(1, $item['count']);
        $this->assertSame('/reports/customers', $item['link']);

        $entity = $item['entities'][0];
        $this->assertSame('Peter', $entity['name']);
        $this->assertSame('Holy Communion Bread', $entity['product']);
        $this->assertEqualsWithDelta(15, $entity['days_over'], 1);

        $nav = collect($item['actions'])->firstWhere('type', 'navigate');
        $this->assertSame('/reports/customers?tab=replenishment', $nav['to']);
    }
}
