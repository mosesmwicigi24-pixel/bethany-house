import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { serialsApi, type SerialStatus, type SerialFilters } from "@/api/serials";
import { Modal } from "@/components/ui/Modal";
import { get } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

const STATUS_META: Record<SerialStatus, { label: string; badge: string }> = {
    in_production: { label: "In Production", badge: "bg-amber-100 text-amber-700" },
    in_stock:     { label: "In Stock",      badge: "bg-emerald-100 text-emerald-700" },
    sold:         { label: "Sold",          badge: "bg-brand-100 text-brand-700" },
    dispatched:   { label: "Dispatched",    badge: "bg-indigo-100 text-indigo-700" },
    returned:     { label: "Returned",      badge: "bg-surface-200 text-surface-600" },
    cancelled:    { label: "Cancelled",     badge: "bg-danger-light text-danger" },
    missing:      { label: "Missing",       badge: "bg-danger text-white" },
};

const STATUS_ORDER: SerialStatus[] = [
    "in_production", "in_stock", "sold", "dispatched", "returned", "cancelled", "missing",
];

function StatusBadge({ status }: { status: SerialStatus }) {
    const m = STATUS_META[status] ?? { label: status, badge: "badge-neutral" };
    return <span className={clsx("badge text-2xs", m.badge)}>{m.label}</span>;
}

