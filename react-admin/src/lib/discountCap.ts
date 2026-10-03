import { useAuthStore } from "@/store/auth.store";

/**
 * The owner's discount rule on the console (2026-10-03): "The max is 5%.
 * Unless the super admin set the % discount, we can only give the discount up
 * to 5%."
 *
 * The SERVER enforces it (App\Support\DiscountRule) and refuses anything above
 * with a 422. These helpers only make the inputs say so up front and stop at
 * the maximum, so nobody types 10% and learns at the till.
 *
 * The figure comes from the signed-in user (`discount_cap_percent`, set by
 * /admin/auth/me): a number for everyone, null for the super_admin, who has no
 * ceiling. A session from before the field existed falls back to 5 unless the
 * user is a super_admin.
 */
export function useDiscountCap(): number | null {
    const user = useAuthStore((s) => s.user);
    return discountCapFor(user);
}

export function discountCapFor(user: { roles?: { name: string }[]; discount_cap_percent?: number | null } | null | undefined): number | null {
    if (!user) return 5;
    if (user.discount_cap_percent !== undefined) {
        return user.discount_cap_percent === null ? null : Number(user.discount_cap_percent);
    }
    return user.roles?.some((r) => r.name === "super_admin") ? null : 5;
}

/** The largest discount AMOUNT allowed on `base`, or Infinity when uncapped. */
export function maxDiscountAmount(base: number, cap: number | null): number {
    if (cap === null) return Infinity;
    return Math.round(Math.max(0, base) * cap) / 100;
}

/**
 * Clamp a typed discount to the maximum. `percent` values are clamped to the
 * percentage; `flat` values to that percentage of `base`.
 */
export function clampDiscount(type: "none" | "flat" | "percent", value: number, base: number, cap: number | null): number {
    const v = Math.max(0, Number.isFinite(value) ? value : 0);
    if (cap === null || type === "none") return v;
    if (type === "percent") return Math.min(v, cap);
    return Math.min(v, maxDiscountAmount(base, cap));
}

/** The lowest unit price allowed against a catalogue price, or 0 when uncapped. */
export function minUnitPrice(catalogue: number, cap: number | null): number {
    if (cap === null || !(catalogue > 0)) return 0;
    return Math.ceil(catalogue * (100 - cap)) / 100;
}

/** "Max 5%" — the hint shown beside a discount input. Empty for the owner. */
export function discountCapHint(cap: number | null): string {
    return cap === null ? "" : `Max ${cap}%`;
}

/** The server's refusal sentence, for client-side stops. */
export function discountCapMessage(cap: number | null): string {
    return `The most anyone can give is ${cap ?? 5}%. Larger discounts are set by the owner.`;
}

/**
 * The sale-price rule (App\Support\DiscountRule::salePriceRefusal): a sale
 * price more than `cap`% under its regular price is the owner's to set. A
 * price as it was loaded may be saved again, or made shallower — nothing on
 * the books is repriced. Returns the sentence, or null when it may be saved.
 */
export function salePriceIssue(
    regular: number,
    sale: number | null | undefined,
    cap: number | null,
    before?: { regular_price?: unknown; sale_price?: unknown } | null,
): string | null {
    if (cap === null) return null;
    const markdown = (r: number, s: number | null | undefined) =>
        s == null || !(s > 0) || !(r > 0) || s >= r ? 0 : (r - s) / r;

    const share = markdown(Number(regular), sale == null ? null : Number(sale));
    if (Math.round(share * 1_000_000) / 10_000 <= cap) return null;

    if (before && before.regular_price != null) {
        const was = markdown(
            Number(before.regular_price),
            before.sale_price == null || before.sale_price === "" ? null : Number(before.sale_price),
        );
        if (share <= was + 1e-9) return null;
    }
    return discountCapMessage(cap);
}

type PriceRow = { currency_code?: string; regular_price?: unknown; sale_price?: unknown };

/**
 * Wrap a react-hook-form resolver so every `prices.N.sale_price` is held to the
 * sale-price rule. `getBefore` returns the prices as loaded (the form's default
 * values), matched by currency.
 */
export function withSalePriceCap<R extends (...args: any[]) => any>(
    base: R,
    getCap: () => number | null,
    getBefore: () => PriceRow[] | undefined,
): R {
    return (async (values: any, ctx: any, opts: any) => {
        const res = await base(values, ctx, opts);
        const cap = getCap();
        if (cap === null) return res;

        const before = getBefore() ?? [];
        const issues: any[] = [];
        ((values?.prices ?? []) as PriceRow[]).forEach((p, i) => {
            const sale = p.sale_price == null || p.sale_price === "" ? null : Number(p.sale_price);
            const was  = before.find((b) => b?.currency_code === p.currency_code);
            const msg  = salePriceIssue(Number(p.regular_price ?? 0), sale, cap, was);
            if (msg) issues[i] = { sale_price: { type: "max", message: msg } };
        });
        if (issues.length === 0) return res;

        const errors: any = { ...(res.errors ?? {}) };
        const prices: any[] = Array.isArray(errors.prices) ? [...errors.prices] : [];
        issues.forEach((issue, i) => { if (issue) prices[i] = { ...(prices[i] ?? {}), ...issue }; });
        errors.prices = prices;
        return { values: {}, errors };
    }) as unknown as R;
}

/**
 * The cart / order discount, held to what the ORDER has left: at most 5% of
 * the cart subtotal, and the lines plus the cart together at most 5% of the
 * gross before any discount. Returns the clamped value in the input's own
 * terms (a percentage for "percent", money for "flat").
 */
export function clampCartDiscount(
    type: "none" | "flat" | "percent",
    value: number,
    subtotal: number,
    gross: number,
    linesGiven: number,
    cap: number | null,
): number {
    const v = Math.max(0, Number.isFinite(value) ? value : 0);
    if (cap === null || type === "none") return v;
    const room = Math.max(0, Math.min(maxDiscountAmount(subtotal, cap), maxDiscountAmount(gross, cap) - linesGiven));
    if (type === "flat") return Math.min(v, room);
    return subtotal > 0 ? Math.min(v, Math.floor((room / subtotal) * 100 * 100) / 100) : 0;
}
