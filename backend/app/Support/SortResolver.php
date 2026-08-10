<?php

namespace App\Support;

/**
 * Resolves a client-supplied sort column + direction against a strict
 * allow-list.
 *
 * The risk here is availability and information disclosure, not injection:
 * the query builder's grammar wraps identifiers (doubling any inner quote)
 * and rejects a direction that isn't literally 'asc' or 'desc' with an
 * InvalidArgumentException, so a raw request value cannot break out of the
 * ORDER BY clause. What it can do is name a column that does not exist —
 * the database errors, the request 500s, and the difference between a 200
 * and a 500 tells an unauthenticated caller exactly which columns a table
 * has. That enumeration matters most on public routes such as
 * `GET /{id}/reviews`.
 *
 * So: any column not in `$allowed` collapses to `$default`, and the
 * direction is normalised to a literal 'asc' or 'desc'.
 */
class SortResolver
{
    /**
     * @param  array<int,string>  $allowed  Whitelisted, sortable column names.
     * @param  string  $default  Fallback column when the request value is not allowed.
     * @return array{0:string,1:string}  [column, direction] — direction is 'asc' or 'desc'.
     */
    public static function resolve(?string $column, ?string $direction, array $allowed, string $default): array
    {
        $col = in_array($column, $allowed, true) ? $column : $default;
        $dir = strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        return [$col, $dir];
    }
}
