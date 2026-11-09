<?php

namespace Tests\Feature;

use App\Models\DownloadRequest;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * One receipt for a whole order (owner, 2026-10-15): "When you share, export
 * it as PDF." Items, every SETTLED payment (paid, net of refunds — the money
 * truth of Order::totalPaid), and the balance due or PAID IN FULL. The PDF
 * must say what the order screen says.
 */
class OrderReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        Storage::fake('local');
        $this->shop = Outlet::factory()->create(['name' => 'Sonalux Store Nairobi']);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        $u->outlets()->attach($this->shop->id, ['is_primary' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u->fresh());

        return $u->fresh();
    }

    private function order(float $total, ?User $by = null): Order
    {
        return Order::factory()->create([
            'order_type' => 'pos', 'outlet_id' => $this->shop->id, 'created_by' => $by?->id,
            'customer_first_name' => 'Bishop', 'customer_last_name' => 'Gitahi',
            'status' => 'processing', 'payment_status' => 'partial', 'currency_code' => 'KES',
            'subtotal' => $total, 'total_amount' => $total, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
        ]);
    }

    private function pay(Order $o, float $amount, string $status = 'paid', float $refunded = 0, string $method = 'cash'): void
    {
        Payment::factory()->create([
            'order_id' => $o->id, 'amount' => $amount, 'refund_amount' => $refunded, 'status' => $status,
            'payment_method' => $method, 'currency_code' => 'KES', 'paid_at' => now(),
        ]);
    }

    /** What the receipt says: its figures, and the page they are drawn into. */
    private function receipt(Order $o): array
    {
        $data = \App\Http\Controllers\Api\DocumentPdfController::orderReceiptData($o->fresh());

        return [$data, \App\Services\PdfService::orderReceipt($data)];
    }

    public function test_a_part_paid_receipt_counts_only_settled_money_net_of_refunds(): void
    {
        $this->person('admin');
        $o = $this->order(50_000);
        $this->pay($o, 5_000);                                   // settled
        $this->pay($o, 2_000, 'paid', 500, 'mpesa');             // settled, 500 refunded
        $this->pay($o, 10_000, 'pending', 0, 'bank_transfer');   // awaiting approval: not money yet
        $this->pay($o, 3_000, 'failed');                         // never money

        // Independent arithmetic: paid 5,000 + (2,000 − 500) = 6,500; due 50,000 − 6,500 = 43,500.
        [$data, $html] = $this->receipt($o);
        $this->assertSame(6_500.0, (float) $data['total_paid']);
        $this->assertSame(43_500.0, (float) $data['balance_due']);
        $this->assertCount(2, $data['settled_payments'], 'only settled payments are listed');
        // The screen's own figures (OrderController / Order::outstandingBalance) agree.
        $this->assertEquals($o->fresh()->outstandingBalance(), $data['balance_due']);

        $this->assertStringContainsString('KES 43,500', $html, 'balance due');
        $this->assertStringContainsString('KES 6,500', $html, 'total paid');
        $this->assertStringContainsString('Refund', $html, 'the refund is shown, not hidden');
        $this->assertStringNotContainsString('KES 10,000', $html, 'a payment awaiting approval is not money received');
        $this->assertStringNotContainsString('KES 3,000', $html, 'a failed payment is not money received');
        $this->assertStringNotContainsString('PAID IN FULL', $html);
        $this->assertStringContainsString('Bishop Gitahi', $html);

        // And the endpoint turns it into a real PDF file.
        $res = $this->get("/api/v1/admin/pdf/orders/{$o->id}/receipt")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $this->assertStringContainsString("Receipt-{$o->order_number}.pdf", (string) $res->headers->get('Content-Disposition'));
    }

    public function test_a_settled_order_says_paid_in_full(): void
    {
        $this->person('admin');
        $o = $this->order(12_000);
        $this->pay($o, 8_000);
        $this->pay($o, 4_000, 'paid', 0, 'mpesa');

        [$data, $html] = $this->receipt($o);
        $this->assertSame(0.0, (float) $data['balance_due']);
        $this->assertStringContainsString('PAID IN FULL', $html);
        $this->assertStringNotContainsString('Balance Due', $html);
    }

    /** What the invoice says: its figures, and the page they are drawn into. */
    private function invoice(Order $o): array
    {
        $data = \App\Http\Controllers\Api\DocumentPdfController::orderData($o->fresh(), true);

        return [$data, \App\Services\PdfService::order($data, true)];
    }

    public function test_an_unpaid_invoice_asks_for_the_amount_due_and_lists_no_unsettled_money(): void
    {
        $this->person('admin');
        $o = $this->order(9_000);
        $this->pay($o, 9_000, 'pending', 0, 'bank_transfer');   // awaiting approval: not received
        $this->pay($o, 4_000, 'failed');

        [$data, $html] = $this->invoice($o);
        $this->assertSame(0.0, (float) $data['total_paid']);
        $this->assertSame(9_000.0, (float) $data['balance_due']);
        $this->assertStringContainsString('Amount Due', $html);
        $this->assertStringContainsString('Please pay the amount due', $html);
        $this->assertStringContainsString($o->order_number, $html);
        $this->assertStringContainsString('No payment received yet.', $html);
        $this->assertStringNotContainsString('KES 4,000', $html, 'a failed payment is not listed as received');
        $this->assertStringContainsString('Invoice', $html);
    }

    public function test_the_invoice_and_the_receipt_agree_on_a_part_paid_order(): void
    {
        $this->person('admin');
        $o = $this->order(20_000);
        $this->pay($o, 6_000);
        $this->pay($o, 1_000, 'paid', 250, 'mpesa');
        $this->pay($o, 5_000, 'pending', 0, 'bank_transfer');

        // Independent arithmetic: 6,000 + (1,000 − 250) = 6,750 paid; 13,250 due.
        [$inv, $invHtml] = $this->invoice($o);
        [$rec, $recHtml] = $this->receipt($o);
        foreach ([[$inv, $invHtml], [$rec, $recHtml]] as [$data, $html]) {
            $this->assertSame(6_750.0, (float) $data['total_paid']);
            $this->assertSame(13_250.0, (float) $data['balance_due']);
            $this->assertStringContainsString('KES 13,250', $html);
            $this->assertStringNotContainsString('KES 5,000', $html, 'pending money is not received');
        }
    }

    public function test_the_invoice_endpoint_still_answers_a_pdf(): void
    {
        $this->person('admin');
        $o = $this->order(3_000);

        $res = $this->get("/api/v1/admin/pdf/orders/{$o->id}/invoice")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $this->get("/api/v1/admin/pdf/orders/{$o->id}")->assertOk();   // the internal Sales Order PDF too
    }

    public function test_nothing_paid_means_no_receipt_send_the_invoice(): void
    {
        $this->person('admin');
        $o = $this->order(9_000);
        $this->pay($o, 9_000, 'pending', 0, 'bank_transfer');

        $this->getJson("/api/v1/admin/pdf/orders/{$o->id}/receipt")->assertStatus(422);
        $this->get("/api/v1/admin/pdf/orders/{$o->id}/invoice")->assertOk();
    }

    public function test_the_receipt_has_the_order_pdfs_door(): void
    {
        // A cashier sees her own sales only (ViewerScope): another's receipt is not found.
        $mine  = $this->person('pos_clerk');
        $other = User::factory()->create();
        $own   = $this->order(1_000, $mine);
        $theirs = $this->order(1_000, $other);
        $this->pay($own, 1_000);
        $this->pay($theirs, 1_000);

        $this->get("/api/v1/admin/pdf/orders/{$own->id}/receipt")->assertOk();
        $this->getJson("/api/v1/admin/pdf/orders/{$theirs->id}/receipt")->assertNotFound();

        // No orders.view at all: refused.
        $this->person('tailor');
        $this->getJson("/api/v1/admin/pdf/orders/{$own->id}/receipt")->assertForbidden();
    }

    public function test_a_receipt_is_a_customer_document_never_held_for_approval(): void
    {
        Queue::fake();
        config(['audit.downloads.enforce' => true]);
        $clerk = $this->person('pos_clerk');
        $o = $this->order(2_000, $clerk);
        $this->pay($o, 2_000);

        $this->get("/api/v1/admin/pdf/orders/{$o->id}/receipt")->assertOk();

        // Recorded for the owner's morning digest like invoices and receipts.
        $this->assertSame('exempt', DownloadRequest::where('user_id', $clerk->id)->sole()->category);
    }

    // ── The receipt redesign (owner, 2026-11-05) ─────────────────────────────

    public function test_tax_included_in_prices_is_shown_as_included_never_as_an_added_line(): void
    {
        // The owner's WA-261027-QUAKB: USD 25 with 16% VAT inside the price.
        $this->person('admin');
        $o = $this->order(25);
        $o->update(['currency_code' => 'USD', 'tax_amount' => 3.45, 'prices_include_tax' => true]);
        Payment::factory()->create(['order_id' => $o->id, 'amount' => 25, 'currency_code' => 'USD', 'status' => 'paid', 'payment_method' => 'other', 'paid_at' => now()]);

        [, $html] = $this->receipt($o);
        $this->assertStringContainsString('Prices include tax of USD 3.45', $html);
        $this->assertStringNotContainsString('<td class="s-k">Tax</td>', $html, 'no Tax line that looks added on');
        $this->assertStringNotContainsString('Discount', $html, 'a zero discount is not printed');
        $this->assertStringNotContainsString('Shipping', $html, 'zero shipping is not printed');
        $this->assertStringContainsString('PAID IN FULL', $html);
        $this->assertStringContainsString('>PAID<', $html);
    }

    public function test_tax_added_on_top_is_a_line_and_the_summary_adds_up(): void
    {
        $this->person('admin');
        // Independent arithmetic: 10,000 − 500 + 1,520 tax + 800 shipping = 11,820.
        $o = $this->order(11_820);
        $o->update(['subtotal' => 10_000, 'discount_amount' => 500, 'tax_amount' => 1_520, 'shipping_amount' => 800, 'prices_include_tax' => false]);
        $this->pay($o, 4_000);

        [$data, $html] = $this->receipt($o);
        $this->assertSame(7_820.0, (float) $data['balance_due']);
        foreach (['KES 10,000', '− KES 500', 'KES 1,520', 'KES 800', 'KES 11,820', 'KES 4,000', 'KES 7,820'] as $figure) {
            $this->assertStringContainsString(str_replace('−', '&minus;', $figure), $html, $figure);
        }
        $this->assertStringContainsString('<td class="s-k">Tax</td>', $html);
        $this->assertStringContainsString('PARTIALLY PAID', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringNotContainsString('PAID IN FULL', $html);
    }

    public function test_a_payment_in_another_currency_shows_what_arrived_and_what_it_counts_for(): void
    {
        $this->person('admin');
        $o = $this->order(450);
        $o->update(['currency_code' => 'USD']);
        Payment::factory()->create(['order_id' => $o->id, 'amount' => 405.77, 'currency_code' => 'ZAR', 'order_amount' => 23.61, 'order_currency_code' => 'USD',
            'status' => 'paid', 'payment_method' => 'mukuru', 'paid_at' => now()->subDay()]);
        Payment::factory()->create(['order_id' => $o->id, 'amount' => 426.39, 'currency_code' => 'USD', 'status' => 'paid', 'payment_method' => 'other', 'paid_at' => now()]);

        [$data, $html] = $this->receipt($o);
        $this->assertSame(450.0, (float) $data['total_paid']);   // 23.61 + 426.39
        $this->assertStringContainsString('ZAR 405.77', $html);
        $this->assertStringContainsString('counts USD 23.61', $html);
        $this->assertStringNotContainsString('USD 405.77', $html, 'rand is never printed as dollars');
        $this->assertStringContainsString('PAID IN FULL', $html);

        // The invoice lists the same payment the same way.
        [, $invHtml] = $this->invoice($o);
        $this->assertStringContainsString('ZAR 405.77', $invHtml);
        $this->assertStringNotContainsString('USD 405.77', $invHtml);
    }

    public function test_a_payment_awaiting_approval_is_neither_listed_nor_counted(): void
    {
        $this->person('admin');
        $o = $this->order(10_000);
        $this->pay($o, 3_000);
        Payment::factory()->create(['order_id' => $o->id, 'amount' => 6_500, 'currency_code' => 'KES', 'status' => 'paid', 'payment_method' => 'bank_transfer',
            'requires_approval' => true, 'approval_status' => 'pending_review', 'paid_at' => now()]);

        [$data, $html] = $this->receipt($o);
        $this->assertSame(3_000.0, (float) $data['total_paid']);
        $this->assertCount(1, $data['settled_payments'], 'the list holds exactly what Total Paid sums');
        $this->assertStringNotContainsString('KES 6,500', $html);
        $this->assertStringContainsString('KES 7,000', $html, 'balance due: 10,000 − 3,000');
        $this->assertStringNotContainsString('PAID IN FULL', $html);
    }

    public function test_many_long_items_still_make_a_valid_pdf_with_every_line(): void
    {
        $this->person('admin');
        $o = $this->order(0);
        $total = 0;
        for ($i = 1; $i <= 30; $i++) {
            \App\Models\OrderItem::forceCreate(['order_id' => $o->id, 'product_id' => \App\Models\Product::factory()->create()->id, 'product_name' => "Vestment {$i} — " . str_repeat('hand-embroidered gold thread detail, ', 4),
                'sku' => "VST-{$i}", 'quantity' => 1, 'unit_price' => 1_000, 'discount_amount' => 0, 'total_price' => 1_000]);
            $total += 1_000;
        }
        $o->update(['subtotal' => $total, 'total_amount' => $total]);
        $this->pay($o, $total);

        [, $html] = $this->receipt($o);
        $this->assertSame(30, substr_count($html, '<td class="c-desc">'));
        $res = $this->get("/api/v1/admin/pdf/orders/{$o->id}/receipt")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $this->assertStringContainsString('KES 30,000', $html);
    }

    public function test_the_per_payment_receipt_prints_the_payment_in_its_own_currency(): void
    {
        $html = \App\Services\PdfService::receipt([
            'receipt_number' => 'RCT-1', 'order_number' => 'BH-1', 'issued_at' => now()->toIso8601String(), 'currency_code' => 'USD',
            'payment' => ['method' => 'mukuru', 'reference' => 'MK-1', 'amount' => 405.77, 'currency_code' => 'ZAR'],
            'invoice_total' => 450, 'paid_to_date' => 23.61, 'balance_due' => 426.39, 'fully_paid' => false,
        ]);
        $this->assertStringContainsString('ZAR 405.77', $html);
        $this->assertStringContainsString('USD 426.39', $html);
        $this->assertStringContainsString('PARTIALLY PAID', $html);
        $this->assertStringNotContainsString('PAID IN FULL', $html);
    }
}
