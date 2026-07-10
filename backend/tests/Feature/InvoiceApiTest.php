<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet  = Outlet::factory()->create();
        $this->product = Product::factory()->create();
        InventoryItem::create([
            'product_id' => $this->product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        $user = User::factory()->create();
        foreach (['quotations.view', 'quotations.create', 'quotations.issue', 'orders.view'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function acceptedInvoice(): array
    {
        $id = $this->postJson('/api/v1/admin/quotations', [
            'outlet_id' => $this->outlet->id, 'customer_first_name' => 'Pastor', 'customer_last_name' => 'Oyi',
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Item', 'quantity' => 2, 'unit_price' => 1000]],
        ])->json('quotation.id');
        $quoteNumber = $this->postJson("/api/v1/admin/quotations/{$id}/issue")->json('document.number');
        $accept = $this->postJson("/api/v1/admin/quotations/{$id}/accept")->assertCreated();
        return ['quotation_id' => $id, 'quote_number' => $quoteNumber, 'invoice_number' => $accept->json('invoice.number')];
    }

    public function test_invoices_list_links_to_order_and_quotation(): void
    {
        $ctx = $this->acceptedInvoice();

        $res = $this->getJson('/api/v1/admin/invoices')->assertOk();
        $row = collect($res->json('data'))->firstWhere('invoice_number', $ctx['invoice_number']);

        $this->assertNotNull($row);
        $this->assertSame('Pastor Oyi', $row['customer_name']);
        $this->assertNotNull($row['order']);
        $this->assertSame('pending', $row['order']['payment_status']);
        $this->assertNotEmpty($row['order']['pay_token']);
        $this->assertSame($ctx['quote_number'], $row['quotation']['number']);
        $this->assertSame($ctx['quotation_id'], $row['quotation']['quotation_id']);
    }

    public function test_a_converted_quotation_exposes_its_invoice(): void
    {
        $ctx = $this->acceptedInvoice();

        $res = $this->getJson('/api/v1/admin/quotations')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $ctx['quotation_id']);

        $this->assertSame('converted', $row['status']);
        $this->assertNotNull($row['invoice_document']);
        $this->assertSame($ctx['invoice_number'], $row['invoice_document']['number']);
    }
}
