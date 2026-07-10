/**
 * Public (unauthenticated) page at /quote/:token — the customer's view of a
 * quotation we sent them. They can review it and accept it, which converts it
 * into an invoice and forwards them to the /pay/:token pay-link. The token is the
 * authorization; no login. Mirrors PaymentLinkPage.
 */
import { useCallback, useEffect, useState } from "react";
import { useParams } from "react-router-dom";

const BASE = import.meta.env.VITE_API_URL ?? "/api";

async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const res = await fetch(`${BASE}${path}`, {
        headers: { "Content-Type": "application/json", Accept: "application/json", ...init?.headers },
        ...init,
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((body as { message?: string })?.message ?? `Error ${res.status}`);
    return body as T;
}

const fmt = (n: number, cc = "KES") =>
    `${cc} ${Number(n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

interface QItem { product_name: string; variant_name: string | null; quantity: number; unit_price: number; total_price: number }
interface QuoteView {
    quote_number: string;
    status: string;
    currency_code: string;
    issued_at: string | null;
    valid_until: string | null;
    is_expired: boolean;
    is_accepted: boolean;
    served_by: string | null;
    customer: { first_name: string | null; last_name: string | null };
    items: QItem[];
    totals: { subtotal: number; tax_amount: number; shipping_amount: number; total_amount: number };
    notes: string | null;
    terms: string | null;
    business: { name: string; email: string | null; phone: string | null; address: string | null };
}

export default function PublicQuotationPage() {
    const { token } = useParams<{ token: string }>();
    const [quote, setQuote] = useState<QuoteView | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [accepting, setAccepting] = useState(false);

    const load = useCallback(async () => {
        try {
            setLoading(true);
            const res = await api<{ quotation: QuoteView }>(`/v1/quote/${token}`);
            setQuote(res.quotation);
        } catch (e) {
            setError(e instanceof Error ? e.message : "Could not load this quotation.");
        } finally {
            setLoading(false);
        }
    }, [token]);

    useEffect(() => { void load(); }, [load]);

    async function accept() {
        setAccepting(true);
        setError("");
        try {
            const res = await api<{ pay_token: string | null }>(`/v1/quote/${token}/accept`, { method: "POST" });
            if (res.pay_token) {
                window.location.assign(`/pay/${res.pay_token}`);
            } else {
                await load();
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : "Could not accept this quotation.");
            setAccepting(false);
        }
    }

    if (loading) {
        return <div className="flex min-h-screen items-center justify-center text-gray-500">Loading…</div>;
    }
    if (!quote) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
                <div className="max-w-md rounded-lg bg-white p-8 text-center shadow">
                    <h1 className="mb-2 text-lg font-semibold text-gray-900">Quotation unavailable</h1>
                    <p className="text-sm text-gray-500">{error || "This quotation link is invalid or has expired."}</p>
                </div>
            </div>
        );
    }

    const cc = quote.currency_code;
    const custName = `${quote.customer.first_name ?? ""} ${quote.customer.last_name ?? ""}`.trim() || "Customer";
    const canAccept = quote.status === "sent" && !quote.is_expired && !quote.is_accepted;

    return (
        <div className="min-h-screen bg-gray-50 py-8 px-4">
            <div className="mx-auto max-w-2xl space-y-4">
                <div className="rounded-lg bg-white p-6 shadow">
                    {/* Header */}
                    <div className="flex items-start justify-between border-b pb-4">
                        <div>
                            <div className="text-lg font-bold text-gray-900">{quote.business.name}</div>
                            {quote.business.email && <div className="text-xs text-gray-500">{quote.business.email}</div>}
                            {quote.business.phone && <div className="text-xs text-gray-500">{quote.business.phone}</div>}
                        </div>
                        <div className="text-right">
                            <div className="text-xl font-bold text-gray-900">Quotation</div>
                            <div className="text-sm text-gray-600">{quote.quote_number}</div>
                            {quote.valid_until && <div className="mt-1 text-xs text-gray-500">Valid until {quote.valid_until}</div>}
                        </div>
                    </div>

                    <div className="flex items-center justify-between py-3 text-sm text-gray-600">
                        <span>Prepared for <span className="font-medium text-gray-900">{custName}</span></span>
                        {quote.served_by && <span className="text-xs text-gray-500">Served by {quote.served_by}</span>}
                    </div>

                    {/* Items */}
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs uppercase tracking-wide text-gray-500">
                                <th className="py-2">Description</th>
                                <th className="py-2 text-right">Qty</th>
                                <th className="py-2 text-right">Unit Price</th>
                                <th className="py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {quote.items.map((it, i) => (
                                <tr key={i} className="border-b border-gray-100">
                                    <td className="py-2">
                                        {it.product_name}
                                        {it.variant_name && <span className="text-xs text-gray-400"> — {it.variant_name}</span>}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{it.quantity}</td>
                                    <td className="py-2 text-right tabular-nums">{fmt(it.unit_price, cc)}</td>
                                    <td className="py-2 text-right tabular-nums">{fmt(it.total_price, cc)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {/* Totals */}
                    <div className="mt-4 ml-auto w-64 space-y-1 text-sm">
                        <div className="flex justify-between text-gray-600"><span>Subtotal</span><span className="tabular-nums">{fmt(quote.totals.subtotal, cc)}</span></div>
                        <div className="flex justify-between text-gray-600"><span>Tax</span><span className="tabular-nums">{fmt(quote.totals.tax_amount, cc)}</span></div>
                        {quote.totals.shipping_amount > 0 && (
                            <div className="flex justify-between text-gray-600"><span>Shipping</span><span className="tabular-nums">{fmt(quote.totals.shipping_amount, cc)}</span></div>
                        )}
                        <div className="flex justify-between border-t pt-1 text-base font-bold text-gray-900"><span>Total</span><span className="tabular-nums">{fmt(quote.totals.total_amount, cc)}</span></div>
                    </div>

                    {quote.notes && <div className="mt-4 text-xs text-gray-500"><span className="font-medium">Notes:</span> {quote.notes}</div>}
                    {quote.terms && <div className="mt-1 text-xs text-gray-500"><span className="font-medium">Terms:</span> {quote.terms}</div>}
                </div>

                {/* Action */}
                <div className="rounded-lg bg-white p-6 shadow">
                    {error && <div className="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
                    {quote.is_accepted ? (
                        <div className="text-center text-sm text-green-700">This quotation has already been accepted. Please check your invoice / pay-link.</div>
                    ) : quote.is_expired ? (
                        <div className="text-center text-sm text-amber-700">This quotation has expired. Please contact us for an updated quote.</div>
                    ) : (
                        <button
                            className="w-full rounded-lg bg-gray-900 py-3 text-sm font-semibold text-white hover:bg-black disabled:opacity-50"
                            disabled={!canAccept || accepting}
                            onClick={accept}
                        >
                            {accepting ? "Processing…" : "Accept & proceed to payment"}
                        </button>
                    )}
                    <p className="mt-2 text-center text-xs text-gray-400">Accepting confirms this order and takes you to secure payment.</p>
                </div>
            </div>
        </div>
    );
}
