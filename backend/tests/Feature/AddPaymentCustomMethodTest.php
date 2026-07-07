<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The order-detail "Record Payment" (OrderController::addPayment) validated the
 * method against a hardcoded whitelist, so recording a partial payment with a
 * configured method like I&M Paybill returned 422 "The selected method is
 * invalid". It now accepts any active payment_methods row.
 *
 * The assertion targets the validation layer specifically (which runs before any
 * processing), so it is unaffected by the downstream notification path.
 */
class AddPaymentCustomMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_configured_payment_method_is_not_rejected_by_validation(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        DB::table('payment_methods')->insert([
            'code'       => 'inmpaybill',
            'name'       => 'I&M Paybill',
            'type'       => 'mobile_money',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'processing',
            'total_amount'   => 7100,
            'payment_status' => 'partial',
        ]);

        $response = $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'inmpaybill',
            'amount' => 100,
        ]);

        // The custom method must no longer trip the whitelist. (Before the fix
        // this returned a 422 with errors.method = "The selected method is invalid".)
        $response->assertJsonMissingValidationErrors('method');
    }
}
