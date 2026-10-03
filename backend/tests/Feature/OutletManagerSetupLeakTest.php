<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 1C, item 9 — the "Setup leak".
 *
 * outlet_manager held settings.view only as a side effect: the dependency map
 * gave it to anyone with orders.set_shipping_fee, because the shipping-fee
 * modal loaded its method picker from /admin/shipping/methods (a Setup
 * endpoint). The same key also gated the EoD review screens. So an outlet
 * manager could read business settings, tax rates, currencies and payment
 * method configuration — Setup, which is the owner's.
 *
 * Now: the shipping-fee picker reads an orders-side endpoint gated by
 * orders.set_shipping_fee; EoD review has its own key, pos.eod_review
 * (admin, outlet_manager, finance_manager — finance reviews takings and has no
 * till); and a migration takes settings.view back from outlet_manager.
 */
class OutletManagerSetupLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function eodReport(): int
    {
        $outlet = Outlet::factory()->create();
        $clerk  = User::factory()->create();
        $registerId = DB::table('cash_registers')->insertGetId([
            'register_number' => 'REG-' . uniqid(), 'outlet_id' => $outlet->id,
            'register_name' => 'Till 1', 'status' => 'closed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cash_register_eod_reports')->insertGetId([
            'register_id' => $registerId, 'user_id' => $clerk->id, 'outlet_id' => $outlet->id,
            'report_date' => today(), 'sentiments' => 'Quiet day.', 'order_notes' => json_encode([]),
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shippingMethods(): void
    {
        $zone = DB::table('shipping_zones')->insertGetId(['name' => 'Kenya', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['Nairobi courier', true], ['Retired method', false]] as [$name, $active]) {
            DB::table('shipping_methods')->insert([
                'shipping_zone_id' => $zone, 'name' => $name, 'cost_type' => 'flat_rate', 'flat_rate' => 350,
                'is_active' => $active, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ── The grants ───────────────────────────────────────────────────────────

    public function test_outlet_manager_no_longer_holds_settings_view(): void
    {
        $this->assertFalse(Role::findByName('outlet_manager', 'sanctum')->hasPermissionTo('settings.view'));
    }

    public function test_eod_review_goes_to_admin_outlet_manager_and_finance(): void
    {
        $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'pos.eod_review'))
            ->pluck('name')->sort()->values()->all();

        // accountant joined in Phase 2: the ledger operator reconciles takings.
        $this->assertSame(['accountant', 'admin', 'finance_manager', 'outlet_manager'], $holders);
    }

    // ── Setup is closed to the outlet manager ───────────────────────────────

    public function test_outlet_manager_cannot_read_setup(): void
    {
        $this->actAs('outlet_manager');

        foreach ([
            '/api/v1/admin/settings',
            '/api/v1/admin/tax-rates',
            '/api/v1/admin/currencies-management',
            '/api/v1/admin/payment-methods-management',
            '/api/v1/admin/shipping/methods',
        ] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    // ── …and keeps the work that depended on it ─────────────────────────────

    public function test_outlet_manager_still_sets_an_orders_shipping_fee(): void
    {
        $this->shippingMethods();
        $manager = $this->actAs('outlet_manager');
        // Phase 4A: a manager works the orders of their own shop.
        $shop = Outlet::factory()->create();
        $manager->outlets()->attach($shop->id);

        $methods = $this->getJson('/api/v1/admin/orders/shipping-methods')->assertOk()->json('data');
        $this->assertSame(['Nairobi courier'], collect($methods)->pluck('name')->all(), 'active methods only');
        $this->assertArrayHasKey('flat_rate', $methods[0]);
        $this->assertArrayHasKey('cost_type', $methods[0]);

        $order = Order::factory()->create(['shipping_amount' => 0, 'outlet_id' => $shop->id]);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/shipping-fee", ['amount' => 350, 'note' => 'Nairobi courier'])
            ->assertOk();
        $this->assertEquals(350.0, (float) $order->fresh()->shipping_amount);
    }

    public function test_the_shipping_methods_endpoint_needs_the_shipping_fee_permission(): void
    {
        $this->actAs('pos_clerk');   // orders.view, no set_shipping_fee

        $this->getJson('/api/v1/admin/orders/shipping-methods')->assertForbidden();
    }

    public function test_outlet_manager_still_reviews_eod_reports(): void
    {
        $id = $this->eodReport();
        $this->actAs('outlet_manager');

        $this->getJson('/api/v1/admin/pos/reports/eod-admin')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/admin/pos/reports/eod-admin/{$id}")->assertOk();
        $this->postJson("/api/v1/admin/pos/reports/eod/{$id}/comments", ['body' => 'Why the variance?'])->assertCreated();
        $this->postJson("/api/v1/admin/pos/reports/eod-admin/{$id}/acknowledge")->assertOk();
        $this->assertNotNull(DB::table('cash_register_eod_reports')->where('id', $id)->value('acknowledged_at'));
    }

    public function test_finance_reviews_eod_reports_without_a_till(): void
    {
        $id = $this->eodReport();
        $fm = $this->actAs('finance_manager');
        $this->assertFalse($fm->can('pos.access'), 'premise: finance has no till');

        $list = $this->getJson('/api/v1/admin/pos/reports/eod-admin')->assertOk();
        $this->assertSame($id, $list->json('data.0.id'));
        $this->assertNotEmpty($list->json('outlets'), 'the outlet filter is served with the list');
        $this->getJson("/api/v1/admin/pos/reports/eod-admin/{$id}")->assertOk();
        $this->postJson("/api/v1/admin/pos/reports/eod-admin/{$id}/acknowledge")->assertOk();
    }

    public function test_a_cashier_cannot_review_other_peoples_reports(): void
    {
        $id = $this->eodReport();
        $this->actAs('pos_clerk');

        $this->getJson('/api/v1/admin/pos/reports/eod-admin')->assertForbidden();
        $this->getJson("/api/v1/admin/pos/reports/eod-admin/{$id}")->assertForbidden();
        $this->postJson("/api/v1/admin/pos/reports/eod-admin/{$id}/acknowledge")->assertForbidden();
    }

    // ── The migration, against production's shape ────────────────────────────

    public function test_the_migration_revokes_settings_view_and_grants_eod_review(): void
    {
        // Production today: outlet_manager holds settings.view; pos.eod_review
        // does not exist yet.
        $om = Role::findByName('outlet_manager', 'sanctum');
        $om->givePermissionTo('settings.view');
        DB::table('role_has_permissions')
            ->where('permission_id', Permission::findByName('pos.eod_review', 'sanctum')->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($om->fresh()->hasPermissionTo('settings.view'));

        $migration = require database_path('migrations/2026_10_03_300002_outlet_manager_leaves_setup_and_eod_review.php');
        $migration->up();

        $this->assertFalse(Role::findByName('outlet_manager', 'sanctum')->hasPermissionTo('settings.view'));
        foreach (['admin', 'outlet_manager', 'finance_manager'] as $role) {
            $this->assertTrue(Role::findByName($role, 'sanctum')->hasPermissionTo('pos.eod_review'), $role);
        }
        $this->assertTrue(Role::findByName('admin', 'sanctum')->hasPermissionTo('settings.view'), 'admin keeps Setup');
    }

    public function test_the_migration_rolls_back_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_03_300002_outlet_manager_leaves_setup_and_eod_review.php');
        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(Role::findByName('outlet_manager', 'sanctum')->hasPermissionTo('settings.view'));
        $this->assertNull(Permission::where('name', 'pos.eod_review')->first());

        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Role::findByName('outlet_manager', 'sanctum')->hasPermissionTo('settings.view'));
        $this->assertTrue(Role::findByName('finance_manager', 'sanctum')->hasPermissionTo('pos.eod_review'));
    }
}
