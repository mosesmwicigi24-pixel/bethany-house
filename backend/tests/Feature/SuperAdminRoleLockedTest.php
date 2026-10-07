<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner, 2026-10-07: the super admin holds every permission (Gate::before) and
 * the Roles screen shows it all ticked and locked. Its list is never written —
 * an explicit grant would make it a signer of lower approval bands and a
 * recipient of every permission-routed alert.
 */
class SuperAdminRoleLockedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_super_admin_role_list_cannot_be_rewritten(): void
    {
        Artisan::call('permission:sync');
        $sa = User::factory()->create();
        $sa->assignRole(Role::findByName('super_admin', 'sanctum'));
        Sanctum::actingAs($sa);
        $role = Role::findByName('super_admin', 'sanctum');
        $before = $role->permissions()->count();
        $ids = Permission::where('guard_name', 'sanctum')->pluck('id')->all();

        $this->postJson("/api/v1/admin/roles/{$role->id}/permissions", ['permissions' => $ids])
            ->assertStatus(422)->assertJson(['reason' => 'super_admin_holds_all']);

        $this->assertSame($before, $role->fresh()->permissions()->count());
        // and it can still do anything
        $this->assertTrue($sa->can('approvals.super_sign'));
        $this->assertTrue($sa->can('procurement.approve'));
    }
}
