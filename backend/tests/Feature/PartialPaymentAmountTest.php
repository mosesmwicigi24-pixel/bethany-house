<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every rail must charge what the page SAYS.
 *
 * The page has always shown `amount_due` while M-Pesa STK, Paystack and the
 * manual rails each charged `total_amount`. On a deposit or part-paid order the
 * customer was shown one number and billed another — and deposits are routine
 * here, with a whole Outstanding Balances screen devoted to them.
 */
class PartialPaymentAmountTest extends TestCase
{
    use RefreshDatabase;

    /** Order of 10,000 with 4,000 already paid — the balance is 6,000. */
    private function partPaidOrder(): Order
    {
        $order = Order::factory()->create([
            'status'         => 'confirmed',
            'payment_status' => 'deposit',
            'currency_code'  => 'KES',
            'total_amount'   => 10000,
            'payment_token'            => bin2hex(random_bytes(16)),
            'payment_token_expires_at' => now()->addHours(72),
        ]);
        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'paid', 'amount' => 4000,
            'payment_method' => 'mpesa',
        ]);

        return $order;
    }

    public function test_the_page_offers_the_balance_not_the_total(): void
    {
        $order = $this->partPaidOrder();

        $body = $this->getJson("/api/v1/pay/{$order->payment_token}")->assertOk()->json();

        $this->assertSame(6000.0, (float) $body['amount_due']);
        $this->assertSame(10000.0, (float) $body['total_amount']);
    }

    public function test_a_manual_rail_records_the_balance_not_the_total(): void
    {
        $order = $this->partPaidOrder();

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", [
            'method' => 'mpesa_manual',
        ])->assertOk();

        $pending = Payment::where('order_id', $order->id)->where('status', 'pending')->first();

        $this->assertNotNull($pending);
        $this->assertSame(6000.0, (float) $pending->amount,
            'the customer was shown 6,000 — billing 10,000 is the defect this pins');
    }

    public function test_the_balance_is_what_remains_after_every_paid_payment(): void
    {
        $order = $this->partPaidOrder();
        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'paid', 'amount' => 1000,
            'payment_method' => 'cash',
        ]);
        // A pending payment must NOT reduce the balance — only money that arrived.
        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'pending', 'amount' => 5000,
            'payment_method' => 'mpesa',
        ]);

        $body = $this->getJson("/api/v1/pay/{$order->payment_token}")->assertOk()->json();

        $this->assertSame(5000.0, (float) $body['amount_due'], '10,000 − 4,000 − 1,000');
    }

    public function test_a_fully_paid_order_offers_no_rails_at_all(): void
    {
        $order = $this->partPaidOrder();
        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'paid', 'amount' => 6000,
            'payment_method' => 'mpesa',
        ]);

        $body = $this->getJson("/api/v1/order/{$order->public_token}")->assertOk()->json();

        $this->assertSame(0.0, (float) $body['amount_due']);
        $this->assertSame([], $body['available_methods']);
    }

    public function test_the_paybill_is_hidden_from_customers_while_typed_as_cash(): void
    {
        // Documents a LIVE configuration fact rather than asserting it is right:
        // show() excludes type 'cash', and production's "I&M Paybill" row is
        // typed cash — so the business's own paybill reaches no customer. The
        // fix is a data decision for the owner, not a code change, and this
        // test will fail the day that row is retyped, which is the reminder to
        // give it instructions at the same time.
        DB::table('payment_methods')->updateOrInsert(
            ['code' => 'inmpaybill'],
            ['name' => 'I&M Paybill', 'type' => 'cash', 'is_active' => true],
        );
        $order = $this->partPaidOrder();

        $codes = collect($this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()->json('available_methods'))->pluck('code')->all();

        $this->assertNotContains('inmpaybill', $codes);
    }
}
