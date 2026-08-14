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
 * Two overloaded permissions, split.
 *
 * products.view is a READ permission on the catalogue. It was also opening the
 * marketing calendar and — because the home-front pages are built on marketing
 * banners — EDITING the public storefront home and product pages. A cashier
 * held it without anyone granting it: orders.create pulls in products.view
 * through the dependency map.
 *
 * customers.view is what a cashier needs to serve someone at the counter. It
 * was also opening customer geography, channel engagement and churn risk — the
 * shape of the whole customer base, not the person in front of them.
 */
class SplitOverloadedPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    /** @param list<string> $permissions */
    private function actingWith(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    // ── products.view no longer opens marketing or the storefront ────────────

    public function test_reading_the_catalogue_no_longer_opens_the_marketing_calendar(): void
    {
        $this->actingWith(['products.view']);

        $this->getJson('/api/v1/admin/marketing/seasons')->assertForbidden();
        $this->getJson('/api/v1/admin/marketing/banners')->assertForbidden();
    }

    public function test_a_cashier_can_no_longer_reach_the_storefront_editor(): void
    {
        // The concrete case: pos_clerk never had products.view granted — it
        // arrives through the orders.create dependency — and that was enough to
        // reach the public storefront's banners.
        $this->actingAsRole('pos_clerk');

        $this->assertTrue(auth()->user()->can('products.view'),
            'the dependency still grants it, which is why the gate had to move');

        $this->getJson('/api/v1/admin/marketing/banners')->assertForbidden();
    }

    public function test_the_marketing_key_opens_marketing(): void
    {
        $this->actingWith(['marketing.view']);

        $this->getJson('/api/v1/admin/marketing/seasons')->assertOk();
    }

    public function test_reading_marketing_does_not_grant_editing_it(): void
    {
        // The storefront is public. Seeing the calendar and changing what the
        // shop front says are different jobs.
        $this->actingWith(['marketing.view']);

        $this->postJson('/api/v1/admin/marketing/seasons', ['name' => 'Advent'])
            ->assertForbidden();
    }

    // ── customers.view no longer opens customer analytics ────────────────────

    public function test_serving_a_customer_no_longer_opens_the_analytics(): void
    {
        $this->actingWith(['customers.view']);

        $this->getJson('/api/v1/admin/intelligence/customer-geography')->assertForbidden();
        $this->getJson('/api/v1/admin/intelligence/channel-engagement')->assertForbidden();
        $this->getJson('/api/v1/admin/intelligence/churn-risk')->assertForbidden();
    }

    public function test_the_intelligence_key_opens_the_analytics(): void
    {
        $this->actingWith(['intelligence.view']);

        $this->getJson('/api/v1/admin/intelligence/customer-geography')->assertOk();
    }

    // ── admin keeps everything it had ────────────────────────────────────────

    public function test_an_admin_still_reaches_both(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/marketing/seasons')->assertOk();
        $this->getJson('/api/v1/admin/intelligence/customer-geography')->assertOk();
    }
}
