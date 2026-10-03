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
 * @return list<ChangeProposal> what was proposed (applied or waiting)
 */
final class PriceRowWriter
{
    public function __construct(private ProposalService $proposals) {}

    /**
     * @param  array{currency_code: string, regular_price: mixed, sale_price?: mixed, cost_price?: mixed, sale_start_date?: mixed, sale_end_date?: mixed}  $price
     * @param  bool  $withDates  the product endpoint sends the sale window; the variant endpoint does not
     * @return list<ChangeProposal>
     */
    public function write(int $productId, ?int $variantId, array $price, User $user, bool $withDates): array
    {
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
