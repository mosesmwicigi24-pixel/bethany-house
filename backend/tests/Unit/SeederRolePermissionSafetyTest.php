<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * No seeder may replace a role's permissions.
 *
 * `permission:sync` is the one thing allowed to write role→permission grants,
 * and it only ever ADDS. A seeder that calls syncPermissions() does the
 * opposite: Spatie's sync REPLACES the whole set, so running it drops every
 * grant the command had assigned.
 *
 * The deleted PermissionsSeeder did exactly that to six roles — including
 * `$superAdmin->syncPermissions(Permission::all())` — using a legacy
 * space-separated permission vocabulary ('view orders') unrelated to the dotted
 * one the application actually enforces ('orders.view'). It was never wired into
 * DatabaseSeeder, so it did not run on deploy, but it sat one
 * `db:seed --class=PermissionsSeeder` away from silently replacing every role's
 * working permissions with dead names.
 *
 * This test exists so that cannot come back by accident. Assigning a ROLE to a
 * user is fine and stays fine — SuperAdminSeeder does it to bootstrap accounts.
 * What is banned is a seeder deciding what a role may DO.
 */
class SeederRolePermissionSafetyTest extends TestCase
{
    private const SEEDER_DIR = __DIR__ . '/../../database/seeders';

    /** Methods that overwrite, rather than extend, a role's permission set. */
    private const DESTRUCTIVE = ['syncPermissions', 'revokePermissionTo'];

    public function test_no_seeder_replaces_a_roles_permissions(): void
    {
        $offenders = [];

        foreach (glob(self::SEEDER_DIR . '/*.php') as $file) {
            $source = file_get_contents($file);

            foreach (self::DESTRUCTIVE as $method) {
                if (str_contains($source, $method . '(')) {
                    $offenders[] = basename($file) . ' calls ' . $method . '()';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A seeder is writing role permissions destructively.',
            'Role permissions belong to `php artisan permission:sync`, which only adds.',
            'A seeder that syncs will drop every grant that command assigned.',
            '',
            ...$offenders,
        ]));
    }

    /**
     * The seeder directory is small and hand-maintained; if it grows a file
     * that grants permissions at all, that is worth a deliberate look even when
     * the grant is additive.
     */
    public function test_no_seeder_grants_permissions_directly(): void
    {
        $offenders = [];

        foreach (glob(self::SEEDER_DIR . '/*.php') as $file) {
            if (str_contains(file_get_contents($file), 'givePermissionTo(')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'Seeders should assign roles to users, not permissions to roles. '
            . 'Permission grants belong in SyncPermissions so they stay reviewable: '
            . implode(', ', $offenders));
    }
}
