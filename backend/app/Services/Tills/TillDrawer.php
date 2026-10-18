<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\CashRegisterTransaction;
use App\Support\SellableCurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one writer of a till's money (currency plan Step 2, owner 2026-10-18).
 *
 * A till keeps one balance row per currency (cash_register_balances). Money
 * moves in the currency of the sale or refund — a US customer's dollars go
 * into the till's USD row, never into the shilling figure — and change is
 * given in that same currency. The register's own money columns are the
 * HOME row's mirror (the till's currency_code), written in the same
 * transaction, so every reader that predates this keeps seeing the till's
 * shillings exactly as before.
 *
 * Every movement that touches cash also lands a row in the drawer's ledger
 * (cash_register_transactions) carrying its currency; expected cash at the
 * count is rebuilt from that ledger per currency (TillService::ledgerBreakdown).
 */
class TillDrawer
{
    /** @var list<string> */
    public const CASH_IN_TYPES = ['sale', 'cash_in'];

    /** The till's own currency, upper-case; KES when a legacy row has none. */
    public static function home(CashRegister $register): string
    {
        return strtoupper((string) ($register->currency_code ?: 'KES'));
    }

    /**
     * Open the drawer's rows: the home float from the register, and any
     * foreign floats typed at opening ({code: amount}). A foreign float must
     * be in a currency the books can state in shillings.
     *
     * @param  array<string, float|int|string>  $foreignFloats
     */
    public function open(CashRegister $register, array $foreignFloats = []): void
    {
        $home = self::home($register);

        CashRegisterBalance::create([
            'cash_register_id' => $register->id,
            'currency_code'    => $home,
            'is_home'          => true,
            'opening_balance'  => round((float) $register->opening_balance, 2),
            'expected_cash'    => round((float) $register->opening_balance, 2),
        ]);

        foreach ($foreignFloats as $code => $amount) {
            $code   = strtoupper(trim((string) $code));
            $amount = round((float) $amount, 2);
            if ($code === $home || $amount <= 0) {
                continue;
            }
            SellableCurrency::assert($code, "opening_floats.{$code}");
            CashRegisterBalance::create([
                'cash_register_id' => $register->id,
                'currency_code'    => $code,
                'is_home'          => false,
                'opening_balance'  => $amount,
                'expected_cash'    => $amount,
            ]);
        }
    }

    /**
     * The row for one currency, created at zero the first time that currency
     * is touched (a foreign sale on a till opened with only a shilling float).
     */
    public function balanceFor(CashRegister $register, string $currency, bool $lock = false): CashRegisterBalance
    {
        $currency = strtoupper($currency);
        $query    = CashRegisterBalance::where('cash_register_id', $register->id)->where('currency_code', $currency);
        $row      = $lock ? $query->lockForUpdate()->first() : $query->first();
        if ($row) {
            return $row;
        }

        $isHome = $currency === self::home($register);

        return CashRegisterBalance::firstOrCreate(
            ['cash_register_id' => $register->id, 'currency_code' => $currency],
            [
                'is_home'         => $isHome,
                'opening_balance' => $isHome ? round((float) $register->opening_balance, 2) : 0,
                'expected_cash'   => $isHome ? round((float) ($register->expected_cash ?? $register->opening_balance), 2) : 0,
            ],
        );
    }

    /** @return \Illuminate\Support\Collection<int, CashRegisterBalance> home row first */
    public function balances(CashRegister $register)
    {
        return CashRegisterBalance::where('cash_register_id', $register->id)
            ->orderByDesc('is_home')->orderBy('currency_code')->get();
    }

