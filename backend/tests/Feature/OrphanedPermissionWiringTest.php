<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The three formerly-inert permissions now govern the routes their catalogue
 * descriptions always claimed. Each test drives a REAL role built by
 * permission:sync — the point is that the Roles screen finally tells the
 * truth, so the assertions must go through the same grants it displays.
 */
class OrphanedPermissionWiringTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        Artisan::call('permission:sync');
        // The registrar caches role->permission maps aggressively; without a
        // flush, grants made by the sync are invisible to the middleware and
        // every request 403s regardless of what the Roles screen would show.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole(\Spatie\Permission\Models\Role::findByName($role, 'sanctum'));

        return $user;
    }

    public function test_production_worker_governs_the_tailor_workspace(): void
    {
        $tailor = $this->userWithRole('tailor');
        $this->actingAs($tailor, 'sanctum')
            ->getJson('/api/v1/tailor/tasks')->assertOk();

        // admin keeps the access it always had — via production.*, now visibly.
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tailor/tasks')->assertOk();

        // a clerk has no business on the shop floor's task list
        $clerk = $this->userWithRole('pos_clerk');
        $this->actingAs($clerk, 'sanctum')
            ->getJson('/api/v1/tailor/tasks')->assertForbidden();
    }

    public function test_settings_manage_governs_the_recycle_bin(): void
    {
        // settings.manage sits in the sync's wildcardExcluded list — admin's
        // settings.* deliberately NEVER expands to it. So admin stays out
        // (exactly the old role:super_admin behaviour), super_admin enters via
        // the Gate::before wildcard, and the permission is grantable one role
        // at a time on the Roles screen — which now actually governs.
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/trash')->assertForbidden();

        $super = $this->userWithRole('super_admin');
        $this->actingAs($super, 'sanctum')
            ->getJson('/api/v1/admin/trash')->assertOk();
    }

    public function test_notifications_view_governs_the_staff_bell(): void
    {
        $clerk = $this->userWithRole('pos_clerk');
        $this->actingAs($clerk, 'sanctum')
            ->getJson('/api/v1/admin/notifications')->assertOk();

        // A user holding no staff role has no bell — the block used to be
        // auth-only, so ANY authenticated account could read it.
        $nobody = User::factory()->create();
        $this->actingAs($nobody, 'sanctum')
            ->getJson('/api/v1/admin/notifications')->assertForbidden();
    }
}
