<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * The rules every customer search obeys (Phase 4A anti-scraping, plan §9).
 *
 * A search box over the customer book is a list of phone numbers to anyone
 * patient enough to type "07", then "071", then "072"… So:
 *
 *   - at least MIN real characters (letters or digits), never wildcard-only;
 *   - "%" and "_" are searched for literally, never as patterns — "%%%"
 *     would otherwise be "everyone";
 *   - at most MAX_RESULTS rows;
 *   - PER_MINUTE searches a minute per user, one bucket across every door
 *     (the `customer-search` rate limiter, RouteServiceProvider);
 *   - the caller's scope is applied BEFORE matching (Customer::searchableBy),
 *     so the match never runs over people the caller cannot see.
 */
final class CustomerSearch
{
    public const MIN         = 3;
    public const MAX_RESULTS = 20;
    public const PER_MINUTE  = 30;

    /** Validate and return the trimmed term, or throw a 422 on $field. */
    public static function term(?string $q, string $field = 'q'): string
    {
        $term = trim((string) $q);
        $real = preg_replace('/[\s%_*?]+/u', '', $term) ?? '';

        if (mb_strlen($real) < self::MIN) {
            throw ValidationException::withMessages([
                $field => ['Type at least ' . self::MIN . ' letters or digits to search for a customer.'],
            ]);
        }

        return $term;
    }

    /** Escape LIKE metacharacters so they match themselves (Postgres' default escape is "\"). */
    public static function escape(string $term): string
    {
        return addcslashes($term, '\\%_');
    }

    /** "%term%" with the term's own wildcards neutralised. */
    public static function contains(string $term): string
    {
        return '%' . self::escape($term) . '%';
    }

    /**
     * The term, when it is a WHOLE phone number (digits, spaces, + - ( )
     * only, at least 9 digits), else null. Compared as
     * normalize_phone(column) = normalize_phone(term), so both sides go
     * through the one SQL rule.
     */
    public static function wholePhone(string $term): ?string
    {
        if (!preg_match('/^[\d\s+\-().]+$/', $term) || strlen(preg_replace('/\D/', '', $term)) < 9) {
            return null;
        }

        return $term;
    }
}
