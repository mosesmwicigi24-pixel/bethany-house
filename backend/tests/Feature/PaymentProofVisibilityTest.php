<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 1C, item 8 — a payment proof is as private as its order.
 *
 * GET /admin/payments/{id}/proof sits on payments.view, which every cashier
 * and both procurement roles hold, and streamed any proof by id: a customer's
 * bank slip or M-Pesa screenshot, names and account numbers included. The
 * pending-approval inbox was already narrowed with constrainToViewer — but
 * DataScopeResolver answers "all" when NO role grants orders.view, so the
 * procurement roles (payments.view without orders.view) saw every pending
 * payment in the group.
 */
class PaymentProofVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        Storage::fake('local');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function pendingProofOn(Order $order): Payment
    {
        $path = 'payment-proofs/proof-' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, 'BANK SLIP for ' . $order->order_number);

        return Payment::factory()->create([
            'order_id' => $order->id, 'payment_method' => 'bank_transfer', 'status' => 'pending',
            'requires_approval' => true, 'approval_status' => 'pending_review',
            'proof_of_payment_path' => $path, 'proof_uploaded_at' => now(),
        ]);
    }

    public function test_a_cashier_opens_proofs_on_her_own_sales_only(): void
    {
        $clerk  = $this->user('pos_clerk');
        $mine   = $this->pendingProofOn(Order::factory()->create(['created_by' => $clerk->id]));
        $theirs = $this->pendingProofOn(Order::factory()->create(['created_by' => User::factory()->create()->id]));
        Sanctum::actingAs($clerk);

        $this->get("/api/v1/admin/payments/{$mine->id}/proof")->assertOk();
        $this->get("/api/v1/admin/payments/{$theirs->id}/proof")->assertNotFound();
    }

    public function test_procurement_holds_payments_view_but_sees_no_customer_proofs(): void
    {
        $payment = $this->pendingProofOn(Order::factory()->create());
        Sanctum::actingAs($this->user('procurement_officer'));

        $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertNotFound();

        $list = $this->getJson('/api/v1/admin/payments/pending-approval')->assertOk();
        $this->assertSame([], $list->json('data'));
        $this->assertSame(0, $list->json('pending_count'));
    }

    public function test_finance_still_reviews_every_proof(): void
    {
        $payment = $this->pendingProofOn(Order::factory()->create());
        Sanctum::actingAs($this->user('finance_manager'));

        $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertOk();
        $this->assertSame(
            [$payment->id],
            collect($this->getJson('/api/v1/admin/payments/pending-approval')->assertOk()->json('data'))->pluck('id')->all(),
        );
    }
}
