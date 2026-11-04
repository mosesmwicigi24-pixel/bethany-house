<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
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
 * Who sees People pages (owner, 2026-11-04): the super admin, admin and
 * outlet manager. Finance keeps Employee files, Payroll and Timesheets (to
 * lock pay periods). Production and system admins see none. A leave reason
 * is read only by the person, whoever decides their leave, and a super admin.
 */
class PeopleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PEOPLE = ['attendance.view_team', 'attendance.manage', 'roster.manage', 'leave.approve'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function grants(string $role): array
    {
        return Role::findByName($role, 'sanctum')->permissions->pluck('name')->all();
    }

    private function user(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole($role);
        if ($outlet) {
            DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $outlet->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u->fresh();
    }

    public function test_the_role_catalogue_keeps_people_pages_to_admins_and_outlet_managers(): void
    {
        foreach (['production_manager', 'system_admin', 'finance_manager'] as $role) {
            foreach (self::PEOPLE as $p) {
                $this->assertNotContains($p, $this->grants($role), "{$role} must not hold {$p}");
            }
        }
        $finance = $this->grants('finance_manager');
        foreach (['timesheets.lock', 'hr.view', 'payroll.view', 'payroll.run'] as $p) {
            $this->assertContains($p, $finance, "finance keeps {$p}");
        }
        foreach (['admin', 'outlet_manager'] as $role) {
            $this->assertContains('attendance.view_team', $this->grants($role), "{$role} still sees People");
        }
    }

    public function test_the_migration_takes_the_old_grants_off_a_live_database_and_sync_does_not_put_them_back(): void
    {
        // Production as it was before: the three roles held the People grants.
        foreach (['production_manager', 'system_admin', 'finance_manager'] as $role) {
            Role::findByName($role, 'sanctum')->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'sanctum'), self::PEOPLE));
        }
        $adminBefore = $this->grants('admin');

        (require database_path('migrations/2026_11_13_100000_people_pages_visibility.php'))->up();
        Artisan::call('permission:sync');

        foreach (['production_manager', 'system_admin', 'finance_manager'] as $role) {
            $this->assertSame([], array_values(array_intersect(self::PEOPLE, $this->grants($role))), "{$role} lost every People grant");
        }
        $this->assertContains('timesheets.lock', $this->grants('finance_manager'));
        $this->assertEqualsCanonicalizing($adminBefore, $this->grants('admin'), 'no other role is touched');
    }

    public function test_finance_reaches_timesheets_but_not_leave_roster_attendance_or_requests(): void
    {
        $shop = Outlet::factory()->create();
        $finance = $this->user('finance_manager', $shop);
        Sanctum::actingAs($finance);

        $this->assertNotSame(403, $this->getJson('/api/v1/admin/timesheets')->status(), 'finance opens Timesheets to lock pay periods');
        $this->getJson('/api/v1/admin/leave/requests')->assertStatus(403);
        $this->getJson('/api/v1/admin/roster')->assertStatus(403);
        $this->getJson('/api/v1/admin/people/requests')->assertStatus(403);
        $this->getJson('/api/v1/admin/people/overview')->assertStatus(403);

        Sanctum::actingAs($this->user('production_manager', $shop));
        $this->getJson('/api/v1/admin/timesheets')->assertStatus(403);
        $this->getJson('/api/v1/admin/leave/requests')->assertStatus(403);

        Sanctum::actingAs($this->user('outlet_manager', $shop));
        $this->getJson('/api/v1/admin/leave/requests')->assertOk();
    }

    public function test_a_leave_reason_is_read_by_the_person_their_approver_and_a_super_admin_only(): void
    {
        $shop = Outlet::factory()->create();
        $boss = $this->user('outlet_manager', $shop);
        $person = $this->user('pos_clerk', $shop);
        $person->update(['reports_to' => $boss->id]);
        EmployeeProfile::factory()->create(['user_id' => $person->id, 'hire_date' => '2024-01-01']);
        $leave = LeaveRequest::factory()->create(['user_id' => $person->id, 'reason' => 'Hospital appointment']);

        // An admin at another outlet who does not decide this person's leave.
        $role = Role::findOrCreate('team_reader_' . uniqid(), 'sanctum');
        $role->givePermissionTo(Permission::findOrCreate('attendance.view_team', 'sanctum'));
        $onlooker = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $onlooker->assignRole($role);
        DB::table('outlet_user')->insert(['user_id' => $onlooker->id, 'outlet_id' => Outlet::factory()->create()->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $super = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $super->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $row = function (User $viewer) use ($leave) {
            Sanctum::actingAs($viewer->fresh());
            $rows = collect($this->getJson('/api/v1/admin/leave/requests?status=pending')->assertOk()->json('data'));
            return $rows->firstWhere('id', $leave->id);
        };

        $seen = $row($onlooker);
        if ($seen) {                                     // listed by outlet scope or not, it never carries the reason
            $this->assertNull($seen['reason']);
            $this->assertTrue($seen['reason_hidden']);
        }
        $this->assertFalse(\App\Http\Controllers\Api\LeaveController::mayReadReason($leave, $onlooker->fresh()));

        foreach (['manager' => $boss, 'super admin' => $super] as $who => $viewer) {
            $seen = $row($viewer);
            $this->assertNotNull($seen, "the {$who} sees the request");
            $this->assertSame('Hospital appointment', $seen['reason'], "the {$who} reads the reason");
            $this->assertFalse($seen['reason_hidden']);
        }

        Sanctum::actingAs($person->fresh());
        $mine = collect($this->getJson('/api/v1/admin/me/leave')->assertOk()->json('requests'))->firstWhere('id', $leave->id);
        $this->assertSame('Hospital appointment', $mine['reason'], 'the person reads their own reason');
    }
}
