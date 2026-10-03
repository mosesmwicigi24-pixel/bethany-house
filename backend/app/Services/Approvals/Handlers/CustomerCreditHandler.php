<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ChangeProposal;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Validation\ValidationException;

/**
 * customer_credit — deposit terms on an order: the deposit the customer pays
 * now and when the balance is due (POST /orders/{id}/set-deposit). Agreeing a
 * deposit is agreeing to carry the rest on credit, so the measure is that
 * BALANCE — order total less the agreed deposit — in KES at the reporting rate:
 *
 *   ≤ KES 20,000   → applies at once (the outlet manager's band, orders.set_deposit)
 *   ≤ KES 200,000  → + finance
 *   above          → + super admin
 *
 * Anti-splitting by sum: the band is judged on this balance plus the credit the
 * same maker agreed for the same customer's OTHER orders in the last 24 hours
 * (the latest terms per order, applied or waiting — an order counts once).
 * Lowering the credit on an order (raising its deposit) applies at once.
 * A currency with no reporting rate cannot be stated: every band.
 *
 * customers.credit_limit has no writer in the API and nothing enforces it at
 * a sale; only deposit terms extend credit today (POS deposits at the till are
 * Phase 4, with the approver PIN).
 */
final class CustomerCreditHandler extends ProposalHandler
{
    public function event(): string { return 'customer_credit'; }
    public function subjectType(): string { return 'order'; }
    public function title(): string { return 'Customer credit (deposit terms)'; }
    public function directEvent(): ?string { return 'customer_credit_direct'; }
    public function unit(): string { return 'kes'; }
    public function makerPermissions(): array { return ['orders.set_deposit']; }

    public function fields(): array
    {
        return ['deposit_amount' => 'Deposit', 'balance_due_date' => 'Balance due'];
    }

    private function order(int $id): ?Order
    {
        return Order::withoutGlobalScopes()->find($id);
    }

    public function current(int $subjectId): ?array
    {
        $o = $this->order($subjectId);

        return $o ? [
            'deposit_amount'   => $o->deposit_amount === null ? null : (float) $o->deposit_amount,
            'balance_due_date' => $o->balance_due_date?->toDateString(),
        ] : null;
    }

    public function label(int $subjectId): string
    {
        $o = $this->order($subjectId);
        if (!$o) {
            return "Order #{$subjectId}";
        }
        $who = trim((string) ($o->customer_name ?? '')) ?: null;

        return "Order {$o->order_number}" . ($who ? " — {$who}" : '') . " ({$o->currency_code} " . number_format((float) $o->total_amount, 2) . ')';
    }

    public function normalise(array $new, int $subjectId): array
    {
        $o   = $this->order($subjectId);
        $out = array_intersect_key($new, $this->fields());
        if (!$o) {
            return $out;
        }
        if ($o->payment_status === 'paid') {
            throw ValidationException::withMessages(['deposit_amount' => 'Order is already fully paid.']);
        }
        if (array_key_exists('deposit_amount', $out)) {
            $d = $out['deposit_amount'];
            if (!is_numeric($d) || (float) $d < 0.01) {
                throw ValidationException::withMessages(['deposit_amount' => 'The deposit must be at least 0.01.']);
            }
            if ((float) $d >= (float) $o->total_amount) {
                throw ValidationException::withMessages(['deposit_amount' => 'Deposit amount must be less than the order total.']);
            }
            $out['deposit_amount'] = round((float) $d, 2);
        }
        if (array_key_exists('balance_due_date', $out) && $out['balance_due_date'] !== null) {
            $date = \Carbon\Carbon::parse($out['balance_due_date'])->startOfDay();
            if (!$date->isAfter(today())) {
                throw ValidationException::withMessages(['balance_due_date' => 'The balance due date must be after today.']);
            }
            $out['balance_due_date'] = $date->toDateString();
        }

        return $out;
    }

    /** The balance an order carries on credit under a deposit: total − deposit (0 with no terms). */
    private static function creditOf(Order $o, ?float $deposit): float
    {
        return $deposit === null ? 0.0 : max(0.0, round((float) $o->total_amount - $deposit, 2));
    }

