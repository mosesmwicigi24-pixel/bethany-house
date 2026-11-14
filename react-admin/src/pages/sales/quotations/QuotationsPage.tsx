import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { get, post } from "@/api/client";
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
import { discountCapMessage, minUnitPrice, useDiscountCap } from "@/lib/discountCap";
import type { ApiError } from "@/types";
import { salesRevisionApi } from "@/api/salesRevisions";
import { RevisionRows } from "@/components/documents/SalesRevisions";
import { whole } from "@/lib/customerMoney";

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
    void:      { label: "Void",      badge: "badge-danger" },
};

/** Who voided it, when and why — on hover over the Void badge. */
function voidNote(q: Quotation): string | undefined {
    if (q.status !== "void") return undefined;
    const who = `${q.voider?.first_name ?? ""} ${q.voider?.last_name ?? ""}`.trim();
    const when = q.voided_at ? new Date(q.voided_at).toLocaleDateString("en-KE", { day: "numeric", month: "short", year: "numeric" }) : "";
    return [`Voided with its invoice${who ? ` by ${who}` : ""}${when ? ` on ${when}` : ""}.`, q.void_reason ? `Reason: ${q.void_reason}` : ""]
        .filter(Boolean).join(" ");
}

function StatusBadge({ status, title }: { status: QuotationStatus; title?: string }) {
    const s = STATUS[status] ?? { label: status, badge: "badge-neutral" };
    return <span className={`badge ${s.badge}`} title={title}>{s.label}</span>;
}

function customerName(q: Quotation): string {
    const name = `${q.customer_first_name ?? ""} ${q.customer_last_name ?? ""}`.trim();
    return name || q.customer_email || q.customer_phone || "—";
}

