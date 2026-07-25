/**
 * Customer Geography — "which country has more customers?"
 * Full page under Intelligence. Country league table resolved from order
 * geography + phone-prefix inference (see backend CountryInference).
 */
import { useState, type ReactNode } from "react";
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

// A sortable metric column header — a coloured icon chip + label + sort arrow.
// Clicking ranks the table by this column, highest first.
function ThMetric({ tone, icon, label, active, onSort }: {
    tone: string; icon: ReactNode; label: string; active: boolean; onSort: () => void;
}) {
    return (
        <th className="px-4 py-3 text-right whitespace-nowrap">
            <button type="button" onClick={onSort}
                className={`inline-flex items-center gap-1.5 text-2xs font-bold uppercase tracking-widest transition-colors ${active ? "text-surface-800" : "text-surface-400 hover:text-surface-600"}`}>
                <span className={`w-4 h-4 rounded flex items-center justify-center shrink-0 ${tone}`} aria-hidden>{icon}</span>
                {label}
                <span className={`text-[9px] ${active ? "opacity-100" : "opacity-0"}`} aria-hidden>▼</span>
            </button>
        </th>
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

    // Sort the table, highest first — default customers, or by a metric the
    // user picks (landed / spending). Revenue is the tiebreaker so "highest
    // landing" still ranks the bigger spenders first among ties.
    type SortKey = "customers" | "visits" | "carts" | "orders" | "revenue";
    const [sortKey, setSortKey] = useState<SortKey>("customers");
    const val = (c: CountryStat, k: SortKey) => c[k];
    const rows = [...countries].sort(
        (a, b) => (val(b, sortKey) - val(a, sortKey)) || (b.revenue - a.revenue) || (b.customers - a.customers),
    );

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
                        <div className="px-5 py-3 border-b border-surface-100 flex items-center justify-between gap-3">
                            <h2 className="font-semibold text-surface-900 text-sm">Countries</h2>
                            <span className="text-2xs text-surface-400">Tap a column to rank — highest first</span>
                        </div>
                        {countries.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-surface-400">No customer location data yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm min-w-[760px]">
                                    <thead>
                                        <tr className="bg-surface-50/70 border-b border-surface-100">
                                            <th className="text-left px-5 py-3 text-2xs font-bold uppercase tracking-widest text-surface-400">Country</th>
                                            <ThMetric tone="bg-violet-50 text-violet-600"  icon={<LandedIcon />}  label="Landed"  active={sortKey === "visits"}  onSort={() => setSortKey("visits")} />
                                            <ThMetric tone="bg-sky-50 text-sky-600"         icon={<CartIcon />}    label="Carts"   active={sortKey === "carts"}   onSort={() => setSortKey("carts")} />
                                            <ThMetric tone="bg-emerald-50 text-emerald-600" icon={<OrdersIcon />}  label="Orders"  active={sortKey === "orders"}  onSort={() => setSortKey("orders")} />
                                            <ThMetric tone="bg-amber-50 text-amber-600"     icon={<RevenueIcon />} label="Revenue" active={sortKey === "revenue"} onSort={() => setSortKey("revenue")} />
                                            <th className="text-right px-5 py-3 whitespace-nowrap">
                                                <button type="button" onClick={() => setSortKey("customers")}
                                                    className={`inline-flex items-center gap-1.5 text-2xs font-bold uppercase tracking-widest transition-colors ${sortKey === "customers" ? "text-surface-800" : "text-surface-400 hover:text-surface-600"}`}>
                                                    Customers
                                                    <span className={`text-[9px] ${sortKey === "customers" ? "opacity-100" : "opacity-0"}`} aria-hidden>▼</span>
                                                </button>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {rows.map((c: CountryStat) => (
                                            <tr key={c.country_code} className="hover:bg-surface-50/50 transition-colors">
                                                {/* Identity — flag tile + country, like the member avatar + name */}
                                                <td className="px-5 py-3.5">
                                                    <div className="flex items-center gap-3">
                                                        <span className="w-10 h-10 rounded-xl bg-surface-50 border border-surface-100 flex items-center justify-center text-xl shrink-0" aria-hidden>{flagOf(c.country_code)}</span>
                                                        <div className="min-w-0">
                                                            <p className="font-semibold text-surface-900 truncate">{c.country_name}</p>
                                                            <p className="text-2xs text-surface-400 font-mono uppercase tracking-wide">{c.country_code}</p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5 text-right tabular-nums font-semibold text-surface-800">{fmtNum(c.visits)}</td>
                                                <td className="px-4 py-3.5 text-right tabular-nums font-semibold text-surface-800">{fmtNum(c.carts)}</td>
                                                <td className="px-4 py-3.5 text-right tabular-nums font-semibold text-surface-800">{fmtNum(c.orders)}</td>
                                                <td className="px-4 py-3.5 text-right tabular-nums font-bold text-surface-900 whitespace-nowrap">{c.revenue > 0 ? money(c.revenue, c.currency) : <span className="text-surface-300 font-normal">—</span>}</td>
                                                {/* Customers — number + share bar, like the Progress column */}
                                                <td className="px-5 py-3.5">
                                                    <div className="flex items-center justify-end gap-2.5">
                                                        <div className="w-20 h-2 rounded-full bg-surface-100 overflow-hidden hidden md:block">
                                                            <div className="h-full rounded-full bg-brand-500" style={{ width: `${(c.customers / maxCust) * 100}%` }} />
                                                        </div>
                                                        <span className="tabular-nums font-bold text-surface-900 w-8 text-right">{fmtNum(c.customers)}</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
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
