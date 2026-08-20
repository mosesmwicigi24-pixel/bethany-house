// src/pages/reports/OrderPipelinePage.tsx
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { reportsApi, type PipelineOrder } from "@/api/reports";
import { ordersApi } from "@/api/orders";
import { fmtKes } from "@/api/expenses";
import { openWhatsApp } from "@/lib/whatsapp";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { KPI_GRID, KpiCard, TableWrapper, EmptyRow } from "./reportShared";
import { clsx } from "clsx";
import dayjs from "dayjs";
import type { ApiError } from "@/types";

/* ============================================================================
   Unconfirmed orders — the queue behind the pipeline figure.

   Revenue is recognised on confirmation, so a cart nobody confirmed is not
   income. That is the right accounting answer and a useless operational one:
   98 carts worth KES 1.1m do not stop existing because a report stopped
   counting them. Somebody has to look at each one and either make the sale
   real or kill it. This is where they do that.

   Design follows from that single job:
     - biggest money first, because that is where an hour of work pays most;
     - age on every row, because a cart from this morning is a phone call and
       one from four months ago is housekeeping;
     - Confirm and Cancel inline, because bouncing to the order page for each
       of a hundred rows is how a backlog stays a backlog.
   ============================================================================ */

const AGE_TONE: Record<string, string> = {
    fresh:   "text-success-700",
    recent:  "text-surface-700",
    stale:   "text-amber-700",
    dormant: "text-danger",
};

/** Which aging bucket a row falls in — same boundaries the server uses. */
function bucketOf(days: number): keyof typeof AGE_TONE {
    if (days <= 7)  return "fresh";
    if (days <= 30) return "recent";
    if (days <= 90) return "stale";
    return "dormant";
}

const CHANNEL_LABEL: Record<string, string> = {
    // Buckets (current payloads) and the legacy names (cached ones).
    till: "Till Sales", web: "Web Orders", chat: "Chat Orders", quoted: "Quoted Sales",
    whatsapp: "WhatsApp", online: "Online", pos: "POS",
};

