/**
 * UserEodModal.tsx
 *
 * The cashier's personal End-of-Day reconciliation modal.
 *
 * Flow:
 *  1. Fetches today's orders created by this user at this outlet
 *  2. Displays a KPI summary (count, total sales, paid, balance)
 *  3. Lists every order as a collapsible row — items, total/paid/balance,
 *     and a per-order note textarea the user can fill in
 *  4. A "Day Sentiments" free-text area for general remarks about the day
 *  5. On Submit → POST /admin/pos/reports/user-eod → sets submitted_at
 *     → calls onSubmitSuccess() which sets eodSubmitted=true in PosPage,
 *       unblocking the "Close Register" flow
 *
 * The modal can be reopened to update the report even after submission.
 */

import { useState, useMemo, useEffect, useRef, useCallback } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";

// ── Types ─────────────────────────────────────────────────────────────────────

interface EodOrderItem {
    product_name: string;
    variant_name?: string | null;
    quantity: number;
    unit_price: number;
    total_price: number;
}

interface EodOrder {
    id: number;
    order_number: string;
    customer_name: string;
    items: EodOrderItem[];
    total_amount: number;
    amount_paid: number;
    balance: number;
    payment_status: string;
    created_at: string;
    eod_note?: string | null;
}

export interface EodSummary {
    date: string;
    register_id: number | null;
    user_name: string;
    outlet_name: string;
    order_count: number;
    total_sales: number;
    total_paid: number;
    total_balance: number;
    orders: EodOrder[];
    existing_report?: {
        id: number;
        sentiments: string;
        order_notes: Record<string, string>;
        submitted_at: string;
        /** Set once an owner has read the report — the clerk's half of the loop. */
        acknowledged_at?: string | null;
        comments?: { id: number; body: string; user_id: number; user_name: string; created_at: string }[];
    } | null;
}

// ── Props ────────────────────────────────────────────────────────────────────

interface Props {
    outletId: number;
    outletName: string;
    registerId: number;
    onClose: () => void;
    /** Called after the user successfully submits the EoD report.
     *  The parent (PosPage) should set eodSubmitted=true so the
     *  Register modal's close-mode unblocks. */
    onSubmitSuccess: () => void;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtNum = (n: number) =>
    n.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// ── RichEditor ────────────────────────────────────────────────────────────────
// Lightweight contenteditable WYSIWYG with Bold / Italic / Bullet toolbar.
// Stores HTML internally; exposes plain-text-compatible HTML to the parent.

interface RichEditorProps {
    value: string;
    onChange: (html: string) => void;
    rows?: number;
    className?: string;
}

function RichEditor({ value, onChange, rows = 6, className }: RichEditorProps) {
    const ref = useRef<HTMLDivElement>(null);
    // Track whether we are mid-user-input to avoid cursor-jumping on re-render
    const isUserEditing = useRef(false);

    // Sync external value into the editor only when it changes from outside
    // (e.g. date change repopulating from DB) — not on every keystroke.
    useEffect(() => {
        if (!ref.current || isUserEditing.current) return;
        // Only overwrite if the content actually differs to preserve cursor
        if (ref.current.innerHTML !== value) {
            ref.current.innerHTML = value;
        }
    }, [value]);

    const exec = useCallback((cmd: string, arg?: string) => {
        ref.current?.focus();
        document.execCommand(cmd, false, arg);
        if (ref.current) onChange(ref.current.innerHTML);
    }, [onChange]);

    const handleInput = () => {
        isUserEditing.current = true;
        if (ref.current) onChange(ref.current.innerHTML);
    };

    const handleBlur = () => {
        isUserEditing.current = false;
    };

    // Prevent default on toolbar mousedown so editor doesn't lose focus
    const prevent = (e: React.MouseEvent) => e.preventDefault();

    const minH = `${rows * 1.625}rem`;

    return (
        <div className={clsx("rounded-xl border border-surface-200 overflow-hidden focus-within:ring-2 focus-within:ring-brand-300 focus-within:border-brand-400 transition-all", className)}>
            {/* Toolbar */}
            <div
                className="flex items-center gap-0.5 px-2 py-1.5 bg-surface-50 border-b border-surface-100"
                onMouseDown={prevent}
            >
                {[
                    { cmd: "bold",          label: <strong className="text-xs font-black">B</strong>,  title: "Bold" },
                    { cmd: "italic",        label: <em className="text-xs font-semibold italic">I</em>, title: "Italic" },
                    { cmd: "insertUnorderedList", label: (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                        </svg>
                    ), title: "Bullet list" },
                ].map(({ cmd, label, title }) => (
                    <button
                        key={cmd}
                        type="button"
                        title={title}
                        onMouseDown={prevent}
                        onClick={() => exec(cmd)}
                        className="w-7 h-7 flex items-center justify-center rounded hover:bg-surface-200 text-surface-600 hover:text-surface-900 transition-colors"
                    >
                        {label}
                    </button>
                ))}
            </div>
            {/* Editable area */}
            <div
                ref={ref}
                contentEditable
                suppressContentEditableWarning
                onInput={handleInput}
                onBlur={handleBlur}
                className="w-full px-3 py-2.5 text-xs leading-relaxed text-surface-900 outline-none bg-white overflow-y-auto prose prose-sm max-w-none [&_ul]:list-disc [&_ul]:pl-4 [&_li]:my-0.5"
                style={{ minHeight: minH }}
            />
        </div>
    );
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function UserEodModal({
    outletId,
    outletName,
    registerId,
    onClose,
    onSubmitSuccess,
}: Props) {
    const toast = useToastStore();
    const today = new Date().toISOString().split("T")[0];
    const [date, setDate] = useState(today);

    // Per-order notes keyed by order id string
    const [orderNotes, setOrderNotes] = useState<Record<string, string>>({});
    // General day sentiments / observations
    const [sentiments, setSentiments] = useState("");
    // Which order row is expanded to show items + note
    const [expandedId, setExpandedId] = useState<number | null>(null);
    // Whether the report has been submitted this session
    const [submitted, setSubmitted] = useState(false);

    // ── Fetch user's EoD summary ──────────────────────────────────────────────
    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ["user-eod", outletId, date],
        queryFn: () =>
            get<{ summary: EodSummary }>("/v1/admin/pos/reports/user-eod", {
                params: { outlet_id: String(outletId), date },
            }),
    });

