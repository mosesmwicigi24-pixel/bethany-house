<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One answer to "what does this cost in <currency>", for every path that
 * writes money onto an order.
 *
 * Two rules, and the second one is why this class exists:
 *
 *   1. A price the shop typed into the hub for that currency wins, verbatim.
 *      A USD order carries the hub's USD price; a Zambian order carries the
 *      hub's kwacha price. Nothing is done arithmetically to a figure a human
 *      set on purpose. A row at 0.00 is NOT such a figure — it is a row
 *      created and never filled in. Reading one as a real price billed two
 *      live orders USD 0.00 for a tray, so rows at or below zero count as
 *      absent: the answer becomes a converted price or a refusal, and both of
 *      those are recoverable in a way that an invoice for nothing is not.
 *
 *   2. With no such row we convert — from the price list the currency is
 *      priced from (currencies.priced_from: the rand from USD, owner
 *      2026-10-29), else from the base-currency row — at the configured rate,
 *      exactly, never rounded — and when no configured rate exists we return NULL
 *      rather than a number. currencies.exchange_rate DEFAULTs to 1.0, so a
 *      non-base row still sitting at exactly 1.0 is an unconfigured row, not
 *      a peg (the same rule MetricEngine::internationalCorridor applies to
 *      reporting). Multiplying by that 1.0 is how a KES 4,500 tray became a
 *      USD 4,500 order: a 130x overcharge that reads as perfectly normal on
 *      screen, because only the label moved.
 *
 * Callers must handle the null — refuse the write and say which product needs
 * a price (or which currency needs a rate). Guessing is the failure mode this
 * class exists to remove.
 */
class CurrencyPricing
{
    /**
     * Base-relative rates by UPPER code, memoised because a POS page prices up
     * to 200 tiles in one request and each would otherwise re-read the table.
     * Statics die with the request under FPM; forget() covers the rest.
     */
    private static ?array $rates = null;

    /** UPPER code of the base currency, or null when no row claims the flag. */
    private static ?string $base = null;

    /** currency → the currency its prices are converted from (currencies.priced_from). */
    private static array $pricedFrom = [];

    /** Drop the memoised rate table — after a currency is edited, and in tests. */
    public static function forget(): void
    {
        self::$rates      = null;
        self::$base       = null;
        self::$pricedFrom = [];
    }

    /**
     * Configured base-relative rates, keyed by UPPER currency code.
     *
     * Semantics match Currency::convert and MetricEngine: base amount =
     * amount / rate. Rows that carry no real rate are absent from the map
     * entirely, which is what makes convert() able to say "I don't know".
     *
     * @return array<string, float>
     */
    public static function rates(): array
    {
        if (self::$rates !== null) {
            return self::$rates;
        }

        $rates = [];
        $base  = null;
        $from  = [];
        $hasPricedFrom = \Illuminate\Support\Facades\Schema::hasColumn('currencies', 'priced_from');

        foreach (DB::table('currencies')->get($hasPricedFrom ? ['code', 'exchange_rate', 'is_base', 'priced_from'] : ['code', 'exchange_rate', 'is_base']) as $row) {
            $code = strtoupper($row->code);
            $rate = (float) $row->exchange_rate;
            if ($hasPricedFrom && !empty($row->priced_from)) {
                $from[$code] = strtoupper((string) $row->priced_from);
            }

            if ($rate <= 0) {
                continue;
            }

            if ((bool) $row->is_base) {
                $base         = $code;
                $rates[$code] = $rate;
            } elseif (abs($rate - 1.0) > 1e-9) {
                // A non-base row at exactly 1.0 is the schema default, not a peg.
                $rates[$code] = $rate;
            }
        }

        // KES is the base by convention when no row claims the flag.
        if ($base === null) {
            $base          = 'KES';
            $rates['KES'] ??= 1.0;
        }

        self::$base       = $base;
        self::$pricedFrom = $from;

        return self::$rates = $rates;
    }

    /** The currency $currency's prices are converted from, or null for the base. */
    public static function pricedFrom(string $currency): ?string
    {
        self::rates();
        $from = self::$pricedFrom[strtoupper($currency)] ?? null;

        return $from && $from !== self::baseCode() ? $from : null;
    }

    /** UPPER code of the base currency (KES by convention). */
    public static function baseCode(): string
    {
        self::rates();

        return self::$base ?? 'KES';
    }

    /** True when a real rate is configured for this currency. */
    public static function hasRate(string $currency): bool
    {
        return isset(self::rates()[strtoupper($currency)]);
    }

