/**
 * PendingQueuePage.tsx
 *
 * The unconfirmed-order worklist: every order no human has confirmed and no
 * money has touched. These are invisible to the financial reports by design
 * (recognition = human confirm OR money arrived), so this page is where they
 * stop being invisible to *people*. Sorted by value x age — the biggest order
 * going coldest sits on top.
 *
 * Every row exits by exactly one of three doors: talk to the customer then
 * Confirm, record a payment (from the order page), or Cancel with a note.
 *
 * Route: /sales/pending-queue   Permission: orders.view (confirm/cancel need orders.edit)
 */

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { ordersApi } from "@/api/orders";
import type { PendingQueueRow } from "@/api/orders";
import { neemaChatUrl } from "@/lib/neema";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

const kesFmt = (n: number) =>
    `KES ${Number(n).toLocaleString("en-KE", { maximumFractionDigits: 0 })}`;

const SOURCE_BADGES: Record<string, { label: string; badge: string }> = {
    chat:     { label: "Chat",   badge: "badge-success" },
    whatsapp: { label: "Chat",   badge: "badge-success" },
    quoted:   { label: "Quoted", badge: "badge-info" },
    web:      { label: "Web",    badge: "badge-neutral" },
    online:   { label: "Web",    badge: "badge-neutral" },
    till:     { label: "Till",   badge: "badge-amber" },
    pos:      { label: "Till",   badge: "badge-amber" },
};

function customerLabel(r: PendingQueueRow): string {
    const name = [r.customer_first_name, r.customer_last_name].filter(Boolean).join(" ").trim();
    return name || r.customer_phone || r.customer_email || "—";
}

/** Age chip: fresh is quiet, 7–30d is the money going cold, 30d+ is a cancel candidate. */
function AgeChip({ days }: { days: number }) {
    const cls = days >= 30 ? "badge-danger" : days >= 7 ? "badge-amber" : "badge-neutral";
    return <span className={clsx("badge", cls)}>{days}d</span>;
}

