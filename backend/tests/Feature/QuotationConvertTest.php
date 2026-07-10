<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Accepting an issued quotation converts it into an INVOICE: an Order + a linked
 * INV document + reserved stock + a pay-link.
 */
class QuotationConvertTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $product;
    private InventoryItem $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet  = Outlet::factory()->create();
        $this->product = Product::factory()->create();
        $this->inv = InventoryItem::create([
            'product_id' => $this->product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        $this->actor();
    }

    private function actor(): void
    {
        $user = User::factory()->create();
        foreach (['quotations.view', 'quotations.create', 'quotations.issue'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function issuedCatalogueQuote(int $qty = 3): int
    {
        $id = $this->postJson('/api/v1/admin/quotations', [
            'outlet_id'           => $this->outlet->id,
            'customer_first_name' => 'Jane',
            'items' => [[
                'product_id'   => $this->product->id,
                'product_name' => $this->product->name ?? 'Item',
                'quantity'     => $qty,
                'unit_price'   => 1000,
            ]],
        ])->assertCreated()->json('quotation.id');

        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();
        return $id;
    }

    public function test_accept_creates_an_order_and_linked_invoice_and_reserves_stock(): void
    {
        $y  = now()->format('Y');
        $id = $this->issuedCatalogueQuote(3);
        $quoteDoc = SalesDocument::where('type', 'quotation')->where('documentable_id', $id)->first();

        $res = $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertCreated();

        $orderId = $res->json('order.id');
        $invNum  = $res->json('invoice.number');
        $this->assertSame("INV-{$y}-0001", $invNum);
        $this->assertNotEmpty($res->json('pay_token'));

        // Quotation is converted and points at the order.
        $this->assertDatabaseHas('quotations', [
            'id' => $id, 'status' => 'converted', 'converted_order_id' => $orderId,
        ]);

        // The order carries the lines and a pay-link.
        $order = Order::with('items')->find($orderId);
        $this->assertSame(1, $order->items->count());
        $this->assertNotNull($order->payment_token);
        $this->assertSame('pending', $order->payment_status);

        // Invoice document links back to the quotation document.
        $invDoc = SalesDocument::where('number', $invNum)->first();
        $this->assertSame('invoice', $invDoc->type);
        $this->assertSame((int) $orderId, (int) $invDoc->documentable_id);
        $this->assertSame($quoteDoc->id, $invDoc->parent_document_id);
        $this->assertNotNull($invDoc->due_date);
        $this->assertCount(1, $invDoc->snapshot['items']);

        // Stock is RESERVED (physical count untouched until payment).
        $this->inv->refresh();
        $this->assertSame(10, (int) $this->inv->quantity_on_hand);
        $this->assertSame(3, (int) $this->inv->quantity_reserved);
    }

    public function test_a_draft_quotation_cannot_be_accepted(): void
    {
        $id = $this->postJson('/api/v1/admin/quotations', [
            'items' => [['product_id' => $this->product->id, 'product_name' => 'x', 'quantity' => 1, 'unit_price' => 10]],
        ])->json('quotation.id');

        $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertStatus(422);
    }

    public function test_a_quotation_with_an_adhoc_line_converts_without_reserving_stock(): void
    {
        $id = $this->postJson('/api/v1/admin/quotations', [
            'items' => [['product_name' => 'Custom tailoring (no product)', 'quantity' => 1, 'unit_price' => 5000]],
        ])->json('quotation.id');
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();

        $res = $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertCreated();
        $orderId = $res->json('order.id');

        // The ad-hoc line became an order line with no product and no stock reserved.
        $order = Order::with('items')->find($orderId);
        $this->assertSame(1, $order->items->count());
        $this->assertNull($order->items->first()->product_id);
        $this->inv->refresh();
        $this->assertSame(0, (int) $this->inv->quantity_reserved);
    }

    public function test_accepting_twice_is_rejected(): void
    {
        $id = $this->issuedCatalogueQuote(2);
        $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertCreated();
        $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertStatus(422);
    }
}
