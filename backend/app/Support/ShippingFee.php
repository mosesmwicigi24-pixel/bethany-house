<?php

namespace App\Support;

use App\Models\ShippingMethod;
use App\Services\CurrencyPricing;
use Illuminate\Validation\ValidationException;

/**
 * A shipping method's fee in the ORDER's currency.
 *
 * Shipping methods are set up in shillings ("Flat Rate (KES)"). Until now the
 * KES figure was added straight into a USD or ZMW cart, so a KES 500 courier
 * fee became USD 500. A flat fee is converted at the customer PRICING rate —
 * the rate the customer's goods are priced at, so the fee sits beside them on
 * the same basis — and a percentage fee needs no conversion. A currency the
 * pricing rates can't reach is refused rather than guessed.
 */
final class ShippingFee
{
    /** @return float the fee in $currency, a whole unit (CustomerMoney) */
    public static function inCurrency(ShippingMethod $method, float $goodsTotal, string $currency): float
    {
        $currency = strtoupper($currency);
        $kesFee   = $method->calculateCost($goodsTotal);

        if ($method->cost_type === 'percentage' || $kesFee <= 0) {
            return CustomerMoney::whole($kesFee);
        }

        $base = CurrencyPricing::baseCode();
        if ($currency === $base) {
            return CustomerMoney::whole($kesFee);
        }

        $converted = CurrencyPricing::convert($kesFee, $base, $currency);
        if ($converted === null) {
            throw ValidationException::withMessages(['shipping_method_id' => [
                "{$method->name} is priced in {$base} and there is no pricing rate to state it in {$currency}.",
            ]]);
        }

        return CustomerMoney::whole($converted);
    }
}
