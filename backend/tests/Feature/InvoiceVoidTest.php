<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublicQuotationController;
use App\Models\DocumentRevision;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\Documents\InvoiceVoid;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * The super admin voids an invoice before it is paid, and the quotation goes
 * with it (owner, 2026-10-26). App\Services\Documents\InvoiceVoid.
 */
class InvoiceVoidTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private User $owner;
    private Outlet $outlet;
    private Product $product;
    private InventoryItem $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->outlet  = Outlet::factory()->create();
        $this->product = Product::factory()->create();
        $this->stock   = InventoryItem::create([
            'product_id' => $this->product->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 50, 'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        foreach (['cash' => 'cash', 'mpesa' => 'mobile_money'] as $code => $type) {
            DB::table('payment_methods')->insertOrIgnore([
                'code' => $code, 'name' => ucfirst($code), 'type' => $type, 'requires_approval' => false,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->owner = $this->person('super_admin');
        Sanctum::actingAs($this->owner);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        $u->outlets()->attach($this->outlet->id, ['is_primary' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** quotation → issue → accept, through the API: an issued invoice holding 3 pieces. */
    private function invoiced(int $qty = 3): array
    {
        $qid = $this->postJson('/api/v1/admin/quotations', [
            'outlet_id' => $this->outlet->id, 'valid_until' => now()->addDays(10)->toDateString(),
            'customer_first_name' => 'Fr', 'customer_last_name' => 'Kamau',
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Chasuble', 'quantity' => $qty, 'unit_price' => 1000]],
        ])->assertCreated()->json('quotation.id');
        $this->postJson("/api/v1/admin/quotations/{$qid}/issue")->assertOk();
        $orderId = $this->postJson("/api/v1/admin/quotations/{$qid}/accept")->assertCreated()->json('order.id');

        $order   = Order::findOrFail($orderId);
        $invoice = SalesDocument::where('type', 'invoice')->where('documentable_id', $order->id)->firstOrFail();

        return [Quotation::findOrFail($qid), $order, $invoice];
    }

    private function voidUrl(SalesDocument $invoice): string
    {
        return "/api/v1/admin/invoices/{$invoice->id}/void";
    }

    /** Nothing about the sale moved. */
    private function assertUntouched(Quotation $q, Order $order, SalesDocument $invoice, int $reserved = 3): void
    {
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->stock_unwound_at);
        $this->assertSame($reserved, (int) $this->stock->fresh()->quantity_reserved);
        $this->assertNotSame('void', $invoice->fresh()->status);
        $this->assertSame(Quotation::CONVERTED, $q->fresh()->status);
        $this->assertNull($q->fresh()->voided_at);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'invoice_voided')->count());
    }

    public function test_the_super_admin_voids_an_issued_unpaid_invoice_and_its_quotation(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);
        $this->assertSame(3, (int) $this->stock->fresh()->quantity_reserved, 'the invoice holds the goods');
        $this->assertNotNull($order->stock_reserved_at);
        $token  = $q->quote_token;
        $orders = Order::withoutViewerScope()->count();
        $docs   = SalesDocument::count();

        $this->stepUp($this->owner);
        $res = $this->postJson($this->voidUrl($invoice), ['reason' => 'Duplicate of INV-2026-0015'])->assertOk();
        $this->assertSame(
            "Invoice {$invoice->number} voided. Order {$order->order_number} cancelled and its stock released; quotation {$q->quote_number} voided.",
            $res->json('message'),
        );
        $res->assertJsonPath('invoice.doc_status', 'void')->assertJsonPath('invoice.order.status', 'cancelled');

        // The order: cancelled through the shared path, stock given back once.
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertNotNull($order->stock_unwound_at);
        $this->assertSame(0, (int) $this->stock->fresh()->quantity_reserved, 'the reservation is released');
        $this->assertSame(50, (int) $this->stock->fresh()->quantity_on_hand, 'nothing was ever taken off the shelf');
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'cancelled', 'created_by' => $this->owner->id,
        ]);
        $this->assertTrue($order->payment_token_expires_at->lte(now()), 'the pay-link dies with the invoice');

        // The invoice: void, the issued version kept as Rev 0.
        $invoice->refresh();
        $this->assertSame('void', $invoice->status);
        $this->assertSame(1, $invoice->revision);
        $rev = DocumentRevision::where('document_type', 'invoice')->where('document_id', $invoice->id)->sole();
        $this->assertSame('issued', $rev->snapshot['status']);
        $this->assertSame('Duplicate of INV-2026-0015', $rev->reason);
        $this->assertContains('Order cancelled: invoice void', $rev->changes);

        // The quotation: void, who/when/why, its public link no longer an offer, the token kept.
        $q->refresh();
        $this->assertSame(Quotation::VOID, $q->status);
        $this->assertSame('Duplicate of INV-2026-0015', $q->void_reason);
        $this->assertSame($this->owner->id, (int) $q->voided_by);
        $this->assertNotNull($q->voided_at);
        $this->assertSame($token, $q->quote_token);
        $this->assertTrue($q->quote_token_expires_at->lte(now()));
        $this->assertSame($order->id, (int) $q->converted_order_id, 'the link to the order it became is kept');
        $quoDoc = SalesDocument::where('type', 'quotation')->where('documentable_id', $q->id)->sole();
        $this->assertSame('void', $quoDoc->status);
        $this->assertSame('sent', DocumentRevision::where('document_type', 'quotation')->where('document_id', $quoDoc->id)->sole()->snapshot['status']);

        // One audit entry naming everything.
        $log = DB::table('activity_log')->where('event', 'invoice_voided')->sole();
        $this->assertSame(Order::class, $log->subject_type);
        $this->assertSame($order->id, (int) $log->subject_id);
        $this->assertSame($this->owner->id, (int) $log->causer_id);
        $props = json_decode($log->properties, true);
        $this->assertSame($invoice->number, $props['invoice_number']);
        $this->assertSame($q->quote_number, $props['quote_number']);
        $this->assertSame($order->order_number, $props['order_number']);
        $this->assertSame('Duplicate of INV-2026-0015', $props['reason']);
        $this->assertStringContainsString($invoice->number, $log->description);
        $this->assertStringContainsString($q->quote_number, $log->description);
        $this->assertStringContainsString($order->order_number, $log->description);

        // Never delete anything.
        $this->assertSame($orders, Order::withoutViewerScope()->count());
        $this->assertSame($docs, SalesDocument::count());

        // Lists say so.
        $this->getJson('/api/v1/admin/invoices?status=void')->assertOk()->assertJsonPath('data.0.invoice_number', $invoice->number);
        $this->getJson('/api/v1/admin/quotations?status=void')->assertOk()
            ->assertJsonPath('data.0.id', $q->id)->assertJsonPath('data.0.void_reason', 'Duplicate of INV-2026-0015');

        // The quotation PDF says it is void.
        $html = \App\Services\PdfService::quotation($q->toArray());
        $this->assertStringContainsString('VOID', $html);
        $this->assertStringContainsString('no longer valid', $html);
    }

    public function test_a_void_quotation_cannot_be_accepted_again_and_its_link_says_so(): void
    {
        [$q, , $invoice] = $this->invoiced(1);
        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Raised twice by mistake'])->assertOk();

        // Staff accept: refused in plain words, nothing new created.
        $orders = Order::withoutViewerScope()->count();
        $this->postJson("/api/v1/admin/quotations/{$q->id}/accept")->assertStatus(422)
            ->assertJsonPath('message', 'This quotation was voided and can no longer be accepted.');
        $this->postJson("/api/v1/admin/quotations/{$q->id}/issue")->assertStatus(422);
        $this->putJson("/api/v1/admin/quotations/{$q->id}/revise", [
            'reason' => 'Try to revive it', 'outlet_id' => $this->outlet->id,
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Chasuble', 'quantity' => 2, 'unit_price' => 1000]],
        ])->assertStatus(422);

        // The customer's link: no longer valid — and no pay-link for the cancelled order.
        $this->getJson("/api/v1/quote/{$q->quote_token}")->assertStatus(410)
            ->assertJsonPath('message', PublicQuotationController::VOID_MESSAGE);
        $accept = $this->postJson("/api/v1/quote/{$q->quote_token}/accept")->assertStatus(410);
        $this->assertNull($accept->json('pay_token'));
        $this->assertSame($orders, Order::withoutViewerScope()->count());
        $this->assertSame(Quotation::VOID, $q->fresh()->status);

        // Voiding again is refused.
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Once more please'])->assertStatus(422)
            ->assertJsonPath('message', "Invoice {$invoice->number} is already void.");
        $this->assertSame(1, DB::table('activity_log')->where('event', 'invoice_voided')->count());
    }

    public function test_only_the_super_admin_may_void_an_invoice(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);

        foreach (['admin', 'finance_manager'] as $role) {
            $u = $this->person($role);
            $this->stepUp($u);   // confirmed identity is not the point: the role is
            Sanctum::actingAs($u);
            $this->postJson($this->voidUrl($invoice), ['reason' => "{$role} tries to void"])->assertForbidden();
        }

        $this->assertUntouched($q, $order, $invoice);
    }

    public function test_the_super_admin_must_step_up_first(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);

        $this->postJson($this->voidUrl($invoice), ['reason' => 'No fresh confirmation'])
            ->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->assertUntouched($q, $order, $invoice);

        // A reason is required, 5–500 characters.
        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($this->voidUrl($invoice), ['reason' => 'dup'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($this->voidUrl($invoice), ['reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertUntouched($q, $order, $invoice);
    }

    public function test_an_invoice_with_a_partial_payment_is_refused_and_nothing_changes(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'mpesa', 'amount' => 1000, 'reference' => 'SJK7PART01',
        ])->assertSuccessful();
        $this->assertSame('issued', $invoice->fresh()->status);
        $status = $order->fresh()->status;

        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Customer changed mind'])->assertStatus(422)
            ->assertJsonPath('message', InvoiceVoid::PAYMENTS_MESSAGE);

        $this->assertSame($status, $order->fresh()->status);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(Quotation::CONVERTED, $q->fresh()->status);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'invoice_voided')->count());
    }

    public function test_a_paid_invoice_is_untouched(): void
    {
        [$q, $order, $invoice] = $this->invoiced(2);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => 'mpesa', 'amount' => (float) $order->total_amount, 'reference' => 'SJK7FULL01',
        ])->assertSuccessful();
        $before = $invoice->fresh()->only(['status', 'revision', 'amount']);
        $orderStatus = $order->fresh()->status;

        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Should never work'])->assertStatus(422)
            ->assertJsonPath('message', InvoiceVoid::PAYMENTS_MESSAGE);

        $this->assertSame($before, $invoice->fresh()->only(['status', 'revision', 'amount']));
        $this->assertSame($orderStatus, $order->fresh()->status);
        $this->assertSame(Quotation::CONVERTED, $q->fresh()->status);
        $this->assertSame(1, Payment::where('order_id', $order->id)->where('status', 'paid')->count());
    }

    public function test_a_payment_awaiting_approval_blocks_but_a_voided_one_does_not(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);
        $pending = Payment::create([
            'order_id' => $order->id, 'amount' => 3000, 'currency_code' => 'KES', 'payment_method' => 'bank_transfer',
            'status' => 'pending', 'requires_approval' => true, 'approval_status' => 'pending_review',
        ]);

        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Awaiting approval case'])->assertStatus(422)
            ->assertJsonPath('message', InvoiceVoid::PAYMENTS_MESSAGE);
        $this->assertUntouched($q, $order, $invoice);

        // Voided first (as the message says) — then the invoice may go.
        $pending->update(['status' => 'voided']);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Payment voided, now the invoice'])->assertOk();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame(Quotation::VOID, $q->fresh()->status);
    }

    public function test_an_order_already_on_its_way_or_cancelled_is_refused(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);
        $this->stepUp($this->owner);

        foreach (['processing', 'shipped', 'delivered', 'completed'] as $status) {
            DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
            $this->postJson($this->voidUrl($invoice), ['reason' => "Order is {$status}"])->assertStatus(422)
                ->assertJsonPath('message', "Order {$order->order_number} is already {$status}. An invoice can only be voided while its order is pending or confirmed.");
        }
        DB::table('orders')->where('id', $order->id)->update(['status' => 'pending']);
        $this->assertUntouched($q, $order, $invoice);

        // Cancelled the old way (the status dropdown): the invoice went void with it.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame('void', $invoice->fresh()->status);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'After a cancel'])->assertStatus(422)
            ->assertJsonPath('message', "Invoice {$invoice->number} is already void.");
    }

    public function test_a_confirmed_unpaid_order_may_have_its_invoice_voided(): void
    {
        [$q, $order, $invoice] = $this->invoiced(2);
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Agreed with the customer'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, (int) $this->stock->fresh()->quantity_reserved);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $order->id, 'from_status' => 'confirmed', 'to_status' => 'cancelled']);
    }

    public function test_the_status_dropdown_still_cancels_through_the_same_path(): void
    {
        [, $order, $invoice] = $this->invoiced(3);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled', 'notes' => 'Customer called'])->assertOk();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertNotNull($order->stock_unwound_at);
        $this->assertSame(0, (int) $this->stock->fresh()->quantity_reserved);
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'cancelled', 'notes' => 'Customer called',
        ]);
        $this->assertDatabaseHas('activity_log', ['event' => 'status_changed', 'subject_id' => $order->id, 'causer_id' => $this->owner->id]);

        // Cancelling again is the machine's no-op (same status), as before:
        // the stock is not given back twice and the cancel date stays.
        $cancelledAt = $order->cancelled_at;
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(0, (int) $this->stock->fresh()->quantity_reserved);
        $this->assertSame(50, (int) $this->stock->fresh()->quantity_on_hand);
        $this->assertTrue($order->fresh()->cancelled_at->equalTo($cancelledAt));
        // And a cancelled order cannot be revived.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'pending'])->assertStatus(422);
    }

    public function test_reports_no_longer_count_the_void_quote_or_its_order(): void
    {
        [$q, $order, $invoice] = $this->invoiced(3);
        $range = '?start=' . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString();

        // Before: the quoted order is an unconfirmed cart (pipeline), the quote converted.
        $this->assertTrue(Order::withoutViewerScope()->pipeline()->whereKey($order->id)->exists());
        $ledger = $this->getJson('/api/v1/admin/reports/sales/ledger' . $range)->assertOk();
        $this->assertSame(1, (int) $ledger->json('pipeline.by_channel.quoted.orders'));
        $this->assertEqualsWithDelta((float) $order->total_amount, (float) $ledger->json('pipeline.by_channel.quoted.sales'), 0.01);
        $funnel = MetricEngine::for($this->owner)->collectionsFunnel();
        $this->assertSame(100.0, (float) $funnel['summary']['conversion']['quotes_converted_rate_90d']);

        $this->stepUp($this->owner);
        $this->postJson($this->voidUrl($invoice), ['reason' => 'Duplicate quotation'])->assertOk();

        // After: neither pipeline nor sales; the void quote is neither quoted nor converted.
        $this->assertFalse(Order::withoutViewerScope()->pipeline()->whereKey($order->id)->exists());
        $this->assertFalse(Order::withoutViewerScope()->recognised()->whereKey($order->id)->exists());
        $ledger = $this->getJson('/api/v1/admin/reports/sales/ledger' . $range)->assertOk();
        $this->assertSame(0, (int) $ledger->json('pipeline.by_channel.quoted.orders'));
        $this->assertSame(0.0, (float) $ledger->json('pipeline.total.sales'));
        $this->assertSame(0.0, (float) collect($ledger->json('channels'))->sum('sales'));
        $funnel = MetricEngine::for($this->owner)->collectionsFunnel();
        $this->assertNull($funnel['summary']['conversion']['quotes_converted_rate_90d']);
        $this->assertNull($funnel['summary']['conversion']['avg_days_quote_to_order']);

        // An open quote is on offer; a void one (even with no order behind it) is not.
        DB::table('quotations')->insert([
            ['quote_number' => 'QUO-OPEN', 'status' => 'sent', 'source' => 'admin', 'currency_code' => 'KES', 'subtotal' => 0, 'discount_amount' => 0,
             'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 7000, 'created_at' => now(), 'updated_at' => now()],
            ['quote_number' => 'QUO-VOID', 'status' => 'void', 'source' => 'admin', 'currency_code' => 'KES', 'subtotal' => 0, 'discount_amount' => 0,
             'tax_amount' => 0, 'shipping_amount' => 0, 'total_amount' => 9000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $funnel = MetricEngine::for($this->owner)->collectionsFunnel();
        $this->assertSame(1, $funnel['summary']['open_quotes']['count']);
        $this->assertEqualsWithDelta(7000.0, $funnel['summary']['open_quotes']['value'], 0.01);
        $this->assertSame(['QUO-OPEN'], collect($funnel['open_quotes'])->pluck('number')->all());
    }
}