export default function QuotationsPage() {
    const { can, isSuperAdmin } = usePermissions();
    const toast = useToastStore();
    const qc = useQueryClient();

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [builder, setBuilder] = useState<{ open: boolean; editing: Quotation | null; revise?: boolean }>({ open: false, editing: null });

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
                    <div className="py-16 text-center text-sm text-surface-500">No quotations yet.</div>
                ) : (
                    <div className="table-wrapper rounded-none border-0">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th className="text-right">Total</th>
                                <th>Served by</th>
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
                                        <td>
                                            <StatusBadge status={q.status} title={voidNote(q)} />
                                            {q.status === "void" && q.void_reason && (
                                                <div className="mt-0.5 max-w-[220px] truncate text-2xs text-surface-500" title={voidNote(q)}>{q.void_reason}</div>
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">{money(q.total_amount, q.currency_code)}</td>
                                        <td className="text-sm">{q.served_by ?? "—"}</td>
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
                                                {issued && q.quote_token && q.status !== "void" && (
                                                    <button className="btn-ghost btn-sm" onClick={() => copyLink(q)} title="Copy the customer's quote link">
                                                        Copy link
                                                    </button>
                                                )}
                                                {issued && <PdfDownloadButton type="quotations" id={q.id} label="Quote PDF" />}
                                                {isSuperAdmin && ["sent", "accepted", "declined", "expired"].includes(q.status) && (
                                                    <button className="btn-ghost btn-sm" onClick={() => setBuilder({ open: true, editing: q, revise: true })}
                                                        title="Change the issued quotation; the issued version is kept">
                                                        Revise (owner)
                                                    </button>
                                                )}
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
                    <span className="text-surface-500">
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
                    revise={builder.revise}
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
                    <p className="text-sm text-surface-500">
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
                    <p className="text-sm text-surface-500">Delete this draft quotation? This can't be undone.</p>
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
    /** The catalogue's price for this line in the quotation's currency, when known —
     *  what a typed price is measured against (the person's discount limit). */
    catalogue_price?: number | null;
}

interface ProductHit { id: number; name: string; sku: string; price: number }

function newKey() { return Math.random().toString(36).slice(2); }

/** Catalogue search. `price` is the KES base price — never shown or used as a line price
 *  directly; a line is priced in the quotation's currency by the server. */
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


function QuotationBuilder({ editing, revise, onClose, onSaved }: { editing: Quotation | null; revise?: boolean; onClose: () => void; onSaved: () => void }) {
    // Owner revision of an issued quotation (2026-10-16): same form, plus a reason.
    const [reviseReason, setReviseReason] = useState("");
    const { data: revisionsData } = useQuery({
        queryKey: ["quotation-revisions", editing?.id],
        queryFn: () => salesRevisionApi.quotationRevisions(editing!.id),
        enabled: !!revise && !!editing,
    });
    const toast = useToastStore();
    const currentUserName = useAuthStore((s) => s.user?.name ?? "");
    const [firstName, setFirstName] = useState(editing?.customer_first_name ?? "");
    const [lastName, setLastName] = useState(editing?.customer_last_name ?? "");
    const [email, setEmail] = useState(editing?.customer_email ?? "");
    const [phone, setPhone] = useState(editing?.customer_phone ?? "");
    const [validUntil, setValidUntil] = useState(editing?.valid_until ?? "");
    const [currency, setCurrency] = useState(editing?.currency_code ?? "KES");
    // Currency used to relabel a quotation without moving a figure — pick ZMW
    // and KES 17,000 read as "ZMW 17,000", seven times the real price. Changing
    // it now re-prices every line on the server (the catalogue's own price for
    // that currency, else the configured rate), and says so.
    const [repricing, setRepricing] = useState(false);
    const [keptKeys, setKeptKeys] = useState<string[]>([]);
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

    // Per-row product search — each line's description doubles as a catalogue picker.
    const [activeRow, setActiveRow] = useState<string | null>(null);
    const [rowHits, setRowHits] = useState<ProductHit[]>([]);
    const [rowSearching, setRowSearching] = useState(false);

    // Search the catalogue from a specific line's description field.
    async function searchRow(key: string, q: string) {
        setActiveRow(key);
        if (q.trim().length < 2) { setRowHits([]); return; }
        setRowSearching(true);
        try { setRowHits(await fetchProducts(q)); } catch { setRowHits([]); } finally { setRowSearching(false); }
    }

    // A product enters a line at its price IN THE QUOTATION'S CURRENCY (owner,
    // 2026-11-14). The search returns the KES base price; picking a product used
    // to drop that figure straight into the line, so a USD quotation showed
    // "Golden Communion Tray · USD 22,000" — the shilling price under a dollar
    // label. The line now waits for the server's answer (the same
    // /quotations/reprice the currency switch uses: the product's own price in
    // that currency, else its KES price at the configured rate) and is flagged
    // when there is none, exactly as a currency change does.
    const [pricingKeys, setPricingKeys] = useState<string[]>([]);
    async function priceNewLines(lines: LineRow[], kesPrices: Map<string, number>) {
        if (lines.length === 0) return;
        const keys = lines.map((l) => l.key);
        setPricingKeys((k) => [...k, ...keys]);
        setRepricing(true);
        try {
            const res = await post<{ lines: { key: string; unit_price: number; source: "catalogue" | "converted" | "kept" }[] }>(
                "/v1/admin/quotations/reprice",
                { from: "KES", to: currency, lines: lines.map((l) => ({ key: l.key, product_id: l.product_id, unit_price: kesPrices.get(l.key) ?? 0, name: l.product_name || "This line" })) },
            );
            const priced = new Map(res.lines.map((l) => [l.key, l]));
            setRows((rs) => rs.map((r) => {
                const hit = priced.get(r.key);
                if (!hit) return r;
                return { ...r, unit_price: String(hit.unit_price), catalogue_price: hit.source !== "kept" ? hit.unit_price : null };
            }));
            const kept = res.lines.filter((l) => l.source === "kept").map((l) => l.key);
            if (kept.length) {
                setKeptKeys((k) => [...k, ...kept]);
                toast.warning(`${kept.length} product${kept.length === 1 ? " has" : "s have"} no ${currency} price on the hub — type the price before sending.`);
            }
        } catch (e) {
            // Never leave a figure we could not price under this currency's label.
            setRows((rs) => rs.map((r) => (keys.includes(r.key) ? { ...r, unit_price: "0", catalogue_price: null } : r)));
            setKeptKeys((k) => [...k, ...keys]);
            toast.error((e as ApiError)?.message ?? `Could not price ${lines.length === 1 ? "that product" : "those products"} in ${currency}. Type the price.`);
        } finally {
            setPricingKeys((k) => k.filter((x) => !keys.includes(x)));
            setRepricing(false);
        }
    }

    // Pick a catalogue product into a line — fills product and sku, then the price in this currency.
    function pickForRow(key: string, hit: ProductHit) {
        setRows((r) => r.map((row) => row.key === key
            ? { ...row, product_id: hit.id, product_name: hit.name, sku: hit.sku, unit_price: "", catalogue_price: null }
            : row));
        setKeptKeys((k) => k.filter((x) => x !== key));
        setActiveRow(null); setRowHits([]);
        void priceNewLines([{ key, product_id: hit.id, product_name: hit.name, sku: hit.sku, quantity: "1", unit_price: "" }], new Map([[key, hit.price]]));
    }

    // Add several catalogue products in one go (the picker's ticked rows).
    function addProducts(picked: ProductHit[]) {
        const fresh = picked.map((h) => ({ key: newKey(), product_id: h.id, product_name: h.name, sku: h.sku, quantity: "1", unit_price: "", catalogue_price: null } as LineRow));
        setRows((r) => [...r, ...fresh]);
        void priceNewLines(fresh, new Map(fresh.map((l, i) => [l.key, picked[i].price])));
    }
    // Changing the currency re-prices the lines. Prices are the server's: the
    // shop's own price for that currency when it has one, otherwise converted at
    // the configured rate. Anything it cannot price is left exactly as typed and
    // flagged — an operator fixing one figure is recoverable, a silent guess is
    // not. The operator can still overwrite any line afterwards.
    async function changeCurrency(next: string) {
        const previous = currency;
        setCurrency(next);
        if (next === previous || rows.length === 0) { setKeptKeys([]); return; }

        setRepricing(true);
        try {
            const res = await post<{
                lines: { key: string; unit_price: number; source: "catalogue" | "converted" | "kept" }[];
                summary: { catalogue: number; converted: number; kept: number };
                warnings: string[];
            }>("/v1/admin/quotations/reprice", {
                from: previous,
                to: next,
                lines: rows.map((r) => ({
                    key: r.key,
                    product_id: r.product_id,
                    unit_price: num(r.unit_price),
                    name: r.product_name || "This line",
                })),
            });

            const priced = new Map(res.lines.map((l) => [l.key, l]));
            setRows((rs) => rs.map((r) => {
                const hit = priced.get(r.key);
                if (!hit) return r;
                // A catalogue line priced by the server is the catalogue price
                // in the new currency; anything else has none we know.
                const catalogue = r.product_id && hit.source !== "kept" ? hit.unit_price : null;
                return { ...r, unit_price: String(hit.unit_price), catalogue_price: catalogue };
            }));
            setKeptKeys(res.lines.filter((l) => l.source === "kept").map((l) => l.key));

            const { catalogue, converted, kept } = res.summary;
            const parts = [
                catalogue ? `${catalogue} from the ${next} price list` : null,
                converted ? `${converted} converted` : null,
                kept ? `${kept} left as typed` : null,
            ].filter(Boolean).join(", ");
            if (kept) toast.warning(`Priced in ${next}: ${parts}. ${res.warnings[0] ?? ""}`.trim());
            else toast.success(`Priced in ${next}: ${parts}.`);
        } catch (e) {
            // Never leave figures from the old currency under a new label.
            setCurrency(previous);
            toast.error((e as ApiError)?.message ?? `Could not price this quotation in ${next}. The currency is unchanged.`);
        } finally {
            setRepricing(false);
        }
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

    // Quoted in whole units (lib/customerMoney), as the server stores them.
    const subtotal = rows.reduce((sum, r) => sum + num(r.quantity) * whole(num(r.unit_price)), 0);

    // The person's discount limit: a catalogue line may be quoted at most that far under its
    // catalogue price (no ceiling for the super_admin). The server enforces it;
    // this says so beside the price and stops the save before a round trip.
    const discountCap = useDiscountCap();
    const belowCatalogue = (r: LineRow) =>
        discountCap !== null && !!r.product_id && !!r.catalogue_price
        && num(r.unit_price) < minUnitPrice(r.catalogue_price, discountCap);
    const priceError = (r: LineRow, idx: number): string | null =>
        belowCatalogue(r)
            ? `${discountCapMessage(discountCap)} Lowest: ${money(minUnitPrice(r.catalogue_price ?? 0, discountCap), currency)}.`
            : (errors[`items.${idx}.unit_price`]?.[0] ?? errors[`items.${idx}.discount_amount`]?.[0] ?? null);

    const save = useMutation({
        mutationFn: () => {
            if (rows.some(belowCatalogue)) {
                return Promise.reject({ message: discountCapMessage(discountCap) } as ApiError);
            }
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
            if (revise && editing) {
                if (reviseReason.trim().length < 5) {
                    return Promise.reject({ message: "Say why you are revising it (at least 5 characters)." } as ApiError);
                }
                return quotationApi.revise(editing.id, { ...payload, reason: reviseReason.trim() });
            }
            return editing ? quotationApi.update(editing.id, payload) : quotationApi.create(payload);
        },
        onSuccess: (res: any) => { toast.success(revise ? (res?.message ?? "Quotation revised.") : editing ? "Quotation updated." : "Quotation created."); onSaved(); },
        onError: (e: ApiError) => { setErrors(e.errors ?? {}); toast.error(e.message); },
    });

    const canSave = rows.length > 0 && !save.isPending && pricingKeys.length === 0 && (!revise || reviseReason.trim().length >= 5);
    const nextRev = (revisionsData?.current_revision ?? 0) + 1;
    const total = subtotal + whole(num(shipping));

    return (
        <Modal
            open
            onClose={onClose}
            size="full"
            title={revise && editing ? `Revise ${editing.quote_number} — Rev ${nextRev}` : editing ? `Edit Quotation ${editing.quote_number ?? "(draft)"}` : "New Quotation"}
            footer={
                <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                    {revise ? (
                        // The reason sits beside the button that needs it (owner, 2026-11-14).
                        <label className="flex-1 min-w-0">
                            <span className="block text-xs font-medium text-surface-600 mb-1">Why are you revising it? <span className="text-danger">*</span></span>
                            <input
                                className="input"
                                value={reviseReason}
                                onChange={(e) => setReviseReason(e.target.value)}
                                placeholder="e.g. Customer asked for five instead of three"
                                aria-label="Why are you revising it?"
                            />
                        </label>
                    ) : <div className="flex-1" />}
                    <div className="flex items-center justify-between gap-2 sm:justify-end sm:gap-3">
                        <div className="mr-auto text-left leading-tight sm:mr-0 sm:text-right">
                            <div className="whitespace-nowrap text-2xs uppercase tracking-wide text-surface-500">Total<span className="hidden sm:inline"> (excl. tax)</span></div>
                            <div className="whitespace-nowrap text-base font-bold tabular-nums sm:text-lg">{money(total, currency)}</div>
                        </div>
                        <button className="btn-secondary hidden whitespace-nowrap sm:inline-flex" onClick={onClose}>Cancel</button>
                        <button
                            className="btn-primary whitespace-nowrap"
                            disabled={!canSave}
                            title={revise && reviseReason.trim().length < 5 ? "Say why you are revising it first" : pricingKeys.length ? "Pricing products…" : undefined}
                            onClick={() => save.mutate()}
                        >
                            {save.isPending ? <Spinner size="sm" /> : revise ? `Save as Rev ${nextRev}` : editing ? "Save changes" : "Create quotation"}
                        </button>
                    </div>
                </div>
            }
        >
            <div className="space-y-5">
                {revise && (
                    <div className="rounded-lg border border-line bg-surface-50 p-3 space-y-2">
                        <p className="text-xs text-surface-600">
                            The quotation as issued is kept, and the customer's link and PDF show Rev {nextRev}.
                            A declined or expired quotation is offered again. Say why at the bottom, beside Save.
                        </p>
                        {(revisionsData?.revisions.length ?? 0) > 0 && <RevisionRows rows={revisionsData!.revisions} label="Quotation" />}
                    </div>
                )}
                <h3 className="text-xs font-semibold uppercase tracking-wide text-surface-500">Customer</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                </div>
                <h3 className="text-xs font-semibold uppercase tracking-wide text-surface-500">Quotation</h3>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <Field label="Currency — every price below is in it" error={errors.currency_code?.[0]}>
                        <FieldSelect value={currency} disabled={repricing} onChange={(e) => changeCurrency(e.target.value)}>
                            {currencies.map((c) => (
                                <option key={c.code} value={c.code}>{c.code} — {c.name}</option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field label="Valid until" error={errors.valid_until?.[0]}>
                        <FieldInput type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
                    </Field>
                    <Field label="Served by" error={errors.served_by?.[0]}>
                        <FieldInput value={servedBy} onChange={(e) => setServedBy(e.target.value)} placeholder="Staff member" />
                    </Field>
                </div>

                {/* Product picker: tick several, add them in one go (as on stock adjustments). */}
                <ProductMultiPicker
                    currency={currency}
                    onTheQuote={new Set(rows.map((r) => r.product_id).filter((x): x is number => x !== null))}
                    search={fetchProducts}
                    disabled={repricing}
                    onAdd={addProducts}
                />

                {/* Line items */}
                <div className="card overflow-hidden">
                    <div className="table-wrapper rounded-none border-0">
                    <table className="table">
                        <thead>
                            <tr>
                                <th className="w-8 text-right">#</th>
                                <th>Description</th>
                                <th className="w-28 text-right">Qty</th>
                                <th className="w-40 text-right">Unit price ({currency})</th>
                                <th className="w-40 text-right">Line total ({currency})</th>
                                <th className="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr><td colSpan={6} className="py-8 text-center text-sm text-surface-500">Tick products above to add them, or “+ Add line” to type one.</td></tr>
                            ) : rows.map((r, idx) => (
                                <tr key={r.key}>
                                    <td className="text-right text-xs tabular-nums text-surface-400">{idx + 1}</td>
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
                                            <div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-surface-0 shadow-lg">
                                                {rowSearching && <div className="px-3 py-2 text-xs text-surface-500">Searching…</div>}
                                                {rowHits.map((h) => (
                                                    <button
                                                        key={h.id}
                                                        type="button"
                                                        className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted/40"
                                                        onMouseDown={(e) => { e.preventDefault(); pickForRow(r.key, h); }}
                                                    >
                                                        <span>{h.name}</span>
                                                        <span className="text-xs text-surface-500">{h.sku}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td>
                                        <input className="input text-right px-2 tabular-nums" type="number" min="1" value={r.quantity} aria-label={`Quantity of ${r.product_name || "this line"}`}
                                            onChange={(e) => updateRow(r.key, "quantity", e.target.value)} />
                                    </td>
                                    <td>
                                        {pricingKeys.includes(r.key) ? (
                                            <div className="flex h-10 items-center justify-end gap-2 text-xs text-surface-500"><Spinner size="xs" /> {currency} price…</div>
                                        ) : (
                                        <input
                                            className={`input text-right tabular-nums${keptKeys.includes(r.key) ? " border-warning bg-warning-light/40" : ""}`}
                                            aria-label={`Unit price of ${r.product_name || "this line"} in ${currency}`}
                                            type="number" min="0" step="0.01" value={r.unit_price}
                                            title={keptKeys.includes(r.key) ? `No ${currency} price on the hub for this line — check the figure before sending.` : undefined}
                                            onChange={(e) => { updateRow(r.key, "unit_price", e.target.value); setKeptKeys((k) => k.filter((x) => x !== r.key)); }} />
                                        )}
                                        {keptKeys.includes(r.key) && !pricingKeys.includes(r.key) && (
                                            <p className="mt-1 text-2xs text-warning-dark text-left">No {currency} price on the hub — type it.</p>
                                        )}
                                        {priceError(r, idx) && (
                                            <p className="mt-1 text-2xs text-danger text-left">{priceError(r, idx)}</p>
                                        )}
                                    </td>
                                    <td className="text-right tabular-nums">{money(num(r.quantity) * whole(num(r.unit_price)), currency)}</td>
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
                                <span className="text-surface-500">Subtotal (excl. tax): </span>
                                <span className="font-semibold tabular-nums">{money(subtotal, currency)}</span>
                            </div>
                        </div>
                        <div className="flex items-center justify-end gap-3 text-sm">
                            <label className="text-surface-500">Shipping ({currency})</label>
                            <input
                                className="input w-32 text-right"
                                type="number" min="0" step="0.01"
                                value={shipping}
                                onChange={(e) => setShipping(e.target.value)}
                            />
                        </div>
                        <div className="flex items-center justify-end gap-2 border-t pt-2 text-base font-bold">
                            <span>Total (excl. tax)</span>
                            <span className="tabular-nums">{money(total, currency)}</span>
                        </div>
                    </div>
                </div>

                <Field label="Notes" error={errors.notes?.[0]}>
                    <FieldTextarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
                </Field>

                <p className="text-xs text-surface-500">
                    Tax is applied automatically on save based on each product's rate. Once issued, a quotation is
                    locked — edits require a new quotation.
                </p>
            </div>
        </Modal>
    );
}

// ── Product picker: tick several, add them in one go ───────────────────────
// Same idea as the stock-adjustment picker (owner, 2026-11-14): search, tick
// the products, "Add N". Prices are not shown here — each line is priced in
// the quotation's currency by the server when it is added.
function ProductMultiPicker({ currency, onTheQuote, search, disabled, onAdd }: {
    currency: string;
    onTheQuote: Set<number>;
    search: (q: string) => Promise<ProductHit[]>;
    disabled?: boolean;
    onAdd: (picked: ProductHit[]) => void;
}) {
    const [query, setQuery] = useState("");
    const [open, setOpen] = useState(false);
    const [hits, setHits] = useState<ProductHit[]>([]);
    const [busy, setBusy] = useState(false);
    const [ticked, setTicked] = useState<Map<number, ProductHit>>(new Map());
    const seq = useRef(0);

    useEffect(() => {
        const q = query.trim();
        if (q.length < 2) { setHits([]); return; }
        const mine = ++seq.current;
        setBusy(true);
        const t = window.setTimeout(async () => {
            try { const r = await search(q); if (mine === seq.current) setHits(r); }
            catch { if (mine === seq.current) setHits([]); }
            finally { if (mine === seq.current) setBusy(false); }
        }, 250);
        return () => window.clearTimeout(t);
    }, [query, search]);

    const toggle = (h: ProductHit) => setTicked((m) => {
        const next = new Map(m);
        if (next.has(h.id)) next.delete(h.id); else next.set(h.id, h);
        return next;
    });
    const add = () => {
        if (!ticked.size) return;
        onAdd([...ticked.values()]);
        setTicked(new Map()); setQuery(""); setHits([]); setOpen(false);
    };

    return (
        <div className="relative">
            <div className="flex gap-2">
                <input
                    className="input flex-1"
                    placeholder={`Search products to add (priced in ${currency})…`}
                    value={query}
                    disabled={disabled}
                    onFocus={() => setOpen(true)}
                    onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                    onKeyDown={(e) => { if (e.key === "Escape") setOpen(false); }}
                    aria-label="Search products to add"
                />
                <button type="button" className="btn-primary shrink-0" disabled={!ticked.size || disabled} onClick={add}>
                    Add{ticked.size ? ` ${ticked.size}` : ""}
                </button>
            </div>
            {open && (query.trim().length >= 2 || ticked.size > 0) && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-surface-200 bg-white shadow-2xl">
                        <div className="max-h-72 overflow-y-auto">
                            {busy && <div className="px-3 py-2 text-sm text-surface-500">Searching…</div>}
                            {!busy && hits.length === 0 && query.trim().length >= 2 && (
                                <div className="px-3 py-6 text-center text-sm text-surface-400">No products match “{query.trim()}”. Use “+ Add line” to type one.</div>
                            )}
                            {hits.map((h, i) => {
                                const already = onTheQuote.has(h.id);
                                const on = ticked.has(h.id);
                                return (
                                    <label
                                        key={h.id}
                                        className={`flex cursor-pointer items-center gap-3 border-b border-surface-50 px-3 py-2 last:border-0 ${on ? "bg-brand-50" : i % 2 ? "bg-surface-50/60" : "bg-white"} hover:bg-brand-50/70`}
                                    >
                                        <input type="checkbox" className="h-4 w-4 shrink-0 accent-brand-500" checked={on} onChange={() => toggle(h)} aria-label={`Add ${h.name}`} />
                                        <span className={`flex-1 min-w-0 truncate text-sm ${on ? "font-semibold text-brand-800" : "font-medium text-surface-800"}`}>{h.name}</span>
                                        {already && <span className="shrink-0 rounded bg-surface-100 px-1.5 py-0.5 text-2xs text-surface-500">on the quote</span>}
                                        <span className="shrink-0 font-mono text-[11px] text-surface-400">{h.sku}</span>
                                    </label>
                                );
                            })}
                        </div>
                        <div className="flex items-center justify-between gap-2 border-t border-line px-3 py-2">
                            <span className="text-[11px] text-surface-400">
                                {ticked.size ? `${ticked.size} ticked: ${[...ticked.values()].map((h) => h.name).slice(0, 3).join(", ")}${ticked.size > 3 ? "…" : ""}` : "Tick products, then Add"}
                            </span>
                            <button type="button" className="btn-primary btn-sm" disabled={!ticked.size} onClick={add}>
                                Add {ticked.size || ""} to the quotation
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
