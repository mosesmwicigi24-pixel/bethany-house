<?php

namespace App\Support;

use App\Models\ProductPrice;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;

/**
 * A product's variants are sold at the product's price (owner, 2026-11-26).
 *
 * A variant line is priced from the variant's OWN price rows
 * (CurrencyPricing::rowsFor), and those rows were a copy taken when the
 * variant was made. Nothing kept them in step, so raising a dress to
 * KES 13,000 on its Pricing tab left every colour and size selling at the
 * old 10,000 — at the till, in chat and on the website.
 *
 * The rule now: the product-level row is the master. For each currency, every
 * variant's row carries the product row's regular price, sale price and sale
 * window. The sale price when one is set, otherwise the regular price — the
 * same effective price everywhere.
 *
 * COST IS NEVER TOUCHED: a variant's cost_price feeds COGS and the cost
 * bands, and stays whatever it is.
 *
 * The values copied are a row that has ALREADY passed the price rules (sale
 * below regular, the 5% markdown rule, the approval bands), so they are
 * written as they stand; each change is on the audit trail with the old
 * figures, which is also how it is undone.
 */
final class VariantPriceSync
{
    private const FOLLOWED = ['regular_price', 'sale_price', 'sale_start_date', 'sale_end_date'];

    /**
     * Bring every variant's row in this currency to the product-level row.
     * A no-op for a variant row, a product with no variants, or rows already
     * in step. A variant with no row in the currency is given one unless
     * $createMissing is false. Returns how many variant rows were written.
     */
    public static function follow(ProductPrice $productRow, ?User $by = null, string $via = 'price_change', bool $createMissing = true): int
    {
        if ($productRow->product_variant_id !== null) {
            return 0;
        }

        $variantIds = DB::table('product_variants')
            ->where('product_id', $productRow->product_id)
            ->orderBy('id')->pluck('id');
        if ($variantIds->isEmpty()) {
            return 0;
        }

        $currency = (string) $productRow->currency_code;
        $target   = self::figures($productRow);
        $existing = DB::table('product_prices')
            ->where('product_id', $productRow->product_id)
            ->whereIn('product_variant_id', $variantIds)
            ->where('currency_code', $currency)
            ->lockForUpdate()->get()->keyBy('product_variant_id');

        $changes = [];
        foreach ($variantIds as $variantId) {
            $row = $existing->get($variantId);

            if (!$row) {
                // The model's own hook only keeps existing rows in step: a
                // caller that is about to write the variant's row itself
                // (a variant being created) must not find one already there.
                if (!$createMissing) {
                    continue;
                }
                DB::table('product_prices')->insert($target + [
                    'product_id'         => $productRow->product_id,
                    'product_variant_id' => $variantId,
                    'currency_code'      => $currency,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
                $changes[] = ['variant_id' => (int) $variantId, 'before' => null, 'after' => $target];
                continue;
            }

            $before = self::figures($row);
            if ($before === $target) {
                continue;
            }

            DB::table('product_prices')->where('id', $row->id)->update($target + ['updated_at' => now()]);
            $changes[] = ['variant_id' => (int) $variantId, 'before' => $before, 'after' => $target];
        }

        if ($changes !== []) {
            ActivityLogService::log('variant_prices_followed_product', $productRow->product, [
                'product_id' => (int) $productRow->product_id,
                'currency'   => $currency,
                'via'        => $via,
                'product'    => $target,
                'variants'   => $changes,
            ], count($changes) . " variant price(s) set to the product's {$currency} price", $by);
        }

        return count($changes);
    }

    /** Every currency the product is priced in. */
    public static function followProduct(int $productId, ?User $by = null, string $via = 'price_change'): int
    {
        return (int) ProductPrice::where('product_id', $productId)->whereNull('product_variant_id')
            ->orderBy('id')->get()
            ->sum(fn (ProductPrice $row) => self::follow($row, $by, $via));
    }

    /**
     * Variant rows that are NOT at their product's price, with both figures —
     * what the alignment would change. Read-only.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public static function outOfStep()
    {
        return DB::table('product_prices as p')
            ->join('product_variants as v', 'v.product_id', '=', 'p.product_id')
            ->join('products as pr', fn ($j) => $j->on('pr.id', '=', 'p.product_id')->whereNull('pr.deleted_at'))
            ->leftJoin('product_prices as vp', fn ($j) => $j->on('vp.product_variant_id', '=', 'v.id')
                ->on('vp.currency_code', '=', 'p.currency_code'))
            ->whereNull('p.product_variant_id')
            ->where(function ($q) {
                $q->whereNull('vp.id')
                  ->orWhereColumn('vp.regular_price', '<>', 'p.regular_price')
                  ->orWhereRaw('vp.sale_price IS DISTINCT FROM p.sale_price')
                  ->orWhereRaw('vp.sale_start_date IS DISTINCT FROM p.sale_start_date')
                  ->orWhereRaw('vp.sale_end_date IS DISTINCT FROM p.sale_end_date');
            })
            ->orderBy('pr.sku')->orderBy('v.sku')->orderBy('p.currency_code')
            ->get([
                'p.product_id', 'pr.sku as product_sku', 'v.id as variant_id', 'v.sku as variant_sku',
                'v.variant_name', 'p.currency_code',
                'p.regular_price as product_regular', 'p.sale_price as product_sale',
                'vp.regular_price as variant_regular', 'vp.sale_price as variant_sale',
            ]);
    }

    /** The four followed columns of a row, as they are stored. */
    private static function figures(object $row): array
    {
        $day = fn ($v) => $v === null ? null : substr((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), 0, 10);

        return [
            'regular_price'   => number_format((float) $row->regular_price, 2, '.', ''),
            'sale_price'      => $row->sale_price === null ? null : number_format((float) $row->sale_price, 2, '.', ''),
            'sale_start_date' => $day($row->sale_start_date ?? null),
            'sale_end_date'   => $day($row->sale_end_date ?? null),
        ];
    }
}
