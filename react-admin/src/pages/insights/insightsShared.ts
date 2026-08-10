// src/pages/insights/insightsShared.ts
//
// Country naming, the choropleth colour scale, and the derived observations
// shown above the Markets table. Kept out of the components so the rules that
// decide what the dashboard SAYS are readable in one place.

import type { MarketRow } from "@/api/marketing";

// ─── Country identity ─────────────────────────────────────────────────────────
// Names come from Intl.DisplayNames (all 249 ISO codes, correctly localised)
// rather than from our `countries` table, which is seeded with ~50.

const regionNames =
    typeof Intl !== "undefined" && "DisplayNames" in Intl
        ? new Intl.DisplayNames(["en"], { type: "region" })
        : null;

// Geo lookups that resolved to nothing. `XX` is the storefront's sentinel;
// `ZZ` and `T1` show up from privacy relays and Tor exits. Intl happily
// returns the literal string for all three, which is how "XX" ended up on
// screen looking like a country.
const UNRESOLVED = new Set(["XX", "ZZ", "T1", "A1", "A2", "O1"]);

export const UNKNOWN_TOOLTIP =
    "The visitor's country could not be resolved by the geo lookup — usually a VPN, a privacy relay, or a bot.";

export function isUnknownCode(cc?: string | null): boolean {
    return !cc || cc.length !== 2 || UNRESOLVED.has(cc.toUpperCase());
}

export function flagOf(cc?: string | null): string {
    if (isUnknownCode(cc)) return "🌐";
    const base = 0x1f1e6;
    const up = cc!.toUpperCase();
    return String.fromCodePoint(
        base + up.charCodeAt(0) - 65,
        base + up.charCodeAt(1) - 65,
    );
}

export function nameOf(cc?: string | null): string {
    if (isUnknownCode(cc)) return "Unknown";
    try {
        return regionNames?.of(cc!.toUpperCase()) ?? cc!.toUpperCase();
    } catch {
        return cc!.toUpperCase();
    }
}

// ─── Formatting ───────────────────────────────────────────────────────────────

export const fmtInt = (n: number) => Math.round(n).toLocaleString("en-KE");

/** Conversion reads as a rate, so it keeps two decimals below 1% and one above. */
export function fmtConversion(pct: number | null | undefined): string {
    if (pct === null || pct === undefined) return "—";
    if (pct === 0) return "0%";
    return `${pct < 1 ? pct.toFixed(2) : pct.toFixed(1)}%`;
}

/** KES, but compact — the Markets table has six columns to fit. */
export function fmtKesCompact(amount: number): string {
    if (!amount) return "—";
    if (amount >= 1_000_000) return `KES ${(amount / 1_000_000).toFixed(1)}M`;
    if (amount >= 10_000) return `KES ${Math.round(amount / 1_000)}K`;
    return `KES ${Math.round(amount).toLocaleString("en-KE")}`;
}

// ─── Choropleth scale ─────────────────────────────────────────────────────────
// Brand ramp, 100 → 500. Traffic is heavily long-tailed (one country can hold
// half the visits), so the buckets are QUANTILES of the countries that have
// any traffic at all. A linear ramp would paint 176 countries the palest tint
// and one of them solid, which says nothing.

export const VISIT_RAMP = ["#ffe4d8", "#ffc7b0", "#ffa17d", "#fb7a4c", "#f05423"];
export const MAP_NO_DATA = "#eceee9";
export const MAP_STROKE = "#ffffff";

export interface VisitScale {
    /** Upper bound (inclusive) of each bucket, ascending; same length as colors. */
    buckets: { max: number; color: string }[];
    colorFor: (visits: number) => string;
}

