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
 * A super_admin bypasses every gate (Gate::before), so letting a lesser admin
 * edit its password / email / status is a full account-takeover and lockout
 * vector. Only another super_admin may modify a super_admin account.
 */
class SuperAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'sanctum');
        $u = User::factory()->create();   // factory emails are unique
        $u->assignRole('super_admin');
        return $u;
    }

    /** A non-super-admin who holds users.view + users.edit (but no super role). */
    private function lesserAdmin(): User
    {
        $u = User::factory()->create();
        $u->givePermissionTo(Permission::findOrCreate('users.view', 'sanctum'));
        $u->givePermissionTo(Permission::findOrCreate('users.edit', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    public function test_lesser_admin_cannot_reset_a_super_admins_password(): void
    {
        $target       = $this->superAdmin();
        $originalHash = $target->password;
        Sanctum::actingAs($this->lesserAdmin());

        $this->putJson("/api/v1/admin/users/{$target->id}", [
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(403);

        // Password hash unchanged → the takeover is blocked.
        $this->assertSame($originalHash, $target->fresh()->password);
    }

    public function test_lesser_admin_cannot_suspend_a_super_admin(): void
    {
        $target = $this->superAdmin();
        Sanctum::actingAs($this->lesserAdmin());

        $this->putJson("/api/v1/admin/users/{$target->id}/status", [
            'status' => 'suspended',
        ])->assertStatus(403);

        $this->assertNotSame('suspended', $target->fresh()->status);
    }

    public function test_lesser_admin_cannot_force_reset_link_on_a_super_admin(): void
    {
        $target = $this->superAdmin();
        Sanctum::actingAs($this->lesserAdmin());

        $this->postJson("/api/v1/admin/users/{$target->id}/reset-password")
            ->assertStatus(403);
    }

    public function test_a_super_admin_may_modify_another_super_admin(): void
    {
        $target = $this->superAdmin();
        $actor  = $this->superAdmin();
        Sanctum::actingAs($actor);

        $this->putJson("/api/v1/admin/users/{$target->id}", [
            'first_name' => 'Renamed',
        ])->assertOk();

        $this->assertSame('Renamed', $target->fresh()->first_name);
    }

    public function test_lesser_admin_can_still_edit_a_normal_user(): void
    {
        $normal = User::factory()->create(['first_name' => 'Old']);
        Sanctum::actingAs($this->lesserAdmin());

        $this->putJson("/api/v1/admin/users/{$normal->id}", [
            'first_name' => 'New',
        ])->assertOk();

        $this->assertSame('New', $normal->fresh()->first_name);
    }
}
