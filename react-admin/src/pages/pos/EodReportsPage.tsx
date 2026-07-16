/**
 * EodReportsPage.tsx
 *
 * Admin view: lists all submitted cashier EoD reports across outlets, users,
 * and dates. Clicking a row opens a right-side drawer showing the full report —
 * sentiments (rendered as HTML from the WYSIWYG), per-order notes, and KPIs.
 *
 * Route: /pos/eod-reports
 * Permission: pos.access (admin / outlet_manager)
 */

import { useState, useEffect, Fragment } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { Spinner } from "@/components/ui/Spinner";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ── Types ─────────────────────────────────────────────────────────────────────

interface EodReportRow {
    id: number;
    report_date: string;
    submitted_at: string;
    outlet_id: number;
    outlet_name: string;
    user_id: number;
    user_name: string;
    order_count: number;
    total_sales: number;
    total_paid: number;
    total_balance: number;
    has_sentiments: boolean;
    note_count: number;
}

interface EodComment {
    id: number;
    body: string;
    user_id: number;
    user_name: string;
    created_at: string;
}

interface EodReportDetail extends EodReportRow {
    sentiments: string;           // HTML from WYSIWYG
    order_notes: Record<string, string>;
    acknowledged_at: string | null;
    acknowledged_by: number | null;
    comments: EodComment[];
    orders: {
        id: number;
        order_number: string;
        customer_name: string;
        total_amount: number;
        amount_paid: number;
        balance: number;
        payment_status: string;
        eod_note?: string | null;
    }[];
}

interface OutletOption {
    id: number;
    name: string;
}

interface UserOption {
    id: number;
    name: string;
}

