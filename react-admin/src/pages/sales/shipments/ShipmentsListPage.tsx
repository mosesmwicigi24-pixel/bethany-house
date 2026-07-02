/**
 * ShipmentsListPage  - /sales/shipments
 *
 * Admin page that lists all order shipments, shows their tracking status,
 * lets staff copy or open the public tracking URL, and navigate to the
 * linked order. Uses the same design tokens as the rest of the admin UI.
 *
 * Permission: shipment.view
 */

import { useState, Fragment } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { shipmentsApi, SHIPMENT_STATUS_LABELS } from "@/api/shipments";
import type { Shipment, ShipmentStatus } from "@/api/shipments";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { DataTable } from "@/components/ui/DataTable";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ── Status colour map ─────────────────────────────────────────────────────────

const STATUS_BADGE: Record<string, string> = {
    order_confirmed:    "bg-blue-50 text-blue-700",
    processing:         "bg-blue-50 text-blue-700",
    ready_to_ship:      "bg-purple-50 text-purple-700",
    picked_up:          "bg-purple-50 text-purple-700",
    in_transit:         "badge-warning",
    out_for_delivery:   "badge-warning",
    delivery_attempted: "badge-danger",
    delivered:          "badge-success",
    exception:          "badge-danger",
    cancelled:          "badge-neutral",
};

