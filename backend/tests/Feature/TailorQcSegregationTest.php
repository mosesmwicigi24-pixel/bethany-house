<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
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
 * The person who sewed it does not pass it (owner's Policy 4, PR 2, approved
 * 2026-10-05: option 1 "managers inspect", self-QC blocked for everyone).
 *
 *   - the tailor role no longer holds production.submit_qc (catalogue + migration)
 *   - nobody assigned a stage on an order may record its QC — manager and owner
 *     included — through MakerChecker, logged like every other self-approval
 *   - QC only on an order the inspector could open (audit B12)
 */
class TailorQcSegregationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_05_100001_tailors_stop_submitting_qc.php';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function user(string $role, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** An order waiting for QC, with one finished stage worked by $worker. */
    private function orderAwaitingQc(?User $worker, ?Outlet $outlet = null): ProductionOrder
    {
        $po = ProductionOrder::create([
            'order_number' => 'PRD-QC-' . fake()->unique()->numerify('######'),
            'product_id'   => Product::factory()->create()->id,
            'status'       => 'qc_pending',
            'quantity'     => 2,
            'outlet_id'    => $outlet?->id,
        ]);
        $stage = ProductionStage::firstOrCreate(
            ['slug' => 'stitch-qc'],
            ['name' => 'Stitching', 'sort_order' => 1, 'is_active' => true],
        );
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id,
            'production_stage_id' => $stage->id,
            'assigned_to'         => $worker?->id,
            'sequence'            => 1,
            'status'              => 'completed',
            'quantity_done'       => 2,
            'started_at'          => now()->subHour(),
            'completed_at'        => now(),
        ]);

        return $po;
    }

    private function pass(ProductionOrder $po)
    {
        return $this->postJson("/api/v1/admin/production-orders/{$po->id}/qc", [
            'passed' => true, 'passed_quantity' => 2, 'failed_quantity' => 0,
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    private function roleHolds(string $role, string $permission): bool
    {
        return DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)->where('permissions.name', $permission)
            ->exists();
    }

    // ── who holds the key ───────────────────────────────────────────────────

    public function test_the_catalogue_gives_qc_to_managers_not_tailors(): void
    {
        $this->assertFalse($this->roleHolds('tailor', 'production.submit_qc'));
        $this->assertTrue($this->roleHolds('tailor', 'production.worker'), 'My Tasks stays');
        $this->assertTrue($this->roleHolds('outlet_manager', 'production.submit_qc'));
        $this->assertTrue($this->user('admin')->can('production.submit_qc'));
    }

    public function test_the_migration_takes_qc_off_production_tailors_and_down_gives_it_back(): void
    {
        // Production's shape: the role still holds the key from the old bundle.
        $tailorRole = Role::findByName('tailor', 'sanctum');
        $submitQc   = Permission::findOrCreate('production.submit_qc', 'sanctum');
        $tailorRole->givePermissionTo($submitQc);
        // A deliberate individual grant must survive.
        $special = $this->user('tailor');
        $special->givePermissionTo($submitQc);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->migration()->down(); // clear what RefreshDatabase's run logged
        $tailorRole->givePermissionTo($submitQc);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->migration()->up();
        $this->assertFalse($this->roleHolds('tailor', 'production.submit_qc'));
        $this->assertTrue($special->fresh()->hasDirectPermission('production.submit_qc'), 'direct grant untouched');
        $this->assertSame(1, DB::table('role_grant_changes')
            ->where('migration', '2026_10_05_100001_tailors_stop_submitting_qc')
            ->where('action', 'revoked')->count());

        // sync after the migration does not put it back
        Artisan::call('permission:sync');
        $this->assertFalse($this->roleHolds('tailor', 'production.submit_qc'));

        $this->migration()->down();
        $this->assertTrue($this->roleHolds('tailor', 'production.submit_qc'), 'down restores exactly what it took');
    }

    public function test_the_migration_takes_nothing_and_gives_nothing_back_when_the_role_never_held_it(): void
    {
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame(0, DB::table('role_grant_changes')
            ->where('migration', '2026_10_05_100001_tailors_stop_submitting_qc')->count());

        $this->migration()->down();
        $this->assertFalse($this->roleHolds('tailor', 'production.submit_qc'));
    }

    // ── a tailor cannot record QC ───────────────────────────────────────────

    public function test_a_tailor_cannot_record_qc_at_all(): void
    {
        $tailor = $this->user('tailor');
        $po     = $this->orderAwaitingQc($tailor);
        Sanctum::actingAs($tailor);

        $this->pass($po)->assertForbidden();
        $this->assertSame('qc_pending', $po->fresh()->status);
    }

    // ── nobody inspects their own work ──────────────────────────────────────

    public function test_a_manager_who_worked_a_stage_cannot_pass_the_order(): void
    {
        $manager = $this->user('admin');
        $po      = $this->orderAwaitingQc($manager);
        Sanctum::actingAs($manager);

        $this->pass($po)
            ->assertForbidden()
            ->assertJsonPath('code', 'SELF_APPROVAL')
            ->assertJsonPath('message', 'You worked on this order, so someone else must inspect it.');

        $this->assertSame('qc_pending', $po->fresh()->status);
        $this->assertSame(0, DB::table('production_quality_checks')->where('production_order_id', $po->id)->count());
        $this->assertTrue(DB::table('activity_log')
            ->where('event', 'self_approval_blocked')
            ->where('subject_id', $po->id)
            ->exists(), 'the refusal is on the audit trail');
    }

    public function test_the_owner_cannot_pass_their_own_work_either(): void
    {
        $owner = $this->user('super_admin');
        $po    = $this->orderAwaitingQc($owner);
        Sanctum::actingAs($owner);

        $this->pass($po)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('qc_pending', $po->fresh()->status);
    }

    public function test_a_manager_who_did_not_work_on_it_passes_it(): void
    {
        $tailor  = $this->user('tailor');
        $po      = $this->orderAwaitingQc($tailor);
        $manager = $this->user('admin');
        Sanctum::actingAs($manager);

        $this->pass($po)->assertOk();
        $this->assertSame('qc_passed', $po->fresh()->status);
        $this->assertSame($manager->id, (int) DB::table('production_quality_checks')
            ->where('production_order_id', $po->id)->value('checked_by'));
    }

    // ── only orders the inspector can see (B12) ─────────────────────────────

    public function test_an_outlet_manager_cannot_inspect_another_outlets_order(): void
    {
        [$mine, $other] = [Outlet::factory()->create(), Outlet::factory()->create()];
        $tailor  = $this->user('tailor');
        $theirs  = $this->orderAwaitingQc($tailor, $other);
        $ours    = $this->orderAwaitingQc($tailor, $mine);
        $manager = $this->user('outlet_manager', $mine);
        Sanctum::actingAs($manager);

        $this->pass($theirs)->assertNotFound();
        $this->assertSame('qc_pending', $theirs->fresh()->status);

        $this->pass($ours)->assertOk();
        $this->assertSame('qc_passed', $ours->fresh()->status);
    }
}
