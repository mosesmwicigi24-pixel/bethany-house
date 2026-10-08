<?php

namespace App\Support;

/**
 * Report parameters, checked BEFORE they reach a query.
 *
 * Cycle 8 sent thirteen kinds of malformed input to all twenty-two report
 * endpoints, each in its own transaction. Seventy-four requests answered with a
 * 500: every malformed date on twelve legacy endpoints, and `outlet_id=abc` on
 * two more. The engine endpoints validated and returned 422; the legacy ones
 * passed the raw string straight into `whereBetween()`, and Postgres refused
 * it mid-query.
 *
 * Nothing was injectable — the queries are parameterised, and the payload
 * `2026-01-01';DROP TABLE orders;--` arrived as a single bound value that
 * Postgres rejected as an invalid timestamp. But a 500 reads as "the reports
 * are broken", it puts the caller's input into an error message, and a report
 * URL is not only typed by the console: it is bookmarked, pasted between
 * colleagues and edited by hand.
 *
 * One validator, used by the middleware that guards the whole section and by
 * the date resolver as a second line, so neither can drift from the other.
 */
final class ReportInput
{
    /**
     * A calendar date, as YYYY-MM-DD, optionally followed by a time. Anything
     * else is refused with a 422 that names the parameter and the format.
     *
     * The WHOLE string is checked, not its first ten characters: checking only
     * the prefix would read `2026-01-01';DROP TABLE orders;--` as a perfectly
     * good 1 January and answer for the wrong window without a word.
     */
    public static function date(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // `start_date[]=…` arrives as an array, and `(string)` on an array is
        // a PHP error — the cycle 8 fix itself answered 500 to it (cycle 10).
        if (! is_scalar($value)) {
            self::refuse($field);
        }

        $value = (string) $value;
        // A time, fractional seconds and a zone are tolerated (a JavaScript
        // toISOString() is a reasonable thing to send) and ignored: reports
        // answer for whole calendar days.
        $shape = '/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

        if (! preg_match($shape, $value, $m)) {
            self::refuse($field);
        }

        // createFromFormat rolls 2026-13-45 forward into a real date; formatting
        // it back and comparing is what catches an impossible calendar day.
        $day    = $m[1];
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            self::refuse($field);
        }

        return $day;
    }

    /** A positive whole number, or absent. An outlet that does not exist is a
     *  valid question with an empty answer; "abc" is not a question. */
    public static function outletId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! preg_match('/^[1-9]\d{0,17}$/', (string) $value)) {
            abort(422, 'outlet_id must be a positive whole number.');
        }

        return (int) $value;
    }

    private static function refuse(string $field): never
    {
        abort(422, "{$field} must be a calendar date in the form YYYY-MM-DD.");
    }
}
