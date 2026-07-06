<?php

namespace Tests\Concerns;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Test helper for authenticating as a staff user that clears the RBAC
 * middleware. Assigning the super_admin role lets the Gate::before bypass grant
 * all permissions, so tests can exercise business logic without wiring up each
 * individual permission/guard.
 */
trait CreatesAuthenticatedStaff
{
    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
