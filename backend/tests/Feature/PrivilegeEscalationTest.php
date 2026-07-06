<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit SEC-1: role assignment is gated only by `users.edit`, so anyone who can
 * edit users could grant `super_admin` (which bypasses every gate via
 * Gate::before). The ceiling in UserController::assertMayAssignRoles now blocks a
 * non-super-admin from granting super_admin or altering a super_admin's roles.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function actAsUserWith(array $permissions = [], array $roles = []): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        foreach ($roles as $r) {
            $user->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_user_editor_cannot_grant_super_admin(): void
    {
        $this->actAsUserWith(['users.view', 'users.edit']); // not a super admin
        Role::findOrCreate('super_admin', 'sanctum');
        $target = User::factory()->create();

        $this->putJson("/api/v1/admin/users/{$target->id}/role", ['role' => 'super_admin'])
            ->assertStatus(403);

        $this->assertFalse($target->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_can_grant_super_admin(): void
    {
        // Explicit perms too, so the route middleware passes regardless of whether
        // Gate::before short-circuits it; the role is what satisfies the ceiling.
        $this->actAsUserWith(['users.view', 'users.edit'], ['super_admin']);
        $target = User::factory()->create();

        $this->putJson("/api/v1/admin/users/{$target->id}/role", ['role' => 'super_admin'])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('super_admin'));
    }

    public function test_user_editor_cannot_alter_a_super_admins_roles(): void
    {
        Role::findOrCreate('staff', 'sanctum');
        $target = User::factory()->create();
        $target->assignRole(Role::findOrCreate('super_admin', 'sanctum'));

        $this->actAsUserWith(['users.view', 'users.edit']);

        $this->putJson("/api/v1/admin/users/{$target->id}/role", ['role' => 'staff'])
            ->assertStatus(403);

        $this->assertTrue($target->fresh()->hasRole('super_admin')); // unchanged
    }
}
