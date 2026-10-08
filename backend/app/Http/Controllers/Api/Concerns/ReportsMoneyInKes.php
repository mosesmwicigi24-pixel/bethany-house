<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\ReportingCurrency;
use Illuminate\Http\Request;

/**
 * ONE answer to "what currency is this report in", for every report that
 * aggregates money.
 *
 * `salesSummary` worked this out correctly and kept it to itself: a local
 * `$reportInKes` flag, a local `$amtKes()` closure and a currency filter on
 * its base query. The other eighteen money-aggregating methods in these
 * controllers summed `total_amount` raw, across currencies, as though a
 * dollar were a shilling.
 *
 * Measured on production 2026-09-30, on the recognised scope:
 *
 *   sales by product / category    6,592,367 shown · 7,089,826 true  (−7.0%)
 *   sales by customer / LTV        6,350,450 shown · 6,897,439 true  (−7.9%)
 *   sales by payment method        5,283,740 shown · 5,740,559 true
 *   /financial/revenue             5,288,590 shown · 5,745,409 true
 *   Neema chat sales                 900,880 shown · 3,419,690 true  (3.8x)
 *
 * The chat line is the one that matters: the WhatsApp and Messenger channel
 * is where this business's foreign currency lives, so the channel management
 * reads about was under-reported by nearly four times.
 *
 * Two rates exist and must never be confused (§ the brief's invariant 1):
 * `currencies.exchange_rate` is the PRICING rate a customer is quoted at
 * (100 KES/USD); `reporting_rate_to_kes` states what earned money is worth
 * (128). Everything here uses the second, via ReportingCurrency, which also
 * parenthesises compound expressions — writing the multiplication by hand is
 * what once understated a month's Collected by 264,287.
 */
trait ReportsMoneyInKes
{
    /**
     * The reporting currency, the converter, and the filter that keeps rows
     * the report cannot state out of its sums.
     *
     * Usage mirrors what salesSummary already did:
     *
     *   [$amtKes, $inKes, $currency] = $this->reportingMoney($request);
     *   ->selectRaw("SUM({$amtKes('orders.total_amount')}) AS revenue")
     *   ->where(fn ($q) => $this->onlyStatableCurrencies($q, $inKes, $currency))
     *
     * @return array{0: \Closure(string): string, 1: bool, 2: string}
     */
    private function reportingMoney(Request $request, string $currencyColumn = 'orders.currency_code'): array
    {
        $currency = strtoupper($request->get('currency_code', $request->get('currency', 'KES')));
        $inKes    = $currency === 'KES';

        // Asking for a specific foreign currency reports it natively, so the
        // amounts are already in one unit and must NOT be multiplied.
        $amtKes = fn (string $column): string => $inKes
            ? ReportingCurrency::kes($column, $currencyColumn)
            : $column;

        return [$amtKes, $inKes, $currency];
    }

    /**
     * Restrict a query to rows the report can actually state.
     *
     * In KES: every currency that has a reporting rate. A currency with no
     * rate is EXCLUDED rather than counted at face value — nobody has said
     * what it is worth, and guessing is how 1,000 of something becomes 1,000
     * shillings. In a named currency: only that currency.
     *
     * This matters beyond the sums. `ReportingCurrency::kes` ends in
     * `ELSE NULL`, so an unrated row contributes NULL to a SUM (dropped) while
     * still being counted by COUNT(*) — an order that exists in the count and
     * not in the money. Filtering makes the two agree.
     */
    private function onlyStatableCurrencies($query, bool $inKes, string $currency, string $currencyColumn = 'orders.currency_code')
    {
        return $inKes
            ? $query->whereRaw(ReportingCurrency::convertibleFilter($currencyColumn))
            : $query->whereRaw("UPPER({$currencyColumn}) = ?", [$currency]);
    }
}
