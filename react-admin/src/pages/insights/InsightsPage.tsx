// src/pages/insights/InsightsPage.tsx
//
// Storefront Insights — who visits bethanyhouse.co.ke, where from, on what
// device, and which countries actually buy. Fed by
// GET /api/v1/admin/analytics/overview (AnalyticsController). Privacy by design:
// the hub stores country + device only, never an IP (Kenya DPA / Privacy Policy).
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { analyticsApi, type AnalyticsOverview } from "@/api/marketing";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import {
    PieChart,
    Pie,
    Cell,
    BarChart,
    Bar,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
} from "recharts";
import { clsx } from "clsx";
import {
    KPI_GRID,
    KpiCard,
    SectionHeader,
    TableWrapper,
    EmptyRow,
    ProgressBar,
    CHART_COLORS,
    TH,
    TH_R,
} from "@/pages/reports/reportShared";

// ─── Country helpers (flag + English name from the ISO-3166 alpha-2) ──────────
const regionNames =
    typeof Intl !== "undefined" && "DisplayNames" in Intl
        ? new Intl.DisplayNames(["en"], { type: "region" })
        : null;

function flagOf(cc?: string | null): string {
    if (!cc || cc.length !== 2) return "🌐";
    const base = 0x1f1e6;
    const up = cc.toUpperCase();
    return String.fromCodePoint(
        base + up.charCodeAt(0) - 65,
        base + up.charCodeAt(1) - 65,
    );
}
function nameOf(cc?: string | null): string {
    if (!cc) return "Unknown";
    try {
        return regionNames?.of(cc.toUpperCase()) ?? cc.toUpperCase();
    } catch {
        return cc.toUpperCase();
    }
}

const RANGES = [
    { label: "7 days", days: 7 },
    { label: "30 days", days: 30 },
    { label: "90 days", days: 90 },
    { label: "12 months", days: 365 },
];

// Rough purchasing-power read from the device mix: mostly-desktop traffic skews
// higher-income / diaspora; mostly-mobile skews local mass-market.
function powerSignal(mobileShare: number): { label: string; color: string } {
    if (mobileShare >= 75)
        return { label: "Mostly mobile — mass-market reach", color: "text-info" };
    if (mobileShare <= 40)
        return { label: "Desktop-heavy — higher-intent / diaspora", color: "text-brand-600" };
    return { label: "Balanced mobile + desktop", color: "text-success" };
}

