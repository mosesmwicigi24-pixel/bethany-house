<?php

namespace Tests\Feature;

use App\Models\ChannelTouchpoint;
use App\Models\ChannelTouchpointDaily;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Neema\FakeNeemaAnalyticsClient;
use App\Services\Neema\NeemaAnalyticsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\FreezesClockMidMonth;
use Tests\TestCase;

/**
 * Neema performance block on the Sales report:
 *
 *  1. channels:sync-touchpoints derives DAILY message-volume deltas from
 *     Neema's cumulative rollup (first sight = full count, normal night =
 *     increment, upstream counter reset = clamp at the incoming value,
 *     same-day re-run = accumulate, never double-count).
 *
 *  2. GET /reports/sales/neema returns the leads funnel, lead→order conversion
 *     within 14 days (joined on normalize_phone across number formats),
 *     WhatsApp-channel sales under the SAME rule as the ledger, contacts per
 *     platform, and message volume (period from daily snapshots, all-time from
 *     the cumulative table — deliberately separate, because cumulative totals
 *     cannot be sliced retroactively).
 */
class NeemaSalesReportTest extends TestCase
{
    use RefreshDatabase;
    use FreezesClockMidMonth;

    private function fakeRollup(array $rows): void
    {
        $this->app->instance(NeemaAnalyticsClient::class, new FakeNeemaAnalyticsClient($rows));
    }

    private function viewer(): User
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        $u->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $u->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    private function lead(array $attrs = []): Lead
    {
        // created_at is NOT in Lead::$fillable, so mass assignment silently
        // drops it and the lead lands at now() — backdate via the query
        // builder, which bypasses both the guard and the timestamp touch.
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $lead = Lead::create(array_merge([
            'client_request_id' => 'lead-' . uniqid('', true),
            'intent'            => 'quote',
            'readiness'         => 'high',
            'phone'             => '0712345678',
            'status'            => 'new',
        ], $attrs));

        if ($createdAt !== null) {
            Lead::whereKey($lead->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);
            $lead->refresh();
        }

        return $lead;
    }

    private function report(): array
    {
        return $this->getJson('/api/v1/admin/reports/sales/neema'
            . '?start_date=' . now()->subDays(7)->format('Y-m-d')
            . '&end_date=' . now()->format('Y-m-d'))
            ->assertOk()
            ->json();
    }

    // ── Daily delta capture ──────────────────────────────────────────────────

