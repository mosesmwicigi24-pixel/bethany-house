<?php

namespace App\Services\Procurement;

use App\Models\Inventory;
use App\Models\Material;
use App\Models\PurchaseOrderItem;
use App\Support\ReportingCurrency;

/**
 * An item's latest cost, set from the price paid on a purchase order.
 *
 * The stored costs (materials.unit_cost, legacy inventories.cost_per_unit) are
 * shillings: BOM costing, material valuation and production cost reports read
 * them as KES. A purchase order is priced in the supplier's currency, so the
 * price is converted at the REPORTING rate (ReportingCurrency, 128 KES/USD),
 * never the customer pricing rate. A currency with no reporting rate leaves
 * the cost as it was: never a guess.
 *
 * Receiving and the owner's correction of a received line both go through
 * here, so the two can't drift apart.
 */
class LatestCost
{
    /** The price in KES, rounded to the cent; null when the currency has no reporting rate. */
    public static function kes(float $price, ?string $currency): ?float
    {
        $code = strtoupper((string) ($currency ?: 'KES'));
        $kes  = $code === 'KES' ? $price : ReportingCurrency::toKes($price, $code);

        return $kes === null ? null : round($kes, 2);
    }

    /** Write the KES cost onto the line's item. */
    public static function set(PurchaseOrderItem $line, float $kes): void
    {
        if ($line->item_type === 'product') {
            Inventory::where('inventory_type', 'product')
                ->where('product_id', $line->product_id)
                ->when($line->product_variant_id, fn ($q) => $q->where('product_variant_id', $line->product_variant_id))
                ->update(['cost_per_unit' => $kes, 'updated_at' => now()]);
        } else {
            Material::whereKey($line->material_id)->update(['unit_cost' => $kes]);
        }
    }

    public static function noRateNote(PurchaseOrderItem $line, string $currency): string
    {
        return "{$line->description}: no reporting rate for " . strtoupper($currency) . ', so the stock cost was not changed.';
    }
}
