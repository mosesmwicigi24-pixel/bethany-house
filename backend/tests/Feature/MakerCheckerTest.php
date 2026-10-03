<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
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
 * Role hardening, Phase 1B (owner's plan): "The role that originates a
 * financial or stock event is never the role that approves it. The same
 * person can never do both, even if they hold both authorities. Enforced on
 * user ID." That includes super_admin, whom Gate::before lets past every
 * permission check — so the rule lives in App\Support\MakerChecker, which runs
 * in the controller after the permission gates, not in a gate.
 *
 * And the owner never transacts: super_admin / system_admin get
 * OWNER_DOES_NOT_TRANSACT on the till's write endpoints (route middleware),
 * while every POS screen stays readable to them.
 */
class MakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
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

    private function assertSelfApprovalBlocked($response, User $actor): void
    {
        $response->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertNotEmpty($response->json('message'));
        $this->assertDatabaseHas('activity_log', [
            'event'     => 'self_approval_blocked',
            'causer_id' => $actor->id,
        ]);
    }

    // ── Purchase orders ─────────────────────────────────────────────────────

    private function supplierId(): int
    {
        return DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'Acme Wholesale',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function po(string $status, ?User $createdBy, ?User $approvedBy = null, int $qty = 3): PurchaseOrder
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();

        $po = PurchaseOrder::create([
            'supplier_id'   => $this->supplierId(),
            'outlet_id'     => $outlet->id,
            'order_date'    => now()->toDateString(),
            'status'        => $status,
            'currency_code' => 'KES',
            'subtotal'      => 600,
            'total_amount'  => 600,
            'created_by'    => $createdBy?->id,
            'approved_by'   => $approvedBy?->id,
            'approved_at'   => $approvedBy ? now() : null,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_type'         => 'product',
            'product_id'        => $product->id,
            'description'       => 'Bought-in item',
            'quantity'          => $qty,
            'quantity_received' => 0,
            'unit_price'        => 200,
            'total_price'       => 200 * $qty,
        ]);

        return $po->load('items');
    }

    public function test_the_officer_who_raised_a_po_cannot_approve_it(): void
    {
        $officer = $this->user('procurement_officer', 'procurement_manager');   // holds create AND approve
        $po      = $this->po('pending_approval', $officer);

        Sanctum::actingAs($officer);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve"), $officer);

        $po->refresh();
        $this->assertSame('pending_approval', $po->status);
        $this->assertNull($po->approved_by);
    }

    public function test_whoever_submitted_a_po_cannot_approve_it(): void
    {
        $raiser    = $this->user('procurement_officer', 'procurement_manager');
        $submitter = $this->user('procurement_officer', 'procurement_manager');
        $po        = $this->po('draft', $raiser);

        Sanctum::actingAs($submitter);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/submit")->assertOk();
        $this->assertSame($submitter->id, (int) $po->fresh()->submitted_by, 'submit() must record who submitted');

        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve"), $submitter);
        $this->assertSame('pending_approval', $po->fresh()->status);
    }

    public function test_a_different_officer_can_approve_the_po(): void
    {
        $raiser   = $this->user('procurement_officer');
        $approver = $this->user('procurement_manager');
        $po       = $this->po('pending_approval', $raiser);

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")->assertOk();

        $po->refresh();
        $this->assertSame('approved', $po->status);
        $this->assertSame($approver->id, (int) $po->approved_by);
    }

    public function test_a_super_admin_who_raised_a_po_cannot_approve_it(): void
    {
        $owner = $this->user('super_admin');
        $po    = $this->po('pending_approval', $owner);

        Sanctum::actingAs($owner);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve"), $owner);
        $this->assertSame('pending_approval', $po->fresh()->status);
    }

    public function test_a_super_admin_can_approve_someone_elses_po(): void
    {
        $owner = $this->user('super_admin');
        $po    = $this->po('pending_approval', $this->user('procurement_officer'));

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/approve")->assertOk();
        $this->assertSame('approved', $po->fresh()->status);
    }

    public function test_the_status_endpoint_cannot_approve_your_own_po_nor_jump_past_approval(): void
    {
        $officer = $this->user('procurement_officer', 'procurement_manager');
        $po      = $this->po('pending_approval', $officer);

        Sanctum::actingAs($officer);
        foreach (['approved', 'ordered', 'partially_received', 'received'] as $target) {
            $this->assertSelfApprovalBlocked(
                $this->patchJson("/api/v1/admin/purchase-orders/{$po->id}/status", ['status' => $target]),
                $officer,
            );
        }

        $po->refresh();
        $this->assertSame('pending_approval', $po->status);
        $this->assertNull($po->approved_by);
    }

    public function test_the_status_endpoint_cannot_revive_your_own_cancelled_po_into_an_approved_state(): void
    {
        $officer = $this->user('procurement_officer', 'procurement_manager');
        $po      = $this->po('cancelled', $officer);

        Sanctum::actingAs($officer);
        $this->assertSelfApprovalBlocked(
            $this->patchJson("/api/v1/admin/purchase-orders/{$po->id}/status", ['status' => 'ordered']),
            $officer,
        );
        $this->assertSame('cancelled', $po->fresh()->status);
    }

    public function test_the_status_endpoint_lets_someone_else_approve_and_records_them(): void
    {
        $approver = $this->user('procurement_manager');
        $po       = $this->po('pending_approval', $this->user('procurement_officer'));

        Sanctum::actingAs($approver);
        $this->patchJson("/api/v1/admin/purchase-orders/{$po->id}/status", ['status' => 'ordered'])->assertOk();

        $po->refresh();
        $this->assertSame('ordered', $po->status);
        $this->assertSame($approver->id, (int) $po->approved_by, 'jumping past approval is an approval, and is recorded as one');
    }

    public function test_the_status_endpoint_still_moves_an_already_approved_po_for_its_raiser(): void
    {
        // Approval is done (by someone else); marking it ordered is not a
        // second approval, so the raiser may do it.
        $officer  = $this->user('procurement_officer', 'procurement_manager');
        $approver = $this->user('procurement_manager');
        $po       = $this->po('approved', $officer, $approver);

        Sanctum::actingAs($officer);
        $this->patchJson("/api/v1/admin/purchase-orders/{$po->id}/status", ['status' => 'ordered'])->assertOk();
        $this->assertSame($approver->id, (int) $po->fresh()->approved_by);
    }

    // ── Goods received against a PO ─────────────────────────────────────────

    public function test_whoever_approved_a_po_cannot_receive_the_goods_against_it(): void
    {
        $approver = $this->user('procurement_officer', 'procurement_manager');   // holds approve AND receive
        $po       = $this->po('approved', $this->user('procurement_officer'), $approver);
        $item     = $po->items->first();

        Sanctum::actingAs($approver);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'location_type' => 'outlet',
            'outlet_id'     => $po->outlet_id,
            'items'         => [['po_item_id' => $item->id, 'quantity_received' => 3]],
        ]), $approver);

        $this->assertSame(0, DB::table('goods_received_notes')->where('purchase_order_id', $po->id)->count());
        $this->assertEquals(0, (float) $item->fresh()->quantity_received);
        $this->assertSame('approved', $po->fresh()->status);
    }

    public function test_someone_other_than_the_approver_can_receive_the_goods(): void
    {
        $po       = $this->po('approved', $this->user('procurement_officer'), $this->user('procurement_officer'));
        $receiver = $this->user('procurement_officer');
        $item     = $po->items->first();

        Sanctum::actingAs($receiver);
        $this->postJson("/api/v1/admin/purchase-orders/{$po->id}/receive", [
            'location_type' => 'outlet',
            'outlet_id'     => $po->outlet_id,
            'items'         => [['po_item_id' => $item->id, 'quantity_received' => 3]],
        ])->assertOk();

        $this->assertSame('received', $po->fresh()->status);
    }

    // ── Purchase returns ────────────────────────────────────────────────────

    private function pendingReturn(User $createdBy): PurchaseReturn
    {
        $po = $this->po('received', $createdBy);

        return PurchaseReturn::create([
            'return_number'     => 'PR-' . uniqid(),
            'purchase_order_id' => $po->id,
            'supplier_id'       => $po->supplier_id,
            'return_date'       => now()->toDateString(),
            'reason'            => 'Damaged in transit',
            'status'            => 'pending',
            'created_by'        => $createdBy->id,
        ]);
    }

    public function test_whoever_raised_a_purchase_return_cannot_approve_it(): void
    {
        $officer = $this->user('procurement_officer', 'procurement_manager');
        $return  = $this->pendingReturn($officer);

        Sanctum::actingAs($officer);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/purchase-returns/{$return->id}/approve"), $officer);

        $return->refresh();
        $this->assertSame('pending', $return->status);
        $this->assertNull($return->approved_by);
    }

    public function test_someone_else_can_approve_the_purchase_return(): void
    {
        $return   = $this->pendingReturn($this->user('procurement_officer'));
        $approver = $this->user('super_admin');

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/admin/purchase-returns/{$return->id}/approve")->assertOk();
        $this->assertSame('approved', $return->fresh()->status);
    }

    // ── Stock adjustments ───────────────────────────────────────────────────

    private function raiseAdjustment(User $as, InventoryItem $item, string $reason = 'correction', int $change = 5)
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/admin/inventory/adjustments', [
            'inventory_item_id' => $item->id,
            'quantity_change'   => $change,
            'reason_code'       => $reason,
            'notes'             => 'Counted again',
        ]);
    }

    /**
     * Since Phase 3B an adjustment is valued at |qty| × the product's KES
     * book cost; a product with no cost cannot be valued and needs every
     * band. The tests about who may approve give the product a cost, so the
     * adjustment sits in the procurement manager's band alone.
     */
    private function costedItem(int $onHand = 10, float $cost = 100): InventoryItem
    {
        $item = InventoryItem::factory()->create(['quantity_on_hand' => $onHand]);
        DB::table('product_prices')->insert([
            'product_id' => $item->product_id, 'currency_code' => 'KES', 'regular_price' => $cost * 2,
            'cost_price' => $cost, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $item;
    }

    public function test_whoever_raised_an_adjustment_cannot_approve_it(): void
    {
        $maker = $this->user('outlet_manager', 'procurement_manager');   // holds inventory.adjust AND inventory.approve
        $item  = InventoryItem::factory()->create(['quantity_on_hand' => 10]);
        $id    = $this->raiseAdjustment($maker, $item)->assertCreated()->json('adjustment.id');

        $this->assertSelfApprovalBlocked($this->putJson("/api/v1/admin/inventory/adjustments/{$id}/approve"), $maker);

        $this->assertSame('pending_approval', InventoryTransaction::find($id)->status);
        $this->assertSame(10, (int) $item->fresh()->quantity_on_hand);
    }

    public function test_someone_else_can_approve_the_adjustment(): void
    {
        $item = $this->costedItem();
        // Phase 4A: the manager adjusts stock at their own shop.
        $maker = $this->user('outlet_manager');
        $maker->outlets()->attach($item->outlet_id);
        $id   = $this->raiseAdjustment($maker, $item)->assertCreated()->json('adjustment.id');

        Sanctum::actingAs($this->user('procurement_manager'));
        $this->putJson("/api/v1/admin/inventory/adjustments/{$id}/approve")->assertOk();

        $this->assertSame('approved', InventoryTransaction::find($id)->status);
        $this->assertSame(15, (int) $item->fresh()->quantity_on_hand);
    }

    public function test_an_admins_adjustment_that_needs_approval_now_waits_like_anyone_elses(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            // Stacked with roles that raise and approve stock adjustments, so the only
            // thing stopping self-approval is the maker≠checker rule itself.
            $boss = $this->user($role, 'outlet_manager', 'procurement_manager');
            $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);

            $res = $this->raiseAdjustment($boss, $item, 'correction', 5)->assertCreated();
            $res->assertJsonPath('requires_approval', true);
            $res->assertJsonPath('auto_approved', false);

            $id = $res->json('adjustment.id');
            $this->assertSame('pending_approval', InventoryTransaction::find($id)->status, "{$role}'s correction must wait");
            $this->assertNull(InventoryTransaction::find($id)->approved_by);
            $this->assertSame(10, (int) $item->fresh()->quantity_on_hand, "{$role}'s correction must not move stock yet");

            // …and the same person cannot then approve it themselves.
            $this->assertSelfApprovalBlocked($this->putJson("/api/v1/admin/inventory/adjustments/{$id}/approve"), $boss);
            $this->assertSame(10, (int) $item->fresh()->quantity_on_hand);
        }
    }

    public function test_reasons_that_never_needed_approval_still_apply_directly_for_admins(): void
    {
        // 'damaged' still applies at once — within the procurement band's
        // value (Phase 3B): 2 × KES 100 is well under KES 10,000.
        $admin = $this->user('admin', 'outlet_manager');
        $item  = $this->costedItem();

        $this->raiseAdjustment($admin, $item, 'damaged', -2)
            ->assertCreated()
            ->assertJsonPath('requires_approval', false);

        $this->assertSame(8, (int) $item->fresh()->quantity_on_hand);
    }

    // ── Stock transfers ─────────────────────────────────────────────────────

    private function pendingTransfer(User $createdBy): InventoryTransfer
    {
        return InventoryTransfer::create([
            'from_outlet_id' => Outlet::factory()->create()->id,
            'to_outlet_id'   => Outlet::factory()->create()->id,
            'status'         => 'pending',
            'transfer_date'  => now()->toDateString(),
            'created_by'     => $createdBy->id,
        ]);
    }

    public function test_whoever_raised_a_transfer_cannot_approve_it(): void
    {
        $maker    = $this->user('outlet_manager', 'procurement_manager');
        $transfer = $this->pendingTransfer($maker);

        Sanctum::actingAs($maker);
        $this->assertSelfApprovalBlocked($this->putJson("/api/v1/admin/inventory/transfers/{$transfer->id}/approve"), $maker);
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_someone_else_can_approve_the_transfer(): void
    {
        $transfer = $this->pendingTransfer($this->user('outlet_manager'));

        Sanctum::actingAs($this->user('procurement_manager'));
        $this->putJson("/api/v1/admin/inventory/transfers/{$transfer->id}/approve")->assertOk();
        $this->assertSame('approved', $transfer->fresh()->status);
    }

    // ── Expenses (every expense, not only imprest) ──────────────────────────

    private function pendingExpense(User $as): Expense
    {
        $category = ExpenseCategory::firstOrCreate(
            ['code' => 'SALES_OFFICE'],
            ['name' => 'Sales Office Expense', 'requires_approval_above' => 3000, 'is_active' => true],
        );

        Sanctum::actingAs($as);
        $id = $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Printer toner', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'amount' => 5000, 'currency_code' => 'KES', 'payment_method' => 'cash', 'vendor_name' => 'Milka',
        ])->assertCreated()->json('expense.id');

        $expense = Expense::findOrFail($id);
        $this->assertSame('pending_approval', $expense->status);
        $this->assertNull($expense->imprest_account_id, 'this is an ordinary expense, not imprest');

        return $expense;
    }

    public function test_whoever_recorded_an_ordinary_expense_cannot_approve_it(): void
    {
        $finance = $this->user('finance_manager', 'accountant');   // holds expenses.create AND expenses.approve
        $expense = $this->pendingExpense($finance);

        Sanctum::actingAs($finance);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/expenses/{$expense->id}/approve"), $finance);

        $expense->refresh();
        $this->assertSame('pending_approval', $expense->status);
        $this->assertNull($expense->approved_by);
    }

    public function test_whoever_submitted_an_expense_cannot_approve_it(): void
    {
        $expense   = $this->pendingExpense($this->user('accountant'));
        $submitter = $this->user('finance_manager', 'accountant');
        DB::table('expenses')->where('id', $expense->id)->update(['submitted_by' => $submitter->id]);

        Sanctum::actingAs($submitter);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/expenses/{$expense->id}/approve"), $submitter);
        $this->assertSame('pending_approval', $expense->fresh()->status);
    }

    public function test_someone_else_can_approve_the_expense(): void
    {
        $expense  = $this->pendingExpense($this->user('accountant'));
        $approver = $this->user('finance_manager');

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/admin/expenses/{$expense->id}/approve")->assertOk();
        $this->assertSame('approved', $expense->fresh()->status);
        $this->assertSame($approver->id, (int) $expense->fresh()->approved_by);
    }

    public function test_a_super_admin_who_recorded_an_expense_cannot_approve_it(): void
    {
        $owner   = $this->user('super_admin');
        $expense = $this->pendingExpense($owner);

        Sanctum::actingAs($owner);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/expenses/{$expense->id}/approve"), $owner);
        $this->assertSame('pending_approval', $expense->fresh()->status);
    }

    // ── Payments awaiting approval ──────────────────────────────────────────

    /**
     * A payment recorded by $recorder, the way every recording path makes one:
     * Payment::create while that person is signed in, so the audit trail's
     * 'created' row names them. $recorder null = nobody signed in (a payment
     * older than the audit trail looks the same: no recorder on file).
     */
    private function pendingPayment(?User $recorder): Payment
    {
        if ($recorder) {
            Sanctum::actingAs($recorder);
        }
        $order = Order::factory()->create(['status' => 'processing', 'payment_status' => 'pending_approval', 'total_amount' => 1000]);

        return Payment::create([
            'order_id'          => $order->id,
            'payment_method'    => 'bank_transfer',
            'amount'            => 1000,
            'currency_code'     => 'KES',
            'status'            => 'pending',
            'requires_approval' => true,
            'approval_status'   => 'pending_review',
        ]);
    }

    public function test_whoever_recorded_a_payment_cannot_approve_it(): void
    {
        $finance = $this->user('finance_manager');   // payments.approve_international
        $payment = $this->pendingPayment($finance);

        Sanctum::actingAs($finance);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/payments/{$payment->id}/approve", ["notes" => "Seen on the bank statement"]), $finance);

        $payment->refresh();
        $this->assertSame('pending_review', $payment->approval_status);
        $this->assertNull($payment->approved_by);
    }

    public function test_a_super_admin_who_recorded_a_payment_cannot_approve_it(): void
    {
        $owner   = $this->user('super_admin');
        $payment = $this->pendingPayment($owner);

        Sanctum::actingAs($owner);
        $this->assertSelfApprovalBlocked($this->postJson("/api/v1/admin/payments/{$payment->id}/approve", ["notes" => "Seen on the bank statement"]), $owner);
        $this->assertSame('pending_review', $payment->fresh()->approval_status);
    }

    public function test_someone_else_can_approve_the_payment(): void
    {
        $payment  = $this->pendingPayment($this->user('pos_clerk'));
        $approver = $this->user('finance_manager');

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", ["notes" => "Seen on the bank statement"])->assertOk();
        $this->assertSame('approved', $payment->fresh()->approval_status);
        $this->assertSame($approver->id, (int) $payment->fresh()->approved_by);
    }

    public function test_a_payment_with_no_recorder_on_file_stays_approvable(): void
    {
        // Documented limit: payments carry no recorded_by column, so the check
        // reads the audit trail and fails OPEN when it has no creator row.
        $payment  = $this->pendingPayment(null);
        $approver = $this->user('finance_manager');

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", ["notes" => "Seen on the bank statement"])->assertOk();
        $this->assertSame('approved', $payment->fresh()->approval_status);
    }

    // ── The owner never transacts at the till ───────────────────────────────

    /** Every POS endpoint that moves a sale, a payment, stock or the drawer. */
    private const TRANSACTING = [
        ['POST',  '/api/v1/admin/pos/sales'],
        ['POST',  '/api/v1/admin/pos/pending-order'],
        ['PATCH', '/api/v1/admin/pos/pending-order/1'],
        ['POST',  '/api/v1/admin/pos/pending-order/1/pay'],
        ['POST',  '/api/v1/admin/pos/register/open'],
        ['POST',  '/api/v1/admin/pos/register/close'],
        ['POST',  '/api/v1/admin/pos/sales/1/void'],
        ['POST',  '/api/v1/admin/pos/returns'],
        ['POST',  '/api/v1/pos/sales'],
        ['POST',  '/api/v1/pos/sales/1/return'],
        ['POST',  '/api/v1/pos/cash-register/open'],
        ['POST',  '/api/v1/pos/cash-register/close'],
        ['POST',  '/api/v1/pos/cash-register/deposit'],
        ['POST',  '/api/v1/pos/cash-register/withdrawal'],
        ['POST',  '/api/v1/pos/cash-register/adjustment'],
    ];

    public function test_a_super_admin_cannot_transact_on_any_pos_write_endpoint(): void
    {
        Sanctum::actingAs($this->user('super_admin'));

        foreach (self::TRANSACTING as [$method, $uri]) {
            $res = $this->json($method, $uri, []);
            $this->assertSame(403, $res->status(), "{$method} {$uri} must refuse the owner (got {$res->status()})");
            $this->assertSame('OWNER_DOES_NOT_TRANSACT', $res->json('code'), "{$method} {$uri}");
            $this->assertNotEmpty($res->json('message'));
        }
    }

    public function test_holding_a_till_role_as_well_does_not_lift_the_owner_deny(): void
    {
        foreach (['super_admin', 'system_admin'] as $ownerRole) {
            Sanctum::actingAs($this->user($ownerRole, 'pos_clerk'));

            $this->postJson('/api/v1/admin/pos/sales', [])
                ->assertForbidden()
                ->assertJsonPath('code', 'OWNER_DOES_NOT_TRANSACT');
            $this->postJson('/api/v1/admin/pos/register/open', [])
                ->assertForbidden()
                ->assertJsonPath('code', 'OWNER_DOES_NOT_TRANSACT');
        }
    }

    public function test_the_owner_can_still_view_pos_screens(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $outlet = Outlet::factory()->create();

        $this->getJson("/api/v1/admin/pos/sales?outlet_id={$outlet->id}")->assertOk();
        $this->getJson("/api/v1/admin/pos/register/status?outlet_id={$outlet->id}")->assertOk();
        $this->getJson('/api/v1/admin/pos/outlets')->assertOk();
    }

    public function test_a_cashier_is_not_caught_by_the_owner_deny(): void
    {
        Sanctum::actingAs($this->user('pos_clerk'));

        // An empty sale fails validation — the point is that it reaches the
        // controller at all rather than being refused as an owner.
        $res = $this->postJson('/api/v1/admin/pos/sales', []);
        $this->assertNotSame('OWNER_DOES_NOT_TRANSACT', $res->json('code'));
        $res->assertStatus(422);
    }
}
