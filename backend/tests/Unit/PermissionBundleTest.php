<?php

namespace Tests\Unit;

use App\Console\Commands\SyncPermissions;
use PHPUnit\Framework\TestCase;

/**
 * Role definitions are composed from bundles, and both must stay honest.
 *
 * The failure this guards against is a typo. Permission names are plain strings
 * everywhere in this codebase — the React sidebar types them as `string`, and
 * nothing at all checks the ones in a role definition. `pos.discunt` in a role
 * list would sync silently, grant nothing, and show on the Roles screen as a
 * permission that governs nothing. That is precisely how pos.discount came to
 * be granted for months while being enforced nowhere.
 */
class PermissionBundleTest extends TestCase
{
    /** @return array<string,array<int,string>|string> */
    private function allRoles(): array
    {
        return array_merge(SyncPermissions::ROLE_PERMISSIONS, SyncPermissions::EXTRA_ROLES);
    }

    public function test_every_bundle_reference_in_a_role_resolves(): void
    {
        $unknown = [];

        foreach ($this->allRoles() as $role => $spec) {
            if ($spec === '*') {
                continue;
            }
            foreach ($spec as $entry) {
                if (str_starts_with($entry, '@') && !isset(SyncPermissions::BUNDLES[substr($entry, 1)])) {
                    $unknown[] = "{$role} references {$entry}";
                }
            }
        }

        $this->assertSame([], $unknown,
            "A role references a bundle that does not exist:\n" . implode("\n", $unknown));
    }

    public function test_every_permission_in_a_bundle_is_declared(): void
    {
        $undeclared = [];

        foreach (SyncPermissions::BUNDLES as $bundle => $permissions) {
            foreach ($permissions as $p) {
                if (!isset(SyncPermissions::PERMISSIONS[$p])) {
                    $undeclared[] = "@{$bundle} contains {$p}";
                }
            }
        }

        $this->assertSame([], $undeclared,
            "A bundle names a permission that is not in the catalogue — a typo here\n"
            . "grants nothing and fails silently:\n" . implode("\n", $undeclared));
    }

    public function test_every_plain_permission_in_a_role_is_declared(): void
    {
        $undeclared = [];

        foreach ($this->allRoles() as $role => $spec) {
            if ($spec === '*') {
                continue;
            }
            foreach (SyncPermissions::expandBundles($spec) as $p) {
                // Wildcards are resolved against the catalogue at sync time.
                if (str_ends_with($p, '.*') || isset(SyncPermissions::PERMISSIONS[$p])) {
                    continue;
                }
                $undeclared[] = "{$role} grants {$p}";
            }
        }

        $this->assertSame([], $undeclared,
            "A role grants a permission that is not in the catalogue:\n" . implode("\n", $undeclared));
    }

    public function test_an_unknown_bundle_fails_loudly_rather_than_silently(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown permission bundle: @nope');

        SyncPermissions::expandBundles(['@nope']);
    }

    public function test_expansion_flattens_and_deduplicates(): void
    {
        // A role may name a bundle AND one of its members — as outlet_manager
        // does with production.view — without the permission arriving twice.
        $expanded = SyncPermissions::expandBundles(['@self', 'profile.view', 'orders.view']);

        $this->assertSame(
            ['profile.view', 'profile.edit', 'notifications.view', 'orders.view'],
            $expanded,
        );
    }

    /**
     * The bundles were factored out of the previous longhand role lists. These
     * two are the ones whose contents are load-bearing elsewhere: @till is what
     * a cashier gets, and pos.cash_management is deliberately NOT in it.
     */
    public function test_the_till_bundle_excludes_cash_management(): void
    {
        $this->assertNotContains('pos.cash_management', SyncPermissions::BUNDLES['till']);
        $this->assertContains('pos.cash_management', SyncPermissions::ROLE_PERMISSIONS['outlet_manager']);
        $this->assertNotContains('pos.cash_management',
            SyncPermissions::expandBundles(SyncPermissions::ROLE_PERMISSIONS['pos_clerk']));
    }
}
