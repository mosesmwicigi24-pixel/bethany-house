import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { get } from "@/api/client";
import {
    quotationApi,
    type Quotation,
    type QuotationStatus,
    type QuotationItemInput,
} from "@/api/quotations";
import { Field, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import { useAuthStore } from "@/store/auth.store";
import type { ApiError } from "@/types";

interface CurrencyOption { code: string; name: string; symbol: string }

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

    // In-app confirmations (native confirm() is unreliable — browsers suppress it
    // after "prevent additional dialogs", which makes the button look dead).
    const [confirmAccept, setConfirmAccept] = useState<Quotation | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<Quotation | null>(null);

    const acceptMutation = useMutation({
        mutationFn: (id: number) => quotationApi.accept(id),
        onSuccess: (res) => { invalidate(); toast.success(`Invoice ${res.invoice?.number ?? ""} created from quotation.`); },
        onError: (e: ApiError) => toast.error(e.message),
        onSettled: () => setConfirmAccept(null),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => quotationApi.delete(id),
        onSuccess: () => { invalidate(); toast.success("Quotation deleted."); },
        onError: (e: ApiError) => toast.error(e.message),
        onSettled: () => setConfirmDelete(null),
    });

    const copyLink = async (q: Quotation) => {
        if (!q.quote_token) { toast.error("Issue the quotation first to get a shareable link."); return; }
        const url = `${window.location.origin}/quote/${q.quote_token}`;
        try {
            await navigator.clipboard.writeText(url);
            toast.success("Customer link copied to clipboard.");
        } catch {
            window.prompt("Copy this customer link:", url);
        }
    };

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
                    <div className="table-wrapper rounded-none border-0">
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
                                                        onClick={() => setConfirmAccept(q)}
                                                    >
                                                        Accept → Invoice
                                                    </button>
                                                )}
                                                {issued && q.quote_token && (
                                                    <button className="btn-ghost btn-sm" onClick={() => copyLink(q)} title="Copy the customer's quote link">
                                                        Copy link
                                                    </button>
                                                )}
                                                {issued && <PdfDownloadButton type="quotations" id={q.id} label="Quote PDF" />}
                                                {q.status === "converted" && q.invoice_document && (
                                                    <PdfDownloadButton type="orders" id={q.invoice_document.documentable_id} subtype="invoice" label="Invoice PDF" />
                                                )}
                                                {q.status === "draft" && can("quotations.delete") && (
                                                    <button
                                                        className="btn-ghost btn-sm text-danger"
                                                        disabled={deleteMutation.isPending}
                                                        onClick={() => setConfirmDelete(q)}
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
                    </div>
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

            {confirmAccept && (
                <Modal
                    open
                    onClose={() => setConfirmAccept(null)}
                    size="sm"
                    title="Accept quotation"
                    footer={
                        <div className="flex justify-end gap-2">
                            <button className="btn-secondary" onClick={() => setConfirmAccept(null)}>Cancel</button>
                            <button className="btn-primary" disabled={acceptMutation.isPending} onClick={() => acceptMutation.mutate(confirmAccept.id)}>
                                {acceptMutation.isPending ? <Spinner size="sm" /> : "Accept & create invoice"}
                            </button>
                        </div>
                    }
                >
                    <p className="text-sm text-muted">
                        Accept <span className="font-medium">{confirmAccept.quote_number}</span> and create an invoice?
                        This confirms the order and reserves stock.
                    </p>
                </Modal>
            )}

            {confirmDelete && (
                <Modal
                    open
                    onClose={() => setConfirmDelete(null)}
                    size="sm"
                    title="Delete quotation"
                    footer={
                        <div className="flex justify-end gap-2">
                            <button className="btn-secondary" onClick={() => setConfirmDelete(null)}>Cancel</button>
                            <button className="btn-primary" disabled={deleteMutation.isPending} onClick={() => deleteMutation.mutate(confirmDelete.id)}>
                                {deleteMutation.isPending ? <Spinner size="sm" /> : "Delete"}
                            </button>
                        </div>
                    }
                >
                    <p className="text-sm text-muted">Delete this draft quotation? This can't be undone.</p>
                </Modal>
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

interface ProductHit { id: number; name: string; sku: string; price: number }

function newKey() { return Math.random().toString(36).slice(2); }

function QuotationBuilder({ editing, onClose, onSaved }: { editing: Quotation | null; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const currentUserName = useAuthStore((s) => s.user?.name ?? "");
    const [firstName, setFirstName] = useState(editing?.customer_first_name ?? "");
    const [lastName, setLastName] = useState(editing?.customer_last_name ?? "");
    const [email, setEmail] = useState(editing?.customer_email ?? "");
    const [phone, setPhone] = useState(editing?.customer_phone ?? "");
    const [validUntil, setValidUntil] = useState(editing?.valid_until ?? "");
    const [currency, setCurrency] = useState(editing?.currency_code ?? "KES");
    const [shipping, setShipping] = useState(String(editing?.shipping_amount ?? 0));
    const [servedBy, setServedBy] = useState(editing?.served_by ?? currentUserName);
    const [notes, setNotes] = useState(editing?.notes ?? "");

    // Active currencies configured in the DB (public endpoint — the currencies
    // table, same source the admin Currencies page manages). Distinct query key
    // so it never collides with the admin page's ["currencies"] cache. Falls back
    // to the current/KES only if the fetch fails.
    const { data: currencyData } = useQuery({
        queryKey: ["public-currencies"],
        queryFn: () => get<{ data: CurrencyOption[] }>("/v1/settings/currencies"),
        staleTime: 5 * 60 * 1000,
    });
    const fetched = currencyData?.data ?? [];
    const currencies: CurrencyOption[] = fetched.length
        ? fetched
        : [{ code: currency, name: currency, symbol: currency }];
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
    // Per-row product search — each line's description doubles as a catalogue picker.
    const [activeRow, setActiveRow] = useState<string | null>(null);
    const [rowHits, setRowHits] = useState<ProductHit[]>([]);
    const [rowSearching, setRowSearching] = useState(false);

    async function fetchProducts(q: string): Promise<ProductHit[]> {
        const res = await get<{ data?: unknown[] }>("/v1/admin/products", { params: { search: q, per_page: 15 } });
        const list = (res.data ?? []) as Array<Record<string, unknown>>;
        return list.map((p) => {
            const bp = p.base_price as { regular_price?: number | string; sale_price?: number | string } | undefined;
            return {
                id: Number(p.id),
                name: String(
                    (p.en_translation as { name?: string } | undefined)?.name
                    ?? p.name ?? p.product_name ?? `Product #${p.id}`,
                ),
                sku: String(p.sku ?? ""),
                // Prefer an active sale price, else the list price.
                price: num(bp?.sale_price ?? bp?.regular_price ?? 0),
            };
        });
    }

    async function searchProducts(q: string) {
        setProductQuery(q);
        if (q.trim().length < 2) { setHits([]); return; }
        setSearching(true);
        try { setHits(await fetchProducts(q)); } catch { setHits([]); } finally { setSearching(false); }
    }

    // Search the catalogue from a specific line's description field.
    async function searchRow(key: string, q: string) {
        setActiveRow(key);
        if (q.trim().length < 2) { setRowHits([]); return; }
        setRowSearching(true);
        try { setRowHits(await fetchProducts(q)); } catch { setRowHits([]); } finally { setRowSearching(false); }
    }

    // Pick a catalogue product into a line — fills product, sku, and price.
    function pickForRow(key: string, hit: ProductHit) {
        setRows((r) => r.map((row) => row.key === key
            ? { ...row, product_id: hit.id, product_name: hit.name, sku: hit.sku, unit_price: String(hit.price || num(row.unit_price) || 0) }
            : row));
        setActiveRow(null); setRowHits([]);
    }

    function addProduct(hit: ProductHit) {
        setRows((r) => [...r, { key: newKey(), product_id: hit.id, product_name: hit.name, sku: hit.sku, quantity: "1", unit_price: String(hit.price || 0) }]);
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
                currency_code: currency,
                shipping_amount: num(shipping),
                served_by: servedBy || null,
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
                    <Field label="Currency" error={errors.currency_code?.[0]}>
                        <FieldSelect value={currency} onChange={(e) => setCurrency(e.target.value)}>
                            {currencies.map((c) => (
                                <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field label="Served by" error={errors.served_by?.[0]}>
                        <FieldInput value={servedBy} onChange={(e) => setServedBy(e.target.value)} placeholder="Staff member" />
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
                    <div className="table-wrapper rounded-none border-0">
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
                                <tr><td colSpan={5} className="py-6 text-center text-sm text-muted">Add a line, then search a product or type a description.</td></tr>
                            ) : rows.map((r) => (
                                <tr key={r.key}>
                                    <td className="relative">
                                        <input
                                            className="input"
                                            value={r.product_name}
                                            placeholder="Search a product, or type a description"
                                            onChange={(e) => { updateRow(r.key, "product_name", e.target.value); void searchRow(r.key, e.target.value); }}
                                            onFocus={() => { if (r.product_name.trim().length >= 2) void searchRow(r.key, r.product_name); }}
                                            onBlur={() => setTimeout(() => setActiveRow((k) => (k === r.key ? null : k)), 150)}
                                        />
                                        {activeRow === r.key && (rowSearching || rowHits.length > 0) && (
                                            <div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-surface shadow-lg">
                                                {rowSearching && <div className="px-3 py-2 text-xs text-muted">Searching…</div>}
                                                {rowHits.map((h) => (
                                                    <button
                                                        key={h.id}
                                                        type="button"
                                                        className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted/40"
                                                        onMouseDown={(e) => { e.preventDefault(); pickForRow(r.key, h); }}
                                                    >
                                                        <span>{h.name}</span>
                                                        <span className="text-xs text-muted">{h.sku}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td>
                                        <input className="input text-right" type="number" min="1" value={r.quantity}
                                            onChange={(e) => updateRow(r.key, "quantity", e.target.value)} />
                                    </td>
                                    <td>
                                        <input className="input text-right" type="number" min="0" step="0.01" value={r.unit_price}
                                            onChange={(e) => updateRow(r.key, "unit_price", e.target.value)} />
                                    </td>
                                    <td className="text-right tabular-nums">{money(num(r.quantity) * num(r.unit_price), currency)}</td>
                                    <td>
                                        <button type="button" className="btn-ghost btn-sm text-danger" onClick={() => removeRow(r.key)}>✕</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                    <div className="space-y-2 border-t px-4 py-3">
                        <div className="flex items-center justify-between">
                            <button type="button" className="btn-ghost btn-sm" onClick={addAdHoc}>+ Add line</button>
                            <div className="text-sm">
                                <span className="text-muted">Subtotal (excl. tax): </span>
                                <span className="font-semibold tabular-nums">{money(subtotal, currency)}</span>
                            </div>
                        </div>
                        <div className="flex items-center justify-end gap-3 text-sm">
                            <label className="text-muted">Shipping</label>
                            <input
                                className="input w-32 text-right"
                                type="number" min="0" step="0.01"
                                value={shipping}
                                onChange={(e) => setShipping(e.target.value)}
                            />
                        </div>
                        <div className="flex items-center justify-end gap-2 border-t pt-2 text-base font-bold">
                            <span>Total (excl. tax)</span>
                            <span className="tabular-nums">{money(subtotal + num(shipping), currency)}</span>
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
