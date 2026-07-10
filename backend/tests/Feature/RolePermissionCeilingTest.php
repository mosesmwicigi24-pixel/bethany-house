<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A roles.edit holder must not be able to escalate: they can only grant
 * permissions they themselves hold, and can never touch the super_admin role.
 * Otherwise a mere role-editor could self-grant users.edit, payments.approve,
 * settings.manage_database … all the way to effective super_admin.
 */
class RolePermissionCeilingTest extends TestCase
{
    use RefreshDatabase;

    private function perm(string $name): Permission
    {
        return Permission::findOrCreate($name, 'sanctum');
    }

    /** An actor holding ONLY the given permissions (no super_admin). */
    private function actorWith(array $permNames): User
    {
        $user = User::factory()->create();
        foreach ($permNames as $n) {
            $user->givePermissionTo($this->perm($n));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $user;
    }

    private function targetRole(): Role
    {
        return Role::create(['name' => 'shop_helper_' . uniqid(), 'guard_name' => 'sanctum']);
    }

    public function test_cannot_grant_a_permission_the_actor_does_not_hold(): void
    {
        $this->perm('roles.view');
        $forbidden = $this->perm('users.edit');   // actor does NOT hold this
        $role      = $this->targetRole();

        Sanctum::actingAs($this->actorWith(['roles.view', 'roles.edit']));

        $this->postJson("/api/v1/admin/roles/{$role->id}/permissions", [
            'permissions' => [$forbidden->id],
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $role->id)->count());
    }

    public function test_can_grant_a_permission_the_actor_holds(): void
    {
        $granted = $this->perm('roles.view');
        $role    = $this->targetRole();

        Sanctum::actingAs($this->actorWith(['roles.view', 'roles.edit']));

        $this->postJson("/api/v1/admin/roles/{$role->id}/permissions", [
            'permissions' => [$granted->id],
        ])->assertOk();

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id, 'permission_id' => $granted->id,
        ]);
    }

    public function test_non_super_admin_cannot_modify_the_super_admin_role(): void
    {
        $this->perm('roles.view');
        $harmless = $this->perm('roles.view');
        $superRole = Role::findOrCreate('super_admin', 'sanctum');

        Sanctum::actingAs($this->actorWith(['roles.view', 'roles.edit']));

        $this->postJson("/api/v1/admin/roles/{$superRole->id}/permissions", [
            'permissions' => [$harmless->id],
        ])->assertStatus(403);
    }

    public function test_super_admin_actor_may_grant_anything(): void
    {
        $superRole = Role::findOrCreate('super_admin', 'sanctum');
        $powerful  = $this->perm('settings.manage_database');
        $role      = $this->targetRole();

        $actor = User::factory()->create();
        $actor->assignRole($superRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/admin/roles/{$role->id}/permissions", [
            'permissions' => [$powerful->id],
        ])->assertOk();

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id, 'permission_id' => $powerful->id,
        ]);
    }

    public function test_cannot_duplicate_a_role_more_powerful_than_yourself(): void
    {
        $powerful = $this->perm('users.edit');
        $this->perm('roles.view');
        $source = $this->targetRole();
        DB::table('role_has_permissions')->insert(['role_id' => $source->id, 'permission_id' => $powerful->id]);

        Sanctum::actingAs($this->actorWith(['roles.view', 'roles.edit']));

        $this->postJson("/api/v1/admin/roles/{$source->id}/duplicate")
            ->assertStatus(403);
    }
}
