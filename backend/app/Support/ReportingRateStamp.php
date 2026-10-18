<?php

namespace App\Support;

/**
 * The reporting rate a money record carries from the day it is made
 * (currency plan Step 3, owner 2026-10-18).
 *
 * Orders, payments, quotations and purchase orders store the REPORTING rate
 * in force when they were created (`reporting_rate_to_kes`, KES per unit) and
 * where it came from (`reporting_rate_source`). Reports read the stored
 * rate (fx_rate_to_kes in SQL), so a rate change approved later never
 * rewrites last month's shillings. Written by the models' own creating hooks,
 * so every path that makes one of these rows stamps it.
 *
 * Sources:
 *   kes            the reporting currency itself — always 1
 *   current        the live rate (ReportingCurrency) when the row was made
 *   none           the currency had no reporting rate when the row was made;
 *                  reports fall back to the live rate if one is set later
 *   history        backfilled from an approved rate change (change_proposals)
 *   initial        backfilled from the rates set on 20 Aug 2026 (USD 128, ZMW 6.5)
 *   earliest_known backfilled: the row predates the first known rate for its
 *                  currency, so it carries the earliest one, marked as such
 *   no_history     backfilled: the live rate, for a currency whose rate was
 *                  set outside the approval flow (no dated history exists)
 */
final class ReportingRateStamp
{
    /** @return array{reporting_rate_to_kes: ?float, reporting_rate_source: string} */
    public static function for(?string $currency): array
    {
        $code = strtoupper(trim((string) $currency));
        if ($code === '' || $code === 'KES') {
            return ['reporting_rate_to_kes' => 1.0, 'reporting_rate_source' => 'kes'];
        }
        $rate = ReportingCurrency::rates()[$code] ?? null;

        return $rate === null
            ? ['reporting_rate_to_kes' => null, 'reporting_rate_source' => 'none']
            : ['reporting_rate_to_kes' => round($rate, 6), 'reporting_rate_source' => 'current'];
    }

    /**
     * Stamp a model about to be written. On creation only when nothing is
     * stamped yet (an import may carry its own); on a currency change always,
     * since the old rate belongs to the old currency.
     */
    public static function apply(\Illuminate\Database\Eloquent\Model $m, bool $force = false): void
    {
        if (!$force && $m->getAttribute('reporting_rate_to_kes') !== null) {
            return;
        }
        foreach (self::for($m->getAttribute('currency_code')) as $k => $v) {
            $m->setAttribute($k, $v);
        }
    }
}
