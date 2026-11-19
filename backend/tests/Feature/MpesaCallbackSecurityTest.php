<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakesSafaricom;
use Tests\TestCase;

/**
 * Security audit 2026-11-19 (PAY-02, PAY-03). Both M-Pesa callback doors
 * marked a payment paid on whatever was posted to them: start a prompt,
 * cancel it on the phone, post "success" yourself. A callback is now only a
 * notice — it names the prompt, and Safaricom's own answer about that prompt
 * is what settles. Figures are hand-set: one order of KES 4,500, one prompt.
 */
class MpesaCallbackSecurityTest extends TestCase
{
    use RefreshDatabase;
    use FakesSafaricom;

    private const SHARED = '/api/webhooks/mpesa/callback';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** @return array{0: Order, 1: Payment} */
    private function prompt(string $checkoutId = 'ws_CO_19112026_0001'): array
    {
        $order = Order::factory()->create([
            'status' => 'pending', 'payment_status' => 'pending', 'currency_code' => 'KES',
            'subtotal' => 4500, 'total_amount' => 4500,
            'payment_token' => 'tok' . bin2hex(random_bytes(8)), 'payment_token_expires_at' => now()->addDay(),
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'mpesa', 'amount' => 4500, 'currency_code' => 'KES',
            'status' => 'pending', 'provider_reference' => $checkoutId, 'recorded_by' => null,
        ]);

        return [$order, $payment];
    }

