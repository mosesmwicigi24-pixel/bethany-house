<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * pos.discount_campaign belongs to the agent's service account, not to people.
 *
 * It was granted to that account and to no role. Within minutes `admin` held it
 * too: admin is declared as `pos.*`, and docker/entrypoint.sh runs
 * `permission:sync` on EVERY container start, expanding the wildcard over the
 * newly catalogued permission. $wildcardExcluded is the existing mechanism for
 * exactly this, and this test is the thing that notices if it stops working.
 */
class CampaignPermissionIsServiceAccountOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_role_picks_it_up_from_a_wildcard(): void
    {
        $this->artisan('permission:sync')->assertExitCode(0);

        $holders = Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'pos.discount_campaign'))
            ->pluck('name')
            ->all();

        $this->assertSame([], $holders,
            'a service-account capability must not arrive at a human role via pos.*');
    }

    public function test_it_can_still_be_granted_explicitly(): void
    {
        // Excluded from wildcards, not from existence — the agent's account is
        // given it by name.
        $permission = Permission::findOrCreate('pos.discount_campaign', 'sanctum');
        $user = \App\Models\User::factory()->create();

        $user->givePermissionTo($permission);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($user->fresh()->can('pos.discount_campaign'));
    }

    public function test_admin_keeps_the_unbounded_override_it_already_had(): void
    {
        // Nothing is taken away: the role that lost the agent pass-through
        // still holds the larger permission.
        $this->artisan('permission:sync')->assertExitCode(0);

        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasPermissionTo('pos.discount_override'));
    }
}
