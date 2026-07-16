<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: a double/triple-tapped Pay button wrote one payment row per submit.
 *
 * Real incident, order #53: three identical KES 8,000 `inmpaybill` rows landed at
 * 08:35:49, :50 and :51 — one second apart, which no human keys by hand. Because
 * there is no way to remove individual payment lines, a staff member voided the
 * entire legitimate KES 15,000 sale just to clean them up.
 *
 * recordPosPay now treats an identical payment (same order, method and amount)
 * inside PAYMENT_REPLAY_WINDOW_SECONDS as a replay of the same submit and writes
 * nothing.
 *
 * NOTE on why this is HTTP-testable at all: a successful recordPosPay is not,
 * because its post-commit admin notification poisons the wrapping transaction
 * under RefreshDatabase. The replay path returns BEFORE any of that, so it can be
 * driven through the real endpoint — which is the part worth proving.
 */
class PosPaymentReplayGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param float $total recordPosPay demands a payment cover the outstanding
     *   balance exactly, so each test sizes the order to make its amount valid.
     */
    private function pendingPosOrder(Outlet $outlet, float $total = 15000): Order
    {
        return Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'payment_status' => 'pending',
            'status'         => 'pending',
            'total_amount'   => $total,
            'currency_code'  => 'KES',
        ]);
    }

    /** The exact shape of the #53 incident: the same submit arriving again. */
    public function test_identical_payment_moments_later_is_ignored_as_a_replay(): void
    {
        $this->actingWithPermissions(['pos.access']);
        $outlet = Outlet::factory()->create();
        $order  = $this->pendingPosOrder($outlet);

        Payment::factory()->create([
            'order_id'       => $order->id,
            'payment_method' => 'inmpaybill',
            'amount'         => 8000,
            'status'         => 'paid',
            'currency_code'  => 'KES',
            'created_at'     => now(),
        ]);

        $response = $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'payments' => [
                ['method' => 'inmpaybill', 'amount' => 8000],
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['replay' => true]);

        // The whole point: still ONE payment row, not two.
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    /** A genuinely different amount is a real second payment, not a replay. */
    public function test_a_different_amount_is_not_treated_as_a_replay(): void
    {
        $this->actingWithPermissions(['pos.access']);
        $outlet = Outlet::factory()->create();
        $order  = $this->pendingPosOrder($outlet, 15500); // 15,500 - 8,000 = 7,500 outstanding

        Payment::factory()->create([
            'order_id'       => $order->id,
            'payment_method' => 'inmpaybill',
            'amount'         => 8000,
            'status'         => 'paid',
            'currency_code'  => 'KES',
            'created_at'     => now(),
        ]);

        // 7,500 is the follow-up the #53 note actually described ("later 7,500ksh
        // after adding a collar") — it must still go through.
        $response = $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'payments' => [
                ['method' => 'inmpaybill', 'amount' => 7500],
            ],
        ]);

        $response->assertOk();
        $this->assertNotTrue($response->json('replay'));
    }

    /** An identical amount long afterwards is a real payment — the guard is a
     *  short window, not a permanent block on repeating an amount. */
    public function test_identical_payment_outside_the_window_is_not_a_replay(): void
    {
        $this->actingWithPermissions(['pos.access']);
        $outlet = Outlet::factory()->create();
        $order  = $this->pendingPosOrder($outlet, 16000); // 16,000 - 8,000 = 8,000 outstanding

        Payment::factory()->create([
            'order_id'       => $order->id,
            'payment_method' => 'inmpaybill',
            'amount'         => 8000,
            'status'         => 'paid',
            'currency_code'  => 'KES',
            'created_at'     => now()->subMinutes(10),
        ]);

        $response = $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'payments' => [
                ['method' => 'inmpaybill', 'amount' => 8000],
            ],
        ]);

        $response->assertOk();
        $this->assertNotTrue($response->json('replay'));
    }
}
