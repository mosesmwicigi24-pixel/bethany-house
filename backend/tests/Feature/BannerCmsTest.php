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
        // Banners are the storefront's shop front, not the catalogue. They
        // moved off products.* for the same reason the marketing calendar did:
        // reading the product list should not let you change what the public
        // site says.
        foreach (['products.view', 'marketing.view', 'marketing.manage'] as $perm) {
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

    public function test_clergy_cassock_product_page_is_seeded(): void
    {
        // A full Apple-style product page seeded as CMS blocks (placement=product:<slug>).
        $data = $this->getJson('/api/v1/site/content?placement=' . urlencode('product:clergy-cassock'))
            ->assertOk()->json('data');
        $this->assertArrayHasKey('product_highlight', $data);
        $this->assertArrayHasKey('product_feature', $data);
        $this->assertArrayHasKey('product_chapter', $data);
        $this->assertCount(3, $data['product_highlight']);
        $this->assertCount(4, $data['product_feature']);
        $this->assertCount(2, $data['product_chapter']);
    }

    public function test_homepage_reviews_are_the_genuine_ones_not_the_written_quotes(): void
    {
        $reviews = $this->getJson('/api/v1/site/content?placement=homepage')
            ->assertOk()->json('data.home_testimonial');

        // Thirteen Google reviews, in the seeded order, each with a name, the
        // customer's own words, a star rating and where it was posted.
        $this->assertCount(13, $reviews);
        $this->assertSame('Doreen Dee', $reviews[0]['title']);
        $this->assertStringStartsWith('The best shop for communion elements.', $reviews[0]['subtitle']);
        $this->assertSame('Judah Thura', $reviews[12]['title']);
        foreach ($reviews as $r) {
            $this->assertNotEmpty($r['title']);
            $this->assertNotEmpty($r['subtitle']);
            $this->assertContains($r['styles']['rating'], [4, 5]);
            $this->assertSame('Google review', $r['styles']['eyebrow']);
        }

        // Word for word: the customer's own spelling survives, and the one
        // review longer than the old 255-character cap is stored whole.
        $njeri = collect($reviews)->firstWhere('title', 'Njeri Muturi');
        $this->assertSame(4, $njeri['styles']['rating']);
        $this->assertStringContainsString('im very greatful', $njeri['subtitle']);
        $this->assertStringEndsWith('I will be back!', $njeri['subtitle']);
        $this->assertGreaterThan(255, mb_strlen($njeri['subtitle']));

        // The quotes written for the website are no longer published…
        $body = json_encode($reviews);
        foreach (['Canon Mwangi', 'Pastor Achieng', 'Fr. Kamau'] as $name) {
            $this->assertStringNotContainsString($name, $body);
        }
        // …but were soft-deleted, not destroyed.
        $this->assertSame(3, Banner::onlyTrashed()->where('position', 'home_testimonial')->count());
    }

    public function test_a_review_longer_than_255_characters_can_be_saved(): void
    {
        $this->admin();
        $text = rtrim(str_repeat('Served us faithfully for years. ', 20)); // 639 characters

        $id = $this->postJson('/api/v1/admin/marketing/banners', [
            'title'     => 'A grateful parish',
            'subtitle'  => $text,
            'position'  => 'home_testimonial',
            'placement' => 'homepage',
            'styles'    => ['rating' => 5, 'eyebrow' => 'Google review'],
        ])->assertCreated()->json('data.id');

        $this->assertSame($text, Banner::findOrFail($id)->subtitle);

        // Still bounded: a pasted essay is refused, not truncated.
        $this->postJson('/api/v1/admin/marketing/banners', [
            'title' => 'Too long', 'subtitle' => str_repeat('x', 2001), 'position' => 'home_testimonial',
        ])->assertStatus(422);
    }
}
