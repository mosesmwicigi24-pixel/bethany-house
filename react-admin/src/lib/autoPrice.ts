/**
 * What a currency's price comes to when nobody typed one — the server's rule
 * (App\Services\CurrencyPricing), mirrored so the price screens fill and show
 * the same figure the till and the pay page will charge (owner, 2026-10-30):
 *
 *   - converted from the price list the currency is priced from
 *     (currencies.priced_from — the rand from USD) when that row has a price,
 *   - else from the default (KES) price,
 *   - to the nearest whole unit, halves up (owner, 2026-10-30): R3,231,
 *     not R3,230.77 — a price typed on a product is never rounded;
 *   - null when it can't be stated: a currency still at the blank default
 *     rate of 1.0 is unconfigured, not a peg, and must never copy the
 *     shilling figure under another label.
 */
export interface CurrencyRow {
    code: string;
    exchange_rate?: number | string | null;
    is_base?: boolean;
    is_default?: boolean;
    priced_from?: string | null;
}

export function baseOf(currencies: CurrencyRow[]): CurrencyRow | undefined {
    return currencies.find((c) => c.is_base || c.is_default);
}

/** A currency's configured customer rate, or null (unset, ≤ 0, or a non-base 1.0). */
export function rateOf(currency: CurrencyRow | undefined, base: CurrencyRow | undefined): number | null {
    const rate = Number(currency?.exchange_rate ?? 0);
    if (!(rate > 0)) return null;
    if (currency?.code === base?.code) return rate;
    return Math.abs(rate - 1) < 1e-9 ? null : rate;
}

/** amount in `from` stated in `to`, at the two customer rates; null when either has none. */
export function convertPrice(amount: number, from: string, to: string, currencies: CurrencyRow[]): number | null {
    const base = baseOf(currencies);
    const rf = rateOf(currencies.find((c) => c.code === from), base);
    const rt = rateOf(currencies.find((c) => c.code === to), base);
    if (rf === null || rt === null) return null;
    return Math.round((amount / rf) * rt * 100) / 100;
}

/**
 * The automatic price of `code`, given the other rows' current amounts.
 * amounts: currency → price (null/0 = no price).
 */
export function autoPrice(code: string, amounts: Record<string, number | null | undefined>, currencies: CurrencyRow[]): number | null {
    const base = baseOf(currencies);
    if (!base || code === base.code) return null;
    const self = currencies.find((c) => c.code === code);
    const from = self?.priced_from && self.priced_from !== base.code ? self.priced_from : null;
    const fromAmount = from ? Number(amounts[from] ?? 0) : 0;
    const whole = (v: number | null) => (v === null ? null : Math.round(v));
    if (from && fromAmount > 0) return whole(convertPrice(fromAmount, from, code, currencies));
    const baseAmount = Number(amounts[base.code] ?? 0);
    return baseAmount > 0 ? whole(convertPrice(baseAmount, base.code, code, currencies)) : null;
}

/**
 * The rows that follow a change to `changed`: every non-base row when the
 * default changes; only the rows priced from it otherwise. Ordered so a row
 * priced from another (the rand from USD) comes after the row it follows.
 */
export function followers(changed: string, currencies: CurrencyRow[]): string[] {
    const base = baseOf(currencies);
    if (!base) return [];
    const plain   = currencies.filter((c) => c.code !== base.code && !(c.priced_from && c.priced_from !== base.code)).map((c) => c.code);
    const chained = currencies.filter((c) => c.code !== base.code && c.priced_from && c.priced_from !== base.code).map((c) => c.code);
    if (changed === base.code) return [...plain, ...chained];
    return currencies.filter((c) => c.priced_from === changed && c.code !== changed).map((c) => c.code);
}
