/**
 * The server's per-currency lines (App\Support\MoneyByCurrency): a KES total
 * at each row's own reporting rate is the headline; the native figures sit
 * beside it. Display only — never added together here.
 */
export interface CurrencyLine {
    currency_code: string;
    amount: number;
    count?: number;
    amount_kes?: number | null;
}

/** "USD 40.00 · ZMW 100.00" for a figure that mixes currencies; empty when it is shillings only. */
export function foreignLine(by?: CurrencyLine[], prefix = ""): string {
    const foreign = (by ?? []).filter((c) => c.currency_code !== "KES");
    if (!foreign.length) return "";
    return prefix + foreign.map((c) => `${c.currency_code} ${c.amount.toLocaleString("en-KE", { minimumFractionDigits: 2 })}`).join(" · ");
}
