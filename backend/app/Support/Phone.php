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
}
