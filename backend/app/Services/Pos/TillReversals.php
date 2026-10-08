<?php

namespace App\Services\Pos;

use App\Models\ApprovalRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PosRefundRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalEngine;
use App\Services\PosInventoryService;
use App\Services\ProductSerialService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Till voids and refunds through the approval engine (Phase 4B part 2).
 *
 * A clerk (or outlet manager) never voids or refunds directly any more: they
 * ASK, and the engine decides who must sign — the outlet manager's band, then
 * finance, then the super admin, cumulative by amount (approval_thresholds
 * events pos_void / pos_refund). The approver signs on the clerk's terminal
 * with their own PIN (PosTillApprovalController) or from the Approvals inbox.
 * Only when the LAST band signs does anything move, inside the engine's
 * transaction (PosVoidHandler / PosRefundHandler::onApproved call execute*):
 *
 *   void    the sale is marked voided, its payments voided, stock and serials
 *           returned, the drawer reversed by the cash it actually took. Only
 *           while the sale's till is still open; after the till is closed a
 *           void is refused and a refund is the only route.
 *   refund  a NEW order_returns row (the refund transaction) — the sale is
 *           never edited — stock back on the shelf, and a cash refund paid out
 *           of a drawer that can cover it, or refused.
 *
 * The drawer logic (which drawer a reversal hits, the cash ledger row) moved
 * here unchanged from PosController::voidSale / processReturn.
 */
class TillReversals
{
    public const VOID   = 'pos_void';
    public const REFUND = 'pos_refund';

    public function __construct(private ApprovalEngine $engine) {}

    // ── the sale's till ──────────────────────────────────────────────────────

