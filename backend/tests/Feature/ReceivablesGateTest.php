<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The last two overloads from the review.
 *
 * pos.access exists to open the till. It was also opening Outstanding
 * Balances — every part-paid order with the customer's name, phone and what
 * they owe. Worth being precise about what this fixes: the rows are ALREADY
 * bounded, because outstandingBalances queries through Eloquent and the viewer
 * scope applies. So this is a decision about who gets the screen, not a leak
 * being closed. Chasing money is a manager's job.
 *
 * The Financial Report is the mirror image: the backend has required
 * reports.financial for some time, but the menu still offered it to anyone with
 * reports.view — who then got a 403 on arrival. Nothing leaked; the menu simply
 * lied. Fixed in the Sidebar rather than here, since the API was already right.
 */
class ReceivablesGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    public function test_the_till_key_no_longer_opens_the_receivables_screen(): void
    {
        $clerk = $this->actingAsRole('pos_clerk');

        $this->assertTrue($clerk->can('pos.access'), 'they still hold the till key');

        $this->getJson('/api/v1/admin/pos/outstanding-balances')->assertForbidden();
    }

    public function test_a_manager_gets_it(): void
    {
        $this->actingAsRole('outlet_manager');

        $this->getJson('/api/v1/admin/pos/outstanding-balances')->assertOk();
    }

    public function test_finance_gets_it(): void
    {
        $this->actingAsRole('finance_manager');

        $this->getJson('/api/v1/admin/pos/outstanding-balances')->assertOk();
    }

    public function test_an_admin_gets_it(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/pos/outstanding-balances')->assertOk();
    }

    /**
     * The permission is what opens it, not the role — so a future role can be
     * given the screen without also being given a till.
     */
    public function test_the_permission_alone_is_enough_without_a_till(): void
    {
        // finance_manager has no pos.access at all. The screen was inside the
        // till group, so the people whose job this is could not reach it.
        $user = User::factory()->create();
        foreach (['receivables.view'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/admin/pos/outstanding-balances')->assertOk();
    }
}
