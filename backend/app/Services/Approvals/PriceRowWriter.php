<?php

namespace App\Services\Approvals;

use App\Models\ChangeProposal;
use App\Models\ProductPrice;
use App\Models\User;

/**
 * How the product and variant edit endpoints write a price row (Phase 3C).
 *
 * A new row (a currency the product had no price in) is created as before —
 * creating a price is not changing one. On an existing row:
 *
 *   sale window dates            written directly, as before
 *   cost_price (KES)             a product_cost_change proposal — only for a
 *                                user holding products.edit_cost; for anyone
 *                                else the column is left exactly as it was
 *                                (the cost-blind rule the endpoints had)
 *   regular_price / sale_price   a selling_price_change proposal
 *
 * Cost goes first, so a price judged against cost is judged against the cost
 * that will stand. Each proposal applies at once inside the maker's band or
 * waits for signatures, leaving the live value untouched.
 *
 * Cost lives on the KES row only (one figure: COGS, CostBasis, the reports and
 * the cost bands all read it). A cost_price on any other currency's row in a
 * save payload is DROPPED, not refused: the console used to send one on every
 * row (null or a figure loaded from an old row), and the product_cost_change
 * handler refuses a non-KES row outright — so from Phase 3C on, every product
 * and variant save by a cost holder on a product with a USD/ZMW row failed
 * with "Cost is kept on the KES price row only." The direct proposal API keeps
 * that refusal; a save form just loses the stray field.
 *
 * @return list<ChangeProposal> what was proposed (applied or waiting)
 */
final class PriceRowWriter
{
    /** The one currency a cost is kept in. */
    public const COST_CURRENCY = 'KES';

    public function __construct(private ProposalService $proposals) {}

    /**
     * A save payload's price rows with cost_price removed from every row that
     * is not the KES row. For the create paths (product store, add variant),
     * which write rows directly, and for write() below.
     *
     * @param  list<array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    public static function withCostOnKesOnly(array $prices): array
    {
        return array_map(static function (array $price): array {
            if (strtoupper((string) ($price['currency_code'] ?? '')) !== self::COST_CURRENCY) {
                unset($price['cost_price']);
            }

            return $price;
        }, array_values($prices));
    }

    /**
     * @param  array{currency_code: string, regular_price: mixed, sale_price?: mixed, cost_price?: mixed, sale_start_date?: mixed, sale_end_date?: mixed}  $price
     * @param  bool  $withDates  the product endpoint sends the sale window; the variant endpoint does not
     * @return list<ChangeProposal>
     */
    public function write(int $productId, ?int $variantId, array $price, User $user, bool $withDates): array
    {
        $price = self::withCostOnKesOnly([$price])[0];

        $row = ProductPrice::where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $variantId))
            ->where('currency_code', $price['currency_code'])
            ->first();

        // Literal ->can(): permission:audit finds enforcement by scanning for it.
        $costWritable = $user->can('products.edit_cost');

        if (!$row) {
            ProductPrice::create([
                'product_id'         => $productId,
                'product_variant_id' => $variantId,
                'currency_code'      => $price['currency_code'],
                'regular_price'      => $price['regular_price'],
                'sale_price'         => $price['sale_price'] ?? null,
            ] + ($withDates ? [
                'sale_start_date' => $price['sale_start_date'] ?? null,
                'sale_end_date'   => $price['sale_end_date'] ?? null,
            ] : []) + ($costWritable ? ['cost_price' => $price['cost_price'] ?? null] : []));

            return [];
        }

        // The owner's 5% rule judges the person asking, before anything is
        // proposed: a markdown deeper than 5% is the super admin's alone, and
        // no signature can make it anyone else's (App\Support\DiscountRule).
        $askedSale = $price['sale_price'] ?? null;
        $askedSale = ($askedSale === null || !is_numeric($askedSale) || (float) $askedSale <= 0) ? null : (float) $askedSale;
        if (is_numeric($price['regular_price'] ?? null)
            && ($refusal = \App\Support\DiscountRule::salePriceRefusal(
                $user, (float) $price['regular_price'], $askedSale,
                $row->regular_price === null ? null : (float) $row->regular_price,
                $row->sale_price === null ? null : (float) $row->sale_price,
            ))) {
            throw new \App\Exceptions\DiscountAboveMaximum('sale_price', $refusal);
        }

        if ($withDates) {
            $row->sale_start_date = $price['sale_start_date'] ?? null;
            $row->sale_end_date   = $price['sale_end_date'] ?? null;
            if ($row->isDirty(['sale_start_date', 'sale_end_date'])) {
                $row->save();
            }
        }

        $out = [];
        if ($costWritable && array_key_exists('cost_price', $price)) {
            $p = $this->proposals->propose('product_cost_change', $row->id, ['cost_price' => $price['cost_price']], $user);
            if ($p) {
                $out[] = $p;
            }
        }

        $p = $this->proposals->propose('selling_price_change', $row->id, [
            'regular_price' => $price['regular_price'],
            'sale_price'    => $price['sale_price'] ?? null,
        ], $user);
        if ($p) {
            $out[] = $p;
        }

        return $out;
    }
}
