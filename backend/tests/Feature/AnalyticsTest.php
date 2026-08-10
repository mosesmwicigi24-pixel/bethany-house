<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Storefront visit analytics — the public track beacon and the admin Insights
 * overview (visitors by country + device/OS mix). Buyers-by-country reuses the
 * orders table and is covered by the checkout tests.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('analyst', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_track_records_a_visit_without_auth(): void
    {
        $this->postJson('/api/v1/site/track', [
            'country' => 'ke', 'device_type' => 'mobile', 'os' => 'Android',
            'is_mobile' => true, 'path' => '/shop',
        ])->assertNoContent();

        // country upper-cased; no IP stored.
        $this->assertDatabaseHas('site_visits', [
            'country_code' => 'KE', 'device_type' => 'mobile', 'is_mobile' => true,
        ]);
    }

    public function test_overview_requires_auth(): void
    {
        $this->assertContains(
            $this->getJson('/api/v1/admin/analytics/overview')->status(),
            [401, 403],
        );
    }

    public function test_overview_aggregates_visits_and_devices(): void
    {
        DB::table('site_visits')->insert([
            ['country_code' => 'US', 'device_type' => 'desktop', 'os' => 'macOS', 'is_mobile' => false, 'created_at' => now()],
            ['country_code' => 'US', 'device_type' => 'mobile', 'os' => 'iOS', 'is_mobile' => true, 'created_at' => now()],
            ['country_code' => 'KE', 'device_type' => 'mobile', 'os' => 'Android', 'is_mobile' => true, 'created_at' => now()],
        ]);

        $this->viewer();
        $res = $this->getJson('/api/v1/admin/analytics/overview?days=30')->assertOk();

        $this->assertEquals(3, $res->json('totals.visits'));

        $vbc = $res->json('visitors_by_country');
        $this->assertEquals('US', $vbc[0]['country_code']);   // top visitor country
        $this->assertEquals(2, $vbc[0]['visits']);

        $this->assertNotEmpty($res->json('devices'));
        $this->assertIsArray($res->json('buyers_by_country')); // present (empty ok)
        $this->assertEquals(67, $res->json('totals.mobile_share')); // 2 of 3
    }

    // ─── markets: the unified traffic + money view ────────────────────────────

    /** An online order attributed to $countryCode for $amount. */
    private function onlineOrder(string $countryCode, float $amount): void
    {
        Order::factory()->create([
            'order_type'            => 'online',
            'customer_country_code' => $countryCode,
            'total_amount'          => $amount,
            'created_at'            => now(),
        ]);
    }

    public function test_markets_joins_visits_and_orders_per_country(): void
    {
        // The shape of the real data: a big traffic source that never buys, a
        // smaller one that does, and a buyer with no recorded visit at all.
        $visits = [];
        for ($i = 0; $i < 5; $i++) {
            $visits[] = ['country_code' => 'US', 'device_type' => 'desktop', 'is_mobile' => false, 'created_at' => now()];
        }
        for ($i = 0; $i < 2; $i++) {
            $visits[] = ['country_code' => 'KE', 'device_type' => 'mobile', 'is_mobile' => true, 'created_at' => now()];
        }
        DB::table('site_visits')->insert($visits);

        $this->onlineOrder('KE', 1000);
        $this->onlineOrder('RW', 4000);

        $this->viewer();
        $markets = $this->getJson('/api/v1/admin/analytics/overview?days=30')
            ->assertOk()
            ->json('markets');

        $by = collect($markets)->keyBy('country_code');
        $this->assertCount(3, $markets, 'US (visits only), KE (both) and RW (orders only)');

        // Traffic with no money — visits present, money zeroed, conversion 0.
        $this->assertSame(5, $by['US']['visits']);
        $this->assertSame(0, $by['US']['orders']);
        $this->assertSame(0.0, (float) $by['US']['revenue']);
        $this->assertSame(0.0, (float) $by['US']['conversion_pct']);

        // Both sides present — conversion is orders ÷ visits.
        $this->assertSame(2, $by['KE']['visits']);
        $this->assertSame(1, $by['KE']['orders']);
        $this->assertSame(1000.0, (float) $by['KE']['revenue']);
        $this->assertSame(50.0, (float) $by['KE']['conversion_pct']);

        // Money with no visit — conversion is NULL ("can't compute"), not 0
        // ("nobody converted"). The dashboard renders those two differently.
        $this->assertSame(0, $by['RW']['visits']);
        $this->assertSame(1, $by['RW']['orders']);
        $this->assertNull($by['RW']['conversion_pct']);

        // Default order is revenue desc, then visits desc.
        $this->assertSame(['RW', 'KE', 'US'], array_column($markets, 'country_code'));
    }

    public function test_totals_countries_is_not_capped_at_the_top_50_list(): void
    {
        // 60 distinct visitor countries. `countries` used to be counted off the
        // ->limit(50) visitors list and so flat-lined at 50.
        $rows = [];
        foreach (range(0, 59) as $i) {
            $rows[] = [
                'country_code' => sprintf('%02d', $i),   // 2 chars, distinct
                'device_type'  => 'mobile',
                'is_mobile'    => true,
                'created_at'   => now(),
            ];
        }
        DB::table('site_visits')->insert($rows);

        $this->viewer();
        $res = $this->getJson('/api/v1/admin/analytics/overview?days=30')->assertOk();

        $this->assertEquals(60, $res->json('totals.countries'));
        $this->assertCount(50, $res->json('visitors_by_country')); // list still capped
        $this->assertCount(60, $res->json('markets'));             // markets is not
    }
}
