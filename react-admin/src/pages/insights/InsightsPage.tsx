// src/pages/insights/InsightsPage.tsx
//
// Storefront Insights — who visits bethanyhouse.co.ke, and which of those
// visitors actually become money. Fed by GET /api/v1/admin/analytics/overview
// (AnalyticsController). Privacy by design: the hub stores country + device
// only, never an IP (Kenya DPA / Privacy Policy).
//
// DESIGN NOTE — traffic is not money.
// The previous layout put "where visitors come from" and "which countries are
// buying" in two separate 50-row tables, side by side, sorted differently. Both
// were accurate and together they hid the single most useful fact on the page:
// the largest source of traffic buys nothing at all, while a much smaller one
// carries essentially all the revenue. Everything here is arranged around that
// comparison — the map shades traffic and dots money, the Markets table puts
// both on one row with a conversion column, and the observation strip names the
// biggest gap outright.
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { analyticsApi, type AnalyticsOverview, type MarketRow } from "@/api/marketing";
import { fmtKes } from "@/api/expenses";
import { KPI_GRID, KpiCard } from "@/pages/reports/reportShared";
import { WorldMapHero } from "./WorldMapHero";
import { MarketsTable } from "./MarketsTable";
import { AudiencePanel } from "./AudiencePanel";
import { deriveObservations, fmtConversion, fmtInt, type Observation } from "./insightsShared";

const RANGES = [
    { label: "7 days", days: 7 },
    { label: "30 days", days: 30 },
    { label: "90 days", days: 90 },
    { label: "12 months", days: 365 },
];

// Postgres count(*) can serialize as a JSON string ("2") through PDO — coerce
// every numeric once so formatting and the colour scales are safe regardless
// of the driver.
const num = (v: unknown) => Number(v ?? 0) || 0;

// ─── Observation strip ────────────────────────────────────────────────────────

const OBSERVATION_STYLE: Record<Observation["kind"], { ring: string; dot: string; tag: string }> = {
    gap:       { ring: "border-amber-200 bg-amber-50",     dot: "bg-amber",     tag: "Biggest gap" },
    win:       { ring: "border-success-light bg-success-light/40", dot: "bg-success", tag: "Converting" },
    unlocated: { ring: "border-line bg-surface-50",        dot: "bg-surface-400", tag: "Unattributed" },
};

function ObservationCard({ observation }: { observation: Observation }) {
    const style = OBSERVATION_STYLE[observation.kind];
    return (
        <div className={clsx("rounded-card border p-4", style.ring)}>
            <div className="flex items-center gap-2">
                <span className={clsx("h-1.5 w-1.5 rounded-full", style.dot)} />
                <span className="text-2xs font-semibold uppercase tracking-wider text-surface-500">{style.tag}</span>
            </div>
            <p className="mt-1.5 text-sm font-semibold text-surface-900">{observation.title}</p>
            <p className="mt-1 text-xs leading-relaxed text-surface-600">{observation.detail}</p>
        </div>
    );
}

// ─── Skeletons ────────────────────────────────────────────────────────────────
// Sized to the real thing so nothing jumps when the data lands.

const Shimmer = ({ className }: { className?: string }) => (
    <div className={clsx("animate-pulse rounded bg-surface-100", className)} />
);

