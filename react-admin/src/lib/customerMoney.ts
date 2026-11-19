/**
 * Mirrors App\Support\CustomerMoney on the server, so a figure on screen is
 * the figure the order will carry.
 *
 * FEES are whole — a discount, a shipping fee, VAT added on top (owner,
 * 2026-10-30). Halves round up.
 */
export const whole = (amount: number): number => Math.round(amount);

/**
 * PRICES keep two decimals (owner, 2026-11-16: "no rounding off — 400
 * plastic cups × USD 0.10 should be USD 40"): typed or from the price list.
 */
export const price = (amount: number): number => Math.round(amount * 100) / 100;

/**
 * An amount as a customer reads it: "4,500", not "4,500.00". Cents only when
 * there are some (VAT inside a price, an older amount) — nothing is hidden.
 * Mirrors App\Support\CustomerMoney::format. Display only.
 */
export const formatAmount = (amount: number | string | null | undefined): string => {
    const n = Number(amount ?? 0) || 0;
    const decimals = Math.abs(n - Math.round(n)) < 0.005 ? 0 : 2;
    return n.toLocaleString("en-KE", { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
};

/** "KES 4,500" — formatAmount with the currency in front. */
export const formatMoney = (amount: number | string | null | undefined, currency?: string | null): string =>
    currency ? `${currency} ${formatAmount(amount)}` : formatAmount(amount);
