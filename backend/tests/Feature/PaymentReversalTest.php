<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\PaymentReversal;
use App\Services\ReceiptService;
use App\Services\Reporting\MetricEngine;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A payment recorded or approved by mistake, undone (owner, 2026-10-18).
 *
 * Nothing is deleted: the payment is voided with the reason, and everything
 * "paid" did is undone with it — money figures, stock, the receipt, the
 * automatic status advance. The super admin does it at once; anyone else
 * raises the request finance signs. Figures by hand.
 */
class PaymentReversalTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $owner;
    private User $fm;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();
        $this->outlet     = Outlet::factory()->create(['country_code' => 'KE']);
        $this->owner      = $this->user('super_admin');
        $this->fm         = $this->user('finance_manager');
        $this->accountant = $this->user('accountant');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findOrCreate($role, 'sanctum'));
        $u->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** A web order a staff member recorded a bank payment on, which auto-advanced it. */
    private function paidWebOrder(float $amount = 44000, string $method = 'bank_transfer', array $paymentAttrs = []): array
    {
        $order = Order::factory()->create([
            'outlet_id' => $this->outlet->id, 'order_type' => 'online', 'sales_bucket' => 'web',
            'status' => 'processing', 'payment_status' => 'paid', 'currency_code' => 'KES',
            'subtotal' => $amount, 'total_amount' => $amount,
            'created_by' => $this->owner->id,
        ]);
        $payment = Payment::create($paymentAttrs + [
            'order_id' => $order->id, 'payment_method' => $method, 'amount' => $amount, 'currency_code' => 'KES',
            'status' => 'paid', 'paid_at' => now()->subMinute(), 'created_by' => $this->owner->id,
        ]);
        DB::table('order_status_history')->insert([
            'order_id' => $order->id, 'from_status' => 'pending', 'to_status' => 'processing',
            'created_by' => $this->owner->id, 'notes' => 'Automatically advanced after payment confirmed.', 'created_at' => now(),
        ]);

        return [$order, $payment];
    }

    private function window(): array
    {
        return [now()->subDay()->startOfDay(), now()->addDay()->endOfDay(), now()->subDays(3), now()->subDays(2)];
    }

    // ── the super admin undoes a mistake at once ─────────────────────────────

    public function test_the_super_admin_reverses_a_mistaken_payment_and_every_figure_reads_unpaid(): void
    {
        [$order, $payment] = $this->paidWebOrder();
        [$s, $e, $ps, $pe] = $this->window();
        $engine = MetricEngine::unscoped();
        $this->assertEquals(44000.0, $engine->collected($s, $e, $ps, $pe)['current'], 'before: the money counts');
        $this->assertEquals(0.0, $engine->outstandingBalance()['amount']);

        Sanctum::actingAs($this->owner);
        $r = $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Approved by mistake'])
            ->assertOk();

        $this->assertSame('pending', $r->json('payment_status'));
        $this->assertSame('pending', $r->json('status'), 'the automatic advance is undone');
        $this->assertEquals(0.0, $r->json('total_paid'));
        $this->assertEquals(44000.0, $r->json('outstanding'));

        $payment->refresh();
        $this->assertSame('voided', $payment->status, 'the row stays, voided');
        $this->assertSame('Approved by mistake', $payment->void_reason);
        $this->assertSame($this->owner->id, (int) $payment->voided_by);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertEquals(0.0, $order->totalPaid());
        $this->assertEquals(44000.0, $order->outstandingBalance());

        // Money truth moves; sales truth does not (a confirmed sale is still a sale — this one went back to a cart).
        $this->assertEquals(0.0, $engine->collected($s, $e, $ps, $pe)['current'], 'collected drops by the amount');
        $this->assertEquals(0.0, $engine->revenue($s, $e, $ps, $pe)['current'], 'a pending unpaid cart is not a sale');
        $this->assertEquals(0.0, $engine->outstandingBalance()['amount'], 'a cart owes nothing yet');

        $history = DB::table('order_status_history')->where('order_id', $order->id)->orderByDesc('id')->first();
        $this->assertSame(['processing', 'pending'], [$history->from_status, $history->to_status]);
        $this->assertStringContainsString('Returned after a payment was reversed: Approved by mistake', $history->notes);

        $this->assertDatabaseHas('activity_log', ['event' => PaymentReversal::EVENT, 'subject_id' => $payment->id]);

        // Again: nothing to reverse.
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Twice'])
            ->assertStatus(422)->assertJsonFragment(['message' => "{$payment->payment_number}: This payment is already voided."]);
    }

    public function test_a_confirmed_order_stays_a_sale_and_becomes_a_receivable_when_the_status_is_kept(): void
    {
        [$order, $payment] = $this->paidWebOrder();
        $order->update(['status' => 'confirmed']);
        [$s, $e, $ps, $pe] = $this->window();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Wrong order', 'restore_status' => false])
            ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('payment_status', 'pending');

        $engine = MetricEngine::unscoped();
        $this->assertEquals(44000.0, $engine->revenue($s, $e, $ps, $pe)['current'], 'still a recognised sale');
        $this->assertEquals(0.0, $engine->collected($s, $e, $ps, $pe)['current']);
        $this->assertEquals(44000.0, $engine->outstandingBalance()['amount'], 'now owed in full');
    }

    public function test_a_reason_is_required_and_a_manual_status_change_since_is_left_alone(): void
    {
        [$order, $payment] = $this->paidWebOrder();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", [])->assertStatus(422);

        // A person moved the order on after the payment: the reversal does not undo their decision.
        $order->update(['status' => 'shipped']);
        DB::table('order_status_history')->insert(['order_id' => $order->id, 'from_status' => 'processing', 'to_status' => 'shipped',
            'created_by' => $this->owner->id, 'notes' => 'Dispatched', 'created_at' => now()]);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Mistake'])
            ->assertOk()->assertJsonPath('status', 'shipped')->assertJsonPath('payment_status', 'pending');
    }

    // ── stock and receipt ────────────────────────────────────────────────────

    public function test_goods_a_full_payment_took_off_the_shelf_go_back_on_it_and_serials_back_in_stock(): void
    {
        [$order, $payment] = $this->paidWebOrder(1000);
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $inv = InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'reorder_point' => 0]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => null,
            'product_name' => 'Cassock', 'sku' => 'CAS-1', 'quantity' => 2, 'unit_price' => 500, 'total_price' => 1000]);
        $serial = ProductSerial::create(['product_id' => $product->id, 'serial_number' => 'SN-1', 'status' => ProductSerial::SOLD,
            'order_id' => $order->id, 'sold_at' => now(), 'outlet_id' => $this->outlet->id]);
        // As commitForOrder left it: 2 units left the shelf, reservation consumed.
        $inv->update(['quantity_on_hand' => 8, 'quantity_reserved' => 0]);
        $order->forceFill(['stock_reserved_at' => now()->subHour(), 'stock_committed_at' => now()->subMinute()])->save();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Mistake'])
            ->assertOk()->assertJsonPath('reversals.0.stock_returned', true);

        $inv->refresh();
        $this->assertSame([10, 2], [(int) $inv->quantity_on_hand, (int) $inv->quantity_reserved], 'back on the shelf, reservation held again');
        $this->assertNull($order->fresh()->stock_committed_at);
        $this->assertNotNull($order->fresh()->stock_reserved_at, 'the order is live: its reservation stands');
        $this->assertSame(ProductSerial::IN_STOCK, $serial->fresh()->status);
        $this->assertNull($serial->fresh()->order_id);
        $this->assertDatabaseHas('inventory_transactions', ['inventory_item_id' => $inv->id, 'transaction_type' => 'correction', 'quantity_change' => 2]);
    }

    public function test_the_receipt_is_marked_void_and_the_invoice_reopens(): void
    {
        [$order, $payment] = $this->paidWebOrder(1000);
        SalesDocument::create(['type' => SalesDocument::INVOICE, 'number' => 'INV-1', 'status' => 'paid', 'amount' => 1000,
            'documentable_type' => Order::class, 'documentable_id' => $order->id, 'currency_code' => 'KES', 'created_by' => $this->owner->id]);
        $receipt = ReceiptService::onPaymentSettled($order, $payment, $this->owner->id);
        $this->assertNotNull($receipt);
        $this->assertSame('issued', $receipt->status);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Mistake'])
            ->assertOk()->assertJsonPath('reversals.0.receipt_voided', true);

        $receipt->refresh();
        $this->assertSame('void', $receipt->status, 'the printed receipt is findable as void, never missing');
        $this->assertSame('Mistake', $receipt->snapshot['void']['reason']);
        $this->assertSame('issued', SalesDocument::where('number', 'INV-1')->value('status'));
    }

    // ── what it refuses ──────────────────────────────────────────────────────

    public function test_provider_verified_money_and_till_cash_are_refused_with_the_right_door_named(): void
    {
        [$mpesaOrder, $mpesa] = $this->paidWebOrder(500, 'mpesa', ['provider_transaction_id' => 'QAB12XYZ']);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/orders/{$mpesaOrder->id}/payments/{$mpesa->id}/reverse", ['reason' => 'Mistake'])
            ->assertStatus(422)->assertJsonFragment(['message' => "{$mpesa->payment_number}: This payment was verified by the provider, so the money is real. Refund it; a verified payment is not reversed as a mistake."]);
        $this->assertSame('paid', $mpesa->fresh()->status);

        [$tillOrder, $cash] = $this->paidWebOrder(700, 'cash');
        $tillOrder->update(['order_type' => 'pos', 'sales_bucket' => 'till']);
        $register = CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => 'Till 1', 'status' => 'open', 'currency_code' => 'KES',
            'opening_balance' => 1000, 'expected_cash' => 1700, 'opened_by' => $this->owner->id, 'opened_at' => now()]);
        DB::table('cash_register_transactions')->insert(['cash_register_id' => $register->id, 'transaction_type' => 'sale', 'payment_method' => 'cash',
            'amount' => 700, 'currency_code' => 'KES', 'balance_after' => 1700, 'order_id' => $tillOrder->id, 'created_at' => now()]);
        $this->postJson("/api/v1/admin/orders/{$tillOrder->id}/payments/{$cash->id}/reverse", ['reason' => 'Mistake'])
            ->assertStatus(422)->assertJsonFragment(['message' => "{$cash->payment_number}: This cash was rung through a till. Void it from the till so the drawer gives the cash back."]);
        $this->assertSame('paid', $cash->fresh()->status);

        // A bank proof still awaiting approval is rejected in the queue, not reversed.
        [$proofOrder, $proof] = $this->paidWebOrder(900, 'bank_transfer', ['requires_approval' => true, 'approval_status' => 'pending']);
        $this->postJson("/api/v1/admin/orders/{$proofOrder->id}/payments/{$proof->id}/reverse", ['reason' => 'Mistake'])
            ->assertStatus(422)->assertJsonFragment(['message' => "{$proof->payment_number}: This payment is still awaiting approval: reject it in the approval queue instead of reversing it."]);
    }

    public function test_reversing_the_whole_order_is_all_or_nothing(): void
    {
        [$order, $bank] = $this->paidWebOrder(1000);
        $mpesa = Payment::create(['order_id' => $order->id, 'payment_method' => 'mpesa', 'amount' => 200, 'currency_code' => 'KES',
            'status' => 'paid', 'paid_at' => now(), 'provider_transaction_id' => 'QAB99', 'created_by' => $this->owner->id]);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/reverse", ['reason' => 'Mistake'])->assertStatus(422);
        $this->assertSame(['paid', 'paid'], [$bank->fresh()->status, $mpesa->fresh()->status], 'nothing half-done');

        $mpesa->delete();
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/reverse", ['reason' => 'Mistake'])
            ->assertOk()->assertJsonPath('payment_status', 'pending');
        $this->assertSame('voided', $bank->fresh()->status);
    }

    // ── everyone else asks; finance executes the full reversal on signing ────

    public function test_an_accountant_raises_a_request_and_finance_signing_it_runs_the_reversal(): void
    {
        [$order, $payment] = $this->paidWebOrder(20000);

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/orders/{$order->id}/payments/{$payment->id}/reverse", ['reason' => 'Recorded on the wrong order'])
            ->assertStatus(202);
        $this->assertSame('paid', $payment->fresh()->status, 'asking reverses nothing');
        $this->assertSame('processing', $order->fresh()->status);

        $r = ApprovalRequest::where('event', 'payment_void')->where('approvable_id', $payment->id)->orderByDesc('version')->firstOrFail();
        $this->assertTrue((bool) $r->payload['reversal']);

        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", ['approvable_id' => $r->approvable_id, 'version' => $r->version])
            ->assertOk()->assertJsonPath('request.status', 'approved');

        $this->assertSame('voided', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('pending', $order->fresh()->status, 'the full reversal ran on signing, not a bare void');
        $this->assertSame($this->fm->id, (int) $payment->fresh()->voided_by);
    }
}