    private function success(string $checkoutId, float $amount = 4500, string $receipt = 'SKJ7ABC123'): array
    {
        return ['Body' => ['stkCallback' => [
            'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'CheckoutRequestID' => $checkoutId,
            'CallbackMetadata' => ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'PhoneNumber', 'Value' => 254700000000],
            ]],
        ]]];
    }

    private function doors(Order $order): array
    {
        return [self::SHARED, "/api/v1/pay/{$order->payment_token}/mpesa-callback"];
    }

    public function test_a_forged_success_settles_nothing_when_safaricom_says_cancelled(): void
    {
        foreach ([0, 1] as $door) {
            [$order, $payment] = $this->prompt("ws_CO_FORGED_{$door}");
            $this->safaricomAnswers('1032');   // the customer cancelled on the phone

            $this->postJson($this->doors($order)[$door], $this->success("ws_CO_FORGED_{$door}"));

            $this->assertSame('failed', $payment->fresh()->status, "door {$door}");
            $this->assertNull($payment->fresh()->paid_at);
            $this->assertSame('pending', $order->fresh()->payment_status, "door {$door}");
            $this->assertSame('pending', $order->fresh()->status);
        }
        $this->assertSame(0, Payment::where('status', 'paid')->count());
    }

    public function test_with_mpesa_not_set_up_both_doors_refuse_and_ask_nobody(): void
    {
        [$order, $payment] = $this->prompt('ws_CO_NOSETUP');
        $this->mpesaNotSetUp();
        Http::fake();

        foreach ($this->doors($order) as $door) {
            $this->postJson($door, $this->success('ws_CO_NOSETUP'))->assertStatus(400);
        }

        Http::assertNothingSent();
        $this->assertFalse(MpesaService::isConfigured());
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_when_safaricom_gives_no_answer_the_payment_stays_pending_not_failed(): void
    {
        foreach ([0, 1] as $door) {
            [$order, $payment] = $this->prompt("ws_CO_OUTAGE_{$door}");
            $this->safaricomAnswers(null);

            $this->postJson($this->doors($order)[$door], $this->success("ws_CO_OUTAGE_{$door}"))->assertOk();

            $this->assertSame('pending', $payment->fresh()->status, "door {$door}");
            $this->assertSame('pending', $order->fresh()->payment_status);
        }
    }

    public function test_a_confirmed_prompt_settles_once_on_either_door(): void
    {
        foreach ([0, 1] as $door) {
            [$order, $payment] = $this->prompt("ws_CO_REAL_{$door}");
            $this->safaricomAnswers('0');
            $receipt = "SKJ7REAL0{$door}";

            $this->postJson($this->doors($order)[$door], $this->success("ws_CO_REAL_{$door}", 4500, $receipt))
                ->assertOk()->assertJson(['ResultCode' => 0]);

            $paid = $payment->fresh();
            $this->assertSame('paid', $paid->status, "door {$door}");
            $this->assertSame($receipt, $paid->provider_reference);
            $this->assertSame('paid', $order->fresh()->payment_status);
            $paidAt = $paid->paid_at;

            // Daraja re-delivers, under the prompt id and under the receipt.
            $this->postJson($this->doors($order)[$door], $this->success("ws_CO_REAL_{$door}", 4500, $receipt))->assertOk();
            $this->postJson($this->doors($order)[$door], $this->success($receipt, 4500, $receipt))->assertOk();

            $this->assertEquals($paidAt, $payment->fresh()->paid_at);
            $this->assertSame(1, Payment::where('order_id', $order->id)->count());
            // collected is exactly the one prompt
            $this->assertEquals(4500.0, (float) DB::table('payments')->where('order_id', $order->id)->where('status', 'paid')->sum('amount'));
        }
    }

    public function test_a_failure_notice_cannot_kill_a_prompt_safaricom_says_was_paid(): void
    {
        [$order, $payment] = $this->prompt('ws_CO_GRIEF');
        $this->safaricomAnswers('0');

        $notice = $this->success('ws_CO_GRIEF');
        $notice['Body']['stkCallback']['ResultCode'] = 1032;
        unset($notice['Body']['stkCallback']['CallbackMetadata']);

        $this->postJson(self::SHARED, $notice)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status, 'Safaricom is believed, not the notice');
    }

    public function test_a_code_the_customer_typed_in_is_never_settled_by_a_callback(): void
    {
        [$order, $payment] = $this->prompt('TYPEDCODE1');
        $payment->update(['requires_approval' => true, 'approval_status' => 'pending_review']);
        $this->safaricomAnswers('0');

        foreach ($this->doors($order) as $door) {
            $this->postJson($door, $this->success('TYPEDCODE1'))->assertOk();
        }

        $held = $payment->fresh();
        $this->assertSame('pending', $held->status);
        $this->assertSame('pending_review', $held->approval_status);
    }

    public function test_on_the_payment_link_a_short_amount_is_still_held_for_review(): void
    {
        [$order, $payment] = $this->prompt('ws_CO_SHORT');
        $this->safaricomAnswers('0');

        $this->postJson($this->doors($order)[1], $this->success('ws_CO_SHORT', 45))->assertOk();

        $held = $payment->fresh();
        $this->assertSame('pending', $held->status);
        $this->assertTrue((bool) $held->requires_approval);
        $this->assertSame('pending_approval', $order->fresh()->payment_status);
    }

    public function test_another_methods_payment_is_not_this_doors_to_touch(): void
    {
        [$order, $payment] = $this->prompt('PSK_REF_1');
        $payment->update(['payment_method' => 'card_paystack']);
        $this->safaricomAnswers('0');

        $this->postJson(self::SHARED, $this->success('PSK_REF_1'))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_callback_log_carries_no_phone_number(): void
    {
        [$order] = $this->prompt('ws_CO_LOG');
        $this->safaricomAnswers('0');
        \Illuminate\Support\Facades\Log::spy();

        foreach ($this->doors($order) as $door) {
            $this->postJson($door, $this->success('ws_CO_LOG'));
        }

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('info', fn ($m, $ctx = []) => str_contains(json_encode($ctx), '254700000000'));
        $this->assertTrue(true);
    }

    public function test_credentials_saved_in_setup_are_what_every_door_reads(): void
    {
        $this->mpesaNotSetUp();
        $this->assertNull(MpesaService::credentials());

        foreach ([
            'mpesa_consumer_key' => 'ck', 'mpesa_consumer_secret' => 'cs',
            'mpesa_passkey' => 'pk', 'mpesa_shortcode' => '600000', 'mpesa_environment' => 'production',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
        MpesaService::invalidateCache();

        $this->assertSame('600000', MpesaService::credentials()['shortcode']);
        $this->assertSame('production', MpesaService::credentials()['environment']);
        $this->assertTrue(MpesaService::isConfigured());
    }
}
