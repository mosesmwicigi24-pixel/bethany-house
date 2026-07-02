<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * CountriesSeeder
 *
 * Seeds all world countries with:
 *  - ISO 3166-1 alpha-2 code
 *  - Name + native name
 *  - Phone dialling code
 *  - Flag emoji
 *  - Region / subregion
 *  - Default currency code (set only when the currency already exists in DB)
 *  - Shipping enabled (Kenya + major markets enabled by default)
 *
 * Kenya is the primary market - shipping enabled + KES default.
 * Major English-speaking / African markets enabled for shipping.
 * All others active but shipping disabled (can be toggled in admin).
 *
 * NOTE: default_currency_code respects the FK constraint on the currencies
 * table. Rows are inserted with null and then updated to the correct code
 * only for currencies that actually exist, so this seeder is safe to run
 * before or after the currencies seeder.
 */
class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Resolve which currency codes are present in the DB right now.
        // Any country whose currency is missing gets null - admin can set
        // it later once the currencies seeder has run.
        $existingCurrencies = DB::table('currencies')->pluck('code')->flip()->all();

        // ── Full country list ─────────────────────────────────────────────
        $countries = $this->getCountries();

        foreach (array_chunk($countries, 50) as $chunk) {
            $rows = array_map(function ($c) use ($now, $existingCurrencies) {
                // Only write the FK value when the referenced row exists
                $currencyCode = isset($existingCurrencies[$c['default_currency_code']])
                    ? $c['default_currency_code']
                    : null;

                return array_merge($c, [
                    'default_currency_code' => $currencyCode,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);
            }, $chunk);

            DB::table('countries')->upsert(
                $rows,
                ['code'],   // unique key
                [           // columns to update on conflict
                    'name', 'native_name', 'phone_code', 'flag',
                    'region', 'subregion', 'default_currency_code',
                    'is_active', 'is_shipping_enabled', 'updated_at',
                ]
            );
        }

        // ── Back-fill currency codes for any countries already in the DB
        //    whose currency has since been seeded (idempotent second pass) ──
        foreach ($this->getCountries() as $country) {
            if (isset($existingCurrencies[$country['default_currency_code']])) {
                DB::table('countries')
                    ->where('code', $country['code'])
                    ->whereNull('default_currency_code')
                    ->update([
                        'default_currency_code' => $country['default_currency_code'],
                        'updated_at'            => $now,
                    ]);
            }
        }

        // ── Configure Kenya specifically ──────────────────────────────────
        DB::table('countries')->where('code', 'KE')->update([
            'is_active'              => true,
            'is_shipping_enabled'    => true,
            'free_shipping_threshold'=> 5000.00,   // KES 5,000
            'standard_shipping_cost' => 300.00,    // KES 300
            'express_shipping_cost'  => 600.00,    // KES 600
            'estimated_delivery_days'=> 2,
            'updated_at'             => now(),
        ]);
    }

    private function getCountries(): array
    {
        // Format: code, name, native_name, phone_code, flag, region, subregion,
        //         default_currency_code, is_active, is_shipping_enabled
        return [
            // ── Africa (primary market) ───────────────────────────────────
            ['code'=>'KE','name'=>'Kenya','native_name'=>'Kenya','phone_code'=>'+254','flag'=>'🇰🇪','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'KES','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'UG','name'=>'Uganda','native_name'=>'Uganda','phone_code'=>'+256','flag'=>'🇺🇬','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'UGX','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'TZ','name'=>'Tanzania','native_name'=>'Tanzania','phone_code'=>'+255','flag'=>'🇹🇿','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'TZS','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'RW','name'=>'Rwanda','native_name'=>'Rwanda','phone_code'=>'+250','flag'=>'🇷🇼','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'RWF','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'ET','name'=>'Ethiopia','native_name'=>'ኢትዮጵያ','phone_code'=>'+251','flag'=>'🇪🇹','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'ETB','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'NG','name'=>'Nigeria','native_name'=>'Nigeria','phone_code'=>'+234','flag'=>'🇳🇬','region'=>'Africa','subregion'=>'Western Africa','default_currency_code'=>'NGN','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'GH','name'=>'Ghana','native_name'=>'Ghana','phone_code'=>'+233','flag'=>'🇬🇭','region'=>'Africa','subregion'=>'Western Africa','default_currency_code'=>'GHS','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'ZA','name'=>'South Africa','native_name'=>'South Africa','phone_code'=>'+27','flag'=>'🇿🇦','region'=>'Africa','subregion'=>'Southern Africa','default_currency_code'=>'ZAR','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'EG','name'=>'Egypt','native_name'=>'مصر','phone_code'=>'+20','flag'=>'🇪🇬','region'=>'Africa','subregion'=>'Northern Africa','default_currency_code'=>'EGP','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'MA','name'=>'Morocco','native_name'=>'المغرب','phone_code'=>'+212','flag'=>'🇲🇦','region'=>'Africa','subregion'=>'Northern Africa','default_currency_code'=>'MAD','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'CI','name'=>'Côte d\'Ivoire','native_name'=>'Côte d\'Ivoire','phone_code'=>'+225','flag'=>'🇨🇮','region'=>'Africa','subregion'=>'Western Africa','default_currency_code'=>'XOF','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'CM','name'=>'Cameroon','native_name'=>'Cameroun','phone_code'=>'+237','flag'=>'🇨🇲','region'=>'Africa','subregion'=>'Middle Africa','default_currency_code'=>'XAF','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'SN','name'=>'Senegal','native_name'=>'Sénégal','phone_code'=>'+221','flag'=>'🇸🇳','region'=>'Africa','subregion'=>'Western Africa','default_currency_code'=>'XOF','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'ZM','name'=>'Zambia','native_name'=>'Zambia','phone_code'=>'+260','flag'=>'🇿🇲','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'ZMW','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'ZW','name'=>'Zimbabwe','native_name'=>'Zimbabwe','phone_code'=>'+263','flag'=>'🇿🇼','region'=>'Africa','subregion'=>'Eastern Africa','default_currency_code'=>'ZWL','is_active'=>true,'is_shipping_enabled'=>false],

            // ── Europe ────────────────────────────────────────────────────
            ['code'=>'GB','name'=>'United Kingdom','native_name'=>'United Kingdom','phone_code'=>'+44','flag'=>'🇬🇧','region'=>'Europe','subregion'=>'Northern Europe','default_currency_code'=>'GBP','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'DE','name'=>'Germany','native_name'=>'Deutschland','phone_code'=>'+49','flag'=>'🇩🇪','region'=>'Europe','subregion'=>'Western Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'FR','name'=>'France','native_name'=>'France','phone_code'=>'+33','flag'=>'🇫🇷','region'=>'Europe','subregion'=>'Western Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'NL','name'=>'Netherlands','native_name'=>'Nederland','phone_code'=>'+31','flag'=>'🇳🇱','region'=>'Europe','subregion'=>'Western Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'SE','name'=>'Sweden','native_name'=>'Sverige','phone_code'=>'+46','flag'=>'🇸🇪','region'=>'Europe','subregion'=>'Northern Europe','default_currency_code'=>'SEK','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'NO','name'=>'Norway','native_name'=>'Norge','phone_code'=>'+47','flag'=>'🇳🇴','region'=>'Europe','subregion'=>'Northern Europe','default_currency_code'=>'NOK','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'CH','name'=>'Switzerland','native_name'=>'Schweiz','phone_code'=>'+41','flag'=>'🇨🇭','region'=>'Europe','subregion'=>'Western Europe','default_currency_code'=>'CHF','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'IT','name'=>'Italy','native_name'=>'Italia','phone_code'=>'+39','flag'=>'🇮🇹','region'=>'Europe','subregion'=>'Southern Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'ES','name'=>'Spain','native_name'=>'España','phone_code'=>'+34','flag'=>'🇪🇸','region'=>'Europe','subregion'=>'Southern Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'PT','name'=>'Portugal','native_name'=>'Portugal','phone_code'=>'+351','flag'=>'🇵🇹','region'=>'Europe','subregion'=>'Southern Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'BE','name'=>'Belgium','native_name'=>'België','phone_code'=>'+32','flag'=>'🇧🇪','region'=>'Europe','subregion'=>'Western Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'AT','name'=>'Austria','native_name'=>'Österreich','phone_code'=>'+43','flag'=>'🇦🇹','region'=>'Europe','subregion'=>'Western Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'IE','name'=>'Ireland','native_name'=>'Ireland','phone_code'=>'+353','flag'=>'🇮🇪','region'=>'Europe','subregion'=>'Northern Europe','default_currency_code'=>'EUR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'DK','name'=>'Denmark','native_name'=>'Danmark','phone_code'=>'+45','flag'=>'🇩🇰','region'=>'Europe','subregion'=>'Northern Europe','default_currency_code'=>'DKK','is_active'=>true,'is_shipping_enabled'=>false],

            // ── Americas ──────────────────────────────────────────────────
            ['code'=>'US','name'=>'United States','native_name'=>'United States','phone_code'=>'+1','flag'=>'🇺🇸','region'=>'Americas','subregion'=>'Northern America','default_currency_code'=>'USD','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'CA','name'=>'Canada','native_name'=>'Canada','phone_code'=>'+1','flag'=>'🇨🇦','region'=>'Americas','subregion'=>'Northern America','default_currency_code'=>'CAD','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'AU','name'=>'Australia','native_name'=>'Australia','phone_code'=>'+61','flag'=>'🇦🇺','region'=>'Oceania','subregion'=>'Australia and New Zealand','default_currency_code'=>'AUD','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'NZ','name'=>'New Zealand','native_name'=>'New Zealand','phone_code'=>'+64','flag'=>'🇳🇿','region'=>'Oceania','subregion'=>'Australia and New Zealand','default_currency_code'=>'NZD','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'BR','name'=>'Brazil','native_name'=>'Brasil','phone_code'=>'+55','flag'=>'🇧🇷','region'=>'Americas','subregion'=>'South America','default_currency_code'=>'BRL','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'MX','name'=>'Mexico','native_name'=>'México','phone_code'=>'+52','flag'=>'🇲🇽','region'=>'Americas','subregion'=>'Central America','default_currency_code'=>'MXN','is_active'=>true,'is_shipping_enabled'=>false],

            // ── Asia / Middle East ────────────────────────────────────────
            ['code'=>'AE','name'=>'United Arab Emirates','native_name'=>'الإمارات','phone_code'=>'+971','flag'=>'🇦🇪','region'=>'Asia','subregion'=>'Western Asia','default_currency_code'=>'AED','is_active'=>true,'is_shipping_enabled'=>true],
            ['code'=>'SA','name'=>'Saudi Arabia','native_name'=>'السعودية','phone_code'=>'+966','flag'=>'🇸🇦','region'=>'Asia','subregion'=>'Western Asia','default_currency_code'=>'SAR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'IN','name'=>'India','native_name'=>'भारत','phone_code'=>'+91','flag'=>'🇮🇳','region'=>'Asia','subregion'=>'Southern Asia','default_currency_code'=>'INR','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'CN','name'=>'China','native_name'=>'中国','phone_code'=>'+86','flag'=>'🇨🇳','region'=>'Asia','subregion'=>'Eastern Asia','default_currency_code'=>'CNY','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'JP','name'=>'Japan','native_name'=>'日本','phone_code'=>'+81','flag'=>'🇯🇵','region'=>'Asia','subregion'=>'Eastern Asia','default_currency_code'=>'JPY','is_active'=>true,'is_shipping_enabled'=>false],
            ['code'=>'SG','name'=>'Singapore','native_name'=>'Singapore','phone_code'=>'+65','flag'=>'🇸🇬','region'=>'Asia','subregion'=>'South-Eastern Asia','default_currency_code'=>'SGD','is_active'=>true,'is_shipping_enabled'=>false],
        ];
    }
}