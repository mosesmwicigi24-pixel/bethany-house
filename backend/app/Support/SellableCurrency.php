<?php

namespace App\Support;

use App\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which currencies the business may SELL in (owner, 2026-10-18).
 *
 * A sale in a currency with no reporting rate cannot be stated in shillings:
 * it falls out of every KES total, has no approval band and no earned
 * revenue. So a currency is sellable only when its row is active AND it has
 * a reporting rate (ReportingCurrency). KES always is. Purchase orders and
 * expenses already apply this rule; this is the one place sales ask it.
 *
 * Two ways in:
 *   - a currency named explicitly (a quotation's currency_code): refused
 *     with a validation error, so the person sees why;
 *   - a currency DERIVED from a customer's country (forCustomer): the ONE
 *     rule every path that bills a customer asks — the till, chat and
 *     WhatsApp orders, order edits, Set Country / Currency, the website
 *     checkout and admin orders (owner, 2026-10-29):
 *
 *         Kenya (the home country), or no country    → KES
 *         a country whose own currency can be sold    → that currency
 *                                                       (ZMW for Zambia, ZAR
 *                                                       for South Africa once
 *                                                       it has a reporting rate)
 *         any other country                           → USD
 *
 *     Before 29 Oct the till fell back to KES here while Set Country fell
 *     back to USD, so a Botswana customer was billed in shillings. A foreign
 *     customer is never given the shop's own currency by default; a person
 *     who really is paying in KES (a relative paying by M-Pesa) is kept in
 *     KES deliberately, with a reason, at the payment link.
 */
final class SellableCurrency
{
    public static function isSellable(?string $code): bool
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return false;
        }
        if ($code === 'KES') {
            return true;
        }
        if (!isset(ReportingCurrency::rates()[$code])) {
            return false;
        }

        return DB::table('currencies')->whereRaw('UPPER(code) = ?', [$code])->where('is_active', true)->exists();
    }

    /** The shop's own currency: the base row, else KES. */
    public static function home(): string
    {
        $base = DB::table('currencies')->where('is_base', true)->value('code');

        return strtoupper((string) ($base ?: 'KES'));
    }

    /** The currency the rest of the world is billed in when its own can't be. */
    public const INTERNATIONAL = 'USD';

    /** The shop's own country (settings app_country), upper-case; KE when unset. */
    public static function homeCountry(): string
    {
        return strtoupper((string) (DB::table('settings')->where('key', 'app_country')->value('value') ?: 'KE'));
    }

    /** Is this customer country outside the shop's own? Empty is not foreign. */
    public static function isForeign(?string $countryCode): bool
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        return $countryCode !== '' && $countryCode !== self::homeCountry();
    }

    /**
     * The currency a customer in this country is billed in — the one rule
     * (see the class note). Every path that prices a customer's order asks
     * this; the console reads the same answer as `sale_currency` on the
     * countries list, so what staff see is what is charged.
     */
    public static function forCustomer(?string $countryCode): string
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        if (!self::isForeign($countryCode)) {
            return self::home();
        }
        $own = DB::table('countries')->where('code', $countryCode)->value('default_currency_code');

        return self::decide($own, self::sellableCodes(), self::home());
    }

    /**
     * The same answer for a whole list of country rows at once (the countries
     * endpoints), without a query per row.
     *
     * @param  iterable<object|array>  $countries  rows with code and default_currency_code
     * @return array<string, string>  country code → currency
     */
    public static function forCountries(iterable $countries): array
    {
        $home = self::home(); $homeCountry = self::homeCountry(); $sellable = self::sellableCodes();
        $out = [];
        foreach ($countries as $c) {
            $code = strtoupper((string) data_get($c, 'code'));
            $out[$code] = $code === '' || $code === $homeCountry ? $home : self::decide(data_get($c, 'default_currency_code'), $sellable, $home);
        }

        return $out;
    }

    /** Currencies a sale may be in right now: active with a reporting rate, and KES. */
    public static function sellableCodes(): array
    {
        $rates  = ReportingCurrency::rates();
        $active = DB::table('currencies')->where('is_active', true)->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();

        return array_values(array_unique(array_merge(['KES'], array_filter($active, fn ($c) => isset($rates[$c])))));
    }

    /** A foreign customer: their own currency when sellable (never the shop's own), else USD, else the shop's. */
    private static function decide(?string $own, array $sellable, string $home): string
    {
        $own = strtoupper(trim((string) $own));
        if ($own !== '' && $own !== $home && in_array($own, $sellable, true)) {
            return $own;
        }

        return in_array(self::INTERNATIONAL, $sellable, true) ? self::INTERNATIONAL : $home;
    }

    /**
     * A currency the customer chose (owner, 2026-10-29: "when someone says
     * USD we quote in USD; when someone says rands, in rands" — every country
     * but Kenya). Null when none was chosen. Any currency the books can carry;
     * a customer abroad billed in KES needs a reason, because that was the
     * mistake. Only for a customer abroad: in Kenya the sale is in shillings.
     */
    public static function chosen(?string $asked, ?string $countryCode, ?string $reason, string $reasonField = 'reason'): ?string
    {
        $asked = strtoupper(trim((string) $asked));
        if ($asked === '') {
            return null;
        }
        if (!self::isForeign($countryCode)) {
            throw ValidationException::withMessages(['currency_code' => ['A customer in Kenya pays in shillings. Set the customer\'s country first to bill in another currency.']]);
        }
        $asked = self::assert($asked);
        if ($asked === self::home() && trim((string) $reason) === '') {
            throw ValidationException::withMessages([$reasonField => ['Say why a customer abroad is billed in ' . $asked . '.']]);
        }

        return $asked;
    }

    /** A country's default currency when it is sellable, else the fallback. */
    public static function forCountry(?string $countryCode, ?string $fallback = null): string
    {
        $fallback = $fallback ?? self::home();
        $countryCode = strtoupper(trim((string) $countryCode));
        if ($countryCode === '') {
            return $fallback;
        }
        $currency = DB::table('countries')->where('code', $countryCode)->value('default_currency_code');

        return $currency && self::isSellable($currency) ? strtoupper($currency) : $fallback;
    }

    /**
     * An outlet's currency from its country (the POS rule: US → USD, GB → GBP,
     * EUR → EUR), kept only when sellable — a UK outlet no longer sells in
     * pounds the books can't convert.
     */
    public static function forOutlet(?Outlet $outlet): string
    {
        $guess = match (strtoupper((string) ($outlet?->country_code ?? ''))) {
            'USA', 'US' => 'USD',
            'GBR', 'GB' => 'GBP',
            'EUR'       => 'EUR',
            default     => self::home(),
        };

        return self::isSellable($guess) ? $guess : self::home();
    }

    public static function reason(string $code): string
    {
        $code = strtoupper($code);

        return "There is no reporting rate for {$code}, so the books can't state it in shillings. Ask finance to set one, or use KES.";
    }

    /** Refuse an explicitly named currency that isn't sellable. */
    public static function assert(?string $code, string $field = 'currency_code'): string
    {
        $code = strtoupper(trim((string) $code));
        if (!self::isSellable($code)) {
            throw ValidationException::withMessages([$field => [self::reason($code)]]);
        }

        return $code;
    }
}
