<?php

namespace Tests\Feature;

use App\Models\ChannelTouchpoint;
use App\Models\Customer;
use App\Models\User;
use App\Services\Neema\FakeNeemaAnalyticsClient;
use App\Services\Neema\NeemaAnalyticsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cross-channel engagement: the nightly sync pulls Neema's rollup (faked here),
 * matches each phone to a customer by canonical E.164, and upserts touchpoints;
 * the Intelligence endpoint returns the five platforms with connected flags.
 */
class ChannelEngagementTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRollup(array $rows): void
    {
        $this->app->instance(NeemaAnalyticsClient::class, new FakeNeemaAnalyticsClient($rows));
    }

    private function actAs(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_sync_matches_neema_phone_to_a_customer_stored_in_local_form(): void
    {
        // Customer stored the Kenyan local way; Neema anchors on E.164 (no +).
        $customer = Customer::create(['first_name' => 'Rev', 'last_name' => 'Otieno', 'phone' => '0712345678']);

        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 12, 'inbound' => 7,
             'first_at' => '2026-06-01T09:00:00Z', 'last_at' => '2026-07-20T18:00:00Z'],
            ['phone' => '254799999999', 'channel' => 'messenger', 'messages' => 3, 'inbound' => 3,
             'first_at' => null, 'last_at' => null],  // no matching customer
        ]);

        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        $wa = ChannelTouchpoint::where('phone', '254712345678')->where('channel', 'whatsapp')->first();
        $this->assertNotNull($wa);
        $this->assertSame($customer->id, $wa->customer_id);   // matched across phone formats
        $this->assertSame(12, $wa->messages);

        $unmatched = ChannelTouchpoint::where('phone', '254799999999')->first();
        $this->assertNotNull($unmatched);
        $this->assertNull($unmatched->customer_id);           // stored, but unmatched
    }

    public function test_sync_is_idempotent_upserting_on_phone_and_channel(): void
    {
        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 5, 'inbound' => 2],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 9, 'inbound' => 4],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        $this->assertSame(1, ChannelTouchpoint::where('phone', '254712345678')->count());
        $this->assertSame(9, ChannelTouchpoint::first()->messages);   // updated, not duplicated
    }

    public function test_endpoint_returns_five_platforms_with_connected_flags(): void
    {
        $this->actAs();

        $customer = Customer::create(['first_name' => 'A', 'last_name' => 'B', 'phone' => '+254712345678']);
        ChannelTouchpoint::create([
            'phone' => '254712345678', 'channel' => 'whatsapp', 'customer_id' => $customer->id,
            'messages' => 20, 'inbound' => 11, 'last_seen' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/intelligence/channel-engagement')->assertOk();

        $channels = collect($res->json('channels'));
        $this->assertSame(['whatsapp', 'messenger', 'instagram', 'facebook', 'web'], $channels->pluck('channel')->all());

        $wa = $channels->firstWhere('channel', 'whatsapp');
        $this->assertTrue($wa['connected']);
        $this->assertSame(20, $wa['messages']);

        // Messenger has no data → not connected.
        $this->assertFalse($channels->firstWhere('channel', 'messenger')['connected']);

        // Top engager surfaced.
        $this->assertSame($customer->id, $res->json('top_customers.0.customer_id'));
        $this->assertContains('whatsapp', $res->json('top_customers.0.channels'));
    }
}