    const summary: EodSummary | undefined = data?.summary;

    // Reply to management on our own report. Posts to the same thread the owner
    // uses; the endpoint authorises the report's author explicitly, precisely so
    // the person who was asked can answer.
    const [replyBody, setReplyBody] = useState("");
    const replyMutation = useMutation({
        mutationFn: (body: string) =>
            post(`/v1/admin/pos/reports/eod/${summary?.existing_report?.id}/comments`, { body }),
        onSuccess: () => {
            setReplyBody("");
            void refetch();
        },
    });

    // Reset and re-populate whenever the fetched summary changes (i.e. date changed or
    // data first arrives). If there's an existing report populate from it; otherwise clear.
    useEffect(() => {
        const existing = summary?.existing_report;
        if (existing) {
            setOrderNotes(existing.order_notes ?? {});
            setSentiments(existing.sentiments ?? "");
            setSubmitted(!!existing.submitted_at);
        } else {
            // New date with no prior report — clear the form
            setOrderNotes({});
            setSentiments("");
        }
    // summary?.date captures a date change even when there's no existing report
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [summary?.date, summary?.existing_report?.id]);

    // ── Derived stats ─────────────────────────────────────────────────────────
    const stats = useMemo(() => {
        if (!summary) return null;
        return {
            count:        summary.order_count,
            totalSales:   summary.total_sales,
            totalPaid:    summary.total_paid,
            totalBalance: summary.total_balance,
        };
    }, [summary]);

    // ── Submit mutation ───────────────────────────────────────────────────────
    const saveMutation = useMutation({
        mutationFn: () =>
            post<{ message: string; report_id: number }>(
                "/v1/admin/pos/reports/user-eod",
                {
                    outlet_id:   outletId,
                    date,
                    register_id: registerId,
                    order_notes: orderNotes,
                    sentiments,
                },
            ),
        onSuccess: () => {
            toast.success("End of Day report saved!");
            setSubmitted(true);
            onSubmitSuccess();
        },
        onError: (err: { message: string }) => {
            toast.error(err.message || "Failed to save EoD report.");
        },
    });

    const isToday = date === today;
    const orders = summary?.orders ?? [];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col animate-slide-up">

                {/* ── Header ── */}
                <div className="px-5 py-4 border-b border-surface-100 shrink-0">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="font-bold text-surface-900 flex items-center gap-2 text-base">
                                <span className="w-7 h-7 rounded-lg bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-black shrink-0">
                                    EOD
                                </span>
                                End of Day Report
                            </h2>
                            <p className="text-xs text-surface-500 mt-0.5">{outletName}</p>
                        </div>
                        <div className="flex items-center gap-2 flex-wrap justify-end">
                            <input
                                type="date"
                                value={date}
                                onChange={(e) => {
                                    setDate(e.target.value);
                                    setSubmitted(false);
                                }}
                                max={today}
                                className="input text-xs py-1"
                            />
                            <button
                                onClick={() => window.print()}
                                className="btn-secondary btn-sm gap-1"
                                title="Print report"
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0" />
                                </svg>
                                Print
                            </button>
                            <button
                                onClick={onClose}
                                className="btn-ghost btn-icon btn-sm"
                                aria-label="Close"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    {/* Submitted badge */}
                    {submitted && (
                        <div className="mt-3 flex items-center gap-2 text-xs text-success bg-success-light rounded-lg px-3 py-2">
                            <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            <span className="font-medium">
                                {isToday
                                    ? "EoD report submitted — you can now close the register."
                                    : "Report already submitted for this date."}
                            </span>
                        </div>
                    )}

                    {/* ── The clerk's half of the loop ─────────────────────────────
                        Submitting used to be the end of it: no way to tell whether
                        anyone read the report, and no way to answer a question about
                        it. Owners now acknowledge and comment; both surface here, and
                        replies go back on the same thread. */}
                    {submitted && summary?.existing_report && (
                        <div className="mt-3 space-y-2">
                            {summary.existing_report.acknowledged_at ? (
                                <p className="text-2xs text-success font-semibold">
                                    ✓ Read by management on {new Date(summary.existing_report.acknowledged_at).toLocaleString("en-KE", { dateStyle: "medium", timeStyle: "short" })}
                                </p>
                            ) : (
                                <p className="text-2xs text-surface-400">Not yet read by management.</p>
                            )}

                            {(summary.existing_report.comments?.length ?? 0) > 0 && (
                                <div className="space-y-2">
                                    {summary.existing_report.comments!.map((c) => (
                                        <div key={c.id} className="bg-white border border-surface-200 rounded-xl px-3 py-2">
                                            <div className="flex items-baseline justify-between gap-2 mb-0.5">
                                                <span className="text-2xs font-bold text-surface-700">{c.user_name}</span>
                                                <span className="text-2xs text-surface-400 tabular-nums">
                                                    {new Date(c.created_at).toLocaleString("en-KE", { dateStyle: "short", timeStyle: "short" })}
                                                </span>
                                            </div>
                                            <p className="text-xs text-surface-700 whitespace-pre-wrap break-words">{c.body}</p>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div className="flex items-end gap-2">
                                <textarea
                                    value={replyBody}
                                    onChange={(e) => setReplyBody(e.target.value)}
                                    rows={2}
                                    placeholder="Reply to management…"
                                    className="input flex-1 resize-none text-xs" />
                                <button
                                    onClick={() => replyBody.trim() && replyMutation.mutate(replyBody.trim())}
                                    disabled={!replyBody.trim() || replyMutation.isPending}
                                    className="shrink-0 px-3 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-40 transition-colors">
                                    {replyMutation.isPending ? "…" : "Reply"}
                                </button>
                            </div>
                        </div>
                    )}
                </div>

                {/* ── Body ── */}
                <div className="flex-1 overflow-y-auto p-5 space-y-5">
                    {isLoading ? (
                        <div className="flex items-center justify-center h-40">
                            <Spinner size="lg" />
                        </div>
                    ) : isError || !summary ? (
                        <div className="flex flex-col items-center justify-center h-40 gap-2 text-surface-400">
                            <p className="text-sm">No data available for this date.</p>
                        </div>
                    ) : (
                        <>
                            {/* ── KPI Summary ── */}
                            <div>
                                <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide mb-2">
                                    {new Date(date + "T00:00:00").toLocaleDateString("en-KE", {
                                        weekday: "long", year: "numeric", month: "long", day: "numeric",
                                    })}
                                </p>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    {[
                                        {
                                            label: "No. of Sales",
                                            value: String(stats!.count),
                                            color: "text-surface-900",
                                            bg: "bg-surface-50",
                                        },
                                        {
                                            label: "Total Sales",
                                            value: `KES ${fmtNum(stats!.totalSales)}`,
                                            color: "text-brand-700",
                                            bg: "bg-brand-50",
                                        },
                                        {
                                            label: "Total Paid",
                                            value: `KES ${fmtNum(stats!.totalPaid)}`,
                                            color: "text-success",
                                            bg: "bg-success-light",
                                        },
                                        {
                                            label: "Balance",
                                            value: `KES ${fmtNum(stats!.totalBalance)}`,
                                            color: stats!.totalBalance > 0 ? "text-warning" : "text-surface-400",
                                            bg: "bg-surface-50",
                                        },
                                    ].map((k) => (
                                        <div key={k.label} className={clsx("rounded-xl p-3 text-center", k.bg)}>
                                            <p className="text-2xs text-surface-400">{k.label}</p>
                                            <p className={clsx("font-bold mt-0.5 text-sm", k.color)}>{k.value}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* ── Order List ── */}
                            <div>
                                <h3 className="text-xs font-semibold text-surface-700 mb-2">
                                    Orders ({orders.length})
                                </h3>

                                {orders.length === 0 ? (
                                    <p className="text-xs text-surface-400 italic text-center py-8">
                                        No sales recorded for this date.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {orders.map((order, idx) => {
                                            const isExpanded = expandedId === order.id;
                                            const note = orderNotes[String(order.id)] ?? order.eod_note ?? "";
                                            const hasBal = order.balance > 0.01;

                                            return (
                                                <div
                                                    key={order.id}
                                                    className={clsx(
                                                        "rounded-xl border transition-all",
                                                        isExpanded
                                                            ? "border-brand-200 bg-brand-50/30"
                                                            : "border-surface-100 bg-surface-50 hover:border-surface-200",
                                                    )}
                                                >
                                                    {/* Row header — click to expand */}
                                                    <button
                                                        className="w-full text-left px-4 py-3 flex items-center gap-3"
                                                        onClick={() =>
                                                            setExpandedId(isExpanded ? null : order.id)
                                                        }
                                                    >
                                                        {/* Index badge */}
                                                        <span className="w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-2xs font-black flex items-center justify-center shrink-0">
                                                            {idx + 1}
                                                        </span>

                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-baseline gap-2 flex-wrap">
                                                                <span className="text-xs font-semibold text-surface-900">
                                                                    {order.customer_name}
                                                                </span>
                                                                <span className="text-2xs text-surface-400">
                                                                    #{order.order_number}
                                                                </span>
                                                            </div>
                                                            <p className="text-2xs text-surface-400 truncate mt-0.5">
                                                                {order.items
                                                                    .map(
                                                                        (i) =>
                                                                            `${i.quantity > 1 ? `${i.quantity}× ` : ""}${i.product_name}`,
                                                                    )
                                                                    .join(", ")}
                                                            </p>
                                                        </div>

                                                        <div className="text-right shrink-0">
                                                            <p className="text-xs font-bold text-surface-900">
                                                                KES {fmtNum(order.total_amount)}
                                                            </p>
                                                            {hasBal ? (
                                                                <span className="text-2xs text-warning font-medium">
                                                                    Bal: KES {fmtNum(order.balance)}
                                                                </span>
                                                            ) : (
                                                                <span className="text-2xs text-success font-medium">
                                                                    Paid
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* Note indicator dot */}
                                                        {note.trim() && (
                                                            <span
                                                                className="w-2 h-2 rounded-full bg-brand-400 shrink-0"
                                                                title="Has note"
                                                            />
                                                        )}

                                                        <svg
                                                            className={clsx(
                                                                "w-4 h-4 text-surface-400 shrink-0 transition-transform",
                                                                isExpanded && "rotate-180",
                                                            )}
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            strokeWidth={2}
                                                        >
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                        </svg>
                                                    </button>

                                                    {/* Expanded detail */}
                                                    {isExpanded && (
                                                        <div className="px-4 pb-4 space-y-3 border-t border-surface-100 pt-3">
                                                            {/* Items */}
                                                            <div className="space-y-1">
                                                                {order.items.map((item, i) => (
                                                                    <div
                                                                        key={i}
                                                                        className="flex items-baseline justify-between text-xs"
                                                                    >
                                                                        <span className="text-surface-700 flex-1 mr-2">
                                                                            {item.quantity > 1 && (
                                                                                <span className="font-medium text-surface-900">
                                                                                    {item.quantity}×{" "}
                                                                                </span>
                                                                            )}
                                                                            {item.product_name}
                                                                            {item.variant_name && (
                                                                                <span className="text-surface-400">
                                                                                    {" "}({item.variant_name})
                                                                                </span>
                                                                            )}
                                                                            {" — "}
                                                                            <span className="text-surface-500">
                                                                                KES {fmtNum(item.unit_price)} each
                                                                            </span>
                                                                        </span>
                                                                        <span className="text-surface-900 font-medium shrink-0">
                                                                            KES {fmtNum(item.total_price)}
                                                                        </span>
                                                                    </div>
                                                                ))}
                                                            </div>

                                                            {/* Totals reconciliation */}
                                                            <div className="bg-white rounded-lg px-3 py-2 space-y-1 text-xs border border-surface-100">
                                                                <div className="flex justify-between text-surface-600">
                                                                    <span>Total</span>
                                                                    <span className="font-semibold text-surface-900">
                                                                        KES {fmtNum(order.total_amount)}
                                                                    </span>
                                                                </div>
                                                                <div className="flex justify-between text-surface-600">
                                                                    <span>Paid</span>
                                                                    <span className="font-semibold text-success">
                                                                        KES {fmtNum(order.amount_paid)}
                                                                    </span>
                                                                </div>
                                                                {hasBal && (
                                                                    <div className="flex justify-between border-t border-surface-100 pt-1 font-semibold text-warning">
                                                                        <span>Balance</span>
                                                                        <span>KES {fmtNum(order.balance)}</span>
                                                                    </div>
                                                                )}
                                                            </div>

                                                            {/* Per-order note */}
                                                            <div>
                                                                <label className="text-2xs font-medium text-surface-500 mb-1 block">
                                                                    Note for this order (optional)
                                                                </label>
                                                                <textarea
                                                                    value={note}
                                                                    onChange={(e) =>
                                                                        setOrderNotes((prev) => ({
                                                                            ...prev,
                                                                            [String(order.id)]: e.target.value,
                                                                        }))
                                                                    }
                                                                    rows={2}
                                                                    placeholder={`e.g. Balance to be paid on delivery, client requested alterations…`}
                                                                    className="input resize-none text-xs"
                                                                />
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>

                            {/* ── Day Sentiments ── */}
                            <div>
                                <label className="text-xs font-semibold text-surface-700 mb-1 block">
                                    Daily Notes & Sentiments
                                </label>
                                <p className="text-2xs text-surface-400 mb-2">
                                    Anything notable about today — client interactions, phone calls, challenges, requests, stock needs, observations, etc.
                                </p>
                                <RichEditor
                                    value={sentiments}
                                    onChange={setSentiments}
                                    rows={6}
                                />
                            </div>
                        </>
                    )}
                </div>

                {/* ── Footer ── */}
                <div className="p-4 border-t border-surface-100 shrink-0 flex gap-3">
                    <button
                        onClick={onClose}
                        className="btn-secondary flex-1"
                    >
                        {submitted ? "Close" : "Cancel"}
                    </button>

                    {summary && (
                        <button
                            onClick={() => saveMutation.mutate()}
                            disabled={saveMutation.isPending}
                            className={clsx(
                                "flex-1 btn gap-2",
                                submitted ? "btn-secondary" : "btn-primary",
                            )}
                        >
                            {saveMutation.isPending && (
                                <div className="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin opacity-60" />
                            )}
                            {submitted ? "Update Report" : "Submit EoD Report"}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}