    public function measure(ChangeProposal $p): array
    {
        $o = $this->order((int) $p->subject_id);
        $live = $this->current((int) $p->subject_id) ?? [];
        $newDeposit = array_key_exists('deposit_amount', $p->changeset)
            ? (float) $p->changeset['deposit_amount']['new']
            : ($live['deposit_amount'] ?? null);

        $credit    = $o ? self::creditOf($o, $newDeposit) : 0.0;
        $wasCredit = $o ? self::creditOf($o, $live['deposit_amount'] ?? null) : 0.0;
        $creditKes = $o ? ApprovalEngine::toKes($credit, (string) $o->currency_code) : null;
        $others    = $this->otherCreditKes($p, $o);
        $basis     = $creditKes === null ? null : round($creditKes + $others, 2);

        return [
            // Less credit than the order already carries goes straight through.
            'direct_basis' => $credit <= $wasCredit ? 0.0 : $basis,
            'direct_ok'    => $creditKes !== null || $credit <= $wasCredit,
            'band_basis'   => $creditKes,
            'credit'       => $credit,
            'currency'     => $o?->currency_code,
            'credit_kes'   => $creditKes,
            'other_credit_kes' => $others,
            'previous_credit'  => $wasCredit,
        ];
    }

    public function extraRolling(ChangeProposal $p): float
    {
        return (float) ($p->measures['other_credit_kes'] ?? 0.0);
    }

    /**
     * The credit this maker agreed in the last 24 hours on the same customer's
     * other orders: the latest terms per order (applied or waiting), in KES.
     */
    private function otherCreditKes(ChangeProposal $p, ?Order $o): float
    {
        if (!$o || !$o->customer_id || !$p->maker_id) {
            return 0.0;
        }
        $orderIds = Order::withoutGlobalScopes()->where('customer_id', $o->customer_id)
            ->where('id', '!=', $o->id)->pluck('id');
        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        return (float) ChangeProposal::where('event', $this->event())
            ->where('subject_type', $this->subjectType())
            ->whereIn('subject_id', $orderIds)
            ->where('maker_id', $p->maker_id)
            ->whereIn('status', [ChangeProposal::APPLIED, ChangeProposal::PENDING, ChangeProposal::SCHEDULED])
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('id')
            ->get()
            ->keyBy('subject_id')          // the latest per order wins
            ->sum(fn (ChangeProposal $e) => (float) ($e->measures['band_basis'] ?? 0));
    }

    public function context(ChangeProposal $p): array
    {
        $o = $this->order((int) $p->subject_id);

        return $o ? [
            'total_amount'   => (float) $o->total_amount,
            'currency_code'  => $o->currency_code,
            'payment_status' => $o->payment_status,
        ] : [];
    }

    public function apply(ChangeProposal $p, array $new, ?User $by): void
    {
        $o = Order::withoutGlobalScopes()->whereKey($p->subject_id)->lockForUpdate()->firstOrFail();
        if ($o->payment_status === 'paid') {
            throw ValidationException::withMessages(['deposit_amount' => 'Order is already fully paid.']);
        }
        if (isset($new['deposit_amount']) && (float) $new['deposit_amount'] >= (float) $o->total_amount) {
            throw ValidationException::withMessages(['deposit_amount' => 'Deposit amount must be less than the order total.']);
        }
        $o->update(array_intersect_key($new, $this->fields()));

        // The order timeline reads this entry (OrderController::timeline).
        ActivityLogService::log('deposit_terms_set', $o, [
            'deposit_amount'     => $o->deposit_amount,
            'balance_due_date'   => $o->balance_due_date?->toDateString(),
            'order_total'        => $o->total_amount,
            'change_proposal_id' => $p->id,
        ], null, $by);
    }

    public function link(ChangeProposal $p): ?string
    {
        return "/sales/orders/{$p->subject_id}";
    }

    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        if ($field === 'balance_due_date') {
            return $value ? \Carbon\Carbon::parse($value)->format('j M Y') : '—';
        }
        $currency = $this->order((int) $p->subject_id)?->currency_code ?? '';

        return $value === null ? 'none' : trim("{$currency} " . number_format((float) $value, 2));
    }

    public function measureLine(ChangeProposal $p): ?string
    {
        $m = $p->measures ?? [];
        if (($m['credit_kes'] ?? null) === null) {
            return 'Balance on credit cannot be stated in KES (no reporting rate) — every band';
        }
        $line = 'Balance on credit: KES ' . number_format((float) $m['credit_kes'], 2);
        if ((float) ($m['other_credit_kes'] ?? 0) > 0) {
            $line .= ' (+ KES ' . number_format((float) $m['other_credit_kes'], 2) . ' on the same customer\'s other orders in 24 h)';
        }

        return $line;
    }
}
