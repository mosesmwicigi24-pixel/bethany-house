<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * OrderController::addPayment must classify a payment's approval requirement from
 * the method's per-method policy — not a hardcoded list. This is the fix for the
 * live contradiction where I&M Paybill (settles instantly) was shown as "awaiting
 * approval" while the backend recorded it paid.
 *
 * The acting user is granted ONLY the `payments.record` permission (not an admin
 * role), so NotificationService::usersWithRole('admin','super_admin','finance')
 * resolves to nobody and send() short-circuits before any notification write —
 * keeping the RefreshDatabase transaction clean so we can assert the saved row.
 */
class PaymentApprovalClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function actAsRecorder(): void
    {
        $perm = Permission::findOrCreate('payments.record', 'sanctum');
        $role = Role::findOrCreate('payment_recorder', 'sanctum');
        $role->givePermissionTo($perm);

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function makeOrder(): Order
    {
        return Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'processing',
            'total_amount'   => 7100,
            'payment_status' => 'partial',
            'currency_code'  => 'KES',
        ]);
    }

    public function test_instant_method_settles_immediately_without_approval(): void
    {
        Notification::fake();
        $this->actAsRecorder();

        // I&M Paybill: settles instantly, so the policy flag is false.
        DB::table('payment_methods')->insert([
            'code' => 'inmpaybill', 'name' => 'I&M Paybill', 'type' => 'cash',
            'is_active' => true, 'requires_approval' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder();

        $res = $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'inmpaybill',
            'amount' => 100,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('requires_approval', false);

        $this->assertDatabaseHas('payments', [
            'order_id'          => $order->id,
            'payment_method'    => 'inmpaybill',
            'status'            => 'paid',
            'requires_approval' => false,
            'approval_status'   => null,
        ]);
    }

    public function test_method_flagged_for_approval_is_held_pending(): void
    {
        Notification::fake();
        $this->actAsRecorder();

        // A cheque method configured to require approval.
        DB::table('payment_methods')->insert([
            'code' => 'cheque', 'name' => 'Cheque', 'type' => 'bank_transfer',
            'is_active' => true, 'requires_approval' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder();

        $res = $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method'    => 'cheque',
            'amount'    => 100,
            'reference' => 'CHQ-001',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('requires_approval', true);

        $this->assertDatabaseHas('payments', [
            'order_id'          => $order->id,
            'payment_method'    => 'cheque',
            'status'            => 'pending',
            'requires_approval' => true,
            'approval_status'   => 'pending_review',
        ]);
    }
}
