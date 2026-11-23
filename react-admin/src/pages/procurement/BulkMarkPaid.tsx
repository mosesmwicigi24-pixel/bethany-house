import { useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { supplierPaymentApi, type BulkPaidRow, type BulkSkippedRow, type OwedOrder } from "@/api/procurement";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import {
    PRE_RECORDS, SUPPLIER_PAYMENT_METHODS, SUPPLIER_PAYMENT_NEEDS_REFERENCE, SUPPLIER_REFERENCE_HINT,
} from "./SupplierPayments";

/**
 * Mark several purchase orders as paid in one go (owner, 2026-11-23). Every
 * order that still owes money is listed; the ones ticked each get ONE
 * payment for exactly what that order owes. The super admin's alone.
 */

const money = (n: number | string | null | undefined, currency = "KES") =>
    `${currency} ${Number(n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const day = (iso: string | null) =>
    iso ? new Date(`${iso}T12:00:00`).toLocaleDateString("en-KE", { day: "numeric", month: "short", year: "numeric" }) : "—";

function newRequestId() {
    return typeof crypto !== "undefined" && "randomUUID" in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** Selected balances, one total per currency — never a sum across currencies. */
function totals(rows: OwedOrder[]) {
    const by = new Map<string, number>();
    for (const r of rows) by.set(r.currency, (by.get(r.currency) ?? 0) + r.balance);
    return [...by.entries()].sort(([a], [b]) => (a === "KES" ? -1 : b === "KES" ? 1 : a.localeCompare(b)));
}

export function MarkOrdersPaidButton() {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button onClick={() => setOpen(true)} className="btn-secondary btn-sm whitespace-nowrap">
                Mark orders as paid
            </button>
            {open && <MarkPaidModal onClose={() => setOpen(false)} />}
        </>
    );
}

function MarkPaidModal({ onClose }: { onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["po-owed-orders"], queryFn: () => supplierPaymentApi.owedOrders() });

    const [picked, setPicked] = useState<Set<number>>(new Set());
    const [supplier, setSupplier] = useState<number | "">("");
    const [step, setStep] = useState<"pick" | "confirm" | "done">("pick");
    const [method, setMethod] = useState(PRE_RECORDS[0]);
    const [reference, setReference] = useState("");
    const [dateMode, setDateMode] = useState<"order_date" | "one_date">("order_date");
    const [paidAt, setPaidAt] = useState("");
    const [notes, setNotes] = useState("");
    const [saving, setSaving] = useState(false);
    const [requestId] = useState(newRequestId);
    const [result, setResult] = useState<{ paid: BulkPaidRow[]; skipped: BulkSkippedRow[] } | null>(null);

    const orders = data?.data ?? [];
    const suppliers = useMemo(() => {
        const m = new Map<number, string>();
        for (const o of orders) m.set(o.supplier_id, o.supplier);
        return [...m.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [orders]);
    const shown = supplier === "" ? orders : orders.filter((o) => o.supplier_id === supplier);
    const chosen = orders.filter((o) => picked.has(o.id));
    const allShownPicked = shown.length > 0 && shown.every((o) => picked.has(o.id));

    const toggle = (id: number) =>
        setPicked((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    const toggleShown = () =>
        setPicked((prev) => {
            const next = new Set(prev);
            for (const o of shown) { if (allShownPicked) next.delete(o.id); else next.add(o.id); }
            return next;
        });

    const needsRef = SUPPLIER_PAYMENT_NEEDS_REFERENCE.includes(method);
    const canSave = chosen.length > 0 && (!needsRef || reference.trim() !== "") && (dateMode === "order_date" || paidAt !== "");

    const save = async () => {
        setSaving(true);
        try {
            const res = await supplierPaymentApi.bulkPay({
                purchase_order_ids: chosen.map((o) => o.id),
                method,
                reference: reference.trim() || undefined,
                date_mode: dateMode,
                paid_at: dateMode === "one_date" ? paidAt : undefined,
                notes: notes.trim() || undefined,
                client_request_id: requestId,
            });
            setResult(res);
            setStep("done");
            qc.invalidateQueries({ queryKey: ["po-owed-orders"] });
            qc.invalidateQueries({ queryKey: ["po-payables"] });
            qc.invalidateQueries({ queryKey: ["purchase-orders"] });
            qc.invalidateQueries({ queryKey: ["po-payments"] });
            toast.success(res.message);
        } catch (e) {
            toast.error((e as { message?: string })?.message ?? "Could not mark the orders as paid.");
        } finally {
            setSaving(false);
        }
    };

    const selectionLine = chosen.length === 0
        ? "Nothing ticked yet"
        : `${chosen.length} order${chosen.length === 1 ? "" : "s"} · ${totals(chosen).map(([c, v]) => money(v, c)).join(" + ")}`;

    return (
        <Modal
            open
            size="lg"
            onClose={onClose}
            title={step === "done" ? "Marked as paid" : step === "confirm" ? "Confirm payment" : "Mark orders as paid"}
            footer={
                step === "pick" ? (
                    <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm font-medium text-surface-700">{selectionLine}</p>
                        <div className="flex gap-2 sm:justify-end">
                            <button onClick={onClose} className="btn-secondary btn-sm flex-1 sm:flex-none">Cancel</button>
                            <button onClick={() => setStep("confirm")} disabled={chosen.length === 0} className="btn-primary btn-sm flex-1 sm:flex-none">
                                Continue
                            </button>
                        </div>
                    </div>
                ) : step === "confirm" ? (
                    <div className="flex w-full gap-2 sm:justify-end">
                        <button onClick={() => setStep("pick")} disabled={saving} className="btn-secondary btn-sm flex-1 sm:flex-none">Back</button>
                        <button onClick={() => void save()} disabled={saving || !canSave} className="btn-primary btn-sm flex-1 sm:flex-none">
                            {saving ? <Spinner size="xs" /> : `Mark ${chosen.length} as paid`}
                        </button>
                    </div>
                ) : (
                    <div className="flex w-full sm:justify-end">
                        <button onClick={onClose} className="btn-primary btn-sm flex-1 sm:flex-none">Done</button>
                    </div>
                )
            }
        >
            {isLoading || !data ? (
                <div className="p-8 flex justify-center"><Spinner size="sm" /></div>
            ) : step === "pick" ? (
                <div className="p-4 sm:p-5 space-y-3">
                    {orders.length === 0 ? (
                        <p className="py-6 text-center text-sm text-surface-500">Nothing is owed on approved purchase orders.</p>
                    ) : (
                        <>
                            <p className="text-sm text-surface-600">
                                Tick the orders that are already paid. Each one gets a payment for exactly what it still owes.
                            </p>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <label className="flex items-center gap-2 text-sm font-medium text-surface-800">
                                    <input type="checkbox" className="h-4 w-4" checked={allShownPicked} onChange={toggleShown} />
                                    Select all{supplier === "" ? "" : " from this supplier"} ({shown.length})
                                </label>
                                {suppliers.length > 1 && (
                                    <select
                                        className="input text-sm sm:w-56"
                                        value={supplier}
                                        onChange={(e) => setSupplier(e.target.value === "" ? "" : Number(e.target.value))}
                                        aria-label="Supplier"
                                    >
                                        <option value="">All suppliers</option>
                                        {suppliers.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                                    </select>
                                )}
                            </div>
                            <ul className="divide-y divide-surface-100 rounded-xl border border-surface-200 max-h-[46vh] overflow-y-auto">
                                {shown.map((o) => (
                                    <li key={o.id}>
                                        <label className={clsx("flex cursor-pointer items-center gap-3 px-3 py-2.5", picked.has(o.id) && "bg-primary-50/60")}>
                                            <input type="checkbox" className="h-4 w-4 shrink-0" checked={picked.has(o.id)} onChange={() => toggle(o.id)} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-surface-800">{o.supplier}</span>
                                                <span className="block truncate font-mono text-2xs text-surface-500">{o.po_number}</span>
                                                <span className="block text-2xs text-surface-500">Ordered {day(o.order_date)}</span>
                                            </span>
                                            <span className="shrink-0 text-right">
                                                <span className="block text-sm font-semibold text-surface-800">{money(o.balance, o.currency)}</span>
                                                {o.paid > 0 && (
                                                    <span className="block text-2xs text-surface-500">of {money(o.payable, o.currency)}</span>
                                                )}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            ) : step === "confirm" ? (
                <div className="p-4 sm:p-5 space-y-4">
                    <div className="rounded-xl bg-surface-50 px-4 py-3">
                        <p className="text-2xs uppercase tracking-wide text-surface-500">You are marking as paid</p>
                        <p className="mt-0.5 text-base font-semibold text-surface-900">{selectionLine}</p>
                        <p className="mt-1 text-2xs text-surface-500">
                            This says the money has already gone to the supplier. It is not an expense and does not change stock.
                        </p>
                    </div>

                    <div>
                        <label className="label" htmlFor="bulk-method">How it was paid</label>
                        <select id="bulk-method" className="input" value={method} onChange={(e) => setMethod(e.target.value)}>
                            <option value={PRE_RECORDS[0]}>{PRE_RECORDS[1]}</option>
                            {SUPPLIER_PAYMENT_METHODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                        {method === PRE_RECORDS[0] && (
                            <p className="mt-1 text-2xs text-surface-500">
                                For orders paid before the hub kept supplier payments. No reference is needed.
                            </p>
                        )}
                    </div>

                    {needsRef && (
                        <div>
                            <label className="label" htmlFor="bulk-ref">Reference</label>
                            <input
                                id="bulk-ref" className="input" value={reference} onChange={(e) => setReference(e.target.value)}
                                placeholder={SUPPLIER_REFERENCE_HINT[method] ?? ""}
                            />
                            <p className="mt-1 text-2xs text-surface-500">The same reference is put on every order in this batch.</p>
                        </div>
                    )}

                    <fieldset>
                        <legend className="label">Date paid</legend>
                        <div className="space-y-2">
                            <label className="flex items-start gap-2 text-sm text-surface-800">
                                <input type="radio" className="mt-0.5" checked={dateMode === "order_date"} onChange={() => setDateMode("order_date")} />
                                <span>
                                    Each order's own date
                                    <span className="block text-2xs text-surface-500">Paid on the day the order was made.</span>
                                </span>
                            </label>
                            <label className="flex items-start gap-2 text-sm text-surface-800">
                                <input type="radio" className="mt-0.5" checked={dateMode === "one_date"} onChange={() => setDateMode("one_date")} />
                                <span className="flex-1">
                                    One date for all of them
                                    {dateMode === "one_date" && (
                                        <input
                                            type="date" className="input mt-1.5" value={paidAt}
                                            max={new Date().toISOString().slice(0, 10)}
                                            onChange={(e) => setPaidAt(e.target.value)} aria-label="Date paid"
                                        />
                                    )}
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <div>
                        <label className="label" htmlFor="bulk-notes">Note (optional)</label>
                        <textarea id="bulk-notes" className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
                    </div>
                </div>
            ) : (
                <div className="p-4 sm:p-5 space-y-4">
                    <div className="rounded-xl bg-success-50 px-4 py-3">
                        <p className="text-base font-semibold text-success-700">
                            {result?.paid.length ?? 0} order{result?.paid.length === 1 ? "" : "s"} marked as paid
                        </p>
                        {!!result?.paid.length && (
                            <p className="mt-0.5 text-sm text-success-700">
                                {[...result.paid.reduce((m, p) => m.set(p.currency, (m.get(p.currency) ?? 0) + p.amount), new Map<string, number>())]
                                    .map(([c, v]) => money(v, c)).join(" + ")}
                            </p>
                        )}
                    </div>
                    {!!result?.skipped.length && (
                        <div>
                            <p className="text-sm font-medium text-surface-800">{result.skipped.length} skipped</p>
                            <ul className="mt-1.5 divide-y divide-surface-100 rounded-xl border border-surface-200">
                                {result.skipped.map((s) => (
                                    <li key={s.id} className="px-3 py-2">
                                        <p className="font-mono text-2xs text-surface-500">{s.po_number ?? `#${s.id}`}</p>
                                        <p className="text-sm text-surface-700">{s.reason}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <p className="text-2xs text-surface-500">
                        A payment marked in error is voided on the order's own page, under Payments to supplier.
                    </p>
                </div>
            )}
        </Modal>
    );
}