    public function test_first_sight_writes_the_full_count_as_that_days_delta(): void
    {
        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 10, 'inbound' => 4],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        $d = ChannelTouchpointDaily::where('phone', '254712345678')->where('channel', 'whatsapp')->first();
        $this->assertNotNull($d);
        $this->assertSame(now()->toDateString(), $d->date->toDateString());
        $this->assertSame(10, $d->messages);
        $this->assertSame(4, $d->inbound);
    }

    public function test_subsequent_syncs_write_only_the_increment_and_same_day_reruns_accumulate(): void
    {
        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 10, 'inbound' => 4],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        // Cumulative moved 10 → 16: the day's delta grows by 6, not by 16.
        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 16, 'inbound' => 6],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        $this->assertSame(1, ChannelTouchpointDaily::count());
        $d = ChannelTouchpointDaily::first();
        $this->assertSame(16, $d->messages);   // 10 (first sight) + 6 (increment)
        $this->assertSame(6, $d->inbound);     // 4 + 2

        // Re-run with NO upstream change: delta is zero, nothing double-counts.
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();
        $this->assertSame(16, ChannelTouchpointDaily::first()->messages);
    }

    public function test_an_upstream_counter_reset_clamps_the_delta_at_the_incoming_value(): void
    {
        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 100, 'inbound' => 50],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        // Upstream restarted its count (100 → 5). A subtraction would go
        // negative; the incoming value is the best "since reset" figure.
        $this->fakeRollup([
            ['phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 5, 'inbound' => 2],
        ]);
        $this->artisan('channels:sync-touchpoints')->assertSuccessful();

        $d = ChannelTouchpointDaily::first();
        $this->assertSame(105, $d->messages);  // 100 + clamped 5, never negative
        $this->assertSame(52, $d->inbound);

        // The cumulative row now holds the reset value, not the old maximum.
        $this->assertSame(5, ChannelTouchpoint::first()->messages);
    }

    // ── The report endpoint ──────────────────────────────────────────────────

    public function test_leads_funnel_counts_only_the_period_and_always_names_every_stage(): void
    {
        $this->viewer();

        $this->lead(['status' => 'won',  'intent' => 'quote',    'created_at' => now()->subDays(3)]);
        $this->lead(['status' => 'new',  'intent' => 'quote',    'created_at' => now()->subDays(2)]);
        $this->lead(['status' => 'lost', 'intent' => 'shipping', 'created_at' => now()->subDay()]);
        $this->lead(['status' => 'won',  'created_at' => now()->subDays(60)]); // outside period

        $leads = $this->report()['leads'];

        $this->assertSame(3, $leads['total']);
        $this->assertSame(['new', 'assigned', 'quoted', 'won', 'lost'], array_keys($leads['by_status']));
        $this->assertSame(1, $leads['by_status']['won']);
        $this->assertSame(0, $leads['by_status']['assigned']);   // present even when zero
        $this->assertEqualsWithDelta(33.3, $leads['won_rate'], 0.1);
        $this->assertSame('quote', $leads['by_intent'][0]['intent']);
        $this->assertSame(2, $leads['by_intent'][0]['count']);
    }

    public function test_conversion_matches_a_lead_to_its_order_across_phone_formats_within_14_days(): void
    {
        $this->viewer();

        // Lead stored the Kenyan local way; the order clerk typed +254….
        // normalize_phone() is what makes these the same person.
        $this->lead(['phone' => '0712345678', 'created_at' => now()->subDays(5)]);
        Order::factory()->create([
            'customer_phone' => '+254712345678',
            'total_amount'   => 5000,
            'status'         => 'confirmed',
            'created_at'     => now()->subDays(2),      // 3 days after the lead
        ]);

        // A lead whose order came 18 days later: outside the window.
        $this->lead(['phone' => '0722000001', 'created_at' => now()->subDays(20)]);
        Order::factory()->create([
            'customer_phone' => '0722000001',
            'total_amount'   => 900,
            'status'         => 'confirmed',
            'created_at'     => now()->subDays(2),
        ]);
        // (that lead is also outside the reporting period, so total stays 2 below)
        $this->lead(['phone' => '0722000002', 'created_at' => now()->subDay()]);

        $conv = $this->report()['lead_conversion'];

        $this->assertSame(1, $conv['converted']);
        $this->assertSame(1, $conv['orders']);
        $this->assertEqualsWithDelta(5000.0, $conv['revenue'], 0.01);
        $this->assertEqualsWithDelta(50.0, $conv['conversion_rate'], 0.1);  // 1 of 2 in-period leads
        $this->assertSame(14, $conv['window_days']);
    }

    public function test_an_order_placed_before_the_lead_does_not_count_as_a_conversion(): void
    {
        $this->viewer();

        $this->lead(['phone' => '0712345678', 'created_at' => now()->subDay()]);
        Order::factory()->create([
            'customer_phone' => '0712345678',
            'status'         => 'confirmed',
            'created_at'     => now()->subDays(5),       // BEFORE the lead
        ]);

        $this->assertSame(0, $this->report()['lead_conversion']['converted']);
    }

    public function test_whatsapp_sales_follow_the_same_channel_rule_as_the_ledger(): void
    {
        $this->viewer();

        $wa = Order::factory()->create([
            'order_type'   => 'whatsapp',
            'total_amount' => 4000,
            'status'       => 'confirmed',
            'created_at'   => now(),
        ]);
        Payment::factory()->create([
            'order_id' => $wa->id, 'amount' => 1500, 'refund_amount' => 0, 'status' => 'paid',
        ]);
        // POS order: not WhatsApp, must not leak in.
        Order::factory()->create([
            'order_type' => 'pos', 'total_amount' => 999, 'status' => 'confirmed', 'created_at' => now(),
        ]);

        $sales = $this->report()['whatsapp_sales'];

        $this->assertSame(1, $sales['orders']);
        $this->assertEqualsWithDelta(4000.0, $sales['revenue'], 0.01);
        $this->assertEqualsWithDelta(1500.0, $sales['paid'], 0.01);
        $this->assertEqualsWithDelta(2500.0, $sales['balance'], 0.01);
    }

    public function test_contacts_report_new_active_and_match_rate_per_platform(): void
    {
        $this->viewer();

        // New this period, matched to a customer.
        $customer = \App\Models\Customer::create(['first_name' => 'A', 'last_name' => 'B', 'phone' => '0712345678']);
        ChannelTouchpoint::create([
            'phone' => '254712345678', 'channel' => 'whatsapp', 'customer_id' => $customer->id,
            'messages' => 20, 'inbound' => 11,
            'first_seen' => now()->subDays(2), 'last_seen' => now()->subDay(),
        ]);
        // Old contact, active this period, unmatched.
        ChannelTouchpoint::create([
            'phone' => '254799999999', 'channel' => 'whatsapp',
            'messages' => 7, 'inbound' => 3,
            'first_seen' => now()->subDays(90), 'last_seen' => now()->subDay(),
        ]);
        // Old and dormant.
        ChannelTouchpoint::create([
            'phone' => '254788888888', 'channel' => 'messenger',
            'messages' => 2, 'inbound' => 2,
            'first_seen' => now()->subDays(90), 'last_seen' => now()->subDays(60),
        ]);

        $contacts = collect($this->report()['contacts'])->keyBy('channel');

        // All four messaging platforms always appear, in order.
        $this->assertSame(['whatsapp', 'messenger', 'instagram', 'facebook'], $contacts->keys()->all());

        $wa = $contacts['whatsapp'];
        $this->assertSame(1, $wa['new_contacts']);
        $this->assertSame(2, $wa['active_contacts']);
        $this->assertSame(2, $wa['contacts']);
        $this->assertEqualsWithDelta(50.0, $wa['match_rate'], 0.1);

        $this->assertSame(0, $contacts['messenger']['active_contacts']);
        $this->assertNull($contacts['instagram']['match_rate']);   // no contact base yet
    }

    public function test_message_volume_separates_period_snapshots_from_all_time_cumulative(): void
    {
        $this->viewer();

        // Cumulative (all-time) totals.
        ChannelTouchpoint::create([
            'phone' => '254712345678', 'channel' => 'whatsapp', 'messages' => 500, 'inbound' => 300,
        ]);
        // Daily snapshots: two in the period, one before it.
        ChannelTouchpointDaily::create([
            'date' => now()->subDays(2)->toDateString(), 'channel' => 'whatsapp',
            'phone' => '254712345678', 'messages' => 12, 'inbound' => 7,
        ]);
        ChannelTouchpointDaily::create([
            'date' => now()->subDay()->toDateString(), 'channel' => 'whatsapp',
            'phone' => '254712345678', 'messages' => 8, 'inbound' => 3,
        ]);
        ChannelTouchpointDaily::create([
            'date' => now()->subDays(30)->toDateString(), 'channel' => 'whatsapp',
            'phone' => '254712345678', 'messages' => 99, 'inbound' => 99,
        ]);

        $volume = $this->report()['message_volume'];
        $wa = collect($volume['channels'])->firstWhere('channel', 'whatsapp');

        $this->assertSame(20, $wa['period']['messages']);     // 12 + 8, NOT the 99 outside
        $this->assertSame(10, $wa['period']['inbound']);
        $this->assertSame(500, $wa['all_time']['messages']);  // cumulative, clearly separate
        $this->assertSame(300, $wa['all_time']['inbound']);
        $this->assertSame(now()->subDays(30)->toDateString(), $volume['daily_since']);
    }

    public function test_the_endpoint_requires_reports_view(): void
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        Sanctum::actingAs($u);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/v1/admin/reports/sales/neema')->assertForbidden();
    }
}
