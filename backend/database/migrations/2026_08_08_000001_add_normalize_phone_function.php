<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * normalize_phone(text) — one definition of "the same person's number".
 *
 * WHY A DATABASE FUNCTION rather than PHP: the rule is needed inside aggregate
 * queries (counting distinct customers), and will be needed by any future
 * dedupe, index or match. A second copy in application code would drift from
 * this one, and the symptom — two screens disagreeing about how many customers
 * exist — is exactly the class of bug this migration is fixing.
 *
 * TWO RULES, both learned from the production data:
 *
 * 1. NORMALISE TO FULL E.164, NEVER MATCH ON THE LAST N DIGITS. This dataset
 *    holds numbers from +250, +257, +243, +255, +39, +973, +211, +44 and +263
 *    alongside Kenyan ones. Matching on trailing digits collides across
 *    countries and would merge unrelated people.
 *
 * 2. ANYTHING THAT IS NOT A PLAUSIBLE PHONE RETURNS NULL, it does not become a
 *    bucket. 26 orders carry free text in customer_phone — "refer to IANDM",
 *    "Via Mpesa", "Paid in cash", "bank transfer", "cash" — 14 distinct strings
 *    that are notes a clerk typed into the wrong field. A naive normaliser maps
 *    every one of them to the same empty key and merges 14 unrelated walk-ins
 *    into a single "customer". That is worse than the double-counting being
 *    fixed: over-counting is visible, silent merging is not. NULL keeps them
 *    uncounted rather than wrongly counted as one.
 *
 * IMMUTABLE so it can back a functional index when the data justifies one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION normalize_phone(raw text)
            RETURNS text
            LANGUAGE plpgsql
            IMMUTABLE
            RETURNS NULL ON NULL INPUT
            AS $$
            DECLARE
                digits text;
                had_plus boolean;
            BEGIN
                had_plus := left(btrim(raw), 1) = '+';
                digits   := regexp_replace(raw, '[^0-9]', '', 'g');

                -- Not a phone: a note, a payment reference, an empty string.
                -- Kenyan subscriber numbers are 9 digits after the country code,
                -- so anything shorter cannot be one.
                IF length(digits) < 9 THEN
                    RETURN NULL;
                END IF;

                -- Already international, in either notation.
                IF had_plus OR left(digits, 3) = '254' THEN
                    RETURN '+' || digits;
                END IF;

                -- Kenyan national form: 0722… -> +254722…
                IF left(digits, 1) = '0' THEN
                    RETURN '+254' || substr(digits, 2);
                END IF;

                -- Bare subscriber number: 722… -> +254722…
                IF length(digits) = 9 THEN
                    RETURN '+254' || digits;
                END IF;

                -- Longer, no plus, not 254: assume it carries its own country
                -- code rather than guessing Kenya onto a foreign number.
                RETURN '+' || digits;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS normalize_phone(text);');
    }
};
