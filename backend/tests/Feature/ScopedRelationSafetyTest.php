<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A scoped caller must never make the application fall over.
 *
 * A global scope on Order narrows relations as well as queries: for a cashier
 * scoped to their own sales, `$payment->order` on a colleague's payment
 * resolves to NULL. Every unguarded `->order->` dereference then throws
 * \Error — a 500, not a refusal — and in uploadProof's case it threw AFTER the
 * proof file had already been written and the payment updated, so the caller
 * saw a failure for work that had actually happened.
 *
 * These are the reachable ones found by reading the 147 Order:: sites once
 * pos_clerk was genuinely scoped. The pattern is worth remembering: scoping
 * turns "sees less" into "gets null", and null goes somewhere.
 */
class ScopedRelationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function scopedClerk(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $user->outlets()->attach(Outlet::factory()->create()->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function someoneElsesOrder(): Order
    {
        return Order::factory()->create([
            'order_type' => 'pos',
            'status'     => 'completed',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_uploading_proof_against_another_cashiers_payment_does_not_500(): void
    {
        Storage::fake('local');

        $order   = $this->someoneElsesOrder();
        // requires_approval is what makes a payment eligible for a proof
        // upload — the endpoint refuses otherwise.
        $payment = Payment::factory()->create([
            'order_id'          => $order->id,
            'status'            => 'paid',
            'requires_approval' => true,
            'approval_status'   => 'pending',
        ]);

        $this->scopedClerk();

        // The order is invisible to this cashier…
        $this->assertNull(Order::find($order->id));

        // …and uploading proof must still complete rather than throw on a null
        // relation after the file has been written.
        // create() with an explicit mimetype — a bare fake image fails the
        // endpoint's mimetypes rule.
        $this->postJson("/api/v1/admin/payments/{$payment->id}/upload-proof", [
            'proof' => UploadedFile::fake()->create('proof.jpg', 64, 'image/jpeg'),
        ])->assertSuccessful();

        $this->assertNotNull($payment->fresh()->proof_of_payment_path);
    }

    public function test_can_be_returned_answers_false_rather_than_throwing(): void
    {
        $order = $this->someoneElsesOrder();
        $item  = OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => Product::factory()->create()->id,
            'product_name' => 'Cassock',
            'sku'          => 'X',
            'quantity'     => 1,
            'unit_price'   => 100,
            'total_price'  => 100,
        ]);

        $this->scopedClerk();

        $this->assertFalse(
            $item->fresh()->canBeReturned(),
            'an item whose order is out of scope cannot be returned by this caller — and must not fatal',
        );
    }
}
