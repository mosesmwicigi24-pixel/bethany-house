<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Payment → receipt + commit for quote-originated invoices. A payment issues an
 * RCP receipt; full payment also commits the reserved stock and marks the invoice
 * paid; a partial payment issues a receipt but keeps the reservation held.
 */
class QuotationReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $product;
    private InventoryItem $inv;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->outlet  = Outlet::factory()->create();
        $this->product = Product::factory()->create();
        $this->inv = InventoryItem::create([
            'product_id' => $this->product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        DB::table('payment_methods')->insert([
            'code' => 'cash', 'name' => 'Cash', 'type' => 'cash',
            'requires_approval' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actor();
    }

    private function actor(): void
    {
        $user = User::factory()->create();
        foreach (['quotations.view', 'quotations.create', 'quotations.issue', 'orders.view', 'payments.record'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    /** Create → issue → accept a catalogue quote; returns the resulting order. */
    private function invoicedOrder(int $qty = 3): Order
    {
        $id = $this->postJson('/api/v1/admin/quotations', [
            'outlet_id' => $this->outlet->id,
            'items' => [[
                'product_id' => $this->product->id,
                'product_name' => 'Item', 'quantity' => $qty, 'unit_price' => 1000,
            ]],
        ])->json('quotation.id');
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();
        $orderId = $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertCreated()->json('order.id');
        return Order::findOrFail($orderId);
    }

    public function test_full_payment_issues_a_receipt_commits_stock_and_marks_invoice_paid(): void
    {
        $order = $this->invoicedOrder(3);
        $total = (float) $order->total_amount;

        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'cash', 'amount' => $total,
        ])->assertSuccessful();

        // A receipt was issued for the payment, linked to the invoice.
        $invoice = SalesDocument::where('type', 'invoice')->where('documentable_id', $order->id)->first();
        $receipt = SalesDocument::where('type', 'receipt')->where('documentable_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $this->assertStringStartsWith('RCP-', $receipt->number);
        $this->assertNotNull($receipt->payment_id);
        $this->assertSame($invoice->id, $receipt->parent_document_id);

        // Invoice closed, stock committed, order paid.
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->inv->refresh();
        $this->assertSame(7, (int) $this->inv->quantity_on_hand);
        $this->assertSame(0, (int) $this->inv->quantity_reserved);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_partial_payment_issues_a_receipt_but_holds_the_reservation(): void
    {
        $order = $this->invoicedOrder(3);
        $part  = round((float) $order->total_amount / 3, 2);

        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'cash', 'amount' => $part,
        ])->assertSuccessful();

        // Receipt issued…
        $receipt = SalesDocument::where('type', 'receipt')->where('documentable_id', $order->id)->first();
        $this->assertNotNull($receipt);

        // …but the invoice is not paid and stock is still only reserved.
        $invoice = SalesDocument::where('type', 'invoice')->where('documentable_id', $order->id)->first();
        $this->assertNotSame('paid', $invoice->fresh()->status);
        $this->inv->refresh();
        $this->assertSame(10, (int) $this->inv->quantity_on_hand);  // physical untouched
        $this->assertSame(3, (int) $this->inv->quantity_reserved);  // still reserved
    }
}
