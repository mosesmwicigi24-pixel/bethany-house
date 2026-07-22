<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Promotion;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /api/v1/site/theme — the storefront's seasonal skin.
 *
 * Time is frozen with Carbon::setTestNow so the date-driven season selection
 * is deterministic against the seeded 2026 windows.
 */
class SiteThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_returns_the_seeded_season_active_for_the_current_date(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00'); // mid-Harvest window

        $res = $this->getJson('/api/v1/site/theme')->assertOk();

        $res->assertJsonPath('season.key', 'harvest')
            ->assertJsonPath('season.name', 'Harvest Thanksgiving')
            ->assertJsonPath('season.theme.motif', 'wheat')
            ->assertJsonPath('season.theme.accent', '#b5791f');
        $this->assertArrayHasKey('campaign', $res->json());
        $this->assertArrayHasKey('banner', $res->json());
    }

    public function test_switches_season_by_date(): void
    {
        Carbon::setTestNow('2026-12-10 10:00:00'); // Advent → Christmas window
        $this->getJson('/api/v1/site/theme')->assertOk()
            ->assertJsonPath('season.key', 'advent-christmas')
            ->assertJsonPath('season.theme.motif', 'star');
    }

    public function test_returns_null_season_out_of_all_windows(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00'); // between Harvest and Advent
        $this->getJson('/api/v1/site/theme')->assertOk()->assertJsonPath('season', null);
    }

    public function test_exposes_a_running_campaign_and_banner_when_linked(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');

        $promo = Promotion::create([
            'name' => 'Blessed Friday — Harvest', 'type' => 'product_discount',
            'discount_type' => 'percentage', 'discount_value' => 15,
            'is_active' => true, 'starts_at' => '2026-08-01', 'ends_at' => '2026-08-31',
        ]);
        $banner = Banner::create([
            'title' => 'Harvest Blessed Friday', 'subtitle' => '15% off communion ware',
            'image_url' => 'https://example.test/harvest.webp', 'is_active' => true,
        ]);
        Season::where('key', 'harvest')->update([
            'promotion_id' => $promo->id, 'banner_id' => $banner->id,
        ]);

        $this->getJson('/api/v1/site/theme')->assertOk()
            ->assertJsonPath('campaign.discount_value', 15.0)
            ->assertJsonPath('campaign.discount_type', 'percentage')
            ->assertJsonPath('banner.title', 'Harvest Blessed Friday');
    }
}
