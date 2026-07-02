<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * TaxCalculationService
 *
 * Centralises all tax calculation logic for the platform.
 *
 * Two modes, driven by the global setting 'tax_inclusive':
 *
 *   tax_inclusive = TRUE  → prices already include VAT.
 *     - The tax_amount on each line is EXTRACTED from the price:
 *       tax = price × rate ÷ (1 + rate)
 *     - The line subtotal stays the same (consumer sees no change).
 *     - Tax is recorded for reporting/reconciliation only.
 *
 *   tax_inclusive = FALSE → prices are net (excluding VAT).
 *     - The tax_amount is ADDED ON TOP:
 *       tax = price × rate
 *     - The line subtotal is GROSSED UP: subtotal += tax.
 *     - Consumer pays price + tax.
 *
 * Per-product tax rates are looked up from the product_tax_rates pivot.
 * If a product has no assigned rate, the global default rate is used.
 * If no default rate exists, no tax is applied (rate = 0).
 */
class TaxCalculationService
{
    // ── Settings helpers ──────────────────────────────────────────────────────

    /**
     * Whether global pricing is tax-inclusive.
     * Cached for 5 minutes to avoid repeated DB reads per request.
     */
    public static function isTaxInclusive(): bool
    {
        return Cache::remember('setting_tax_inclusive', 300, function () {
            $val = DB::table('settings')->where('key', 'tax_inclusive')->value('value');
            return filter_var($val ?? '0', FILTER_VALIDATE_BOOLEAN);
        });
    }

    /**
     * Returns the default tax rate (percentage, e.g. 16.0 for 16%).
     * 0 if no default rate is configured.
     */
    public static function defaultRate(): float
    {
        return Cache::remember('setting_default_tax_rate', 300, function () {
            // Check for a rate marked is_default = true
            $defaultRate = DB::table('tax_rates')
                ->where('is_active', true)
                ->where('is_default', true)
                ->value('rate');

            if ($defaultRate !== null) {
                return (float) $defaultRate;
            }

            // Fall back to the settings key if it exists
            $settingsRate = DB::table('settings')->where('key', 'default_tax_rate')->value('value');
            return $settingsRate !== null ? (float) $settingsRate : 0.0;
        });
    }

    // ── Rate resolution ───────────────────────────────────────────────────────

    /**
     * Return the combined applicable tax rate (as a decimal, e.g. 0.16) for a product.
     * Looks up the product_tax_rates pivot; falls back to the global default.
     */
    public static function rateForProduct(int $productId): float
    {
        $rates = Cache::remember("tax_rates_product_{$productId}", 300, function () use ($productId) {
            return DB::table('product_tax_rates as ptr')
                ->join('tax_rates as tr', 'ptr.tax_rate_id', '=', 'tr.id')
                ->where('ptr.product_id', $productId)
                ->where('tr.is_active', true)
                ->pluck('tr.rate')
                ->toArray();
        });

        if (empty($rates)) {
            // No product-specific rates - use the global default
            return self::defaultRate() / 100;
        }

        // Sum all applicable rates (e.g. VAT 16% + Tourism levy 2% = 18%)
        return array_sum($rates) / 100;
    }

    /**
     * Return tax rate details (id, name, rate, code) for a product - used for receipts.
     */
    public static function rateDetailsForProduct(int $productId): array
    {
        $rates = DB::table('product_tax_rates as ptr')
            ->join('tax_rates as tr', 'ptr.tax_rate_id', '=', 'tr.id')
            ->where('ptr.product_id', $productId)
            ->where('tr.is_active', true)
            ->select('tr.id', 'tr.name', 'tr.rate', 'tr.code')
            ->get()
            ->toArray();

        if (empty($rates)) {
            // Return global default
            $defaultRate = DB::table('tax_rates')
                ->where('is_active', true)
                ->where('is_default', true)
                ->first();
            return $defaultRate ? [$defaultRate] : [];
        }

        return $rates;
    }

    // ── Line-item calculation ─────────────────────────────────────────────────

