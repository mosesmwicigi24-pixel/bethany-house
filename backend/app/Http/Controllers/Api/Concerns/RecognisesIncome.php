<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Order;

/**
 * ONE definition of recognised income, for queries that are not Eloquent.
 *
 * `Order::scopeRecognised()` is the canonical rule and every Eloquent query
 * uses it. But several reports start from `DB::table('order_items')` and join
 * orders — a plain query builder, which has no scopes. Those queries therefore
 * hand-wrote the condition, or used `payment_status = 'paid'` because it was
 * one line instead of four, which is how the product and category breakdowns
 * came to answer a different question from the headline above them (D3).
 *
 * `ReportPdfController` had already worked this out and kept a private copy
 * called `recognisedOrders()`. This is that method, shared, so the rule has one
 * home whichever builder a report happens to start from.
 *
 * The rule itself (spec §revenue truth): an order is income when a human has
 * confirmed it OR money has arrived against it, and never when it is dead.
 * Both arms matter — a customer who pays a link before staff open the order
 * leaves it `pending` with the cash already banked.
 */
trait RecognisesIncome
{
    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $alias  the orders table's name or alias in this query
     */
    private function recognisedOrders($query, string $alias = 'orders')
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return $query
            ->whereNotIn("{$prefix}status", Order::DEAD_STATUSES)
            ->where(fn ($q) => $q->whereIn("{$prefix}status", Order::RECOGNISED_STATUSES)
                                 ->orWhereIn("{$prefix}payment_status", Order::SETTLED_PAYMENT_STATUSES));
    }
}
