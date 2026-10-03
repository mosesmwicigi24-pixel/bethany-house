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
 * Owner decision 2026-10-02: "I will set the roles for each person." Only a
 * super administrator changes who holds which role; nobody changes their own
 * roles, outlet or 2FA requirement. Other edits keep working.
 */
class RoleAssignmentRuleTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): Role
    {
        return Role::findOrCreate($name, 'sanctum');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        foreach (['users.view', 'users.create', 'users.edit'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $u->assignRole($this->role('admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    private function owner(): User
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        $u->assignRole($this->role('super_admin'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    public function test_an_admin_cannot_give_anyone_a_role_or_take_one_away(): void
    {
        $clerk = User::factory()->create(['user_type' => 'staff']);
        $clerk->assignRole($this->role('pos_clerk'));
        $om = $this->role('outlet_manager');
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/admin/users/{$clerk->id}", ['first_name' => 'Renamed', 'role_ids' => [$om->id]])->assertForbidden();
        $this->assertSame(['pos_clerk'], $clerk->fresh()->getRoleNames()->all(), 'role unchanged');
        $this->assertNotSame('Renamed', $clerk->fresh()->first_name, 'a refused role change refuses the whole save');

        $this->putJson("/api/v1/admin/users/{$clerk->id}/role", ['role' => 'outlet_manager'])->assertForbidden();
        $this->assertSame(['pos_clerk'], $clerk->fresh()->getRoleNames()->all());
    }

    public function test_an_admin_can_still_edit_a_person_when_the_roles_are_unchanged(): void
    {
        $clerk = User::factory()->create(['user_type' => 'staff']);
        $clerk->assignRole($this->role('pos_clerk'));
        Sanctum::actingAs($this->admin());

        $this->putJson("/api/v1/admin/users/{$clerk->id}", ['first_name' => 'Renamed', 'role_ids' => [$this->role('pos_clerk')->id]])->assertOk();
        $this->assertSame('Renamed', $clerk->fresh()->first_name);
    }

    public function test_an_admin_creates_accounts_without_roles_but_not_with_them(): void
    {
        Sanctum::actingAs($this->admin());
        $base = ['first_name' => 'New', 'last_name' => 'Staff', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'user_type' => 'staff'];

        $this->postJson('/api/v1/admin/users', $base + ['email' => 'a@example.test', 'role_ids' => [$this->role('pos_clerk')->id]])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'a@example.test']);

        $this->postJson('/api/v1/admin/users', $base + ['email' => 'b@example.test'])->assertSuccessful();
    }

    public function test_the_owner_sets_roles_for_others_and_may_stack_them(): void
    {
        $person = User::factory()->create(['user_type' => 'staff']);
        Sanctum::actingAs($this->owner());

        $ids = [$this->role('outlet_manager')->id, $this->role('pos_clerk')->id];
        $this->putJson("/api/v1/admin/users/{$person->id}", ['role_ids' => $ids])->assertOk();
        $this->assertEqualsCanonicalizing(['outlet_manager', 'pos_clerk'], $person->fresh()->getRoleNames()->all());

        $this->putJson("/api/v1/admin/users/{$person->id}", ['role_ids' => [$ids[0]]])->assertOk();
        $this->assertSame(['outlet_manager'], $person->fresh()->getRoleNames()->all(), 'clicking a role out removes it');
    }

    public function test_nobody_changes_their_own_roles_outlet_or_2fa_requirement(): void
    {
        $owner = $this->owner();
        $this->owner();   // a second super admin exists, as production requires
        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/admin/users/{$owner->id}", ['role_ids' => [$this->role('super_admin')->id, $this->role('pos_clerk')->id]])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$owner->id}", ['must_setup_2fa' => ! (bool) $owner->fresh()->must_setup_2fa])->assertForbidden();

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/users/{$admin->id}", ['role_ids' => [$this->role('admin')->id, $this->role('finance_manager')->id]])->assertForbidden();
        $this->assertSame(['admin'], $admin->fresh()->getRoleNames()->all());
    }
}