export default function PendingQueuePage() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canEdit = can("orders.edit");

    // The action modal: confirm needs an explicit "I spoke to the customer",
    // cancel needs a reason — both because the click IS the accounting event.
    const [action, setAction] = useState<{ kind: "confirm" | "cancel"; row: PendingQueueRow } | null>(null);
    const [note, setNote] = useState("");

    const { data, isLoading, isError } = useQuery({
        queryKey: ["pending-queue"],
        queryFn: ordersApi.pendingQueue,
    });

    const mutation = useMutation({
        mutationFn: ({ id, status, notes }: { id: number; status: "confirmed" | "cancelled"; notes?: string }) =>
            ordersApi.updateStatus(id, { status, ...(notes ? { notes } : {}) }),
        onSuccess: (_res, vars) => {
            toast.success(vars.status === "confirmed"
                ? "Order confirmed — it now counts as income."
                : "Order cancelled.");
            setAction(null);
            setNote("");
            queryClient.invalidateQueries({ queryKey: ["pending-queue"] });
            queryClient.invalidateQueries({ queryKey: ["orders"] });
        },
        onError: (e: ApiError) => toast.error(e?.message ?? "Update failed."),
    });

    const summary = data?.summary;
    const rows = data?.rows ?? [];

    return (
        <div className="flex flex-col h-full min-w-0 overflow-hidden">
            {/* Header */}
            <div className="px-4 sm:px-6 pt-5 pb-4 border-b border-line">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-lg sm:text-xl font-bold text-surface-900">Pending Queue</h1>
                        <p className="text-xs text-surface-400 mt-0.5">
                            Unconfirmed orders, no money received — talk to the customer, then confirm, take payment, or cancel
                        </p>
                    </div>
                    {summary && (
                        <div className="flex items-center gap-2 flex-wrap text-xs">
                            <span className="badge badge-neutral">{summary.total_count} orders</span>
                            <span className="badge badge-amber">{kesFmt(summary.total_kes)}</span>
                            <span className="badge badge-neutral">&lt;7d: {summary.fresh_count}</span>
                            <span className="badge badge-amber">7–30d: {summary.aging_count}</span>
                            <span className="badge badge-danger">30d+: {summary.stale_count}</span>
                        </div>
                    )}
                </div>
                {summary && summary.excluded_currencies.length > 0 && (
                    <p className="text-[11px] text-surface-400 mt-2">
                        Not in the KES total (no reporting rate):{" "}
                        {summary.excluded_currencies.map((e) => `${e.code} ${Number(e.amount).toLocaleString()} (${e.n})`).join(", ")}
                    </p>
                )}
            </div>

            {/* Body */}
            <div className="flex-1 overflow-auto p-4 sm:p-6">
                {isLoading ? (
                    <div className="flex justify-center py-16"><Spinner /></div>
                ) : isError ? (
                    <p className="text-center text-danger py-16">Failed to load the pending queue.</p>
                ) : rows.length === 0 ? (
                    <p className="text-center text-surface-400 py-16">Queue is empty — every order has been worked. 🎉</p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-line">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-50 text-surface-500 text-xs">
                                <tr>
                                    <th className="text-left font-medium px-4 py-2.5">Order</th>
                                    <th className="text-left font-medium px-4 py-2.5">Customer</th>
                                    <th className="text-left font-medium px-4 py-2.5">Source</th>
                                    <th className="text-left font-medium px-4 py-2.5">Age</th>
                                    <th className="text-right font-medium px-4 py-2.5">Value</th>
                                    <th className="text-right font-medium px-4 py-2.5">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {rows.map((r) => {
                                    const chat = neemaChatUrl(r.customer_phone, r.order_number);
                                    const src = r.source ? SOURCE_BADGES[r.source] : undefined;
                                    return (
                                        <tr key={r.id} className="hover:bg-surface-50">
                                            <td className="px-4 py-2.5">
                                                <button onClick={() => navigate(`/sales/orders/${r.id}`)}
                                                    className="font-medium text-brand-600 hover:underline">
                                                    {r.order_number}
                                                </button>
                                                {r.possible_duplicate && (
                                                    <span className="badge badge-danger ml-2" title="Another pending order shares this phone number — likely one sale quoted twice. Confirm one, cancel the twin.">
                                                        duplicate?
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <div className="text-surface-900">{customerLabel(r)}</div>
                                                {r.customer_phone && (
                                                    <div className="text-xs text-surface-400">{r.customer_phone}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                {src ? <span className={clsx("badge", src.badge)}>{src.label}</span>
                                                     : <span className="text-surface-400">—</span>}
                                            </td>
                                            <td className="px-4 py-2.5"><AgeChip days={r.days_pending} /></td>
                                            <td className="px-4 py-2.5 text-right">
                                                {r.kes_value != null ? (
                                                    <>
                                                        <div className="font-semibold text-surface-900">{kesFmt(Number(r.kes_value))}</div>
                                                        {r.currency_code.toUpperCase() !== "KES" && (
                                                            <div className="text-xs text-surface-400">
                                                                {r.currency_code.toUpperCase()} {Number(r.total_amount).toLocaleString()}
                                                            </div>
                                                        )}
                                                    </>
                                                ) : (
                                                    <div className="text-surface-500">
                                                        {r.currency_code.toUpperCase()} {Number(r.total_amount).toLocaleString()}
                                                        <span className="block text-[11px] text-surface-400">no rate</span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {chat && (
                                                        <a href={chat} target="_blank" rel="noreferrer"
                                                            className="btn-secondary btn-sm" title="Open the customer's chat in Neema">
                                                            Chat
                                                        </a>
                                                    )}
                                                    {canEdit && (
                                                        <>
                                                            <button onClick={() => { setAction({ kind: "confirm", row: r }); setNote(""); }}
                                                                className="btn-primary btn-sm">Confirm</button>
                                                            <button onClick={() => { setAction({ kind: "cancel", row: r }); setNote(""); }}
                                                                className="btn-secondary btn-sm text-danger">Cancel</button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Confirm / cancel modal */}
            {action && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onClick={() => setAction(null)}>
                    <div className="bg-white rounded-2xl shadow-xl max-w-md w-full p-6"
                        onClick={(e) => e.stopPropagation()}>
                        {action.kind === "confirm" ? (
                            <>
                                <h2 className="text-base font-bold text-surface-900">
                                    Confirm {action.row.order_number}?
                                </h2>
                                <p className="text-sm text-surface-500 mt-2">
                                    Confirming books this order as income
                                    {action.row.kes_value != null && <> ({kesFmt(Number(action.row.kes_value))})</>}.
                                    Only confirm after speaking with the customer — any unpaid amount becomes a
                                    receivable on the order.
                                </p>
                            </>
                        ) : (
                            <>
                                <h2 className="text-base font-bold text-surface-900">
                                    Cancel {action.row.order_number}?
                                </h2>
                                <p className="text-sm text-surface-500 mt-2">
                                    Say why, so the pipeline history stays honest.
                                </p>
                                <textarea value={note} onChange={(e) => setNote(e.target.value)}
                                    placeholder="e.g. customer unreachable after 3 attempts / duplicate of BH-…"
                                    className="input w-full mt-3 h-20 resize-none" />
                            </>
                        )}
                        <div className="flex justify-end gap-2 mt-5">
                            <button onClick={() => setAction(null)} className="btn-secondary btn-sm">Back</button>
                            <button
                                disabled={mutation.isPending || (action.kind === "cancel" && !note.trim())}
                                onClick={() => mutation.mutate({
                                    id: action.row.id,
                                    status: action.kind === "confirm" ? "confirmed" : "cancelled",
                                    notes: note.trim() || undefined,
                                })}
                                className={clsx("btn-sm", action.kind === "confirm" ? "btn-primary" : "btn-danger")}>
                                {mutation.isPending ? "Saving…"
                                    : action.kind === "confirm" ? "✓ I spoke to the customer — confirm"
                                    : "Cancel the order"}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
