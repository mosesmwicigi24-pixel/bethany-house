<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin CRUD for the Marketing CMS — liturgical seasons + Blessed Friday
 * promotions. Gated behind the products.* permissions (catalog managers).
 */
class MarketingCmsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('catalog_manager', 'sanctum'));
        foreach (['products.view', 'products.edit', 'products.delete'] as $perm) {
            $user->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_marketing_endpoints_require_auth(): void
    {
        $seasons = $this->getJson('/api/v1/admin/marketing/seasons');
        $promos  = $this->getJson('/api/v1/admin/marketing/promotions');
        $this->assertContains($seasons->status(), [401, 403]);
        $this->assertContains($promos->status(), [401, 403]);
    }

    public function test_admin_can_crud_a_season(): void
    {
        $this->admin();

        $create = $this->postJson('/api/v1/admin/marketing/seasons', [
            'key'       => 'test-feast',
            'name'      => 'Test Feast',
            'scripture' => 'Rejoice always.',
            'theme'     => ['accent' => '#123456', 'motif' => 'star'],
            'starts_at' => '2026-09-01',
            'ends_at'   => '2026-09-30',
            'is_active' => true,
            'priority'  => 5,
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->assertDatabaseHas('seasons', ['key' => 'test-feast', 'name' => 'Test Feast']);

        $this->putJson("/api/v1/admin/marketing/seasons/{$id}", [
            'key'  => 'test-feast',
            'name' => 'Test Feast Renamed',
        ])->assertOk()->assertJsonPath('data.name', 'Test Feast Renamed');

        $this->deleteJson("/api/v1/admin/marketing/seasons/{$id}")->assertOk();
        $this->assertSoftDeleted('seasons', ['id' => $id]);
    }

    public function test_admin_can_create_a_blessed_friday_promotion(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/marketing/promotions', [
            'name'           => 'Blessed Friday — Harvest',
            'discount_type'  => 'percentage',
            'discount_value' => 15,
            'is_active'      => true,
            'starts_at'      => '2026-08-01',
            'ends_at'        => '2026-08-31',
        ])->assertCreated()->assertJsonPath('data.name', 'Blessed Friday — Harvest');

        $this->assertDatabaseHas('promotions', ['name' => 'Blessed Friday — Harvest']);
    }

    public function test_percentage_over_100_is_rejected(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/marketing/promotions', [
            'name'           => 'Bad Promo',
            'discount_type'  => 'percentage',
            'discount_value' => 150,
        ])->assertStatus(422);
    }
}
