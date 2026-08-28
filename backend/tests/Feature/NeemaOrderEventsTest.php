<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the hub tells Neema about an order — a path that had ZERO test
 * coverage, which is why two whole classes of silence went unnoticed:
 *
 *  1. `confirmed` had no arm on the observer. Confirmation is the moment an
 *     order becomes income, and in 30 days production emitted order.paid,
 *     order.production_started and order.delivered — never a confirmation. So
 *     Neema could not show a confirmed order as confirmed, because nothing
 *     ever told her.
 *
 *  2. Every real fulfilment write used DB::table()->update(), which fires no
 *     model events. Shipping, delivering and settling a held payment emitted
 *     nothing at all.
 */
class NeemaOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.neema.events_secret' => 'test-secret',
                'services.neema.url'           => 'https://neema.test']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->create($attrs + [
            'status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 5000,
        ]);
    }

    /** @return array<int,array> every event payload the hub sent */
    private function sent(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request, $_response]) {
            if (str_contains($request->url(), '/api/hub/events')) {
                $out[] = json_decode($request->body(), true);
            }
        }
        return $out;
    }

    private function types(): array
    {
        return array_column($this->sent(), 'type');
    }

    public function test_confirming_an_order_announces_it(): void
    {
        $order = $this->order();

        $order->update(['status' => 'confirmed']);

        $this->assertContains('order.confirmed', $this->types(),
            'confirmation is when an order becomes income — Neema must hear it');
    }

    public function test_the_payload_carries_enough_state_to_mirror(): void
    {
        $order = $this->order();
        $order->update(['status' => 'confirmed']);

        $event = collect($this->sent())->firstWhere('type', 'order.confirmed');

        // Without these, Neema can only use an event she can match to a
        // conversation — and 23 of 27 order.paid events in a month were
        // dropped as 'no_conversation'.
        $this->assertSame($order->id, $event['hub_order_id']);
        $this->assertSame('confirmed', $event['status']);
        $this->assertSame('pending', $event['payment_status']);
        $this->assertStringContainsString("/order/{$order->public_token}", $event['public_url']);
    }

    public function test_delivering_an_order_announces_it(): void
    {
        $order = $this->order(['status' => 'shipped']);

        $order->update(['status' => 'delivered']);

        $this->assertContains('order.delivered', $this->types());
    }

    public function test_cancelling_an_order_announces_it(): void
    {
        $order = $this->order();

        $order->update(['status' => 'cancelled']);

        $this->assertContains('order.cancelled', $this->types());
    }

    public function test_a_raw_db_update_is_the_bug_this_suite_exists_for(): void
    {
        // Pins the REASON the shipment writes were converted to Eloquent: a raw
        // update changes the row and announces nothing. If someone reintroduces
        // DB::table()->update() for a status, this test documents the cost.
        $order = $this->order();

        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $order->id)->update(['status' => 'confirmed']);

        $this->assertNotContains('order.confirmed', $this->types(),
            'a raw update fires no model events — which is exactly why the '
            . 'fulfilment writes now use Eloquent');
    }

    public function test_the_emitter_is_inert_without_a_secret(): void
    {
        config(['services.neema.events_secret' => '']);
        $order = $this->order();

        $order->update(['status' => 'confirmed']);

        $this->assertSame([], $this->sent());
    }

    public function test_an_unreachable_neema_never_breaks_an_order_save(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $order = $this->order();

        $order->update(['status' => 'confirmed']);

        $this->assertSame('confirmed', $order->fresh()->status,
            'the order save must survive a dead Neema');
    }
}
