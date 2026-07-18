<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 6 — the insights engine.
 *
 * Every detector is deterministic arithmetic over real rows: runway is
 * stock ÷ burn, the trend is three full months strictly declining, drift is
 * last price vs the prior 90-day average. Nothing is fabricated; each test
 * reproduces the number by hand.
 */
class InsightsEngineTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_manager', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_material_runway_is_stock_divided_by_burn(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $materialId = DB::table('materials')->insertGetId([
            'code' => 'THR-GOLD', 'name' => 'Gold Thread', 'unit_of_measure' => 'roll',
            'unit_cost' => 300, 'reorder_point' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('material_inventory')->insert([
            'material_id' => $materialId, 'outlet_id' => $outlet->id,
            'quantity_on_hand' => 20, 'quantity_reserved' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::factory()->create();
        $poId = DB::table('production_orders')->insertGetId([
            'order_number' => 'PRD-P6-RW', 'product_id' => $product->id,
            'quantity' => 30, 'status' => 'in_progress',
            'created_at' => now()->subDays(10), 'updated_at' => now(),
        ]);
        // 60 rolls burned over the last 30 days → 2/day; 20 left → 10 days.
        DB::table('material_allocations')->insert([
            'production_order_id' => $poId, 'material_id' => $materialId,
            'quantity_required' => 80, 'quantity_allocated' => 80, 'quantity_used' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'material_runway');

        $this->assertNotNull($item);
        $this->assertStringContainsString('Gold Thread', $item['title']);
        $this->assertStringContainsString('~10', $item['title']);
        $this->assertSame('medium', $item['severity']);
    }

    public function test_revenue_trend_fires_on_three_full_months_of_decline(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        foreach ([[3, 100000], [2, 80000], [1, 60000]] as [$monthsAgo, $amount]) {
            Order::factory()->create([
                'order_type' => 'pos', 'outlet_id' => $outlet->id,
                'status' => 'confirmed', 'total_amount' => $amount, 'currency_code' => 'KES',
                'created_at' => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(5),
            ]);
        }

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'revenue_trend');

        $this->assertNotNull($item);
        // (100,000 − 60,000) / 100,000 = 40% decline across the streak.
        $this->assertStringContainsString('40%', $item['title']);
        $this->assertStringContainsString('100,000', $item['detail']);
        $this->assertStringContainsString('60,000', $item['detail']);
    }

    public function test_revenue_trend_stays_silent_when_a_month_recovers(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        foreach ([[3, 100000], [2, 60000], [1, 80000]] as [$monthsAgo, $amount]) {
            Order::factory()->create([
                'order_type' => 'pos', 'outlet_id' => $outlet->id,
                'status' => 'confirmed', 'total_amount' => $amount, 'currency_code' => 'KES',
                'created_at' => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(5),
            ]);
        }

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $this->assertNull(collect($res->json('attention'))->firstWhere('key', 'revenue_trend'));
    }

    public function test_price_drift_compares_last_price_with_the_prior_average(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $materialId = DB::table('materials')->insertGetId([
            'code' => 'FAB-WHITE', 'name' => 'White Cotton', 'unit_of_measure' => 'm',
            'unit_cost' => 100, 'reorder_point' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-P6', 'name' => 'Eastleigh Mills', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[60, 100.0], [40, 100.0], [5, 130.0]] as $i => [$daysAgo, $price]) {
            $poId = DB::table('purchase_orders')->insertGetId([
                'po_number' => "PO-P6-{$i}", 'supplier_id' => $supplierId, 'outlet_id' => $outlet->id,
                'order_date' => now()->subDays($daysAgo)->format('Y-m-d'), 'status' => 'received',
                'subtotal' => $price * 100, 'total_amount' => $price * 100,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('purchase_order_items')->insert([
                'purchase_order_id' => $poId, 'item_type' => 'material', 'material_id' => $materialId,
                'description' => 'White cotton 100m', 'quantity' => 100,
                'unit_price' => $price, 'total_price' => $price * 100,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($res->json('attention'))->firstWhere('key', 'price_drift');

        $this->assertNotNull($item);
        // 130 vs the 100 average of the two prior buys = +30%.
        $this->assertStringContainsString('30.0%', $item['title']);
        $this->assertStringContainsString('Eastleigh Mills', $item['detail']);
    }

    public function test_digest_command_mails_recipients_and_guards_reruns(): void
    {
        $outlet = Outlet::factory()->create();
        $product = Product::factory()->create();

        // One real finding so the digest has something to say.
        DB::table('production_orders')->insert([
            'order_number' => 'PRD-P6-LATE', 'product_id' => $product->id, 'quantity' => 5,
            'status' => 'in_progress', 'due_date' => now()->subDays(4)->format('Y-m-d'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->upsert(
            ['key' => 'eod_delivery',
             'value' => json_encode(['email_enabled' => true, 'email_recipients' => ['owner@sonalux.test']]),
             'updated_at' => now()],
            ['key'], ['value', 'updated_at'],
        );

        $this->artisan('insights:digest --force')
            ->expectsOutputToContain('Sent to owner@sonalux.test')
            ->assertSuccessful();

        $this->assertSame(now()->toDateString(),
            DB::table('system_settings')->where('key', 'insights_digest_last_sent')->value('value'));

        // Second run the same day without --force: guarded, not re-sent.
        $this->artisan('insights:digest')
            ->expectsOutputToContain('already sent today')
            ->assertSuccessful();
    }

    public function test_digest_skips_when_nothing_needs_attention(): void
    {
        DB::table('system_settings')->upsert(
            ['key' => 'eod_delivery',
             'value' => json_encode(['email_enabled' => true, 'email_recipients' => ['owner@sonalux.test']]),
             'updated_at' => now()],
            ['key'], ['value', 'updated_at'],
        );

        $this->artisan('insights:digest --force')
            ->expectsOutputToContain('Nothing needs attention')
            ->assertSuccessful();
    }
}
