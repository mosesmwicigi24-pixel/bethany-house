/**
 * Customer Geography — "which country has more customers?"
 * Full page under Intelligence. Country league table resolved from order
 * geography + phone-prefix inference (see backend CountryInference).
 */
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
                                            <p className="text-xs text-surface-400 mt-1">
                                                {fmtNum(c.orders)} orders{c.revenue > 0 ? ` · ${money(c.revenue, c.currency)}` : ""}
                                            </p>
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
