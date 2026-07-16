import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { invoiceApi, type Invoice } from "@/api/invoices";
import { Spinner } from "@/components/ui/Spinner";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";
import { useToastStore } from "@/store/toast.store";

const num = (v: unknown): number => Number(v ?? 0) || 0;
const money = (v: unknown, currency = "KES"): string =>
    `${currency} ${num(v).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function PaymentBadge({ status }: { status: string }) {
    const map: Record<string, { label: string; badge: string }> = {
        paid:             { label: "Paid",    badge: "badge-success" },
        partial:          { label: "Partial", badge: "badge-warning" },
        deposit:          { label: "Deposit", badge: "badge-warning" },
        pending:          { label: "Unpaid",  badge: "badge-neutral" },
        pending_approval: { label: "Awaiting approval", badge: "badge-info" },
    };
    const s = map[status] ?? { label: status, badge: "badge-neutral" };
    return <span className={`badge ${s.badge}`}>{s.label}</span>;
}

export default function InvoicesPage() {
    const navigate = useNavigate();
    const toast = useToastStore();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");

    const params = useMemo(() => ({
        page,
        per_page: 20,
        ...(search ? { search } : {}),
        ...(statusFilter ? { status: statusFilter } : {}),
    }), [page, search, statusFilter]);

    const { data, isLoading } = useQuery({
        queryKey: ["invoices", params],
        queryFn: () => invoiceApi.list(params),
    });

    const copyPayLink = async (inv: Invoice) => {
        if (!inv.order?.pay_token) { toast.error("No pay-link on this invoice."); return; }
        const url = `${window.location.origin}/pay/${inv.order.pay_token}`;
        try { await navigator.clipboard.writeText(url); toast.success("Pay-link copied."); }
        catch { window.prompt("Copy this pay-link:", url); }
    };

    const rows = data?.data ?? [];

    return (
        <div className="space-y-5">
            <div>
                <h1 className="page-title">Invoices</h1>
                <p className="page-subtitle">Accepted quotations awaiting or completing payment. Paid invoices become orders.</p>
            </div>

            <div className="flex flex-wrap gap-3">
                <input
                    className="input flex-1 min-w-[200px]"
                    placeholder="Search invoice number…"
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                />
                <select className="input w-44" value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}>
                    <option value="">All</option>
                    <option value="issued">Open (issued)</option>
                    <option value="paid">Paid</option>
                </select>
            </div>

            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                ) : rows.length === 0 ? (
                    <div className="py-16 text-center text-sm text-muted">No invoices yet. Accept a quotation to create one.</div>
                ) : (
                    <div className="table-wrapper rounded-none border-0">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>From quote</th>
                                <th className="text-right">Total</th>
                                <th>Payment</th>
                                <th>Due</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((inv) => (
                                <tr key={inv.id}>
                                    <td className="font-medium tabular-nums">{inv.invoice_number}</td>
                                    <td>{inv.customer_name ?? "—"}</td>
                                    <td className="text-sm text-muted tabular-nums">{inv.quotation?.number ?? "—"}</td>
                                    <td className="text-right tabular-nums">{money(inv.amount, inv.currency_code)}</td>
                                    <td>{inv.order ? <PaymentBadge status={inv.order.payment_status} /> : "—"}</td>
                                    <td>{inv.due_date ?? "—"}</td>
                                    <td>
                                        <div className="flex justify-end gap-1">
                                            {inv.order && <PdfDownloadButton type="orders" id={inv.order.id} subtype="invoice" label="Invoice PDF" />}
                                            {inv.order && inv.order.payment_status !== "paid" && (
                                                <button className="btn-ghost btn-sm" onClick={() => copyPayLink(inv)}>Pay link</button>
                                            )}
                                            {inv.order && (
                                                <button className="btn-ghost btn-sm" onClick={() => navigate(`/sales/orders/${inv.order!.id}`)}>
                                                    View order
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}
            </div>

            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted">{data.from ?? 0}–{data.to ?? 0} of {data.total}</span>
                    <div className="flex gap-2">
                        <button className="btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</button>
                        <button className="btn-secondary btn-sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Next</button>
                    </div>
                </div>
            )}
        </div>
    );
}
