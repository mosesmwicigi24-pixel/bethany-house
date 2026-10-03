<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ImprestAccount;
use App\Models\ImprestTopupRequest;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
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
 * Phase 3B — every flow wired into the approval engine, end to end through the
 * API: stock adjustments (valued at product cost), stock transfers, expenses,
 * imprest top-ups, and the new request step for payment void / reassign.
 */
class ApprovalFlowsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $pm;
    private User $fm;
    private User $accountant;
    private User $outletManager;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->owner         = $this->user('super_admin');
        $this->pm            = $this->user('procurement_manager');
        $this->fm            = $this->user('finance_manager');
        $this->accountant    = $this->user('accountant');
        $this->outletManager = $this->user('outlet_manager');
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->create(['status' => 'active']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function latest(string $event, int $id): ApprovalRequest
    {
        return ApprovalRequest::where('event', $event)->where('approvable_id', $id)->orderByDesc('version')->firstOrFail();
    }

    private function sign(User $as, ApprovalRequest $r)
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", ['approvable_id' => $r->approvable_id, 'version' => $r->version]);
    }

    private function inboxIds(User $as): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson('/api/v1/admin/approvals/inbox')->assertOk()->json('data'))->pluck('id')->all();
    }

    // ── stock adjustments ───────────────────────────────────────────────────

    /** An inventory item whose product costs $cost KES on the price book (null = no cost). */
    private function item(?float $cost, int $onHand = 1000): InventoryItem
    {
        $product = Product::factory()->create();
        if ($cost !== null) {
            DB::table('product_prices')->insert([
                'product_id' => $product->id, 'currency_code' => 'KES', 'regular_price' => $cost * 2,
                'cost_price' => $cost, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return InventoryItem::factory()->create(['product_id' => $product->id, 'quantity_on_hand' => $onHand]);
    }

    private function adjust(User $as, InventoryItem $item, string $reason, int $change)
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/admin/inventory/adjustments', [
            'inventory_item_id' => $item->id, 'quantity_change' => $change, 'reason_code' => $reason, 'notes' => 'Counted',
        ]);
    }

    public function test_an_adjustment_is_valued_at_product_cost_and_banded(): void
    {
        $item = $this->item(100);   // KES 100 a unit

        // 5 × 100 = 500: the procurement manager alone.
        $id = $this->adjust($this->outletManager, $item, 'correction', 5)->assertCreated()
            ->assertJsonPath('requires_approval', true)->json('adjustment.id');
        $r = $this->latest('stock_adjustment', $id);
        $this->assertEquals(500, (float) $r->amount_kes);
        $this->assertSame(['inventory.approve'], array_column($r->bands, 'permission'));
        $this->sign($this->pm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(1005, (int) $item->fresh()->quantity_on_hand);

        // 200 × 100 = 20,000: PM then finance; stock moves only on the last band.
        $id = $this->adjust($this->outletManager, $item, 'stolen', -200)->assertCreated()->json('adjustment.id');
        $r = $this->latest('stock_adjustment', $id);
        $this->assertSame(['inventory.approve', 'approvals.finance_sign'], array_column($r->bands, 'permission'));
        $this->sign($this->pm, $r)->assertOk();
        $this->assertSame(1005, (int) $item->fresh()->quantity_on_hand);
        $this->assertSame('pending_approval', InventoryTransaction::find($id)->status);
        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(805, (int) $item->fresh()->quantity_on_hand);
        $this->assertSame($this->fm->id, (int) InventoryTransaction::find($id)->approved_by);
    }

    public function test_reasons_that_skipped_approval_skip_only_under_the_procurement_band(): void
    {
        $item = $this->item(100);

        // damaged −20 = 2,000: under the PM band, applied at once as before.
        $this->adjust($this->outletManager, $item, 'damaged', -20)->assertCreated()->assertJsonPath('requires_approval', false);
        $this->assertSame(980, (int) $item->fresh()->quantity_on_hand);
        $this->assertSame(0, ApprovalRequest::count());

        // damaged −150 = 15,000: above it — waits, with PM then finance.
        $id = $this->adjust($this->outletManager, $item, 'damaged', -150)->assertCreated()
            ->assertJsonPath('requires_approval', true)->json('adjustment.id');
        $this->assertSame(980, (int) $item->fresh()->quantity_on_hand);
        $this->assertSame(['inventory.approve', 'approvals.finance_sign'], array_column($this->latest('stock_adjustment', $id)->bands, 'permission'));
    }

    public function test_skipping_adjustments_cannot_be_split_under_the_band(): void
    {
        $item = $this->item(100);
        $this->adjust($this->outletManager, $item, 'damaged', -40)->assertJsonPath('requires_approval', false);   // 4,000
        $this->adjust($this->outletManager, $item, 'damaged', -40)->assertJsonPath('requires_approval', false);   // 8,000
        $id = $this->adjust($this->outletManager, $item, 'damaged', -40)->assertJsonPath('requires_approval', true)->json('adjustment.id');   // 12,000

        $r = $this->latest('stock_adjustment', $id);
        $this->assertEquals(4000, (float) $r->amount_kes);
        $this->assertEquals(12000, (float) $r->basis_kes);
        $this->assertSame(['inventory.approve', 'approvals.finance_sign'], array_column($r->bands, 'permission'));
        $this->assertSame(920, (int) $item->fresh()->quantity_on_hand);
    }

    public function test_an_uncosted_product_cannot_be_valued_so_it_needs_every_band(): void
    {
        $item = $this->item(null);
        $id = $this->adjust($this->outletManager, $item, 'damaged', -1)->assertCreated()
            ->assertJsonPath('requires_approval', true)->json('adjustment.id');
        $r = $this->latest('stock_adjustment', $id);
        $this->assertTrue($r->value_unknown);
        $this->assertSame(['inventory.approve', 'approvals.finance_sign', 'approvals.super_sign'], array_column($r->bands, 'permission'));
    }

    public function test_the_adjustment_approve_endpoint_is_a_signature_and_the_maker_cannot_use_it(): void
    {
        $maker = $this->user('outlet_manager', 'procurement_manager');
        $item  = $this->item(100);
        $id    = $this->adjust($maker, $item, 'correction', 5)->json('adjustment.id');

        Sanctum::actingAs($maker);
        $this->putJson("/api/v1/admin/inventory/adjustments/{$id}/approve")->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');

        Sanctum::actingAs($this->pm);
        $this->putJson("/api/v1/admin/inventory/adjustments/{$id}/reject", ['reason' => 'Recount first'])->assertOk();
        $this->assertSame('rejected', InventoryTransaction::find($id)->status);
        $this->assertSame('rejected', $this->latest('stock_adjustment', $id)->status);

        // Back as version 2 through the inbox's resubmit.
        Sanctum::actingAs($maker);
        $this->postJson('/api/v1/admin/approvals/' . $this->latest('stock_adjustment', $id)->id . '/resubmit')->assertCreated();
        $this->assertSame('pending_approval', InventoryTransaction::find($id)->status);
        $this->assertSame(2, $this->latest('stock_adjustment', $id)->version);
    }

    // ── stock transfers ─────────────────────────────────────────────────────

    public function test_a_transfer_waits_for_the_procurement_manager(): void
    {
        $from = Outlet::factory()->create();
        $to   = Outlet::factory()->create();
        $product = Product::factory()->create();

        Sanctum::actingAs($this->outletManager);
        $id = $this->postJson('/api/v1/admin/inventory/transfers', [
            'from_outlet_id' => $from->id, 'to_outlet_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity_requested' => 3]],
        ])->assertCreated()->json('transfer.id');

        $r = $this->latest('stock_transfer', $id);
        $this->assertSame(['inventory.approve'], array_column($r->bands, 'permission'));
        $this->assertNull($r->amount_kes);
        $this->assertNotContains($r->id, $this->inboxIds($this->outletManager));
        $this->assertNotContains($r->id, $this->inboxIds($this->fm));
        $this->assertContains($r->id, $this->inboxIds($this->pm));

        $this->sign($this->pm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame('approved', InventoryTransfer::find($id)->status);
    }

    public function test_cancelling_a_transfer_withdraws_its_request(): void
    {
        $transfer = InventoryTransfer::create([
            'from_outlet_id' => Outlet::factory()->create()->id, 'to_outlet_id' => Outlet::factory()->create()->id,
            'status' => 'pending', 'transfer_date' => now()->toDateString(), 'created_by' => $this->outletManager->id,
        ]);
        Artisan::call('approvals:adopt-pending');
        $r = $this->latest('stock_transfer', $transfer->id);

        Sanctum::actingAs($this->outletManager);
        $this->putJson("/api/v1/admin/inventory/transfers/{$transfer->id}/cancel", ['reason' => 'Not needed'])->assertOk();
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertNotContains($r->id, $this->inboxIds($this->pm));
    }

    // ── expenses ────────────────────────────────────────────────────────────

    private function expense(User $as, float $amount): Expense
    {
        $category = ExpenseCategory::firstOrCreate(['code' => 'SALES_OFFICE'],
            ['name' => 'Sales Office Expense', 'requires_approval_above' => 3000, 'is_active' => true]);
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Printer', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'amount' => $amount, 'currency_code' => 'KES', 'payment_method' => 'cash', 'vendor_name' => 'Milka',
        ])->assertCreated()->json('expense.id');

        return Expense::findOrFail($id);
    }

    public function test_an_expense_above_50k_needs_finance_then_the_super_admin(): void
    {
        $small = $this->expense($this->accountant, 5000);
        $this->assertSame(['expenses.approve'], array_column($this->latest('expense', $small->id)->bands, 'permission'));

        $big = $this->expense($this->accountant, 60000);
        $this->assertSame(['expenses.approve', 'approvals.super_sign'], array_column($this->latest('expense', $big->id)->bands, 'permission'));

        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/expenses/{$big->id}/approve", ['comments' => 'Quote attached'])->assertOk()
            ->assertJsonPath('approval.status', 'pending');
        $this->assertSame('pending_approval', $big->fresh()->status);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/{$big->id}/approve")->assertOk();
        $this->assertSame('approved', $big->fresh()->status);
        $this->assertSame($this->owner->id, (int) $big->fresh()->approved_by);
    }

    public function test_an_expense_below_its_category_threshold_needs_no_approval_until_submitted(): void
    {
        $draft = $this->expense($this->accountant, 400);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(0, ApprovalRequest::count());

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/expenses/{$draft->id}/submit")->assertOk();
        $this->assertSame(['expenses.approve'], array_column($this->latest('expense', $draft->id)->bands, 'permission'));
    }

    public function test_a_rejected_expense_is_resubmitted_as_a_new_version(): void
    {
        $e = $this->expense($this->accountant, 5000);
        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/expenses/{$e->id}/reject", ['reason' => 'No receipt'])->assertOk();
        $this->assertSame('rejected', $e->fresh()->status);

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/expenses/{$e->id}/submit")->assertOk();
        $v2 = $this->latest('expense', $e->id);
        $this->assertSame(2, $v2->version);
        $this->assertNotNull($v2->supersedes_id);
    }

    // ── imprest top-ups ─────────────────────────────────────────────────────

    private function imprest(User $custodian): ImprestAccount
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/admin/expenses/imprest', [
            'name' => 'Petty cash', 'custodian_id' => $custodian->id, 'float_amount' => 500000, 'opening_balance' => 1000,
        ])->assertCreated()->json('account.id');

        return ImprestAccount::findOrFail($id);
    }

    public function test_a_topup_is_approved_by_finance_before_it_is_sent(): void
    {
        $a = $this->imprest($this->accountant);
        Sanctum::actingAs($this->accountant);
        $uuid = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups", ['amount' => 8500])->assertCreated()->json('request.uuid');
        $topup = ImprestTopupRequest::where('uuid', $uuid)->firstOrFail();

        $r = $this->latest('imprest_topup', $topup->id);
        $this->assertSame(['expenses.approve'], array_column($r->bands, 'permission'));
        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(ImprestTopupRequest::PENDING, $topup->fresh()->status, 'approved to go, not yet sent');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid}/send", ['amount' => 8500, 'method' => 'mpesa'])->assertOk();
        $this->assertSame(ImprestTopupRequest::SENT, $topup->fresh()->status);
    }

    public function test_a_large_topup_cannot_be_sent_before_finance_signs(): void
    {
        $a = $this->imprest($this->accountant);
        Sanctum::actingAs($this->accountant);
        $uuid = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups", ['amount' => 150000])->assertCreated()->json('request.uuid');
        $topup = ImprestTopupRequest::where('uuid', $uuid)->firstOrFail();
        $r = $this->latest('imprest_topup', $topup->id);
        $this->assertSame(['expenses.approve', 'approvals.super_sign'], array_column($r->bands, 'permission'));

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid}/send", ['amount' => 150000, 'method' => 'bank_transfer'])
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'Finance must approve this top-up before it is sent.');
        $this->assertSame(ImprestTopupRequest::PENDING, $topup->fresh()->status);
        $this->assertSame(0, DB::table('approval_signatures')->where('approval_request_id', $r->id)->count(), 'the owner did not spend his signature on the finance band');

        $this->sign($this->fm, $r)->assertOk();
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid}/send", ['amount' => 150000, 'method' => 'bank_transfer'])->assertOk();
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(ImprestTopupRequest::SENT, $topup->fresh()->status);
    }

    // ── payment void / reassign: a request step ─────────────────────────────

    private function paidPayment(float $amount): Payment
    {
        $order = Order::factory()->create(['total_amount' => $amount, 'status' => 'processing', 'payment_status' => 'paid']);

        return Payment::create([
            'order_id' => $order->id, 'payment_method' => 'cash', 'amount' => $amount,
            'currency_code' => 'KES', 'status' => 'paid',
        ]);
    }

    public function test_the_accountant_requests_a_void_and_finance_executes_it_on_signing(): void
    {
        $payment = $this->paidPayment(20000);

        // The accountant cannot void directly…
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void", ['reason' => 'Wrong order'])->assertForbidden();
        // …but can ask.
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void-request", ['reason' => 'Wrong order'])
            ->assertStatus(202)->assertJsonPath('approval.status', 'pending');
        $this->assertSame('paid', $payment->fresh()->status, 'asking voids nothing');
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void-request", ['reason' => 'Again'])->assertStatus(409);

        $r = $this->latest('payment_void', $payment->id);
        $this->assertSame(['payments.void'], array_column($r->bands, 'permission'));
        $this->assertContains($r->id, $this->inboxIds($this->fm));
        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');

        $payment->refresh();
        $this->assertSame('voided', $payment->status);
        $this->assertSame('Wrong order', $payment->void_reason);
        $this->assertSame($this->fm->id, (int) $payment->voided_by);
    }

    public function test_a_void_above_50k_needs_the_super_admin_too(): void
    {
        $payment = $this->paidPayment(60000);
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void-request", ['reason' => 'Duplicate'])->assertStatus(202);
        $r = $this->latest('payment_void', $payment->id);
        $this->assertSame(['payments.void', 'approvals.super_sign'], array_column($r->bands, 'permission'));

        $this->sign($this->fm, $r)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->sign($this->owner, $r)->assertOk();
        $this->assertSame('voided', $payment->fresh()->status);
    }

    public function test_finance_asking_through_the_old_void_endpoint_needs_someone_else_to_sign(): void
    {
        $payment = $this->paidPayment(1000);
        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void", ['reason' => 'Wrong order'])->assertStatus(202);
        $this->assertSame('paid', $payment->fresh()->status);

        $r = $this->latest('payment_void', $payment->id);
        $this->sign($this->fm, $r)->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->sign($this->user('finance_manager'), $r)->assertOk();
        $this->assertSame('voided', $payment->fresh()->status);
    }

    public function test_the_accountant_requests_a_reassign_and_finance_executes_it(): void
    {
        $payment = $this->paidPayment(5000);
        $target  = Order::factory()->create(['total_amount' => 5000, 'status' => 'processing']);

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/reassign-request", ['order_id' => $target->id, 'reason' => 'Paid for the other order'])
            ->assertStatus(202);
        $r = $this->latest('payment_reassign', $payment->id);

        $this->sign($this->fm, $r)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame($target->id, (int) $payment->fresh()->order_id);
    }

    public function test_a_void_request_on_a_payment_changed_since_cannot_be_signed(): void
    {
        $payment = $this->paidPayment(1000);
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void-request", ['reason' => 'Wrong order'])->assertStatus(202);
        DB::table('payments')->where('id', $payment->id)->update(['amount' => 900]);

        $this->sign($this->fm, $this->latest('payment_void', $payment->id))->assertStatus(422)->assertJsonPath('code', 'APPROVAL_STALE');
        $this->assertSame('paid', $payment->fresh()->status);
    }
}
