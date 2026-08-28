/**
 * OrderPage.tsx — the customer's own view of one order, at /order/{public_token}.
 *
 * This is the link a customer is given. It is the RECEIPT when the order is
 * paid and the CHECKOUT when it is not, and unlike /pay/{token} it does not
 * expire — that was the defect behind "the payment link is faulty": a pay token
 * lives 72 hours, so every link Neema ever sent (all 88) was dead when the
 * owner checked.
 *
 * Paying reuses the existing, hardened /pay page rather than duplicating a
 * single payment panel: "Pay now" asks the server for a fresh 72-hour session
 * and hands off. So the customer can always pay, however old their link is, and
 * the country gate / M-Pesa STK / Paystack verification code stays in one place.
 */

import { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { clsx } from "clsx";

// This page is PUBLIC. It deliberately uses a bare fetch rather than
// @/api/client, which injects the staff Bearer token and redirects on 401 —
// exactly the login wall a customer must never hit on their own receipt. Same
// reasoning (and the same two helpers) as PaymentLinkPage.
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

function Card({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <div className={clsx("bg-white rounded-2xl shadow-sm border border-surface-100 overflow-hidden", className)}>
            {children}
        </div>
    );
}

interface OrderItem {
    name: string;
    variant_name: string | null;
    quantity: number;
    unit_price: number;
    total_price: number;
    notes: string | null;
}

interface PublicOrder {
    order_number: string;
    ordered_at: string | null;
    status: string;
    payment_status: string;
    currency_code: string;
    subtotal: number;
    discount_amount: number;
    shipping_amount: number;
    tax_amount: number;
    total_amount: number;
    amount_paid: number;
    amount_due: number;
    items: OrderItem[];
    payments: { method: string; amount: number; provider_reference: string | null; paid_at: string | null }[];
    shipment: { status: string; carrier: string | null; tracking_number: string | null } | null;
    customer_name: string | null;
    customer_phone: string | null;
    business_name: string;
    business_logo: string | null;
    business_tagline: string | null;
}

const STATUS_LABEL: Record<string, { label: string; cls: string }> = {
    paid:      { label: "Paid",             cls: "bg-success-100 text-success-800 border-success-200" },
    partial:   { label: "Partially paid",   cls: "bg-amber-100 text-amber-800 border-amber-200" },
    deposit:   { label: "Deposit received", cls: "bg-info-100 text-info-800 border-info-200" },
    pending:   { label: "Awaiting payment", cls: "bg-brand-100 text-brand-800 border-brand-200" },
    unpaid:    { label: "Awaiting payment", cls: "bg-brand-100 text-brand-800 border-brand-200" },
};

export default function OrderPage() {
    const { token } = useParams<{ token: string }>();
    const navigate = useNavigate();
    const [order, setOrder] = useState<PublicOrder | null>(null);
    const [state, setState] = useState<"loading" | "ready" | "missing">("loading");
    const [paying, setPaying] = useState(false);
    const [payError, setPayError] = useState<string | null>(null);

    useEffect(() => {
        let alive = true;
        api<PublicOrder>(`/v1/order/${token}`)
            .then((o) => { if (alive) { setOrder(o); setState("ready"); } })
            .catch(() => { if (alive) setState("missing"); });
        return () => { alive = false; };
    }, [token]);

    const fmt = (n: number) =>
        `${order?.currency_code ?? "KES"} ${Number(n).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`;

    async function payNow() {
        setPaying(true);
        setPayError(null);
        try {
            // Always a FRESH session — this is what stops old links dying.
            const r = await api<{ payment_token: string }>(
                `/v1/order/${token}/pay-session`, { method: "POST", body: "{}" });
            navigate(`/pay/${r.payment_token}`);
        } catch (e) {
            const msg = (e as { message?: string })?.message;
            setPayError(msg ?? "Could not open the payment page. Please try again.");
            setPaying(false);
        }
    }

    if (state === "loading") {
        return (
            <div className="min-h-screen flex items-center justify-center bg-surface-50">
                <div className="h-8 w-8 rounded-full border-2 border-surface-200 border-t-brand-500 animate-spin" />
            </div>
        );
    }

    if (state === "missing" || !order) {
        return (
            <div className="min-h-screen bg-surface-50 flex items-center justify-center p-4">
                <Card className="max-w-sm w-full p-8 text-center">
                    <h1 className="text-lg font-bold text-surface-900 mb-2">Order not found</h1>
                    <p className="text-sm text-surface-500">
                        This link doesn’t match an order. Please check the link, or contact us and quote your order number.
                    </p>
                </Card>
            </div>
        );
    }

    const badge = STATUS_LABEL[order.payment_status] ?? {
        label: order.payment_status,
        cls: "bg-surface-100 text-surface-700 border-surface-200",
    };
    const owes = order.amount_due > 0;

    return (
        <div className="min-h-screen bg-surface-50 py-6 px-4">
            <div className="max-w-lg mx-auto space-y-4">
                {/* Business header */}
                <div className="text-center">
                    {order.business_logo && (
                        <img src={order.business_logo} alt={order.business_name}
                             className="h-10 mx-auto mb-2 object-contain" />
                    )}
                    <h1 className="text-base font-bold text-surface-900">{order.business_name}</h1>
                    {order.business_tagline && (
                        <p className="text-xs text-surface-500">{order.business_tagline}</p>
                    )}
                </div>

                <Card>
                    <div className="p-5 space-y-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-2xs text-surface-400 uppercase tracking-wide font-medium">
                                    {owes ? "Order" : "Receipt"}
                                </p>
                                <p className="text-xl font-bold font-mono text-surface-900">{order.order_number}</p>
                                {order.customer_name && (
                                    <p className="text-xs font-medium text-surface-700 mt-0.5">{order.customer_name}</p>
                                )}
                                {order.customer_phone && (
                                    <p className="text-2xs text-surface-500">{order.customer_phone}</p>
                                )}
                                {order.ordered_at && (
                                    <p className="text-2xs text-surface-400 mt-1">
                                        {new Date(order.ordered_at).toLocaleDateString("en-KE",
                                            { day: "numeric", month: "long", year: "numeric" })}
                                    </p>
                                )}
                            </div>
                            <span className={clsx(
                                "inline-flex items-center text-2xs font-semibold px-2.5 py-1.5 rounded-full border whitespace-nowrap",
                                badge.cls)}>
                                {badge.label}
                            </span>
                        </div>

                        {/* Items — the part the old paid screen never showed */}
                        {order.items.length > 0 && (
                            <div className="border-t border-line pt-3">
                                <table className="w-full text-sm">
                                    <tbody className="divide-y divide-line">
                                        {order.items.map((it, i) => (
                                            <tr key={i}>
                                                <td className="py-2 pr-2">
                                                    <span className="text-surface-800">{it.name}</span>
                                                    {it.variant_name && (
                                                        <span className="text-surface-500"> · {it.variant_name}</span>
                                                    )}
                                                    <span className="text-surface-400"> × {it.quantity}</span>
                                                    {it.notes && (
                                                        <span className="block text-2xs text-surface-400">{it.notes}</span>
                                                    )}
                                                </td>
                                                <td className="py-2 text-right whitespace-nowrap text-surface-800">
                                                    {fmt(it.total_price)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Money */}
                        <div className="border-t border-line pt-3 space-y-1.5 text-sm">
                            <Row label="Subtotal" value={fmt(order.subtotal)} />
                            {order.discount_amount > 0 && <Row label="Discount" value={`− ${fmt(order.discount_amount)}`} />}
                            {order.shipping_amount > 0 && <Row label="Delivery" value={fmt(order.shipping_amount)} />}
                            {order.tax_amount > 0 && <Row label="Tax" value={fmt(order.tax_amount)} />}
                            <div className="flex justify-between pt-1.5 border-t border-line font-bold text-surface-900">
                                <span>Total</span><span>{fmt(order.total_amount)}</span>
                            </div>
                            {order.amount_paid > 0 && (
                                <Row label="Paid" value={`− ${fmt(order.amount_paid)}`} />
                            )}
                            {owes && (
                                <div className="flex justify-between font-bold text-brand-700">
                                    <span>Balance due</span><span>{fmt(order.amount_due)}</span>
                                </div>
                            )}
                        </div>

                        {/* What was paid, and how */}
                        {order.payments.length > 0 && (
                            <div className="border-t border-line pt-3 space-y-1">
                                <p className="text-2xs text-surface-400 uppercase tracking-wide font-medium">Payments</p>
                                {order.payments.map((p, i) => (
                                    <div key={i} className="flex justify-between text-xs text-surface-600">
                                        <span>
                                            {p.method}
                                            {p.provider_reference && (
                                                <span className="font-mono text-surface-400"> · {p.provider_reference}</span>
                                            )}
                                        </span>
                                        <span>{fmt(p.amount)}</span>
                                    </div>
                                ))}
                            </div>
                        )}

                        {order.shipment && (
                            <div className="border-t border-line pt-3 text-xs text-surface-600">
                                <span className="font-medium">Delivery:</span> {order.shipment.status}
                                {order.shipment.carrier && ` · ${order.shipment.carrier}`}
                                {order.shipment.tracking_number && (
                                    <span className="font-mono"> · {order.shipment.tracking_number}</span>
                                )}
                            </div>
                        )}
                    </div>
                </Card>

                {owes ? (
                    <>
                        <button onClick={payNow} disabled={paying}
                                className="w-full rounded-full bg-brand-600 text-white text-sm font-semibold py-3.5 hover:bg-brand-700 disabled:opacity-60 transition-colors">
                            {paying ? "Opening payment…" : `Pay ${fmt(order.amount_due)}`}
                        </button>
                        {payError && <p className="text-xs text-danger text-center">{payError}</p>}
                        <p className="text-2xs text-surface-400 text-center">
                            You’ll see the payment options available in your country.
                        </p>
                    </>
                ) : (
                    <p className="text-xs text-surface-500 text-center">
                        Paid in full — thank you. Keep this link as your receipt.
                    </p>
                )}
            </div>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between text-surface-600">
            <span>{label}</span><span>{value}</span>
        </div>
    );
}
