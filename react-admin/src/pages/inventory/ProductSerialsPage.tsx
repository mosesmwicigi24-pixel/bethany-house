import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { serialsApi, type SerialStatus, type SerialFilters } from "@/api/serials";

const STATUS_META: Record<SerialStatus, { label: string; badge: string }> = {
    in_production: { label: "In Production", badge: "bg-amber-100 text-amber-700" },
    in_stock:     { label: "In Stock",      badge: "bg-emerald-100 text-emerald-700" },
    sold:         { label: "Sold",          badge: "bg-brand-100 text-brand-700" },
    dispatched:   { label: "Dispatched",    badge: "bg-indigo-100 text-indigo-700" },
    returned:     { label: "Returned",      badge: "bg-surface-200 text-surface-600" },
    cancelled:    { label: "Cancelled",     badge: "bg-danger-light text-danger" },
};

const STATUS_ORDER: SerialStatus[] = [
    "in_production", "in_stock", "sold", "dispatched", "returned", "cancelled",
];

function StatusBadge({ status }: { status: SerialStatus }) {
    const m = STATUS_META[status] ?? { label: status, badge: "badge-neutral" };
    return <span className={clsx("badge text-2xs", m.badge)}>{m.label}</span>;
}

export default function ProductSerialsPage() {
    const [filters, setFilters] = useState<SerialFilters>({ per_page: 30 });
    const [page, setPage] = useState(1);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["product-serials", filters, page],
        queryFn: () => serialsApi.list({ ...filters, page }),
        placeholderData: (prev) => prev,
    });

    const serials = data?.data ?? [];
    const meta = data?.meta;
    const summary = data?.summary ?? {};

    const setFilter = (key: keyof SerialFilters, value: string) => {
        setFilters((prev) => ({ ...prev, [key]: value || undefined }));
        setPage(1);
    };

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            <div>
                <h1 className="page-title">Product Serials</h1>
                <p className="page-subtitle">
                    {meta ? `${meta.total.toLocaleString()} tracked units` : "Per-item tracking"}
                    {isFetching && !isLoading && <span className="ml-2 text-brand-500 text-xs">Refreshing…</span>}
                </p>
            </div>

            {/* Status summary */}
            <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
                {STATUS_ORDER.map((s) => (
                    <button
                        key={s}
                        onClick={() => setFilter("status", filters.status === s ? "" : s)}
                        className={clsx(
                            "rounded-xl border p-3 text-left transition-colors",
                            filters.status === s ? "border-brand-400 bg-brand-50" : "border-surface-200 hover:border-brand-300",
                        )}
                    >
                        <p className="text-2xs uppercase tracking-widest text-surface-400">{STATUS_META[s].label}</p>
                        <p className="text-xl font-bold text-surface-900 tabular-nums">{summary[s] ?? 0}</p>
                    </button>
                ))}
            </div>

            {/* Filters */}
            <div className="card card-body flex flex-wrap items-center gap-3">
                <input
                    className="input flex-1 min-w-[200px]"
                    placeholder="Search serial number…"
                    value={filters.search ?? ""}
                    onChange={(e) => setFilter("search", e.target.value)}
                />
                <select className="input w-44" value={filters.status ?? ""} onChange={(e) => setFilter("status", e.target.value)}>
                    <option value="">All statuses</option>
                    {STATUS_ORDER.map((s) => <option key={s} value={s}>{STATUS_META[s].label}</option>)}
                </select>
                {(filters.search || filters.status) && (
                    <button onClick={() => { setFilters({ per_page: 30 }); setPage(1); }} className="btn-ghost btn-sm text-danger">
                        Clear
                    </button>
                )}
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-2xs uppercase tracking-widest text-surface-400 border-b border-surface-100">
                                <th className="px-4 py-3">Serial</th>
                                <th className="px-4 py-3">Product</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3">Production Order</th>
                                <th className="px-4 py-3">Outlet</th>
                                <th className="px-4 py-3">Sold On</th>
                            </tr>
                        </thead>
                        <tbody>
                            {isLoading ? (
                                <tr><td colSpan={6} className="px-4 py-10 text-center text-surface-400">Loading…</td></tr>
                            ) : serials.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-10 text-center text-surface-400">No serials yet. They're minted when a production order is approved.</td></tr>
                            ) : serials.map((s) => (
                                <tr key={s.id} className="border-b border-surface-50 hover:bg-surface-50/50">
                                    <td className="px-4 py-3 font-mono text-xs font-semibold text-surface-800">{s.serial_number}</td>
                                    <td className="px-4 py-3">
                                        <p className="text-surface-800">{s.product_name ?? "—"}</p>
                                        <p className="text-2xs text-surface-400 font-mono">{s.product_sku}</p>
                                    </td>
                                    <td className="px-4 py-3"><StatusBadge status={s.status} /></td>
                                    <td className="px-4 py-3 font-mono text-2xs text-surface-500">{s.production_order_number ?? "—"}</td>
                                    <td className="px-4 py-3 text-surface-600">{s.outlet_name ?? "—"}</td>
                                    <td className="px-4 py-3 text-2xs text-surface-500">
                                        {s.order_number ? <span className="font-mono">{s.order_number}</span> : "—"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {meta && meta.last_page > 1 && (
                    <div className="flex items-center justify-between px-4 py-3 border-t border-surface-100 text-xs">
                        <span className="text-surface-400">Page {meta.current_page} of {meta.last_page}</span>
                        <div className="flex gap-2">
                            <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="btn-secondary btn-sm disabled:opacity-40">Prev</button>
                            <button disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} className="btn-secondary btn-sm disabled:opacity-40">Next</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