export default function ProductSerialsPage() {
    const [filters, setFilters] = useState<SerialFilters>({ per_page: 30 });
    const [page, setPage] = useState(1);
    const [showReconcile, setShowReconcile] = useState(false);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["product-serials", filters, page],
        queryFn: () => serialsApi.list({ ...filters, page }),
        placeholderData: (prev) => prev,
    });

    const serials = data?.data ?? [];
    const meta = data?.meta;
    const summary = data?.summary ?? {};
    const agedCount = data?.aged_count ?? 0;
    const agingDays = data?.aging_days ?? 90;

    const setFilter = (key: keyof SerialFilters, value: string) => {
        setFilters((prev) => ({ ...prev, [key]: value || undefined }));
        setPage(1);
    };
    const toggleAged = () => {
        setFilters((prev) => ({ ...prev, aged: prev.aged ? undefined : 1, status: undefined }));
        setPage(1);
    };

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Product Serials</h1>
                    <p className="page-subtitle">
                        {meta ? `${meta.total.toLocaleString()} tracked units` : "Per-item tracking"}
                        {isFetching && !isLoading && <span className="ml-2 text-brand-500 text-xs">Refreshing…</span>}
                    </p>
                </div>
                <button onClick={() => setShowReconcile(true)} className="btn-secondary btn-sm">
                    🔍 Reconcile stock
                </button>
            </div>

            {/* Aging alert */}
            {agedCount > 0 && (
                <button
                    onClick={toggleAged}
                    className={clsx(
                        "flex items-center gap-3 rounded-xl border px-4 py-3 text-left transition-colors",
                        filters.aged ? "border-amber-400 bg-amber-100" : "border-amber-200 bg-amber-50 hover:border-amber-300",
                    )}
                >
                    <span className="text-lg">⏳</span>
                    <span className="text-sm text-amber-900">
                        <span className="font-bold">{agedCount}</span> unit(s) have been on the shelf over{" "}
                        <span className="font-bold">{agingDays} days</span> without selling — {filters.aged ? "showing them" : "click to review"}.
                    </span>
                </button>
            )}

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
                {(filters.search || filters.status || filters.aged) && (
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
                                <th className="px-4 py-3">On Shelf</th>
                                <th className="px-4 py-3">Production Order</th>
                                <th className="px-4 py-3">Outlet</th>
                                <th className="px-4 py-3">Sold On</th>
                            </tr>
                        </thead>
                        <tbody>
                            {isLoading ? (
                                <tr><td colSpan={7} className="px-4 py-10 text-center text-surface-400">Loading…</td></tr>
                            ) : serials.length === 0 ? (
                                <tr><td colSpan={7} className="px-4 py-10 text-center text-surface-400">No serials yet. They're minted when a production order is approved.</td></tr>
                            ) : serials.map((s) => (
                                <tr key={s.id} className="border-b border-surface-50 hover:bg-surface-50/50">
                                    <td className="px-4 py-3 font-mono text-xs font-semibold text-surface-800">{s.serial_number}</td>
                                    <td className="px-4 py-3">
                                        <p className="text-surface-800">{s.product_name ?? "—"}</p>
                                        <p className="text-2xs text-surface-400 font-mono">{s.product_sku}</p>
                                    </td>
                                    <td className="px-4 py-3"><StatusBadge status={s.status} /></td>
                                    <td className="px-4 py-3 text-2xs">
                                        {s.status === "in_stock" && s.days_in_stock != null ? (
                                            <span className={clsx("tabular-nums", s.aged ? "text-amber-700 font-bold" : "text-surface-500")}>
                                                {s.days_in_stock}d{s.aged ? " ⏳" : ""}
                                            </span>
                                        ) : "—"}
                                    </td>
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

            {showReconcile && <ReconcileModal onClose={() => setShowReconcile(false)} />}
        </div>
    );
}

// ── Reconcile modal ─────────────────────────────────────────────────────────
// Scan/enter the serials physically on the shelf for a product; the system
// reports what's missing (possible loss) and what's unexpectedly present.
function ReconcileModal({ onClose }: { onClose: () => void }) {
    const toast = useToastStore();
    const [productId, setProductId] = useState<number | "">("");
    const [text, setText] = useState("");
    const [flagMissing, setFlagMissing] = useState(false);

    const { data: productsData } = useQuery({
        queryKey: ["products-simple"],
        queryFn: () => get<{ data: any[] }>("/v1/admin/products", { params: { per_page: "200" } }),
    });
    const products = [...(productsData?.data ?? [])].sort((a, b) =>
        (a?.en_translation?.name ?? a?.sku ?? "").localeCompare(b?.en_translation?.name ?? b?.sku ?? ""),
    );

    const mutation = useMutation({
        mutationFn: () =>
            serialsApi.reconcile({
                product_id: Number(productId),
                serials: text.split(/[\s,]+/).map((s) => s.trim()).filter(Boolean),
                flag_missing: flagMissing,
            }),
        onSuccess: (res) => toast[res.missing.length > 0 ? "error" : "success"](res.message),
        onError: (e: ApiError) => toast.error(e.message),
    });

    const result = mutation.data;

    return (
        <Modal open title="Reconcile stock" onClose={onClose}>
            <div className="space-y-4 p-5">
                <p className="text-xs text-surface-500">
                    Pick a product, then scan or paste the serial numbers physically on the shelf.
                    The system will tell you what's missing.
                </p>
                <div>
                    <label className="label">Product</label>
                    <select className="input" value={productId} onChange={(e) => setProductId(e.target.value ? Number(e.target.value) : "")}>
                        <option value="">— Select product —</option>
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>{p.en_translation?.name ?? p.sku} ({p.sku})</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="label">Serial numbers on the shelf</label>
                    <textarea
                        className="input h-32 font-mono text-xs"
                        placeholder="One per line (or comma/space separated)…"
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                    />
                </div>
                <label className="flex items-center gap-2 text-xs text-surface-600">
                    <input type="checkbox" checked={flagMissing} onChange={(e) => setFlagMissing(e.target.checked)} />
                    Flag missing units as lost (removes them from sellable stock)
                </label>

                {result && (
                    <div className="rounded-xl border border-surface-200 p-3 space-y-2 text-xs">
                        <p className="text-emerald-700">✓ {result.matched_count} accounted for</p>
                        {result.missing.length > 0 && (
                            <div>
                                <p className="font-semibold text-danger">{result.missing.length} missing{result.flagged_missing ? " (flagged lost)" : ""}:</p>
                                <p className="font-mono text-2xs text-surface-500">{result.missing.map((m) => m.serial_number).join(", ")}</p>
                            </div>
                        )}
                        {result.unexpected.length > 0 && (
                            <div>
                                <p className="font-semibold text-amber-700">{result.unexpected.length} unexpected (not in system stock):</p>
                                <p className="font-mono text-2xs text-surface-500">{result.unexpected.join(", ")}</p>
                            </div>
                        )}
                    </div>
                )}

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Close</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={!productId || !text.trim() || mutation.isPending}
                        className="btn-primary flex-1 disabled:opacity-50"
                    >
                        {mutation.isPending ? "Reconciling…" : "Reconcile"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
