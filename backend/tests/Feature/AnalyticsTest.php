<?php

namespace Tests\Feature;

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
}
