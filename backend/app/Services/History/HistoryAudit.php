<?php

namespace App\Services\History;

use App\Models\CashRegister;
use App\Models\ShippingMethod;
use App\Services\CurrencyPricing;
use App\Services\Tills\TillService;
use App\Support\ReportingCurrency;
use App\Support\ShippingFee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Currency plan Step 6 (owner, 2026-10-18): FIND every record the old code
 * wrote in the wrong currency, so each can be listed, reviewed and put
 * right with an audit trail. Read-only; every method is a query and a
 * shape, nothing here writes.
 *
 * The seven classes are the plan's table:
 *   1. foreign cash rung through a till in its own currency      → tills()
 *   2. order lines priced from another currency's price row     → linesPricedInAnotherCurrency()
 *   3. a method's KES shipping fee on a non-KES order            → kesShippingOnForeignOrders()
 *   4. a payment in a different currency from its order          → paymentsInAnotherCurrency()
 *   5. M-Pesa (shillings) charged on a non-KES order             → mpesaOnForeignOrders()
 *   6. sales, quotes, POs, payments, expenses, budgets whose
 *      currency has no reporting rate                            → recordsWithoutRate()
 *   7. expense budgets set in a currency other than KES          → foreignBudgets()
 * plus the Step 0 exposure query for outlets whose country prices in
 * another currency                                               → outletsInForeignCurrency()
 *
 * Classes 1 and 7 are mechanical and are repaired by the history:repair-*
 * commands. The rest need a person's judgment: they are listed with the
 * figure the books expected, for the existing correction flows (order
 * line edit, set-shipping, payment move/void, currency rate proposal).
 */
class HistoryAudit
{
    public function __construct(private readonly TillService $tills)
    {
    }