const ALL_STATUSES: ShipmentStatus[] = [
    "order_confirmed", "processing", "ready_to_ship", "picked_up",
    "in_transit", "out_for_delivery", "delivery_attempted",
    "delivered", "exception", "cancelled",
];

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDate = (d?: string | null) =>
    d ? new Date(d).toLocaleDateString("en-KE", { dateStyle: "medium" }) : "-";

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ShipmentsListPage() {
    const navigate   = useNavigate();
    const toast      = useToastStore();
    const [search,   setSearch]   = useState("");
    const [status,   setStatus]   = useState<string>("");
    const [page,     setPage]     = useState(1);

    const params: Record<string, string> = { page: String(page), per_page: "20" };
    if (search)  params.search = search;
    if (status)  params.status = status;

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["shipments", params],
        queryFn:  () => shipmentsApi.list(params),
        staleTime: 30_000,
    });

    const shipments = data?.data ?? [];
    const meta      = data?.meta;

    // Group the current page of rows by shipped_at. Shipments not yet
    // dispatched (shipped_at is null) fall into a "No date" group rather than
    // being silently merged into "Today". Pagination, sort, and filters are
    // untouched - this only re-partitions the rows already fetched.
    const shipmentGroups = groupRowsByDate(shipments, (s) => s.shipped_at);

    // ── Copy tracking URL ────────────────────────────────────────────────────

    const copyTracking = async (s: Shipment) => {
        if (!s.tracking_token) {
            toast.error("No tracking token for this shipment.");
            return;
        }
        const url = `${window.location.origin}/track/${s.tracking_token}`;
        try {
            await navigator.clipboard.writeText(url);
            toast.success("Tracking link copied!");
        } catch {
            toast.error("Clipboard not available - try manually.");
        }
    };

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h1 className="page-title">Shipments</h1>
                    <p className="page-subtitle">
                        Track and manage all outbound deliveries.
                        {meta ? ` ${meta.total} shipments total.` : ""}
                    </p>
                </div>
            </div>

            {/* Filters */}
            <div className="card p-4">
                <div className="flex flex-wrap gap-3 items-center">
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                        placeholder="Search by order # or tracking #…"
                        className="input flex-1 min-w-48"
                    />
                    <select
                        value={status}
                        onChange={(e) => { setStatus(e.target.value); setPage(1); }}
                        className="input w-52"
                    >
                        <option value="">All statuses</option>
                        {ALL_STATUSES.map((s) => (
                            <option key={s} value={s}>{SHIPMENT_STATUS_LABELS[s]}</option>
                        ))}
                    </select>
                    {(search || status) && (
                        <button
                            onClick={() => { setSearch(""); setStatus(""); setPage(1); }}
                            className="btn-secondary btn-sm"
                        >
                            Clear
                        </button>
                    )}
                    {isFetching && !isLoading && <Spinner size="sm" />}
                </div>
            </div>

            {/* Table */}
            {isLoading ? (
                <div className="flex items-center justify-center h-40">
                    <Spinner size="lg" />
                </div>
            ) : shipments.length === 0 ? (
                <div className="card flex flex-col items-center justify-center py-20 text-surface-400 gap-3">
                    <div className="flex items-center justify-center w-16 h-16 bg-surface-100 rounded-2xl mb-3 text-surface-400">
                        <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    </div>
                    <p className="text-sm font-medium text-surface-500">No shipments found</p>
                    <p className="text-xs">Shipments are created from an order's detail page.</p>
                </div>
            ) : (
                <div className="card overflow-hidden p-0">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-xs">
                            <thead>
                                <tr className="bg-surface-50 border-b border-surface-100">
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Shipment #</th>
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Order</th>
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Customer</th>
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Carrier</th>
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Status</th>
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Shipped</th>
                                    <th className="text-left px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Est. Delivery</th>
                                    <th className="text-right px-4 py-3 font-semibold text-surface-500 uppercase tracking-wide text-2xs">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {shipmentGroups.map((group) => (
                                    <Fragment key={group.key}>
                                        <DateGroupHeaderRow label={group.label} colSpan={8} />
                                        {group.items.map((s) => (
                                    <tr
                                        key={s.id}
                                        className="hover:bg-surface-50 transition-colors cursor-pointer"
                                        onClick={() => navigate(`/sales/shipments/${s.id}`)}
                                    >
                                        <td className="px-4 py-3 font-mono font-medium text-surface-900">
                                            {s.shipment_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-brand-600 hover:underline">
                                                {s.order_number}
                                            </span>
                                            {s.outlet_name && (
                                                <p className="text-2xs text-surface-400">{s.outlet_name}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {s.customer_first_name
                                                ? `${s.customer_first_name} ${s.customer_last_name ?? ""}`.trim()
                                                : <span className="text-surface-400">-</span>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium">{s.carrier}</p>
                                            {s.tracking_number && (
                                                <p className="font-mono text-2xs text-surface-400">{s.tracking_number}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={clsx("badge text-2xs",
                                                STATUS_BADGE[s.status] ?? "badge-neutral")}>
                                                {SHIPMENT_STATUS_LABELS[s.status] ?? s.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-surface-600">
                                            {fmtDate(s.shipped_at)}
                                        </td>
                                        <td className="px-4 py-3 text-surface-600">
                                            {s.delivered_at
                                                ? <span className="text-success font-medium">✓ {fmtDate(s.delivered_at)}</span>
                                                : fmtDate(s.estimated_delivery_date)}
                                        </td>
                                        <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                            <div className="flex items-center justify-end gap-2">
                                                {s.tracking_token && (
                                                    <>
                                                        <button
                                                            onClick={() => copyTracking(s)}
                                                            title="Copy tracking link"
                                                            className="btn-ghost btn-icon btn-sm"
                                                            aria-label="Copy"
                                                        >
                                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                                            </svg>
                                                        </button>
                                                        <a
                                                            href={`/track/${s.tracking_token}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            title="Open public tracking page"
                                                            className="btn-ghost btn-icon btn-sm"
                                                            onClick={(e) => e.stopPropagation()}
                                                        >
                                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                            </svg>
                                                        </a>
                                                    </>
                                                )}
                                                <button
                                                    onClick={() => navigate(`/sales/shipments/${s.id}`)}
                                                    title="View shipment detail"
                                                    className="btn-ghost btn-icon btn-sm"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                        ))}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {meta && meta.last_page > 1 && (
                        <div className="flex items-center justify-between px-4 py-3 border-t border-surface-100">
                            <p className="text-xs text-surface-500">
                                Page {meta.current_page} of {meta.last_page} &nbsp;·&nbsp; {meta.total} total
                            </p>
                            <div className="flex gap-2">
                                <button
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    disabled={page <= 1}
                                    className="btn-secondary btn-sm disabled:opacity-40"
                                >
                                    ← Prev
                                </button>
                                <button
                                    onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                                    disabled={page >= meta.last_page}
                                    className="btn-secondary btn-sm disabled:opacity-40"
                                >
                                    Next →
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}