export default function OrderPipelinePage() {
    const [sort, setSort] = useState<"value" | "age">("value");
    const [busyId, setBusyId] = useState<number | null>(null);
    const navigate = useNavigate();
    const qc = useQueryClient();
    const { can } = usePermissions();
    const toast = useToastStore();

    const canWork = can("orders.edit");

    const query = useQuery({
        queryKey: ["order-pipeline", sort],
        queryFn: () => reportsApi.orderPipeline({ sort }),
    });

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["order-pipeline"] });
        // The sales report's recognised total changes the moment a cart is
        // confirmed, so it must not be left showing the pre-confirmation figure.
        qc.invalidateQueries({ queryKey: ["report-sales-ledger"] });
    };

    const advance = useMutation({
        mutationFn: ({ id, status }: { id: number; status: "confirmed" | "cancelled" }) =>
            ordersApi.updateStatus(id, { status }),
        onMutate: ({ id }) => setBusyId(id),
        onSettled: () => setBusyId(null),
        onSuccess: (_d, v) => {
            toast.success(
                v.status === "confirmed"
                    ? "Order confirmed — it now counts as sales"
                    : "Order cancelled",
            );
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const d = query.data;

    if (query.isLoading) {
        return <div className="flex justify-center p-12"><Spinner /></div>;
    }

    return (
        <div className="space-y-6 p-5">
            <div>
                <h1 className="text-2xl font-bold text-surface-900">Unconfirmed orders</h1>
                <p className="text-sm text-surface-500 mt-1 max-w-3xl">
                    Carts customers started on the storefront or over WhatsApp and never had
                    confirmed. They are <strong>not</strong> counted as sales. Confirm the real
                    ones to move them into income, cancel the rest.
                </p>
            </div>

            {/* Headline + aging */}
            <div className={KPI_GRID}>
                <KpiCard label="Unconfirmed value" value={fmtKes(d?.summary.value ?? 0)}
                         sub={`${d?.summary.orders ?? 0} orders — not in sales`} />
                {d?.aging.map(a => (
                    <KpiCard key={a.key} label={a.label} value={fmtKes(a.value)}
                             sub={`${a.orders} order${a.orders === 1 ? "" : "s"}`} />
                ))}
            </div>

            <p className="text-xs text-surface-500">
                Foreign orders are counted at the reporting rate of{" "}
                <strong>128 KES to 1 USD</strong> — not the 100 used when quoting a customer.
                {!!d?.summary.excluded_non_kes_orders && (
                    <>
                        {" "}
                        {d.summary.excluded_non_kes_orders} order
                        {d.summary.excluded_non_kes_orders === 1 ? " is" : "s are"} in a currency with
                        no reporting rate set; {d.summary.excluded_non_kes_orders === 1 ? "it is" : "they are"}{" "}
                        listed below but left out of the totals rather than converted at a guess.
                    </>
                )}
            </p>

            {/* Per channel */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {d?.by_channel.map(c => (
                    <div key={c.channel} className="card p-4">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wide">{c.label}</p>
                        <p className="text-xl font-bold text-surface-900 mt-1 tabular-nums">{fmtKes(c.value)}</p>
                        <p className="text-xs text-surface-500 mt-0.5">{c.orders} unconfirmed</p>
                    </div>
                ))}
            </div>

            {/* The queue */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-3 flex items-center justify-between gap-4">
                    <div>
                        <p className="font-semibold text-surface-900">The queue</p>
                        <p className="text-xs text-surface-500 mt-0.5">
                            {sort === "value" ? "Biggest recoverable money first." : "Oldest first."}
                        </p>
                    </div>
                    <div className="flex gap-1 shrink-0">
                        {(["value", "age"] as const).map(s => (
                            <button key={s} onClick={() => setSort(s)}
                                    className={clsx("btn-sm", sort === s ? "btn-primary" : "btn-ghost")}>
                                {s === "value" ? "By value" : "By age"}
                            </button>
                        ))}
                    </div>
                </div>

                <TableWrapper>
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Channel</th>
                                <th>Customer</th>
                                <th className="text-right">Items</th>
                                <th className="text-right">Value</th>
                                <th className="text-right">Age</th>
                                <th className="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!d?.orders.length && (
                                <EmptyRow cols={7} text="Nothing unconfirmed. Every order has been worked." />
                            )}
                            {d?.orders.map((o: PipelineOrder) => {
                                const bucket = bucketOf(o.age_days);
                                const busy = busyId === o.id;

                                return (
                                    <tr key={o.id} className={clsx(busy && "opacity-50")}>
                                        <td>
                                            <button onClick={() => navigate(`/sales/orders/${o.id}`)}
                                                    className="font-semibold text-primary hover:underline">
                                                {o.order_number}
                                            </button>
                                            <p className="text-xs text-surface-400">
                                                {dayjs(o.created_at).format("D MMM YYYY")}
                                            </p>
                                        </td>
                                        <td className="text-sm">{CHANNEL_LABEL[o.channel] ?? o.channel}</td>
                                        <td>
                                            <p className="text-sm text-surface-900">{o.customer_name ?? "—"}</p>
                                            {o.customer_phone && (
                                                <p className="text-xs text-surface-400">{o.customer_phone}</p>
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">{o.item_count}</td>
                                        <td className="text-right tabular-nums font-semibold">
                                            {o.currency_code === "KES" ? (
                                                fmtKes(o.total_amount)
                                            ) : (
                                                <>
                                                    {/* Native first — staff talk to this customer in
                                                        dollars — with the KES the report counts beneath. */}
                                                    <span>{o.currency_code} {o.total_amount.toLocaleString()}</span>
                                                    <span className="block text-2xs font-normal text-surface-400">
                                                        {o.total_kes === null
                                                            ? "no reporting rate"
                                                            : `≈ ${fmtKes(o.total_kes)}`}
                                                    </span>
                                                </>
                                            )}
                                        </td>
                                        <td className={clsx("text-right tabular-nums font-medium", AGE_TONE[bucket])}>
                                            {o.age_days}d
                                        </td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                {o.customer_phone && (
                                                    <button
                                                        className="btn-ghost btn-sm"
                                                        title="Ask the customer whether they still want this"
                                                        onClick={() => {
                                                            const ok = openWhatsApp(
                                                                o.customer_phone,
                                                                `Hello${o.customer_name ? " " + o.customer_name : ""}, `
                                                                + `about your Bethany House order ${o.order_number} — `
                                                                + `would you like us to go ahead with it?`,
                                                            );
                                                            if (!ok) toast.error("That number cannot be messaged on WhatsApp");
                                                        }}>
                                                        Ask
                                                    </button>
                                                )}
                                                {canWork && (
                                                    <>
                                                        <button
                                                            className="btn-ghost btn-sm text-danger"
                                                            disabled={busy}
                                                            onClick={() => advance.mutate({ id: o.id, status: "cancelled" })}>
                                                            Cancel
                                                        </button>
                                                        <button
                                                            className="btn-primary btn-sm"
                                                            disabled={busy}
                                                            onClick={() => advance.mutate({ id: o.id, status: "confirmed" })}>
                                                            Confirm
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>
        </div>
    );
}