    /**
     * Tills whose cash ledger carries a currency other than the till's own.
     * One entry per till, with its home figures as stored against the ledger
     * (home currency only), and one line per foreign currency moved.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tills(?int $registerId = null): Collection
    {
        $rows = DB::table('cash_register_transactions as t')
            ->join('cash_registers as r', 'r.id', '=', 't.cash_register_id')
            ->where(fn ($q) => $q->whereNull('t.payment_method')->orWhere('t.payment_method', 'cash'))
            ->whereRaw("UPPER(t.currency_code) <> UPPER(COALESCE(r.currency_code, 'KES'))")
            ->when($registerId, fn ($q) => $q->where('r.id', $registerId))
            ->groupBy('r.id', 't.currency_code')
            ->orderBy('r.id')->orderBy('t.currency_code')
            ->selectRaw("
                r.id AS register_id,
                UPPER(t.currency_code) AS currency,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'sale'     THEN ABS(t.amount) ELSE 0 END), 0) AS cash_sales,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'cash_in'  THEN ABS(t.amount) ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'refund'   THEN ABS(t.amount) ELSE 0 END), 0) AS refunds,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'void'     THEN ABS(t.amount) ELSE 0 END), 0) AS voids,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'cash_out' THEN ABS(t.amount) ELSE 0 END), 0) AS paid_outs,
                SUM(CASE WHEN t.transaction_type = 'sale' THEN 1 ELSE 0 END) AS sale_count,
                EXISTS (SELECT 1 FROM cash_register_balances b
                         WHERE b.cash_register_id = r.id AND UPPER(b.currency_code) = UPPER(t.currency_code)) AS has_balance_row
            ")
            ->get();

        return $rows->groupBy('register_id')->map(function (Collection $lines, int $id) {
            $register = CashRegister::query()->findOrFail($id);
            $home     = strtoupper((string) ($register->currency_code ?: 'KES'));
            $ledger   = $this->tills->ledgerBreakdown($register, $home);
            $stored   = $register->expected_cash_at_count ?? $register->expected_cash;
            $stored   = $stored === null ? null : round((float) $stored, 2);
            $actual   = $register->actual_cash === null ? null : round((float) $register->actual_cash, 2);

            return [
                'register_id'       => $register->id,
                'register_number'   => $register->register_number,
                'register_name'     => $register->register_name,
                'outlet_id'         => $register->outlet_id,
                'status'            => $register->status,
                'finalized'         => $register->finalized_at !== null,
                'home'              => $home,
                'stored_expected'   => $stored,
                'ledger_expected'   => $ledger['expected'],
                'difference'        => $stored === null ? null : round($stored - $ledger['expected'], 2),
                'actual_cash'       => $actual,
                'stored_variance'   => $register->variance === null ? null : round((float) $register->variance, 2),
                'restated_variance' => $actual === null ? null : round($actual - $ledger['expected'], 2),
                'currencies'        => $lines->map(fn ($l) => [
                    'currency'        => $l->currency,
                    'cash_sales'      => round((float) $l->cash_sales, 2),
                    'cash_in'         => round((float) $l->cash_in, 2),
                    'refunds'         => round((float) $l->refunds, 2),
                    'voids'           => round((float) $l->voids, 2),
                    'paid_outs'       => round((float) $l->paid_outs, 2),
                    'net'             => round((float) $l->cash_sales + (float) $l->cash_in - (float) $l->refunds - (float) $l->voids - (float) $l->paid_outs, 2),
                    'sale_count'      => (int) $l->sale_count,
                    'has_balance_row' => (bool) $l->has_balance_row,
                ])->values()->all(),
            ];
        })->values();
    }

    /**
     * Order lines whose unit price equals a price row of ANOTHER currency and
     * no row of the order's own currency — the figure was borrowed as-is.
     * `expected` is the base (KES) regular price at the customer pricing
     * rate, null when the line cannot be priced in the order's currency.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function linesPricedInAnotherCurrency(): Collection
    {
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('product_prices as pp', function ($j) {
                $j->on('pp.product_id', '=', 'oi.product_id')
                  ->whereRaw('pp.product_variant_id IS NOT DISTINCT FROM oi.product_variant_id')
                  ->whereRaw('UPPER(pp.currency_code) <> UPPER(o.currency_code)')
                  ->whereRaw('(pp.regular_price = oi.unit_price OR pp.sale_price = oi.unit_price)');
            })
            ->whereRaw('NOT EXISTS (SELECT 1 FROM product_prices own
                         WHERE own.product_id = oi.product_id
                           AND own.product_variant_id IS NOT DISTINCT FROM oi.product_variant_id
                           AND UPPER(own.currency_code) = UPPER(o.currency_code)
                           AND (own.regular_price = oi.unit_price OR own.sale_price = oi.unit_price))')
            ->orderBy('o.id')->orderBy('oi.id')
            ->get([
                'o.id as order_id', 'o.order_number', 'o.status', 'o.currency_code',
                'oi.id as line_id', 'oi.product_id', 'oi.product_variant_id', 'oi.product_name',
                'oi.quantity', 'oi.unit_price', 'pp.currency_code as priced_in',
            ]);

        $base = CurrencyPricing::baseCode();

        return $rows->map(function ($r) use ($base) {
            $currency = strtoupper((string) $r->currency_code);
            $baseRow  = CurrencyPricing::rowsFor($r->product_id, $r->product_variant_id)
                ->first(fn ($p) => strtoupper((string) $p->currency_code) === $base && (float) $p->regular_price > 0);
            $expected = $baseRow ? CurrencyPricing::convert((float) $baseRow->regular_price, $base, $currency) : null;

            return [
                'order_id'      => $r->order_id,
                'order_number'  => $r->order_number,
                'status'        => $r->status,
                'currency'      => $currency,
                'line_id'       => $r->line_id,
                'product'       => $r->product_name,
                'quantity'      => (int) $r->quantity,
                'unit_price'    => round((float) $r->unit_price, 2),
                'priced_in'     => strtoupper((string) $r->priced_in),
                'expected'      => $expected,
                'expected_note' => $expected === null ? 'no base price or no pricing rate' : "{$base} regular price at the pricing rate",
            ];
        })->unique('line_id')->values();
    }

    /**
     * Non-KES orders whose shipping equals a flat method's KES fee, taken
     * unconverted. `expected` is that method's fee in the order's currency
     * at the customer pricing rate (Step 1's rule), null when no rate.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function kesShippingOnForeignOrders(): Collection
    {
        $rows = DB::table('orders as o')
            ->join('shipping_methods as sm', function ($j) {
                $j->on('sm.flat_rate', '=', 'o.shipping_amount')->where('sm.cost_type', 'flat_rate');
            })
            ->whereRaw("UPPER(o.currency_code) <> 'KES'")
            ->where('o.shipping_amount', '>', 0)
            ->orderBy('o.id')
            ->orderByRaw('CASE WHEN sm.name = o.shipping_method THEN 0 ELSE 1 END')
            ->get(['o.id as order_id', 'o.order_number', 'o.status', 'o.currency_code', 'o.subtotal', 'o.shipping_amount',
                   'o.shipping_method', 'sm.id as method_id', 'sm.name as method_name', 'sm.flat_rate']);

        return $rows->unique('order_id')->map(function ($r) {
            $method   = ShippingMethod::query()->find($r->method_id);
            $expected = null;
            if ($method) {
                try {
                    $expected = ShippingFee::inCurrency($method, (float) $r->subtotal, (string) $r->currency_code);
                } catch (ValidationException) {
                    $expected = null;
                }
            }

            return [
                'order_id'       => $r->order_id,
                'order_number'   => $r->order_number,
                'status'         => $r->status,
                'currency'       => strtoupper((string) $r->currency_code),
                'shipping_amount' => round((float) $r->shipping_amount, 2),
                'method'         => $r->method_name,
                'method_kes_fee' => round((float) $r->flat_rate, 2),
                'expected'       => $expected,
            ];
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> payments whose currency differs from their order's */
    public function paymentsInAnotherCurrency(): Collection
    {
        return DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->whereRaw('UPPER(p.currency_code) <> UPPER(o.currency_code)')
            ->orderBy('p.id')
            ->get(['p.id as payment_id', 'p.payment_number', 'o.order_number', 'p.currency_code as payment_currency',
                   'o.currency_code as order_currency', 'p.amount', 'p.refund_amount', 'p.status', 'p.payment_method', 'p.paid_at', 'p.created_at'])
            ->map(fn ($r) => [
                'payment_id'       => $r->payment_id,
                'payment_number'   => $r->payment_number,
                'order_number'     => $r->order_number,
                'payment_currency' => strtoupper((string) $r->payment_currency),
                'order_currency'   => strtoupper((string) $r->order_currency),
                'amount'           => round((float) $r->amount, 2),
                'refund_amount'    => round((float) ($r->refund_amount ?? 0), 2),
                'status'           => $r->status,
                'method'           => $r->payment_method,
                'at'               => $r->paid_at ?? $r->created_at,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> M-Pesa payments on non-KES orders, with what was actually received */
    public function mpesaOnForeignOrders(): Collection
    {
        return DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.payment_method', 'mpesa')
            ->whereRaw("UPPER(o.currency_code) <> 'KES'")
            ->orderBy('p.id')
            ->get(['p.id as payment_id', 'p.payment_number', 'o.order_number', 'o.currency_code as order_currency', 'o.total_amount',
                   'p.currency_code as payment_currency', 'p.amount', 'p.status', 'p.provider_transaction_id', 'p.paid_at', 'p.created_at'])
            ->map(fn ($r) => [
                'payment_id'       => $r->payment_id,
                'payment_number'   => $r->payment_number,
                'order_number'     => $r->order_number,
                'order_currency'   => strtoupper((string) $r->order_currency),
                'order_total'      => round((float) $r->total_amount, 2),
                'payment_currency' => strtoupper((string) $r->payment_currency),
                'amount_received'  => round((float) $r->amount, 2),
                'status'           => $r->status,
                'mpesa_receipt'    => $r->provider_transaction_id,
                'at'               => $r->paid_at ?? $r->created_at,
            ]);
    }

    /**
     * Records whose currency has no reporting rate, per table, and the
     * currency rows themselves that lack one.
     *
     * @return array{currencies: list<string>, tables: array<string, array{count: int, references: list<string>}>}
     */
    public function recordsWithoutRate(int $sample = 50): array
    {
        $codes = ReportingCurrency::convertibleCodes();
        $in    = implode(',', array_fill(0, count($codes), '?'));

        $noRate = DB::table('currencies')
            ->where(fn ($q) => $q->whereNull('reporting_rate_to_kes')->orWhere('reporting_rate_to_kes', '<=', 0))
            ->orderBy('code')->pluck('code')->map(fn ($c) => strtoupper($c))->values()->all();

        $tables = [
            'orders'          => 'order_number',
            'quotations'      => 'quote_number',
            'purchase_orders' => 'po_number',
            'payments'        => 'payment_number',
            'expenses'        => 'reference_number',
            'expense_budgets' => 'id',
        ];
        $out = [];
        foreach ($tables as $table => $ref) {
            $q = DB::table($table)->whereNotNull('currency_code')->whereRaw("UPPER(currency_code) NOT IN ({$in})", $codes);
            $out[$table] = [
                'count'      => (clone $q)->count(),
                'references' => (clone $q)->orderBy('id')->limit($sample)
                    ->get([$ref . ' as ref', 'currency_code'])
                    ->map(fn ($r) => "{$r->ref} ({$r->currency_code})")->values()->all(),
            ];
        }

        return ['currencies' => $noRate, 'tables' => $out];
    }

    /** @return Collection<int, array<string, mixed>> budgets not set in KES, with their shilling equivalent at the reporting rate */
    public function foreignBudgets(): Collection
    {
        $rates = ReportingCurrency::rates();

        return DB::table('expense_budgets as b')
            ->leftJoin('expense_categories as c', 'c.id', '=', 'b.category_id')
            ->whereRaw("UPPER(b.currency_code) <> 'KES'")
            ->orderBy('b.id')
            ->get(['b.id', 'c.name as category', 'b.outlet_id', 'b.period_type', 'b.period_year', 'b.period_number', 'b.budgeted_amount', 'b.currency_code'])
            ->map(function ($r) use ($rates) {
                $code = strtoupper((string) $r->currency_code);
                $rate = $rates[$code] ?? null;

                return [
                    'budget_id'   => $r->id,
                    'category'    => $r->category,
                    'outlet_id'   => $r->outlet_id,
                    'period'      => "{$r->period_type} {$r->period_year}/{$r->period_number}",
                    'amount'      => round((float) $r->budgeted_amount, 2),
                    'currency'    => $code,
                    'rate'        => $rate,
                    'amount_kes'  => $rate === null ? null : round((float) $r->budgeted_amount * $rate, 2),
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> outlets whose country's default currency is not KES */
    public function outletsInForeignCurrency(): Collection
    {
        return DB::table('outlets as o')
            ->join('countries as c', 'c.code', '=', 'o.country_code')
            ->whereRaw("UPPER(c.default_currency_code) <> 'KES'")
            ->orderBy('o.id')
            ->get(['o.id', 'o.name', 'o.country_code', 'c.default_currency_code'])
            ->map(fn ($r) => [
                'outlet_id'    => $r->id,
                'outlet'       => $r->name,
                'country'      => $r->country_code,
                'currency'     => strtoupper((string) $r->default_currency_code),
                'has_rate'     => isset(ReportingCurrency::rates()[strtoupper((string) $r->default_currency_code)]),
            ]);
    }
}