function InsightsSkeleton() {
    return (
        <div className="space-y-6" aria-busy="true" aria-label="Loading insights">
            <div className={KPI_GRID}>
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="card card-body flex flex-col gap-2">
                        <Shimmer className="h-3 w-16" />
                        <Shimmer className="h-6 w-24" />
                        <Shimmer className="h-3 w-20" />
                    </div>
                ))}
            </div>
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {[0, 1].map((i) => (
                    <div key={i} className="rounded-card border border-line p-4">
                        <Shimmer className="h-3 w-24" />
                        <Shimmer className="mt-2 h-4 w-2/3" />
                        <Shimmer className="mt-2 h-3 w-full" />
                    </div>
                ))}
            </div>
            <div className="card overflow-hidden">
                <div className="px-5 pt-5">
                    <Shimmer className="h-4 w-72" />
                    <Shimmer className="mt-2 h-3 w-96 max-w-full" />
                </div>
                {/* Same 800×360 ratio and min-width as the map, so nothing moves
                    when the real thing arrives. */}
                <div className="mt-3 overflow-x-auto">
                    <div className="aspect-[800/360] w-full min-w-[560px] animate-pulse bg-surface-50" />
                </div>
                <div className="border-t border-line px-5 py-3">
                    <Shimmer className="h-3 w-64" />
                </div>
            </div>
            <div className="card overflow-hidden">
                <div className="px-5 pt-5">
                    <Shimmer className="h-4 w-28" />
                </div>
                <div className="mt-4 divide-y divide-line border-t border-line">
                    {Array.from({ length: 10 }, (_, i) => (
                        <div key={i} className="flex items-center gap-4 px-4 py-2.5">
                            <Shimmer className="h-4 flex-1" />
                            <Shimmer className="h-4 w-14" />
                            <Shimmer className="h-4 w-14" />
                            <Shimmer className="h-4 w-20" />
                            <Shimmer className="h-1.5 w-[18%]" />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function InsightsPage() {
    const [days, setDays] = useState(30);

    const { data, isLoading, isError, refetch, isFetching } = useQuery({
        queryKey: ["insights-overview", days],
        queryFn: () => analyticsApi.overview(days),
        staleTime: 60_000,
    });

    const d: AnalyticsOverview | undefined = data;
    const totals = {
        visits: num(d?.totals.visits),
        orders: num(d?.totals.orders),
        countries: num(d?.totals.countries),
        mobile_share: num(d?.totals.mobile_share),
    };

    const markets: MarketRow[] = (d?.markets ?? []).map((m) => ({
        country_code: (m.country_code ?? "").toUpperCase(),
        visits: num(m.visits),
        orders: num(m.orders),
        revenue: num(m.revenue),
        conversion_pct: m.conversion_pct === null || m.conversion_pct === undefined ? null : num(m.conversion_pct),
    }));

    const devices = (d?.devices ?? []).map((x) => ({ name: x.device_type, value: num(x.visits) }));
    const os = (d?.os ?? []).map((x) => ({ name: x.os, value: num(x.visits) }));

    // Revenue is summed off the markets rows rather than requested separately:
    // one number, one source, and it always agrees with the table above it.
    const revenue = markets.reduce((sum, m) => sum + m.revenue, 0);
    const siteConversion = totals.visits > 0 ? (totals.orders * 100) / totals.visits : null;
    const observations = deriveObservations(markets, totals);

    return (
        <div className="animate-fade-in">
            {/* ── Sticky header + range selector ──
                Bleeds to the gutters of the shell's content padding (px-3 /
                md:px-8 / 2xl:px-10 in AdminLayout) so the blur covers the full
                width as the page scrolls under it. */}
            <div className="sticky top-0 z-20 -mx-3 -mt-3 mb-6 border-b border-line bg-surface-canvas/90 px-3 pb-3 pt-3 backdrop-blur-sm md:-mx-8 md:-mt-8 md:px-8 md:pt-6 2xl:-mx-10 2xl:px-10">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0">
                        <h1 className="text-xl font-bold text-surface-900">Storefront Insights</h1>
                        <p className="text-sm text-surface-500">
                            Which countries browse, which countries buy, and the distance between the two.
                            Country and device only — no personal data or IP is stored.
                        </p>
                    </div>
                    <div
                        role="group"
                        aria-label="Reporting period"
                        className="inline-flex shrink-0 self-start rounded-lg border border-surface-200 bg-white p-0.5"
                    >
                        {RANGES.map((range) => (
                            <button
                                key={range.days}
                                type="button"
                                onClick={() => setDays(range.days)}
                                aria-pressed={days === range.days}
                                className={clsx(
                                    "whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition-colors",
                                    days === range.days
                                        ? "bg-brand-500 text-white shadow-sm"
                                        : "text-surface-500 hover:text-surface-800",
                                )}
                            >
                                {range.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {isLoading ? (
                <InsightsSkeleton />
            ) : isError ? (
                <div className="card card-body py-16 text-center">
                    <p className="text-sm font-medium text-surface-700">Couldn't load insights</p>
                    <p className="mx-auto mt-1 max-w-sm text-xs text-surface-500">
                        The analytics endpoint didn't respond. Nothing has been lost — the numbers are
                        recomputed on every request.
                    </p>
                    <button
                        type="button"
                        onClick={() => refetch()}
                        disabled={isFetching}
                        className="btn-secondary btn-sm mx-auto mt-4 disabled:opacity-50"
                    >
                        {isFetching ? "Retrying…" : "Try again"}
                    </button>
                </div>
            ) : (
                <div className="space-y-6">
                    {/* ── KPIs — conversion sits between the traffic and the money ── */}
                    <div className={KPI_GRID}>
                        <KpiCard
                            label="Visits"
                            value={fmtInt(totals.visits)}
                            sub={`${fmtInt(totals.countries)} ${totals.countries === 1 ? "country" : "countries"} · last ${days} days`}
                        />
                        <KpiCard
                            label="Online Orders"
                            value={fmtInt(totals.orders)}
                            sub="Storefront checkouts"
                            color="text-surface-900"
                        />
                        <KpiCard
                            label="Visit → Order"
                            value={fmtConversion(siteConversion === null ? null : Number(siteConversion.toFixed(2)))}
                            sub="Share of visits that end in an order"
                            color="text-brand-700"
                        />
                        <KpiCard
                            label="Online Revenue"
                            value={revenue > 0 ? fmtKes(revenue) : "—"}
                            sub="Attributed to a country"
                            color="text-success-dark"
                        />
                    </div>

                    {/* ── What the numbers say ── */}
                    {observations.length > 0 && (
                        <div
                            className={clsx(
                                "grid grid-cols-1 gap-4",
                                observations.length === 2 && "lg:grid-cols-2",
                                observations.length >= 3 && "lg:grid-cols-3",
                            )}
                        >
                            {observations.map((observation) => (
                                <ObservationCard key={observation.kind} observation={observation} />
                            ))}
                        </div>
                    )}

                    <WorldMapHero markets={markets} />

                    <MarketsTable markets={markets} />

                    <AudiencePanel devices={devices} os={os} mobileShare={totals.mobile_share} />

                    {/* ── Info bar: the two caveats that change how every number above reads ── */}
                    <div className="flex items-start gap-3 rounded-card border border-info-light bg-info-light/40 px-4 py-3">
                        <svg
                            className="mt-0.5 h-4 w-4 shrink-0 text-info-dark"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.75}
                            aria-hidden="true"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        <div className="text-xs leading-relaxed text-surface-600">
                            <p>
                                <span className="font-semibold text-surface-800">Revenue is reported in KES.</span>{" "}
                                Storefront prices auto-switch by visitor country — Kenya sees KES, Zambia sees
                                Kwacha, everywhere else sees US dollars, and a manual pick always wins. That is a
                                display currency: the checkout prices every cart from the KES catalogue, so order
                                totals are KES magnitudes and every row in this table is directly comparable.
                            </p>
                            <p className="mt-1.5">
                                <span className="font-semibold text-surface-800">Some countries can't be resolved.</span>{" "}
                                VPNs, privacy relays and bots come through without a usable country and are grouped
                                as <em>Unknown</em>. Orders can also arrive from a country with no recorded visit —
                                those show a conversion of “—” rather than a misleading figure.
                            </p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
