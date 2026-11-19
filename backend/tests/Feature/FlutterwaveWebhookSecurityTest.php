<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security audit 2026-11-19, PAY-01. With no secret hash configured, a
 * request carrying no `verif-hash` header "matched" the absent secret, and
 * the reference was matched on a fragment — so one unsigned request could
 * mark a payment paid. The webhook now refuses without a configured secret,
 * matches the whole reference, and never settles twice or for less.
 */
class FlutterwaveWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/webhooks/flutterwave/webhook';

    private function pending(float $amount = 4500.00): Payment
    {
        $order = Order::factory()->create([
            'status' => 'pending', 'payment_status' => 'pending',
            'total_amount' => $amount, 'currency_code' => 'KES',
        ]);

        return Payment::factory()->create([
            'order_id' => $order->id, 'payment_method' => 'card_flutterwave',
            'amount' => $amount, 'currency_code' => 'KES', 'status' => 'pending',
            'provider_reference' => 'BH-1001-1763500000', 'paid_at' => null,
        ]);
    }

    private function charge(array $over = []): array
    {
        return ['event' => 'charge.completed', 'data' => array_merge([
            'status' => 'successful', 'tx_ref' => 'BH-1001-1763500000',
            'flw_ref' => 'FLW-REF-1', 'amount' => 4500, 'currency' => 'KES',
        ], $over)];
    }

    public function test_with_no_secret_configured_nothing_is_trusted(): void
    {
        config(['services.flutterwave.secret_hash' => null]);
        $payment = $this->pending();

        $this->postJson(self::URL, $this->charge())->assertStatus(400);
        $this->postJson(self::URL, $this->charge(), ['verif-hash' => ''])->assertStatus(400);
        $this->postJson(self::URL, $this->charge(), ['verif-hash' => 'anything'])->assertStatus(400);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $payment->order->fresh()->payment_status);
    }

    public function test_a_missing_or_wrong_hash_is_refused(): void
    {
        config(['services.flutterwave.secret_hash' => 'the-dashboard-hash']);
        $payment = $this->pending();

        $this->postJson(self::URL, $this->charge())->assertStatus(400);
        $this->postJson(self::URL, $this->charge(), ['verif-hash' => 'wrong'])->assertStatus(400);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_signed_notice_settles_the_payment_once(): void
    {
        config(['services.flutterwave.secret_hash' => 'the-dashboard-hash']);
        $payment = $this->pending();
        $h = ['verif-hash' => 'the-dashboard-hash'];

        $this->postJson(self::URL, $this->charge(), $h)->assertOk();
        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame('FLW-REF-1', $fresh->provider_reference);
        $paidAt = $fresh->paid_at;

        // delivered again, under either reference: nothing moves
        $this->postJson(self::URL, $this->charge(['tx_ref' => 'FLW-REF-1']), $h)->assertOk();
        $this->postJson(self::URL, $this->charge(), $h)->assertOk();
        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertSame(1, Payment::where('order_id', $payment->order_id)->where('status', 'paid')->count());
    }

    public function test_a_fragment_of_a_reference_matches_nothing(): void
    {
        config(['services.flutterwave.secret_hash' => 'the-dashboard-hash']);
        $payment = $this->pending();
        $h = ['verif-hash' => 'the-dashboard-hash'];

        foreach (['BH', 'BH-1001', '1', '%', ''] as $fragment) {
            $this->postJson(self::URL, $this->charge(['tx_ref' => $fragment]), $h)->assertOk();
        }
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_another_methods_payment_is_never_settled_here(): void
    {
        config(['services.flutterwave.secret_hash' => 'the-dashboard-hash']);
        $payment = $this->pending();
        $payment->update(['payment_method' => 'mpesa']);

        $this->postJson(self::URL, $this->charge(), ['verif-hash' => 'the-dashboard-hash'])->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_less_than_asked_or_another_currency_is_held_not_settled(): void
    {
        config(['services.flutterwave.secret_hash' => 'the-dashboard-hash']);
        $h = ['verif-hash' => 'the-dashboard-hash'];

        $short = $this->pending();
        $this->postJson(self::URL, $this->charge(['amount' => 45]), $h)->assertOk();
        $this->assertSame('pending', $short->fresh()->status);
        $this->assertTrue((bool) $short->fresh()->requires_approval);

        $short->update(['provider_reference' => 'elsewhere']);
        $other = $this->pending();
        $this->postJson(self::URL, $this->charge(['currency' => 'UGX']), $h)->assertOk();
        $this->assertSame('pending', $other->fresh()->status);
    }
}
