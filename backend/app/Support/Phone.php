<?php

namespace App\Support;

/**
 * Canonicalise a phone number to E.164 digits with no leading '+', so numbers
 * stored in different shapes across systems compare equal. This is the join key
 * between hub customers (stored as 0722…, +254…, 254…) and Neema (stored E.164
 * without '+', e.g. 254712345678).
 *
 * Kenyan local forms with no country code (07xx / 01xx, or a bare 7xx/1xx) are
 * promoted to 254…; anything already carrying a country code is kept. Full-number
 * comparison only — never match on a last-N fragment (international collisions).
 */
class Phone
{
    public static function canonical(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $hasPlus = str_contains($phone, '+');
        $digits  = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Local Kenyan forms only when there is no explicit '+'/country code.
        if (!$hasPlus) {
            if (preg_match('/^0([17]\d{8})$/', $digits, $m)) {
                return '254' . $m[1];         // 0722xxxxxx → 254722xxxxxx
            }
            if (preg_match('/^([17]\d{8})$/', $digits, $m)) {
                return '254' . $m[1];         // bare 722xxxxxx → 254722xxxxxx
            }
        }

        return $digits;
    }

    /**
     * The number as the REPORTS read it: a line-for-line mirror of the SQL
     * function normalize_phone() (migration 2026_08_22_120000), returning
     * '+' E.164 or NULL for anything that is not a plausible phone. The SQL is
     * the definition — every buyer count, retention and match uses it — so a
     * write-time check must accept exactly what it accepts, or the till would
     * pass a value the reports then cannot read. PhoneParityTest runs both on
     * the same inputs. canonical() above is a separate, older key (Neema joins).
     */
    public static function e164(?string $raw): ?string
    {
        if ($raw === null || preg_match('/[A-Za-z]/', $raw)) {
            return null;                                   // a note, not a phone
        }
        $hadPlus = str_starts_with(trim($raw), '+');
        $digits  = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($digits) < 9) {
            return null;
        }
        if ($hadPlus || str_starts_with($digits, '254')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);               // 00 = international prefix
        }
        if ($digits[0] === '0' && strlen($digits) === 10) {
            return '+254' . substr($digits, 1);            // 0722… national form
        }
        if (strlen($digits) === 9) {
            return '+254' . $digits;                       // bare subscriber number
        }

        return '+' . $digits;                              // carries its own country code
    }
}
