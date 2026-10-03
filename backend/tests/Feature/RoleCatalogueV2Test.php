<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Services\PermissionDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening, Phase 2 — the role catalogue the owner approved
 * (bh-tools/phase2_target.md, from the Role Hardening Plan §3, §6, §7, §15).
 *
 * SPEC below is written out by hand from that document, NOT derived from
 * SyncPermissions, so the comparison is between the code and the decision. It
 * is checked in both directions: nothing missing, nothing extra.
 *
 * BEFORE is the shape every role had before Phase 2 (what permission:sync
 * produced on feat/roles-p1c), plus production's legacy-named grants on
 * system_admin and accountant. It is what the migration starts from in
 * production and what down() must put back.
 */
class RoleCatalogueV2Test extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_03_300003_role_catalogue_v2.php';

    private const SPEC = [
        'super_admin' => [],
        'system_admin' => [
            'attendance.manage', 'attendance.view_team', 'dashboard.view', 'notifications.view',
            'outlets.create', 'outlets.edit', 'outlets.view', 'profile.edit', 'profile.view',
            'roles.view', 'setup.technical', 'users.create', 'users.edit', 'users.view',
        ],
        'accountant' => [
            'dashboard.view', 'expenses.create', 'expenses.edit', 'expenses.export', 'expenses.view',
            'inventory.view', 'notifications.view', 'orders.view', 'payments.transactions', 'payments.view',
            'pos.eod_review', 'procurement.view', 'products.view', 'products.view_cost', 'profile.edit',
            'profile.view', 'receivables.view',
        ],
        'admin' => [
            'attendance.view_team', 'customers.create', 'customers.create_without_email', 'customers.delete',
            'customers.edit', 'customers.insights', 'customers.invite', 'customers.view', 'dashboard.view',
            'expenses.view', 'intelligence.view', 'inventory.view', 'marketing.manage', 'marketing.view',
            'notifications.view',
            'orders.authorize_dispatch', 'orders.cancel', 'orders.create', 'orders.edit', 'orders.edit_items',
            'orders.manage_returns', 'orders.reduce_shipping_fee', 'orders.set_deposit', 'orders.set_shipping_fee',
            'orders.view', 'outlets.view',
            'payments.record', 'payments.transactions', 'payments.upload_proof', 'payments.view',
            'pos.access', 'pos.eod_review', 'procurement.view',
            'production.approve_qc', 'production.configure_auto_assignees', 'production.confirm_order',
            'production.manage_assignees', 'production.raise_order', 'production.submit_qc', 'production.view',
            'production.view_bom', 'production.worker',
            'products.create', 'products.delete', 'products.edit', 'products.export', 'products.import',
            'products.view', 'products.view_cost', 'profile.edit', 'profile.view',
            'quotations.create', 'quotations.delete', 'quotations.issue', 'quotations.view',
            'receivables.view', 'reports.export', 'reports.financial', 'reports.view', 'roles.view',
            'settings.view', 'shipment.create', 'shipment.edit', 'shipment.manage_tracking', 'shipment.view',
            'users.view',
        ],
        'finance_manager' => [
            'customers.view', 'dashboard.view', 'expenses.approve', 'expenses.budgets', 'expenses.export',
            'expenses.view', 'inventory.view', 'notifications.view', 'orders.view',
            'payments.approve_international', 'payments.reassign', 'payments.transactions', 'payments.view',
            'payments.void', 'pos.eod_review', 'production.view_bom', 'products.view', 'products.view_cost',
            'profile.edit', 'profile.view', 'receivables.view', 'reports.export', 'reports.financial',
            'reports.view',
        ],
        'procurement_manager' => [
            'bom.edit', 'dashboard.view', 'expenses.view', 'inventory.adjust', 'inventory.approve',
            'inventory.transfer', 'inventory.view', 'notifications.view', 'payments.view', 'procurement.approve',
            'procurement.create', 'procurement.receive', 'procurement.view', 'production.view_bom',
            'products.view', 'products.view_cost', 'profile.edit', 'profile.view', 'reports.export',
            'reports.view',
        ],
        'procurement_officer' => [
            'dashboard.view', 'inventory.adjust', 'inventory.transfer', 'inventory.view', 'notifications.view',
            'procurement.create', 'procurement.receive', 'procurement.view', 'production.view_bom',
            'products.view', 'products.view_cost', 'profile.edit', 'profile.view', 'reports.view',
        ],
        'outlet_manager' => [
            'attendance.manage', 'attendance.view_team', 'customers.create', 'customers.create_without_email',
            'customers.edit', 'customers.insights', 'customers.view', 'dashboard.view', 'expenses.create',
            'expenses.edit', 'expenses.view', 'inventory.adjust', 'inventory.transfer', 'inventory.view',
            'notifications.view', 'orders.create', 'orders.edit', 'orders.manage_returns', 'orders.set_deposit',
            'orders.set_shipping_fee', 'orders.view', 'outlets.view', 'payments.record', 'payments.upload_proof',
            'payments.view', 'pos.access', 'pos.cash_management', 'pos.close_register', 'pos.discount',
            'pos.discount_override', 'pos.eod_review', 'pos.open_register', 'pos.returns', 'pos.void',
            'production.approve_qc', 'production.confirm_order', 'production.manage_assignees',
            'production.raise_order', 'production.submit_qc', 'production.view', 'production.view_bom',
            'products.view', 'profile.edit', 'profile.view', 'quotations.create', 'quotations.issue',
            'quotations.view', 'receivables.view', 'reports.view', 'shipment.create', 'shipment.manage_tracking',
            'shipment.view',
        ],
        'pos_clerk' => [
            'customers.create', 'customers.create_without_email', 'customers.view', 'dashboard.view',
            'notifications.view', 'orders.create', 'orders.view', 'payments.record', 'payments.upload_proof',
            'payments.view', 'pos.access', 'pos.close_register', 'pos.discount', 'pos.open_register',
            'pos.returns', 'pos.void', 'production.raise_order', 'production.view', 'products.view',
            'profile.edit', 'profile.view', 'quotations.create', 'quotations.view',
        ],
        'tailor' => [
            'dashboard.view', 'notifications.view', 'production.submit_qc', 'production.view',
            'production.worker', 'profile.edit', 'profile.view',
        ],
    ];

    /**
     * Phase 3A (one permission per report page) changes only the report
     * grants, by its own migration (2026_10_03_300004). SPEC above stays the
     * shape THIS migration produces; spec() is SPEC with 3A applied — what
     * permission:sync produces today. Per-page access is asserted in
     * ReportPageAccessTest.
     */
    private const PHASE_3A = [
        'admin' => [
            'remove' => ['reports.financial', 'reports.view'],
            'add'    => ['reports.executive', 'reports.sales', 'reports.customers', 'reports.production',
                'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
                'reports.data_quality', 'reports.explorer'],
        ],
        'finance_manager' => [
            'remove' => ['reports.view'],
            'add'    => ['reports.executive', 'reports.sales', 'reports.production', 'reports.inventory',
                'reports.procurement', 'reports.performance', 'reports.signals', 'reports.data_quality',
                'reports.explorer'],
        ],
        'accountant' => [
            'remove' => [],
            'add'    => ['reports.sales', 'reports.financial', 'reports.production', 'reports.inventory',
                'reports.procurement', 'reports.performance'],
        ],
        'outlet_manager' => [
            'remove' => ['reports.view'],
            'add'    => ['reports.sales', 'reports.production', 'reports.inventory', 'reports.performance'],
        ],
        'procurement_manager' => [
            'remove' => ['reports.view', 'reports.export'],
            'add'    => ['reports.production', 'reports.inventory', 'reports.procurement', 'reports.export_supply'],
        ],
        'procurement_officer' => [
            'remove' => ['reports.view'],
            'add'    => ['reports.production', 'reports.inventory'],
        ],
    ];

    private const MIGRATION_3A = '2026_10_03_300004_report_page_permissions.php';

    /** @return array<string,list<string>> SPEC with Phase 3A's report grants applied. */
    private function spec(): array
    {
        $out = self::SPEC;
        foreach (self::PHASE_3A as $role => ['remove' => $remove, 'add' => $add]) {
            $out[$role] = array_values(array_unique(array_merge(array_diff($out[$role], $remove), $add)));
        }

        return $out;
    }

    private function migration3a(): object
    {
        return require database_path('migrations/' . self::MIGRATION_3A);
    }

    /**
     * ONE expected shape for every later phase. SPEC is the Phase 2 decision and
     * spec() is SPEC with Phase 3A applied; the grants below are added on top by
     * later phases' own migrations and by permission:sync (which runs after the
     * migrations on every container start) — never by the Phase 2 or 3A
     * migration. After sync a role holds spec() plus these, so every assertion
     * that runs after a sync compares against withLater(...).
     *
     * One list, grouped by the phase (and migration) that grants each key, so a
     * new phase adds its grants here and nowhere else.
     */
    private const LATER_GRANTS = [
        'admin' => [
            // 3C (2026_10_03_530001): cost edits and the customer pricing rate are proposals.
            'products.edit_cost', 'settings.pricing_rate_propose',
            // 4B part 1 (2026_10_03_440002): every till, read-only.
            'pos.tills_view_all',
        ],
        'finance_manager' => [
            // 3B (2026_10_03_520002): signs the finance band.
            'approvals.finance_sign',
            // 3C (2026_10_03_530001): proposes tax, reporting FX and settlement changes.
            'settings.financial_propose',
            // 4B part 1 (2026_10_03_440002): reconciles and corrects finalized tills.
            'pos.reconcile', 'pos.till_correction', 'pos.tills_view_all',
        ],
        'accountant' => [
            // 3B (2026_10_03_520002): asks for payment voids and moves; signs nothing.
            'payments.request_reassign', 'payments.request_void',
            // 4B part 1 (2026_10_03_440002): reconciles tills.
            'pos.reconcile', 'pos.tills_view_all',
        ],
        'outlet_manager' => [
            // 4B part 1 (2026_10_03_440002): verifies a clerk's blind count.
            'pos.till_verify',
            // 4B part 2 (2026_10_03_860002): the band key for till voids and
            // refunds — a key no clerk holds.
            'pos.approve_reversal',
        ],
        'procurement_manager' => [
            // 3C (2026_10_03_530001): proposes cost changes.
            'products.edit_cost',
        ],
    ];

    /**
     * @param array<string,list<string>> $shape
     * @return array<string,list<string>> $shape plus what later phases' migrations and sync add.
     */
    private static function withLater(array $shape): array
    {
        foreach (self::LATER_GRANTS as $role => $perms) {
            $shape[$role] = array_values(array_unique(array_merge($shape[$role] ?? [], $perms)));
        }

        return $shape;
    }

    /** Production's system_admin / accountant held the legacy vocabulary (2026-10-02 read). */
    private const LEGACY = [
        'system_admin' => ['assign roles', 'create users', 'manage settings', 'edit orders', 'create products'],
        'accountant'   => ['view inventory', 'view orders'],
    ];

    private const BEFORE = [
        'super_admin' => [],
        'admin' => [
            'attendance.manage', 'attendance.view_team', 'customers.create', 'customers.create_without_email', 'customers.delete',
            'customers.edit', 'customers.insights', 'customers.invite', 'customers.view', 'dashboard.view',
            'expenses.approve', 'expenses.budgets', 'expenses.create', 'expenses.delete', 'expenses.edit',
            'expenses.export', 'expenses.view', 'intelligence.view', 'inventory.adjust', 'inventory.approve',
            'inventory.transfer', 'inventory.view', 'marketing.manage', 'marketing.view', 'notifications.view',
            'orders.authorize_dispatch', 'orders.cancel', 'orders.create', 'orders.edit', 'orders.edit_items',
            'orders.manage_returns', 'orders.reduce_shipping_fee', 'orders.refund', 'orders.set_deposit', 'orders.set_shipping_fee',
            'orders.view', 'outlets.create', 'outlets.delete', 'outlets.edit', 'outlets.view',
            'payments.approve_international', 'payments.reassign', 'payments.record', 'payments.transactions', 'payments.upload_proof',
            'payments.view', 'payments.void', 'pos.access', 'pos.cash_management', 'pos.close_register',
            'pos.discount', 'pos.discount_override', 'pos.eod_review', 'pos.open_register', 'pos.returns',
            'pos.void', 'procurement.approve', 'procurement.create', 'procurement.receive', 'procurement.view',
            'production.approve_qc', 'production.configure_auto_assignees', 'production.confirm_order', 'production.manage_assignees', 'production.raise_order',
            'production.submit_qc', 'production.view', 'production.view_bom', 'production.worker', 'products.create',
            'products.delete', 'products.edit', 'products.export', 'products.import', 'products.view',
            'products.view_cost', 'quotations.create', 'quotations.delete', 'quotations.issue', 'quotations.view',
            'receivables.view', 'reports.export', 'reports.financial', 'reports.view', 'roles.edit',
            'roles.view', 'settings.edit', 'settings.view', 'shipment.create', 'shipment.edit',
            'shipment.manage_tracking', 'shipment.view', 'users.create', 'users.delete', 'users.edit',
            'users.view',
        ],
        'finance_manager' => [
            'dashboard.view', 'expenses.approve', 'expenses.budgets', 'expenses.create', 'expenses.delete',
            'expenses.edit', 'expenses.export', 'expenses.view', 'notifications.view', 'orders.view',
            'payments.approve_international', 'payments.reassign', 'payments.transactions', 'payments.view', 'payments.void',
            'pos.eod_review', 'products.view_cost', 'profile.edit', 'profile.view', 'receivables.view',
            'reports.export', 'reports.financial', 'reports.view',
        ],
        'outlet_manager' => [
            'attendance.manage', 'attendance.view_team', 'customers.create', 'customers.create_without_email', 'customers.edit',
            'customers.insights', 'customers.view', 'dashboard.view', 'expenses.create', 'expenses.delete',
            'expenses.edit', 'expenses.view', 'inventory.adjust', 'inventory.approve', 'inventory.transfer',
            'inventory.view', 'notifications.view', 'orders.create', 'orders.edit', 'orders.manage_returns',
            'orders.set_deposit', 'orders.set_shipping_fee', 'orders.view', 'outlets.edit', 'outlets.view',
            'payments.record', 'payments.upload_proof', 'payments.view', 'pos.access', 'pos.cash_management',
            'pos.close_register', 'pos.discount', 'pos.discount_override', 'pos.eod_review', 'pos.open_register',
            'pos.returns', 'pos.void', 'production.approve_qc', 'production.confirm_order', 'production.manage_assignees',
            'production.raise_order', 'production.submit_qc', 'production.view', 'production.view_bom', 'products.view',
            'profile.edit', 'profile.view', 'receivables.view', 'reports.view', 'shipment.create',
            'shipment.manage_tracking', 'shipment.view',
        ],
        'pos_clerk' => [
            'customers.create', 'customers.create_without_email', 'customers.view', 'dashboard.view', 'expenses.create',
            'expenses.view', 'notifications.view', 'orders.create', 'orders.view', 'payments.record',
            'payments.upload_proof', 'payments.view', 'pos.access', 'pos.close_register', 'pos.discount',
            'pos.open_register', 'pos.returns', 'pos.void', 'production.raise_order', 'production.view',
            'products.view', 'profile.edit', 'profile.view', 'quotations.create', 'quotations.view',
        ],
        'procurement_manager' => [
            'dashboard.view', 'expenses.view', 'inventory.adjust', 'inventory.approve', 'inventory.transfer',
            'inventory.view', 'notifications.view', 'payments.view', 'procurement.approve', 'procurement.create',
            'procurement.receive', 'procurement.view', 'production.view_bom', 'products.view', 'products.view_cost',
            'profile.edit', 'profile.view', 'reports.export', 'reports.view',
        ],
        'procurement_officer' => [
            'dashboard.view', 'expenses.view', 'inventory.adjust', 'inventory.approve', 'inventory.transfer',
            'inventory.view', 'notifications.view', 'payments.view', 'procurement.approve', 'procurement.create',
            'procurement.receive', 'procurement.view', 'production.view_bom', 'products.view', 'products.view_cost',
            'profile.edit', 'profile.view', 'reports.view',
        ],
        'tailor' => [
            'dashboard.view', 'notifications.view', 'production.submit_qc', 'production.view', 'production.worker',
            'profile.edit', 'profile.view',
        ],
        'system_admin' => self::LEGACY['system_admin'],
        'accountant'   => self::LEGACY['accountant'],
    ];

    /** Modules that are business data — the platform head holds none of them. */
    private const BUSINESS_MODULES = [
        'orders', 'quotations', 'payments', 'production', 'shipment', 'customers', 'procurement',
        'inventory', 'products', 'bom', 'pos', 'receivables', 'marketing', 'intelligence', 'reports',
        'expenses', 'settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function grants(string $role): array
    {
        $r = Role::where('name', $role)->where('guard_name', 'sanctum')->firstOrFail();

        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $r->id)
            ->orderBy('permissions.name')
            ->pluck('permissions.name')->all();
    }

    /** @param array<string,list<string>> $expected */
    private function assertRolesAre(array $expected, string $when): void
    {
        foreach ($expected as $role => $perms) {
            $actual = $this->grants($role);
            $want = $perms;
            sort($want);
            $missing = array_values(array_diff($want, $actual));
            $extra = array_values(array_diff($actual, $want));
            $this->assertSame([[], []], [$missing, $extra],
                "{$role} {$when}: missing [" . implode(', ', $missing) . '] extra [' . implode(', ', $extra) . ']');
        }
    }

    /** Every role_has_permissions row as "role:permission", for whole-table comparisons. */
    private function snapshot(): array
    {
        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->orderBy('roles.name')->orderBy('permissions.name')
            ->get(['roles.name as r', 'permissions.name as p'])
            ->map(fn ($row) => "{$row->r}:{$row->p}")->all();
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    /**
     * Put the database in production's pre-Phase-2 shape: every role as it was,
     * system_admin and accountant on the legacy vocabulary, and neither new
     * slug in existence (as in production before this migration).
     */
    private function rewindToProductionBefore(): void
    {
        // Undo what RefreshDatabase's run of the migration recorded, so up()
        // starts from nothing — exactly as in production.
        $this->migration()->down();

        foreach (['bom.edit', 'setup.technical'] as $slug) {
            $id = DB::table('permissions')->where('name', $slug)->value('id');
            if ($id) {
                DB::table('role_has_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }

        foreach (self::BEFORE as $role => $perms) {
            $r = Role::findOrCreate($role, 'sanctum');
            DB::table('role_has_permissions')->where('role_id', $r->id)->delete();
            foreach ($perms as $p) {
                DB::table('role_has_permissions')->insert([
                    'role_id' => $r->id, 'permission_id' => Permission::findOrCreate($p, 'sanctum')->id,
                ]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actAs(string $role, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    // ── the grants ───────────────────────────────────────────────────────────

    public function test_every_role_matches_the_spec_exactly_after_sync(): void
    {
        $this->assertRolesAre(self::withLater($this->spec()), 'after permission:sync on a fresh database');

        $roles = Role::where('guard_name', 'sanctum')->orderBy('name')->pluck('name')->all();
        $expected = array_keys(self::SPEC);
        sort($expected);
        $this->assertSame($expected, $roles, 'sync seeds exactly the catalogue\'s roles');
    }

    public function test_on_productions_shape_the_migration_alone_reaches_the_spec_and_sync_keeps_it(): void
    {
        $this->rewindToProductionBefore();
        $this->assertRolesAre(self::BEFORE, 'before the migration');

        $this->migration()->up();
        $this->assertRolesAre(self::SPEC, 'after the migration, before sync');

        // Phase 3A's migration runs next in production, before any sync.
        $this->migration3a()->up();
        $this->assertRolesAre($this->spec(), 'after the 3A migration, before sync');

        Artisan::call('permission:sync');
        $this->assertRolesAre(self::withLater($this->spec()), 'after the migrations and permission:sync');
    }

    public function test_sync_twice_after_the_migration_changes_nothing(): void
    {
        $this->rewindToProductionBefore();
        $this->migration()->up();
        $this->migration3a()->up();
        Artisan::call('permission:sync');
        $first = $this->snapshot();

        Artisan::call('permission:sync');
        $this->assertSame($first, $this->snapshot());
    }

    public function test_the_migration_is_idempotent(): void
    {
        $this->rewindToProductionBefore();
        $this->migration()->up();
        $first = $this->snapshot();

        $this->migration()->up();
        $this->assertSame($first, $this->snapshot());
    }

    public function test_down_restores_the_previous_shape_including_legacy_grants(): void
    {
        $this->rewindToProductionBefore();
        $migration = $this->migration();
        $migration->up();
        $this->migration3a()->up();          // the next migration in production
        Artisan::call('permission:sync');   // what a container start does next

        $this->migration3a()->down();        // rollback runs newest first
        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Sync's additions made after up() are part of the new code, so they
        // are not the migration's to undo. Of Phase 2's own grants sync adds
        // nothing the migration did not already give (previous test), so the
        // shape is exactly BEFORE plus the later phases' grants.
        $this->assertRolesAre(self::withLater(self::BEFORE), 'after down()');
        $this->assertNull(Permission::where('name', 'bom.edit')->first());
        $this->assertNull(Permission::where('name', 'setup.technical')->first());
        // Its own log rows are gone (the table stays while a later migration's rows remain).
        $this->assertSame(0, Schema::hasTable('role_grant_changes')
            ? DB::table('role_grant_changes')->where('migration', '2026_10_03_300003_role_catalogue_v2')->count()
            : 0);
    }

    public function test_the_migration_never_touches_direct_user_grants(): void
    {
        $this->rewindToProductionBefore();
        $person = User::factory()->create();
        $person->givePermissionTo(Permission::findByName('payments.void', 'sanctum'), Permission::findByName('manage settings', 'sanctum'));
        $before = DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray();

        $migration = $this->migration();
        $migration->up();
        $this->assertEquals($before, DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray());

        $migration->down();
        $this->assertEquals($before, DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray());
    }

    public function test_system_admin_holds_no_business_data(): void
    {
        $business = array_filter(
            $this->grants('system_admin'),
            fn ($p) => in_array(explode('.', $p)[0], self::BUSINESS_MODULES, true),
        );
        $this->assertSame([], array_values($business));
        $this->assertSame([], array_values(array_filter($this->grants('system_admin'), fn ($p) => str_contains($p, ' '))),
            'no legacy-named grant');
    }

    public function test_accountant_approves_nothing(): void
    {
        $approvals = ['expenses.approve', 'payments.void', 'payments.reassign', 'payments.approve_international',
            'inventory.approve', 'procurement.approve', 'production.approve_qc', 'orders.refund'];
        $this->assertSame([], array_values(array_intersect($approvals, $this->grants('accountant'))));
        $this->assertSame([], array_values(array_filter($this->grants('accountant'), fn ($p) => str_contains($p, 'approve'))));
    }

    public function test_no_denied_grant_is_a_dependency_of_a_granted_one(): void
    {
        // A denial that a granted permission needs would leave the role with a
        // screen that 403s; ROLE_DENIES must only ever remove whole features.
        foreach (SyncPermissions::ROLE_DENIES as $role => $denied) {
            $granted = $this->grants($role);
            foreach ($granted as $p) {
                $needs = array_diff(PermissionDependencyService::resolve([$p]), [$p]);
                $this->assertSame([], array_values(array_intersect($needs, $denied)), "{$role}: {$p} needs a denied grant");
            }
        }
    }

    // ── admin, narrowed ──────────────────────────────────────────────────────

    public function test_admin_can_no_longer_void_approve_open_a_register_or_administer(): void
    {
        $this->actAs('admin');

        $this->postJson('/api/v1/admin/payment-transactions/999999/void')->assertForbidden();
        $this->postJson('/api/v1/admin/payment-transactions/999999/reassign')->assertForbidden();
        $this->postJson('/api/v1/admin/payment-transactions/999999/refund')->assertForbidden();
        $this->postJson('/api/v1/admin/payments/999999/approve')->assertForbidden();
        $this->postJson('/api/v1/admin/purchase-orders/999999/approve')->assertForbidden();
        $this->putJson('/api/v1/admin/inventory/adjustments/999999/approve')->assertForbidden();
        $this->postJson('/api/v1/admin/expenses/999999/approve')->assertForbidden();
        $this->postJson('/api/v1/admin/expenses', [])->assertForbidden();
        $this->postJson('/api/v1/admin/pos/register/open', [])->assertForbidden();
        $this->postJson('/api/v1/pos/cash-register/open', [])->assertForbidden();
        $this->putJson('/api/v1/admin/settings', [])->assertForbidden();
        $this->postJson('/api/v1/admin/users', [])->assertForbidden();
        $this->postJson('/api/v1/admin/roles', [])->assertForbidden();
        $this->postJson('/api/v1/admin/outlets', [])->assertForbidden();
    }

    public function test_the_roles_that_should_act_still_pass_the_same_gates(): void
    {
        // Positive controls: the 403s above are the permission, not the URL.
        $this->actAs('finance_manager');
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/payment-transactions/999999/void')->status());
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/expenses/999999/approve')->status());

        $this->actAs('procurement_manager');
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/purchase-orders/999999/approve')->status());
        $this->assertNotSame(403, $this->putJson('/api/v1/admin/inventory/adjustments/999999/approve')->status());

        $this->actAs('system_admin');
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/users', [])->status());
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/outlets', [])->status());
    }

    public function test_admin_still_reads_the_business(): void
    {
        $this->actAs('admin');

        foreach (['/api/v1/admin/orders', '/api/v1/admin/payment-transactions', '/api/v1/admin/expenses',
            '/api/v1/admin/purchase-orders', '/api/v1/admin/settings', '/api/v1/admin/users',
            '/api/v1/admin/roles', '/api/v1/admin/countries'] as $url) {
            $this->assertNotSame(403, $this->getJson($url)->status(), $url);
        }
    }

    // ── BOMs belong to procurement ──────────────────────────────────────────

    public function test_procurement_manager_creates_a_bom_and_admin_cannot(): void
    {
        $product = Product::factory()->create();
        $material = DB::table('materials')->insertGetId([
            'code' => 'MAT-P2', 'name' => 'Wool', 'unit_of_measure' => 'm', 'unit_cost' => 120,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = ['items' => [['material_id' => $material, 'quantity' => 2, 'unit_of_measure' => 'm']]];

        $this->actAs('admin');
        $this->postJson("/api/v1/admin/products/{$product->id}/bom", $payload)->assertForbidden();
        $this->assertSame(0, DB::table('bills_of_materials')->where('product_id', $product->id)->count());

        $this->actAs('procurement_manager');
        $this->postJson("/api/v1/admin/products/{$product->id}/bom", $payload)->assertSuccessful();
        $this->assertSame(1, DB::table('bills_of_materials')->where('product_id', $product->id)->count());
    }

    // ── Technical setup ─────────────────────────────────────────────────────

    public function test_system_admin_edits_technical_setup_and_nothing_else_in_setup(): void
    {
        $this->actAs('system_admin');

        $this->getJson('/api/v1/admin/countries')->assertOk();
        $this->getJson('/api/v1/admin/languages')->assertOk();
        $this->getJson('/api/v1/admin/shipping/zones')->assertOk();
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/shipping/zones', [])->status());
        $this->assertNotSame(403, $this->postJson('/api/v1/admin/languages', [])->status());

        foreach (['/api/v1/admin/settings', '/api/v1/admin/tax-rates', '/api/v1/admin/currencies-management',
            '/api/v1/admin/payment-methods-management', '/api/v1/admin/orders', '/api/v1/admin/customers',
            '/api/v1/admin/products', '/api/v1/admin/expenses'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_admin_reads_technical_setup_but_cannot_edit_it(): void
    {
        $this->actAs('admin');

        $this->getJson('/api/v1/admin/countries')->assertOk();
        $this->getJson('/api/v1/admin/shipping/zones')->assertOk();
        $this->postJson('/api/v1/admin/shipping/zones', ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/admin/languages', [])->assertForbidden();
        $this->putJson('/api/v1/admin/countries/KE/toggle')->assertForbidden();
    }

    // ── the other roles ─────────────────────────────────────────────────────

    public function test_outlet_manager_issues_a_quotation(): void
    {
        $outlet = Outlet::factory()->create();
        $this->actAs('pos_clerk', $outlet);
        $id = $this->postJson('/api/v1/admin/quotations', [
            'customer_first_name' => 'Jane',
            'valid_until' => now()->addDays(14)->toDateString(),
            'items' => [['product_name' => 'Cassock', 'quantity' => 1, 'unit_price' => 15000]],
        ])->assertCreated()->json('quotation.id');
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertForbidden();

        $this->actAs('outlet_manager', $outlet);
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();
        $this->assertDatabaseMissing('quotations', ['id' => $id, 'quote_number' => null]);
    }

    public function test_finance_manager_can_no_longer_create_an_expense(): void
    {
        $this->actAs('finance_manager');

        $this->postJson('/api/v1/admin/expenses', [])->assertForbidden();
        $this->putJson('/api/v1/admin/expenses/999999', [])->assertForbidden();
        $this->deleteJson('/api/v1/admin/expenses/999999')->assertForbidden();
        $this->getJson('/api/v1/admin/expenses')->assertOk();
    }

    public function test_clerk_has_no_expenses_and_officer_approves_nothing(): void
    {
        $this->actAs('pos_clerk');
        $this->getJson('/api/v1/admin/expenses')->assertForbidden();

        $this->actAs('procurement_officer');
        $this->postJson('/api/v1/admin/purchase-orders/999999/approve')->assertForbidden();
        $this->putJson('/api/v1/admin/inventory/adjustments/999999/approve')->assertForbidden();
    }
}
