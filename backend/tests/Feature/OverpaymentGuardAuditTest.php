<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Overpayment audit, 2026-10-01 (Reports hardening cycle 9 found 11 recognised
 * orders whose settled payments exceed their totals by KES 63,350).
 *
 * OrderController::addPayment is the reference guard: it counts paid AND
 * pending money, refuses an amount above the remaining balance, and re-checks
 * under a row lock. Every other door that writes money — or settles it, or
 * moves it, or moves the total underneath it — is held here to the same
 * invariant:
 *
 *     money committed to an order (paid + held-for-approval) never exceeds
 *     its total, and when a total change makes it so, that is never silent.
 *
 * Each test names the production order whose history it reproduces, where
 * there is one. These tests are written FAILING against main @ 211a4ee; they
 * are the acceptance criteria for the fixes, not characterisations.
 */
class OverpaymentGuardAuditTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->outlet = Outlet::factory()->create();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function actor(array $perms): User
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->syncWithoutDetaching([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function openRegister(User $user): void
    {
        CashRegister::create([
            'register_number' => "REG-{$this->outlet->id}-{$user->id}",
            'outlet_id'       => $this->outlet->id,
            'register_name'   => 'Till',
            'status'          => 'open',
            'currency_code'   => 'KES',
            'opening_balance' => 0,
            'expected_cash'   => 0,
            'total_sales' => 0, 'total_cash_sales' => 0, 'total_card_sales' => 0,
            'total_mpesa_sales' => 0, 'total_refunds' => 0, 'transaction_count' => 0,
            'opened_by'       => $user->id,
            'opened_at'       => now(),
        ]);
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type'               => 'pos',
            'outlet_id'                => $this->outlet->id,
            'status'                   => 'processing',
            'payment_status'           => 'pending',
            'currency_code'            => 'KES',
            'subtotal'                 => 1000,
            'total_amount'             => 1000,
            // A non-Kenyan number, so the remittance rails are offered.
            'customer_phone'           => '+1 415 555 0100',
            'payment_token'            => 'tok-' . bin2hex(random_bytes(8)),
            'payment_token_expires_at' => now()->addDays(7),
        ], $attrs));
    }

    private function heldPayment(Order $order, float $amount, string $method = 'wu_moneygram'): Payment
    {
        return Payment::factory()->create([
            'order_id'          => $order->id,
            'payment_method'    => $method,
            'amount'            => $amount,
            'status'            => 'pending',
            'requires_approval' => true,
            'approval_status'   => 'pending_review',
        ]);
    }

    private function committed(Order $order): float
    {
        return (float) Payment::where('order_id', $order->id)
            ->whereIn('status', ['paid', 'pending'])->sum('amount');
    }

    private function settled(Order $order): float
    {
        return (float) $order->fresh()->totalPaid();
    }

    private function setting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Doors that WRITE a payment
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * PosController::recordPosPay sizes its ceiling with outstandingBalance(),
     * which counts only status='paid'. A tender already held for approval is
     * invisible to it, so the full amount can be taken a second time; approving
     * the held one later settles the order twice. addPayment counts it.
     */
    public function test_till_payment_counts_money_already_held_for_approval(): void
    {
        $user  = $this->actor(['pos.access']);
        $this->openRegister($user);
        $order = $this->order();
        $this->heldPayment($order, 1000, 'bank_transfer');

        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'method' => 'cash', 'amount' => 1000, 'cash_received' => 1000,
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(1000.0, $this->committed($order), 0.01);
    }

    /**
     * PosController::createSale checks only a FLOOR (payments >= total), never
     * a ceiling. A cash line keyed at the tendered figure is recorded as the
     * payment with change_given 0 — the exact shape of orders 48/60/63/64/66/75
     * (cash_received = amount, change 0, amount > total).
     */
    public function test_a_new_till_sale_refuses_tenders_above_its_total(): void
    {
        $user = $this->actor(['pos.access']);
        $this->openRegister($user);

        $product = Product::factory()->create();
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => 1000, 'cost_price' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        $this->postJson('/api/v1/admin/pos/sales', [
            'outlet_id' => $this->outlet->id,
            'payment_method' => 'cash',
            'items'     => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000]],
            'payments'  => [['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000]],
        ])->assertStatus(422);

        $this->assertSame(0, Payment::where('amount', 5000)->count());
    }

    /**
     * ORDER 191 (BH-59FRJEQI), part one. The public pay page's manual rails
     * (Western Union/MoneyGram, Mukuru, bank transfer, M-Pesa-to-number) write a
     * pending payment for the full amount due on EVERY initiate, with no lock and
     * no replay check — and amountDue() ignores pending rows, so the second tap
     * is sized exactly like the first. Production: payments 178 and 179,
     * KES 21,800 each, 28 seconds apart, neither with proof.
     */
    public function test_a_double_tapped_manual_rail_on_the_pay_page_records_one_payment(): void
    {
        $order = $this->order(['order_type' => 'online', 'status' => 'pending']);

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'wu_moneygram'])->assertOk();
        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'wu_moneygram']);

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(1000.0, $this->committed($order), 0.01);
    }

    /**
     * Same door, different rail: once a held claim covers the order, a second
     * rail must not raise another claim for the whole amount.
     */
    public function test_the_pay_page_does_not_raise_a_second_claim_over_a_held_one(): void
    {
        $order = $this->order(['order_type' => 'online', 'status' => 'pending']);
        $this->heldPayment($order, 1000, 'wu_moneygram');

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'bank_transfer']);

        $this->assertEqualsWithDelta(1000.0, $this->committed($order), 0.01);
    }

    /**
     * PublicPaymentController::confirmMpesa only refuses a code that is already
     * PAID. Submitting the same code twice before staff review writes two
     * full-amount pending claims for one M-Pesa transaction.
     */
    public function test_the_same_mpesa_code_submitted_twice_is_one_claim(): void
    {
        $order = $this->order(['order_type' => 'online', 'status' => 'pending']);

        $this->postJson("/api/v1/pay/{$order->payment_token}/mpesa-confirm", ['transaction_code' => 'QJL3ABC7DE']);
        $this->postJson("/api/v1/pay/{$order->payment_token}/mpesa-confirm", ['transaction_code' => 'QJL3ABC7DE']);

        $this->assertSame(1, Payment::where('order_id', $order->id)->where('provider_reference', 'QJL3ABC7DE')->count());
    }

    /**
     * addPayment re-checks the ceiling under a lock but has no replay guard, so
     * a double-tapped PART payment (both halves fit under the total) is written
     * twice. recordPosPay already treats same method + same amount within
     * PAYMENT_REPLAY_WINDOW_SECONDS as the same submit arriving again; the
     * order screen's door should agree with the till's.
     */
    public function test_a_double_tapped_part_payment_on_the_order_screen_is_one_payment(): void
    {
        $this->actor(['orders.view', 'payments.record']);
        $order = $this->order();

        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 400])->assertSuccessful();
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", ["method" => "cash", "amount" => 400]);

        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    /**
     * PaymentController::initiatePayment (admin "send gateway request") charges
     * total_amount, not the balance — a part-paid order is asked for the whole
     * thing again. (No gateway payment exists in production yet; latent.)
     */
    public function test_an_admin_gateway_request_on_a_part_paid_order_asks_for_the_balance(): void
    {
        $this->actor(['orders.view', 'payments.record']);
        $this->setting('paystack_secret_key', 'sk_test_x');
        Http::fake(['api.paystack.co/*' => Http::response(['data' => [
            'reference' => 'REF-ADMIN', 'authorization_url' => 'https://pay.test', 'access_code' => 'ac',
        ]], 200)]);

        $order = $this->order(['payment_status' => 'partial']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 400]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment", [
            'payment_method' => 'card_paystack', 'email' => 'buyer@example.com',
        ])->assertOk();

        $this->assertEqualsWithDelta(600.0, (float) Payment::where('provider_reference', 'REF-ADMIN')->value('amount'), 0.01);
    }

    /**
     * Pay-page card flow: initiatePaystack writes the pending row as 'card',
     * verifyPaystack looks only for 'card_paystack', misses it, and creates a
     * SECOND (paid) row for the same reference. When Paystack's webhook then
     * lands it finds the first row by reference and settles that too — one
     * charge, counted twice. (Latent: no card payments in production yet.)
     */
    public function test_one_card_charge_on_the_pay_page_settles_once(): void
    {
        $this->setting('paystack_secret_key', 'sk_test_x');
        $order = $this->order(['order_type' => 'online', 'status' => 'pending']);

        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['data' => [
            'reference' => 'REF-CARD', 'authorization_url' => 'https://pay.test',
        ]], 200), 'api.paystack.co/transaction/verify/*' => Http::response(['data' => [
            'status' => 'success', 'amount' => 100000, 'currency' => 'KES',
            'metadata' => ['order_id' => $order->id],
        ]], 200)]);

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", [
            'method' => 'paystack', 'email' => 'buyer@example.com',
        ])->assertOk();
        $this->postJson("/api/v1/pay/{$order->payment_token}/paystack-verify", ['reference' => 'REF-CARD'])->assertOk();

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'REF-CARD', 'amount' => 100000]]);
        $this->call('POST', '/api/webhooks/paystack/webhook', [], [], [], [
            'CONTENT_TYPE'              => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_x'),
        ], $body)->assertOk();

        $this->assertSame(1, Payment::where('order_id', $order->id)->where('provider_reference', 'REF-CARD')->count());
        $this->assertEqualsWithDelta(1000.0, $this->settled($order), 0.01);
    }

    /**
     * Gateway webhooks flip whatever row matches the reference to 'paid' with
     * no status check — a re-delivered charge.success silently undoes a void.
     */
    public function test_a_redelivered_webhook_does_not_revive_a_voided_payment(): void
    {
        $this->setting('paystack_secret_key', 'sk_test_x');
        $order = $this->order(['payment_status' => 'pending']);
        Payment::factory()->create([
            'order_id' => $order->id, 'payment_method' => 'card_paystack', 'amount' => 1000,
            'status' => 'voided', 'provider_reference' => 'REF-VOID', 'voided_at' => now(),
        ]);

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'REF-VOID', 'amount' => 100000]]);
        $this->call('POST', '/api/webhooks/paystack/webhook', [], [], [], [
            'CONTENT_TYPE'              => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_x'),
        ], $body)->assertOk();

        $this->assertSame('voided', Payment::where('provider_reference', 'REF-VOID')->value('status'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Doors that SETTLE or MOVE money already written
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * ORDER 191, part two. PaymentApprovalController::approve flips a held
     * payment to paid with no lock and no look at what is already settled.
     * Production: user 8 approved payment 179 at 13:52:57 and 178 at 13:53:00
     * — both "approved_without_proof" — settling KES 43,600 on a 21,800 order.
     */
    public function test_approving_a_second_full_claim_on_a_settled_order_is_refused(): void
    {
        $this->actor(['payments.view', 'payments.approve_international']);
        $order = $this->order(['order_type' => 'online', 'status' => 'pending', 'payment_status' => 'pending_approval']);
        $first  = $this->heldPayment($order, 1000);
        $second = $this->heldPayment($order, 1000);

        $this->postJson("/api/v1/admin/payments/{$first->id}/approve", ["notes" => null])->assertOk();
        $this->postJson("/api/v1/admin/payments/{$second->id}/approve", ["notes" => null])->assertStatus(422);

        $this->assertEqualsWithDelta(1000.0, $this->settled($order), 0.01);
    }

    /**
     * PaymentController::reassignPayment moves a payment onto any order with no
     * look at that order's balance, and never re-syncs its payment_status.
     */
    public function test_reassigning_a_payment_onto_a_settled_order_is_refused(): void
    {
        $this->actor(['payments.view', 'payments.reassign']);
        $source = $this->order(['payment_status' => 'paid']);
        $target = $this->order(['payment_status' => 'paid']);
        $moving = Payment::factory()->create(['order_id' => $source->id, 'amount' => 1000]);
        Payment::factory()->create(['order_id' => $target->id, 'amount' => 1000]);

        $this->postJson("/api/v1/admin/payment-transactions/{$moving->id}/reassign", [
            'order_id' => $target->id, 'reason' => 'keyed against the wrong order',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(1000.0, $this->settled($target), 0.01);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Doors that move the TOTAL underneath money already taken
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * ORDER 105 (POS-260708-QKOMG), root cause. addPayment's held-payment branch
     * reads $order->payments — the collection loaded BEFORE the new row was
     * inserted — so it never sees the payment it just wrote and leaves
     * payment_status at 'pending'. Production log 1380 says exactly that:
     * new_payment_status "pending" for a USD 95 held payment.
     */
    public function test_a_held_payment_on_the_order_screen_marks_the_order_pending_approval(): void
    {
        $this->actor(['orders.view', 'payments.record']);
        $order = $this->order();

        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'wu_moneygram', 'amount' => 1000,
        ])->assertSuccessful();

        $this->assertSame('pending_approval', $order->fresh()->payment_status);
    }

    /**
     * ORDER 105, consequence. updateCurrency's guard reads the cached
     * payment_status, so with the stale 'pending' above it repriced an order
     * carrying a USD 95 held payment (a no-op USD→USD change of country;
     * the line went to 0.00, total 95 → 20). The guard must read the money,
     * not the label.
     */
    public function test_the_currency_cannot_change_under_a_held_payment(): void
    {
        $this->actor(['orders.view', 'orders.edit']);
        DB::table('countries')->updateOrInsert(
            ['code' => 'UG'],
            ['name' => 'Uganda', 'default_currency_code' => 'KES', 'created_at' => now(), 'updated_at' => now()],
        );
        $order   = $this->order(['payment_status' => 'pending', 'customer_country_code' => 'TZ']);
        $product = Product::factory()->create();
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'sku' => $product->sku,
            'product_name' => 'Prayer Shawl', 'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000,
        ]);
        $this->heldPayment($order, 1000);   // payment_status left stale at 'pending'

        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'UG'])
            ->assertStatus(422);
    }

    /**
     * OrderController::setShippingFee lets an administrator lower shipping on a
     * paid order (rightly — orders.reduce_shipping_fee), but when the new total
     * falls below the money collected it just writes 'paid'. The line editor
     * (OrderLineEditor, order 389) surfaces the same situation as REFUND DUE on
     * the order; this door says nothing. A total that drops under the money
     * received must never be silent.
     */
    public function test_lowering_shipping_below_the_money_collected_is_not_silent(): void
    {
        $this->actor(['orders.view', 'orders.set_shipping_fee', 'orders.reduce_shipping_fee']);
        $order = $this->order(['subtotal' => 700, 'shipping_amount' => 300, 'total_amount' => 1000, 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000]);

        $res = $this->patchJson("/api/v1/admin/orders/{$order->id}/shipping-fee", ['amount' => 0]);

        $refused  = $res->status() === 422;
        $surfaced = str_contains((string) $order->fresh()->notes, 'REFUND DUE');
        $this->assertTrue($refused || $surfaced, 'Shipping cut below collected money: neither refused nor surfaced as a refund due.');
    }
}
