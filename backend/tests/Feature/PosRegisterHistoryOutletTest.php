<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 1C, item 7 — a till's history belongs to its outlet.
 *
 * Every POS action checks authoriseOutletAccess; GET register/history did not,
 * so a cashier at one shop could page through every register session at every
 * other shop (who opened it, who closed it, the counted cash and variance) by
 * changing outlet_id.
 */
class PosRegisterHistoryOutletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function clerkAt(?Outlet $outlet): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        if ($outlet) {
            $user->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    public function test_a_cashier_reads_her_own_outlets_register_history(): void
    {
        $mine = Outlet::factory()->create();
        $this->clerkAt($mine);

        $this->getJson("/api/v1/admin/pos/register/history?outlet_id={$mine->id}")->assertOk();
    }

    public function test_a_cashier_cannot_read_another_outlets_register_history(): void
    {
        $this->clerkAt(Outlet::factory()->create());
        $other = Outlet::factory()->create();

        $this->getJson("/api/v1/admin/pos/register/history?outlet_id={$other->id}")->assertForbidden();
    }

    public function test_a_cashier_with_no_outlet_is_refused(): void
    {
        $this->clerkAt(null);

        $this->getJson('/api/v1/admin/pos/register/history?outlet_id=' . Outlet::factory()->create()->id)
            ->assertForbidden();
    }

    public function test_admin_reads_any_outlet(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/admin/pos/register/history?outlet_id=' . Outlet::factory()->create()->id)
            ->assertOk();
    }
}
