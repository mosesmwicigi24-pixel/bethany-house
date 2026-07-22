<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Home-front content-block CMS — banners keyed by position (slot) + sort_order
 * (order within the slot, e.g. the hero slider's slides 1, 2, 3). Admin CRUD is
 * gated by products.*; the public /site/content feed groups active blocks by
 * position for the storefront.
 */
class BannerCmsTest extends TestCase
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

    public function test_banner_admin_requires_auth(): void
    {
        $this->assertContains(
            $this->getJson('/api/v1/admin/marketing/banners')->status(),
            [401, 403],
        );
    }

    public function test_admin_can_crud_a_home_hero_slide(): void
    {
        $this->admin();

        $create = $this->postJson('/api/v1/admin/marketing/banners', [
            'title'      => 'Tailored for the pulpit',
            'subtitle'   => 'Gowns measured in Nairobi.',
            'position'   => 'home_hero',
            'placement'  => 'homepage',
            'sort_order' => 1,
            'is_active'  => true,
            'link_url'   => '/shop',
            'link_text'  => 'Book a fitting',
            'styles'     => ['eyebrow' => 'Made to Measure', 'theme' => 'slate'],
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->assertDatabaseHas('banners', [
            'title' => 'Tailored for the pulpit', 'position' => 'home_hero', 'sort_order' => 1,
        ]);

        // Re-order the slide (position 1 → 2).
        $this->putJson("/api/v1/admin/marketing/banners/{$id}", [
            'title' => 'Tailored for the pulpit', 'position' => 'home_hero', 'sort_order' => 2,
        ])->assertOk()->assertJsonPath('data.sort_order', 2);

        $this->deleteJson("/api/v1/admin/marketing/banners/{$id}")->assertOk();
        $this->assertSoftDeleted('banners', ['id' => $id]);
    }

    public function test_public_content_groups_active_blocks_by_position_ordered(): void
    {
        // Use isolated positions so the seeded home_hero slides don't affect counts.
        Banner::create(['title' => 'Slide B', 'position' => 'zz_hero', 'placement' => 'homepage', 'sort_order' => 2, 'is_active' => true]);
        Banner::create(['title' => 'Slide A', 'position' => 'zz_hero', 'placement' => 'homepage', 'sort_order' => 1, 'is_active' => true]);
        Banner::create(['title' => 'Promo',   'position' => 'zz_promo', 'placement' => 'homepage', 'sort_order' => 1, 'is_active' => true]);
        Banner::create(['title' => 'Hidden',  'position' => 'zz_hero', 'placement' => 'homepage', 'sort_order' => 9, 'is_active' => false]);

        $data = $this->getJson('/api/v1/site/content?placement=homepage')->assertOk()->json('data');

        $this->assertArrayHasKey('zz_hero', $data);
        $this->assertArrayHasKey('zz_promo', $data);

        // Inactive excluded; slides ordered by sort_order (A before B).
        $this->assertCount(2, $data['zz_hero']);
        $this->assertEquals('Slide A', $data['zz_hero'][0]['title']);
        $this->assertEquals('Slide B', $data['zz_hero'][1]['title']);
    }

    public function test_text_only_block_without_image_is_allowed(): void
    {
        // The newsletter/pillars blocks have no image — allowed after the
        // nullable-image migration.
        Banner::create(['title' => 'Grace in every detail', 'position' => 'home_newsletter', 'placement' => 'homepage', 'is_active' => true]);

        $data = $this->getJson('/api/v1/site/content?placement=homepage')->assertOk()->json('data');
        $this->assertArrayHasKey('home_newsletter', $data);
        $this->assertNull($data['home_newsletter'][0]['image_url']);
    }

    public function test_home_hero_slides_are_seeded_from_the_website(): void
    {
        // The seed migration puts the storefront's current 3 hero slides into
        // the CMS as home_hero entries, ordered 1/2/3.
        $data = $this->getJson('/api/v1/site/content?placement=homepage')->assertOk()->json('data');
        $this->assertArrayHasKey('home_hero', $data);
        $this->assertCount(3, $data['home_hero']);
        $this->assertEquals(1, $data['home_hero'][0]['sort_order']);
        $this->assertStringContainsString('altar', (string) $data['home_hero'][0]['title']);
        $this->assertNotEmpty($data['home_hero'][0]['image_url']);
    }
}
