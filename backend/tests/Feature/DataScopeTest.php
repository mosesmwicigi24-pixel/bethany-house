<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DataScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The scope machinery: proven inert at 'all', and proven to bite when narrowed.
 *
 * Every role ships at DataScope::All, so the whole of this PR changes nothing
 * in production. That is deliberate — the machinery lands and is shown to be a
 * no-op, and narrowing a role afterwards is a one-line change whose effect
 * shows up here rather than at a counter.
 *
 * The cases below are the contract that one-line change will rely on.
 */
class DataScopeTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name, string $scope, array $permissions = ['orders.view']): Role
    {
        $role = Role::findOrCreate($name, 'sanctum');
        foreach ($permissions as $p) {
            $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        DB::table('roles')->where('id', $role->id)->update(['data_scope' => $scope]);

        return $role->fresh();
    }

    private function actAs(User $user): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());
        $this->actingAs($user->fresh());

        return $user;
    }

    private function orderBy(?User $creator, ?Outlet $outlet = null): Order
    {
        return Order::factory()->create([
            'order_type' => 'pos',
            'status'     => 'confirmed',
            'created_by' => $creator?->id,
            'outlet_id'  => $outlet?->id,
        ]);
    }

    // ── Inert at 'all' ───────────────────────────────────────────────────────

    public function test_a_role_at_all_sees_every_order(): void
    {
        $clerk = User::factory()->create();
        $clerk->assignRole($this->role('clerk_all', 'all'));
        $this->actAs($clerk);

        $this->orderBy($clerk);
        $this->orderBy(User::factory()->create());

        $this->assertSame(2, Order::count());
    }

    public function test_the_default_for_a_new_role_is_all(): void
    {
        $role = Role::findOrCreate('brand_new', 'sanctum');

        $this->assertSame('all', DB::table('roles')->where('id', $role->id)->value('data_scope'));
    }

    // ── Bites at 'own' ───────────────────────────────────────────────────────

    public function test_a_role_at_own_sees_only_its_own_orders(): void
    {
        $clerk = User::factory()->create();
        $clerk->assignRole($this->role('clerk_own', 'own'));
        $this->actAs($clerk);

        $mine   = $this->orderBy($clerk);
        $theirs = $this->orderBy(User::factory()->create());

        $this->assertSame([$mine->id], Order::pluck('id')->all());
        $this->assertNull(Order::find($theirs->id), 'the detail lookup is bounded too — this is the IDOR');
        $this->assertSame(2, Order::withoutViewerScope()->count(), 'the escape hatch still sees everything');
    }

    // ── Bites at 'outlet' ────────────────────────────────────────────────────

    public function test_a_role_at_outlet_sees_only_its_assigned_outlets(): void
    {
        $mine      = Outlet::factory()->create();
        $elsewhere = Outlet::factory()->create();

        $manager = User::factory()->create();
        $manager->assignRole($this->role('manager_outlet', 'outlet'));
        $manager->outlets()->attach($mine->id);
        $this->actAs($manager);

        $here  = $this->orderBy(User::factory()->create(), $mine);
        $there = $this->orderBy(User::factory()->create(), $elsewhere);

        $this->assertSame([$here->id], Order::pluck('id')->all());
        $this->assertNull(Order::find($there->id));
    }

    public function test_outlet_scope_with_no_assignment_sees_nothing(): void
    {
        // An empty assignment means nothing, never everything — the same rule
        // the POS outlet guard had inverted.
        $manager = User::factory()->create();
        $manager->assignRole($this->role('manager_unassigned', 'outlet'));
        $this->actAs($manager);

        $this->orderBy(User::factory()->create(), Outlet::factory()->create());

        $this->assertSame(0, Order::count());
    }

    // ── Multi-role ───────────────────────────────────────────────────────────

    public function test_a_wide_scope_in_another_domain_does_not_widen_this_one(): void
    {
        // The clause that matters. pos_clerk is scoped to own and grants
        // orders.view; procurement_officer is scoped to all and does NOT.
        // The buyer's wide view must not reach into the clerk's sales.
        $user = User::factory()->create();
        $user->assignRole($this->role('clerk_narrow', 'own', ['orders.view']));
        $user->assignRole($this->role('buyer_wide', 'all', ['procurement.view']));
        $this->actAs($user);

        $mine = $this->orderBy($user);
        $this->orderBy(User::factory()->create());

        $this->assertSame([$mine->id], Order::pluck('id')->all());
    }

    public function test_two_roles_that_both_grant_it_resolve_to_the_wider(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('clerk_own2', 'own', ['orders.view']));
        $user->assignRole($this->role('supervisor_all', 'all', ['orders.view']));
        $this->actAs($user);

        $this->orderBy($user);
        $this->orderBy(User::factory()->create());

        $this->assertSame(2, Order::count(), 'holding a wider role for the same capability widens the view');
    }

    // ── The paths that must never be narrowed ────────────────────────────────

    public function test_code_running_without_a_session_is_not_scoped(): void
    {
        // Queue jobs, webhooks, scheduled commands and the storefront's guest
        // paths. Narrowing these would silently break background work.
        $this->orderBy(User::factory()->create());
        $this->orderBy(User::factory()->create());

        $this->assertNull(auth()->user());
        $this->assertSame(2, Order::count());
    }

    public function test_super_admin_is_not_scoped(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole($this->role('super_admin', 'own'));   // even at 'own'
        $this->actAs($admin);

        $this->orderBy(User::factory()->create());

        $this->assertSame(1, Order::count(), 'the Gate::before bypass covers scope too');
    }

    // ── The resolver in isolation ────────────────────────────────────────────

    public function test_the_resolver_returns_all_for_a_direct_permission_grant(): void
    {
        // Held directly rather than through a role, which the Roles UI allows.
        // A direct grant carries no scope, so there is no narrower answer.
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(DataScope::All, DataScopeResolver::for($user->fresh(), 'orders.view'));
    }

    public function test_widest_prefers_the_broader_scope(): void
    {
        $this->assertSame(DataScope::All, DataScope::widest([DataScope::Own, DataScope::All]));
        $this->assertSame(DataScope::Outlet, DataScope::widest([DataScope::Own, DataScope::Outlet]));
        $this->assertSame(DataScope::Own, DataScope::widest([DataScope::Own]));
    }
}
