<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PosRefundRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Auth\TerminalPin;
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
 * Phase 4B part 2 — till voids and refunds go through the approval engine,
 * approved with the approver's PIN on the clerk's terminal (or from the
 * Approvals inbox).
 *
 *   void    outlet manager ≤ 20,000 · finance ≤ 100,000 · super admin above
 *   refund  outlet manager ≤  5,000 · finance ≤  50,000 · super admin above
 */
class PosTillApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private const PIN_OM    = '4821';
    private const PIN_OM2   = '5930';
    private const PIN_FM    = '6142';
    private const PIN_CLERK = '7395';

    private Outlet $outlet;
    private User $clerk;
    private User $om;
    private User $fm;
    private User $owner;
    private CashRegister $till;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->outlet = Outlet::factory()->create();
        $this->clerk  = $this->user(['pos_clerk'], $this->outlet);
        $this->om     = $this->user(['outlet_manager'], $this->outlet);
        $this->fm     = $this->user(['finance_manager']);
        $this->owner  = $this->user(['super_admin']);

        $pins = app(TerminalPin::class);
        $pins->set($this->om, self::PIN_OM);
        $pins->set($this->fm, self::PIN_FM);
        $pins->set($this->clerk, self::PIN_CLERK);

        $this->till = $this->openTill($this->clerk, 50000);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function user(array $roles, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        if ($outlet) {
            $u->outlets()->attach($outlet->id);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function openTill(User $by, float $cash): CashRegister
    {
        return CashRegister::create([
            'register_number' => "REG-{$this->outlet->id}-{$by->id}-" . uniqid(),
            'outlet_id' => $this->outlet->id, 'register_name' => 'Till', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => $cash, 'expected_cash' => $cash,
            'total_sales' => 0, 'total_cash_sales' => 0, 'total_card_sales' => 0, 'total_mpesa_sales' => 0,
            'total_refunds' => 0, 'transaction_count' => 0,
            'opened_by' => $by->id, 'opened_at' => now()->subHour(),
        ]);
    }

    /** A paid cash sale of $qty × $unit on the clerk's till, with one line and its stock row. */
    private function sale(float $unit, int $qty = 1): Order
    {
        $total   = $unit * $qty;
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $stock   = InventoryItem::factory()->create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 10,
        ]);

        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'outlet_id' => $this->outlet->id,
            'currency_code' => 'KES', 'subtotal' => $total, 'total_amount' => $total,
            'payment_method' => 'cash', 'payment_status' => 'paid', 'created_by' => $this->clerk->id,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_name' => 'Cassock', 'sku' => 'SKU-' . $variant->id, 'quantity' => $qty, 'unit_price' => $unit, 'total_price' => $total,
            'inventory_item_id' => $stock->id,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id, 'amount' => $total, 'status' => 'paid',
            'payment_method' => 'cash', 'currency_code' => 'KES',
        ]);
        DB::table('cash_register_transactions')->insert([
            'cash_register_id' => $this->till->id, 'transaction_type' => 'sale', 'payment_method' => 'cash',
            'amount' => $total, 'balance_after' => (float) $this->till->expected_cash + $total,
            'order_id' => $order->id, 'created_by' => $this->clerk->id, 'created_at' => now(),
        ]);
        DB::table('cash_registers')->where('id', $this->till->id)->update([
            'expected_cash' => DB::raw("expected_cash + {$total}"),
            'total_sales' => DB::raw("total_sales + {$total}"),
            'total_cash_sales' => DB::raw("total_cash_sales + {$total}"),
            'transaction_count' => DB::raw('transaction_count + 1'),
        ]);

        return $order->fresh(['items']);
    }

    private function as(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    private function requestVoid(Order $order, ?User $by = null)
    {
        $this->as($by ?? $this->clerk);

        return $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'Rung twice']);
    }

    private function requestRefund(Order $order, int $qty = 1, string $method = 'cash', ?User $by = null)
    {
        $this->as($by ?? $this->clerk);

        return $this->postJson('/api/v1/admin/pos/returns', [
            'original_order_id' => $order->id,
            'items'             => [['variant_id' => $order->items->first()->product_variant_id, 'quantity' => $qty]],
            'reason'            => 'Wrong size',
            'refund_method'     => $method,
        ]);
    }

    /** The approver steps up to the clerk's terminal and enters their PIN. */
    private function pinSign(int $approvalId, User $approver, string $pin, array $extra = [], ?User $terminal = null)
    {
        $this->as($terminal ?? $this->clerk);

        return $this->postJson("/api/v1/admin/pos/approvals/{$approvalId}/pin-sign", array_merge([
            'approver_id' => $approver->id, 'pin' => $pin,
        ], $extra));
    }

    private function inboxSign(User $as, ApprovalRequest $r)
    {
        $this->as($as);

        return $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", ['approvable_id' => $r->approvable_id, 'version' => $r->version]);
    }

    private function voidRequest(Order $order): ApprovalRequest
    {
        return ApprovalRequest::where('event', 'pos_void')->where('approvable_id', $order->id)->orderByDesc('version')->firstOrFail();
    }

    // ── void: a request, never a direct void ─────────────────────────────────

    public function test_a_clerk_cannot_void_directly_the_void_endpoint_only_asks(): void
    {
        $order = $this->sale(1500);

        $res = $this->requestVoid($order)->assertStatus(202)
            ->assertJsonPath('approval.event', 'pos_void')
            ->assertJsonPath('approval.status', 'pending');

        $this->assertSame('confirmed', $order->fresh()->status, 'nothing happens to the sale on asking');
        $this->assertSame('paid', Payment::where('order_id', $order->id)->value('status'));
        $this->assertEquals(51500, $this->till->fresh()->expected_cash, 'the drawer is untouched');
        $this->assertSame((int) $this->clerk->id, (int) $this->voidRequest($order)->maker_id);
        $this->assertCount(1, $res->json('pending'));

        // A clerk holds no band key: they cannot sign anyone's void, on the inbox or the till.
        $other = $this->user(['pos_clerk'], $this->outlet);
        $this->inboxSign($other, $this->voidRequest($order))->assertStatus(403);
        app(TerminalPin::class)->set($other, '8204');
        $this->pinSign($this->voidRequest($order)->id, $other, '8204')->assertStatus(403);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_the_outlet_manager_approves_a_small_void_with_their_pin_on_the_clerks_till(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $this->pinSign($id, $this->om, self::PIN_OM)->assertOk()
            ->assertJsonPath('request.status', 'approved');

        $this->assertSame('voided', $order->fresh()->status);
        $this->assertSame('voided', Payment::where('order_id', $order->id)->value('status'));
        $this->assertEquals(50000, $this->till->fresh()->expected_cash, 'the drawer is reversed by the cash the sale took');
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $this->till->id, 'transaction_type' => 'void', 'order_id' => $order->id, 'amount' => 1500,
        ]);
    }

    public function test_void_bands_are_cumulative_a_60000_void_needs_the_outlet_manager_then_finance(): void
    {
        $order = $this->sale(60000);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $r = ApprovalRequest::findOrFail($id);
        $this->assertSame(['pos.approve_reversal', 'approvals.finance_sign'], array_column($r->bands, 'permission'));

        // Finance cannot jump the queue: the outlet manager's band is first.
        $this->inboxSign($this->fm, $r)->assertStatus(403);

        $this->pinSign($id, $this->om, self::PIN_OM)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->assertSame('confirmed', $order->fresh()->status, 'one band of two: not voided yet');

        // The same outlet manager cannot sign the second band too.
        $this->pinSign($id, $this->om, self::PIN_OM)->assertStatus(403);

        $this->pinSign($id, $this->fm, self::PIN_FM)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame('voided', $order->fresh()->status);
    }

    public function test_a_void_above_finance_needs_the_super_admin_who_approves_but_never_rings(): void
    {
        $order = $this->sale(150000);

        // The owner cannot ask for a void (owner.no_transact)…
        $this->requestVoid($order, $this->owner)->assertStatus(403);

        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');
        $this->assertSame(['pos.approve_reversal', 'approvals.finance_sign', 'approvals.super_sign'],
            array_column(ApprovalRequest::find($id)->bands, 'permission'));

        $this->pinSign($id, $this->om, self::PIN_OM)->assertOk();
        $this->inboxSign($this->fm, ApprovalRequest::find($id))->assertOk();
        $this->assertSame('confirmed', $order->fresh()->status);

        // …but signs the top band.
        $this->inboxSign($this->owner, ApprovalRequest::find($id))->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame('voided', $order->fresh()->status);
    }

    public function test_the_requesters_own_pin_is_refused(): void
    {
        // The outlet manager rings the sale's void request themselves…
        $order = $this->sale(1500);
        $id = $this->requestVoid($order, $this->om)->assertStatus(202)->json('approval.id');

        // …and enters their own (correct) PIN: maker ≠ checker.
        $this->pinSign($id, $this->om, self::PIN_OM, [], $this->om)
            ->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'self_approval_blocked', 'causer_id' => $this->om->id]);

        // A second outlet manager at the outlet signs it.
        $om2 = $this->user(['outlet_manager'], $this->outlet);
        app(TerminalPin::class)->set($om2, self::PIN_OM2);
        $this->pinSign($id, $om2, self::PIN_OM2, [], $this->om)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame('voided', $order->fresh()->status);
    }

    public function test_a_clerks_own_pin_cannot_approve_the_clerks_request(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $this->pinSign($id, $this->clerk, self::PIN_CLERK)->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_wrong_pins_are_rate_limited_per_approver_and_audited(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        for ($i = 1; $i <= 4; $i++) {
            $this->pinSign($id, $this->om, '0000')->assertStatus(422)
                ->assertJsonPath('code', 'PIN_INCORRECT')
                ->assertJsonPath('attempts_left', 5 - $i);
        }
        $this->pinSign($id, $this->om, '0000')->assertStatus(429)->assertJsonPath('code', 'APPROVER_PIN_LOCKED');

        // Locked: even the right PIN is refused now.
        $this->pinSign($id, $this->om, self::PIN_OM)->assertStatus(429)->assertJsonPath('code', 'APPROVER_PIN_LOCKED');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(5, DB::table('activity_log')->where('event', 'till_approval_pin_failed')->count());

        // The inbox still works for the locked-out approver.
        $this->inboxSign($this->om, ApprovalRequest::find($id))->assertOk();
        $this->assertSame('voided', $order->fresh()->status);
    }

    public function test_one_terminal_cannot_spray_guesses_across_approvers(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $approvers = collect(range(1, 3))->map(function ($n) {
            $u = $this->user(['outlet_manager'], $this->outlet);
            app(TerminalPin::class)->set($u, '58' . $n . '3');

            return $u;
        });

        // Four wrong guesses each stays under every account's own limit (5)…
        $wrong = 0;
        foreach ($approvers as $u) {
            for ($i = 0; $i < 4 && $wrong < 10; $i++, $wrong++) {
                $this->pinSign($id, $u, '0000')->assertStatus(422);
            }
        }
        // …but the terminal has had ten: it is throttled for everyone.
        $this->pinSign($id, $this->om, self::PIN_OM)->assertStatus(429)->assertJsonPath('code', 'TILL_PIN_THROTTLED');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_an_approver_without_a_pin_is_told_to_use_the_inbox(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');
        app(TerminalPin::class)->clear($this->om);

        $this->pinSign($id, $this->om, self::PIN_OM)->assertStatus(422)->assertJsonPath('code', 'APPROVER_PIN_NOT_SET');
    }

    public function test_an_outlet_manager_of_another_outlet_cannot_sign_at_this_till(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $elsewhere = $this->user(['outlet_manager'], Outlet::factory()->create());
        app(TerminalPin::class)->set($elsewhere, self::PIN_OM2);

        $this->pinSign($id, $elsewhere, self::PIN_OM2)->assertStatus(403)->assertJsonPath('code', 'APPROVER_NOT_AT_OUTLET');
        $this->assertSame('confirmed', $order->fresh()->status);

        // The approver list for this till names only those who could sign here.
        $this->as($this->clerk);
        $ids = collect($this->getJson("/api/v1/admin/pos/approvals/{$id}/approvers")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($this->om->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertNotContains($this->clerk->id, $ids);
    }

    // ── till close ───────────────────────────────────────────────────────────

    public function test_a_void_is_refused_after_the_till_is_closed(): void
    {
        $order = $this->sale(1500);
        $this->till->update(['status' => 'closed', 'closed_at' => now()]);

        $this->requestVoid($order)->assertStatus(422)->assertJsonPath('code', 'TILL_CLOSED');
        $this->assertSame(0, ApprovalRequest::where('event', 'pos_void')->count());

        // A refund is the route.
        $this->requestRefund($order)->assertStatus(202);
    }

    public function test_a_void_asked_before_close_cannot_be_signed_after_it(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');
        $this->till->update(['status' => 'closed', 'closed_at' => now()]);

        $this->pinSign($id, $this->om, self::PIN_OM)->assertStatus(422)->assertJsonPath('code', 'TILL_CLOSED');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('pending', ApprovalRequest::find($id)->status, 'the refused signature was not recorded');
        $this->assertSame(0, DB::table('approval_signatures')->where('approval_request_id', $id)->count());
    }

    // ── refunds ──────────────────────────────────────────────────────────────

    public function test_a_refund_is_always_a_new_transaction_and_nothing_moves_until_it_is_signed(): void
    {
        $order = $this->sale(1200, 2);
        $before = DB::table('orders')->where('id', $order->id)->first();
        $stock  = InventoryItem::where('product_variant_id', $order->items->first()->product_variant_id)->first();

        $res = $this->requestRefund($order, 1)->assertStatus(202)->assertJsonPath('approval.event', 'pos_refund');
        $this->assertEquals(1200, $res->json('refund_amount'));
        $this->assertSame(0, DB::table('order_returns')->count(), 'a request is not a refund');
        $this->assertSame(10, (int) $stock->fresh()->quantity_on_hand);
        $this->assertEquals(52400, $this->till->fresh()->expected_cash);

        $this->pinSign($res->json('approval.id'), $this->om, self::PIN_OM)->assertOk()->assertJsonPath('request.status', 'approved');

        $return = DB::table('order_returns')->where('order_id', $order->id)->first();
        $this->assertNotNull($return);
        $this->assertSame('completed', $return->status);
        $this->assertEquals(1200, $return->refund_amount);
        $this->assertSame((int) $this->clerk->id, (int) $return->created_by, 'created by the person who asked');
        $this->assertSame((int) $this->om->id, (int) $return->approved_by, 'approved by the person who signed');
        $this->assertSame(1, (int) DB::table('return_items')->where('return_id', $return->id)->value('quantity'));
        $this->assertSame((int) $return->id, (int) PosRefundRequest::find($res->json('refund_request_id'))->order_return_id);

        // The sale itself is never edited.
        $after = DB::table('orders')->where('id', $order->id)->first();
        foreach (['status', 'total_amount', 'subtotal', 'payment_status', 'updated_at'] as $col) {
            $this->assertEquals($before->$col, $after->$col, "orders.{$col} unchanged");
        }
        $this->assertSame(2, (int) DB::table('order_items')->where('order_id', $order->id)->value('quantity'));

        $this->assertSame(11, (int) $stock->fresh()->quantity_on_hand, 'the returned unit is back on the shelf');
        $this->assertEquals(51200, $this->till->fresh()->expected_cash, 'the cash came out of the drawer');
        $this->assertDatabaseHas('cash_register_transactions', ['transaction_type' => 'refund', 'order_id' => $order->id, 'amount' => 1200]);
    }

    public function test_refund_bands_a_6000_refund_needs_the_outlet_manager_then_finance(): void
    {
        $order = $this->sale(6000);
        $id = $this->requestRefund($order)->assertStatus(202)->json('approval.id');
        $this->assertSame(['pos.approve_reversal', 'approvals.finance_sign'], array_column(ApprovalRequest::find($id)->bands, 'permission'));

        $this->pinSign($id, $this->om, self::PIN_OM)->assertOk()->assertJsonPath('request.status', 'pending');
        $this->assertSame(0, DB::table('order_returns')->count());
        $this->pinSign($id, $this->fm, self::PIN_FM)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(1, DB::table('order_returns')->count());
    }

    public function test_partial_refunds_of_one_sale_are_banded_on_their_sum(): void
    {
        $order = $this->sale(3000, 2);

        $first = $this->requestRefund($order, 1)->assertStatus(202)->json('approval.id');
        $this->assertCount(1, ApprovalRequest::find($first)->bands);
        $this->pinSign($first, $this->om, self::PIN_OM)->assertOk();

        // 3,000 more within 24 hours by the same clerk: judged on 6,000.
        $second = $this->requestRefund($order, 1)->assertStatus(202)->json('approval.id');
        $this->assertEquals(6000, (float) ApprovalRequest::find($second)->basis_kes);
        $this->assertCount(2, ApprovalRequest::find($second)->bands);
    }

    public function test_a_cash_refund_must_be_covered_by_the_drawer_at_asking_and_at_signing(): void
    {
        $order = $this->sale(3000);
        DB::table('cash_registers')->where('id', $this->till->id)->update(['expected_cash' => 1000]);

        $this->requestRefund($order)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_CASH');
        $this->assertSame(0, PosRefundRequest::count());

        // Covered when asked, drained before it is signed: the signature is refused.
        DB::table('cash_registers')->where('id', $this->till->id)->update(['expected_cash' => 5000]);
        $id = $this->requestRefund($order)->assertStatus(202)->json('approval.id');
        DB::table('cash_registers')->where('id', $this->till->id)->update(['expected_cash' => 500]);

        $this->pinSign($id, $this->om, self::PIN_OM)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_CASH');
        $this->assertSame(0, DB::table('order_returns')->count());
        $this->assertSame('pending', ApprovalRequest::find($id)->status);
    }

    public function test_a_line_cannot_be_asked_for_twice_while_a_refund_is_pending(): void
    {
        $order = $this->sale(1000, 1);
        $this->requestRefund($order, 1)->assertStatus(202);

        $this->requestRefund($order, 1)->assertStatus(422)->assertJsonPath('code', 'OVER_RETURN');
        // And the sale cannot be voided while its refund waits.
        $this->requestVoid($order)->assertStatus(409)->assertJsonPath('code', 'REFUND_PENDING');
    }

    public function test_a_rejected_refund_moves_nothing_and_frees_the_lines(): void
    {
        $order = $this->sale(1000, 1);
        $id = $this->requestRefund($order, 1)->assertStatus(202)->json('approval.id');

        $this->pinSign($id, $this->om, self::PIN_OM, ['decision' => 'reject', 'reason' => 'Not our stock'])
            ->assertOk()->assertJsonPath('request.status', 'rejected');

        $this->assertSame('rejected', PosRefundRequest::first()->status);
        $this->assertSame(0, DB::table('order_returns')->count());
        $this->requestRefund($order, 1)->assertStatus(202);
    }

    // ── the inbox, the sale view, the audit ─────────────────────────────────

    public function test_an_approver_can_approve_from_the_approvals_inbox(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $this->as($this->om);
        $inbox = collect($this->getJson('/api/v1/admin/approvals/inbox')->assertOk()->json('data'));
        $this->assertTrue($inbox->contains('id', $id));
        $this->assertStringContainsString($order->order_number, $inbox->firstWhere('id', $id)['summary']['title']);

        $this->inboxSign($this->om, ApprovalRequest::find($id))->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame('voided', $order->fresh()->status);
    }

    public function test_the_pending_state_is_visible_on_the_sale(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');

        $this->as($this->clerk);
        $pending = $this->getJson("/api/v1/admin/pos/sales/{$order->id}")->assertOk()->json('sale.pending_reversals');
        $this->assertCount(1, $pending);
        $this->assertSame($id, $pending[0]['approval_id']);
        $this->assertSame('void', $pending[0]['kind']);
        $this->assertSame('pos.approve_reversal', $pending[0]['awaiting']);

        $this->getJson("/api/v1/admin/pos/sales/{$order->id}/reversals")->assertOk()
            ->assertJsonPath('pending.0.approval_id', $id)->assertJsonPath('till_closed', false);

        // A second void request while one waits is refused.
        $this->requestVoid($order)->assertStatus(409)->assertJsonPath('code', 'APPROVAL_PENDING');

        $this->pinSign($id, $this->om, self::PIN_OM)->assertOk();
        $this->as($this->clerk);
        $this->assertSame([], $this->getJson("/api/v1/admin/pos/sales/{$order->id}")->json('sale.pending_reversals'));
    }

    public function test_the_audit_trail_names_both_the_requester_and_the_approver(): void
    {
        $order = $this->sale(1500);
        $id = $this->requestVoid($order)->assertStatus(202)->json('approval.id');
        $this->pinSign($id, $this->om, self::PIN_OM)->assertOk();

        $sig = DB::table('approval_terminal_signatures')->where('approval_request_id', $id)->first();
        $this->assertNotNull($sig);
        $this->assertSame((int) $this->clerk->id, (int) $sig->requester_id);
        $this->assertSame((int) $this->om->id, (int) $sig->approver_id);
        $this->assertSame((int) $this->clerk->id, (int) $sig->terminal_user_id);
        $this->assertSame('approved', $sig->decision);
        $this->assertSame((int) $this->om->id, (int) DB::table('approval_signatures')->where('id', $sig->approval_signature_id)->value('signer_id'));

        foreach (['pos_sale_voided', 'till_approval_signed_on_terminal'] as $event) {
            $row = DB::table('activity_log')->where('event', $event)->where('subject_id', $order->id)->first();
            $this->assertNotNull($row, "{$event} logged");
            $props = json_decode($row->properties, true);
            $this->assertSame((int) $this->clerk->id, (int) ($props['requested_by'] ?? $props['requester_id']), "{$event} names the requester");
            $this->assertSame((int) $this->om->id, (int) ($props['approved_by'] ?? $props['approver_id']), "{$event} names the approver");
            $this->assertSame((int) $this->om->id, (int) $row->causer_id, "{$event} is the approver's act");
        }
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_submitted', 'causer_id' => $this->clerk->id]);
    }

    public function test_the_refund_audit_names_both_people(): void
    {
        $order = $this->sale(1000);
        $id = $this->requestRefund($order)->assertStatus(202)->json('approval.id');
        $this->inboxSign($this->om, ApprovalRequest::find($id))->assertOk();

        $props = json_decode(DB::table('activity_log')->where('event', 'pos_return_processed')->value('properties'), true);
        $this->assertSame((int) $this->clerk->id, (int) $props['requested_by']);
        $this->assertSame((int) $this->om->id, (int) $props['approved_by']);
    }

    public function test_the_threshold_sets_are_seeded_and_listed(): void
    {
        $this->as($this->owner);
        $events = collect($this->getJson('/api/v1/admin/approvals/thresholds')->assertOk()->json('data'))->keyBy('event');

        $this->assertEquals([20000, 100000, null], array_column($events['pos_void']['bands'], 'up_to_kes'));
        $this->assertEquals([5000, 50000, null], array_column($events['pos_refund']['bands'], 'up_to_kes'));
        $this->assertSame(['pos.approve_reversal', 'approvals.finance_sign', 'approvals.super_sign'],
            array_column($events['pos_refund']['bands'], 'approver_permission'));
    }

    // ── the band key ─────────────────────────────────────────────────────────

    public function test_only_the_outlet_manager_holds_the_band_key(): void
    {
        $holders = Role::where('guard_name', 'sanctum')->get()
            ->filter(fn ($r) => $r->hasPermissionTo('pos.approve_reversal'))->pluck('name')->all();

        $this->assertSame(['outlet_manager'], $holders);
    }

    public function test_the_permission_migration_grants_and_takes_back_exactly_its_own(): void
    {
        $migration = require database_path('migrations/2026_10_03_860002_pos_approve_reversal_permission.php');
        $om = Role::findByName('outlet_manager', 'sanctum');
        $person = $this->user([]);

        // Production before it: no such key.
        $migration->down();
        DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'pos.approve_reversal')->pluck('id'))->delete();
        DB::table('permissions')->where('name', 'pos.approve_reversal')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration->up();
        $migration->up();   // idempotent
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($om->fresh()->hasPermissionTo('pos.approve_reversal'));
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $om->id)
            ->where('permission_id', DB::table('permissions')->where('name', 'pos.approve_reversal')->value('id'))->count());

        $person->givePermissionTo('pos.approve_reversal');   // a direct grant made after
        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($om->fresh()->hasPermissionTo('pos.approve_reversal'));
        $this->assertTrue($person->fresh()->hasPermissionTo('pos.approve_reversal', 'sanctum'), 'a direct grant is never touched');
    }
}
