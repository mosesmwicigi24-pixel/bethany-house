<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * normalize_phone(text) — close the two silent-merge holes the first cut left.
 *
 * The original (2026_08_08_000001) gated on ONE thing: fewer than 9 digits
 * returns NULL. That let two classes of non-phone through into the +254
 * branches, minting fake customers and merging unrelated rows — the exact
 * failure normalize_phone exists to prevent (its Rule 2). Both are real values
 * from the production customer_phone column:
 *
 *   'MPESA REF 123456789'  -> +254123456789   a payment reference, 9 digits, so
 *                                              it cleared the length gate and
 *                                              became a bare subscriber number.
 *                                              Any two notes sharing those digits
 *                                              then merge into one "customer".
 *
 *   '0044 7401 182700'     -> +2540447401182700   a UK number written with the 00
 *                                              international prefix. The leading-0
 *                                              branch read it as Kenyan national
 *                                              form and prepended +254 onto a
 *                                              foreign number (Rule 1), and split
 *                                              it from its own +44… spelling.
 *
 * Three additions, each aligned to the rules already documented on the function:
 *
 *   1. A phone number carries NO LETTERS. If the raw input has any, it is a note
 *      or a reference, not a phone — return NULL. This is the durable class in
 *      the data (every clerk-note example carries letters) and it aligns with
 *      Rule 2: uncounted is better than wrongly merged.
 *
 *   2. 00 + country code is the INTERNATIONAL dialling prefix, not a Kenyan
 *      leading zero. Strip the 00 and keep the country code it carries, so
 *      0044 7401 182700 normalises to +447401182700 — identical to +44 7401
 *      182700, so the same person converges instead of splitting.
 *
 *   3. The Kenyan national-0 branch now fires ONLY at 10 digits (0 + the 9-digit
 *      subscriber number). A longer 0-led string is not a Kenyan number and must
 *      not be minted into an over-long +254 one.
 *
 * Read-time only: no functional index or generated column stores this value, so
 * replacing the function corrects every consumer (unique-customer counts,
 * retention, second-purchase, RFM, lead/win-back/replenishment matching) at once,
 * with no stored data to migrate. down() restores the prior definition exactly.
 *
 * A purely-numeric 9-digit non-phone with no letters (e.g. a bare '123456789')
 * stays ambiguous from digits alone — that residual is for write-time validation
 * of the phone field, not this function.
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
                -- A phone number carries no letters. Free text in the phone field
                -- — 'MPESA REF 123456789', 'Via Mpesa' — does, and its embedded
                -- digits would otherwise mint a fake customer and merge unrelated
                -- rows sharing those digits. Not a plausible phone -> NULL.
                IF raw ~ '[A-Za-z]' THEN
                    RETURN NULL;
                END IF;

                had_plus := left(btrim(raw), 1) = '+';
                digits   := regexp_replace(raw, '[^0-9]', '', 'g');

                -- Too short to be a subscriber number even without a country code
                -- (Kenyan subscriber numbers are 9 digits).
                IF length(digits) < 9 THEN
                    RETURN NULL;
                END IF;

                -- Already international, in either notation.
                IF had_plus OR left(digits, 3) = '254' THEN
                    RETURN '+' || digits;
                END IF;

                -- 00 + country code is the international dialling prefix, NOT a
                -- Kenyan leading zero: 0044 7401 182700 is +44…, never +254…0044…
                IF left(digits, 2) = '00' THEN
                    RETURN '+' || substr(digits, 3);
                END IF;

                -- Kenyan national form: 0722… -> +254722…, but only at the right
                -- length (a leading 0 plus the 9-digit subscriber number). A longer
                -- 0-led string is not Kenyan national form — fall through.
                IF left(digits, 1) = '0' AND length(digits) = 10 THEN
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
        // Restore the prior definition exactly (2026_08_08_000001) — the length
        // gate as the only guard. Read-time only, so this is a complete rollback.
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

                IF length(digits) < 9 THEN
                    RETURN NULL;
                END IF;

                IF had_plus OR left(digits, 3) = '254' THEN
                    RETURN '+' || digits;
                END IF;

                IF left(digits, 1) = '0' THEN
                    RETURN '+254' || substr(digits, 2);
                END IF;

                IF length(digits) = 9 THEN
                    RETURN '+254' || digits;
                END IF;

                RETURN '+' || digits;
            END;
            $$;
        SQL);
    }
};
