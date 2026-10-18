<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Translating foreign sales into KES for reporting.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS IS NOT THE PRICING RATE. Read this before using either.
 *
 *   Quoting / charging a customer   →  App\Services\CurrencyPricing
 *                                      currencies.exchange_rate
 *                                      100 KES = 1 USD
 *                                      A commercial decision. A round number
 *                                      chosen to make price lists legible. It
 *                                      is NOT what a dollar is worth.
 *
 *   Reporting completed sales       →  THIS CLASS
 *                                      currencies.reporting_rate_to_kes
 *                                      128 KES = 1 USD
 *                                      What a dollar already earned is worth
 *                                      when stated in the reporting currency.
 *
 * Reporting with the pricing rate understates foreign revenue by 22%. Pricing
 * with the reporting rate would raise every dollar price by 28% overnight.
 * Neither class may reach for the other's column, and a test asserts it.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * A currency with no reporting rate is NOT converted at a guess. It stays out
 * of KES totals and is counted separately, which is the same honesty the engine
 * already applies to a sum it cannot legitimately make.
 */
class ReportingCurrency
{
    private const CACHE_KEY = 'reporting-currency:rates';
    private const CACHE_TTL = 300;

    /** @return array<string, float> UPPERCASE code => KES per one unit */
    public static function rates(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $out = [];
            foreach (DB::table('currencies')->get(['code', 'reporting_rate_to_kes']) as $row) {
                $rate = $row->reporting_rate_to_kes === null ? null : (float) $row->reporting_rate_to_kes;
                if ($rate === null || $rate <= 0) {
                    continue;               // unset, or nonsense — never a guess
                }
                $out[strtoupper($row->code)] = $rate;
            }

            // KES is the reporting currency; it is 1 whether or not a row says so.
            $out['KES'] = 1.0;

            return $out;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Currencies that CAN be stated in KES. Everything else is reported apart. */
    public static function convertibleCodes(): array
    {
        return array_keys(self::rates());
    }

    /**
     * The STORED-RATE basis (currency plan Step 3, 2026-10-18).
     *
     * Orders, payments, quotations and purchase orders carry the reporting
     * rate in force when they were made (reporting_rate_to_kes, stamped by
     * App\Support\ReportingRateStamp). Every SQL conversion goes through the
     * database function fx_rate_to_kes(stored, code): the stored rate, else
     * the live rate for the code (a legacy row, or a currency that had no rate
     * at the time), else NULL — so a rate change approved today never
     * rewrites last month, and nothing is converted at a guess.
     *
     * The rate column is derived from the currency column ("o.currency_code"
     * → "o.reporting_rate_to_kes"), so every caller on one of the four tables
     * is on the stored basis without naming it; pass $rateColumn for a
     * currency column on another table.
     */
    public static function rateSql(string $currencyColumn, ?string $rateColumn = null): string
    {
        $rateColumn ??= preg_replace('/currency_code$/', 'reporting_rate_to_kes', $currencyColumn);

        return sprintf('fx_rate_to_kes(%s, %s)', $rateColumn, $currencyColumn);
    }

    /**
     * Which basis the session reads: 'stored' (the default — the rate each
     * row carries) or 'current' (today's rate for every row, the basis every
     * report had before Step 3). Only the comparison command and its tests
     * switch it; a request never does.
     */
    public static function useBasis(string $basis): void
    {
        $basis = $basis === 'current' ? 'current' : 'stored';
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Inside a transaction (every test) the switch lives and dies with it;
        // the command runs outside one and sets it for the session.
        DB::unprepared(DB::transactionLevel() > 0
            ? "SET LOCAL bethany.fx_basis = '{$basis}'"
            : "SET bethany.fx_basis = '{$basis}'");
    }

    /**
     * SQL that states a money column in KES, on the stored-rate basis.
     *
     *   ReportingCurrency::kes('orders.total_amount', 'orders.currency_code')
     *   → ((orders.total_amount) * fx_rate_to_kes(orders.reporting_rate_to_kes, orders.currency_code))
     *
     * An unconvertible currency yields NULL, so SUM() skips it rather than
     * quietly adding a dollar to a shilling. Pair it with convertibleFilter()
     * when the row itself should be excluded.
     *
     * The amount expression gets its OWN parentheses. Without them a
     * compound expression breaks on operator precedence:
     *   p.amount - COALESCE(p.refund_amount,0) * rate
     * multiplies the rate into the refund term only, so with zero refunds
     * the currency is added at face value — which is exactly how the
     * Collected tile understated a month by KES 264,287 (10.1%) while
     * looking perfectly plausible. Money SQL never trusts precedence.
     */
    public static function kes(string $amountColumn, string $currencyColumn, ?string $rateColumn = null): string
    {
        return sprintf('((%s) * %s)', $amountColumn, self::rateSql($currencyColumn, $rateColumn));
    }

    /** SQL predicate: this row's currency can legitimately be stated in KES. */
    public static function convertibleFilter(string $currencyColumn, ?string $rateColumn = null): string
    {
        return self::rateSql($currencyColumn, $rateColumn) . ' IS NOT NULL';
    }

    /** Convert a single amount in PHP. Null when the currency has no rate. */
    public static function toKes(float $amount, string $currency): ?float
    {
        $rate = self::rates()[strtoupper($currency)] ?? null;

        return $rate === null ? null : $amount * $rate;
    }
}
