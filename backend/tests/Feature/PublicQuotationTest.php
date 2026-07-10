<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public /quote/{token} link: a customer can view their issued quotation and
 * accept it (which converts it into an invoice and hands back the pay-link),
 * without logging in. The token is the authorization.
 */
class PublicQuotationTest extends TestCase
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
    }

    private function issuedQuote(bool $adHoc = false): Quotation
    {
        $q = Quotation::create([
            'outlet_id' => $this->outlet->id, 'source' => 'admin', 'status' => 'draft',
            'currency_code' => 'KES', 'customer_first_name' => 'Jane',
            'subtotal' => 2000, 'tax_amount' => 0, 'total_amount' => 2000,
        ]);
        QuotationItem::create([
            'quotation_id' => $q->id,
            'product_id' => $adHoc ? null : $this->product->id,
            'product_name' => 'Item', 'quantity' => 2, 'unit_price' => 1000,
            'discount_amount' => 0, 'tax_amount' => 0, 'total_price' => 2000,
        ]);
        QuotationService::issue($q->fresh('items'));
        return $q->fresh('items');
    }

    public function test_customer_can_view_a_quote_by_token(): void
    {
        $q = $this->issuedQuote();

        $res = $this->getJson("/api/v1/quote/{$q->quote_token}")->assertOk();
        $res->assertJsonPath('quotation.quote_number', $q->quote_number);
        $res->assertJsonCount(1, 'quotation.items');
        $res->assertJsonPath('quotation.totals.total_amount', 2000);
    }

    public function test_unknown_token_is_404(): void
    {
        $this->getJson('/api/v1/quote/nope-not-a-real-token')->assertStatus(404);
    }

    public function test_expired_token_is_404(): void
    {
        $q = $this->issuedQuote();
        $q->update(['quote_token_expires_at' => now()->subDay()]);

        $this->getJson("/api/v1/quote/{$q->quote_token}")->assertStatus(404);
    }

    public function test_customer_can_accept_a_quote_and_gets_a_pay_link(): void
    {
        $q = $this->issuedQuote();

        $res = $this->postJson("/api/v1/quote/{$q->quote_token}/accept")->assertOk();
        $payToken = $res->json('pay_token');
        $this->assertNotEmpty($payToken);

        // Converted to an order with that pay-link.
        $q->refresh();
        $this->assertSame('converted', $q->status);
        $this->assertNotNull($q->converted_order_id);
        $this->assertSame($payToken, Order::find($q->converted_order_id)->payment_token);
    }

    public function test_accepting_a_quote_with_an_adhoc_line_converts(): void
    {
        $q = $this->issuedQuote(adHoc: true);

        $res = $this->postJson("/api/v1/quote/{$q->quote_token}/accept")->assertOk();
        $this->assertNotEmpty($res->json('pay_token'));
        $this->assertSame('converted', $q->fresh()->status);
    }
}
