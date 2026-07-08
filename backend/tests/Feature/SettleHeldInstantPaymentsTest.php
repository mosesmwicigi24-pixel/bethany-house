<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\SettleHeldInstantPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-time cleanup settles POS payments that were wrongly held for approval
 * on an instant (no-approval) method — I&M Paybill in particular — and re-marks
 * their orders paid/confirmed, without disturbing genuine approval methods.
 */
class SettleHeldInstantPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private function heldPayment(int $orderId, string $method, float $amount): int
    {
        return DB::table('payments')->insertGetId([
            'order_id'          => $orderId,
            'payment_number'    => 'PMT-' . $orderId . '-' . $method,
            'payment_method'    => $method,
            'amount'            => $amount,
            'currency_code'     => 'KES',
            'status'            => 'pending',
            'requires_approval' => true,
            'approval_status'   => 'pending_review',
            'paid_at'           => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_it_settles_a_held_im_order_to_paid_and_confirmed(): void
    {
        // I&M is configured no-approval.
        DB::table('payment_methods')->insert([
            'code' => 'inmpaybill', 'name' => 'I&M Paybill', 'type' => 'mobile_money',
            'is_active' => true, 'requires_approval' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'processing',
            'payment_status' => 'pending_approval',
            'total_amount'   => 350,
            'currency_code'  => 'KES',
        ]);
        $paymentId = $this->heldPayment($order->id, 'inmpaybill', 350);

        $result = SettleHeldInstantPayments::run();

        $this->assertSame(1, $result['payments']);
        $this->assertSame(1, $result['orders']);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId, 'status' => 'paid', 'requires_approval' => false, 'approval_status' => null,
        ]);
        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame('confirmed', $fresh->status);
    }

    public function test_it_leaves_genuine_approval_methods_untouched(): void
    {
        // Cheque genuinely requires approval.
        DB::table('payment_methods')->insert([
            'code' => 'cheque', 'name' => 'Cheque', 'type' => 'bank_transfer',
            'is_active' => true, 'requires_approval' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'processing',
            'payment_status' => 'pending_approval',
            'total_amount'   => 5000,
            'currency_code'  => 'KES',
        ]);
        $paymentId = $this->heldPayment($order->id, 'cheque', 5000);

        $result = SettleHeldInstantPayments::run();

        $this->assertSame(0, $result['payments']);
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId, 'status' => 'pending', 'requires_approval' => true,
        ]);
        $this->assertSame('pending_approval', $order->fresh()->payment_status);
    }

    public function test_it_is_idempotent(): void
    {
        DB::table('payment_methods')->insert([
            'code' => 'inmpaybill', 'name' => 'I&M Paybill', 'type' => 'mobile_money',
            'is_active' => true, 'requires_approval' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'processing', 'payment_status' => 'pending_approval',
            'total_amount' => 350, 'currency_code' => 'KES',
        ]);
        $this->heldPayment($order->id, 'inmpaybill', 350);

        SettleHeldInstantPayments::run();
        $second = SettleHeldInstantPayments::run();   // nothing left to do

        $this->assertSame(0, $second['payments']);
        $this->assertSame(0, $second['orders']);
    }
}
