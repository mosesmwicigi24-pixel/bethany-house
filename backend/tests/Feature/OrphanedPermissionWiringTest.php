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
            ->getJson('/api/v1/admin/tailor/tasks')->assertOk();

        // admin keeps the access it always had — via production.*, now visibly.
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/tailor/tasks')->assertOk();

        // a clerk has no business on the shop floor's task list
        $clerk = $this->userWithRole('pos_clerk');
        $this->actingAs($clerk, 'sanctum')
            ->getJson('/api/v1/admin/tailor/tasks')->assertForbidden();
    }

    public function test_settings_manage_governs_the_recycle_bin(): void
    {
        // admin holds settings.* — the Roles screen advertised recycle-bin
        // access for admin all along while a hard-coded role gate denied it.
        // The screen wins: what it displays is now what happens.
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/trash')->assertOk();

        $finance = $this->userWithRole('finance_manager');
        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/admin/trash')->assertForbidden();
    }

    public function test_notifications_view_governs_the_staff_bell(): void
    {
        $clerk = $this->userWithRole('pos_clerk');
        $this->actingAs($clerk, 'sanctum')
            ->getJson('/api/v1/admin/notifications')->assertOk();

        // walkin_customer is a customer account, not staff — no bell.
        $customer = $this->userWithRole('walkin_customer');
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/notifications')->assertForbidden();
    }
}
