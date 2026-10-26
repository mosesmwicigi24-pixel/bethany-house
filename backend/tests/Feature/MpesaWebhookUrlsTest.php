<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-10-26: the console's M-Pesa STK fallback callback and the "verify"
 * button's Transaction Status result/timeout URLs pointed at
 * /api/v1/webhooks/mpesa/… — no such routes — so Safaricom's answers 404'd.
 */
class MpesaWebhookUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mpesa_url_the_code_hands_safaricom_is_a_real_route(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Api/PaymentController.php'));
        preg_match_all("~url\\('(/api/[^']*mpesa[^']*)'\\)~", $src, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $path) {
            $this->assertStringStartsNotWith('/api/v1/webhooks', $path, $path);
            // registered (throws NotFoundHttpException when not)
            $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($path, 'POST'));
            $this->assertNotNull($route, $path);
        }
    }

    public function test_a_transaction_status_result_is_recorded_on_the_payment(): void
    {
        $payment = Payment::factory()->create(['provider_reference' => 'SJK7ABC123']);

        $this->postJson('/api/webhooks/mpesa/transaction-result', ['Result' => [
            'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
            'TransactionID' => 'SJK7ABC123', 'OriginatorConversationID' => 'conv-1',
            'ResultParameters' => ['ResultParameter' => [
                ['Key' => 'Amount', 'Value' => 4500], ['Key' => 'TransactionStatus', 'Value' => 'Completed'],
            ]],
        ]])->assertOk()->assertJson(['ResultCode' => 0]);

        $stored = $payment->fresh()->provider_response['mpesa_transaction_status'] ?? null;
        $this->assertSame('SJK7ABC123', $stored['receipt'] ?? null);
        $this->assertSame('Completed', $stored['status'] ?? null);
        // it records only — it never marks a payment paid by itself
        $this->assertSame($payment->status, $payment->fresh()->status);
    }

    public function test_the_timeout_endpoint_answers(): void
    {
        $this->postJson('/api/webhooks/mpesa/transaction-timeout', ['Result' => ['OriginatorConversationID' => 'c']])
            ->assertOk()->assertJson(['ResultCode' => 0]);
    }
}
