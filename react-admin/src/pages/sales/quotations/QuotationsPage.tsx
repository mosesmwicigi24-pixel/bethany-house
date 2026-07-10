import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { get } from "@/api/client";
import {
    quotationApi,
    type Quotation,
    type QuotationStatus,
    type QuotationItemInput,
} from "@/api/quotations";
import { Field, FieldInput, FieldTextarea } from "@/components/setup/FormComponents";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

const num = (v: unknown): number => Number(v ?? 0) || 0;
const money = (v: unknown, currency = "KES"): string =>
    `${currency} ${num(v).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const STATUS: Record<QuotationStatus, { label: string; badge: string }> = {
    draft:     { label: "Draft",     badge: "badge-neutral" },
    sent:      { label: "Issued",    badge: "badge-info" },
    accepted:  { label: "Accepted",  badge: "badge-success" },
    converted: { label: "Converted", badge: "badge-success" },
    declined:  { label: "Declined",  badge: "badge-danger" },
    expired:   { label: "Expired",   badge: "badge-warning" },
};

function StatusBadge({ status }: { status: QuotationStatus }) {
    const s = STATUS[status] ?? { label: status, badge: "badge-neutral" };
    return <span className={`badge ${s.badge}`}>{s.label}</span>;
}

function customerName(q: Quotation): string {
    const name = `${q.customer_first_name ?? ""} ${q.customer_last_name ?? ""}`.trim();
    return name || q.customer_email || q.customer_phone || "—";
}

export default function QuotationsPage() {
    const { can } = usePermissions();
    const toast = useToastStore();
    const qc = useQueryClient();

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [builder, setBuilder] = useState<{ open: boolean; editing: Quotation | null }>({ open: false, editing: null });

    const params = useMemo(() => ({
        page,
        per_page: 20,
        ...(search ? { search } : {}),
        ...(statusFilter ? { status: statusFilter } : {}),
    }), [page, search, statusFilter]);

    const { data, isLoading } = useQuery({
        queryKey: ["quotations", params],
        queryFn: () => quotationApi.list(params),
    });

    const invalidate = () => qc.invalidateQueries({ queryKey: ["quotations"] });

    const issueMutation = useMutation({
        mutationFn: (id: number) => quotationApi.issue(id),
        onSuccess: (res) => { invalidate(); toast.success(`Quotation issued (${res.quotation.quote_number})`); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const acceptMutation = useMutation({
        mutationFn: (id: number) => quotationApi.accept(id),
        onSuccess: (res) => { invalidate(); toast.success(`Invoice ${res.invoice.number} created from quotation.`); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => quotationApi.delete(id),
        onSuccess: () => { invalidate(); toast.success("Quotation deleted."); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const rows = data?.data ?? [];

    return (
        <div className="space-y-5">
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h1 className="page-title">Quotations</h1>
                    <p className="page-subtitle">Prepare priced offers, issue them, and convert to invoices.</p>
                </div>
                {can("quotations.create") && (
                    <button className="btn-primary" onClick={() => setBuilder({ open: true, editing: null })}>
                        New Quotation
                    </button>
                )}
            </div>

            <div className="flex flex-wrap gap-3">
                <input
                    className="input flex-1 min-w-[200px]"
                    placeholder="Search number, customer…"
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                />
                <select
                    className="input w-44"
                    value={statusFilter}
                    onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
                >
                    <option value="">All statuses</option>
                    {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                </select>
            </div>

            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                ) : rows.length === 0 ? (
                    <div className="py-16 text-center text-sm text-muted">No quotations yet.</div>
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th className="text-right">Total</th>
                                <th>Valid Until</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((q) => {
                                const issued = q.status !== "draft";
                                return (
                                    <tr key={q.id}>
                                        <td className="font-medium tabular-nums">{q.quote_number ?? "— (draft)"}</td>
                                        <td>{customerName(q)}</td>
                                        <td><StatusBadge status={q.status} /></td>
                                        <td className="text-right tabular-nums">{money(q.total_amount, q.currency_code)}</td>
                                        <td>{q.valid_until ?? "—"}</td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                {q.status === "draft" && can("quotations.create") && (
                                                    <button className="btn-ghost btn-sm" onClick={() => setBuilder({ open: true, editing: q })}>
                                                        Edit
                                                    </button>
                                                )}
                                                {q.status === "draft" && can("quotations.issue") && (
                                                    <button
                                                        className="btn-secondary btn-sm"
                                                        disabled={issueMutation.isPending}
                                                        onClick={() => issueMutation.mutate(q.id)}
                                                    >
                                                        Issue
                                                    </button>
                                                )}
                                                {q.status === "sent" && can("quotations.issue") && (
                                                    <button
                                                        className="btn-primary btn-sm"
                                                        disabled={acceptMutation.isPending}
                                                        onClick={() => {
                                                            if (confirm("Accept this quotation and create an invoice? This reserves stock.")) {
                                                                acceptMutation.mutate(q.id);
                                                            }
                                                        }}
                                                    >
                                                        Accept → Invoice
                                                    </button>
                                                )}
                                                {issued && <PdfDownloadButton type="quotations" id={q.id} label="PDF" />}
                                                {q.status === "draft" && can("quotations.delete") && (
                                                    <button
                                                        className="btn-ghost btn-sm text-danger"
                                                        disabled={deleteMutation.isPending}
                                                        onClick={() => { if (confirm("Delete this draft quotation?")) deleteMutation.mutate(q.id); }}
                                                    >
                                                        Delete
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted">
                        {data.from ?? 0}–{data.to ?? 0} of {data.total}
                    </span>
                    <div className="flex gap-2">
                        <button className="btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</button>
                        <button className="btn-secondary btn-sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
                    </div>
                </div>
            )}

            {builder.open && (
                <QuotationBuilder
                    editing={builder.editing}
                    onClose={() => setBuilder({ open: false, editing: null })}
                    onSaved={() => { invalidate(); setBuilder({ open: false, editing: null }); }}
                />
            )}
        </div>
    );
}

// ── Builder ─────────────────────────────────────────────────────────────────

interface LineRow {
    key: string;
    product_id: number | null;
    product_name: string;
    sku: string;
    quantity: string;
    unit_price: string;
}

interface ProductHit { id: number; name: string; sku: string }

function newKey() { return Math.random().toString(36).slice(2); }

function QuotationBuilder({ editing, onClose, onSaved }: { editing: Quotation | null; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const [firstName, setFirstName] = useState(editing?.customer_first_name ?? "");
    const [lastName, setLastName] = useState(editing?.customer_last_name ?? "");
    const [email, setEmail] = useState(editing?.customer_email ?? "");
    const [phone, setPhone] = useState(editing?.customer_phone ?? "");
    const [validUntil, setValidUntil] = useState(editing?.valid_until ?? "");
    const [notes, setNotes] = useState(editing?.notes ?? "");
    const [rows, setRows] = useState<LineRow[]>(
        (editing?.items ?? []).map((i) => ({
            key: newKey(),
            product_id: i.product_id,
            product_name: i.product_name,
            sku: i.sku ?? "",
            quantity: String(i.quantity),
            unit_price: String(i.unit_price),
        })),
    );
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    const [productQuery, setProductQuery] = useState("");
    const [hits, setHits] = useState<ProductHit[]>([]);
    const [searching, setSearching] = useState(false);

    async function searchProducts(q: string) {
        setProductQuery(q);
        if (q.trim().length < 2) { setHits([]); return; }
        setSearching(true);
        try {
            const res = await get<{ data?: unknown[] }>("/v1/admin/products", { params: { search: q, per_page: 15 } });
            const list = (res.data ?? []) as Array<Record<string, unknown>>;
            setHits(list.map((p) => ({
                id: Number(p.id),
                name: String(
                    (p.en_translation as { name?: string } | undefined)?.name
                    ?? p.name ?? p.product_name ?? `Product #${p.id}`,
                ),
                sku: String(p.sku ?? ""),
            })));
        } catch {
            setHits([]);
        } finally {
            setSearching(false);
        }
    }

    function addProduct(hit: ProductHit) {
        setRows((r) => [...r, { key: newKey(), product_id: hit.id, product_name: hit.name, sku: hit.sku, quantity: "1", unit_price: "0" }]);
        setProductQuery(""); setHits([]);
    }
    function addAdHoc() {
        setRows((r) => [...r, { key: newKey(), product_id: null, product_name: "", sku: "", quantity: "1", unit_price: "0" }]);
    }
    function updateRow(key: string, field: keyof LineRow, value: string) {
        setRows((r) => r.map((row) => row.key === key ? { ...row, [field]: value } : row));
    }
    function removeRow(key: string) {
        setRows((r) => r.filter((row) => row.key !== key));
    }

    const subtotal = rows.reduce((sum, r) => sum + num(r.quantity) * num(r.unit_price), 0);

    const save = useMutation({
        mutationFn: () => {
            const items: QuotationItemInput[] = rows.map((r) => ({
                product_id: r.product_id,
                product_name: r.product_name.trim() || "Item",
                sku: r.sku || null,
                quantity: Math.max(1, Math.round(num(r.quantity))),
                unit_price: num(r.unit_price),
            }));
            const payload = {
                customer_first_name: firstName || null,
                customer_last_name: lastName || null,
                customer_email: email || null,
                customer_phone: phone || null,
                valid_until: validUntil || null,
                notes: notes || null,
                items,
            };
            return editing ? quotationApi.update(editing.id, payload) : quotationApi.create(payload);
        },
        onSuccess: () => { toast.success(editing ? "Quotation updated." : "Quotation created."); onSaved(); },
        onError: (e: ApiError) => { setErrors(e.errors ?? {}); toast.error(e.message); },
    });

    const canSave = rows.length > 0 && !save.isPending;

    return (
        <Modal
            open
            onClose={onClose}
            size="full"
            title={editing ? `Edit Quotation ${editing.quote_number ?? "(draft)"}` : "New Quotation"}
            footer={
                <div className="flex justify-end gap-2">
                    <button className="btn-secondary" onClick={onClose}>Cancel</button>
                    <button className="btn-primary" disabled={!canSave} onClick={() => save.mutate()}>
                        {save.isPending ? <Spinner size="sm" /> : editing ? "Save changes" : "Create quotation"}
                    </button>
                </div>
            }
        >
            <div className="space-y-5">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="Customer first name" error={errors.customer_first_name?.[0]}>
                        <FieldInput value={firstName} onChange={(e) => setFirstName(e.target.value)} />
                    </Field>
                    <Field label="Customer last name" error={errors.customer_last_name?.[0]}>
                        <FieldInput value={lastName} onChange={(e) => setLastName(e.target.value)} />
                    </Field>
                    <Field label="Email" error={errors.customer_email?.[0]}>
                        <FieldInput type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                    </Field>
                    <Field label="Phone" error={errors.customer_phone?.[0]}>
                        <FieldInput value={phone} onChange={(e) => setPhone(e.target.value)} />
                    </Field>
                    <Field label="Valid until" error={errors.valid_until?.[0]}>
                        <FieldInput type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
                    </Field>
                </div>

                {/* Product picker */}
                <div className="relative">
                    <input
                        className="input"
                        placeholder="Search a product to add…"
                        value={productQuery}
                        onChange={(e) => searchProducts(e.target.value)}
                    />
                    {(searching || hits.length > 0) && (
                        <div className="absolute z-10 mt-1 w-full rounded-md border bg-surface shadow-lg">
                            {searching && <div className="px-3 py-2 text-sm text-muted">Searching…</div>}
                            {hits.map((h) => (
                                <button
                                    key={h.id}
                                    type="button"
                                    className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted/40"
                                    onClick={() => addProduct(h)}
                                >
                                    <span>{h.name}</span>
                                    <span className="text-xs text-muted">{h.sku}</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Line items */}
                <div className="card overflow-hidden">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th className="w-24 text-right">Qty</th>
                                <th className="w-36 text-right">Unit Price</th>
                                <th className="w-36 text-right">Line Total</th>
                                <th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan={5} className="py-6 text-center text-sm text-muted">Add a product or an ad-hoc line.</td></tr>
                            ) : rows.map((r) => (
                                <tr key={r.key}>
                                    <td>
                                        <input
                                            className="input"
                                            value={r.product_name}
                                            placeholder="Description"
                                            onChange={(e) => updateRow(r.key, "product_name", e.target.value)}
                                        />
                                    </td>
                                    <td>
                                        <input className="input text-right" type="number" min="1" value={r.quantity}
                                            onChange={(e) => updateRow(r.key, "quantity", e.target.value)} />
                                    </td>
                                    <td>
                                        <input className="input text-right" type="number" min="0" step="0.01" value={r.unit_price}
                                            onChange={(e) => updateRow(r.key, "unit_price", e.target.value)} />
                                    </td>
                                    <td className="text-right tabular-nums">{money(num(r.quantity) * num(r.unit_price))}</td>
                                    <td>
                                        <button type="button" className="btn-ghost btn-sm text-danger" onClick={() => removeRow(r.key)}>✕</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="flex items-center justify-between border-t px-4 py-2">
                        <button type="button" className="btn-ghost btn-sm" onClick={addAdHoc}>+ Add ad-hoc line</button>
                        <div className="text-sm">
                            <span className="text-muted">Subtotal (excl. tax): </span>
                            <span className="font-semibold tabular-nums">{money(subtotal)}</span>
                        </div>
                    </div>
                </div>

                <Field label="Notes" error={errors.notes?.[0]}>
                    <FieldTextarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
                </Field>

                <p className="text-xs text-muted">
                    Tax is applied automatically on save based on each product's rate. Once issued, a quotation is
                    locked — edits require a new quotation.
                </p>
            </div>
        </Modal>
    );
}
