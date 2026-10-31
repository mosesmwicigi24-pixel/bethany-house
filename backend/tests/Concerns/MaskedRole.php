<?php

namespace Tests\Concerns;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait MaskedRole
{
    /**
     * A role the contact table does not name, with the same rights as $role:
     * the way a masked caller still exists now the sales roles see contacts in
     * full (owner, 2026-10-30).
     */
    protected function maskedCopyOf(string $role): string
    {
        $name   = "custom_{$role}";
        $source = Role::findByName($role, 'sanctum');
        $copy   = Role::findOrCreate($name, 'sanctum');
        $copy->syncPermissions($source->permissions);
        $copy->forceFill(['data_scope' => $source->data_scope])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $name;
    }
}
