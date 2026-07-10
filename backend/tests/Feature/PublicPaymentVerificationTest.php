<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The public payment link is unauthenticated (token only). Marking an order paid
 * must require real proof for the right amount — never a customer-typed code or a
 * gateway "success" whose amount/ownership is unchecked. Otherwise a customer can
 * settle their own order for free.
 */
class PublicPaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function payableOrder(float $total = 5000, string $currency = 'KES', string $token = 'TOK-1'): Order
    {
        return Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'unpaid',
            'total_amount'   => $total,
            'currency_code'  => $currency,
            'payment_token'  => $token,
        ]);
    }

    // ── M-Pesa "I paid, here's my code" ──────────────────────────────────────

    public function test_customer_submitted_mpesa_code_is_never_auto_confirmed(): void
    {
        Notification::fake();
        $order = $this->payableOrder(total: 5000, token: 'TOK-MP');

        $res = $this->postJson('/api/v1/pay/TOK-MP/mpesa-confirm', [
            'transaction_code' => 'QJL3ABC7DE',
        ]);

        $res->assertOk()->assertJson(['confirmed' => false, 'payment_status' => 'pending_approval']);

        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status);
        $this->assertTrue((bool) $payment->requires_approval);
        $this->assertNull($payment->paid_at);

        // The order must NOT be paid off an unverified code.
        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    // ── Paystack redirect verification ───────────────────────────────────────

    private function fakePaystack(array $data): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'paystack_secret_key'],
            ['value' => 'sk_test_x', 'updated_at' => now(), 'created_at' => now()],
        );
        Http::fake([
            'api.paystack.co/*' => Http::response(['data' => $data], 200),
        ]);
    }

    private function pendingPaystackPayment(Order $order, string $reference): void
    {
        Payment::create([
            'order_id'           => $order->id,
            'payment_method'     => 'card_paystack',
            'amount'             => $order->total_amount,
            'currency_code'      => $order->currency_code,
            'status'             => 'pending',
            'provider_reference' => $reference,
        ]);
    }

    public function test_paystack_verify_confirms_on_matching_amount_and_currency(): void
    {
        Notification::fake();
        $order = $this->payableOrder(total: 5000, token: 'TOK-PS');
        $this->pendingPaystackPayment($order, 'REF-OK');
        $this->fakePaystack(['status' => 'success', 'amount' => 500000, 'currency' => 'KES']);

        $this->postJson('/api/v1/pay/TOK-PS/paystack-verify', ['reference' => 'REF-OK'])
            ->assertOk()->assertJson(['confirmed' => true]);

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_paystack_verify_rejects_an_underpaying_reference(): void
    {
        Notification::fake();
        $order = $this->payableOrder(total: 5000, token: 'TOK-PS2');
        $this->pendingPaystackPayment($order, 'REF-CHEAP');
        // Gateway says success, but only KES 1 (100 minor) was paid.
        $this->fakePaystack(['status' => 'success', 'amount' => 100, 'currency' => 'KES']);

        $this->postJson('/api/v1/pay/TOK-PS2/paystack-verify', ['reference' => 'REF-CHEAP'])
            ->assertStatus(422);

        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_paystack_verify_rejects_a_currency_mismatch(): void
    {
        Notification::fake();
        $order = $this->payableOrder(total: 5000, currency: 'KES', token: 'TOK-PS3');
        $this->pendingPaystackPayment($order, 'REF-USD');
        $this->fakePaystack(['status' => 'success', 'amount' => 500000, 'currency' => 'USD']);

        $this->postJson('/api/v1/pay/TOK-PS3/paystack-verify', ['reference' => 'REF-USD'])
            ->assertStatus(422);

        $this->assertNotSame('paid', $order->fresh()->payment_status);
    }

    public function test_paystack_reference_cannot_be_replayed_across_orders(): void
    {
        Notification::fake();
        // A reference already settled on another order.
        $other = $this->payableOrder(total: 5000, token: 'TOK-OTHER');
        Payment::create([
            'order_id' => $other->id, 'payment_method' => 'card_paystack',
            'amount' => 5000, 'currency_code' => 'KES',
            'status' => 'paid', 'provider_reference' => 'REF-REUSED', 'paid_at' => now(),
        ]);

        $victim = $this->payableOrder(total: 5000, token: 'TOK-VICTIM');
        $this->pendingPaystackPayment($victim, 'REF-REUSED');
        $this->fakePaystack(['status' => 'success', 'amount' => 500000, 'currency' => 'KES']);

        $this->postJson('/api/v1/pay/TOK-VICTIM/paystack-verify', ['reference' => 'REF-REUSED'])
            ->assertStatus(422);

        $this->assertNotSame('paid', $victim->fresh()->payment_status);
    }
}
