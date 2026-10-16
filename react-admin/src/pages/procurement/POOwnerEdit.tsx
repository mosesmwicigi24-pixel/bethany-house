import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get } from "@/api/client";
import {
    poRevisionApi,
    purchaseOrderApi,
    type DocumentRevision,
    type POReviseLine,
    type PurchaseOrder,
} from "@/api/procurement";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";

/**
 * The owner's edits of issued purchase documents (2026-10-16). Every edit is
 * a revision: the server keeps the document as it was, with the reason. Goods
 * already received are corrected, never rewritten — stock moves by the
 * difference only.
 */

const money = (n: number | string | null | undefined, currency = "KES") =>
    `${currency} ${Number(n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const num = (v: unknown) => Number(v ?? 0);

interface EditLine extends POReviseLine {
    key: string;
    description: string;
    received: number;
}

const toLine = (i: PurchaseOrder["items"][number]): EditLine => ({
    key: `l${i.id}`,
    id: i.id,
    type: i.item_type,
    item_id: Number(i.item_type === "product" ? i.product_id : i.material_id),
    product_variant_id: i.product_variant_id ?? null,
    quantity: num(i.quantity),
    unit_price: num(i.unit_price),
    description: i.description ?? i.product?.name ?? i.material?.name ?? `Line ${i.id}`,
    received: num(i.quantity_received),
});

// ── Edit purchase order (owner) ───────────────────────────────────────────────

export function RevisePurchaseOrderModal({ po, onClose, onSaved }: { po: PurchaseOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const currency = po.currency_code ?? po.currency ?? "KES";
    const [lines, setLines] = useState<EditLine[]>(() => po.items.map(toLine));
    const [expected, setExpected] = useState((po.expected_delivery_date ?? "").slice(0, 10));
    const [terms, setTerms] = useState(po.payment_terms ?? "");
    const [notes, setNotes] = useState(po.notes ?? "");
    const [shipping, setShipping] = useState(num(po.shipping_amount ?? po.shipping_cost));
    const [tax, setTax] = useState(num(po.tax_amount ?? po.tax));
    const [reason, setReason] = useState("");
    const [saving, setSaving] = useState(false);

    // Add a line
    const [addType, setAddType] = useState<"product" | "material">("product");
    const [query, setQuery] = useState("");
    const [results, setResults] = useState<Array<{ id: number; name: string; sku?: string }>>([]);
    const [searching, setSearching] = useState(false);
    const [variantPick, setVariantPick] = useState<{ productId: number; name: string; options: Array<{ id: number; label: string; price: number | null }> } | null>(null);

    const search = async (q: string) => {
        setQuery(q);
        if (q.trim().length < 2) return setResults([]);
        setSearching(true);
        try {
            if (addType === "material") {
                const res = await get<{ data: Array<{ id: number; name: string }> }>("/v1/admin/inventory/materials", { params: { search: q, per_page: 15 } });
                setResults((res.data ?? []).map((m) => ({ id: m.id, name: m.name })));
            } else {
                const res = await get<{ data: any[] }>("/v1/admin/products", { params: { search: q, per_page: 15 } });
                setResults((res.data ?? []).map((p) => ({ id: p.id, name: p.en_translation?.name ?? p.sku ?? `Product #${p.id}`, sku: p.sku })));
            }
        } catch {
            setResults([]);
        } finally {
            setSearching(false);
        }
    };

    const addLine = (line: Omit<EditLine, "key" | "received">) => {
        setLines((prev) => [...prev, { ...line, key: `n${Date.now()}${Math.random()}`, received: 0 }]);
        setResults([]);
        setQuery("");
        setVariantPick(null);
    };

    const pick = async (r: { id: number; name: string; sku?: string }) => {
        if (addType === "material") {
            addLine({ type: "material", item_id: r.id, quantity: 1, unit_price: 0, description: r.name, product_variant_id: null });
            return;
        }
        try {
            const costs = await purchaseOrderApi.itemCosts(r.id, currency);
            if (costs.variants.length > 0) {
                setVariantPick({
                    productId: r.id,
                    name: r.name,
                    options: costs.variants.map((v) => ({ id: v.id, label: v.variant_name ? `${v.sku} — ${v.variant_name}` : v.sku, price: v.unit_price })),
                });
                return;
            }
            addLine({ type: "product", item_id: r.id, quantity: 1, unit_price: costs.product.unit_price ?? 0, description: r.sku ?? r.name, product_variant_id: null });
        } catch {
            addLine({ type: "product", item_id: r.id, quantity: 1, unit_price: 0, description: r.sku ?? r.name, product_variant_id: null });
        }
    };

    const update = (key: string, patch: Partial<EditLine>) => setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
    const subtotal = lines.reduce((s, l) => s + l.quantity * l.unit_price, 0);
    const total = subtotal + shipping + tax;

    const problem =
        lines.length === 0 ? "An order needs at least one line."
        : lines.find((l) => l.quantity < l.received) ? `${lines.find((l) => l.quantity < l.received)!.description}: ${lines.find((l) => l.quantity < l.received)!.received} already received — correct the goods receipt to go lower.`
        : lines.find((l) => l.type === "product" && !Number.isInteger(l.quantity)) ? "Products are ordered in whole pieces."
        : lines.find((l) => !(l.quantity > 0)) ? "Every line needs a quantity."
        : reason.trim().length < 5 ? "Say why you are changing it (at least 5 characters)."
        : null;

    const save = async () => {
        if (problem) return toast.error(problem);
        setSaving(true);
        try {
            const res = await poRevisionApi.revise(po.id, {
                reason: reason.trim(),
                expected_delivery_date: expected || undefined,
                payment_terms: terms || null,
                notes: notes || null,
                shipping_cost: shipping,
                tax,
                items: lines.map(({ id, type, item_id, product_variant_id, quantity, unit_price }) => ({ id, type, item_id, product_variant_id, quantity, unit_price })),
            });
            toast.success(res.message);
            res.notes.forEach((n) => toast.info(n));
            onSaved();
        } catch (err: any) {
            const first = err?.errors ? Object.values(err.errors as Record<string, string[]>)[0]?.[0] : null;
            toast.error(first ?? err?.message ?? "Could not save the changes.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open onClose={onClose} title={`Edit ${po.po_number} (owner)`} size="xl">
            <div className="space-y-4 text-sm">
                <p className="rounded-lg bg-surface-50 border border-line p-3 text-xs text-surface-600">
                    Saving keeps the current version as Revision {po.revision ?? 0} and makes this Rev {(po.revision ?? 0) + 1}.
                    Lines with goods received can't go below what was received or be removed — correct the goods receipt for that.
                    A new price on received goods corrects the stock cost; goods already sold keep the cost they were sold at.
                    {["approved", "ordered", "partially_received", "received"].includes(po.status) && " Your edit keeps the order approved."}
                    {po.status === "pending_approval" && " This order is waiting for approval: it goes back to draft to be submitted again."}
                </p>

                <ul className="divide-y divide-line rounded-lg border border-line">
                    {lines.map((l) => (
                        <li key={l.key} className="p-3 space-y-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="font-medium text-surface-800">{l.description}</p>
                                    {l.received > 0 && <p className="text-2xs text-surface-500">{l.received} received — can't go lower here</p>}
                                </div>
                                <button type="button" disabled={l.received > 0} title={l.received > 0 ? "Goods received on this line" : "Remove"}
                                    onClick={() => setLines((prev) => prev.filter((x) => x.key !== l.key))}
                                    aria-label={`Remove ${l.description}`}
                                    className="btn-ghost btn-sm text-danger disabled:opacity-30 shrink-0">✕</button>
                            </div>
                            <div className="grid grid-cols-3 gap-2 items-end">
                                <label className="space-y-0.5"><span className="text-2xs text-surface-500">Qty</span>
                                    <input type="number" inputMode="decimal" min={l.received || 0.01} step={l.type === "product" ? 1 : 0.01} value={l.quantity}
                                        onChange={(e) => update(l.key, { quantity: Number(e.target.value) })} className="input w-full" aria-label={`Quantity of ${l.description}`} /></label>
                                <label className="space-y-0.5"><span className="text-2xs text-surface-500">Unit price</span>
                                    <input type="number" inputMode="decimal" min={0} step={0.01} value={l.unit_price}
                                        onChange={(e) => update(l.key, { unit_price: Number(e.target.value) })} className="input w-full" aria-label={`Unit price of ${l.description}`} /></label>
                                <p className="text-right tabular-nums text-xs pb-2">{money(l.quantity * l.unit_price, currency)}</p>
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="rounded-lg border border-dashed border-surface-300 p-3 space-y-2">
                    <div className="flex gap-2">
                        <select value={addType} onChange={(e) => { setAddType(e.target.value as any); setResults([]); setQuery(""); }} className="input w-32" aria-label="Kind of item">
                            <option value="product">Product</option>
                            <option value="material">Material</option>
                        </select>
                        <input value={query} onChange={(e) => void search(e.target.value)} placeholder={`Add a ${addType} — type 2+ letters`} className="input flex-1" />
                    </div>
                    {searching && <Spinner size="sm" />}
                    {results.length > 0 && (
                        <ul className="max-h-40 overflow-y-auto divide-y divide-line rounded border border-line">
                            {results.map((r) => (
                                <li key={r.id}>
                                    <button type="button" onClick={() => void pick(r)} className="w-full text-left px-3 py-2 hover:bg-surface-50">
                                        {r.name}{r.sku && <span className="text-surface-400"> · {r.sku}</span>}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {variantPick && (
                        <div className="space-y-1">
                            <p className="text-xs text-surface-600">Which {variantPick.name}?</p>
                            <div className="flex flex-wrap gap-1.5">
                                {variantPick.options.map((o) => (
                                    <button key={o.id} type="button" className="btn-secondary btn-sm"
                                        onClick={() => addLine({ type: "product", item_id: variantPick.productId, product_variant_id: o.id, quantity: 1, unit_price: o.price ?? 0, description: o.label })}>
                                        {o.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <label className="space-y-1"><span className="text-xs text-surface-500">Expected delivery</span>
                        <input type="date" value={expected} onChange={(e) => setExpected(e.target.value)} className="input w-full" /></label>
                    <label className="space-y-1"><span className="text-xs text-surface-500">Shipping</span>
                        <input type="number" min={0} step={0.01} value={shipping} onChange={(e) => setShipping(Number(e.target.value))} className="input w-full" /></label>
                    <label className="space-y-1"><span className="text-xs text-surface-500">Tax</span>
                        <input type="number" min={0} step={0.01} value={tax} onChange={(e) => setTax(Number(e.target.value))} className="input w-full" /></label>
                    <label className="space-y-1"><span className="text-xs text-surface-500">Payment terms</span>
                        <input value={terms} onChange={(e) => setTerms(e.target.value)} className="input w-full" /></label>
                </div>
                <label className="block space-y-1"><span className="text-xs text-surface-500">Notes</span>
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} className="input w-full resize-none" /></label>

                <div className="flex items-center justify-between rounded-lg bg-surface-50 px-3 py-2">
                    <span className="text-surface-600">New total</span>
                    <span className="font-bold tabular-nums">
                        {money(total, currency)}
                        {Math.abs(total - num(po.total_amount)) > 0.005 && <span className="ml-2 text-2xs font-normal text-surface-500">was {money(po.total_amount, currency)}</span>}
                    </span>
                </div>

                <label className="block space-y-1">
                    <span className="text-xs font-medium text-surface-700">Why are you changing it? *</span>
                    <input value={reason} onChange={(e) => setReason(e.target.value)} className="input w-full" placeholder="e.g. Supplier confirmed 28 pieces at KES 450" />
                </label>
                {problem && reason.length > 0 && <p className="text-xs text-warning">{problem}</p>}

                <div className="flex justify-end gap-2 pt-2 border-t border-line">
                    <button onClick={onClose} className="btn-ghost" disabled={saving}>Cancel</button>
                    <button onClick={() => void save()} className="btn-primary" disabled={saving || !!problem}>
                        {saving ? <Spinner size="sm" /> : `Save as Rev ${(po.revision ?? 0) + 1}`}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Supplier's invoice ────────────────────────────────────────────────────────

export function SupplierInvoiceCard({ po, canEdit, onSaved }: { po: PurchaseOrder; canEdit: boolean; onSaved: () => void }) {
    const toast = useToastStore();
    const currency = po.currency_code ?? po.currency ?? "KES";
    const [editing, setEditing] = useState(false);
    const [number, setNumber] = useState(po.supplier_invoice_number ?? "");
    const [date, setDate] = useState((po.supplier_invoice_date ?? "").slice(0, 10));
    const [amount, setAmount] = useState(po.supplier_invoice_amount != null ? String(po.supplier_invoice_amount) : "");
    const [saving, setSaving] = useState(false);

    const has = !!po.supplier_invoice_number || po.supplier_invoice_amount != null;
    const diff = po.supplier_invoice_amount != null ? num(po.supplier_invoice_amount) - num(po.total_amount) : null;

    const save = async () => {
        setSaving(true);
        try {
            await poRevisionApi.recordSupplierInvoice(po.id, {
                supplier_invoice_number: number.trim() || null,
                supplier_invoice_date: date || null,
                supplier_invoice_amount: amount === "" ? null : Number(amount),
            });
            toast.success("Supplier invoice saved.");
            setEditing(false);
            onSaved();
        } catch (err: any) {
            toast.error(err?.message ?? "Could not save it.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-2">
            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Supplier's invoice</p>
            {editing ? (
                <div className="space-y-2">
                    <input value={number} onChange={(e) => setNumber(e.target.value)} placeholder="Invoice number" className="input w-full" />
                    <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="input w-full" aria-label="Invoice date" />
                    <input type="number" min={0} step={0.01} value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={`Amount (${currency})`} className="input w-full" />
                    <div className="flex gap-2">
                        <button onClick={() => void save()} disabled={saving} className="btn-primary btn-sm">{saving ? <Spinner size="xs" /> : "Save"}</button>
                        <button onClick={() => setEditing(false)} className="btn-ghost btn-sm">Cancel</button>
                    </div>
                </div>
            ) : has ? (
                <div className="text-xs space-y-0.5">
                    <p className="font-medium text-surface-800">{po.supplier_invoice_number ?? "—"}{po.supplier_invoice_date && <span className="text-surface-500"> · {String(po.supplier_invoice_date).slice(0, 10)}</span>}</p>
                    {po.supplier_invoice_amount != null && <p>Billed {money(po.supplier_invoice_amount, currency)}</p>}
                    {diff !== null && Math.abs(diff) >= 0.005 && (
                        <p className="text-warning font-medium">{diff > 0 ? "Over" : "Under"} the order by {money(Math.abs(diff), currency)}</p>
                    )}
                    {diff !== null && Math.abs(diff) < 0.005 && <p className="text-success-700">Matches the order total</p>}
                    {canEdit && <button onClick={() => setEditing(true)} className="text-brand-700 underline">Change</button>}
                </div>
            ) : canEdit ? (
                <button onClick={() => setEditing(true)} className="btn-secondary btn-sm">Record supplier's invoice</button>
            ) : (
                <p className="text-xs text-surface-400">Not recorded.</p>
            )}
        </div>
    );
}

// ── Revision history (PO or goods receipt) ────────────────────────────────────

export function RevisionHistory({ kind, id }: { kind: "po" | "grn"; id: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["revisions", kind, id],
        queryFn: () => (kind === "po" ? poRevisionApi.revisions(id) : poRevisionApi.grnRevisions(id)),
    });
    const [open, setOpen] = useState<number | null>(null);

    if (isLoading) return <Spinner size="sm" />;
    const rows: DocumentRevision[] = data?.revisions ?? [];
    if (rows.length === 0) return <p className="text-sm text-surface-400">No edits — this is the document as first issued.</p>;

    return (
        <ul className="space-y-3">
            {rows.map((r) => (
                <li key={r.id} className="rounded-lg border border-line p-3 text-xs space-y-1">
                    <div className="flex items-center justify-between gap-2">
                        <p className="font-semibold text-surface-800">Rev {r.revision} → Rev {r.revision + 1}</p>
                        <p className="text-surface-500">{r.by ?? "—"} · {new Date(r.created_at).toLocaleString()}</p>
                    </div>
                    <p className="text-surface-700"><span className="text-surface-500">Why:</span> {r.reason}</p>
                    <ul className="list-disc pl-4 text-surface-600">{r.changes.map((c, i) => <li key={i}>{c}</li>)}</ul>
                    <button onClick={() => setOpen(open === r.id ? null : r.id)} className="text-brand-700 underline">
                        {open === r.id ? "Hide" : "Show"} Rev {r.revision} as it was
                    </button>
                    {open === r.id && (
                        <div className="mt-1 rounded bg-surface-50 p-2">
                            {(r.snapshot.items ?? []).map((i: any) => (
                                <p key={i.id} className="tabular-nums">
                                    {i.description}: {kind === "po" ? `${Number(i.quantity)} × ${Number(i.unit_price).toFixed(2)}` : `received ${Number(i.quantity_received)}`}
                                    {kind === "po" && Number(i.quantity_received) > 0 && ` (${Number(i.quantity_received)} received)`}
                                </p>
                            ))}
                            {kind === "po" && <p className="mt-1 font-semibold">Total {Number(r.snapshot.total_amount).toFixed(2)} · {String(r.snapshot.status).replace("_", " ")}</p>}
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

// ── Correct a goods receipt (owner) ───────────────────────────────────────────

export function CorrectGoodsReceiptModal({
    grnId, grnNumber, revision, items, onClose, onSaved,
}: {
    grnId: number;
    grnNumber: string;
    revision: number;
    items: Array<{ id: number; description: string; quantity_received: number; condition: string; item_type: string }>;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const [qty, setQty] = useState<Record<number, number>>(() => Object.fromEntries(items.map((i) => [i.id, Number(i.quantity_received)])));
    const [reason, setReason] = useState("");
    const [saving, setSaving] = useState(false);

    const changed = items.filter((i) => Math.abs(qty[i.id] - Number(i.quantity_received)) > 0.001);
    const problem =
        changed.length === 0 ? "Change at least one quantity."
        : changed.find((i) => i.item_type === "product" && !Number.isInteger(qty[i.id])) ? "Products are received in whole pieces."
        : changed.find((i) => qty[i.id] < 0) ? "A quantity can't be below zero."
        : reason.trim().length < 5 ? "Say why you are correcting it (at least 5 characters)."
        : null;

    const save = async () => {
        if (problem) return toast.error(problem);
        setSaving(true);
        try {
            const res = await poRevisionApi.correctGrn(grnId, {
                reason: reason.trim(),
                lines: changed.map((i) => ({ grn_item_id: i.id, quantity_received: qty[i.id] })),
            });
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
        <Modal open onClose={onClose} title={`Correct ${grnNumber} (owner)`} size="lg">
            <div className="space-y-4 text-sm">
                <p className="rounded-lg bg-surface-50 border border-line p-3 text-xs text-surface-600">
                    Stock moves by the difference only, dated now, with your reason. Saving keeps this receipt as Revision {revision} and makes it Rev {revision + 1}.
                    Units already sold can't be taken back here — use a purchase return.
                </p>
                <ul className="divide-y divide-line rounded-lg border border-line">
                    {items.map((i) => {
                        const d = qty[i.id] - Number(i.quantity_received);
                        return (
                            <li key={i.id} className="flex items-center gap-3 p-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium text-surface-800 truncate">{i.description}</p>
                                    <p className="text-2xs text-surface-500">
                                        Received {Number(i.quantity_received)}{i.condition === "rejected" && " · rejected (no stock)"}
                                        {Math.abs(d) > 0.001 && <span className={clsx("ml-1 font-semibold", d > 0 ? "text-success-700" : "text-danger")}>{d > 0 ? `+${d}` : d} to stock</span>}
                                    </p>
                                </div>
                                <input type="number" min={0} step={i.item_type === "product" ? 1 : 0.01} value={qty[i.id]}
                                    onChange={(e) => setQty((p) => ({ ...p, [i.id]: Number(e.target.value) }))}
                                    className="input w-24" aria-label={`Corrected quantity of ${i.description}`} />
                            </li>
                        );
                    })}
                </ul>
                <label className="block space-y-1">
                    <span className="text-xs font-medium text-surface-700">Why are you correcting it? *</span>
                    <input value={reason} onChange={(e) => setReason(e.target.value)} className="input w-full" placeholder="e.g. Recount: 2 boxes were counted twice" />
                </label>
                {problem && reason.length > 0 && <p className="text-xs text-warning">{problem}</p>}
                <div className="flex justify-end gap-2 pt-2 border-t border-line">
                    <button onClick={onClose} className="btn-ghost" disabled={saving}>Cancel</button>
                    <button onClick={() => void save()} className="btn-primary" disabled={saving || !!problem}>
                        {saving ? <Spinner size="sm" /> : `Save as Rev ${revision + 1}`}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