export default function InsightsPage() {
    const [days, setDays] = useState(30);

    const { data, isLoading, isError } = useQuery({
        queryKey: ["insights-overview", days],
        queryFn: () => analyticsApi.overview(days),
        staleTime: 60_000,
    });

    const d: AnalyticsOverview | undefined = data;
    // Postgres count(*) can serialize as a JSON string ("2") through PDO — coerce
    // every numeric once so formatting + charts are safe regardless of the driver.
    const n = (v: unknown) => Number(v ?? 0) || 0;
    const totals = {
        visits: n(d?.totals.visits),
        orders: n(d?.totals.orders),
        countries: n(d?.totals.countries),
        mobile_share: n(d?.totals.mobile_share),
    };
    const visitors = (d?.visitors_by_country ?? []).map((c) => ({ cc: c.country_code, visits: n(c.visits) }));
    const buyers = (d?.buyers_by_country ?? []).map((c) => ({ cc: c.country_code, orders: n(c.orders), revenue: n(c.revenue) }));
    const deviceData = (d?.devices ?? []).map((x) => ({ name: x.device_type, value: n(x.visits) }));
    const osData = (d?.os ?? []).map((x) => ({ name: x.os, value: n(x.visits) }));
    const maxVisit = Math.max(1, ...visitors.map((c) => c.visits));
    const maxOrders = Math.max(1, ...buyers.map((c) => c.orders));
    const power = powerSignal(totals.mobile_share);

    return (
        <div className="space-y-6 animate-fade-in">
            {/* ── Header + range selector ── */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold text-surface-900">Insights</h1>
                    <p className="text-sm text-surface-500">
                        Where storefront visitors come from, what they browse on, and
                        which countries are buying. Country + device only — no personal
                        data or IP is stored.
                    </p>
                </div>
                <div className="inline-flex rounded-lg border border-surface-200 bg-white p-0.5 self-start shrink-0">
                    {RANGES.map((r) => (
                        <button
                            key={r.days}
                            onClick={() => setDays(r.days)}
                            className={clsx(
                                "px-3 py-1.5 text-sm font-medium rounded-md transition-colors whitespace-nowrap",
                                days === r.days
                                    ? "bg-brand-500 text-white shadow-sm"
                                    : "text-surface-500 hover:text-surface-800",
                            )}
                        >
                            {r.label}
                        </button>
                    ))}
                </div>
            </div>

            {isLoading ? (
                <div className="flex justify-center py-24">
                    <Spinner />
                </div>
            ) : isError ? (
                <div className="card card-body text-center text-sm text-surface-500 py-16">
                    Couldn't load insights. Try again shortly.
                </div>
            ) : (
                <>
                    {/* ── KPIs ── */}
                    <div className={KPI_GRID}>
                        <KpiCard label="Visits" value={totals.visits.toLocaleString()} sub={`Last ${days} days`} />
                        <KpiCard label="Countries Reached" value={totals.countries} sub="Distinct visitor countries" color="text-brand-600" />
                        <KpiCard label="Mobile Share" value={`${totals.mobile_share}%`} sub={power.label} color={power.color} />
                        <KpiCard label="Online Orders" value={totals.orders} sub="Storefront checkouts" color="text-success" />
                    </div>

                    {/* ── Visitors vs Buyers by country ── */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Visitors by country */}
                        <div className="card overflow-hidden">
                            <div className="px-5 pt-5">
                                <SectionHeader title="Where visitors come from" />
                            </div>
                            <TableWrapper>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-y border-surface-100 bg-surface-50/50">
                                            <th className={TH}>Country</th>
                                            <th className={TH_R}>Visits</th>
                                            <th className={clsx(TH, "w-1/3")}>Share</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {visitors.length === 0 ? (
                                            <EmptyRow cols={3} text="No visits recorded yet." />
                                        ) : (
                                            visitors.map((c) => (
                                                <tr key={c.cc} className="hover:bg-surface-50/50">
                                                    <td className="px-4 py-3">
                                                        <span className="mr-2 text-base align-middle">{flagOf(c.cc)}</span>
                                                        <span className="font-medium text-surface-800 align-middle">{nameOf(c.cc)}</span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums font-semibold text-surface-900">{c.visits.toLocaleString()}</td>
                                                    <td className="px-4 py-3">
                                                        <ProgressBar value={c.visits} max={maxVisit} color={CHART_COLORS[0]} />
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </TableWrapper>
                        </div>

                        {/* Buyers by country */}
                        <div className="card overflow-hidden">
                            <div className="px-5 pt-5">
                                <SectionHeader title="Which countries are buying" />
                            </div>
                            <TableWrapper>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-y border-surface-100 bg-surface-50/50">
                                            <th className={TH}>Country</th>
                                            <th className={TH_R}>Orders</th>
                                            <th className={TH_R}>Revenue</th>
                                            <th className={clsx(TH, "w-1/4")}>Share</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {buyers.length === 0 ? (
                                            <EmptyRow cols={4} text="No online orders in this range yet." />
                                        ) : (
                                            buyers.map((c) => (
                                                <tr key={c.cc} className="hover:bg-surface-50/50">
                                                    <td className="px-4 py-3">
                                                        <span className="mr-2 text-base align-middle">{flagOf(c.cc)}</span>
                                                        <span className="font-medium text-surface-800 align-middle">{nameOf(c.cc)}</span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums font-semibold text-surface-900">{c.orders}</td>
                                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">{fmtKes(c.revenue)}</td>
                                                    <td className="px-4 py-3">
                                                        <ProgressBar value={c.orders} max={maxOrders} color={CHART_COLORS[4]} />
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </TableWrapper>
                        </div>
                    </div>

                    {/* ── Device + OS mix (purchasing-power signal) ── */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div className="card p-5">
                            <SectionHeader title="Devices" />
                            {deviceData.length === 0 ? (
                                <p className="text-sm text-surface-400 py-10 text-center">No device data yet.</p>
                            ) : (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                                    <ResponsiveContainer width="100%" height={200}>
                                        <PieChart>
                                            <Pie
                                                data={deviceData}
                                                dataKey="value"
                                                nameKey="name"
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={45}
                                                outerRadius={75}
                                                paddingAngle={2}
                                            >
                                                {deviceData.map((_, i) => (
                                                    <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />
                                                ))}
                                            </Pie>
                                            <Tooltip />
                                        </PieChart>
                                    </ResponsiveContainer>
                                    <div className="space-y-2">
                                        {(() => {
                                            const total = deviceData.reduce((s, x) => s + x.value, 0) || 1;
                                            return deviceData.map((seg, i) => {
                                                const pct = Math.round((seg.value / total) * 100);
                                                return (
                                                    <div key={seg.name}>
                                                        <div className="flex justify-between text-sm mb-1">
                                                            <span className="font-medium text-surface-700 capitalize">{seg.name}</span>
                                                            <span className="tabular-nums text-surface-500">{seg.value.toLocaleString()} · {pct}%</span>
                                                        </div>
                                                        <ProgressBar value={seg.value} max={total} color={CHART_COLORS[i % CHART_COLORS.length]} />
                                                    </div>
                                                );
                                            });
                                        })()}
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="card p-5">
                            <SectionHeader title="Operating systems" />
                            {osData.length === 0 ? (
                                <p className="text-sm text-surface-400 py-10 text-center">No OS data yet.</p>
                            ) : (
                                <ResponsiveContainer width="100%" height={220}>
                                    <BarChart data={osData} layout="vertical">
                                        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" horizontal={false} />
                                        <XAxis type="number" tick={{ fontSize: 11 }} allowDecimals={false} />
                                        <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} width={72} />
                                        <Tooltip />
                                        <Bar dataKey="value" name="Visits" fill={CHART_COLORS[1]} radius={[0, 3, 3, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            )}
                        </div>
                    </div>

                    <p className="text-xs text-surface-400 px-1">
                        Prices on the storefront auto-switch by visitor country — Kenya sees
                        KES, Zambia sees Kwacha, everywhere else sees US dollars (a manual
                        pick always wins). Device mix is a rough purchasing-power read, not a
                        precise measure.
                    </p>
                </>
            )}
        </div>
    );
}
