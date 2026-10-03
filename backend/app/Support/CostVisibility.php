<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;

/**
 * What a product or a garment COSTS us is a need-to-know figure.
 *
 * The owner's rule (Role Hardening Plan, field rules): product cost and
 * production cost are visible to super_admin, admin, finance_manager and the
 * two procurement roles — the people who price, pay for and buy materials. Not
 * to an outlet manager, a cashier or a tailor. `products.view_cost` is that
 * rule as a permission; super_admin passes it through Gate::before.
 *
 * Cost reaches the admin API in two places a cost-blind role can open: the
 * product detail (price rows serialised whole, cost_price included) and every
 * bill of materials (material unit cost, line cost, total). Rather than a
 * second formatter per endpoint, the payload is built as before and the cost
 * keys are taken out on the way out for a viewer without the permission —
 * the same shape as StripsInternalCost on the public storefront.
 */
final class CostVisibility
{
    /**
     * Every key that carries a cost figure in the payloads this guards.
     * cost_per_unit / line_cost / total_cost are the BOM formatter's names;
     * unit_cost is the materials column as serialised whole (production
     * detail, task detail); the rest mirror StripsInternalCost.
     */
    private const KEYS = [
        'cost_price', 'unit_cost', 'cost_per_unit', 'line_cost', 'total_cost',
        'cost_amount', 'average_cost', 'last_cost',
    ];

    /** May this user see what things cost? */
    public static function allows(?User $user): bool
    {
        // Literal permission name, not a constant: permission:audit finds
        // enforcement by scanning for ->can('…'), and a constant would make
        // the permission look granted-but-enforced-nowhere.
        return $user !== null && $user->can('products.view_cost');
    }

    /** The payload unchanged for a cost viewer; cost keys removed otherwise. */
    public static function forViewer(mixed $payload, ?User $user): mixed
    {
        return self::allows($user) ? $payload : self::strip($payload);
    }

    /** Remove every cost key, at any depth. */
    public static function strip(mixed $value): mixed
    {
        // Payloads are built as arrays holding Eloquent models and collections
        // (price rows, BOM items). Flatten each to the array it would
        // serialise to, so a cost key inside one is reached too.
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                unset($value[$key]);
                continue;
            }
            $value[$key] = self::strip($item);
        }

        return $value;
    }
}
