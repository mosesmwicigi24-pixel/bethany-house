<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DataScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 4A, the resolver: outlet managers are bounded to their outlets, and a
 * capability nobody granted resolves to nothing.
 *
 * Two changes to the one rule every scoped query runs through:
 *
 *   1. outlet_manager ships at data_scope 'outlet' (SyncPermissions::ROLE_SCOPES
 *      and a migration, because deploys run migrate, not permission:sync).
 *      The outlets are the outlet_user pivot; an empty assignment is nothing.
 *   2. A STAFF member whose roles do not grant the capability resolves to
 *      DataScope::None. It used to be All — so any route that forgot its
 *      permission check handed the whole table to the role that lacked it.
 *
 * And the owner's standing decision (2026-10-03): reports stay business-wide
 * for anyone who may read them. The scope must not leak into /admin/reports.
 */
class OutletDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $mine;
    private Outlet $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->mine   = Outlet::factory()->create(['name' => 'Nairobi']);
        $this->theirs = Outlet::factory()->create(['name' => 'Mombasa']);
    }

    private function actAs(User $user): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        Sanctum::actingAs($user);
        $this->actingAs($user);

        return $user;
    }

    private function withRole(string $role, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id);
        }

        return $this->actAs($user);
    }

    private function orderAt(?Outlet $outlet, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type'     => 'pos',
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => 'KES',
            'outlet_id'      => $outlet?->id,
        ], $extra));
    }

    // ── The catalogue ────────────────────────────────────────────────────────

    public function test_outlet_manager_ships_scoped_to_outlet(): void
    {
        $this->assertSame('outlet', DB::table('roles')->where('name', 'outlet_manager')->value('data_scope'));
    }

    public function test_the_migration_narrows_an_existing_outlet_manager_role(): void
    {
        // Production runs migrate, never permission:sync — a catalogue-only
        // change would never reach it.
        DB::table('roles')->where('name', 'outlet_manager')->update(['data_scope' => 'all']);

        $migration = require database_path('migrations/2026_10_03_410001_scope_outlet_manager_to_assigned_outlets.php');
        $migration->up();

        $this->assertSame('outlet', DB::table('roles')->where('name', 'outlet_manager')->value('data_scope'));
    }

    // ── Outlet, from the pivot ───────────────────────────────────────────────

    public function test_an_outlet_manager_resolves_to_outlet_and_its_pivot_outlets(): void
    {
        $om = $this->withRole('outlet_manager', $this->mine);

        $this->assertSame(DataScope::Outlet, DataScopeResolver::for($om, 'orders.view'));
        $this->assertSame([$this->mine->id], DataScopeResolver::outletIds($om));
    }

    public function test_an_outlet_manager_with_no_assignment_sees_nothing(): void
    {
        $this->withRole('outlet_manager');
        $this->orderAt($this->mine);

        $this->assertSame(0, Order::count());
    }

    // ── No granting role → nothing ───────────────────────────────────────────

    public function test_a_staff_member_whose_roles_do_not_grant_it_resolves_to_none(): void
    {
        // procurement_officer holds no orders.view. The resolver said All.
        $buyer = $this->withRole('procurement_officer');
        $this->orderAt($this->mine);

        $this->assertSame(DataScope::None, DataScopeResolver::for($buyer, 'orders.view'));
        $this->assertSame(0, Order::count(), 'a role without orders.view must not see the order book');
    }

    public function test_a_direct_grant_still_resolves_to_all(): void
    {
        // Granted on the user, not through a role: a deliberate, named act in
        // the Roles screen. It carries no scope, so it is not narrowed.
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        $user = $this->actAs($user);

        $this->assertSame(DataScope::All, DataScopeResolver::for($user, 'orders.view'));
    }

    public function test_a_staff_login_with_no_role_keeps_the_old_answer(): void
    {
        // Every right it has is a hand grant on the user: there is no role,
        // so no data_scope to read. Narrowing it would break service
        // accounts set up by hand; the None rule is about ROLES that lack it.
        $user = $this->actAs(User::factory()->create());

        $this->assertSame(DataScope::All, DataScopeResolver::for($user, 'orders.view'));
    }

    public function test_a_storefront_customer_is_not_narrowed(): void
    {
        // Customers hold no roles; their own endpoints filter by user_id. They
        // are not staff with a missing grant.
        $customer = User::factory()->create(['user_type' => 'customer']);

        $this->assertSame(DataScope::All, DataScopeResolver::for($customer, 'orders.view'));
    }

    // ── Reports stay business-wide (owner, 2026-10-03) ───────────────────────

    public function test_an_outlet_scoped_manager_still_reads_the_whole_business_in_reports(): void
    {
        $this->orderAt($this->mine, ['total_amount' => 1_000, 'subtotal' => 1_000]);
        $this->orderAt($this->theirs, ['total_amount' => 9_000, 'subtotal' => 9_000]);

        $om = $this->withRole('outlet_manager', $this->mine);
        $this->assertSame(DataScope::Outlet, DataScopeResolver::for($om, 'orders.view'));

        $window = 'start_date=2020-01-01&end_date=2030-12-31';
        $summary = $this->getJson("/api/v1/admin/reports/sales/summary?{$window}")->assertOk();

        $this->assertSame(10_000.0, (float) $summary->json('summary.total_revenue'),
            'reports are business-wide: both outlets, not the manager\'s one');

        // …while the same manager's order list is bounded to the one outlet.
        $ids = collect($this->getJson('/api/v1/admin/orders')->assertOk()->json('data'))->pluck('outlet_id')->unique();
        $this->assertSame([$this->mine->id], $ids->values()->all());
    }

    public function test_every_report_page_route_is_business_wide(): void
    {
        // Phase 3A split the one reports group into a group per page; the
        // business-wide switch has to ride on every one of them (and on the
        // PDFs, Storefront Insights and the Signals feeds) or a page quietly
        // drops to the manager's outlet while its neighbours do not.
        $pageRoutes = 0;
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $isReportPage = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'report.page:'));
            if (! $isReportPage) {
                continue;
            }
            $pageRoutes++;
            $this->assertContains('report.business_wide', $middleware,
                "{$route->uri()} names a report page but is not business-wide");
        }

        $this->assertGreaterThan(20, $pageRoutes, 'the report routes were found');
    }
}
