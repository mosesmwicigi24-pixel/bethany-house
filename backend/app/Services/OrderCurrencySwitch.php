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
    /**
     * $requested: a currency the customer asked to be billed in instead of
     * their country's (a Botswana customer who wants rand), with $reason. The
     * caller has already checked it is sellable and that a reason was given.
     */
    public static function toCountry(Order $order, string $countryCode, ?User $actor = null, string $source = 'set_country', ?string $requested = null, ?string $reason = null): array
    {
        $order->loadMissing('items');
        $countryCode     = strtoupper(trim($countryCode));
        $isInternational = SellableCurrency::isForeign($countryCode);
        $ruled           = SellableCurrency::forCustomer($countryCode);
        $requested       = $requested ? strtoupper(trim($requested)) : null;
        $newCurrency     = $requested ?: $ruled;
        // Any explicit choice is the customer's, even when it matches today's
        // rule: if South Africa later moves to the rand, a customer who asked
        // for dollars stays in dollars.
        $onRequest       = $requested !== null;
        $oldCurrency     = strtoupper((string) $order->currency_code);
        $oldCountryCode  = $order->customer_country_code;

        if ($why = self::moneyHasMoved($order)) {
            return ['ok' => false, 'changed' => false, 'code' => 'payment_started', 'currency_code' => $oldCurrency, 'is_international' => (bool) $order->is_international, 'message' => $why];
        }

        if ($oldCurrency === $newCurrency && $oldCountryCode === $countryCode) {
            if ($onRequest) {
                self::recordChoice($order, $newCurrency, $countryCode, $ruled, $reason, $actor);
            }

            return ['ok' => true, 'changed' => false, 'currency_code' => $newCurrency, 'is_international' => $isInternational];
        }

        // ── Reprice every line BEFORE writing anything ────────────────────────
        // A price row entered for the new currency wins; otherwise the base row
        // converted at the configured rate; otherwise — for a hand-priced line
        // the catalogue knows nothing about — the line's own price converted.
        // Where none of the three can be done the change is refused outright,
        // because keeping the old figure under a new label is exactly what
        // turned a KES 4,500 tray into a USD 4,500 order.
        [$repriced, $unpriceable] = self::reprice($order, $oldCurrency, $newCurrency);

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
            'on_request'       => $onRequest,
            'reason'           => $onRequest ? $reason : null,
        ], ($actor ? "{$actor->first_name} moved" : 'Moved') . " {$order->order_number} from {$oldCurrency} to {$newCurrency} ({$countryCode})"
            . ($onRequest ? " at the customer's request: {$reason}" : ''), $actor);

        return ['ok' => true, 'changed' => true, 'currency_code' => $newCurrency, 'is_international' => $isInternational,
            'new_total' => $plan['total'], 'old_currency' => $oldCurrency, 'old_total' => $oldTotal];
    }

    /**
     * What switching would make the order's total, without writing anything
     * (the repair's dry run shows it beside each order). Null when it can't
     * be priced in the customer's currency.
     *
     * @return array{currency_code: string, total: float}|null
     */
    public static function preview(Order $order, string $countryCode): ?array
    {
        $order->loadMissing('items');
        $old = strtoupper((string) $order->currency_code);
        $new = SellableCurrency::forCustomer($countryCode);
        [$repriced, $unpriceable] = self::reprice($order, $old, $new);
        $plan = $unpriceable ? null : OrderRepricer::plan($order, $repriced, $old, $new);

        return $plan ? ['currency_code' => $new, 'total' => (float) $plan['total']] : null;
    }

    /**
     * New unit prices in $new: the hub's price for that currency (its own row,
     * else converted from the list the currency is priced from), else the
     * line's own price converted. Lines that can do neither are named.
     *
     * @return array{0: array<int, float>, 1: string[]}
     */
    private static function reprice(Order $order, string $old, string $new): array
    {
        $repriced = []; $unpriceable = [];
        if ($old === $new) {
            return [$repriced, $unpriceable];
        }
        foreach ($order->items as $item) {
            $priced = CurrencyPricing::catalogue($item->product_id, $item->product_variant_id, $new);
            if ($priced) { $repriced[$item->id] = $priced['regular_price']; continue; }
            $converted = CurrencyPricing::convert((float) $item->unit_price, $old, $new);
            if ($converted === null) { $unpriceable[] = $item->product_name ?: "Item #{$item->id}"; continue; }
            $repriced[$item->id] = $converted;
        }

        return [$repriced, $unpriceable];
    }

    /**
     * Record that the customer chose this order's currency, so the link check
     * and the repair leave it (chosenOnPurpose). Used by the Set Country
     * dialog and the till when a currency is picked explicitly.
     */
    public static function recordChoice(Order $order, string $currency, ?string $countryCode, ?string $ruled, ?string $reason, ?User $actor = null): void
    {
        ActivityLogService::log('currency_kept', $order, [
            'currency_code' => $currency, 'country_code' => $countryCode, 'suggested_currency' => $ruled,
            'reason' => $reason, 'on_request' => true,
        ], "{$order->order_number} billed in {$currency} at the customer's choice" . ($reason ? ": {$reason}" : ''), $actor);
    }

    /**
     * Was this order's currency chosen on purpose — kept in KES with a reason
     * at the link, or billed in a currency the customer asked for? Only the
     * latest currency decision counts: a later switch by the rule undoes it.
     * The link check and the repair leave such an order alone.
     */
    public static function chosenOnPurpose(Order $order): bool
    {
        $last = DB::table('activity_log')
            ->where('subject_type', Order::class)->where('subject_id', $order->id)
            ->whereIn('event', ['currency_changed', 'currency_kept'])
            ->orderByDesc('id')->first(['event', 'properties']);
        if (!$last) {
            return false;
        }
        if ($last->event === 'currency_kept') {
            return true;
        }
        $props = json_decode((string) $last->properties, true) ?: [];

        return !empty($props['on_request']);
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
