<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Recompute an order's lines and totals from a set of new unit prices.
 *
 * One implementation, because there are two callers with the same arithmetic
 * and no reason for them to drift: the Set Country / Currency endpoint, and
 * the backfill that repairs orders already written with a mislabelled price.
 *
 * Line tax is re-derived rather than scaled — the rate (16% VAT) is a property
 * of the product, not of the old figure. Lines with no product fall back to the
 * rate implied by what is already on the line, which is the only thing left to
 * go on.
 *
 * Money attached to the ORDER rather than a line — the cart-level discount and
 * shipping — is converted when the currency changes, and refused when it can't
 * be. Leaving a KES shipping fee sitting under a USD total is the same defect
 * as the line prices, one field along.
 */
class OrderRepricer
{
    /**
     * What repricing this order would do, without writing.
     *
     * @param  array<int, float>  $unitPrices  order_item id → new unit price, in $to.
     *                                         Lines absent from the map keep theirs.
     * @return array{lines: array<int, array{unit_price: float, tax_amount: float, total_price: float}>, subtotal: float, tax_amount: float, discount_amount: float, shipping_amount: float, total: float}|null
     *         Null when an order-level figure cannot be carried into $to.
     */
    public static function plan(Order $order, array $unitPrices, string $from, string $to): ?array
    {
        $taxInclusive = (bool) $order->prices_include_tax;
        $lines        = [];
        $subtotal     = 0.0;
        $taxTotal     = 0.0;

        foreach ($order->items as $item) {
            $unitPrice = array_key_exists($item->id, $unitPrices)
                ? (float) $unitPrices[$item->id]
                : (float) $item->unit_price;

            // A line discount is a figure in the OLD currency when the currency
            // moves; carry it across the same way the unit price travelled.
            $lineDiscount = (float) $item->discount_amount;
            if ($lineDiscount > 0 && $from !== $to) {
                $converted = CurrencyPricing::convert($lineDiscount, $from, $to);
                if ($converted === null) {
                    return null;
                }
                $lineDiscount = $converted;
            }

            $lineSubtotal    = max(0, ($unitPrice * (int) $item->quantity) - $lineDiscount);
            $oldLineSubtotal = max(0, ((float) $item->unit_price * (int) $item->quantity) - (float) $item->discount_amount);

            if (!empty($item->product_id)) {
                $rate     = TaxCalculationService::rateForProduct($item->product_id);
                $lineTax  = $taxInclusive
                    ? round($lineSubtotal * $rate / (1 + $rate), 4)
                    : round($lineSubtotal * $rate, 4);
            } elseif ($oldLineSubtotal > 0 && (float) $item->tax_amount > 0) {
                // No product to ask — keep the rate the line already carries.
                $lineTax = round($lineSubtotal * ((float) $item->tax_amount / $oldLineSubtotal), 4);
            } else {
                $lineTax = 0.0;
            }

            $lines[$item->id] = [
                'unit_price'      => round($unitPrice, 2),
                'discount_amount' => round($lineDiscount, 2),
                'tax_amount'      => $lineTax,
                // Tax-inclusive: the subtotal already carries the tax, so
                // adding it again would double it. This mirrors how the line
                // was written at creation (PosController::createPendingOrder).
                'total_price'     => round($taxInclusive ? $lineSubtotal : $lineSubtotal + $lineTax, 2),
            ];

            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
        }

        $discount = (float) $order->discount_amount;
        $shipping = (float) $order->shipping_amount;

        if ($from !== $to) {
            $discount = $discount > 0 ? CurrencyPricing::convert($discount, $from, $to) : 0.0;
            $shipping = $shipping > 0 ? CurrencyPricing::convert($shipping, $from, $to) : 0.0;

            if ($discount === null || $shipping === null) {
                return null;
            }
        }

        return [
            'lines'           => $lines,
            'subtotal'        => round($subtotal, 2),
            'tax_amount'      => round($taxTotal, 2),
            'discount_amount' => round($discount, 2),
            'shipping_amount' => round($shipping, 2),
            'total'           => round(
                $subtotal - $discount + ($taxInclusive ? 0 : $taxTotal) + $shipping,
                2
            ),
        ];
    }

    /**
     * Write a plan. Caller owns the transaction; $extra carries any non-money
     * columns the caller is changing in the same breath (currency, country).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function apply(Order $order, array $plan, array $extra = []): void
    {
        foreach ($plan['lines'] as $itemId => $line) {
            DB::table('order_items')->where('id', $itemId)->update([
                'unit_price'      => $line['unit_price'],
                'discount_amount' => $line['discount_amount'],
                'tax_amount'      => $line['tax_amount'],
                'total_price'     => $line['total_price'],
                'updated_at'      => now(),
            ]);
        }

        $order->update($extra + [
            'subtotal'        => $plan['subtotal'],
            'discount_amount' => $plan['discount_amount'],
            'tax_amount'      => $plan['tax_amount'],
            'shipping_amount' => $plan['shipping_amount'],
            'total_amount'    => $plan['total'],
        ]);
    }
}
