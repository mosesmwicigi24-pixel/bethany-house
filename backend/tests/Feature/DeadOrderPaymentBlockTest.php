<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\PaymentLinkService;
use App\Services\Reporting\MetricEngine;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Block payment on dead orders (owner, 2026-10-26: "yes, block payment on
 * cancelled orders").
 *
 * Found by the void-invoice build (#496): /pay/{token} never looked at the
 * order's status, and the public order page and the console's "Payment link"
 * could mint a fresh link for a cancelled order. Now one rule —
 * Order::isPayable() — is asked by every door that STARTS or OFFERS a payment.
 *
 * Money already in flight is the other half: a push approved before the
 * cancel still lands, is recorded, is flagged "Paid after cancel — refund
 * needed", tells finance, and never revives the order or its sale.
 */
class DeadOrderPaymentBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
        ReportingCurrency::forget();
    }

    private function order(string $status, array $attrs = []): Order
    {
        return Order::factory()->create($attrs + [
            'order_type'               => 'online',
            'status'                   => $status,
            'payment_status'           => 'pending',
            'currency_code'            => 'KES',
            'subtotal'                 => 1000,
            'total_amount'             => 1000,
            'payment_token'            => 'TOK' . strtoupper(bin2hex(random_bytes(6))),
            'payment_token_expires_at' => now()->addDay(),
        ]);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    private function financeManager(): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findOrCreate('finance_manager', 'sanctum'));
        $u->givePermissionTo(Permission::findOrCreate('payments.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function stkCallback(string $checkoutId, float $amount, string $receipt): array
    {
        return ['Body' => ['stkCallback' => [
            'ResultCode'        => 0,
            'ResultDesc'        => 'The service request is processed successfully.',
            'CheckoutRequestID' => $checkoutId,
            'CallbackMetadata'  => ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
            ]],
        ]]];
    }

    private function window(): array
    {
        return [now()->subDay()->startOfDay(), now()->addDay()->endOfDay(), now()->subDays(3), now()->subDays(2)];
    }

    // ── The customer's pay page ──────────────────────────────────────────────

    public function test_the_pay_page_refuses_every_dead_status_calmly(): void
    {
        foreach (['cancelled' => 'cancelled', 'voided' => 'cancelled', 'refunded' => 'refunded'] as $status => $word) {
            $order = $this->order($status);

            $this->getJson("/api/v1/pay/{$order->payment_token}")
                ->assertStatus(410)
                ->assertJson([
                    'message'      => "This order was {$word} — please contact us.",
                    'reason'       => 'order_not_payable',
                    'order_number' => $order->order_number,
                    'order_status' => $status,
                ])
                ->assertJsonStructure(['contact' => ['business_name', 'phone', 'email']])
                ->assertJsonMissingPath('available_methods');
        }

        $this->assertSame(0, Payment::count(), 'no payment was started');
    }

    public function test_a_voided_invoice_s_expired_link_still_says_cancelled_not_not_found(): void
    {
        // InvoiceVoid (#496) expires the link as it cancels the order.
        $order = $this->order('cancelled', ['payment_token_expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertStatus(410)
            ->assertJsonPath('message', 'This order was cancelled — please contact us.');
    }

    public function test_no_customer_action_can_start_a_payment_on_a_dead_order(): void
    {
        $order = $this->order('cancelled');
        $t     = $order->payment_token;

        $this->postJson("/api/v1/pay/{$t}/initiate", ['method' => 'mpesa', 'phone' => '0712345678'])->assertStatus(410);
        $this->postJson("/api/v1/pay/{$t}/initiate", ['method' => 'paystack', 'email' => 'a@b.test'])->assertStatus(410);
        $this->postJson("/api/v1/pay/{$t}/initiate", ['method' => 'bank_transfer'])->assertStatus(410);
        $this->postJson("/api/v1/pay/{$t}/mpesa-confirm", ['transaction_code' => 'QJL3ABC7DE'])->assertStatus(410);
        $this->postJson("/api/v1/pay/{$t}/upload-proof", ['payment_id' => 1])->assertStatus(410);

        Http::assertNothingSent();   // no STK push, no card session
        $this->assertSame(0, Payment::count(), 'nothing recorded, not even a pending intent');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_public_order_page_offers_no_payment_and_will_not_mint_a_link(): void
    {
        $order = $this->order('cancelled', ['payment_token' => null, 'payment_token_expires_at' => null]);

        $body = $this->getJson("/api/v1/order/{$order->public_token}")->assertOk()->json();
        $this->assertFalse($body['payable']);
        $this->assertSame([], $body['available_methods']);
        $this->assertSame('This order was cancelled — please contact us.', $body['closed']['message']);

        $this->postJson("/api/v1/order/{$order->public_token}/pay-session")
            ->assertStatus(410)
            ->assertJsonPath('message', 'This order was cancelled — please contact us.');
        $this->assertNull($order->fresh()->payment_token, 'no link was minted');
    }

    public function test_the_minting_service_itself_refuses_a_dead_order(): void
    {
        $this->expectException(\App\Exceptions\OrderNotPayableException::class);
        PaymentLinkService::mint($this->order('voided'));
    }

    public function test_storefront_and_invoice_listings_hand_out_no_pay_link_for_a_dead_order(): void
    {
        $order = $this->order('cancelled');

        $this->getJson("/api/v1/storefront/orders/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('payment_link', null);
    }

    // ── Staff doors ──────────────────────────────────────────────────────────

    public function test_staff_cannot_issue_a_payment_link_for_a_dead_order(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $order  = $this->order('cancelled', ['payment_token_expires_at' => now()->subMinute()]);
        $before = $order->payment_token;

        $this->getJson("/api/v1/admin/orders/{$order->id}/payment-link")
            ->assertStatus(422)
            ->assertJson([
                'message' => "Order {$order->order_number} is cancelled — a payment can't be recorded against it.",
                'reason'  => 'order_not_payable',
            ]);

        $fresh = $order->fresh();
        $this->assertSame($before, $fresh->payment_token);
        $this->assertTrue($fresh->payment_token_expires_at->isPast(), 'the expired link was not renewed');
    }

    public function test_staff_cannot_record_a_payment_against_a_dead_order(): void
    {
        Sanctum::actingAs($this->superAdmin());

        foreach (['cancelled' => 'is cancelled', 'voided' => 'is voided', 'refunded' => 'has been refunded'] as $status => $state) {
            $order = $this->order($status);

            $this->postJson("/api/v1/admin/orders/{$order->id}/payments", ['method' => 'cash', 'amount' => 500])
                ->assertStatus(422)
                ->assertJsonPath('message', "Order {$order->order_number} {$state} — a payment can't be recorded against it.");
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_staff_cannot_push_an_stk_or_card_session_for_a_dead_order(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $order = $this->order('cancelled');

        $this->postJson("/api/v1/admin/orders/{$order->id}/payment", ['payment_method' => 'mpesa', 'phone' => '0712345678'])
            ->assertStatus(422)->assertJsonPath('reason', 'order_not_payable');
        $this->postJson("/api/v1/orders/{$order->id}/payment", ['payment_method' => 'card_paystack', 'email' => 'a@b.test'])
            ->assertStatus(422)->assertJsonPath('reason', 'order_not_payable');

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
    }

    public function test_the_till_cannot_take_payment_on_a_dead_order(): void
    {
        $outlet = Outlet::factory()->create();
        $user   = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        $user->outlets()->attach($outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $order = $this->order('cancelled', ['order_type' => 'pos', 'outlet_id' => $outlet->id]);

        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", ['method' => 'cash', 'amount' => 1000, 'cash_received' => 1000])
            ->assertStatus(422)
            ->assertJsonPath('message', "Order {$order->order_number} is cancelled — a payment can't be recorded against it.");

        $this->assertSame(0, Payment::count());
    }

    // ── A live order is untouched ────────────────────────────────────────────

    public function test_a_live_order_is_still_payable_at_every_door(): void
    {
        DB::table('payment_methods')->updateOrInsert(['code' => 'bank_transfer'], [
            'name' => 'Bank transfer', 'type' => 'bank_transfer', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = $this->order('pending');

        $page = $this->getJson("/api/v1/pay/{$order->payment_token}")->assertOk()->json();
        $this->assertSame(1000.0, (float) $page['amount_due']);
        $this->assertNotEmpty($page['available_methods']);

        $this->postJson("/api/v1/pay/{$order->payment_token}/initiate", ['method' => 'bank_transfer'])->assertOk();

        $body = $this->getJson("/api/v1/order/{$order->public_token}")->assertOk()->json();
        $this->assertTrue($body['payable']);
        $this->assertNull($body['closed']);

        Sanctum::actingAs($this->superAdmin());
        $this->getJson("/api/v1/admin/orders/{$order->id}/payment-link")->assertOk()->assertJsonStructure(['payment_url']);

        $live = $this->order('processing');
        $this->postJson("/api/v1/admin/orders/{$live->id}/payments", ['method' => 'cash', 'amount' => 400])->assertCreated();
    }

    // ── Money already in flight ──────────────────────────────────────────────

    public function test_an_stk_approved_before_the_cancel_is_recorded_flagged_and_never_revives_the_order(): void
    {
        $fm    = $this->financeManager();
        $order = $this->order('pending');
        $push  = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'mpesa', 'amount' => 1000, 'currency_code' => 'KES',
            'status' => 'pending', 'provider_reference' => 'ws_CO_LATE_1', 'recorded_by' => null,
        ]);

        // Staff cancel while the customer's phone is still showing the prompt.
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        $this->postJson("/api/v1/pay/{$order->payment_token}/mpesa-callback", $this->stkCallback('ws_CO_LATE_1', 1000, 'RJK1LATE01'))
            ->assertOk()->assertJson(['ResultCode' => 0]);

        $push->refresh();
        $this->assertSame('paid', $push->status, 'real money is recorded, never refused');
        $this->assertSame('RJK1LATE01', $push->provider_reference);
        $this->assertNotNull($push->received_after_cancel_at, 'flagged: paid after cancel');
        $this->assertTrue($push->needsRefundAfterCancel());
        $this->assertSame('cancelled', $order->fresh()->status, 'the order stays cancelled');

        Notification::assertSentTo($fm, InAppNotification::class,
            fn (InAppNotification $n) => str_contains($n->title, 'Paid after cancel — refund needed')
                && ($n->data['order_number'] ?? null) === $order->order_number);

        $this->assertTrue(DB::table('activity_log')
            ->where('event', 'payment_received_after_cancel')
            ->where('subject_type', Order::class)->where('subject_id', $order->id)->exists(), 'audited');

        // Collected counts money received; Sold and the receivables do not.
        [$s, $e, $ps, $pe] = $this->window();
        $engine = MetricEngine::unscoped();
        $this->assertEquals(0.0, $engine->revenue($s, $e, $ps, $pe)['current'], 'not counted as Sold');
        $this->assertEquals(0.0, $engine->ordersCount($s, $e, $ps, $pe)['current']);
        $this->assertEquals(1000.0, $engine->collected($s, $e, $ps, $pe)['current'], 'it is money received');
        $this->assertEquals(0.0, $engine->outstandingBalance()['amount'], 'and no receivable');
        $this->assertSame(0, Order::recognised()->whereKey($order->id)->count());

        $check = collect($engine->dataQuality($s, $e, true)['checks'])->firstWhere('key', 'paid_after_cancel');
        $this->assertSame(1, $check['count']);
        $this->assertEquals(1000.0, (float) $check['value']);

        // Daraja re-delivers: still one payment, still one flag.
        $this->postJson("/api/v1/pay/{$order->payment_token}/mpesa-callback", $this->stkCallback('ws_CO_LATE_1', 1000, 'RJK1LATE01'))->assertOk();
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'payment_received_after_cancel')->count());
    }

    public function test_the_shared_daraja_webhook_records_late_money_the_same_way(): void
    {
        $order = $this->order('pending');
        $push  = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'mpesa', 'amount' => 1000, 'currency_code' => 'KES',
            'status' => 'pending', 'provider_reference' => 'ws_CO_LATE_2', 'recorded_by' => null,
        ]);
        $order->forceFill(['status' => 'voided'])->save();

        $this->postJson('/api/webhooks/mpesa/callback', $this->stkCallback('ws_CO_LATE_2', 1000, 'RJK1LATE02'))->assertOk();

        $push->refresh();
        $this->assertSame('paid', $push->status);
        $this->assertNotNull($push->received_after_cancel_at);
        $this->assertSame('voided', $order->fresh()->status);
    }

    public function test_approving_a_bank_proof_after_the_cancel_records_it_flagged_without_reviving_the_order(): void
    {
        $order = $this->order('pending');
        $proof = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'bank_transfer', 'amount' => 1000, 'currency_code' => 'KES',
            'status' => 'pending', 'requires_approval' => true, 'approval_status' => 'pending_review', 'recorded_by' => null,
        ]);
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        Sanctum::actingAs($this->superAdmin());
        $this->postJson("/api/v1/admin/payments/{$proof->id}/approve")
            ->assertOk()
            ->assertJsonPath('received_after_cancel', true)
            ->assertJsonPath('message', "Payment recorded. Order {$order->order_number} is cancelled, so it stays that way — the money is flagged \"Paid after cancel — refund needed\" for finance.");

        $proof->refresh();
        $this->assertSame('paid', $proof->status);
        $this->assertSame('approved', $proof->approval_status);
        $this->assertNotNull($proof->received_after_cancel_at);
        // Before: approval set a cancelled order back to 'confirmed'.
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_money_settling_on_a_live_order_is_not_flagged(): void
    {
        $order = $this->order('pending');
        $push  = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'mpesa', 'amount' => 1000, 'currency_code' => 'KES',
            'status' => 'pending', 'provider_reference' => 'ws_CO_LIVE_1', 'recorded_by' => null,
        ]);

        $this->postJson("/api/v1/pay/{$order->payment_token}/mpesa-callback", $this->stkCallback('ws_CO_LIVE_1', 1000, 'RJK1LIVE01'))->assertOk();

        $this->assertNull($push->fresh()->received_after_cancel_at);
        $this->assertSame('confirmed', $order->fresh()->status, 'a paid live order is confirmed as before');
        $this->assertSame(0, DB::table('activity_log')->where('event', 'payment_received_after_cancel')->count());
    }
}
