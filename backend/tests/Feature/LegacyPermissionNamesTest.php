<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The legacy space-named vocabulary leaves every role; dotted grants and
 * direct grants to a person are untouched; down() restores exactly.
 */
class LegacyPermissionNamesTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_03_600001_revoke_legacy_space_named_role_grants.php');
    }

    public function test_space_named_role_grants_go_and_everything_else_stays(): void
    {
        $admin = Role::findOrCreate('admin', 'sanctum');
        $om    = Role::findOrCreate('outlet_manager', 'sanctum');
        foreach (['view orders', 'create users', 'manage settings'] as $legacy) {
            $admin->givePermissionTo(Permission::findOrCreate($legacy, 'sanctum'));
        }
        $om->givePermissionTo(Permission::findOrCreate('view orders', 'sanctum'));
        $admin->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        $person = User::factory()->create();
        $person->givePermissionTo('create users');   // a direct grant: an individual decision

        $this->migration()->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(['orders.view'], $admin->fresh()->permissions->pluck('name')->all());
        $this->assertSame([], $om->fresh()->permissions->pluck('name')->all());
        $this->assertTrue($person->fresh()->hasDirectPermission('create users'), 'direct grants untouched');
        $this->assertSame(4, DB::table('role_grant_changes')->where('migration', 'like', '%600001%')->count());

        $this->migration()->up();   // idempotent
        $this->assertSame(4, DB::table('role_grant_changes')->where('migration', 'like', '%600001%')->count());

        $this->migration()->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertEqualsCanonicalizing(['view orders', 'create users', 'manage settings', 'orders.view'],
            $admin->fresh()->permissions->pluck('name')->all());
        $this->assertSame(['view orders'], $om->fresh()->permissions->pluck('name')->all());
    }

    public function test_the_user_policy_reads_the_dotted_names(): void
    {
        $u = User::factory()->create();
        $u->givePermissionTo(Permission::findOrCreate('users.create', 'sanctum'));
        Permission::findOrCreate('create users', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($u->fresh()->can('create', User::class));

        $legacyOnly = User::factory()->create();
        $legacyOnly->givePermissionTo('create users');
        Permission::findOrCreate('users.create', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($legacyOnly->fresh()->can('create', User::class), 'the legacy name grants nothing');
    }
}
