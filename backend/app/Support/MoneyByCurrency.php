<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Money that may be in more than one currency, stated honestly (currency
 * plan Step 5, owner 2026-10-18): each currency's own total beside a KES
 * total at the reporting rate, and the name of any currency the books
 * cannot convert. Nothing is ever added across currencies.
 *
 *   MoneyByCurrency::of($rows, 'total_amount', 'currency_code', 'reporting_rate_to_kes')
 *   → ['kes_total' => 6620.0,
 *      'by_currency' => [['currency_code' => 'KES', 'amount' => 1500.0, 'count' => 1, 'amount_kes' => 1500.0],
 *                        ['currency_code' => 'USD', 'amount' => 40.0,   'count' => 1, 'amount_kes' => 5120.0]],
 *      'unconverted' => []]
 *
 * The stored rate ($rateKey, Step 3) is used when the row carries one; the
 * live rate otherwise; a currency with neither is listed, not guessed.
 */
final class MoneyByCurrency
{
    /**
     * @param  iterable<array|object>  $rows
     * @return array{kes_total: float, by_currency: list<array{currency_code: string, amount: float, count: int, amount_kes: ?float}>, unconverted: list<string>}
     */
    public static function of(iterable $rows, string $amountKey, string $currencyKey = 'currency_code', ?string $rateKey = null): array
    {
        $by = [];
        foreach ($rows as $row) {
            $get    = fn (string $k) => is_array($row) ? ($row[$k] ?? null) : ($row->{$k} ?? null);
            $code   = strtoupper((string) ($get($currencyKey) ?: 'KES'));
            $amount = (float) ($get($amountKey) ?? 0);
            $rate   = $rateKey !== null && $get($rateKey) !== null ? (float) $get($rateKey) : null;
            $kes    = self::toKes($amount, $code, $rate);

            $by[$code] ??= ['currency_code' => $code, 'amount' => 0.0, 'count' => 0, 'amount_kes' => 0.0];
            $by[$code]['amount'] += $amount;
            $by[$code]['count']++;
            if ($kes === null || $by[$code]['amount_kes'] === null) {
                $by[$code]['amount_kes'] = null;
            } else {
                $by[$code]['amount_kes'] += $kes;
            }
        }
        ksort($by);

        $out = array_values(array_map(fn ($b) => [
            'currency_code' => $b['currency_code'],
            'amount'        => round($b['amount'], 2),
            'count'         => $b['count'],
            'amount_kes'    => $b['amount_kes'] === null ? null : round($b['amount_kes'], 2),
        ], $by));

        return [
            'kes_total'   => round(array_sum(array_map(fn ($b) => $b['amount_kes'] ?? 0.0, $out)), 2),
            'by_currency' => $out,
            'unconverted' => array_values(array_map(fn ($b) => $b['currency_code'], array_filter($out, fn ($b) => $b['amount_kes'] === null))),
        ];
    }

    /** One amount in KES: at the stored rate when the row carries one, else the live rate, else null. */
    public static function toKes(float $amount, string $currency, ?float $storedRate = null): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'KES') {
            return $amount;
        }
        if ($storedRate !== null && $storedRate > 0) {
            return $amount * $storedRate;
        }

        return ReportingCurrency::toKes($amount, $currency);
    }

    /** "KES 6,620.00 (KES 1,500.00 · USD 40.00)" for a mail or a Slack line. */
    public static function label(array $money): string
    {
        $parts = array_map(fn ($b) => $b['currency_code'] . ' ' . number_format($b['amount'], 2), $money['by_currency']);
        $kes   = 'KES ' . number_format($money['kes_total'], 2);
        if (count($money['by_currency']) <= 1 && ($money['by_currency'][0]['currency_code'] ?? 'KES') === 'KES') {
            return $kes;
        }
        $note = $money['unconverted'] ? ' — no reporting rate for ' . implode(', ', $money['unconverted']) : '';

        return $kes . ' (' . implode(' · ', $parts) . ')' . $note;
    }
}
