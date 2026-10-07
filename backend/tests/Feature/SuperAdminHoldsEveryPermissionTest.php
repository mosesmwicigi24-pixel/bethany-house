<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner, 2026-10-07: "all the permissions should be granted to super admin by
 * default". The role passed every check via Gate::before but HELD only part of
 * the catalogue, so the Roles screen showed it incomplete.
 */
class SuperAdminHoldsEveryPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function heldBy(string $role): int
    {
        return Role::findByName($role, 'sanctum')->permissions()->count();
    }

    public function test_after_sync_the_super_admin_holds_every_permission(): void
    {
        Artisan::call('permission:sync');

        $all = Permission::where('guard_name', 'sanctum')->count();
        $this->assertGreaterThan(100, $all);
        $this->assertSame($all, $this->heldBy('super_admin'));

        // spot-check the areas the owner named
        $sa = Role::findByName('super_admin', 'sanctum');
        foreach (['procurement.approve', 'expenses.approve', 'pos.open_register',
                  'payments.void', 'approvals.finance_sign', 'approvals.super_sign'] as $p) {
            if (Permission::where('name', $p)->where('guard_name', 'sanctum')->exists()) {
                $this->assertTrue($sa->hasPermissionTo($p, 'sanctum'), $p);
            }
        }
    }

    public function test_a_permission_added_later_reaches_the_super_admin_on_the_next_sync(): void
    {
        Artisan::call('permission:sync');
        Permission::create(['name' => 'future.capability', 'guard_name' => 'sanctum']);

        Artisan::call('permission:sync');

        $this->assertTrue(Role::findByName('super_admin', 'sanctum')->hasPermissionTo('future.capability', 'sanctum'));
    }

    public function test_other_roles_are_not_given_everything(): void
    {
        Artisan::call('permission:sync');
        $all = Permission::where('guard_name', 'sanctum')->count();

        foreach (['admin', 'finance_manager', 'accountant', 'pos_clerk'] as $role) {
            $this->assertLessThan($all, $this->heldBy($role), $role);
        }
        $this->assertFalse(Role::findByName('admin', 'sanctum')->hasPermissionTo('approvals.super_sign', 'sanctum'));
    }
}
