<?php

namespace App\Support;

/**
 * Infer a customer's country from the weak signals we actually hold.
 *
 * Order country codes are captured on < 10% of orders (POS/walk-in never asks),
 * but ~90% of customers have a phone number — so the dialing prefix is by far
 * our richest location signal. This resolves a country ISO-2 by cascade:
 *   explicit order country → phone (E.164 prefix, longest match) → null.
 *
 * Kenyan numbers are frequently stored in local form (0722…, 0110…, 7xx…, 1xx…)
 * with no country code; those resolve to KE. Same E.164-longest-prefix doctrine
 * as Neema's countries.resolve_country (see the phone-identity-matching note):
 * never match on a short/last-N fragment — always the full leading prefix.
 */
class CountryInference
{
    /**
     * Dialing code (no +) → ISO-3166 alpha-2. Longest-prefix wins, so 4-digit
     * codes (e.g. 1876 Jamaica) must beat their 1-digit parent (1 US/CA).
     * Africa first (our market), then the diaspora we actually see, then a
     * pragmatic global tail. Not exhaustive — unknown prefixes return null
     * (surfaced as "unlocated", never mislabelled).
     */
    private const DIAL = [
        // East & Southern Africa (core + observed diaspora)
        '254' => 'KE', '255' => 'TZ', '256' => 'UG', '250' => 'RW', '257' => 'BI',
        '251' => 'ET', '252' => 'SO', '211' => 'SS', '253' => 'DJ',
        '260' => 'ZM', '263' => 'ZW', '265' => 'MW', '258' => 'MZ', '267' => 'BW',
        '264' => 'NA', '268' => 'SZ', '266' => 'LS', '27' => 'ZA', '243' => 'CD',
        '244' => 'AO', '261' => 'MG', '230' => 'MU',
        // West & North Africa
        '234' => 'NG', '233' => 'GH', '225' => 'CI', '221' => 'SN', '237' => 'CM',
        '20' => 'EG', '212' => 'MA', '216' => 'TN', '213' => 'DZ',
        // Diaspora / common international
        '44' => 'GB', '353' => 'IE', '1' => 'US', '971' => 'AE', '966' => 'SA',
        '974' => 'QA', '973' => 'BH', '965' => 'KW', '968' => 'OM', '91' => 'IN',
        '49' => 'DE', '33' => 'FR', '39' => 'IT', '34' => 'ES', '31' => 'NL',
        '32' => 'BE', '46' => 'SE', '47' => 'NO', '41' => 'CH', '61' => 'AU',
        '64' => 'NZ', '86' => 'CN', '81' => 'JP', '65' => 'SG', '852' => 'HK',
        '55' => 'BR', '7' => 'RU', '90' => 'TR',
    ];

    /** Resolve from the first usable signal: explicit country, else phone. */
    public static function resolve(array $countryCodes, ?string $phone): ?string
    {
        foreach ($countryCodes as $cc) {
            $cc = strtoupper(trim((string) $cc));
            if (strlen($cc) === 2 && ctype_alpha($cc)) {
                return $cc;
            }
        }
        return self::fromPhone($phone);
    }

    /** Country ISO-2 from a phone number, or null if it can't be resolved. */
    public static function fromPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        $hasPlus = str_contains($phone, '+');
        $digits  = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        // Local Kenyan forms with no country code: 07xxxxxxxx / 01xxxxxxxx, or a
        // bare 7xxxxxxxx / 1xxxxxxxx (9 digits). Only when there's no leading '+'.
        if (!$hasPlus) {
            if (preg_match('/^0[17]\d{8}$/', $digits) || preg_match('/^0[17]\d{7}$/', $digits)) {
                return 'KE';
            }
            if (preg_match('/^[17]\d{8}$/', $digits)) {
                return 'KE';
            }
        }

        // Strip a leading 00 international prefix if present.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Longest dialing-prefix match (up to 4 leading digits).
        for ($len = 4; $len >= 1; $len--) {
            $prefix = substr($digits, 0, $len);
            if (isset(self::DIAL[$prefix])) {
                return self::DIAL[$prefix];
            }
        }
        return null;
    }
}
