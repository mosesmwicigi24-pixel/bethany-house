<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Order;
use App\Support\ReportInput;

/**
 * The orders a report counts: recognised income, in the report's outlet.
 *
 * Recognised = not dead, and either confirmed by a person or paid. Outlet =
 * the report's `outlet_id` filter, when one is chosen (reports build,
 * 2026-10-01). Reports are business-wide (owner, 2026-09-30); the outlet is a
 * FILTER, applied here at the query layer so a headline and the table under it
 * can never answer for different shops. Before this, the sales summary
 * honoured the outlet while sales by product, category and customer, the
 * customer pages and the P&L ignored it — one page, two scopes.
 *
 * Used only by ReportController; queries that do not start from orders apply
 * inOutlet() to their own outlet column.
 */
trait RecognisesIncome
{
    private function recognisedOrders($query, string $alias = 'orders')
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return $this->inOutlet($query
            ->whereNotIn("{$prefix}status", Order::DEAD_STATUSES)
            ->where(fn ($q) => $q->whereIn("{$prefix}status", Order::RECOGNISED_STATUSES)
                                 ->orWhereIn("{$prefix}payment_status", Order::SETTLED_PAYMENT_STATUSES)),
            "{$prefix}outlet_id");
    }

    /** The outlet this report is filtered to, or null for the whole business (validated at report.window). */
    private function reportOutletId(): ?int
    {
        return ReportInput::outletId(request()->query('outlet_id'));
    }

    /** Narrow a query to the report's outlet on its own outlet column; a no-op with no outlet chosen. */
    private function inOutlet($query, string $column)
    {
        $id = $this->reportOutletId();

        return $id ? $query->where($column, $id) : $query;
    }
}
