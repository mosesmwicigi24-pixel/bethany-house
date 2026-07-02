import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { posApi } from "@/api/pos";
import type { PosSale } from "@/api/pos";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";

interface Props {
    outletId: number;
    outletName: string;
    onClose: () => void;
}

const RETURN_REASONS = [
    "Defective / damaged product",
    "Wrong item received",
    "Customer changed mind",
    "Size / fit issue",
    "Duplicate purchase",
    "Other",
];

export default function PosReturnsModal({ outletId, outletName, onClose }: Props) {
    const toast = useToastStore();
    const qc = useQueryClient();

    const [searchQuery, setSearchQuery] = useState("");
    const [selectedSale, setSelectedSale] = useState<PosSale | null>(null);
    const [returnItems, setReturnItems] = useState<Record<string, number>>({});
    const [reason, setReason] = useState(RETURN_REASONS[0]);
    const [customReason, setCustomReason] = useState("");
    const [refundMethod, setRefundMethod] = useState("cash");
    const [step, setStep] = useState<"search" | "select" | "confirm">("search");

    // Search sales
    const { data: salesData, isFetching: searching } = useQuery({
        queryKey: ["pos-return-search", outletId, searchQuery],
        queryFn: () => posApi.sales(outletId, { search: searchQuery }),
        enabled: searchQuery.length >= 3,
    });
    const sales = salesData?.data ?? [];

    const returnMutation = useMutation({
        mutationFn: () =>
            posApi.processReturn({
                original_order_id: selectedSale!.id,
                items: Object.entries(returnItems)
                    .filter(([, qty]) => qty > 0)
                    .map(([itemId, qty]) => {
                        const saleItem = selectedSale!.items.find((i) => String(i.id) === itemId);
                        return {
                            variant_id: saleItem?.variant_id ?? null,
                            quantity: qty,
                        };
                    }),
                reason: reason === "Other" ? customReason : reason,
                refund_method: refundMethod,
            }),
        onSuccess: () => {
            toast.success("Return processed successfully!");
            qc.invalidateQueries({ queryKey: ["pos-sales"] });
            onClose();
        },
        onError: (err: { message: string }) => toast.error(err.message),
    });

    const refundTotal = selectedSale
        ? Object.entries(returnItems).reduce((sum, [itemId, qty]) => {
            const item = selectedSale.items.find((i) => String(i.id) === itemId);
            return sum + (item ? (item.unit_price * qty) : 0);
        }, 0)
        : 0;

    const hasReturnItems = Object.values(returnItems).some((q) => q > 0);

    const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col animate-slide-up">
                {/* Header */}
                <div className="px-5 py-4 border-b border-surface-100 flex items-center gap-3 shrink-0">
                    {step !== "search" && (
                        <button
                            onClick={() => {
                                if (step === "confirm") setStep("select");
                                else { setStep("search"); setSelectedSale(null); setReturnItems({}); }
                            }}
                            className="btn-ghost btn-icon btn-sm"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                        </button>
                    )}
                    <div className="flex-1">
                        <h2 className="font-bold text-surface-900">Process Return</h2>
                        <p className="text-xs text-surface-500">{outletName}</p>
                    </div>
                    <button onClick={onClose} className="btn-ghost btn-icon btn-sm"
aria-label="Close">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Step: Search */}
                {step === "search" && (
                    <div className="flex-1 flex flex-col overflow-hidden">
                        <div className="p-4 border-b border-surface-50">
                            <div className="relative">
                                <input
                                    type="search"
                                    placeholder="Search by receipt number or customer name…"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="input pl-9"
                                    autoFocus
                                />
                                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">
                                    {searching
                                        ? <div className="w-3.5 h-3.5 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
                                        : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                                    }
                                </div>
                            </div>
                        </div>
                        <div className="flex-1 overflow-y-auto">
                            {sales.length === 0 && searchQuery.length >= 3 && !searching ? (
                                <div className="flex flex-col items-center justify-center h-32 text-surface-400 gap-1">
                                    <p className="text-sm">No sales found</p>
                                    <p className="text-2xs">Try a different receipt number</p>
                                </div>
                            ) : searchQuery.length < 3 ? (
                                <div className="flex flex-col items-center justify-center h-32 text-surface-300 gap-2">
                                    <svg className="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={0.8}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                                    <p className="text-sm">Enter at least 3 characters to search</p>
                                </div>
                            ) : (
                                <div className="divide-y divide-surface-50">
                                    {sales.filter((s) => s.status !== "voided").map((sale) => (
                                        <button
                                            key={sale.id}
                                            onClick={() => {
                                                setSelectedSale(sale);
                                                setReturnItems({});
                                                setStep("select");
                                            }}
                                            className="w-full px-5 py-3 hover:bg-surface-50 text-left flex items-center justify-between gap-3 transition-colors"
                                        >
                                            <div>
                                                <p className="text-sm font-semibold text-surface-900">{sale.order_number}</p>
                                                <p className="text-xs text-surface-400">
                                                    {sale.customer_name ?? "Walk-in"} · {new Date(sale.created_at).toLocaleDateString("en-KE")}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-bold text-surface-900">KES {fmt(sale.total)}</p>
                                                <p className="text-2xs text-surface-400 capitalize">{sale.payment_method?.replace("_", " ") ?? "-"}</p>
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Step: Select items */}
                {step === "select" && selectedSale && (
                    <div className="flex-1 flex flex-col overflow-hidden">
                        <div className="px-4 py-3 bg-surface-50 border-b border-surface-100 text-xs text-surface-600 shrink-0">
                            <p><strong>{selectedSale.order_number}</strong> · {new Date(selectedSale.created_at).toLocaleDateString("en-KE")}</p>
                            <p>{selectedSale.customer_name ?? "Walk-in"} · KES {fmt(selectedSale.total)}</p>
                        </div>
                        <div className="flex-1 overflow-y-auto p-4 space-y-3">
                            <p className="text-xs font-semibold text-surface-600">Select items to return:</p>
                            {selectedSale.items.map((item) => (
                                <div key={item.variant_id} className="flex items-center gap-3 p-3 bg-surface-50 rounded-xl">
                                    <div className="flex-1">
                                        <p className="text-xs font-medium text-surface-900">{item.product_name}</p>
                                        <p className="text-2xs text-surface-400">{item.variant_name} · {fmt(item.unit_price)} each</p>
                                    </div>
                                    <div className="flex items-center border border-surface-200 rounded-lg overflow-hidden bg-white">
                                        <button
                                            onClick={() => setReturnItems((prev) => ({
                                                ...prev,
                                                [item.id]: Math.max(0, (prev[item.id] ?? 0) - 1),
                                            }))}
                                            className="w-7 h-7 flex items-center justify-center text-surface-500 hover:bg-surface-50"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 12h-15" /></svg>
                                        </button>
                                        <span className="w-8 text-center text-xs font-semibold">
                                            {returnItems[item.id] ?? 0}
                                        </span>
                                        <button
                                            onClick={() => setReturnItems((prev) => ({
                                                ...prev,
                                                [item.id]: Math.min(item.quantity, (prev[item.id] ?? 0) + 1),
                                            }))}
                                            className="w-7 h-7 flex items-center justify-center text-surface-500 hover:bg-surface-50"
                                            aria-label="Add"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <span className="text-xs font-semibold text-surface-700 w-20 text-right">
                                        / {item.quantity}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <div className="p-4 border-t border-surface-100 shrink-0 flex gap-3">
                            <button onClick={onClose} className="btn-secondary flex-1 btn-sm">Cancel</button>
                            <button
                                onClick={() => setStep("confirm")}
                                disabled={!hasReturnItems}
                                className="btn-primary flex-1 btn-sm"
                            >
                                Continue →
                            </button>
                        </div>
                    </div>
                )}

                {/* Step: Confirm */}
                {step === "confirm" && selectedSale && (
                    <div className="flex-1 flex flex-col overflow-hidden">
                        <div className="flex-1 overflow-y-auto p-5 space-y-4">
                            {/* Refund summary */}
                            <div className="bg-brand-50 rounded-xl p-4 text-center">
                                <p className="text-xs text-brand-600">Refund Amount</p>
                                <p className="text-2xl font-bold text-brand-700 mt-1">KES {fmt(refundTotal)}</p>
                            </div>

                            {/* Return items */}
                            <div>
                                <p className="text-xs font-semibold text-surface-700 mb-2">Returning</p>
                                <div className="space-y-1">
                                    {Object.entries(returnItems)
                                        .filter(([, qty]) => qty > 0)
                                        .map(([variantId, qty]) => {
                                            const item = selectedSale.items.find((i) => i.variant_id === Number(variantId));
                                            return item ? (
                                                <div key={variantId} className="flex justify-between text-xs">
                                                    <span className="text-surface-700">{item.product_name} × {qty}</span>
                                                    <span className="font-medium">KES {fmt(item.unit_price * qty)}</span>
                                                </div>
                                            ) : null;
                                        })}
                                </div>
                            </div>

                            {/* Reason */}
                            <div>
                                <label className="label">Return Reason</label>
                                <select
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    className="input"
                                >
                                    {RETURN_REASONS.map((r) => (
                                        <option key={r} value={r}>{r}</option>
                                    ))}
                                </select>
                                {reason === "Other" && (
                                    <textarea
                                        value={customReason}
                                        onChange={(e) => setCustomReason(e.target.value)}
                                        placeholder="Please specify…"
                                        className="input mt-2 resize-none"
                                        rows={2}
                                    />
                                )}
                            </div>

                            {/* Refund method */}
                            <div>
                                <label className="label">Refund Method</label>
                                <div className="grid grid-cols-3 gap-2">
                                    {["cash", "mpesa", "store_credit"].map((m) => (
                                        <button
                                            key={m}
                                            onClick={() => setRefundMethod(m)}
                                            className={clsx(
                                                "py-2 rounded-xl border text-xs font-medium capitalize transition-all",
                                                refundMethod === m
                                                    ? "bg-brand-500 text-white border-brand-500"
                                                    : "bg-white border-surface-200 text-surface-600 hover:border-brand-300",
                                            )}
                                        >
                                            {m.replace("_", " ")}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                        <div className="p-4 border-t border-surface-100 flex gap-3 shrink-0">
                            <button onClick={() => setStep("select")} className="btn-secondary flex-1">Back</button>
                            <button
                                onClick={() => returnMutation.mutate()}
                                disabled={returnMutation.isPending || (reason === "Other" && !customReason)}
                                className="btn-primary flex-1 gap-2"
                            >
                                {returnMutation.isPending ? (
                                    <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                ) : null}
                                Process Return
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}