<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The permission catalogue and the code that enforces it must agree.
 *
 * Two ways they drifted, both of which cost real time to find by hand:
 *
 *   PHANTOM — a permission the code CHECKS but the catalogue never declares.
 *     permission:sync then never creates it, so nobody can hold it, and the
 *     feature it guards answers only to super_admin by accident of its bypass.
 *     `production.delete_order` and `settings.manage` were in this state.
 *
 *   INERT — a permission the catalogue declares and roles are granted, which
 *     nothing ever checks. It shows on the Roles screen and governs nothing.
 *     `pos.discount` sat here while cashiers discounted without limit.
 *
 * Both assertions are ratchets rather than exact matches, so this test does not
 * have to be edited every time one of the known items is fixed — it fails only
 * when something NEW drifts. That also makes it independent of the order the
 * in-flight access-control PRs land in.
 */
class PermissionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Enforced-but-undeclared strings that are known and accounted for.
     *
     * All four are the legacy space-separated vocabulary, checked by `can:`
     * inside the role gates in routes/web.php — the Livewire admin, which is
     * still live. They are the reason `permission:sync --prune` is not safe
     * yet: pruning the database to match the dotted catalogue would delete the
     * rows these gates depend on.
     *
     * This list shrinks to nothing when routes/web.php migrates to the dotted
     * vocabulary. It must never grow.
     */
    private const KNOWN_PHANTOM = [
        'access pos',
        'manage orders',
        'manage procurement',
        'view production',
    ];

    /**
     * Declared and granted, but enforced nowhere. Each is a control the Roles
     * screen advertises and the application does not apply.
     *
     * Three have already dropped off as the access-control work landed:
     * dashboard.view and payments.transactions now gate routes, and pos.discount
     * is enforced by PosDiscountPolicy. The two profile.* entries are benign —
     * self-service is implicit and no route consults them. The export pair and
     * the walk-in flag are still real gaps.
     */
    private const KNOWN_INERT = [
        'customers.create_without_email',
        'expenses.export',
        'products.export',
        'profile.edit',
        'profile.view',
    ];

    /** @return array<string,list<string>> */
    private function audit(): array
    {
        Artisan::call('permission:audit', ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_no_new_permission_is_enforced_without_being_declared(): void
    {
        $unexpected = array_values(array_diff($this->audit()['phantom'], self::KNOWN_PHANTOM));

        $this->assertSame([], $unexpected, implode("\n", [
            'A permission is checked in code but missing from SyncPermissions::PERMISSIONS.',
            'permission:sync will never create it, so no role can hold it and the gate',
            'answers only to super_admin — by accident, not by decision.',
            'Declare it in the catalogue, then grant it deliberately.',
            '',
            ...$unexpected,
        ]));
    }

    public function test_no_new_permission_is_granted_without_being_enforced(): void
    {
        $unexpected = array_values(array_diff($this->audit()['inert'], self::KNOWN_INERT));

        $this->assertSame([], $unexpected, implode("\n", [
            'A permission is declared and granted to roles but checked nowhere.',
            'It appears on the Roles screen and governs nothing, which is how',
            'pos.discount let cashiers discount without limit for as long as it did.',
            'Either wire it up to a gate, or remove it from the catalogue.',
            '',
            ...$unexpected,
        ]));
    }

    /**
     * The ratchet is only honest if the lists stay current. A fixed item left
     * in KNOWN_INERT would silently excuse a future regression of the same
     * permission, so flag entries that are no longer inert.
     */
    public function test_the_known_lists_do_not_go_stale(): void
    {
        $audit = $this->audit();

        $this->assertSame([], array_values(array_diff(self::KNOWN_INERT, $audit['inert'])),
            'These are listed as known-inert but are now enforced. Remove them from '
            . 'KNOWN_INERT so a future regression is caught rather than excused.');

        $this->assertSame([], array_values(array_diff(self::KNOWN_PHANTOM, $audit['phantom'])),
            'These are listed as known-phantom but are now declared. Remove them from '
            . 'KNOWN_PHANTOM.');
    }
}
