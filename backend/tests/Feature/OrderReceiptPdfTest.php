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

        $this->assertStringContainsString('43,500.00', $html, 'balance due');
        $this->assertStringContainsString('6,500.00', $html, 'total paid');
        $this->assertStringContainsString('Refund', $html, 'the refund is shown, not hidden');
        $this->assertStringNotContainsString('10,000.00', $html, 'a payment awaiting approval is not money received');
        $this->assertStringNotContainsString('3,000.00', $html, 'a failed payment is not money received');
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
}