    /**
     * Calculate tax for a single line item.
     *
     * @param  float  $unitPrice   Price per unit (as stored in the system).
     * @param  int    $quantity
     * @param  int    $productId   Used to look up the applicable tax rate(s).
     * @param  bool|null $taxInclusive  Override; null = use global setting.
     * @return array{
     *   unit_price: float,
     *   quantity: int,
     *   subtotal_net: float,
     *   tax_rate: float,
     *   tax_amount: float,
     *   subtotal_gross: float,
     *   tax_inclusive: bool,
     * }
     */
    public static function calculateLine(
        float $unitPrice,
        int $quantity,
        int $productId,
        ?bool $taxInclusive = null
    ): array {
        $inclusive  = $taxInclusive ?? self::isTaxInclusive();
        $rate       = self::rateForProduct($productId);
        $lineTotal  = $unitPrice * $quantity;

        if ($inclusive) {
            // Extract tax from the already-inclusive price
            $taxAmount    = $rate > 0
                ? round($lineTotal - ($lineTotal / (1 + $rate)), 4)
                : 0.0;
            $subtotalNet  = round($lineTotal - $taxAmount, 4);
            $subtotalGross = $lineTotal;
        } else {
            // Add tax on top
            $subtotalNet   = $lineTotal;
            $taxAmount     = round($lineTotal * $rate, 4);
            $subtotalGross = round($lineTotal + $taxAmount, 4);
        }

        return [
            'unit_price'     => $unitPrice,
            'quantity'       => $quantity,
            'subtotal_net'   => $subtotalNet,
            'tax_rate'       => $rate,
            'tax_amount'     => $taxAmount,
            'subtotal_gross' => $subtotalGross,
            'tax_inclusive'  => $inclusive,
        ];
    }

    // ── Order-level calculation ───────────────────────────────────────────────

    /**
     * Calculate tax totals across a list of line items.
     *
     * Input: array of ['product_id', 'unit_price', 'quantity', 'discount_amount']
     * Output: [
     *   'lines'           => [...per-line results from calculateLine()],
     *   'subtotal'        => float,   // sum of net subtotals
     *   'total_tax'       => float,   // sum of all line tax_amounts
     *   'total_gross'     => float,   // subtotal + tax (or equal to subtotal if inclusive)
     *   'tax_inclusive'   => bool,
     *   'tax_breakdown'   => [ ['name' => 'VAT', 'rate' => 0.16, 'amount' => 480.00], ... ]
     * ]
     */
    public static function calculateOrder(array $lines, ?bool $taxInclusive = null): array
    {
        $inclusive   = $taxInclusive ?? self::isTaxInclusive();
        $results     = [];
        $subtotal    = 0.0;
        $totalTax    = 0.0;
        $totalGross  = 0.0;
        $taxByRate   = [];

        foreach ($lines as $line) {
            $productId     = (int) ($line['product_id'] ?? 0);
            $unitPrice     = (float) ($line['unit_price'] ?? 0);
            $qty           = (int) ($line['quantity'] ?? 1);
            $discount      = (float) ($line['discount_amount'] ?? 0);
            $effectivePrice = max(0, $unitPrice - ($discount / max($qty, 1)));

            $calc = self::calculateLine($effectivePrice, $qty, $productId, $inclusive);
            $calc['discount_amount'] = $discount;

            $results[]  = $calc;
            $subtotal  += $calc['subtotal_net'];
            $totalTax  += $calc['tax_amount'];
            $totalGross += $calc['subtotal_gross'];

            // Accumulate for breakdown display
            $rateKey = number_format($calc['tax_rate'], 6);
            if (!isset($taxByRate[$rateKey])) {
                $taxByRate[$rateKey] = [
                    'rate'   => $calc['tax_rate'],
                    'amount' => 0.0,
                    'label'  => self::rateLabelForProduct($productId),
                ];
            }
            $taxByRate[$rateKey]['amount'] += $calc['tax_amount'];
        }

        return [
            'lines'         => $results,
            'subtotal'      => round($subtotal, 2),
            'total_tax'     => round($totalTax, 2),
            'total_gross'   => round($totalGross, 2),
            'tax_inclusive' => $inclusive,
            'tax_breakdown' => array_values($taxByRate),
        ];
    }

    // ── Cache invalidation ────────────────────────────────────────────────────

    /**
     * Call this whenever a tax rate is attached/detached from a product.
     */
    public static function invalidateProductCache(int $productId): void
    {
        Cache::forget("tax_rates_product_{$productId}");
    }

    /**
     * Call when the default tax rate or tax_inclusive setting changes.
     */
    public static function invalidateGlobalCache(): void
    {
        Cache::forget('setting_tax_inclusive');
        Cache::forget('setting_default_tax_rate');
        Cache::forget('app_settings'); // also bust the settings cache
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function rateLabelForProduct(int $productId): string
    {
        $names = DB::table('product_tax_rates as ptr')
            ->join('tax_rates as tr', 'ptr.tax_rate_id', '=', 'tr.id')
            ->where('ptr.product_id', $productId)
            ->where('tr.is_active', true)
            ->pluck('tr.name')
            ->implode(' + ');

        return $names ?: 'Tax';
    }
}