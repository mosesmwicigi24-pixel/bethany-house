<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Zero roles is a legal save (owner, 2026-08-27): deactivating a staff
 * member must not force a role, and unselect-all must save. What stays
 * impossible is administering the business into a locked room — the
 * last active Super Admin can neither lose the role nor be deactivated,
 * and nobody removes their own Super Admin role.
 */
class RolelessUserSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('users.edit', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('users.view', 'sanctum'));

        return $user;
    }

    public function test_a_staff_user_can_be_saved_with_zero_roles(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole(Role::findOrCreate('pos_clerk', 'sanctum'));

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/users/{$staff->id}", ['role_ids' => []])
            ->assertOk();

        $this->assertSame(0, $staff->fresh()->roles()->count(),
            'unselect-all must actually clear the roles');

        // The audit write must actually LAND. It never had before this
        // change: four controllers inserted a user_id column the table does
        // not have, a silent catch ate the error, and production carried
        // 2,534 audit rows with ZERO from any of them.
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('activity_log')
                ->where('action', 'user_updated')->where('causer_id', '>', 0)->exists(),
            'the user_updated audit row must be written'
        );
    }

    public function test_deactivating_without_touching_roles_works(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole(Role::findOrCreate('tailor', 'sanctum'));

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/users/{$staff->id}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame('inactive', $staff->fresh()->status);
        $this->assertSame(1, $staff->fresh()->roles()->count(), 'roles untouched');
    }

    public function test_deactivate_and_clear_roles_in_one_save(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole(Role::findOrCreate('pos_clerk', 'sanctum'));

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/admin/users/{$staff->id}", [
                'status' => 'inactive', 'role_ids' => [],
            ])->assertOk();

        $fresh = $staff->fresh();
        $this->assertSame('inactive', $fresh->status);
        $this->assertSame(0, $fresh->roles()->count());
    }

    public function test_you_cannot_remove_your_own_super_admin_role(): void
    {
        $me = $this->admin();
        User::factory()->create(['status' => 'active'])
            ->assignRole(Role::findOrCreate('super_admin', 'sanctum')); // not the last one

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/admin/users/{$me->id}", ['role_ids' => []])
            ->assertStatus(422);

        $this->assertTrue($me->fresh()->hasRole('super_admin'));
    }

    public function test_the_last_super_admin_cannot_lose_the_role(): void
    {
        $me   = $this->admin();
        $last = User::factory()->create(['status' => 'active']);
        $last->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        // Make $last genuinely the last ACTIVE super admin. That includes
        // the owner account a migration seeds into every fresh database
        // (make_owner_super_admin) — the guard correctly counted it.
        User::where('id', '!=', $last->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->update(['status' => 'inactive']);

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/admin/users/{$last->id}", ['role_ids' => []])
            ->assertStatus(422);

        $this->assertTrue($last->fresh()->hasRole('super_admin'));
    }

    public function test_the_last_super_admin_cannot_be_deactivated(): void
    {
        $me   = $this->admin();
        $last = User::factory()->create(['status' => 'active']);
        $last->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        User::where('id', '!=', $last->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->update(['status' => 'inactive']);

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/admin/users/{$last->id}", ['status' => 'inactive'])
            ->assertStatus(422);

        $this->assertSame('active', $last->fresh()->status);
    }

    public function test_a_super_admin_can_be_demoted_while_another_remains(): void
    {
        $me    = $this->admin();
        $other = User::factory()->create(['status' => 'active']);
        $other->assignRole(Role::findOrCreate('super_admin', 'sanctum'));

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/v1/admin/users/{$other->id}", ['role_ids' => []])
            ->assertOk();

        $this->assertFalse($other->fresh()->hasRole('super_admin'));
    }
}
