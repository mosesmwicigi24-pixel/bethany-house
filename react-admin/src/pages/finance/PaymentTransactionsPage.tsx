// src/pages/finance/PaymentTransactionsPage.tsx
import { useState, useCallback } from "react";
import { Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import dayjs from "dayjs";
import { transactionsApi, type TransactionListParams } from "@/api/transactions";
import { get } from "@/api/client";
import { DATE_PRESETS, datePresetRange, type DatePreset } from "@/api/reports";
import { DateRangePicker, useDateRange, KpiCard } from "@/pages/reports/reportShared";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import {
    AreaChart, Area, XAxis, YAxis, CartesianGrid,
    Tooltip, ResponsiveContainer,
} from "recharts";
import { Fragment } from "react";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtCurrency = (n?: number | string | null, code = "KES") => {
    const num = typeof n === "string" ? parseFloat(n) : (n ?? 0);
    return new Intl.NumberFormat("en-KE", {
        style: "currency", currency: code,
        minimumFractionDigits: 0, maximumFractionDigits: 0,
    }).format(num);
};

const fmtNum = (n?: number | null) =>
    n != null ? n.toLocaleString("en-KE") : "0";

const fmtDate = (d?: string | null) =>
    d ? dayjs(d).format("DD MMM YYYY, HH:mm") : "—";

const METHOD_LABELS: Record<string, string> = {
    mpesa: "M-Pesa", card_paystack: "Card (Paystack)",
    card_flutterwave: "Card (Flutterwave)", cash: "Cash",
    bank_transfer: "Bank Transfer", cheque: "Cheque", manual: "Manual",
};

const METHOD_COLORS: Record<string, string> = {
    mpesa: "#00B300", card_paystack: "#6366F1", card_flutterwave: "#F59E0B",
    cash: "#10B981", bank_transfer: "#3B82F6", cheque: "#8B5CF6", manual: "#64748B",
};

const STATUS_CONFIG: Record<string, { label: string; cls: string }> = {
    paid:               { label: "Paid",           cls: "bg-success-light text-success"      },
    pending:            { label: "Pending",        cls: "bg-warning-light text-warning-dark" },
    failed:             { label: "Failed",         cls: "bg-danger-light text-danger"        },
    refunded:           { label: "Refunded",       cls: "bg-surface-100 text-surface-500"    },
    partially_refunded: { label: "Part. Refunded", cls: "bg-purple-50 text-purple-600"       },
    voided:             { label: "Voided",         cls: "bg-surface-200 text-surface-400 line-through" },
};

// ── Page ─────────────────────────────────────────────────────────────────────

export default function PaymentTransactionsPage() {
    const { can }  = usePermissions();
    const table    = useTableState();
    const dr       = useDateRange("last_30_days");

    const [status,        setStatus]        = useState("");
    const [paymentMethod, setPaymentMethod] = useState("");
    const [search,        setSearch]        = useState("");
    const [minAmount,     setMinAmount]     = useState("");
    const [maxAmount,     setMaxAmount]     = useState("");
    const [showFilters,   setShowFilters]   = useState(false);
    const [selectedId,    setSelectedId]    = useState<number | null>(null);

    // ── Void modal state ──────────────────────────────────────────────────────
    const [voidTarget,   setVoidTarget]   = useState<number | null>(null);
    const [voidReason,   setVoidReason]   = useState("");

    // ── Reassign modal state ───────────────────────────────────────────────────
    const [reassignTarget,    setReassignTarget]    = useState<number | null>(null);
    const [reassignOrderQuery, setReassignOrderQuery] = useState("");
    const [reassignOrderId,    setReassignOrderId]    = useState<number | null>(null);
    const [reassignOrderLabel, setReassignOrderLabel] = useState("");
    const [reassignReason,     setReassignReason]     = useState("");

    const queryClient = useQueryClient();

    const { data: orderSearchResults } = useQuery({
        queryKey: ["order-search-reassign", reassignOrderQuery],
        queryFn: () => get<{ data: any[] }>("/v1/admin/orders", { params: { search: reassignOrderQuery, per_page: 8 } }),
        enabled: reassignOrderQuery.length >= 3,
    });

    const voidMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            transactionsApi.void(id, { reason }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["payment-transactions"] });
            queryClient.invalidateQueries({ queryKey: ["payment-transaction", voidTarget] });
            setVoidTarget(null);
            setVoidReason("");
        },
    });

    const reassignMutation = useMutation({
        mutationFn: ({ id, order_id, reason }: { id: number; order_id: number; reason: string }) =>
            transactionsApi.reassign(id, { order_id, reason }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ["payment-transactions"] });
            queryClient.invalidateQueries({ queryKey: ["payment-transaction", reassignTarget] });
            setReassignTarget(null);
            setReassignOrderQuery("");
            setReassignOrderId(null);
            setReassignOrderLabel("");
            setReassignReason("");
        },
    });

    const listParams: TransactionListParams = {
        page: table.state.page, per_page: table.state.perPage,
        search: search || undefined,
        status: status || undefined,
        payment_method: paymentMethod || undefined,
        start_date: dr.start, end_date: dr.end,
        min_amount: minAmount ? parseFloat(minAmount) : undefined,
        max_amount: maxAmount ? parseFloat(maxAmount) : undefined,
    };

    const { data: listData,  isLoading: listLoading }      = useQuery({ queryKey: ["payment-transactions", listParams], queryFn: () => transactionsApi.list(listParams) });
    const { data: analytics, isLoading: analyticsLoading } = useQuery({ queryKey: ["payment-transactions-analytics", dr.start, dr.end], queryFn: () => transactionsApi.analytics({ start_date: dr.start, end_date: dr.end }) });
    const { data: detailData } = useQuery({ queryKey: ["payment-transaction", selectedId], queryFn: () => transactionsApi.show(selectedId!), enabled: selectedId !== null });

    const transactions = listData?.data ?? [];
    const total        = listData?.total ?? 0;
    const lastPage      = listData?.last_page ?? 1;
    const activeFilters = [status, paymentMethod, minAmount, maxAmount].filter(Boolean).length;

    // Group the current page of rows by created_at. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const transactionGroups = groupRowsByDate(transactions, (txn) => txn.created_at);

    const handleExport = useCallback(async () => {
        const res = await transactionsApi.export(listParams);
        if (!res?.data?.length) return;
        const head = ["#", "Reference", "Order", "Customer", "Method", "Amount", "Currency", "Status", "Date"];
        const csv  = [head, ...res.data.map((p, i) => [
            i + 1, p.payment_number, p.order?.order_number ?? "",
            p.order ? `${p.order.customer_first_name} ${p.order.customer_last_name}` : "",
            METHOD_LABELS[p.payment_method] ?? p.payment_method,
            p.amount, p.currency_code, p.status, p.created_at,
        ])].map(r => r.map(String).join(",")).join("\n");
        const a = Object.assign(document.createElement("a"), {
            href: URL.createObjectURL(new Blob([csv], { type: "text/csv" })),
            download: `transactions-${dr.start}-${dr.end}.csv`,
        });
        a.click();
    }, [listParams, dr.start, dr.end]);

    return (
        <>
        <div className="animate-fade-in space-y-6">

            {/* ── Header ──────────────────────────────────────────────────── */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                <div>
                    <h1 className="page-title">Payment Transactions</h1>
                    <p className="page-subtitle">All payment records across every channel and gateway.</p>
                </div>
                <div className="flex flex-col items-start gap-2 sm:items-end">
                    <DateRangePicker
                        preset={dr.preset}
                        start={dr.start}
                        end={dr.end}
                        onPresetChange={dr.handlePreset}
                        onStartChange={dr.setStart}
                        onEndChange={dr.setEnd}
                    />
                    {can("payments.view") && (
                        <button onClick={handleExport}
                            className="btn-ghost btn-sm inline-flex items-center gap-1.5">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Export CSV
                        </button>
                    )}
                </div>
            </div>

            {/* ── KPI Cards ───────────────────────────────────────────────── */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <KpiCard
                    label="Total Volume"
                    value={analyticsLoading ? "—" : fmtCurrency(analytics?.paid_volume)}
                    sub={`${fmtNum(analytics?.paid_count)} paid`}
                    color="text-brand"
                />
                <KpiCard
                    label="Transactions"
                    value={analyticsLoading ? "—" : fmtNum(analytics?.total_count)}
                    sub={`${analytics?.success_rate ?? 0}% success rate`}
                />
                <KpiCard
                    label="Avg Transaction"
                    value={analyticsLoading ? "—" : fmtCurrency(analytics?.avg_transaction)}
                />
                <KpiCard
                    label="Failed"
                    value={analyticsLoading ? "—" : fmtNum(analytics?.failed_count)}
                    sub="transactions"
                    color="text-danger"
                />
                <KpiCard
                    label="Pending"
                    value={analyticsLoading ? "—" : fmtNum(analytics?.pending_count)}
                    sub="awaiting"
                    color="text-warning-dark"
                />
                <KpiCard
                    label="Refunded"
                    value={analyticsLoading ? "—" : fmtCurrency(analytics?.refunded_volume)}
                    sub={`${fmtNum(analytics?.refunded_count)} transactions`}
                    color="text-surface-600"
                />
            </div>

            {/* ── Charts ──────────────────────────────────────────────────── */}
            {!analyticsLoading && (analytics?.daily?.length ?? 0) > 1 && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    {/* Volume trend */}
                    <div className="lg:col-span-2 card overflow-hidden">
                        <div className="card-header flex items-center justify-between">
                            <h3 className="font-semibold text-sm text-surface-900">Volume Trend</h3>
                            <span className="text-xs text-surface-400">
                                {dayjs(dr.start).format("D MMM")} – {dayjs(dr.end).format("D MMM YYYY")}
                            </span>
                        </div>
                        <div className="card-body pt-0">
                            <ResponsiveContainer width="100%" height={160}>
                                <AreaChart data={analytics?.daily ?? []} margin={{ top: 4, right: 0, left: -20, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="txnGrad" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%"   stopColor="#6366F1" stopOpacity={0.15} />
                                            <stop offset="100%" stopColor="#6366F1" stopOpacity={0}    />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="4 4" stroke="#f1f5f9" vertical={false} />
                                    <XAxis dataKey="date" tickFormatter={d => dayjs(d).format("DD MMM")}
                                        tick={{ fontSize: 10, fill: "#94a3b8" }} axisLine={false} tickLine={false} interval="preserveStartEnd" />
                                    <YAxis tickFormatter={v => `${Math.round(v / 1000)}k`}
                                        tick={{ fontSize: 10, fill: "#94a3b8" }} axisLine={false} tickLine={false} />
                                    <Tooltip
                                        formatter={(v) => [fmtCurrency(Number(v ?? 0)), "Volume"]}
                                        labelFormatter={l => dayjs(l).format("DD MMM YYYY")}
                                        contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid #e2e8f0" }} />
                                    <Area type="monotone" dataKey="volume" stroke="#6366F1" strokeWidth={2} fill="url(#txnGrad)" dot={false} />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </div>

                    {/* By method */}
                    <div className="card overflow-hidden">
                        <div className="card-header">
                            <h3 className="font-semibold text-sm text-surface-900">By Method</h3>
                        </div>
                        <div className="card-body space-y-3">
                            {(analytics?.by_method?.length ?? 0) > 0 ? (
                                analytics?.by_method?.slice(0, 6).map(m => {
                                    const maxVol = Math.max(...(analytics.by_method?.map(x => x.volume) ?? [1]));
                                    const pct    = maxVol > 0 ? (m.volume / maxVol) * 100 : 0;
                                    const color  = METHOD_COLORS[m.payment_method] ?? "#94A3B8";
                                    return (
                                        <div key={m.payment_method} className="space-y-1">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs text-surface-600 flex items-center gap-1.5">
                                                    <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: color }} />
                                                    {METHOD_LABELS[m.payment_method] ?? m.payment_method}
                                                </span>
                                                <span className="text-xs font-semibold text-surface-800">{fmtCurrency(m.volume)}</span>
                                            </div>
                                            <div className="h-1.5 bg-surface-100 rounded-full overflow-hidden">
                                                <div className="h-full rounded-full transition-all duration-500"
                                                    style={{ width: `${pct}%`, backgroundColor: color }} />
                                            </div>
                                        </div>
                                    );
                                })
                            ) : (
                                <p className="text-sm text-surface-400 text-center py-6">No data for period</p>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* ── Table ───────────────────────────────────────────────────── */}
            <div className="card overflow-hidden">

                {/* Toolbar — stacks on mobile, single row on lg+ */}
                <div className="card-header flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-3">
                    {/* Search */}
                    <div className="relative w-full lg:flex-1">
                        <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-surface-400"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <circle cx="11" cy="11" r="8" /><path strokeLinecap="round" d="M21 21l-4.35-4.35" />
                        </svg>
                        <input type="text" placeholder="Search reference, order, customer…"
                            value={search} onChange={e => { setSearch(e.target.value); table.setPage(1); }}
                            className="input pl-9 text-sm py-2 w-full" />
                    </div>

                    {/* Selects + filters + count — all in one row, wrap on mobile only */}
                    <div className="flex items-center gap-2 flex-wrap lg:flex-nowrap">
                        <select value={status} onChange={e => { setStatus(e.target.value); table.setPage(1); }}
                            className="input text-sm py-2 flex-1 lg:flex-none lg:w-36">
                            <option value="">All Statuses</option>
                            {Object.entries(STATUS_CONFIG).map(([v, { label }]) => (
                                <option key={v} value={v}>{label}</option>
                            ))}
                        </select>

                        <select value={paymentMethod} onChange={e => { setPaymentMethod(e.target.value); table.setPage(1); }}
                            className="input text-sm py-2 flex-1 lg:flex-none lg:w-36">
                            <option value="">All Methods</option>
                            {Object.entries(METHOD_LABELS).map(([v, l]) => (
                                <option key={v} value={v}>{l}</option>
                            ))}
                        </select>

                        <button onClick={() => setShowFilters(v => !v)}
                            className={clsx(
                                "btn-ghost text-sm inline-flex items-center gap-1.5 shrink-0",
                                (showFilters || activeFilters > 0) && "text-brand",
                            )}>
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                            </svg>
                            Filters
                            {activeFilters > 0 && (
                                <span className="w-4 h-4 bg-brand text-white text-2xs rounded-full flex items-center justify-center font-bold">{activeFilters}</span>
                            )}
                        </button>

                        <span className="text-xs text-surface-400 shrink-0 whitespace-nowrap ml-auto lg:ml-0">
                            {total.toLocaleString()} result{total !== 1 ? "s" : ""}
                        </span>
                    </div>
                </div>

                {/* Amount filters */}
                {showFilters && (
                    <div className="border-b border-surface-100 px-4 py-3 bg-surface-50 flex items-center gap-3 flex-wrap">
                        <label className="text-xs text-surface-500">Amount range</label>
                        <input type="number" placeholder="Min" value={minAmount}
                            onChange={e => setMinAmount(e.target.value)}
                            className="input text-sm py-1.5 w-28" />
                        <span className="text-xs text-surface-400">to</span>
                        <input type="number" placeholder="Max" value={maxAmount}
                            onChange={e => setMaxAmount(e.target.value)}
                            className="input text-sm py-1.5 w-28" />
                        {(minAmount || maxAmount) && (
                            <button onClick={() => { setMinAmount(""); setMaxAmount(""); }}
                                className="text-xs text-danger hover:underline">
                                Clear
                            </button>
                        )}
                    </div>
                )}

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-surface-100">
                                {[
                                    { label: "Reference", align: "left"  },
                                    { label: "Order",     align: "left"  },
                                    { label: "Customer",  align: "left"  },
                                    { label: "Method",    align: "left"  },
                                    { label: "Amount",    align: "right" },
                                    { label: "Status",    align: "left"  },
                                    { label: "Date",      align: "left"  },
                                    { label: "",          align: "left"  },
                                ].map(h => (
                                    <th key={h.label}
                                        className={clsx(
                                            "px-4 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wider whitespace-nowrap",
                                            h.align === "right" ? "text-right" : "text-left",
                                        )}>
                                        {h.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {listLoading ? (
                                Array.from({ length: 8 }).map((_, i) => (
                                    <tr key={i}>
                                        {Array.from({ length: 8 }).map((_, j) => (
                                            <td key={j} className="px-4 py-3">
                                                <div className="skeleton h-4 rounded w-full" />
                                            </td>
                                        ))}
                                    </tr>
                                ))
                            ) : transactions.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-12 text-center text-sm text-surface-400">
                                        No transactions found for the selected period and filters.
                                    </td>
                                </tr>
                            ) : (
                                transactionGroups.map((group) => (
                                    <Fragment key={group.key}>
                                        <DateGroupHeaderRow label={group.label} colSpan={8} />
                                        {group.items.map(txn => {
                                    const sc         = STATUS_CONFIG[txn.status] ?? { label: txn.status, cls: "bg-surface-100 text-surface-500" };
                                    const isRefunded = parseFloat(txn.refund_amount ?? "0") > 0;
                                    const mColor     = METHOD_COLORS[txn.payment_method];
                                    return (
                                        <tr key={txn.id}
                                            onClick={() => setSelectedId(txn.id)}
                                            className={clsx(
                                                "hover:bg-surface-50/50 transition-colors cursor-pointer group",
                                                selectedId === txn.id && "bg-surface-50",
                                            )}>
                                            {/* Reference */}
                                            <td className="px-4 py-3">
                                                <p className="font-mono text-xs font-semibold text-surface-700">{txn.payment_number}</p>
                                                {txn.provider_reference && (
                                                    <p className="font-mono text-2xs text-surface-400 mt-0.5 truncate max-w-[130px]">{txn.provider_reference}</p>
                                                )}
                                            </td>
                                            {/* Order */}
                                            <td className="px-4 py-3">
                                                {txn.order ? (
                                                    <Link to={`/sales/orders/${txn.order.id}`}
                                                        onClick={e => e.stopPropagation()}
                                                        className="text-xs font-semibold text-brand hover:underline">
                                                        {txn.order.order_number}
                                                    </Link>
                                                ) : <span className="text-surface-300 text-xs">—</span>}
                                            </td>
                                            {/* Customer */}
                                            <td className="px-4 py-3 text-xs text-surface-700">
                                                {txn.order
                                                    ? [txn.order.customer_first_name, txn.order.customer_last_name].filter(Boolean).join(" ") || "—"
                                                    : "—"}
                                            </td>
                                            {/* Method */}
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-surface-700">
                                                    <span className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: mColor ?? "#94a3b8" }} />
                                                    {METHOD_LABELS[txn.payment_method] ?? txn.payment_method}
                                                </span>
                                            </td>
                                            {/* Amount */}
                                            <td className="px-4 py-3 text-right">
                                                <p className="text-sm font-bold text-surface-900 tabular-nums">
                                                    {fmtCurrency(txn.amount, txn.currency_code)}
                                                </p>
                                                {isRefunded && (
                                                    <p className="text-2xs text-danger mt-0.5 tabular-nums">
                                                        -{fmtCurrency(txn.refund_amount, txn.currency_code)}
                                                    </p>
                                                )}
                                            </td>
                                            {/* Status */}
                                            <td className="px-4 py-3">
                                                <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold", sc.cls)}>
                                                    {sc.label}
                                                </span>
                                                {txn.requires_approval && txn.approval_status === "pending_review" && (
                                                    <p className="text-2xs text-warning-dark mt-0.5">Needs approval</p>
                                                )}
                                            </td>
                                            {/* Date */}
                                            <td className="px-4 py-3 text-xs text-surface-500 whitespace-nowrap">
                                                {fmtDate(txn.created_at)}
                                            </td>
                                            {/* Arrow */}
                                            <td className="px-4 py-3">
                                                <svg className="w-4 h-4 text-surface-300 group-hover:text-brand transition-colors"
                                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                </svg>
                                            </td>
                                        </tr>
                                    );
                                        })}
                                    </Fragment>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {lastPage > 1 && (
                    <div className="card-footer flex items-center justify-between">
                        <p className="text-xs text-surface-500">Page {table.state.page} of {lastPage}</p>
                        <div className="flex gap-1">
                            <button disabled={table.state.page <= 1}
                                onClick={() => table.setPage(table.state.page - 1)}
                                className="btn-ghost text-xs px-2.5 py-1.5 disabled:opacity-40">
                                ← Previous
                            </button>
                            <button disabled={table.state.page >= lastPage}
                                onClick={() => table.setPage(table.state.page + 1)}
                                className="btn-ghost text-xs px-2.5 py-1.5 disabled:opacity-40">
                                Next →
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* ── Detail Drawer ────────────────────────────────────────────── */}
            {selectedId !== null && (
                <div className="fixed inset-0 z-40 flex" onClick={() => setSelectedId(null)}>
                    <div className="flex-1 bg-black/20" />
                    <div className="w-full max-w-md bg-white shadow-2xl overflow-y-auto animate-slide-in-right"
                        onClick={e => e.stopPropagation()}>

                        <div className="sticky top-0 bg-white border-b border-surface-100 px-5 py-4 flex items-center justify-between">
                            <h2 className="font-semibold text-surface-900">Transaction Detail</h2>
                            <button onClick={() => setSelectedId(null)}
                                className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-surface-100 transition-colors">
                                <svg className="w-4 h-4 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {!detailData ? (
                            <div className="p-5 space-y-3">
                                {Array.from({ length: 6 }).map((_, i) => (
                                    <div key={i} className="skeleton h-5 rounded w-full" />
                                ))}
                            </div>
                        ) : (() => {
                            const p  = detailData.payment;
                            const sc = STATUS_CONFIG[p.status] ?? { label: p.status, cls: "bg-surface-100 text-surface-500" };
                            return (
                                <div className="p-5 space-y-5">
                                    {/* Amount hero */}
                                    <div className="text-center py-5 bg-surface-50 rounded-xl">
                                        <p className="text-3xl font-bold text-surface-900 tracking-tight tabular-nums">
                                            {fmtCurrency(p.amount, p.currency_code)}
                                        </p>
                                        <div className="mt-2 flex items-center justify-center gap-2">
                                            <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold", sc.cls)}>
                                                {sc.label}
                                            </span>
                                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-surface-600">
                                                <span className="w-2 h-2 rounded-full" style={{ backgroundColor: METHOD_COLORS[p.payment_method] ?? "#94a3b8" }} />
                                                {METHOD_LABELS[p.payment_method] ?? p.payment_method}
                                            </span>
                                        </div>
                                    </div>

                                    {/* Fields */}
                                    <div className="space-y-3">
                                        {([
                                            ["Reference",    p.payment_number],
                                            ["Provider Ref", p.provider_reference],
                                            ["Order",        p.order?.order_number],
                                            ["Customer",     p.order ? [p.order.customer_first_name, p.order.customer_last_name].filter(Boolean).join(" ") || null : null],
                                            ["Paid at",      p.paid_at ? fmtDate(p.paid_at) : null],
                                            ["Created",      fmtDate(p.created_at)],
                                        ] as [string, string | null | undefined][]).filter(([, v]) => v).map(([label, value]) => (
                                            <div key={label} className="flex items-start justify-between gap-3 text-sm">
                                                <span className="text-surface-500 shrink-0">{label}</span>
                                                <span className="text-surface-800 font-medium text-right break-all font-mono text-xs">{value}</span>
                                            </div>
                                        ))}
                                    </div>

                                    {/* Refund */}
                                    {parseFloat(p.refund_amount ?? "0") > 0 && (
                                        <div className="bg-danger-light rounded-xl p-3 space-y-0.5">
                                            <p className="text-xs font-semibold text-danger">Refund Applied</p>
                                            <p className="text-xs text-danger/80">
                                                {fmtCurrency(p.refund_amount, p.currency_code)} refunded
                                                {p.refunded_at ? ` on ${fmtDate(p.refunded_at)}` : ""}
                                            </p>
                                        </div>
                                    )}

                                    {/* Approval */}
                                    {p.requires_approval && (
                                        <div className="bg-warning-light rounded-xl p-3 space-y-0.5">
                                            <p className="text-xs font-semibold text-warning-dark">Approval Required</p>
                                            <p className="text-xs text-warning-dark/80 capitalize">
                                                {p.approval_status?.replace(/_/g, " ")}
                                            </p>
                                        </div>
                                    )}

                                    {/* Actions */}
                                    <div className="flex gap-2 pt-1">
                                        {p.order && (
                                            <Link to={`/sales/orders/${p.order.id}`}
                                                className="btn-secondary text-sm flex-1 text-center"
                                                onClick={() => setSelectedId(null)}>
                                                View Order
                                            </Link>
                                        )}
                                        {p.requires_approval && p.approval_status === "pending_review" && can("payments.approve_international") && (
                                            <Link to="/approvals"
                                                className="btn-primary text-sm flex-1 text-center"
                                                onClick={() => setSelectedId(null)}>
                                                Go to Approvals
                                            </Link>
                                        )}
                                    </div>

                                    {/* Admin actions — Void & Reassign */}
                                    {p.status !== 'voided' && (can('payments.void') || can('payments.reassign')) && (
                                        <div className="border-t border-surface-100 pt-4 space-y-2">
                                            <p className="text-2xs text-surface-400 font-semibold uppercase tracking-widest">Admin Actions</p>
                                            <div className="flex gap-2">
                                                {can('payments.reassign') && (
                                                    <button
                                                        onClick={() => { setReassignTarget(p.id); setSelectedId(null); }}
                                                        className="btn-secondary text-xs flex-1">
                                                        Reassign to Order
                                                    </button>
                                                )}
                                                {can('payments.void') && (
                                                    <button
                                                        onClick={() => { setVoidTarget(p.id); setSelectedId(null); }}
                                                        className="flex-1 px-3 py-2 rounded-lg border border-danger/30 text-danger text-xs font-medium hover:bg-danger-light transition-colors">
                                                        Void Payment
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {/* Voided notice */}
                                    {p.status === 'voided' && (
                                        <div className="border-t border-surface-100 pt-4 bg-surface-50 rounded-xl p-3 space-y-1">
                                            <p className="text-xs font-semibold text-surface-500">Payment Voided</p>
                                            {p.void_reason && <p className="text-xs text-surface-400">{p.void_reason}</p>}
                                            {p.voided_at && <p className="text-2xs text-surface-400">{fmtDate(p.voided_at)}</p>}
                                        </div>
                                    )}
                                </div>
                            );
                        })()}
                    </div>
                </div>
            )}
        </div>

        {/* ── Void Payment Modal ─────────────────────────────────────────── */}
        {voidTarget !== null && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-4">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-danger-light flex items-center justify-center shrink-0">
                            <svg className="w-5 h-5 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                        </div>
                        <div>
                            <h3 className="font-semibold text-surface-900">Void Payment</h3>
                            <p className="text-xs text-surface-500">This cannot be undone. The order balance will be recalculated.</p>
                        </div>
                    </div>
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-surface-700">Reason for voiding *</label>
                        <textarea
                            rows={3}
                            className="input w-full text-sm resize-none"
                            placeholder="e.g. Payment was applied to the wrong order"
                            value={voidReason}
                            onChange={e => setVoidReason(e.target.value)}
                        />
                    </div>
                    {voidMutation.isError && (
                        <p className="text-xs text-danger">{(voidMutation.error as any)?.message ?? 'Failed to void payment.'}</p>
                    )}
                    <div className="flex gap-2 pt-1">
                        <button onClick={() => { setVoidTarget(null); setVoidReason(''); }}
                            className="btn-secondary flex-1" disabled={voidMutation.isPending}>
                            Cancel
                        </button>
                        <button
                            onClick={() => voidMutation.mutate({ id: voidTarget!, reason: voidReason })}
                            disabled={!voidReason.trim() || voidMutation.isPending}
                            className="flex-1 px-4 py-2 rounded-lg bg-danger text-white text-sm font-semibold hover:bg-danger/90 disabled:opacity-50 transition-colors">
                            {voidMutation.isPending ? 'Voiding…' : 'Confirm Void'}
                        </button>
                    </div>
                </div>
            </div>
        )}

        {/* ── Reassign Payment Modal ──────────────────────────────────────── */}
        {reassignTarget !== null && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-4">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center shrink-0">
                            <svg className="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 3M21 7.5H7.5" />
                            </svg>
                        </div>
                        <div>
                            <h3 className="font-semibold text-surface-900">Reassign Payment</h3>
                            <p className="text-xs text-surface-500">Move this payment to a different sales order.</p>
                        </div>
                    </div>

                    {/* Order search */}
                    <div className="space-y-1">
                        <label className="text-xs font-medium text-surface-700">Search for the correct order *</label>
                        <input
                            type="text"
                            className="input w-full text-sm"
                            placeholder="Order number or customer name…"
                            value={reassignOrderQuery}
                            onChange={e => { setReassignOrderQuery(e.target.value); setReassignOrderId(null); setReassignOrderLabel(''); }}
                        />
                        {reassignOrderQuery.length >= 3 && !reassignOrderId && (
                            <div className="border border-surface-200 rounded-xl divide-y divide-surface-100 max-h-48 overflow-y-auto mt-1">
                                {(orderSearchResults?.data ?? []).length === 0 ? (
                                    <p className="px-3 py-2 text-xs text-surface-400">No orders found</p>
                                ) : (orderSearchResults?.data ?? []).map((o: any) => (
                                    <button key={o.id} onClick={() => {
                                        setReassignOrderId(o.id);
                                        setReassignOrderLabel(`${o.order_number} — ${[o.customer_first_name, o.customer_last_name].filter(Boolean).join(' ') || 'Unknown customer'}`);
                                        setReassignOrderQuery('');
                                    }} className="w-full text-left px-3 py-2 hover:bg-surface-50 transition-colors">
                                        <p className="text-xs font-mono font-semibold text-surface-800">{o.order_number}</p>
                                        <p className="text-2xs text-surface-500">{[o.customer_first_name, o.customer_last_name].filter(Boolean).join(' ') || 'No customer'} · {o.status}</p>
                                    </button>
                                ))}
                            </div>
                        )}
                        {reassignOrderId && (
                            <div className="flex items-center justify-between bg-indigo-50 border border-indigo-200 rounded-lg px-3 py-2 mt-1">
                                <p className="text-xs font-medium text-indigo-800">{reassignOrderLabel}</p>
                                <button onClick={() => { setReassignOrderId(null); setReassignOrderLabel(''); }}
                                    className="text-indigo-400 hover:text-indigo-600 ml-2">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="space-y-1">
                        <label className="text-xs font-medium text-surface-700">Reason for reassignment *</label>
                        <textarea
                            rows={2}
                            className="input w-full text-sm resize-none"
                            placeholder="e.g. Payment was incorrectly applied to order #ORD-001"
                            value={reassignReason}
                            onChange={e => setReassignReason(e.target.value)}
                        />
                    </div>

                    {reassignMutation.isError && (
                        <p className="text-xs text-danger">{(reassignMutation.error as any)?.message ?? 'Failed to reassign payment.'}</p>
                    )}
                    <div className="flex gap-2 pt-1">
                        <button onClick={() => { setReassignTarget(null); setReassignOrderId(null); setReassignOrderLabel(''); setReassignOrderQuery(''); setReassignReason(''); }}
                            className="btn-secondary flex-1" disabled={reassignMutation.isPending}>
                            Cancel
                        </button>
                        <button
                            onClick={() => reassignMutation.mutate({ id: reassignTarget!, order_id: reassignOrderId!, reason: reassignReason })}
                            disabled={!reassignOrderId || !reassignReason.trim() || reassignMutation.isPending}
                            className="btn-primary flex-1 disabled:opacity-50">
                            {reassignMutation.isPending ? 'Reassigning…' : 'Confirm Reassign'}
                        </button>
                    </div>
                </div>
            </div>
        )}
        </>
    );
}