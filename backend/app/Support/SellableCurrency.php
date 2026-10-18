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
 *   - a currency DERIVED from a customer's country or an outlet (POS,
 *     checkout, admin orders): falls back to the shop's own currency, so the
 *     sale still goes through — in shillings — rather than in a currency
 *     the books can't carry.
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
