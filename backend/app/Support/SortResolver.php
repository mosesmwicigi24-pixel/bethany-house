<?php

namespace App\Support;

/**
 * Resolves a client-supplied sort column + direction against a strict
 * allow-list.
 *
 * Column identifiers cannot be bound as query parameters the way values can,
 * so passing a raw request value straight into `orderBy()` is a SQL-injection
 * vector. Any column not in `$allowed` collapses to `$default`, and the
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