    /**
     * Money taken for a sale, in the sale's currency, split by method. The
     * cash part raises expected cash and lands a ledger row; card and M-Pesa
     * only move the method totals. One transaction per call.
     *
     * @param  array{cash?: float, card?: float, mpesa?: float}  $byMethod  settled tenders only
     */
    public function take(CashRegister $register, string $currency, array $byMethod, ?int $orderId, int $userId, ?string $notes = null): void
    {
        $currency = strtoupper($currency);
        $cash  = round((float) ($byMethod['cash'] ?? 0), 2);
        $card  = round((float) ($byMethod['card'] ?? 0), 2);
        $mpesa = round((float) ($byMethod['mpesa'] ?? 0), 2);
        $total = round($cash + $card + $mpesa, 2);
        if ($total <= 0) {
            return;
        }

        $this->move($register, $currency, [
            'total_sales'       => $total,
            'total_cash_sales'  => $cash,
            'total_card_sales'  => $card,
            'total_mpesa_sales' => $mpesa,
            'transaction_count' => 1,
            'expected_cash'     => $cash,
        ]);

        if ($cash > 0) {
            $this->ledger($register, $currency, 'sale', 'cash', $cash, $orderId, $userId, $notes);
        }
    }

    /**
     * A sale reversed (void) or money paid back (refund), in the sale's
     * currency. A void takes the sale's method totals back out; a refund adds
     * to total_refunds. Cash comes out of that currency's drawer only.
     *
     * @param  array{cash?: float, card?: float, mpesa?: float}  $byMethod
     */
    public function giveBack(CashRegister $register, string $currency, string $type, array $byMethod, ?int $orderId, int $userId, ?string $notes = null): void
    {
        $currency = strtoupper($currency);
        $cash  = round((float) ($byMethod['cash'] ?? 0), 2);
        $card  = round((float) ($byMethod['card'] ?? 0), 2);
        $mpesa = round((float) ($byMethod['mpesa'] ?? 0), 2);
        $total = round($cash + $card + $mpesa, 2);
        if ($total <= 0) {
            return;
        }

        $delta = $type === 'void'
            ? [
                'total_sales'       => -$total,
                'total_cash_sales'  => -$cash,
                'total_card_sales'  => -$card,
                'total_mpesa_sales' => -$mpesa,
                'transaction_count' => -1,
                'expected_cash'     => -$cash,
            ]
            : [
                'total_refunds' => $total,
                'expected_cash' => -$cash,
            ];

        $this->move($register, $currency, $delta, floorAtZero: true);

        if ($cash > 0) {
            $this->ledger($register, $currency, $type, 'cash', $cash, $orderId, $userId, $notes);
        }
    }

    /** Can this currency's drawer pay out $amount in cash right now? */
    public function canPayOut(CashRegister $register, string $currency, float $amount): bool
    {
        $row = CashRegisterBalance::where('cash_register_id', $register->id)
            ->where('currency_code', strtoupper($currency))->first();
        $expected = $row ? (float) $row->expected_cash
            : (strtoupper($currency) === self::home($register) ? (float) $register->expected_cash : 0.0);

        return $amount <= $expected + 0.005;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Apply column deltas to one currency's row and, for the home currency,
     * to the register's own columns in the same statement shape — so the two
     * can never disagree. Floor at zero for reversals, as the register always has.
     *
     * @param  array<string, float|int>  $delta
     */
    private function move(CashRegister $register, string $currency, array $delta, bool $floorAtZero = false): void
    {
        $row = $this->balanceFor($register, $currency, true);

        $expr = function (string $col, float|int $d) use ($floorAtZero): \Illuminate\Database\Query\Expression {
            $sql = $col . ' + (' . $d . ')';

            return DB::raw($floorAtZero ? "GREATEST(0, {$sql})" : $sql);
        };

        $set = ['updated_at' => now()];
        foreach ($delta as $col => $d) {
            $set[$col] = $expr($col, $d);
        }
        DB::table('cash_register_balances')->where('id', $row->id)->update($set);

        if ($row->is_home) {
            DB::table('cash_registers')->where('id', $register->id)->update($set);
        }
    }

    private function ledger(CashRegister $register, string $currency, string $type, ?string $method, float $amount, ?int $orderId, int $userId, ?string $notes): void
    {
        $after = (float) CashRegisterBalance::where('cash_register_id', $register->id)
            ->where('currency_code', $currency)->value('expected_cash');

        CashRegisterTransaction::create([
            'cash_register_id' => $register->id,
            'transaction_type' => $type,
            'payment_method'   => $method,
            'amount'           => round($amount, 2),
            'currency_code'    => $currency,
            'balance_after'    => round($after, 2),
            'order_id'         => $orderId,
            'notes'            => $notes,
            'created_by'       => $userId,
        ]);
    }
}
