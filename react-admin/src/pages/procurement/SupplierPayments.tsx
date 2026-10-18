import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { supplierPaymentApi, type PayableSummary, type SupplierPayment } from "@/api/procurement";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";

/**
 * Payments to the supplier against this purchase order — the payables
 * ledger. Not expenses: a supplier payment is cash out, and the goods reach
 * profit as cost of sales when sold. Owed = the supplier's invoice amount
 * when recorded, else the PO total.
 */

const money = (n: number | string | null | undefined, currency = "KES") =>
    `${currency} ${Number(n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const METHODS: Array<[string, string]> = [
    ["bank_transfer", "Bank transfer"], ["mpesa", "M-PESA"], ["cheque", "Cheque"],
    ["cash", "Cash"], ["card", "Card"], ["other", "Other"],
];
const NEEDS_REFERENCE = ["bank_transfer", "mpesa", "cheque", "card"];

const STATUS: Record<PayableSummary["status"], [string, string]> = {
    unpaid: ["Unpaid", "text-amber-600"],
    partially_paid: ["Part paid", "text-amber-600"],
    paid: ["Paid in full", "text-success-600"],
    overpaid: ["Overpaid", "text-danger"],
};

const who = (u?: { first_name?: string; last_name?: string } | null) =>
    u ? `${u.first_name ?? ""} ${u.last_name ?? ""}`.trim() : "";

function newRequestId() {
    return typeof crypto !== "undefined" && "randomUUID" in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function SupplierPaymentsCard({ poId, payable, canPay, canVoid, onChanged }: {
    poId: number;
    /** Payments are taken only once the order is approved. */
    payable: boolean;
    canPay: boolean;
    canVoid: boolean;
    onChanged: () => void;
}) {
    const qc = useQueryClient();
    const [recording, setRecording] = useState(false);
    const [voiding, setVoiding] = useState<SupplierPayment | null>(null);
    const { data, isLoading } = useQuery({
        queryKey: ["po-payments", poId],
        queryFn: () => supplierPaymentApi.list(poId),
    });

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["po-payments", poId] });
        qc.invalidateQueries({ queryKey: ["po-payables"] });
        onChanged();
    };

    if (isLoading || !data) {
        return <div className="py-3"><Spinner size="sm" /></div>;
    }
    const s = data.summary;
    const [label, tone] = STATUS[s.status];

    return (
        <div className="space-y-2">
            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Payments to supplier</p>
            <div className="text-xs space-y-1">
                <Row label={s.payable_source === "supplier_invoice" ? "Owed (supplier's invoice)" : "Owed (order total)"} value={money(s.payable, s.currency)} />
                <Row label="Paid" value={money(s.paid, s.currency)} />
                <Row
                    label={s.balance < 0 ? "Credit with supplier" : "Balance"}
                    value={
                        <span className={clsx("font-semibold", tone)}>
                            {money(Math.abs(s.balance), s.currency)}
                            {s.currency !== "KES" && s.balance_kes !== null && (
                                <span className="block text-2xs font-normal text-surface-500">≈ {money(Math.abs(s.balance_kes))} at today's reporting rate</span>
                            )}
                        </span>
                    }
                />
                <Row label="Status" value={<span className={clsx("font-semibold", tone)}>{label}</span>} />
            </div>

            {data.payments.length > 0 && (
                <ul className="divide-y divide-surface-100 border border-surface-100 rounded-lg">
                    {data.payments.map((p) => (
                        <li key={p.id} className={clsx("px-2.5 py-2 text-xs", p.voided_at && "opacity-60")}>
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className={clsx("font-medium text-surface-800", p.voided_at && "line-through")}>
                                        {money(p.amount, p.currency_code)}
                                        <span className="font-normal text-surface-500"> · {METHODS.find(([m]) => m === p.method)?.[1] ?? p.method}</span>
                                    </p>
                                    <p className="text-surface-500 break-all">
                                        {p.payment_number} · {String(p.paid_at).slice(0, 10)}{p.reference && ` · ${p.reference}`}
                                    </p>
                                    {p.currency_code !== "KES" && p.amount_kes && (
                                        <p className="text-2xs text-surface-500">KES {Number(p.amount_kes).toLocaleString("en-KE", { minimumFractionDigits: 2 })} at {Number(p.rate_to_kes)} when paid</p>
                                    )}
                                    {who(p.recorder) && <p className="text-2xs text-surface-400">Recorded by {who(p.recorder)}</p>}
                                    {p.voided_at && (
                                        <p className="text-2xs text-danger">Voided{who(p.voider) && ` by ${who(p.voider)}`}: {p.void_reason}</p>
                                    )}
                                </div>
                                {canVoid && !p.voided_at && (
                                    <button onClick={() => setVoiding(p)} className="text-2xs text-danger underline shrink-0">Void</button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canPay && payable && s.balance > 0 && (
                <button onClick={() => setRecording(true)} className="btn-secondary btn-sm whitespace-nowrap">Record payment</button>
            )}
            {canPay && !payable && <p className="text-2xs text-surface-400">Payments are recorded once the order is approved.</p>}

            {recording && (
                <RecordPaymentModal poId={poId} summary={s} onClose={() => setRecording(false)} onSaved={() => { setRecording(false); refresh(); }} />
            )}
            {voiding && (
                <VoidPaymentModal poId={poId} payment={voiding} onClose={() => setVoiding(null)} onSaved={() => { setVoiding(null); refresh(); }} />
            )}
        </div>
    );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-3">
            <span className="text-surface-500">{label}</span>
            <span className="text-right text-surface-800">{value}</span>
        </div>
    );
}

function RecordPaymentModal({ poId, summary, onClose, onSaved }: {
    poId: number; summary: PayableSummary; onClose: () => void; onSaved: () => void;
}) {
    const toast = useToastStore();
    const [amount, setAmount] = useState(String(summary.balance));
    const [method, setMethod] = useState("bank_transfer");
    const [reference, setReference] = useState("");
    const [paidOn, setPaidOn] = useState(new Date().toLocaleDateString("en-CA"));
    const [notes, setNotes] = useState("");
    const [saving, setSaving] = useState(false);
    // One id per opening of the form: a double tap or a retry records once.
    const [requestId] = useState(newRequestId);

    const value = Number(amount);
    const over = value > summary.balance + 0.0001;
    const needsRef = NEEDS_REFERENCE.includes(method) && !reference.trim();
    const invalid = !(value > 0) || over || needsRef;

    const save = async () => {
        setSaving(true);
        try {
            const res = await supplierPaymentApi.record(poId, {
                amount: Math.round(value * 100) / 100,
                method,
                reference: reference.trim() || undefined,
                paid_at: paidOn,
                notes: notes.trim() || undefined,
                client_request_id: requestId,
            });
            toast.success(res.message);
            onSaved();
        } catch (err: any) {
            toast.error(err?.message ?? "Could not record the payment.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open title="Record payment to supplier" onClose={onClose}>
            <div className="p-5 space-y-3">
                <p className="text-xs text-surface-500">
                    Outstanding: <span className="font-semibold text-surface-800">{money(summary.balance, summary.currency)}</span>.
                    A supplier payment is not an expense; it pays for stock.
                </p>
                <label className="block text-xs font-medium text-surface-600">
                    Amount ({summary.currency})
                    <input type="number" min={0.01} step={0.01} value={amount} onChange={(e) => setAmount(e.target.value)} className="input w-full mt-1" />
                </label>
                {over && <p className="text-xs text-danger">That is more than is outstanding.</p>}
                <label className="block text-xs font-medium text-surface-600">
                    Method
                    <select value={method} onChange={(e) => setMethod(e.target.value)} className="input w-full mt-1">
                        {METHODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                </label>
                <label className="block text-xs font-medium text-surface-600">
                    Reference{NEEDS_REFERENCE.includes(method) ? "" : " (optional)"}
                    <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Bank, M-PESA or cheque number" className="input w-full mt-1" />
                </label>
                <label className="block text-xs font-medium text-surface-600">
                    Paid on
                    <input type="date" value={paidOn} max={new Date().toLocaleDateString("en-CA")} onChange={(e) => setPaidOn(e.target.value)} className="input w-full mt-1" />
                </label>
                <label className="block text-xs font-medium text-surface-600">
                    Notes (optional)
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} className="input w-full mt-1" />
                </label>
                <div className="flex justify-end gap-2 pt-1">
                    <button onClick={onClose} className="btn-ghost btn-sm">Cancel</button>
                    <button onClick={() => void save()} disabled={saving || invalid} className="btn-primary btn-sm">
                        {saving ? <Spinner size="xs" /> : "Record payment"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

function VoidPaymentModal({ poId, payment, onClose, onSaved }: {
    poId: number; payment: SupplierPayment; onClose: () => void; onSaved: () => void;
}) {
    const toast = useToastStore();
    const [reason, setReason] = useState("");
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);
        try {
            const res = await supplierPaymentApi.void(poId, payment.id, reason.trim());
            toast.success(res.message);
            onSaved();
        } catch (err: any) {
            toast.error(err?.message ?? "Could not void the payment.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open title={`Void ${payment.payment_number}`} onClose={onClose}>
            <div className="p-5 space-y-3">
                <p className="text-xs text-surface-500">
                    {money(payment.amount, payment.currency_code)} stays on record, marked void, with your reason. Use this only for a
                    payment recorded in error; the person who recorded it can't void it.
                </p>
                <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} placeholder="Why is it being voided?" className="input w-full" />
                <div className="flex justify-end gap-2">
                    <button onClick={onClose} className="btn-ghost btn-sm">Cancel</button>
                    <button onClick={() => void save()} disabled={saving || reason.trim().length < 5} className="btn-danger btn-sm">
                        {saving ? <Spinner size="xs" /> : "Void payment"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

/** "Owed to suppliers": every approved order not yet paid in full. */
export function OwedToSuppliersChip() {
    const [open, setOpen] = useState(false);
    const { data } = useQuery({ queryKey: ["po-payables"], queryFn: () => supplierPaymentApi.payables(), staleTime: 60_000 });
    if (!data) return null;

    return (
        <>
            <button onClick={() => setOpen(true)} className="btn-secondary btn-sm whitespace-nowrap">
                Owed to suppliers: {money(data.total_owed_kes)}
            </button>
            {open && (
                <Modal open title="Owed to suppliers" onClose={() => setOpen(false)} size="lg">
                    <div className="p-5 space-y-3">
                        {data.data.length === 0 ? (
                            <p className="text-sm text-surface-500">Nothing is owed on approved purchase orders.</p>
                        ) : (
                            <ul className="divide-y divide-surface-100">
                                {data.data.map((r) => (
                                    <li key={`${r.supplier_id}-${r.currency}`} className="py-2.5 flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-surface-800">{r.supplier}</p>
                                            <p className="text-2xs text-surface-500">
                                                {r.orders} order{r.orders === 1 ? "" : "s"} · owed {money(r.payable, r.currency)} · paid {money(r.paid, r.currency)}
                                            </p>
                                        </div>
                                        <div className={clsx("text-right text-sm font-semibold whitespace-nowrap", r.balance < 0 && "text-danger")}>
                                            {r.balance < 0 ? "Credit " : ""}{money(Math.abs(r.balance), r.currency)}
                                            {r.currency !== "KES" && r.balance_kes !== null && (
                                                <span className="block text-2xs font-normal text-surface-500">≈ {money(Math.abs(r.balance_kes))}</span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="text-2xs text-surface-400">{data.basis}</p>
                        {data.unconverted.length > 0 && (
                            <p className="text-2xs text-warning">Not in the KES total (no reporting rate): {data.unconverted.join(", ")}.</p>
                        )}
                    </div>
                </Modal>
            )}
        </>
    );
}
