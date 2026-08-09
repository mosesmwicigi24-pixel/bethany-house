<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Attach-rate intelligence — basket affinity mining + the POS suggestion feed.
 *
 * The promises pinned here:
 *  - attach_rate = co-baskets / anchor baskets; lift = attach_rate over the
 *    companion's base rate; pairs below support 3 / lift 1.2 / attach 10%
 *    never surface.
 *  - missed_revenue_estimate = missed x attach_rate x avg companion line
 *    value — the expected-value proxy, exactly as documented.
 *  - The report endpoint sits behind reports.view; the POS suggestions
 *    endpoint behind pos.access, excludes what is already in the cart, and
 *    is capped at 4 chips.
 *  - The 10-minute cache is keyed by outlet scope: an outlet-scoped engine
 *    can never be served the unscoped (global) result, or vice versa.
 */
class AttachRatesTest extends TestCase
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

    /**
     * One basket: an order holding the given product lines
     * [[Product, lineTotal], ...], placed $daysAgo days ago.
     */
    private function basket(array $lines, int $daysAgo = 10, array $orderAttrs = []): Order
    {
        $when  = now()->subDays($daysAgo);
        $order = Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => collect($lines)->sum(fn ($l) => $l[1]),
            'currency_code' => 'KES',
            'created_at' => $when,
        ], $orderAttrs));

        foreach ($lines as [$product, $lineTotal]) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $product->id,
                'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
                'quantity' => 1, 'unit_price' => $lineTotal, 'total_price' => $lineTotal,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }

        return $order;
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

    private function posClerk(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * The canonical fixture: 20 baskets.
     *   6 x {A, B}   2 x {A}   2 x {B}   10 x {C}  (2 of those also carry D)
     *
     * A: 8 baskets, B: 8 baskets, together 6 → attach A→B = 75%.
     * lift = 0.75 / (8/20) = 1.875.  missed = 8 - 6 = 2.
     * B's line value is always 2,000 → missed_revenue = 2 x 0.75 x 2000 = 3000.
     * C is an anchor (10 baskets) whose only companion D has support 2 → D
     * must NOT surface (below the 3-basket support gate).
     */
    private function seedCanonicalBaskets(): array
    {
        $a = $this->product('Cassock');
        $b = $this->product('Clergy Collar');
        $c = $this->product('Communion Bread');
        $d = $this->product('Altar Candle');

        for ($i = 0; $i < 6; $i++) {
            $this->basket([[$a, 5000], [$b, 2000]]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->basket([[$a, 5000]]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->basket([[$b, 2000]]);
        }
        for ($i = 0; $i < 10; $i++) {
            $lines = [[$c, 1500]];
            if ($i < 2) {
                $lines[] = [$d, 800];
            }
            $this->basket($lines);
        }

        return [$a, $b, $c, $d];
    }

    public function test_attach_rate_lift_and_missed_revenue_math(): void
    {
        [$a, $b] = $this->seedCanonicalBaskets();

        $report = MetricEngine::for(User::factory()->create())->attachRates();

        // Basket shape: 20 baskets, 8 of them multi-item (6 A+B, 2 C+D).
        $this->assertSame(20, $report['summary']['total_baskets']);
        $this->assertEqualsWithDelta(40.0, $report['summary']['multi_item_pct'], 0.01);
        $this->assertEqualsWithDelta(1.4, $report['summary']['avg_basket_items'], 0.01);

        $anchorA = collect($report['anchors'])->firstWhere('product_id', $a->id);
        $this->assertNotNull($anchorA, 'Product A (8 baskets) must be an anchor');
        $this->assertSame('Cassock', $anchorA['name']);
        $this->assertSame(8, $anchorA['baskets']);

        $comp = collect($anchorA['companions'])->firstWhere('product_id', $b->id);
        $this->assertNotNull($comp, 'B must surface as A\'s companion');
        $this->assertSame('Clergy Collar', $comp['name']);
        $this->assertEqualsWithDelta(75.0, $comp['attach_rate_pct'], 0.01);
        // lift = 0.75 / (8 companion baskets / 20 total) = 1.875
        $this->assertEqualsWithDelta(1.88, $comp['lift'], 0.01);
        $this->assertEqualsWithDelta(2000.0, $comp['avg_value'], 0.01);
        $this->assertSame(2, $comp['missed']);
        // missed_revenue = missed x attach_rate x avg_value = 2 x 0.75 x 2000
        $this->assertEqualsWithDelta(3000.0, $comp['missed_revenue_estimate'], 0.01);

        // The best pair headline is the A→B (or B→A) 75% attach.
        $this->assertNotNull($report['summary']['top_pair']);
        $this->assertEqualsWithDelta(75.0, $report['summary']['top_pair']['attach_rate'], 0.01);
    }

    public function test_below_threshold_pairs_are_excluded(): void
    {
        [, , $c, $d] = $this->seedCanonicalBaskets();

        $report = MetricEngine::for(User::factory()->create())->attachRates();

        // C is a valid anchor (10 baskets) but its only companion D co-occurs
        // in just 2 baskets — below the support-3 gate — so C has no
        // surfaced companions and therefore no anchor row at all.
        $anchorC = collect($report['anchors'])->firstWhere('product_id', $c->id);
        $this->assertNull($anchorC, 'C\'s only pair is below support threshold — no anchor row');

        // And D never appears as anyone's companion.
        foreach ($report['anchors'] as $anchor) {
            $this->assertNull(
                collect($anchor['companions'])->firstWhere('product_id', $d->id),
                'D (support 2) must never surface as a companion',
            );
        }
    }

    public function test_report_endpoint_requires_reports_view(): void
    {
        $this->seedCanonicalBaskets();

        // No permission → 403.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reports/attach-rates')->assertForbidden();

        // reports.view → the full payload.
        $this->reportsViewer();
        $res = $this->getJson('/api/v1/admin/reports/attach-rates')->assertOk()->json();
        $this->assertSame(20, $res['summary']['total_baskets']);
        $this->assertNotEmpty($res['anchors']);
    }

    public function test_pos_suggestions_rank_exclude_in_cart_and_gate_by_permission(): void
    {
        [$a, $b] = $this->seedCanonicalBaskets();

        // A user with no pos.access is refused.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/pos/suggestions?product_ids[]=' . $a->id)
            ->assertForbidden();

        // A pos clerk with A in the cart is told to offer B (75% attach).
        $this->posClerk();
        $res = $this->getJson('/api/v1/admin/pos/suggestions?product_ids[]=' . $a->id)
            ->assertOk()->json('suggestions');

        $this->assertCount(1, $res);
        $this->assertSame($b->id, $res[0]['product_id']);
        $this->assertSame('Clergy Collar', $res[0]['name']);
        $this->assertEqualsWithDelta(75.0, $res[0]['attach_rate_pct'], 0.01);
        $this->assertSame('Cassock', $res[0]['anchor_name']);

        // With B already in the cart too, there is nothing left to suggest.
        $res = $this->getJson(
            '/api/v1/admin/pos/suggestions?product_ids[]=' . $a->id . '&product_ids[]=' . $b->id,
        )->assertOk()->json('suggestions');
        $this->assertSame([], $res);
    }

    public function test_cache_is_keyed_by_outlet_scope(): void
    {
        Cache::flush();

        $outletId = \App\Models\Outlet::factory()->create()->id;

        $a = $this->product('Cassock');
        $b = $this->product('Clergy Collar');
        $c = $this->product('Communion Bread');

        // All affinity history lives OUTSIDE the scoped outlet (the orders
        // carry no matching outlet_id, so the scoped engine must see none of
        // it). The lone-C baskets keep A→B's lift above the 1.2 gate.
        for ($i = 0; $i < 6; $i++) {
            $this->basket([[$a, 5000], [$b, 2000]]);
        }
        for ($i = 0; $i < 4; $i++) {
            $this->basket([[$a, 5000]]);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->basket([[$c, 1500]]);
        }

        // Global (unscoped) result mines the baskets…
        $global = MetricEngine::unscoped()->attachRates();
        $this->assertSame(15, $global['summary']['total_baskets']);
        $this->assertNotEmpty($global['anchors']);

        // …while an engine scoped to the outlet with zero orders must compute
        // its own empty result, not inherit the warm global cache entry.
        $scopedUser = User::factory()->create();
        $scopedUser->outlets()->attach($outletId);
        $scoped = MetricEngine::for($scopedUser)->attachRates();
        $this->assertSame(0, $scoped['summary']['total_baskets']);
        $this->assertSame([], $scoped['anchors']);

        // And asking globally again still returns the mined data (the scoped
        // call did not overwrite the global key either).
        $this->assertSame(15, MetricEngine::unscoped()->attachRates()['summary']['total_baskets']);
    }
}
