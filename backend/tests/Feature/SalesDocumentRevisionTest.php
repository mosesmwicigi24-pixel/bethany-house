<?php

namespace Tests\Feature;

use App\Models\DocumentRevision;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Revisions of issued sales documents (owner, 2026-10-16): an issued
 * quotation revised by the owner; the invoice copy following its order;
 * a payment's details corrected (its receipt following) — never its amount.
 * Every earlier version is kept, and documents show "Rev N".
 */
class SalesDocumentRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Outlet $outlet;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->outlet  = Outlet::factory()->create();
        $this->product = Product::factory()->create();
        InventoryItem::create([
            'product_id' => $this->product->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 50, 'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        foreach (['cash' => 'cash', 'mpesa' => 'mobile_money', 'bank_transfer' => 'bank'] as $code => $type) {
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

    private function issuedQuote(int $qty = 3): Quotation
    {
        $id = $this->postJson('/api/v1/admin/quotations', [
            'outlet_id' => $this->outlet->id, 'valid_until' => now()->addDays(10)->toDateString(),
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Chasuble', 'quantity' => $qty, 'unit_price' => 1000]],
        ])->assertCreated()->json('quotation.id');
        $this->postJson("/api/v1/admin/quotations/{$id}/issue")->assertOk();

        return Quotation::findOrFail($id);
    }

    private function quoteDoc(Quotation $q): SalesDocument
    {
        return SalesDocument::where('type', 'quotation')->where('documentable_id', $q->id)->firstOrFail();
    }

    private function invoiceDoc(Order $o): SalesDocument
    {
        return SalesDocument::where('type', 'invoice')->where('documentable_id', $o->id)->firstOrFail();
    }

    private function revisePayload(Quotation $q, int $qty, string $reason): array
    {
        return [
            'reason' => $reason, 'outlet_id' => $this->outlet->id, 'valid_until' => now()->addDays(20)->toDateString(),
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Chasuble', 'quantity' => $qty, 'unit_price' => 1000]],
        ];
    }

    // ── Quotation ─────────────────────────────────────────────────────────────

    public function test_the_owner_revises_an_issued_quotation_and_the_issued_version_is_kept(): void
    {
        $q   = $this->issuedQuote(3);
        $doc = $this->quoteDoc($q);
        $was = (float) $doc->amount;
        $this->assertSame(3, $doc->snapshot['items'][0]['quantity']);

        $this->putJson("/api/v1/admin/quotations/{$q->id}/revise", $this->revisePayload($q, 5, 'Customer wants five'))
            ->assertOk()->assertJsonPath('revision', 1);

        $doc->refresh();
        $q->refresh();
        $this->assertSame(1, $doc->revision);
        $this->assertSame(5, $doc->snapshot['items'][0]['quantity']);
        $this->assertSame((string) $q->total_amount, (string) $doc->amount, 'the issued copy states the revised total');
        // Same price per piece: five cost five-thirds of three.
        $this->assertEqualsWithDelta($was * 5 / 3, (float) $doc->amount, 0.01);
        $this->assertSame($q->quote_number, $doc->number, 'the number stays');

        $rev = DocumentRevision::where('document_type', 'quotation')->where('document_id', $doc->id)->sole();
        $this->assertSame(0, $rev->revision);
        $this->assertSame(3, $rev->snapshot['snapshot']['items'][0]['quantity']);
        $this->assertSame($was, (float) $rev->snapshot['amount']);
        $this->assertSame('Customer wants five', $rev->reason);
        $this->assertDatabaseHas('activity_log', ['event' => 'quotation_revised', 'subject_id' => $q->id, 'causer_id' => $this->owner->id]);
        $this->getJson("/api/v1/admin/quotations/{$q->id}/revisions")->assertOk()->assertJsonPath('current_revision', 1);

        // The PDF says which revision it is.
        $data = $q->toArray() + ['revision' => 1];
        $this->assertStringContainsString('Rev 1', \App\Services\PdfService::quotation($data));
    }

    public function test_quotation_revision_rules(): void
    {
        $q = $this->issuedQuote(2);

        // Not the owner: refused, nothing changes.
        Sanctum::actingAs($this->person('admin'));
        $this->putJson("/api/v1/admin/quotations/{$q->id}/revise", $this->revisePayload($q, 4, 'Admin tries'))->assertForbidden();
        Sanctum::actingAs($this->owner);

        // No change → refused, no empty revision.
        $same = $this->revisePayload($q, 2, 'Nothing really');
        $same['valid_until'] = $q->valid_until->toDateString();
        $this->putJson("/api/v1/admin/quotations/{$q->id}/revise", $same)->assertStatus(422);
        $this->assertSame(0, DocumentRevision::count());

        // A declined quote revised is offered again.
        $q->update(['status' => Quotation::DECLINED]);
        $this->putJson("/api/v1/admin/quotations/{$q->id}/revise", $this->revisePayload($q, 1, 'Smaller offer'))->assertOk();
        $this->assertSame(Quotation::SENT, $q->fresh()->status);
        $this->assertSame('sent', $this->quoteDoc($q)->status);

        // A draft is edited, not revised.
        $draftId = $this->postJson('/api/v1/admin/quotations', [
            'outlet_id' => $this->outlet->id,
            'items' => [['product_id' => $this->product->id, 'product_name' => 'Chasuble', 'quantity' => 1, 'unit_price' => 1000]],
        ])->json('quotation.id');
        $this->putJson("/api/v1/admin/quotations/{$draftId}/revise", $this->revisePayload($q, 2, 'Draft revise'))->assertStatus(422);

        // Once it is an order, the order is what changes.
        $this->postJson("/api/v1/admin/quotations/{$q->id}/accept")->assertCreated();
        $this->putJson("/api/v1/admin/quotations/{$q->id}/revise", $this->revisePayload($q, 3, 'Too late'))
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'This quotation became an order. Change the order instead — its invoice follows.');
    }

    // ── Invoice ───────────────────────────────────────────────────────────────

    private function invoicedOrder(int $qty = 3): Order
    {
        $q = $this->issuedQuote($qty);
        $orderId = $this->postJson("/api/v1/admin/quotations/{$q->id}/accept")->assertCreated()->json('order.id');

        return Order::findOrFail($orderId);
    }

    public function test_the_invoice_copy_follows_its_order_and_keeps_each_old_version(): void
    {
        $order = $this->invoicedOrder(3);
        $inv   = $this->invoiceDoc($order);
        $was   = (float) $inv->amount;
        $issuedAt = $inv->snapshot['issued_at'];
        $this->assertSame((float) $order->total_amount, $was);

        // A non-money change (notes) leaves the invoice alone.
        $order->update(['notes' => 'Deliver to the vestry']);
        $this->assertSame(0, $inv->fresh()->revision);

        // Shipping 500 added: the invoice is now the old total + 500.
        $this->patchJson("/api/v1/admin/orders/{$order->id}/shipping-fee", ['amount' => 500, 'note' => 'Courier'])->assertOk();
        $inv->refresh();
        $this->assertSame(1, $inv->revision);
        $this->assertEqualsWithDelta($was + 500, (float) $inv->amount, 0.01);
        $this->assertSame(500.0, (float) $inv->snapshot['totals']['shipping_amount']);
        $this->assertSame($issuedAt, $inv->snapshot['issued_at'], 'a revision keeps the issue date');
        $rev = DocumentRevision::where('document_type', 'invoice')->where('document_id', $inv->id)->sole();
        $this->assertSame($was, (float) $rev->snapshot['amount']);

        // Lines edited through the order editor: quantity 3 → 4.
        $line = $order->fresh('items')->items->first();
        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 1000]],
            'reason' => 'One more chasuble',
        ])->assertOk();
        $inv->refresh();
        $this->assertSame(2, $inv->revision);
        $this->assertSame(4, $inv->snapshot['items'][0]['quantity']);
        $this->assertSame((float) $order->fresh()->total_amount, (float) $inv->amount);
        $this->assertSame('One more chasuble', DocumentRevision::where('document_id', $inv->id)->where('revision', 1)->value('reason'));

        // The invoice PDF names the invoice and its revision.
        $html = \App\Services\PdfService::order(\App\Http\Controllers\Api\DocumentPdfController::orderData($order->fresh(), true), true);
        $this->assertStringContainsString($inv->number . ' · Rev 2', $html);

        // Voiding the order voids the invoice, as a revision too.
        $this->postJson("/api/v1/admin/orders/{$order->id}/void", ['reason' => 'Customer cancelled the order'])->assertOk();
        $inv->refresh();
        $this->assertSame('void', $inv->status);
        $this->assertSame(3, $inv->revision);
    }

    // ── Receipt (payment details) ─────────────────────────────────────────────

    private function paid(Order $order, string $method = 'mpesa', string $ref = 'SJK7ABC123'): Payment
    {
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments", [
            'method' => $method, 'amount' => (float) $order->total_amount, 'reference' => $ref, 'provider_reference' => $ref,
        ])->assertSuccessful();

        return Payment::where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    public function test_the_owner_corrects_a_payments_details_and_its_receipt_follows(): void
    {
        // Midday, so "an hour ago" is still the order's day whenever the suite runs.
        $this->travelTo(now()->setTime(12, 0));
        $order   = $this->invoicedOrder(2);
        $payment = $this->paid($order, 'mpesa');
        $payment->update(['provider_reference' => 'SJK7ABC123']);
        $amount  = (string) $payment->amount;
        $receipt = SalesDocument::where('type', 'receipt')->where('payment_id', $payment->id)->firstOrFail();

        $paidAt = now()->subHour()->startOfMinute();
        $this->putJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/revise", [
            'reason' => 'Customer paid by bank, not M-PESA', 'provider_reference' => 'FT26289XYZ',
            'payment_method' => 'bank_transfer', 'paid_at' => $paidAt->toIso8601String(),
        ])->assertOk();

        $payment->refresh();
        $this->assertSame('FT26289XYZ', $payment->provider_reference);
        $this->assertSame('bank_transfer', $payment->payment_method);
        $this->assertSame($amount, (string) $payment->amount, 'the amount never moves');
        $this->assertTrue($payment->paid_at->equalTo($paidAt));

        $receipt->refresh();
        $this->assertSame(1, $receipt->revision);
        $this->assertSame('FT26289XYZ', $receipt->snapshot['payment']['reference']);
        $this->assertSame('bank_transfer', $receipt->snapshot['payment']['method']);
        $this->assertStringContainsString('Rev 1', \App\Services\PdfService::receipt($receipt->snapshot));

        $rev = DocumentRevision::where('document_type', 'payment')->where('document_id', $payment->id)->sole();
        $this->assertSame('SJK7ABC123', $rev->snapshot['payment']['provider_reference']);
        $this->assertSame('mpesa', $rev->snapshot['payment']['payment_method']);
        $this->assertSame($receipt->number, $rev->snapshot['receipt']['number']);
        $this->getJson("/api/v1/admin/orders/{$order->id}/document-revisions")->assertOk()
            ->assertJsonPath("payments.{$payment->id}.0.revision", 0);
    }

    public function test_a_payment_is_never_dated_before_its_orders_day_across_midnight(): void
    {
        // 00:20 Nairobi: the order is minutes old and yesterday is an hour away.
        $this->travelTo(now()->setTime(0, 20));
        $order   = $this->invoicedOrder(1);
        $payment = $this->paid($order, 'mpesa');
        $url = "/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/revise";

        $this->putJson($url, ['reason' => 'Paid late last night', 'paid_at' => now()->subHour()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('paid_at');
        $this->assertNull(DocumentRevision::where('document_type', 'payment')->where('document_id', $payment->id)->first());

        // Earlier the same Nairobi day is still the order's day.
        $early = now()->setTime(0, 5);
        $this->putJson($url, ['reason' => 'Paid just after midnight', 'paid_at' => $early->toIso8601String()])->assertOk();
        $this->assertTrue($payment->fresh()->paid_at->equalTo($early));
    }

    public function test_money_taken_is_never_rewritten_here(): void
    {
        $order   = $this->invoicedOrder(1);
        $payment = $this->paid($order, 'mpesa');
        $url = "/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/revise";

        $this->putJson($url, ['reason' => 'Wrong amount typed', 'amount' => 1])->assertStatus(422);
        $this->putJson($url, ['reason' => 'Was actually cash', 'payment_method' => 'cash'])->assertStatus(422);
        $this->putJson($url, ['reason' => 'Paid tomorrow?', 'paid_at' => now()->addDay()->toIso8601String()])->assertStatus(422);
        $this->putJson($url, ['reason' => 'Nothing at all'])->assertStatus(422);

        // Taken at a till: the drawer counted it by method and day — only the reference changes.
        DB::table('cash_register_transactions')->insert([
            'cash_register_id' => DB::table('cash_registers')->insertGetId([
                'register_number' => 'REG-T1', 'register_name' => 'Till 1', 'outlet_id' => $this->outlet->id,
                'opened_by' => $this->owner->id, 'opening_balance' => 0, 'status' => 'open',
                'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]),
            'transaction_type' => 'sale', 'payment_method' => 'mpesa', 'amount' => $payment->amount,
            'balance_after' => 0, 'order_id' => $order->id, 'created_by' => $this->owner->id, 'created_at' => now(),
        ]);
        $this->putJson($url, ['reason' => 'Bank after all', 'payment_method' => 'bank_transfer'])->assertStatus(422);
        $this->putJson($url, ['reason' => 'Typo in the code', 'provider_reference' => 'SJK7ABC124'])->assertOk();

        // Only the owner.
        Sanctum::actingAs($this->person('admin'));
        $this->putJson($url, ['reason' => 'Admin tries', 'provider_reference' => 'X'])->assertForbidden();
        $this->assertSame('SJK7ABC124', $payment->fresh()->provider_reference);
        $this->assertSame((string) $payment->amount, (string) $payment->fresh()->amount);
    }
}
