<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A cashier's dashboard shows their own takings, and says so.
 *
 * Two things went wrong when pos_clerk was narrowed. today_sales was behind
 * reports.view, which cashiers do not hold, so the card rendered blank — a
 * screen that reads as a broken till rather than a deliberate boundary. And
 * today_orders, now counting only their own sales, still sat under the label
 * "Today's Orders", which a cashier would reasonably read as the shop's.
 *
 * The fix rests on something that was not true before: Order carries a viewer
 * scope, so for a narrowed role the same sum IS their own money. There is no
 * reason to hide it from them. A tailor — unscoped for orders, and with no
 * business reading group revenue — must still be refused.
 */
class ScopedDashboardTest extends TestCase
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
        $user->outlets()->attach(Outlet::factory()->create()->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function paidSaleBy(User $who, float $amount): Order
    {
        return Order::factory()->create([
            'order_type'     => 'pos',
            'created_by'     => $who->id,
            'payment_status' => 'paid',
            'total_amount'   => $amount,
            'created_at'     => now(),
        ]);
    }

    public function test_a_cashier_sees_their_own_takings_not_the_shops(): void
    {
        $clerk = $this->actAs('pos_clerk');
        $this->paidSaleBy($clerk, 1500);
        $this->paidSaleBy(User::factory()->create(), 90000);   // someone else's

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');

        $this->assertSame('own', $stats['scope']);
        $this->assertEqualsWithDelta(1500.0, (float) $stats['today_sales'], 0.001,
            'the figure must be the cashier\'s own, not the group\'s 91,500');
        $this->assertSame(1, $stats['today_orders']);
    }

    public function test_a_tailor_still_gets_no_revenue_figure(): void
    {
        // Unscoped for orders and holds no reports.view, so today_sales would
        // be the whole business — exactly what the gate exists to stop.
        $this->actAs('tailor');
        $this->paidSaleBy(User::factory()->create(), 90000);

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');

        $this->assertSame('all', $stats['scope']);
        $this->assertArrayNotHasKey('today_sales', $stats);
    }

    public function test_an_admin_still_sees_the_group_figure(): void
    {
        $admin = $this->actAs('admin');
        $this->paidSaleBy($admin, 1500);
        $this->paidSaleBy(User::factory()->create(), 8500);

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');

        $this->assertSame('all', $stats['scope']);
        $this->assertEqualsWithDelta(10000.0, (float) $stats['today_sales'], 0.001);
    }
}
