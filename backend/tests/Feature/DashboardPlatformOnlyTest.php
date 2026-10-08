<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D: the system administrator runs the platform — accounts,
 * outlets, roles — not the business. Their dashboard carries no order,
 * product, stock, shipment, production or money counts, and neither do their
 * sidebar badges.
 */
class DashboardPlatformOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const BUSINESS_KEYS = [
        'total_orders', 'pending_orders', 'today_orders', 'today_sales', 'pending_payment_approvals',
        'total_products', 'low_stock_products', 'shipments_in_transit', 'shipments_pending_dispatch',
        'production_draft', 'production_queue', 'production_in_progress', 'production_qc_pending',
        'production_overdue', 'customers',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');   // the Phase 2 catalogue (RoleCatalogueV2Test::SPEC)
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u->fresh());
        return $u;
    }

    public function test_the_system_admin_sees_platform_counts_only(): void
    {
        Order::factory()->count(3)->create();
        $this->as('system_admin');

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');

        $this->assertTrue($stats['platform_only'] ?? false);
        $this->assertArrayHasKey('total_users', $stats);
        $this->assertArrayHasKey('staff_users', $stats);
        foreach (self::BUSINESS_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $stats, "system_admin dashboard still carries {$key}");
        }

        $badges = $this->getJson('/api/v1/admin/sidebar-badges')->assertOk()->json();
        foreach (['orders', 'approvals', 'purchase_orders', 'returns', 'low_stock', 'stock_adjustments'] as $key) {
            $this->assertArrayNotHasKey($key, $badges, "system_admin badges still carry {$key}");
        }
    }

    public function test_the_business_roles_keep_their_dashboard(): void
    {
        Order::factory()->count(2)->create();
        $this->as('admin');

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
        $this->assertFalse($stats['platform_only'] ?? false);
        $this->assertSame(2, $stats['total_orders']);
        $this->assertArrayHasKey('orders', $this->getJson('/api/v1/admin/sidebar-badges')->assertOk()->json());
    }
}
