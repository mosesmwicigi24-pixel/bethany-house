<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An empty outlet assignment means NOTHING, never everything.
 *
 * PosController::authoriseOutletAccess guarded with
 * `$assigned->isNotEmpty() && !$assigned->contains($outletId)`, so a non-admin
 * with no outlet attached passed every check at every outlet rather than
 * failing them all. The trait that does this correctly elsewhere —
 * ScopesToAssignedOutlets — documents the rule it was breaking:
 *
 *   "An EMPTY assignment array means 'nothing', never 'everything'."
 *
 * Twenty existing tests relied on the fall-open: they created a POS actor and
 * never attached an outlet, because it did not matter. Their setup is now
 * complete rather than their assertions weakened.
 */
class PosOutletScopeTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions = ['pos.access']): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function posOrderAt(Outlet $outlet): Order
    {
        return Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id]);
    }

    public function test_a_user_with_no_outlet_is_refused_rather_than_admitted(): void
    {
        $this->actor();   // deliberately no outlet attached

        $order = $this->posOrderAt(Outlet::factory()->create());

        $this->getJson("/api/v1/admin/pos/sales/{$order->id}")
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'Your account is not assigned to an outlet. Ask an administrator to assign one before using the till.',
            ]);
    }

    public function test_a_user_assigned_to_the_outlet_gets_through(): void
    {
        $user   = $this->actor();
        $outlet = Outlet::factory()->create();
        $user->outlets()->attach($outlet->id);

        $this->getJson("/api/v1/admin/pos/sales/{$this->posOrderAt($outlet)->id}")->assertOk();
    }

    public function test_a_user_assigned_elsewhere_is_refused(): void
    {
        $user = $this->actor();
        $user->outlets()->attach(Outlet::factory()->create()->id);

        $order = $this->posOrderAt(Outlet::factory()->create());

        $this->getJson("/api/v1/admin/pos/sales/{$order->id}")
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'You do not have access to this outlet.']);
    }

    /**
     * The change must not reach administrators. isAdminUser() returns before
     * any assignment is read, and admins routinely have no outlet attached —
     * locking them out of the till would be a far worse failure than the one
     * being fixed.
     */
    public function test_an_admin_with_no_outlet_is_unaffected(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $order = $this->posOrderAt(Outlet::factory()->create());

        $this->getJson("/api/v1/admin/pos/sales/{$order->id}")->assertOk();
    }

    public function test_a_super_admin_with_no_outlet_is_unaffected(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $order = $this->posOrderAt(Outlet::factory()->create());

        $this->getJson("/api/v1/admin/pos/sales/{$order->id}")->assertOk();
    }
}
