import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { salesRevisionApi } from "@/api/salesRevisions";
import type { DocumentRevision } from "@/api/procurement";

/**
 * Revisions of issued sales documents (owner, 2026-10-16): the invoice copy
 * that follows its order, and payment details the owner corrected. The
 * server keeps every earlier version; nothing here deletes anything.
 */

export function RevisionRows({ rows, label }: { rows: DocumentRevision[]; label: string }) {
    return (
        <ul className="space-y-2">
            {rows.map((r) => (
                <li key={r.id} className="rounded-lg border border-line p-2.5 text-xs space-y-1">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-semibold text-surface-800">{label} Rev {r.revision} → Rev {r.revision + 1}</p>
                        <p className="text-surface-500">{r.by ?? "—"} · {new Date(r.created_at).toLocaleString()}</p>
                    </div>
                    <p className="text-surface-700"><span className="text-surface-500">Why:</span> {r.reason}</p>
                    {r.changes.length > 0 && <ul className="list-disc pl-4 text-surface-600">{r.changes.map((c, i) => <li key={i}>{c}</li>)}</ul>}
                </li>
            ))}
        </ul>
    );
}

/** The order's invoice-copy and payment revisions; renders nothing when there are none. */
export function OrderDocumentHistory({ orderId, version }: { orderId: number; version?: unknown }) {
    const { data } = useQuery({
        queryKey: ["order-document-revisions", orderId, version],
        queryFn: () => salesRevisionApi.orderHistory(orderId),
    });
    const [open, setOpen] = useState(false);
    const invoiceRows = data?.invoice?.revisions ?? [];
    const paymentRows = Object.values(data?.payments ?? {}).flat();
    if (invoiceRows.length === 0 && paymentRows.length === 0) return null;

    return (
        <div className="mt-3 rounded-xl border border-line p-3">
            <button onClick={() => setOpen(!open)} className="flex w-full items-center justify-between text-xs font-semibold text-surface-700">
                <span>
                    Document history
                    {data?.invoice && data.invoice.revision > 0 && <span className="ml-2 font-normal text-surface-500">Invoice {data.invoice.number} · Rev {data.invoice.revision}</span>}
                </span>
                <span>{open ? "Hide" : `Show (${invoiceRows.length + paymentRows.length})`}</span>
            </button>
            {open && (
                <div className="mt-3 space-y-3">
                    {invoiceRows.length > 0 && <RevisionRows rows={invoiceRows} label={`Invoice ${data?.invoice?.number ?? ""}`} />}
                    {paymentRows.length > 0 && <RevisionRows rows={paymentRows} label="Payment" />}
                </div>
            )}
        </div>
    );
}

const METHODS = [
    { value: "mpesa", label: "M-PESA" },
    { value: "bank_transfer", label: "Bank transfer" },
    { value: "card", label: "Card" },
    { value: "cheque", label: "Cheque" },
];

/** The owner corrects a payment's details; the amount is never changed here. */
export function CorrectPaymentModal({
    orderId, payment, onClose, onSaved,
}: {
    orderId: number;
    payment: { id: number; payment_number?: string | null; payment_method: string; provider_reference?: string | null; phone_number?: string | null; paid_at?: string | null; amount: number | string };
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const [reference, setReference] = useState(payment.provider_reference ?? "");
    const [phone, setPhone] = useState(payment.phone_number ?? "");
    const [method, setMethod] = useState(payment.payment_method);
    // datetime-local works in the viewer's own time (Nairobi), not UTC.
    const toLocalInput = (iso?: string | null) => {
        if (!iso) return "";
        const d = new Date(iso);
        return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
    };
    const [paidAt, setPaidAt] = useState(toLocalInput(payment.paid_at));
    const [reason, setReason] = useState("");
    const [saving, setSaving] = useState(false);
    const isCash = payment.payment_method === "cash";

    const save = async () => {
        if (reason.trim().length < 5) return toast.error("Say why you are correcting it (at least 5 characters).");
        const data: Parameters<typeof salesRevisionApi.revisePayment>[2] = { reason: reason.trim() };
        if (reference !== (payment.provider_reference ?? "")) data.provider_reference = reference || null;
        if (phone !== (payment.phone_number ?? "")) data.phone_number = phone || null;
        if (method !== payment.payment_method) data.payment_method = method;
        const originalAt = toLocalInput(payment.paid_at);
        if (paidAt && paidAt !== originalAt) data.paid_at = new Date(paidAt).toISOString();
        setSaving(true);
        try {
            const res = await salesRevisionApi.revisePayment(orderId, payment.id, data);
            toast.success(res.message);
            onSaved();
        } catch (err: any) {
            const first = err?.errors ? Object.values(err.errors as Record<string, string[]>)[0]?.[0] : null;
            toast.error(first ?? err?.message ?? "Could not correct it.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open onClose={onClose} title={`Correct payment ${payment.payment_number ?? ""} (owner)`} size="md">
            <div className="space-y-3 text-sm">
                <p className="rounded-lg bg-surface-50 border border-line p-3 text-xs text-surface-600">
                    The amount ({Number(payment.amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}) is not changed here — if it is wrong, void the payment and record it again.
                    A payment taken at a till keeps its method and date; cash is never switched in or out. The old details are kept, and the receipt shows the new revision.
                </p>
                <label className="block space-y-1"><span className="text-xs text-surface-500">Reference / code</span>
                    <input value={reference} onChange={(e) => setReference(e.target.value)} className="input w-full font-mono" /></label>
                <label className="block space-y-1"><span className="text-xs text-surface-500">Phone</span>
                    <input value={phone} onChange={(e) => setPhone(e.target.value)} className="input w-full" /></label>
                <div className="grid grid-cols-2 gap-3">
                    <label className="space-y-1"><span className="text-xs text-surface-500">Method</span>
                        <select value={method} onChange={(e) => setMethod(e.target.value)} disabled={isCash} className="input w-full">
                            {isCash && <option value="cash">Cash</option>}
                            {!METHODS.some((m) => m.value === payment.payment_method) && !isCash && <option value={payment.payment_method}>{payment.payment_method}</option>}
                            {METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </select></label>
                    <label className="space-y-1"><span className="text-xs text-surface-500">Paid at</span>
                        <input type="datetime-local" value={paidAt} onChange={(e) => setPaidAt(e.target.value)} className="input w-full" /></label>
                </div>
                <label className="block space-y-1"><span className="text-xs font-medium text-surface-700">Why are you correcting it? *</span>
                    <input value={reason} onChange={(e) => setReason(e.target.value)} className="input w-full" placeholder="e.g. Customer paid by bank, not M-PESA" /></label>
                <div className="flex justify-end gap-2 pt-2 border-t border-line">
                    <button onClick={onClose} className="btn-ghost" disabled={saving}>Cancel</button>
                    <button onClick={() => void save()} className="btn-primary" disabled={saving}>{saving ? <Spinner size="sm" /> : "Save correction"}</button>
                </div>
            </div>
        </Modal>
    );
}
