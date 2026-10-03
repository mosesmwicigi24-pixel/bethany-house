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
