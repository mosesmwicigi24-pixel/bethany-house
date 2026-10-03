<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * 2026_10_03_440002_till_lifecycle_permissions on production's shape (the
 * Phase 2 catalogue, without the four till keys): up() grants exactly the
 * Phase 4B keys, sync afterwards changes nothing, down() takes back exactly
 * what up() gave, and nobody's direct grant is touched.
 */
class TillLifecyclePermissionsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_03_440002_till_lifecycle_permissions.php';

    private const KEYS = ['pos.till_verify', 'pos.reconcile', 'pos.till_correction', 'pos.tills_view_all'];

    private const EXPECTED = [
        'outlet_manager'  => ['pos.till_verify'],
        'accountant'      => ['pos.reconcile', 'pos.tills_view_all'],
        'finance_manager' => ['pos.reconcile', 'pos.till_correction', 'pos.tills_view_all'],
        'admin'           => ['pos.tills_view_all'],
        'pos_clerk'       => [],
        'system_admin'    => [],
        'procurement_manager' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        // Rewind to production today: the Phase 2 catalogue, no till keys.
        DB::table('role_grant_changes')->delete();
        foreach (self::KEYS as $k) {
            Permission::where('name', $k)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    /** @return array<int,string> */
    private function tillKeysOf(string $role): array
    {
        $keys = Role::findByName($role, 'sanctum')->permissions()->whereIn('name', self::KEYS)->pluck('name')->all();
        sort($keys);

        return $keys;
    }

    public function test_up_grants_exactly_the_till_keys_and_sync_agrees(): void
    {
        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::EXPECTED as $role => $keys) {
            sort($keys);
            $this->assertSame($keys, $this->tillKeysOf($role), "{$role} after up()");
        }
        $this->assertSame('POS', Permission::findByName('pos.reconcile', 'sanctum')->group);

        $before = DB::table('role_has_permissions')->count();
        Artisan::call('permission:sync');
        $this->assertSame($before, DB::table('role_has_permissions')->count(), 'sync adds nothing up() did not');
    }

    public function test_up_twice_changes_nothing(): void
    {
        $this->migration()->up();
        $count = DB::table('role_has_permissions')->count();
        $this->migration()->up();
        $this->assertSame($count, DB::table('role_has_permissions')->count());
    }

    public function test_down_takes_back_exactly_what_up_gave_and_leaves_direct_grants(): void
    {
        $shape = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray();

        $this->migration()->up();
        $person = \App\Models\User::factory()->create();
        $person->givePermissionTo(Permission::findByName('pos.reconcile', 'sanctum'));

        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertEquals($shape, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray());
        // Given to a person directly since: it stays with them.
        $this->assertTrue($person->fresh()->hasPermissionTo('pos.reconcile', 'sanctum'));
        $this->assertNull(Permission::where('name', 'pos.till_verify')->first());
    }
}
