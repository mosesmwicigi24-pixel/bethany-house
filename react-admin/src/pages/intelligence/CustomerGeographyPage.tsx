/**
 * Customer Geography — "which country has more customers?"
 * Full page under Intelligence. Country league table resolved from order
 * geography + phone-prefix inference (see backend CountryInference).
 */
import type { ReactNode } from "react";
import { useQuery } from "@tanstack/react-query";
import { intelligenceApi, type CountryStat } from "@/api/intelligence";
import { Spinner } from "@/components/ui/Spinner";

const fmtNum = (n: number) => new Intl.NumberFormat("en-KE").format(n);

function flagOf(code: string): string {
    if (!code || code.length !== 2) return "🏳️";
    return String.fromCodePoint(...[...code.toUpperCase()].map(c => 0x1f1e6 + c.charCodeAt(0) - 65));
}
function money(n: number, currency: string | null): string {
    try {
        return new Intl.NumberFormat("en-KE", { style: "currency", currency: currency || "KES", maximumFractionDigits: 0 }).format(n);
    } catch {
        return `${currency || ""} ${fmtNum(Math.round(n))}`.trim();
    }
}

// One funnel stat — a coloured icon chip + value. Each stage gets its own hue
// so the row reads at a glance: landed → cart → orders → revenue.
function FunnelStat({ tone, icon, value, label }: {
    tone: string; icon: ReactNode; value: string; label: string;
}) {
    return (
        <span className="inline-flex items-center gap-1.5" title={label}>
            <span className={`w-5 h-5 rounded-md flex items-center justify-center shrink-0 ${tone}`}>{icon}</span>
            <span className="text-xs font-semibold text-surface-800 tabular-nums">{value}</span>
            <span className="text-2xs text-surface-400 hidden sm:inline">{label}</span>
        </span>
    );
}

const ic = { className: "w-3 h-3", fill: "none", viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
const LandedIcon  = () => <svg {...ic}><path d="M12 21s-6-5.686-6-10a6 6 0 1112 0c0 4.314-6 10-6 10z"/><circle cx="12" cy="11" r="2"/></svg>;
const CartIcon    = () => <svg {...ic}><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>;
const OrdersIcon  = () => <svg {...ic}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>;
const RevenueIcon = () => <svg {...ic}><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>;

function Kpi({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
    return (
        <div className="bg-white rounded-2xl border border-surface-200 p-5">
            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">{label}</p>
            <p className="text-2xl font-bold text-surface-900 mt-1">{value}</p>
            {sub && <p className="text-xs text-surface-400 mt-0.5">{sub}</p>}
        </div>
    );
}

export default function CustomerGeographyPage() {
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "geography"],
        queryFn:  intelligenceApi.customerGeography,
        staleTime: 5 * 60_000,
    });

    const countries = data?.countries ?? [];
    const s = data?.summary;
    const maxCust = Math.max(1, ...countries.map(c => c.customers));

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header">
                <h1 className="page-title">Customer Geography</h1>
                <p className="page-subtitle">Which countries your customers are in — resolved from order countries and phone numbers.</p>
            </div>

            {isLoading ? <div className="py-16 flex justify-center"><Spinner /></div> : (
                <>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <Kpi label="Located customers" value={fmtNum(s?.located_customers ?? 0)} sub={`across ${s?.distinct_countries ?? 0} countries`} />
                        <Kpi label="Top country" value={s?.top_country_name ? `${flagOf(s.top_country_code ?? "")} ${s.top_country_name}` : "—"} />
                        <Kpi label="Countries" value={fmtNum(s?.distinct_countries ?? 0)} />
                        <Kpi label="Unlocated" value={fmtNum(s?.unlocated_customers ?? 0)} sub="no country or phone" />
                    </div>

                    <div className="bg-white rounded-2xl border border-surface-200 overflow-hidden">
                        <div className="px-5 py-3 border-b border-surface-100">
                            <h2 className="font-semibold text-surface-900 text-sm">Countries by customers</h2>
                        </div>
                        {countries.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-surface-400">No customer location data yet.</p>
                        ) : (
                            <div className="divide-y divide-surface-50">
                                {countries.map((c: CountryStat) => (
                                    <div key={c.country_code} className="flex items-center gap-4 px-5 py-3.5">
                                        <span className="text-2xl shrink-0" aria-hidden>{flagOf(c.country_code)}</span>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between gap-3">
                                                <p className="text-sm font-semibold text-surface-900 truncate">{c.country_name}</p>
                                                <p className="text-sm font-bold text-surface-900 shrink-0">
                                                    {fmtNum(c.customers)} <span className="text-xs font-normal text-surface-400">{c.customers === 1 ? "customer" : "customers"}</span>
                                                </p>
                                            </div>
                                            <div className="mt-1.5 h-2 rounded-full bg-surface-100 overflow-hidden">
                                                <div className="h-full rounded-full bg-brand-500" style={{ width: `${(c.customers / maxCust) * 100}%` }} />
                                            </div>
                                            {/* Funnel in one row: landed → cart → orders → revenue */}
                                            <div className="flex items-center gap-x-4 gap-y-1.5 flex-wrap mt-2">
                                                <FunnelStat tone="bg-violet-50 text-violet-600"  icon={<LandedIcon />}  value={fmtNum(c.visits)} label="landed" />
                                                <FunnelStat tone="bg-sky-50 text-sky-600"        icon={<CartIcon />}    value={fmtNum(c.carts)}  label="carts" />
                                                <FunnelStat tone="bg-emerald-50 text-emerald-600" icon={<OrdersIcon />} value={fmtNum(c.orders)} label="orders" />
                                                {c.revenue > 0 && (
                                                    <FunnelStat tone="bg-amber-50 text-amber-600" icon={<RevenueIcon />} value={money(c.revenue, c.currency)} label="revenue" />
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <p className="text-xs text-surface-400 px-1">
                        Country is each customer's most recent order location, falling back to their phone's dialing prefix.
                    </p>
                </>
            )}
        </div>
    );
}
