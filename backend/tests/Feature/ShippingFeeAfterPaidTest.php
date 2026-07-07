<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A paid receipt is "closed" and its goods + collected money are immutable. But
 * for made-to-order work the shipping cost is only known after production, so it
 * must be attachable afterwards. setShippingFee therefore allows adjusting the
 * shipping charge on a paid order: it re-opens the balance (paid → partial) so it
 * can be collected later, WITHOUT changing line prices or any recorded payment.
 */
class ShippingFeeAfterPaidTest extends TestCase
{
    use RefreshDatabase;

    private function actAsShipper(): void
    {
        $user = User::factory()->create();
        // Orders group requires orders.view; the endpoint requires
        // orders.set_shipping_fee. Grant both directly (no admin role).
        $user->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('orders.set_shipping_fee', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_adding_shipping_to_a_paid_receipt_reopens_a_balance_without_touching_payments(): void
    {
        $this->actAsShipper();

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'confirmed',
            'payment_status' => 'paid',
            'subtotal'       => 1000,
            'tax_amount'     => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount'   => 1000,
            'currency_code'  => 'KES',
        ]);
        Payment::factory()->create([
            'order_id'       => $order->id,
            'amount'         => 1000,
            'status'         => 'paid',
            'payment_method' => 'cash',
        ]);

        $res = $this->patchJson("/api/v1/admin/orders/{$order->id}/shipping-fee", [
            'amount' => 200,
            'note'   => 'DHL after production',
        ]);

        $res->assertOk();
        $res->assertJsonPath('payment_status', 'partial');   // re-opened

        $fresh = $order->fresh();
        $this->assertEquals(200.0, (float) $fresh->shipping_amount);
        $this->assertEquals(1200.0, (float) $fresh->total_amount);   // +shipping only
        $this->assertEquals(1000.0, (float) $fresh->subtotal);       // product price untouched
        $this->assertSame('partial', $fresh->payment_status);
        $this->assertSame('confirmed', $fresh->status);              // fulfilment untouched

        // The money already collected is exactly as it was — nothing added/removed.
        $this->assertEquals(1000.0, (float) $fresh->totalPaid());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'amount' => 1000, 'status' => 'paid',
        ]);

        // The 200 balance now shows up in receivables.
        $this->assertEquals(200.0, (float) $fresh->total_amount - (float) $fresh->totalPaid());
    }

    public function test_a_voided_order_stays_locked(): void
    {
        $this->actAsShipper();

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'voided',
            'payment_status' => 'paid',
            'total_amount'   => 1000,
            'shipping_amount' => 0,
            'currency_code'  => 'KES',
        ]);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/shipping-fee", ['amount' => 200])
            ->assertStatus(422);

        $this->assertEquals(0.0, (float) $order->fresh()->shipping_amount);
    }
}
