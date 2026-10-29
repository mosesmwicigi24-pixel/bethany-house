<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\SellableCurrency;
use Illuminate\Support\Facades\DB;

/**
 * Move an unpaid order to the currency its customer's country is billed in
 * (SellableCurrency::forCustomer), repricing every line, the cart discount
 * and the shipping fee in one transaction. The order model re-stamps its
 * reporting rate on the currency change, so the books follow.
 *
 * ONE implementation for the Set Country / Currency button, the payment-link
 * check's "switch and get the link", and orders:foreign-currency (the repair
 * of foreign orders billed in shillings, owner 2026-10-29).
 *
 * Refused — nothing written — when money has started to move: a settled
 * payment, a payment awaiting review, or a payment request still in flight
 * (an M-Pesa push from the last half hour would land in the old currency).
 * Money collected in one currency is never restated in another.
 */
class OrderCurrencySwitch
{
    /** A payment request younger than this is still in flight. */
    public const IN_FLIGHT_MINUTES = 30;

    /**
     * @return array{ok: bool, changed: bool, currency_code: string, is_international: bool, new_total?: float, old_currency?: string, old_total?: float, message?: string, unpriceable_items?: string[], code?: string}
     */
    public static function toCountry(Order $order, string $countryCode, ?User $actor = null, string $source = 'set_country'): array
    {
        $order->loadMissing('items');
        $countryCode     = strtoupper(trim($countryCode));
        $isInternational = SellableCurrency::isForeign($countryCode);
        $newCurrency     = SellableCurrency::forCustomer($countryCode);
        $oldCurrency     = strtoupper((string) $order->currency_code);
        $oldCountryCode  = $order->customer_country_code;

        if ($why = self::moneyHasMoved($order)) {
            return ['ok' => false, 'changed' => false, 'code' => 'payment_started', 'currency_code' => $oldCurrency, 'is_international' => (bool) $order->is_international, 'message' => $why];
        }

        if ($oldCurrency === $newCurrency && $oldCountryCode === $countryCode) {
            return ['ok' => true, 'changed' => false, 'currency_code' => $newCurrency, 'is_international' => $isInternational];
        }

        // ── Reprice every line BEFORE writing anything ────────────────────────
        // A price row entered for the new currency wins; otherwise the base row
        // converted at the configured rate; otherwise — for a hand-priced line
        // the catalogue knows nothing about — the line's own price converted.
        // Where none of the three can be done the change is refused outright,
        // because keeping the old figure under a new label is exactly what
        // turned a KES 4,500 tray into a USD 4,500 order.
        $repriced = []; $unpriceable = [];
        if ($oldCurrency !== $newCurrency) {
            foreach ($order->items as $item) {
                $priced = CurrencyPricing::catalogue($item->product_id, $item->product_variant_id, $newCurrency);
                if ($priced) { $repriced[$item->id] = $priced['regular_price']; continue; }
                $converted = CurrencyPricing::convert((float) $item->unit_price, $oldCurrency, $newCurrency);
                if ($converted === null) { $unpriceable[] = $item->product_name ?: "Item #{$item->id}"; continue; }
                $repriced[$item->id] = $converted;
            }
        }

        if ($unpriceable) {
            $names = implode(', ', array_unique($unpriceable));

            return ['ok' => false, 'changed' => false, 'code' => 'unpriceable', 'currency_code' => $oldCurrency, 'is_international' => (bool) $order->is_international,
                'unpriceable_items' => array_values(array_unique($unpriceable)),
                'message' => "Cannot switch this order to {$newCurrency}: no {$newCurrency} price on the hub for {$names}"
                    . (CurrencyPricing::hasRate($newCurrency) ? '. Set one on the product, then try again.' : ", and {$newCurrency} has no exchange rate set. Set a {$newCurrency} price or rate, then try again.")];
        }

        // The cart-level discount and the shipping fee are figures in the OLD
        // currency too. A plan of null means one of them cannot cross.
        $plan = OrderRepricer::plan($order, $repriced, $oldCurrency, $newCurrency);
        if ($plan === null) {
            return ['ok' => false, 'changed' => false, 'code' => 'unpriceable', 'currency_code' => $oldCurrency, 'is_international' => (bool) $order->is_international,
                'message' => "Cannot switch this order to {$newCurrency}: its shipping or discount amount cannot be converted from {$oldCurrency}. Set a {$newCurrency} exchange rate, then try again."];
        }

        $oldTotal = (float) $order->total_amount;
        // Re-check under a row lock: a payment that arrived while we priced wins,
        // and the refusal comes back as a result, never as an exception's text.
        $lateRefusal = DB::transaction(function () use ($order, $plan, $newCurrency, $countryCode, $isInternational) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($locked && ($why = self::moneyHasMoved($locked))) {
                return $why;
            }
            OrderRepricer::apply($order, $plan, [
                'currency_code'         => $newCurrency,
                'customer_country_code' => $countryCode,
                'is_international'      => $isInternational,
            ]);

            return null;
        });
        if ($lateRefusal !== null) {
            return ['ok' => false, 'changed' => false, 'code' => 'payment_started', 'currency_code' => $oldCurrency, 'is_international' => (bool) $order->is_international, 'message' => $lateRefusal];
        }

        ActivityLogService::log('currency_changed', $order, [
            'old_currency'     => $oldCurrency,
            'new_currency'     => $newCurrency,
            'old_country_code' => $oldCountryCode,
            'new_country_code' => $countryCode,
            'is_international' => $isInternational,
            'old_total'        => $oldTotal,
            'new_total'        => $plan['total'],
            'source'           => $source,
            'actor_id'         => $actor?->id,
        ], ($actor ? "{$actor->first_name} moved" : 'Moved') . " {$order->order_number} from {$oldCurrency} to {$newCurrency} ({$countryCode})", $actor);

        return ['ok' => true, 'changed' => true, 'currency_code' => $newCurrency, 'is_international' => $isInternational,
            'new_total' => $plan['total'], 'old_currency' => $oldCurrency, 'old_total' => $oldTotal];
    }

    /** Why this order's currency may no longer change, or null when it may. */
    public static function moneyHasMoved(Order $order): ?string
    {
        if ($order->payment_status !== 'pending') {
            return 'Cannot change currency after payment has started.';
        }
        $moving = DB::table('payments')->where('order_id', $order->id)
            ->where(function ($q) {
                $q->whereIn('status', ['paid', 'processing', 'confirmed'])
                  ->orWhere(fn ($p) => $p->where('status', 'pending')->where('created_at', '>', now()->subMinutes(self::IN_FLIGHT_MINUTES)));
            })->exists();

        return $moving ? 'Cannot change currency: a payment on this order is recorded, awaiting review or still in progress.' : null;
    }
}