    /**
     * Convert between currencies. NULL when either leg has no configured rate —
     * never a pass-through of the original number under a new label.
     */
    public static function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);

        if ($from === $to) {
            return round($amount, 2);
        }

        $rates = self::rates();
        if (!isset($rates[$from], $rates[$to])) {
            return null;
        }

        return round(($amount / $rates[$from]) * $rates[$to], 2);
    }

    /**
     * What these product_prices rows cost in $currency.
     *
     * Rows may be ProductPrice models or plain DB::table objects — only
     * currency_code, regular_price, sale_price and the sale window are read.
     *
     * @param  iterable<object>  $rows
     * @return array{currency_code: string, regular_price: float, sale_price: float|null, effective_price: float, converted_from: string|null}|null
     */
    public static function priceIn(iterable $rows, string $currency): ?array
    {
        $currency = strtoupper($currency);

        // A row at 0.00 is a row somebody created and never filled in, not a
        // decision to give the thing away. Reading it as a price billed two
        // orders USD 0.00 for a tray. Treat it as absent — the shop types a
        // price, or the order is refused, both of which are recoverable.
        $rows = collect($rows)->filter(fn ($r) => $r && (float) $r->regular_price > 0);

        // 1. The shop's own price for this currency, used as entered.
        $direct = $rows->first(fn ($r) => strtoupper((string) $r->currency_code) === $currency);
        if ($direct) {
            return self::shape($direct, $currency, null);
        }

        // 2. Otherwise convert — from the price list this currency is priced
        //    from (the rand from USD) when the product has a price there, else
        //    from the base row, else from any row whose currency carries a
        //    configured rate. Exact: no rounding beyond the cent.
        $rates       = self::rates();
        $base        = self::baseCode();
        $anchor      = self::pricedFrom($currency);
        $convertible = $rows->filter(fn ($r) => isset($rates[strtoupper((string) $r->currency_code)]));

        $source = ($anchor ? $convertible->first(fn ($r) => strtoupper((string) $r->currency_code) === $anchor) : null)
            ?? $convertible->first(fn ($r) => strtoupper((string) $r->currency_code) === $base)
            ?? $convertible->first();

        if (!$source) {
            return null;
        }

        return self::shape($source, $currency, strtoupper((string) $source->currency_code));
    }

    /**
     * priceIn() for a catalogue line, read straight from the database.
     * Variant lines price off the variant's rows; simple products off the
     * product-level rows (product_variant_id IS NULL).
     */
    public static function catalogue(?int $productId, ?int $variantId, string $currency): ?array
    {
        return self::priceIn(self::rowsFor($productId, $variantId), $currency);
    }

    /**
     * The product_prices rows that price one order line.
     *
     * @return Collection<int, object>
     */
    public static function rowsFor(?int $productId, ?int $variantId): Collection
    {
        if ($variantId) {
            return DB::table('product_prices')->where('product_variant_id', $variantId)->get();
        }

        if ($productId) {
            return DB::table('product_prices')
                ->where('product_id', $productId)
                ->whereNull('product_variant_id')
                ->get();
        }

        return collect();
    }

    /**
     * A one-line reason a currency could not be priced, phrased as the fix:
     * either the product needs a price row or the currency needs a rate.
     */
    public static function unpriceableReason(string $productName, string $currency): string
    {
        $currency = strtoupper($currency);

        return self::hasRate($currency)
            ? "\"{$productName}\" has no {$currency} price on the hub."
            : "\"{$productName}\" has no {$currency} price on the hub, and {$currency} has no exchange rate set.";
    }

    /**
     * @return array{currency_code: string, regular_price: float, sale_price: float|null, effective_price: float, converted_from: string|null}|null
     */
    private static function shape(object $row, string $currency, ?string $from): ?array
    {
        $regular = (float) $row->regular_price;
        $sale    = self::activeSalePrice($row);

        if ($from !== null) {
            $regular = self::convert($regular, $from, $currency);
            $sale    = $sale !== null ? self::convert($sale, $from, $currency) : null;

            if ($regular === null) {
                return null;
            }
        }

        return [
            'currency_code'   => $currency,
            'regular_price'   => round($regular, 2),
            'sale_price'      => $sale !== null ? round($sale, 2) : null,
            'effective_price' => round($sale ?? $regular, 2),
            'converted_from'  => $from,
        ];
    }

    /** The sale price only while its window is open — mirrors ProductPrice::isOnSale. */
    private static function activeSalePrice(object $row): ?float
    {
        $sale = $row->sale_price ?? null;
        if ($sale === null || (float) $sale <= 0) {
            return null;
        }

        // A "sale" at or above the regular price is not a sale, and this is the
        // method that decides what a customer is CHARGED — effective_price is
        // read straight from it. ProductPrice refuses to store such a row and a
        // migration cleared the ones that predate that rule; this is the floor
        // that means no row, however it arrived, can bill above list.
        if ((float) $sale >= (float) $row->regular_price) {
            return null;
        }

        $now   = now();
        $start = $row->sale_start_date ?? null;
        $end   = $row->sale_end_date   ?? null;

        if ($start && $now->lt(Carbon::parse($start)->startOfDay())) {
            return null;
        }

        // Inclusive of the day named on the label — see ProductPrice::isOnSale.
        if ($end && $now->gt(Carbon::parse($end)->endOfDay())) {
            return null;
        }

        return (float) $sale;
    }
}