export function buildVisitScale(values: number[]): VisitScale {
    const sorted = values.filter((v) => v > 0).sort((a, b) => a - b);
    if (sorted.length === 0) {
        return { buckets: [], colorFor: () => MAP_NO_DATA };
    }

    const at = (p: number) => sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
    // Dedupe: with a handful of countries the quantiles collide, and a legend
    // reading "1–1, 1–1, 1–4" is worse than a three-step legend.
    const cuts = Array.from(new Set([at(0.2), at(0.4), at(0.6), at(0.8), sorted[sorted.length - 1]]))
        .sort((a, b) => a - b);
    const colors = VISIT_RAMP.slice(VISIT_RAMP.length - cuts.length);
    const buckets = cuts.map((max, i) => ({ max, color: colors[i] }));

    return {
        buckets,
        colorFor: (visits: number) => {
            if (!visits) return MAP_NO_DATA;
            for (const b of buckets) if (visits <= b.max) return b.color;
            return buckets[buckets.length - 1].color;
        },
    };
}

// ─── Derived observations ─────────────────────────────────────────────────────
// Deliberately OBSERVATIONS, never conclusions: the hub cannot tell a crawler
// from a genuine unserved market, so it says what it sees and names both
// possibilities. Thresholds are held here so they are arguable in one place.

/** Visits a country must send before "and no orders" is worth remarking on. */
const ZERO_ORDER_VISIT_FLOOR = 200;
/** Visits a converting country needs before its rate is treated as signal. */
const CONVERTER_VISIT_FLOOR = 50;
/** How far above the site average a rate must sit to be called out. */
const CONVERTER_MULTIPLE = 1.5;

export interface Observation {
    kind: "gap" | "win" | "unlocated";
    title: string;
    detail: string;
}

export function deriveObservations(
    markets: MarketRow[],
    totals: { visits: number; orders: number },
): Observation[] {
    const out: Observation[] = [];
    const siteRate = totals.visits > 0 ? (totals.orders * 100) / totals.visits : 0;

    // 1. The largest audience that has bought nothing.
    const gap = markets
        .filter((m) => m.orders === 0 && m.visits >= ZERO_ORDER_VISIT_FLOOR && !isUnknownCode(m.country_code))
        .sort((a, b) => b.visits - a.visits)[0];
    if (gap) {
        out.push({
            kind: "gap",
            title: `${nameOf(gap.country_code)}: ${fmtInt(gap.visits)} visits, no orders`,
            detail:
                "The biggest audience in this period that has not bought anything. Worth checking whether it is crawler traffic or a market the storefront does not currently serve — shipping, currency and payment options are the usual blockers.",
        });
    }

    // 2. The best converter, relative to the site as a whole.
    const win = markets
        .filter(
            (m) =>
                m.orders > 0 &&
                m.visits >= CONVERTER_VISIT_FLOOR &&
                m.conversion_pct !== null &&
                m.conversion_pct > siteRate * CONVERTER_MULTIPLE,
        )
        .sort((a, b) => (b.conversion_pct ?? 0) - (a.conversion_pct ?? 0))[0];
    if (win) {
        out.push({
            kind: "win",
            title: `${nameOf(win.country_code)} converts at ${fmtConversion(win.conversion_pct)}`,
            detail: `Against a site-wide ${fmtConversion(Number(siteRate.toFixed(2)))} across ${fmtInt(totals.visits)} visits. ${fmtInt(win.orders)} ${win.orders === 1 ? "order" : "orders"} from ${fmtInt(win.visits)} visits.`,
        });
    }

    // 3. Money arriving from countries we never saw browsing.
    const unlocated = markets.filter((m) => m.orders > 0 && m.visits === 0);
    if (unlocated.length > 0) {
        const revenue = unlocated.reduce((s, m) => s + m.revenue, 0);
        out.push({
            kind: "unlocated",
            title: `${unlocated.length} ${unlocated.length === 1 ? "market has" : "markets have"} orders but no recorded visits`,
            detail: `${unlocated.map((m) => nameOf(m.country_code)).join(", ")} — ${fmtKesCompact(revenue)}. Their country came from the order or the buyer's phone, so the visit itself was never attributed. Conversion cannot be calculated for them.`,
        });
    }

    return out;
}
