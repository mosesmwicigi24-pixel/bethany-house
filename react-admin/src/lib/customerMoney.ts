/**
 * What a customer is billed is a whole unit — KES 1,000, R3,231, USD 150 —
 * never cents (owner, 2026-10-30). Halves round up. Mirrors
 * App\Support\CustomerMoney on the server, so a figure on screen is the
 * figure the order will carry.
 */
export const whole = (amount: number): number => Math.round(amount);

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
