import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { posApi } from "@/api/pos";
import type { PosSale } from "@/api/pos";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";

interface Props {
    outletId: number;
    outletName: string;
    onClose: () => void;
    /** Called when the cashier restores a pending unpaid order into the cart */
    onRestoreCart?: (sale: PosSale) => void;
}

// A sale is restorable when it was created (order exists) but no payment has
// been recorded yet — status=pending AND payment_status=pending.
function isRestorable(sale: PosSale): boolean {
    return sale.status === "pending" && (sale.payment_status === "pending" || !sale.payment_status);
}

function SaleDetailPanel({
    sale,
    onClose,
    onVoid,
    onRestore,
}: {
    sale: PosSale;
    onClose: () => void;
    onVoid: (id: number) => void;
    onRestore?: (sale: PosSale) => void;
}) {
    const fmt = (n: number | null | undefined) => (n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 });
    const restorable = isRestorable(sale);
    const { can } = usePermissions();
    const canVoid = can("pos.void");

    return (
        <div className="absolute inset-0 bg-white z-10 flex flex-col animate-slide-left">
            <div className="px-5 py-4 border-b border-surface-100 flex items-center gap-3">
                <button onClick={onClose} className="btn-ghost btn-icon btn-sm">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                </button>
                <div className="flex-1">
                    <h3 className="font-semibold text-surface-900 text-sm">{sale.order_number}</h3>
                    <p className="text-2xs text-surface-400">{new Date(sale.created_at).toLocaleString("en-KE")}</p>
                </div>
                <span className={clsx(
                    "badge text-2xs",
                    sale.status === "completed" ? "badge-success" :
                    sale.status === "pending"   ? "bg-warning-light text-warning-dark" :
                    "badge-danger",
                )}>
                    {restorable ? "Awaiting payment" : sale.status}
                </span>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-4">

                {/* Restore notice */}
                {restorable && (
                    <div className="bg-brand-50 border border-brand-200 rounded-xl px-4 py-3 text-xs text-brand-700">
                        <p className="font-semibold">No payment recorded</p>
                        <p className="mt-0.5 opacity-80">
                            This order was created but not yet paid. You can restore it to the cart to add items, make adjustments, or proceed to payment.
                        </p>
                    </div>
                )}

                {/* Customer & cashier */}
                <div className="bg-surface-50 rounded-xl p-3 text-xs space-y-1.5">
                    {sale.customer_name && (
                        <div className="flex justify-between">
                            <span className="text-surface-500">Customer</span>
                            <span className="font-medium">{sale.customer_name}</span>
                        </div>
                    )}
                    <div className="flex justify-between">
                        <span className="text-surface-500">Cashier</span>
                        <span className="font-medium">{sale.cashier_name ?? "—"}</span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-surface-500">Payment</span>
                        <span className="font-medium capitalize">
                            {sale.payment_method
                                ? (sale.payment_method ?? "").replace(/_/g, " ")
                                : restorable ? "Pending" : "—"}
                        </span>
                    </div>
                    {sale.payment_reference && (
                        <div className="flex justify-between">
                            <span className="text-surface-500">Reference</span>
                            <span className="font-medium font-mono">{sale.payment_reference}</span>
                        </div>
                    )}
                </div>

                {/* Items */}
                <div>
                    <p className="text-xs font-semibold text-surface-600 mb-2">Items</p>
                    <div className="space-y-2">
                        {sale.items.map((item, i) => (
                            <div key={i} className="flex items-start justify-between text-xs">
                                <div className="flex-1 pr-3">
                                    <p className="font-medium text-surface-900">{item.product_name}</p>
                                    <p className="text-surface-400">
                                        {item.variant_name} · {item.quantity} × {fmt(item.unit_price)}
                                        {item.discount_amount > 0 && (
                                            <> · <span className="text-warning-dark">-{fmt(item.discount_amount)}</span></>
                                        )}
                                    </p>
                                </div>
                                <span className="font-semibold text-surface-900">{fmt(item.subtotal)}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Totals */}
                <div className="border-t border-surface-100 pt-3 space-y-1.5 text-xs">
                    <div className="flex justify-between text-surface-500">
                        <span>Subtotal</span><span>{fmt(sale.subtotal)}</span>
                    </div>
                    {sale.discount_amount > 0 && (
                        <div className="flex justify-between text-warning-dark">
                            <span>Discount</span><span>-{fmt(sale.discount_amount)}</span>
                        </div>
                    )}
                    {sale.tax_amount > 0 && (
                        <div className="flex justify-between text-surface-500">
                            <span>VAT</span><span>{fmt(sale.tax_amount)}</span>
                        </div>
                    )}
                    <div className="flex justify-between font-bold text-sm pt-1 border-t border-surface-100">
                        <span>Total</span>
                        <span className="text-brand-600">KES {fmt(sale.total)}</span>
                    </div>
                    {sale.cash_received && (
                        <>
                            <div className="flex justify-between text-surface-500">
                                <span>Cash Received</span><span>{fmt(sale.cash_received)}</span>
                            </div>
                            <div className="flex justify-between text-surface-500">
                                <span>Change</span><span>{fmt(sale.change_given ?? 0)}</span>
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* Action buttons */}
            {sale.status !== "voided" && (
                <div className="p-4 border-t border-surface-100 space-y-2">
                    {/* Restore to cart — only for unpaid pending orders */}
                    {restorable && onRestore && (
                        <button
                            onClick={() => onRestore(sale)}
                            className="btn-primary w-full gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            Restore to Cart
                        </button>
                    )}
                    {/* Void — available for all non-voided sales the user has pos.void for */}
                    {canVoid && (
                    <button
                        onClick={() => onVoid(sale.id)}
                        className={clsx("w-full btn-sm", restorable ? "btn-ghost text-danger hover:bg-danger-light" : "btn-danger")}
                    >
                        Void Sale
                    </button>
                    )}
                </div>
            )}
        </div>
    );
}

export default function SalesHistoryDrawer({ outletId, outletName, onClose, onRestoreCart }: Props) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const todayLocal = new Date();
    const todayStr = `${todayLocal.getFullYear()}-${String(todayLocal.getMonth() + 1).padStart(2, "0")}-${String(todayLocal.getDate()).padStart(2, "0")}`;
    const [dateFilter, setDateFilter] = useState(todayStr);
    const [search, setSearch] = useState("");
    const [selectedSale, setSelectedSale] = useState<PosSale | null>(null);
    const [voidingId, setVoidingId] = useState<number | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["pos-sales", outletId, dateFilter],
        queryFn: () => posApi.sales(outletId, { date: dateFilter, my_orders_only: "1" }),
    });
    const sales = data?.data ?? [];

    const filteredSales = search
        ? sales.filter((s) =>
            s.order_number.toLowerCase().includes(search.toLowerCase()) ||
            (s.customer_name ?? "").toLowerCase().includes(search.toLowerCase())
        )
        : sales;

    const voidMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            posApi.voidSale(id, reason),
        onSuccess: () => {
            toast.success("Sale voided");
            setSelectedSale(null);
            setVoidingId(null);
            qc.invalidateQueries({ queryKey: ["pos-sales", outletId] });
        },
        onError: () => {
            toast.error("Failed to void sale");
            setVoidingId(null);
        },
    });

    const summary = {
        total: sales.reduce((s, i) => s + (i.status !== "voided" ? i.total : 0), 0),
        count: sales.filter((s) => s.status !== "voided").length,
        cash:  sales.filter((s) => s.payment_method === "cash"  && s.status !== "voided").reduce((s, i) => s + i.total, 0),
        mpesa: sales.filter((s) => s.payment_method === "mpesa" && s.status !== "voided").reduce((s, i) => s + i.total, 0),
        card:  sales.filter((s) => s.payment_method === "card"  && s.status !== "voided").reduce((s, i) => s + i.total, 0),
    };

    const handleRestore = (sale: PosSale) => {
        onRestoreCart?.(sale);
        onClose();
    };

    return (
        <div className="fixed inset-0 z-40 flex">
            <div className="flex-1 bg-black/30" onClick={onClose} />
            <div className="w-full max-w-lg bg-white shadow-2xl flex flex-col relative">

                {/* Detail panel overlay */}
                {selectedSale && (
                    <SaleDetailPanel
                        sale={selectedSale}
                        onClose={() => setSelectedSale(null)}
                        onRestore={handleRestore}
                        onVoid={(id) => {
                            setVoidingId(id);
                            if (confirm("Are you sure you want to void this sale? This cannot be undone.")) {
                                voidMutation.mutate({ id, reason: "Voided by manager" });
                            } else {
                                setVoidingId(null);
                            }
                        }}
                    />
                )}

                {/* Header */}
                <div className="px-5 py-4 border-b border-surface-100 shrink-0">
                    <div className="flex items-center justify-between mb-3">
                        <div>
                            <h2 className="font-bold text-surface-900">My Orders</h2>
                            <p className="text-xs text-surface-500">{outletName}</p>
                        </div>
                        <button onClick={onClose} className="btn-ghost btn-icon btn-sm">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div className="flex gap-2">
                        <input type="date" value={dateFilter} onChange={(e) => setDateFilter(e.target.value)}
                            className="input text-xs w-36 shrink-0" />
                        <input type="search" placeholder="Search receipt #…" value={search}
                            onChange={(e) => setSearch(e.target.value)} className="input text-xs flex-1" />
                    </div>
                </div>

                {/* Summary pills */}
                <div className="px-4 py-3 border-b border-surface-50 grid grid-cols-4 gap-2 shrink-0">
                    {[
                        { label: "Total", value: summary.total, cls: "text-surface-900" },
                        { label: "Cash",  value: summary.cash,  cls: "text-success"     },
                        { label: "M-Pesa",value: summary.mpesa, cls: "text-brand-600"   },
                        { label: "Card",  value: summary.card,  cls: "text-info"        },
                    ].map((s) => (
                        <div key={s.label} className="bg-surface-50 rounded-lg p-2 text-center">
                            <p className="text-2xs text-surface-400">{s.label}</p>
                            <p className={clsx("text-xs font-bold mt-0.5", s.cls)}>
                                {s.value.toLocaleString("en-KE", { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
                            </p>
                        </div>
                    ))}
                </div>

                {/* Sales list */}
                <div className="flex-1 overflow-y-auto">
                    {isLoading ? (
                        <div className="flex items-center justify-center h-32"><Spinner /></div>
                    ) : filteredSales.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-32 text-surface-400 gap-2">
                            <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                            </svg>
                            <p className="text-sm">No orders for this date</p>
                            <p className="text-2xs text-surface-300">Only your orders appear here</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-surface-50">
                            {filteredSales.map((sale) => {
                                const pending = isRestorable(sale);
                                return (
                                    <button key={sale.id} onClick={() => setSelectedSale(sale)}
                                        className="w-full px-5 py-3 hover:bg-surface-50 transition-colors text-left flex items-center gap-3">
                                        <div className={clsx(
                                            "w-8 h-8 rounded-lg flex items-center justify-center shrink-0",
                                            pending                            ? "bg-warning-light text-warning-dark" :
                                            sale.payment_method === "cash"    ? "bg-success-light text-success" :
                                            sale.payment_method === "mpesa"   ? "bg-brand-50 text-brand-600" :
                                            "bg-info-light text-info",
                                        )}>
                                            {pending ? (
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            ) : (
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9" />
                                                </svg>
                                            )}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span className="text-xs font-semibold text-surface-900">{sale.order_number}</span>
                                                {sale.status === "voided" && <span className="badge-danger text-2xs">Voided</span>}
                                                {pending && <span className="text-2xs font-semibold px-1.5 py-0.5 rounded-full bg-warning-light text-warning-dark">Unpaid</span>}
                                            </div>
                                            <p className="text-2xs text-surface-400 truncate">
                                                {sale.customer_name ?? "Walk-in"} · {sale.items.length} item{sale.items.length !== 1 ? "s" : ""}
                                            </p>
                                        </div>
                                        <div className="text-right shrink-0">
                                            <p className={clsx(
                                                "text-sm font-bold",
                                                sale.status === "voided" ? "line-through text-surface-400" : "text-surface-900",
                                            )}>
                                                KES {sale.total.toLocaleString("en-KE", { minimumFractionDigits: 2 })}
                                            </p>
                                            <p className="text-2xs text-surface-400">
                                                {new Date(sale.created_at).toLocaleTimeString("en-KE", { timeStyle: "short" })}
                                            </p>
                                        </div>
                                        <svg className="w-4 h-4 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}