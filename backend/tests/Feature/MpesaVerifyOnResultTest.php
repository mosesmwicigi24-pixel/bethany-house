<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The M-Pesa "verify" button, receipt-code strategy (owner, 2026-10-26: "yes,
 * fix the verify button").
 *
 * Daraja's Transaction Status query answers ResponseCode '0' when it ACCEPTS
 * the question; the real answer arrives later at
 * /api/webhooks/mpesa/transaction-result. The button used to mark the payment
 * paid on that acceptance — money recorded that may never have arrived. Now
 * it records the question, answers 202, and only Safaricom's answer (matched
 * by Daraja's conversation ids, proving the money) settles the payment.
 */
class MpesaVerifyOnResultTest extends TestCase
{
    use RefreshDatabase;

    private const SHORTCODE  = '600999';
    private const ORIGINATOR = 'OC-29115-34620561-1';
    private const CONVERSATION = 'AG_20261026_0000a1b2c3d4e5f6';
    private const RECEIPT = 'SJQ7ABC123';

    private User $staff;
    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach ([
            'mpesa_consumer_key'    => 'ck-test',
            'mpesa_consumer_secret' => 'cs-test',
            'mpesa_passkey'         => 'pk-test',
            'mpesa_shortcode'       => self::SHORTCODE,
            'mpesa_environment'     => 'sandbox',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => 'payments']);
        }
        MpesaService::invalidateCache();

        $this->staff = User::factory()->create(['status' => 'active']);
        $this->staff->assignRole(Role::findOrCreate('super_admin', 'sanctum'));

        $this->finance = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $this->finance->assignRole(Role::findOrCreate('finance_manager', 'sanctum'));
        $this->finance->givePermissionTo(Permission::findOrCreate('payments.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->finance = $this->finance->fresh();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * Daraja, faked. Each Transaction Status query is accepted with the next
     * pair of conversation ids ([originator, conversation]); the last pair
     * repeats. (Http::fake stubs accumulate and the first match wins, so a
     * test that needs new ids passes them here rather than faking again.)
     */
    private function fakeDaraja(
        array $stkQuery = ['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user'],
        array $conversations = [[self::ORIGINATOR, self::CONVERSATION]],
    ): void {
        $accepted = fn (array $ids) => [
            'OriginatorConversationID' => $ids[0],
            'ConversationID'           => $ids[1],
            'ResponseCode'             => '0',
            'ResponseDescription'      => 'Accept the service request successfully.',
        ];
        $queries = Http::sequence();
        foreach ($conversations as $ids) {
            $queries->push($accepted($ids));
        }
        $queries->whenEmpty(Http::response($accepted(end($conversations))));

        Http::fake([
            '*/oauth/v1/generate*'               => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/mpesa/transactionstatus/v1/query' => $queries,
            '*/mpesa/stkpushquery/v1/query'      => Http::response($stkQuery),
        ]);
    }

    private function order(string $status = 'pending', float $total = 1000): Order
    {
        return Order::factory()->create([
            'order_type'     => 'online',
            'status'         => $status,
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'subtotal'       => $total,
            'total_amount'   => $total,
        ]);
    }

    private function pendingMpesa(Order $order, float $amount = 1000, ?string $reference = null): Payment
    {
        return Payment::create([
            'order_id'           => $order->id,
            'payment_method'     => 'mpesa',
            'amount'             => $amount,
            'currency_code'      => 'KES',
            'status'             => 'pending',
            'provider_reference' => $reference,
            'recorded_by'        => null,
        ]);
    }

    private function verify(Order $order, Payment $payment, ?string $code = self::RECEIPT)
    {
        Sanctum::actingAs($this->staff);

        return $this->postJson(
            "/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/verify-mpesa",
            $code === null ? [] : ['transaction_code' => strtolower($code)],
        );
    }

    /** Safaricom calls the webhook with no signed-in user. */
    private function asSafaricom(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function darajaAnswer(array $overrides = [], array $params = []): array
    {
        $params += [
            'ReceiptNo'         => self::RECEIPT,
            'Amount'            => 1000,
            'TransactionStatus' => 'Completed',
            'CreditPartyName'   => self::SHORTCODE . ' - Bethany House',
            'DebitPartyName'    => '2547******89 - Customer',
            'FinalisedTime'     => 20261026140512,
        ];

        return ['Result' => $overrides + [
            'ResultType'               => 0,
            'ResultCode'               => 0,
            'ResultDesc'               => 'The service request is processed successfully.',
            'OriginatorConversationID' => self::ORIGINATOR,
            'ConversationID'           => self::CONVERSATION,
            'TransactionID'            => self::RECEIPT,
            'ResultParameters'         => ['ResultParameter' => collect($params)
                ->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])->values()->all()],
        ]];
    }

    private function postResult(array $body): void
    {
        $this->asSafaricom();
        $this->postJson('/api/webhooks/mpesa/transaction-result', $body)
            ->assertOk()->assertJson(['ResultCode' => 0]);
    }

    private function verification(Payment $payment): array
    {
        return $payment->fresh()->provider_response['mpesa_verification'] ?? [];
    }

    private function kindSentTo(User $user, string $kind): int
    {
        return Notification::sent($user, InAppNotification::class)
            ->filter(fn (InAppNotification $n) => ($n->data['kind'] ?? null) === $kind)
            ->count();
    }

    private function events(string $event): int
    {
        return DB::table('activity_log')->where('event', $event)->count();
    }

    // ── The question ──────────────────────────────────────────────────────────

    public function test_a_query_safaricom_accepts_leaves_the_payment_pending_and_says_it_asked(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);

        $this->verify($order, $payment)
            ->assertStatus(202)
            ->assertJson([
                'message' => 'Asked Safaricom to confirm receipt ' . self::RECEIPT
                    . ' — the payment will update here automatically when they answer (usually within a minute).',
                'pending' => true,
                'payment_status' => 'pending',
            ]);

        $payment->refresh();
        $this->assertSame('pending', $payment->status, 'accepting the question is not money');
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->provider_reference, 'the receipt is not recorded as the payment reference yet');
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('pending', $order->fresh()->status);

        $v = $this->verification($payment);
        $this->assertSame('pending', $v['state']);
        $this->assertSame(self::RECEIPT, $v['receipt']);
        $this->assertSame($this->staff->id, $v['requested_by']);
        $this->assertNotEmpty($v['requested_at']);
        $this->assertSame(self::CONVERSATION, $v['conversation_id']);
        $this->assertSame(self::ORIGINATOR, $v['originator_conversation_id']);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/mpesa/transactionstatus/v1/query')
            && $r['TransactionID'] === self::RECEIPT
            && str_ends_with($r['ResultURL'], '/api/webhooks/mpesa/transaction-result'));
        Notification::assertNotSentTo($this->finance, PaymentReceivedNotification::class);
        $this->assertSame(0, $this->events('mpesa_payment_verified'));
        $this->assertSame(1, $this->events('mpesa_verification_requested'));
    }

    public function test_a_receipt_already_recorded_on_another_payment_is_refused_before_asking(): void
    {
        $this->fakeDaraja();
        $other = $this->order('processing');
        $taken = Payment::factory()->create([
            'order_id' => $other->id, 'payment_method' => 'mpesa', 'status' => 'paid',
            'provider_reference' => self::RECEIPT, 'payment_number' => 'PAY-TAKEN1',
        ]);
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);

        $this->verify($order, $payment)
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Receipt ' . self::RECEIPT . " is already recorded on payment PAY-TAKEN1 (order {$other->order_number}).",
                'reason'  => 'receipt_already_recorded',
            ]);

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'transactionstatus'));
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame([], $this->verification($payment));
        $this->assertSame('paid', $taken->fresh()->status);
    }

    // ── The answer ────────────────────────────────────────────────────────────

    public function test_a_matching_completed_answer_settles_through_the_shared_path(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        // A quote-originated invoice: settling must issue the receipt document.
        SalesDocument::create(['type' => SalesDocument::INVOICE, 'number' => 'INV-VERIFY-1', 'status' => 'issued',
            'amount' => 1000, 'documentable_type' => Order::class, 'documentable_id' => $order->id,
            'currency_code' => 'KES', 'created_by' => $this->staff->id]);

        $this->verify($order, $payment)->assertStatus(202);
        $this->postResult($this->darajaAnswer());

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(self::RECEIPT, $payment->provider_reference);
        $this->assertNull($payment->received_after_cancel_at);
        $this->assertSame('paid', $this->verification($payment)['state']);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status, 'the order is synced');
        $this->assertSame('processing', $order->status, 'pending → processing, as before');

        $this->assertTrue(SalesDocument::where('type', SalesDocument::RECEIPT)->where('payment_id', $payment->id)->exists(),
            'the receipt document is issued, as every settle path does');

        $log = DB::table('activity_log')->where('event', 'mpesa_payment_verified')->first();
        $this->assertNotNull($log);
        $props = json_decode($log->properties, true);
        $this->assertSame('transaction_status_result', $props['via']);
        $this->assertSame(self::RECEIPT, $props['transaction_code']);

        Notification::assertSentTo($this->finance, PaymentReceivedNotification::class);
        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_confirmed'));
    }

    public function test_an_answer_for_less_money_is_not_paid_and_both_people_hear_why(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order, 1000);
        $this->verify($order, $payment)->assertStatus(202);

        $this->postResult($this->darajaAnswer([], ['Amount' => 900]));

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->provider_reference);
        $v = $this->verification($payment);
        $this->assertSame('refused', $v['state']);
        $this->assertSame('amount_short', $v['reason']);
        $this->assertSame('Receipt ' . self::RECEIPT . ' is for KES 900.00, but this payment expects KES 1,000.00. '
            . 'It was not recorded — record the amount actually received instead.', $v['message']);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_refused'));
        $this->assertSame(1, $this->kindSentTo($this->finance, 'mpesa_verification_refused'));
        Notification::assertSentTo($this->staff, InAppNotification::class,
            fn (InAppNotification $n) => $n->body === $v['message']);
        Notification::assertNotSentTo($this->finance, PaymentReceivedNotification::class);
        $this->assertSame(1, $this->events('mpesa_verification_refused'));
    }

    public function test_within_a_shilling_counts_as_exact(): void
    {
        $this->fakeDaraja();
        $order   = $this->order('pending', 1000.50);
        $payment = $this->pendingMpesa($order, 1000.50);
        $this->verify($order, $payment)->assertStatus(202);

        $this->postResult($this->darajaAnswer([], ['Amount' => 1000]));

        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_a_failed_or_reversed_answer_is_not_paid(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $failed  = $this->pendingMpesa($order);
        $this->verify($order, $failed)->assertStatus(202);
        $this->postResult($this->darajaAnswer(['ResultCode' => 2001, 'ResultDesc' => 'The initiator information is invalid.'], ['TransactionStatus' => null]));

        $this->assertSame('pending', $failed->fresh()->status);
        $v = $this->verification($failed);
        $this->assertSame('refused', $v['state']);
        $this->assertSame('failed', $v['reason']);
        $this->assertSame('Safaricom could not confirm receipt ' . self::RECEIPT
            . ' (The initiator information is invalid.). The payment was not recorded.', $v['message']);

        // Same payment asked again; this time Safaricom says the money went back.
        $this->verify($order, $failed)->assertStatus(202);
        $this->assertSame('pending', $this->verification($failed)['state']);
        $this->postResult($this->darajaAnswer([], ['TransactionStatus' => 'Reversed']));

        $this->assertSame('pending', $failed->fresh()->status);
        $v = $this->verification($failed);
        $this->assertSame('not_completed', $v['reason']);
        $this->assertSame('Safaricom says receipt ' . self::RECEIPT . ' is "Reversed", not completed. The payment was not recorded.', $v['message']);
        $this->assertSame(0, $this->events('mpesa_payment_verified'));
    }

    public function test_money_paid_to_another_till_is_not_ours(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        $this->postResult($this->darajaAnswer([], ['CreditPartyName' => '123456 - Someone Else Ltd']));

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('wrong_till', $this->verification($payment)['reason']);
    }

    public function test_a_receipt_settled_elsewhere_while_waiting_is_refused(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        $other = $this->order('processing');
        Payment::factory()->create([
            'order_id' => $other->id, 'payment_method' => 'mpesa', 'status' => 'paid',
            'provider_reference' => self::RECEIPT, 'payment_number' => 'PAY-TAKEN2',
        ]);

        $this->postResult($this->darajaAnswer());

        $this->assertSame('pending', $payment->fresh()->status);
        $v = $this->verification($payment);
        $this->assertSame('receipt_already_recorded', $v['reason']);
        $this->assertSame('Receipt ' . self::RECEIPT . " is already recorded on payment PAY-TAKEN2 (order {$other->order_number}).", $v['message']);
    }

    public function test_a_repeated_answer_changes_nothing(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        $this->postResult($this->darajaAnswer());
        $paidAt = $payment->fresh()->paid_at;
        $this->travel(5)->minutes();
        $this->postResult($this->darajaAnswer());

        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertSame(1, $this->events('mpesa_payment_verified'));
        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_confirmed'));
        Notification::assertSentToTimes($this->finance, PaymentReceivedNotification::class, 1);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_an_answer_nobody_asked_for_is_ignored_safely(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);

        $this->postResult($this->darajaAnswer([
            'OriginatorConversationID' => 'OC-nobody', 'ConversationID' => 'AG_nobody', 'TransactionID' => 'ZZZ0000000',
        ], ['ReceiptNo' => 'ZZZ0000000']));

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->provider_response);
        $this->assertSame(0, $this->events('mpesa_payment_verified'));
        Notification::assertNothingSent();
    }

    public function test_an_answer_tied_only_by_its_receipt_is_never_applied(): void
    {
        // The webhook is public: someone who knows the receipt (the customer
        // who typed it) can post a "Completed" answer. Without Daraja's
        // conversation ids for our question it settles nothing — and the real
        // answer still can.
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        $this->postResult($this->darajaAnswer(['OriginatorConversationID' => 'OC-forged', 'ConversationID' => 'AG_forged']));

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('unmatched', $this->verification($payment)['state']);
        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_unmatched'));

        // Posted again: reported once, not twice.
        $this->postResult($this->darajaAnswer(['OriginatorConversationID' => 'OC-forged', 'ConversationID' => 'AG_forged']));
        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_unmatched'));

        $this->postResult($this->darajaAnswer());
        $this->assertSame('paid', $payment->fresh()->status, "Safaricom's real answer still settles it");
    }

    public function test_an_answer_to_an_earlier_press_of_verify_still_counts(): void
    {
        $this->fakeDaraja(conversations: [[self::ORIGINATOR, self::CONVERSATION], ['OC-second', 'AG_second']]);
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        // Second press: Daraja hands out new ids; the first answer then arrives.
        $this->verify($order, $payment)->assertStatus(202);
        $this->assertSame('AG_second', $this->verification($payment)['conversation_id']);

        $this->postResult($this->darajaAnswer());

        $this->assertSame('paid', $payment->fresh()->status);
    }

    // ── Safaricom gives up ────────────────────────────────────────────────────

    public function test_a_timeout_tells_the_person_who_asked(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        $this->asSafaricom();
        $timeout = ['Result' => ['OriginatorConversationID' => self::ORIGINATOR, 'ConversationID' => self::CONVERSATION]];
        $this->postJson('/api/webhooks/mpesa/transaction-timeout', $timeout)->assertOk()->assertJson(['ResultCode' => 0]);

        $this->assertSame('pending', $payment->fresh()->status);
        $v = $this->verification($payment);
        $this->assertSame('timed_out', $v['state']);
        $this->assertSame("Safaricom didn't answer — try Verify again or check the M-Pesa statement.", $v['message']);
        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_timed_out'));
        Notification::assertSentTo($this->staff, InAppNotification::class,
            fn (InAppNotification $n) => str_contains((string) $n->body, "Safaricom didn't answer — try Verify again or check the M-Pesa statement."));

        $this->postJson('/api/webhooks/mpesa/transaction-timeout', $timeout)->assertOk();
        $this->assertSame(1, $this->kindSentTo($this->staff, 'mpesa_verification_timed_out'), 'told once');
    }

    // ── What must not change ──────────────────────────────────────────────────

    public function test_an_stk_query_confirmation_still_marks_paid_at_once(): void
    {
        $this->fakeDaraja(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.',
            'CheckoutRequestID' => 'ws_CO_26102026140000123456']);
        $order   = $this->order();
        $payment = $this->pendingMpesa($order, 1000, 'ws_CO_26102026140000123456');

        $this->verify($order, $payment, null)
            ->assertOk()
            ->assertJson(['message' => 'M-Pesa payment confirmed successfully.', 'payment_status' => 'paid']);

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('ws_CO_26102026140000123456', $payment->provider_reference);
        $this->assertSame('processing', $order->fresh()->status);
        $props = json_decode(DB::table('activity_log')->where('event', 'mpesa_payment_verified')->value('properties'), true);
        $this->assertSame('stk_query', $props['via']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'transactionstatus'));
    }

    public function test_money_confirmed_for_a_cancelled_order_is_flagged_and_the_order_stays_cancelled(): void
    {
        $this->fakeDaraja();
        $order   = $this->order();
        $payment = $this->pendingMpesa($order);
        $this->verify($order, $payment)->assertStatus(202);

        // Cancelled while Safaricom was answering.
        $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $this->postResult($this->darajaAnswer());

        $payment->refresh();
        $this->assertSame('paid', $payment->status, 'real money is recorded, never refused');
        $this->assertNotNull($payment->received_after_cancel_at, 'flagged: paid after cancel (#498)');
        $this->assertTrue($payment->needsRefundAfterCancel());
        $this->assertSame('cancelled', $order->fresh()->status, 'the order stays cancelled');
        Notification::assertSentTo($this->finance, InAppNotification::class,
            fn (InAppNotification $n) => str_contains($n->title, 'Paid after cancel — refund needed'));
    }
}