interface EodReportMeta {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmt = (n: number) =>
    n.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtDate = (iso: string) =>
    new Date(iso + "T00:00:00").toLocaleDateString("en-KE", {
        weekday: "short", day: "numeric", month: "short", year: "numeric",
    });

const fmtDatetime = (iso: string) =>
    new Date(iso).toLocaleString("en-KE", {
        day: "numeric", month: "short", year: "numeric",
        hour: "2-digit", minute: "2-digit",
    });

// ── Component ─────────────────────────────────────────────────────────────────

export default function EodReportsPage() {
    const today = new Date().toISOString().split("T")[0];
    const thirtyDaysAgo = new Date(Date.now() - 30 * 86400_000).toISOString().split("T")[0];

    const [dateFrom, setDateFrom]   = useState(thirtyDaysAgo);
    const [dateTo, setDateTo]       = useState(today);
    const [outletId, setOutletId]   = useState<string>("");
    const [userId, setUserId]       = useState<string>("");
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [page, setPage]           = useState(1);
    const perPage = 25;

    // Any filter change resets to page 1, same as the other list pages.
    const updateDateFrom = (v: string) => { setDateFrom(v); setPage(1); };
    const updateDateTo   = (v: string) => { setDateTo(v); setPage(1); };
    const updateOutlet   = (v: string) => { setOutletId(v); setUserId(""); setPage(1); };
    const updateUser     = (v: string) => { setUserId(v); setPage(1); };

    // ── Fetch filter options ──────────────────────────────────────────────────

    const { data: outletsData } = useQuery({
        queryKey: ["pos-outlets-filter"],
        queryFn: () => get<{ data: OutletOption[] }>("/v1/admin/pos/outlets"),
        staleTime: 5 * 60_000,
    });

    // ── Fetch report list ─────────────────────────────────────────────────────

    const { data, isLoading, isError } = useQuery({
        queryKey: ["eod-reports", dateFrom, dateTo, outletId, userId, page],
        queryFn: () =>
            get<{ data: EodReportRow[]; users: UserOption[]; meta: EodReportMeta }>(
                "/v1/admin/pos/reports/eod-admin",
                {
                    params: {
                        from:       dateFrom,
                        to:         dateTo,
                        ...(outletId ? { outlet_id: outletId } : {}),
                        ...(userId   ? { user_id: userId }     : {}),
                        page:       String(page),
                        per_page:   String(perPage),
                    },
                },
            ),
        placeholderData: (prev) => prev,
    });

    const reports = data?.data ?? [];
    const userOptions = data?.users ?? [];
    const meta = data?.meta;

    // Group the current page of rows by report_date. Pagination and filters
    // are untouched - this only re-partitions the rows already fetched.
    const reportGroups = groupRowsByDate(reports, (r) => r.report_date);

    // ── Fetch detail ──────────────────────────────────────────────────────────

    const { data: detailData, isLoading: detailLoading } = useQuery({
        queryKey: ["eod-report-detail", selectedId],
        queryFn: () =>
            get<{ report: EodReportDetail }>(
                `/v1/admin/pos/reports/eod-admin/${selectedId}`,
            ),
        enabled: selectedId !== null,
    });

    const detail = detailData?.report;

    // Deep link. A chip in a channel and the "you were replied to" notification
    // both point at /pos/eod-reports?report=<id>; without this they would land on
    // the list and do nothing, which is the same dead end the page already was.
    const [searchParams, setSearchParams] = useSearchParams();
    useEffect(() => {
        const wanted = Number(searchParams.get("report"));
        if (wanted && wanted !== selectedId) setSelectedId(wanted);
    }, [searchParams]);

    // ── Closing the loop ──────────────────────────────────────────────────────
    // Reports used to be write-only: the clerk submitted into a void and had no
    // way to know she had been read, let alone answered. Acknowledging is the
    // countersignature; the thread is the conversation, and it lives ON the
    // report so that in six months it still explains itself.
    const qc = useQueryClient();
    const [commentBody, setCommentBody] = useState("");

    const ackMutation = useMutation({
        mutationFn: () => post(`/v1/admin/pos/reports/eod-admin/${selectedId}/acknowledge`, {}),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["eod-report-detail", selectedId] });
            qc.invalidateQueries({ queryKey: ["eod-reports"] });
        },
    });

    const commentMutation = useMutation({
        mutationFn: (body: string) => post(`/v1/admin/pos/reports/eod/${selectedId}/comments`, { body }),
        onSuccess: () => {
            setCommentBody("");
            qc.invalidateQueries({ queryKey: ["eod-report-detail", selectedId] });
        },
    });

    return (
        <div className="flex h-full">

            {/* ── Main panel ── */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">

                {/* Header */}
                <div className="px-4 sm:px-6 pt-5 pb-4 border-b border-surface-100">
                    <div className="flex items-start justify-between gap-4 flex-wrap">
                        <div>
                            <h1 className="text-lg sm:text-xl font-bold text-surface-900">EoD Reports</h1>
                            <p className="text-xs text-surface-400 mt-0.5">
                                End-of-day reports submitted by cashiers
                            </p>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="mt-4 grid grid-cols-2 sm:flex sm:flex-wrap gap-2 items-end">
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-medium text-surface-500">From</label>
                            <input
                                type="date"
                                value={dateFrom}
                                max={dateTo}
                                onChange={(e) => updateDateFrom(e.target.value)}
                                className="input text-xs py-1.5 w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-medium text-surface-500">To</label>
                            <input
                                type="date"
                                value={dateTo}
                                min={dateFrom}
                                max={today}
                                onChange={(e) => updateDateTo(e.target.value)}
                                className="input text-xs py-1.5 w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-medium text-surface-500">Outlet</label>
                            <select
                                value={outletId}
                                onChange={(e) => updateOutlet(e.target.value)}
                                className="input text-xs py-1.5 w-full"
                            >
                                <option value="">All outlets</option>
                                {(outletsData?.data ?? []).map((o) => (
                                    <option key={o.id} value={String(o.id)}>{o.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-medium text-surface-500">Cashier</label>
                            <select
                                value={userId}
                                onChange={(e) => updateUser(e.target.value)}
                                className="input text-xs py-1.5 w-full"
                            >
                                <option value="">All cashiers</option>
                                {userOptions.map((u) => (
                                    <option key={u.id} value={String(u.id)}>{u.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                {/* List / Table body */}
                <div className="flex-1 overflow-y-auto">
                    {isLoading ? (
                        <div className="flex items-center justify-center h-48">
                            <Spinner size="lg" />
                        </div>
                    ) : isError ? (
                        <div className="flex items-center justify-center h-48 text-sm text-surface-400">
                            Failed to load reports.
                        </div>
                    ) : reports.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-48 gap-2 text-surface-400">
                            <svg className="w-10 h-10 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                            </svg>
                            <p className="text-sm">No reports found for this period.</p>
                        </div>
                    ) : (
                        <>
                            {/* ── Mobile: card list (hidden on sm+) ── */}
                            <div className="sm:hidden divide-y divide-surface-100">
                                {reports.map((r) => (
                                    <button
                                        key={r.id}
                                        type="button"
                                        onClick={() => setSelectedId(r.id === selectedId ? null : r.id)}
                                        className={clsx(
                                            "w-full text-left px-4 py-3.5 transition-colors",
                                            selectedId === r.id
                                                ? "bg-brand-50"
                                                : "hover:bg-surface-50",
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold text-surface-900 truncate">
                                                    {r.user_name}
                                                </p>
                                                <p className="text-2xs text-surface-400 mt-0.5">
                                                    {fmtDate(r.report_date)} · {r.outlet_name}
                                                </p>
                                            </div>
                                            <div className="text-right shrink-0">
                                                <p className="text-xs font-bold text-brand-700">
                                                    KES {fmt(r.total_sales)}
                                                </p>
                                                {r.total_balance > 0.01 ? (
                                                    <p className="text-2xs text-warning font-medium">
                                                        Bal: KES {fmt(r.total_balance)}
                                                    </p>
                                                ) : (
                                                    <p className="text-2xs text-success font-medium">Paid</p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="mt-2 flex items-center gap-2 flex-wrap text-2xs">
                                            <span className="text-surface-400">{r.order_count} order{r.order_count !== 1 ? "s" : ""}</span>
                                            <span className="text-surface-300">·</span>
                                            <span className="text-surface-400">Paid <span className="text-success font-semibold">KES {fmt(r.total_paid)}</span></span>
                                            {r.total_balance > 0.01 && (
                                                <>
                                                    <span className="text-surface-300">·</span>
                                                    <span className="text-surface-400">Bal <span className="text-warning font-semibold">KES {fmt(r.total_balance)}</span></span>
                                                </>
                                            )}
                                        </div>
                                    </button>
                                ))}
                            </div>

                            {/* ── Desktop: table (hidden below sm) ── */}
                            <table className="hidden sm:table w-full text-xs">
                                <thead className="sticky top-0 bg-surface-50 border-b border-surface-100">
                                    <tr>
                                        {[
                                            { label: "Date",        cls: "" },
                                            { label: "Cashier",     cls: "" },
                                            { label: "Outlet",      cls: "" },
                                            { label: "Orders",      cls: "text-center" },
                                            { label: "Total Sales", cls: "text-right" },
                                            { label: "Total Paid",  cls: "text-right" },
                                            { label: "Balance",     cls: "text-right" },
                                            { label: "Submitted",   cls: "" },
                                        ].map((h) => (
                                            <th key={h.label} className={clsx("px-4 py-3 font-semibold text-surface-400 uppercase tracking-wide text-2xs whitespace-nowrap", h.cls)}>
                                                {h.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-100">
                                    {reportGroups.map((group) => (
                                        <Fragment key={group.key}>
                                            <DateGroupHeaderRow label={group.label} colSpan={8} />
                                            {group.items.map((r) => (
                                    <tr
                                        key={r.id}
                                        onClick={() => setSelectedId(r.id === selectedId ? null : r.id)}
                                        className={clsx(
                                            "cursor-pointer transition-colors text-xs",
                                            selectedId === r.id
                                                ? "bg-brand-50"
                                                : "hover:bg-surface-50",
                                        )}
                                    >
                                        {/* Date */}
                                        <td className="px-4 py-3.5 whitespace-nowrap">
                                            <p className="font-semibold text-surface-900">{fmtDate(r.report_date)}</p>
                                        </td>

                                        {/* Cashier */}
                                        <td className="px-4 py-3.5">
                                            <p className="font-medium text-surface-800">{r.user_name}</p>
                                        </td>

                                        {/* Outlet */}
                                        <td className="px-4 py-3.5">
                                            <span className="px-2 py-0.5 rounded-md bg-surface-100 text-surface-600 font-medium">
                                                {r.outlet_name}
                                            </span>
                                        </td>

                                        {/* Orders */}
                                        <td className="px-4 py-3.5 text-center">
                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-surface-100 text-surface-700 font-bold text-2xs">
                                                {r.order_count}
                                            </span>
                                        </td>

                                        {/* Total Sales */}
                                        <td className="px-4 py-3.5 text-right">
                                            <span className="inline-block px-2.5 py-1 rounded-lg bg-brand-50 text-brand-700 font-bold">
                                                KES {fmt(r.total_sales)}
                                            </span>
                                        </td>

                                        {/* Total Paid */}
                                        <td className="px-4 py-3.5 text-right">
                                            <span className="inline-block px-2.5 py-1 rounded-lg bg-success-light text-success font-bold">
                                                KES {fmt(r.total_paid)}
                                            </span>
                                        </td>

                                        {/* Balance */}
                                        <td className="px-4 py-3.5 text-right">
                                            {r.total_balance > 0.01 ? (
                                                <span className="inline-block px-2.5 py-1 rounded-lg bg-warning-light text-warning font-bold">
                                                    KES {fmt(r.total_balance)}
                                                </span>
                                            ) : (
                                                <span className="text-surface-300 font-medium">—</span>
                                            )}
                                        </td>

                                        {/* Submitted */}
                                        <td className="px-4 py-3.5 text-surface-400 whitespace-nowrap">
                                            {fmtDatetime(r.submitted_at)}
                                        </td>
                                    </tr>
                                            ))}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>

                            {/* Pagination */}
                            {meta && meta.last_page > 1 && (
                                <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="text-xs text-surface-500">
                                        Showing {meta.from}–{meta.to} of {meta.total}
                                    </p>
                                    <div className="flex gap-1">
                                        <button
                                            disabled={meta.current_page === 1}
                                            onClick={() => setPage(meta.current_page - 1)}
                                            className="btn-ghost btn-sm text-xs disabled:opacity-40"
                                        >
                                            ← Prev
                                        </button>
                                        <button
                                            disabled={meta.current_page === meta.last_page}
                                            onClick={() => setPage(meta.current_page + 1)}
                                            className="btn-ghost btn-sm text-xs disabled:opacity-40"
                                        >
                                            Next →
                                        </button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* ── Detail drawer ── */}
            {selectedId !== null && (
                <div className="
                    fixed inset-0 z-40 flex flex-col bg-white
                    sm:static sm:inset-auto sm:z-auto
                    sm:w-[420px] sm:shrink-0 sm:border-l sm:border-surface-100 sm:overflow-hidden
                ">
                    {/* Drawer header */}
                    <div className="px-5 py-4 border-b border-surface-100 shrink-0 flex items-start justify-between gap-3">
                        <div>
                            <h2 className="font-bold text-surface-900 text-sm">Report Detail</h2>
                            {detail && (
                                <p className="text-xs text-surface-400 mt-0.5">
                                    {detail.user_name} · {fmtDate(detail.report_date)}
                                </p>
                            )}
                        </div>
                        <button
                            onClick={() => {
                                setSelectedId(null);
                                // Drop ?report= too, or the deep-link effect above
                                // immediately re-opens what was just closed.
                                if (searchParams.has("report")) {
                                    searchParams.delete("report");
                                    setSearchParams(searchParams, { replace: true });
                                }
                            }}
                            className="btn-ghost btn-icon btn-sm shrink-0"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div className="flex-1 overflow-y-auto p-5 space-y-5">
                        {detailLoading ? (
                            <div className="flex items-center justify-center h-40">
                                <Spinner size="lg" />
                            </div>
                        ) : !detail ? (
                            <p className="text-sm text-surface-400 text-center py-8">No data.</p>
                        ) : (
                            <>
                                {/* KPIs */}
                                <div className="grid grid-cols-2 gap-2">
                                    {[
                                        { label: "Orders",      value: String(detail.order_count),        color: "text-surface-900", bg: "bg-surface-50" },
                                        { label: "Total Sales", value: `KES ${fmt(detail.total_sales)}`,  color: "text-brand-700",   bg: "bg-brand-50" },
                                        { label: "Total Paid",  value: `KES ${fmt(detail.total_paid)}`,   color: "text-success",     bg: "bg-success-light" },
                                        { label: "Balance",     value: detail.total_balance > 0.01 ? `KES ${fmt(detail.total_balance)}` : "Nil", color: detail.total_balance > 0.01 ? "text-warning" : "text-surface-400", bg: "bg-surface-50" },
                                    ].map((k) => (
                                        <div key={k.label} className={clsx("rounded-xl p-3 text-center", k.bg)}>
                                            <p className="text-2xs text-surface-400">{k.label}</p>
                                            <p className={clsx("font-bold mt-0.5 text-sm", k.color)}>{k.value}</p>
                                        </div>
                                    ))}
                                </div>

                                {/* Orders with notes */}
                                {detail.orders.length > 0 && (
                                    <div>
                                        <h3 className="text-xs font-semibold text-surface-700 mb-2">Orders</h3>
                                        <div className="space-y-2">
                                            {detail.orders.map((order) => (
                                                <div
                                                    key={order.id}
                                                    className="rounded-xl border border-surface-100 bg-surface-50 px-4 py-3 space-y-2"
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <p className="text-xs font-semibold text-surface-900">{order.customer_name}</p>
                                                            <p className="text-2xs text-surface-400">#{order.order_number}</p>
                                                        </div>
                                                        <div className="text-right shrink-0">
                                                            <p className="text-xs font-bold text-surface-900">KES {fmt(order.total_amount)}</p>
                                                            {order.balance > 0.01 ? (
                                                                <p className="text-2xs text-warning font-medium">Bal: KES {fmt(order.balance)}</p>
                                                            ) : (
                                                                <p className="text-2xs text-success font-medium">Paid</p>
                                                            )}
                                                        </div>
                                                    </div>
                                                    {order.eod_note && (
                                                        <p className="text-xs text-surface-600 bg-white rounded-lg px-3 py-2 border border-surface-100 italic">
                                                            {order.eod_note}
                                                        </p>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Sentiments */}
                                {detail.sentiments && (
                                    <div>
                                        <h3 className="text-xs font-semibold text-surface-700 mb-2">Daily Notes & Sentiments</h3>
                                        <div
                                            className="text-xs text-surface-700 bg-surface-50 rounded-xl px-4 py-3 border border-surface-100 prose prose-sm max-w-none leading-relaxed [&_ul]:list-disc [&_ul]:pl-4 [&_li]:my-0.5"
                                            dangerouslySetInnerHTML={{ __html: detail.sentiments }}
                                        />
                                    </div>
                                )}

                                {/* ── Acknowledge + thread ─────────────────────────────
                                    Sits directly under the notes, because that is where
                                    the questions come from: this report asks for three
                                    receipts to be adjusted and one voided. */}
                                <div className="pt-3 border-t border-surface-100 space-y-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <h3 className="text-xs font-semibold text-surface-700">Discussion</h3>
                                        {detail.acknowledged_at ? (
                                            <span className="flex items-center gap-1 text-2xs font-bold text-success bg-success-light border border-success/30 rounded-full px-2 py-1">
                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                                Read {fmtDatetime(detail.acknowledged_at)}
                                            </span>
                                        ) : (
                                            <button
                                                onClick={() => ackMutation.mutate()}
                                                disabled={ackMutation.isPending}
                                                className="text-2xs font-bold text-brand-700 bg-brand-50 border border-brand-200 rounded-full px-2.5 py-1 hover:bg-brand-100 disabled:opacity-40 transition-colors">
                                                {ackMutation.isPending ? "Marking…" : "Mark as read"}
                                            </button>
                                        )}
                                    </div>

                                    {detail.comments?.length > 0 && (
                                        <div className="space-y-2">
                                            {detail.comments.map((c) => (
                                                <div key={c.id} className="bg-white border border-surface-200 rounded-xl px-3 py-2">
                                                    <div className="flex items-baseline justify-between gap-2 mb-0.5">
                                                        <span className="text-2xs font-bold text-surface-700">{c.user_name}</span>
                                                        <span className="text-2xs text-surface-400 tabular-nums">{fmtDatetime(c.created_at)}</span>
                                                    </div>
                                                    <p className="text-xs text-surface-700 whitespace-pre-wrap break-words">{c.body}</p>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    <div className="flex items-end gap-2">
                                        <textarea
                                            value={commentBody}
                                            onChange={(e) => setCommentBody(e.target.value)}
                                            rows={2}
                                            placeholder={`Ask ${detail.user_name.split(" ")[0] || "them"} a question…`}
                                            className="input flex-1 resize-none text-xs" />
                                        <button
                                            onClick={() => commentBody.trim() && commentMutation.mutate(commentBody.trim())}
                                            disabled={!commentBody.trim() || commentMutation.isPending}
                                            className="shrink-0 px-3 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-40 transition-colors">
                                            {commentMutation.isPending ? "…" : "Send"}
                                        </button>
                                    </div>
                                    <p className="text-2xs text-surface-400">
                                        {detail.user_name.split(" ")[0] || "The author"} is notified, and can reply from their own report.
                                    </p>
                                </div>

                                {/* Meta */}
                                <div className="text-2xs text-surface-400 space-y-1 pt-2 border-t border-surface-100">
                                    <p>Outlet: <span className="text-surface-600">{detail.outlet_name}</span></p>
                                    <p>Submitted: <span className="text-surface-600">{fmtDatetime(detail.submitted_at)}</span></p>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}