    /**
     * The till (cash register) a sale was rung on: the drawer its cash went
     * into (from the cash ledger), else the register its cashier had open at
     * the outlet when the sale was made. Null when neither exists (a non-cash
     * sale rung without a register).
     */
    public function saleTill(Order $order): ?CashRegister
    {
        $originId = DB::table('cash_register_transactions')
            ->where('order_id', $order->id)
            ->where('transaction_type', 'sale')
            ->orderBy('id')
            ->value('cash_register_id');
        if ($originId) {
            return CashRegister::find($originId);
        }

        if (!$order->created_by || !$order->outlet_id || !$order->created_at) {
            return null;
        }

        return CashRegister::where('outlet_id', $order->outlet_id)
            ->where('opened_by', $order->created_by)
            ->where('opened_at', '<=', $order->created_at)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $order->created_at))
            ->orderByDesc('opened_at')
            ->first();
    }

    /**
     * Has the sale's till been closed? A till that is not open is closed
     * (whatever later states Phase 4B part 1 adds). A sale with no till at all
     * belongs to its business day: once that day is over, it is closed too.
     */
    public function tillClosed(Order $order): bool
    {
        $till = $this->saleTill($order);
        if ($till) {
            return $till->status !== 'open';
        }

        return !$order->created_at || !$order->created_at->isSameDay(now());
    }

    // ── asking ───────────────────────────────────────────────────────────────

    /** Ask for a void. Nothing happens to the sale until the last band signs. */
    public function requestVoid(Order $order, string $reason, User $maker): ApprovalRequest
    {
        $this->assertVoidable($order);

        return $this->engine->submit(self::VOID, $order, $maker, ['reason' => $reason]);
    }

    /**
     * Ask for a refund: price the lines exactly as the till always has
     * (unit price × quantity, capped at what the sale actually collected net
     * of refunds already made or already asked for) and put the request in
     * front of its signers. Nothing moves yet.
     *
     * @param  array{items: list<array{variant_id: int|null, quantity: int}>, reason: string, refund_method: string}  $input
     * @return array{0: PosRefundRequest, 1: ApprovalRequest}
     */
    public function requestRefund(Order $order, array $input, User $maker): array
    {
        if (in_array($order->status, ['voided', 'cancelled'], true)) {
            $this->fail(422, 'SALE_VOIDED', 'This sale has been voided or cancelled; there is nothing to refund.');
        }
        if ($this->engine->openRequest(self::VOID, $order)) {
            $this->fail(409, 'VOID_PENDING', 'A void of this sale is waiting for approval. It must be decided before a refund can be asked for.');
        }

        [$lines, $refundTotal] = $this->priceRefund($order, $input['items'], null);

        if ($input['refund_method'] === 'cash' && $refundTotal > 0) {
            $drawer = $this->drawerFor($order, $maker, false);
            if ($drawer && $refundTotal > (float) $drawer->expected_cash) {
                $this->fail(422, 'INSUFFICIENT_CASH', 'Insufficient cash in the register to make this refund.');
            }
        }

        return DB::transaction(function () use ($order, $input, $maker, $lines, $refundTotal) {
            $refund = PosRefundRequest::create([
                'order_id'      => $order->id,
                'outlet_id'     => $order->outlet_id,
                'status'        => PosRefundRequest::PENDING,
                'items'         => $lines,
                'refund_amount' => round($refundTotal, 2),
                'currency_code' => $order->currency_code ?: 'KES',
                'refund_method' => $input['refund_method'],
                'reason'        => $input['reason'],
                'requested_by'  => $maker->id,
            ]);

            $approval = $this->engine->submit(self::REFUND, $refund, $maker);

            return [$refund, $approval];
        });
    }

    // ── guards ───────────────────────────────────────────────────────────────

    /** Why this sale cannot be voided now, as the refusal; silent when it can. */
    public function assertVoidable(Order $order): void
    {
        if ($order->status === 'voided') {
            $this->fail(422, 'ALREADY_VOIDED', 'Order is already voided.');
        }
        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            $this->fail(422, 'NOT_VOIDABLE', "A {$order->status} sale cannot be voided.");
        }
        if ($this->tillClosed($order)) {
            $this->fail(422, 'TILL_CLOSED', 'The till this sale was rung on has been closed, so it can no longer be voided. Process a refund instead.');
        }
        if (DB::table('order_returns')->where('order_id', $order->id)->where('status', 'completed')->exists()) {
            $this->fail(422, 'PARTLY_REFUNDED', 'Part of this sale has already been refunded, so it cannot be voided. Refund the rest instead.');
        }
        if (PosRefundRequest::where('order_id', $order->id)->where('status', PosRefundRequest::PENDING)->exists()) {
            $this->fail(409, 'REFUND_PENDING', 'A refund on this sale is waiting for approval. It must be decided before the sale can be voided.');
        }
    }

    /**
     * Price the lines asked for and check each is still returnable: quantity
     * sold − quantity already returned (any return not rejected/cancelled) −
     * quantity in other refund requests still pending. The total is capped at
     * what the sale collected, net of completed refunds and other pending
     * refund requests.
     *
     * @param  list<array{variant_id: int|null, quantity: int}>  $items
     * @return array{0: list<array>, 1: float}
     */
    public function priceRefund(Order $order, array $items, ?int $exceptRequestId): array
    {
        $order->loadMissing('items');
        $pending = PosRefundRequest::where('order_id', $order->id)
            ->where('status', PosRefundRequest::PENDING)
            ->when($exceptRequestId, fn ($q) => $q->whereKeyNot($exceptRequestId))
            ->get();

        $lines = [];
        $total = 0.0;
        foreach ($items as $req) {
            $variantId = $req['variant_id'] ?? null;
            $orderItem = $order->items->firstWhere('product_variant_id', $variantId);
            if (!$orderItem) {
                $this->fail(422, 'NOT_ON_SALE', "Variant #{$variantId} not found in this order.");
            }

            $alreadyReturned = (int) DB::table('return_items')
                ->whereIn('return_id', fn ($q) => $q->select('id')->from('order_returns')
                    ->where('order_id', $order->id)
                    ->whereNotIn('status', ['rejected', 'cancelled']))
                ->where('order_item_id', $orderItem->id)
                ->sum('quantity');
            $asked = (int) $pending->sum(fn ($r) => collect($r->items)->where('order_item_id', $orderItem->id)->sum('quantity'));

            $maxReturnable = (int) $orderItem->quantity - $alreadyReturned - $asked;
            $qty = (int) $req['quantity'];
            if ($qty > $maxReturnable) {
                $this->fail(422, 'OVER_RETURN', "Cannot return {$qty} - only " . max(0, $maxReturnable) . ' returnable for this item.');
            }

            $line = round((float) $orderItem->unit_price * $qty, 2);
            $total += $line;
            $lines[] = [
                'order_item_id' => (int) $orderItem->id,
                'variant_id'    => $variantId === null ? null : (int) $variantId,
                'product_id'    => $orderItem->product_id === null ? null : (int) $orderItem->product_id,
                'quantity'      => $qty,
                'unit_price'    => (float) $orderItem->unit_price,
                'line_refund'   => $line,
            ];
        }

        // Bound the refund to what was ACTUALLY collected on this order, net
        // of refunds made and refunds already asked for. The line total is
        // unit_price × qty, which ignores discounts and tax and could pay out
        // more than the customer ever paid.
        $collected = (float) $order->payments()->where('status', 'paid')->sum('amount');
        $refunded  = (float) DB::table('order_returns')->where('order_id', $order->id)->where('status', 'completed')->sum('refund_amount');
        $total = min($total, max(0, $collected - $refunded - (float) $pending->sum('refund_amount')));

        return [$lines, round($total, 2)];
    }

    // ── carrying out (inside the engine's transaction) ──────────────────────

    /** The last band signed a void: carry it out. Throws to refuse the signature. */
    public function executeVoid(ApprovalRequest $approval, Order $order, User $approver): void
    {
        $order = Order::withoutViewerScope()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->assertVoidable($order);

        $requester = User::find($approval->maker_id) ?? $approver;
        $reason    = (string) ($approval->payload['reason'] ?? '');

        $order->update([
            'status'         => 'voided',
            'customer_notes' => ($order->customer_notes ? $order->customer_notes . ' | ' : '') . "Void: {$reason}",
        ]);

        // Capture what the sale ACTUALLY collected, by method, BEFORE voiding
        // the payment rows — so the register is reversed by the real cash
        // taken, not the order total.
        $cashCodes = DB::table('payment_methods')->where('type', 'cash')
            ->pluck('code')->push('cash')->map(fn ($c) => strtolower($c))->unique();
        $vCash = $vCard = $vMpesa = $vTotal = 0.0;
        foreach ($order->payments()->where('status', 'paid')->get() as $p) {
            $amt = (float) $p->amount;
            $vTotal += $amt;
            $m = strtolower($p->payment_method);
            if ($cashCodes->contains($m))                    { $vCash  += $amt; }
            elseif (in_array($m, ['card', 'card_paystack']))  { $vCard  += $amt; }
            elseif (in_array($m, ['mpesa', 'm-pesa']))        { $vMpesa += $amt; }
        }

        // MON-1: void the settled payments and reconcile payment_status so a
        // voided sale stops counting as collected.
        $order->payments()
            ->whereNotIn('status', ['voided', 'refunded'])
            ->update(['status' => 'voided', 'updated_at' => now()]);
        $order->syncPaymentStatus();

        // Stock back (committed → shelf, reserved → released; idempotent) and
        // the serialized units with it.
        PosInventoryService::unwindForOrder($order, $requester->id);
        ProductSerialService::releaseForOrder($order);

        // Reverse the drawer that took the sale (D8) by what it collected.
        if ($vTotal > 0) {
            $register = $this->drawerFor($order, $requester, true);
            if ($register) {
                DB::table('cash_registers')->where('id', $register->id)->update([
                    'total_sales'       => DB::raw('GREATEST(0, total_sales - ' . $vTotal . ')'),
                    'total_cash_sales'  => DB::raw('GREATEST(0, total_cash_sales - ' . $vCash . ')'),
                    'total_card_sales'  => DB::raw('GREATEST(0, total_card_sales - ' . $vCard . ')'),
                    'total_mpesa_sales' => DB::raw('GREATEST(0, total_mpesa_sales - ' . $vMpesa . ')'),
                    'transaction_count' => DB::raw('GREATEST(0, transaction_count - 1)'),
                    'expected_cash'     => DB::raw('GREATEST(0, expected_cash - ' . $vCash . ')'),
                    'updated_at'        => now(),
                ]);

                if ($vCash > 0) {
                    $this->ledger($register, 'void', $vCash, max(0, (float) $register->expected_cash - $vCash),
                        $order->id, $requester->id, "POS void (approval #{$approval->id})");
                }
            }
        }

        ActivityLogService::log('pos_sale_voided', $order, [
            'order_number'        => $order->order_number,
            'outlet_id'           => $order->outlet_id,
            'total_amount'        => $order->total_amount,
            'reason'              => $reason,
            'payment_method'      => $order->payment_method,
            'approval_request_id' => $approval->id,
            'requested_by'        => $requester->id,
            'approved_by'         => $approver->id,
        ], "POS sale {$order->order_number} voided — asked by #{$requester->id}, approved by #{$approver->id}", $approver);
    }

    /** The last band signed a refund: write the refund transaction. Throws to refuse the signature. */
    public function executeRefund(ApprovalRequest $approval, PosRefundRequest $refund, User $approver): void
    {
        $refund = PosRefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
        if ($refund->status !== PosRefundRequest::PENDING) {
            $this->fail(409, 'REFUND_DECIDED', "This refund request is already {$refund->status}.");
        }
        $order = Order::withoutViewerScope()->with('items')->whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
        if (in_array($order->status, ['voided', 'cancelled'], true)) {
            $this->fail(422, 'SALE_VOIDED', 'This sale has been voided or cancelled since the refund was asked for.');
        }

        // Still returnable, still covered by what the sale collected?
        [, $stillPayable] = $this->priceRefund($order, array_map(fn ($l) => [
            'variant_id' => $l['variant_id'], 'quantity' => $l['quantity'],
        ], $refund->items), $refund->id);
        $amount = round((float) $refund->refund_amount, 2);
        if ($stillPayable + 0.005 < $amount) {
            $this->fail(422, 'REFUND_STALE', 'The sale has changed since this refund was asked for. Reject it and ask again.');
        }

        $requester = User::find($refund->requested_by) ?? $approver;

        foreach ($refund->items as $line) {
            $inventory = InventoryItem::where('product_variant_id', $line['variant_id'])
                ->where('outlet_id', $order->outlet_id)
                ->first();
            $inventory?->adjustQuantity((int) $line['quantity'], 'return', Order::class, $order->id, $requester->id);

            if ($line['product_id']) {
                ProductSerialService::returnUnitsForOrder($order, (int) $line['product_id'], (int) $line['quantity']);
            }
        }

        // The refund transaction: a NEW record. The sale is not touched.
        $orderReturn = OrderReturn::create([
            'order_id'      => $order->id,
            'status'        => 'completed',
            'return_reason' => mb_substr($refund->reason, 0, 255),
            'admin_notes'   => "Till refund request #{$refund->id}, approval #{$approval->id}",
            'refund_amount' => $amount,
            'refund_method' => $refund->refund_method,
            'created_by'    => $requester->id,
            'approved_by'   => $approver->id,
            'approved_at'   => now(),
            'refunded_at'   => now(),
        ]);
        foreach ($refund->items as $line) {
            DB::table('return_items')->insert([
                'return_id'     => $orderReturn->id,
                'order_item_id' => $line['order_item_id'],
                'quantity'      => $line['quantity'],
                'reason'        => mb_substr($refund->reason, 0, 255),
                'restock'       => true,
                'created_at'    => now(),
            ]);
        }

        // A cash refund comes out of the drawer that took the sale (D8), or
        // the requester's current drawer when that shift is closed — and only
        // if it can cover it.
        if ($refund->refund_method === 'cash' && $amount > 0) {
            $register = $this->drawerFor($order, $requester, true);
            if ($register) {
                if ($amount > (float) $register->expected_cash) {
                    $this->fail(422, 'INSUFFICIENT_CASH', 'Insufficient cash in the register to make this refund.');
                }
                DB::table('cash_registers')->where('id', $register->id)->update([
                    'total_refunds' => DB::raw('total_refunds + ' . $amount),
                    'expected_cash' => DB::raw('GREATEST(0, expected_cash - ' . $amount . ')'),
                    'updated_at'    => now(),
                ]);
                $this->ledger($register, 'refund', $amount, max(0, (float) $register->expected_cash - $amount),
                    $order->id, $requester->id, "POS return (approval #{$approval->id})");
            }
        }

        $refund->update([
            'status'          => PosRefundRequest::APPROVED,
            'decided_by'      => $approver->id,
            'decided_at'      => now(),
            'order_return_id' => $orderReturn->id,
        ]);

        ActivityLogService::log('pos_return_processed', $order, [
            'return_id'             => $orderReturn->id,
            'return_number'         => $orderReturn->return_number,
            'outlet_id'             => $order->outlet_id,
            'refund_amount'         => $amount,
            'refund_method'         => $refund->refund_method,
            'reason'                => $refund->reason,
            'items_count'           => count($refund->items),
            'pos_refund_request_id' => $refund->id,
            'approval_request_id'   => $approval->id,
            'requested_by'          => $requester->id,
            'approved_by'           => $approver->id,
        ], "POS refund {$orderReturn->return_number} on {$order->order_number} — asked by #{$requester->id}, approved by #{$approver->id}", $approver);
    }

    // ── reading ──────────────────────────────────────────────────────────────

    /**
     * The void / refund requests on a sale that are still waiting, for the
     * till's sale view.
     *
     * @return list<array>
     */
    public function pendingForOrder(int $orderId): array
    {
        $refundIds = PosRefundRequest::where('order_id', $orderId)->where('status', PosRefundRequest::PENDING)->pluck('id');

        return ApprovalRequest::with('maker:id,first_name,last_name,email')
            ->where('status', ApprovalRequest::PENDING)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('event', self::VOID)
                    ->where('approvable_type', (new Order())->getMorphClass())
                    ->where('approvable_id', $orderId))
                ->orWhere(fn ($w) => $w->where('event', self::REFUND)
                    ->where('approvable_type', (new PosRefundRequest())->getMorphClass())
                    ->whereIn('approvable_id', $refundIds)))
            ->orderBy('created_at')
            ->get()
            ->map(fn (ApprovalRequest $r) => [
                'approval_id'   => $r->id,
                'event'         => $r->event,
                'kind'          => $r->event === self::VOID ? 'void' : 'refund',
                'approvable_id' => (int) $r->approvable_id,
                'version'       => $r->version,
                'amount'        => $r->amount === null ? null : (float) $r->amount,
                'currency_code' => $r->currency_code,
                'awaiting'      => $r->currentBandDef()['permission'] ?? null,
                'requested_by'  => $r->maker ? (trim("{$r->maker->first_name} {$r->maker->last_name}") ?: $r->maker->email) : null,
                'requested_at'  => $r->created_at?->toIso8601String(),
                'expires_at'    => $r->expires_at?->toIso8601String(),
            ])->values()->all();
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * D8: the drawer a void/refund for this order hits. Prefer the register
     * that ACTUALLY recorded the sale (from the cash ledger); if that shift is
     * closed, the acting person's current open drawer at the outlet — the cash
     * is paid out of the till in front of them. Null when there is none.
     */
    private function drawerFor(Order $order, User $actor, bool $lock): ?CashRegister
    {
        $originId = DB::table('cash_register_transactions')
            ->where('order_id', $order->id)
            ->where('transaction_type', 'sale')
            ->orderBy('id')
            ->value('cash_register_id');

        if ($originId) {
            $origin = CashRegister::whereKey($originId)->where('status', 'open')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if ($origin) {
                return $origin;
            }
        }

        return CashRegister::where('outlet_id', $order->outlet_id)
            ->where('opened_by', $actor->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function ledger(CashRegister $register, string $type, float $amount, float $balanceAfter, int $orderId, int $userId, string $notes): void
    {
        CashRegisterTransaction::create([
            'cash_register_id' => $register->id,
            'transaction_type' => $type,
            'payment_method'   => 'cash',
            'amount'           => round($amount, 2),
            'balance_after'    => round($balanceAfter, 2),
            'order_id'         => $orderId,
            'notes'            => $notes,
            'created_by'       => $userId,
        ]);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
