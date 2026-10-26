// src/components/orders/OrderRow.tsx
//
// THE order list row — one component behind Till Sales, Web Orders, Chat
// Orders and Quoted Sales (all four are OrdersListPage with a channel), so a
// row can never read one way on one page and another way on the next.
//
// Layout (owner's mock, 2026-10-26):
//
//   ORDER | CHANNEL | STATUS | ITEMS | TOTAL | SERVED BY | DATE | ACTIONS
//
//   ORDER     name + phone / ID: customer number / Order#: …   (was two columns)
//   CHANNEL   channel / ONE payment badge / how it was paid
//   ITEMS     "3 items" link, then each line on its own row, 3 shown + "+N more"
//   DATE      when it was PAID (latest settled payment), Nairobi time;
//             unpaid → when it was created, labelled so
//
// Eight separate columns need ~1,400px of content; with the 256px sidebar open
// that is a 1760px window. Below it (a laptop, an iPad Pro) STATUS stacks under
// TOTAL and SERVED BY under DATE, so six columns carry all eight without a
// sideways scroll. Below xl (1280) the page shows OrderCard instead.
//
// Every value here comes from the API row as served — masking (phones) is the
// server's, and nothing on this row is summed into a figure.

import type { ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import type { Order, OrderItem, OrderListPaymentState } from "@/api/orders";
import { BUSINESS_TIME_ZONE } from "@/lib/businessDate";

// ── Status pill (unchanged semantics — moved here from OrdersListPage) ───────

// Order-stage semantics. An order's life is three stages and the colours say
// which one it is at a glance down a list:
//
//   Confirmed  amber  — a human accepted it; it is income and it is waiting
//   Processed  pink   — it is being worked (in production, shipped, delivered)
//   Completed  green  — done
//
// `pending` is deliberately NOT one of them. It is an unconfirmed cart a
// customer built and abandoned, it is NOT income, and it is excluded from the
// sales report — so it reads as neutral-grey "Unconfirmed", not as a warning.
// Calling it "Pending Payment" implied the only missing thing was money, which
// is how 98 abandoned carts came to be counted as KES 1.1m of revenue.
export const STATUS_CONFIG: Record<string, { label: string; dot: string; badge: string }> = {
    pending:         { label: "Unconfirmed",     dot: "bg-surface-400",   badge: "badge-neutral" },
    paid:            { label: "Paid",            dot: "bg-success-vivid", badge: "badge-success" },
    confirmed:       { label: "Confirmed",       dot: "bg-amber",         badge: "badge-amber"   },
    processing:      { label: "Processed",       dot: "bg-pink-vivid",    badge: "badge-pink"    },
    shipped:         { label: "Shipped",         dot: "bg-pink-vivid",    badge: "badge-pink"    },
    delivered:       { label: "Delivered",       dot: "bg-pink-vivid",    badge: "badge-pink"    },
    completed:       { label: "Completed",       dot: "bg-success-vivid", badge: "badge-success" },
    cancelled:       { label: "Cancelled",       dot: "bg-danger",        badge: "badge-danger"  },
    refunded:        { label: "Refunded",        dot: "bg-surface-400",   badge: "badge-neutral" },
    voided:          { label: "Voided",          dot: "bg-surface-400",   badge: "badge-neutral" },
};

export function statusConfig(status: string) {
    return STATUS_CONFIG[status] ?? { label: status, badge: "badge-neutral", dot: "bg-surface-400" };
}

export function StatusBadge({ status }: { status: string }) {
    const config = statusConfig(status);
    return (
        <span className={clsx("badge whitespace-nowrap", config.badge)}>
            <span className={clsx("w-1.5 h-1.5 rounded-full", config.dot)} />
            {config.label}
        </span>
    );
}

// ── Formatting ───────────────────────────────────────────────────────────────

const money = (n: number | string | null | undefined) =>
    (Number(n) || 0).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const formatMoney = (currency: string | null | undefined, n: number | string | null | undefined) =>
    `${currency || "KES"} ${money(n)}`;

const dayFmt     = new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TIME_ZONE, day: "numeric", month: "long", year: "numeric" });
const weekdayFmt = new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TIME_ZONE, weekday: "long" });
const timeFmt    = new Intl.DateTimeFormat("en-GB", { timeZone: BUSINESS_TIME_ZONE, hour: "2-digit", minute: "2-digit", hourCycle: "h23" });

/** "26 October 2026" / "Monday" / "14:30", read on the business clock (Nairobi). */
export function businessDateParts(iso: string | null | undefined) {
    if (!iso) return null;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return null;
    return { day: dayFmt.format(d), weekday: weekdayFmt.format(d), time: timeFmt.format(d) };
}

/** The date the row shows: paid (latest settled payment), else created. */
export function orderRowDate(order: Order): { iso: string; label: string | null; title: string } {
    const created = businessDateParts(order.created_at);
    const createdText = created ? `Created ${created.day}, ${created.time}` : "";
    const paidAt = order.payment_summary?.paid_at ?? null;
    const paid = businessDateParts(paidAt);
    if (paidAt && paid) {
        const fully = order.payment_summary?.state === "paid";
        return {
            iso: paidAt,
            label: fully ? null : "Last payment",
            title: `${fully ? "Paid" : "Last payment"} ${paid.day}, ${paid.time} (Nairobi) · ${createdText}`,
        };
    }
    return { iso: order.created_at, label: "Created", title: `Not paid yet · ${createdText} (Nairobi)` };
}

/** Real email only — checkout mints noemail+… placeholders for walk-ins. */
function realEmail(email: string | null | undefined): string | null {
    return email && !email.startsWith("noemail+") ? email : null;
}

/** Phone first (the mock), else a real email. Masked by the server for masked roles. */
export function customerContact(order: Order): string | null {
    return order.customer_phone || realEmail(order.customer_email) || null;
}

// ── Channel ──────────────────────────────────────────────────────────────────

type ChannelKind = "till" | "web" | "quoted" | "whatsapp" | "messenger" | "instagram" | "chat";

const CHAT_SOURCES: Record<string, { label: string; kind: ChannelKind }> = {
    whatsapp:  { label: "WhatsApp",  kind: "whatsapp"  },
    messenger: { label: "Messenger", kind: "messenger" },
    instagram: { label: "Instagram", kind: "instagram" },
    website:   { label: "Website",   kind: "chat"      },
    walk_in:   { label: "Walk-in",   kind: "chat"      },
};

/** Which door the sale came through. The stored bucket first; legacy rows by order_type. */
export function orderChannel(order: Order): { label: string; kind: ChannelKind } {
    const bucket = order.sales_bucket
        ?? ((order.order_type as string) === "whatsapp" ? "chat"
            : order.order_type === "pos" ? "till"
            : order.order_type === "online" ? "web" : null);
    switch (bucket) {
        case "till":   return { label: "POS", kind: "till" };
        case "web":    return { label: "Web", kind: "web" };
        case "quoted": return { label: "Quoted", kind: "quoted" };
        case "chat":   return CHAT_SOURCES[order.source_channel ?? ""] ?? { label: "Chat", kind: "chat" };
        default:       return { label: order.order_type ?? "—", kind: "web" };
    }
}

const ICON = "w-3.5 h-3.5 shrink-0";

function ChannelIcon({ kind }: { kind: ChannelKind }) {
    const p = { className: ICON, fill: "none", viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const, "aria-hidden": true };
    switch (kind) {
        case "till": // storefront
            return <svg {...p}><path d="M3 9l1.5-5h15L21 9M3 9v11h18V9M3 9h18M9 20v-6h6v6" /></svg>;
        case "quoted": // document
            return <svg {...p}><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z" /><path d="M14 3v5h5M9 13h6M9 17h4" /></svg>;
        case "whatsapp":
        case "messenger":
        case "instagram":
        case "chat": // speech bubble
            return <svg {...p}><path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z" /></svg>;
        default: // web — globe
            return <svg {...p}><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z" /></svg>;
    }
}

// ── Payment ──────────────────────────────────────────────────────────────────

/** ONE badge per row — the state the server derived (OrderController::listPaymentSummary). */
const PAYMENT_BADGE: Record<OrderListPaymentState, { label: string; badge: string }> = {
    paid:               { label: "Paid",              badge: "badge-success" },
    partial:            { label: "Partially paid",    badge: "badge-amber"   },
    unpaid:             { label: "Unpaid",            badge: "badge-neutral" },
    awaiting_approval:  { label: "Awaiting approval", badge: "badge-neutral" },
    refunded:           { label: "Refunded",          badge: "badge-neutral" },
    partially_refunded: { label: "Partly refunded",   badge: "badge-neutral" },
    failed:             { label: "Payment failed",    badge: "badge-neutral" },
};

/** Labels for a method code when the server sent none (an older API build). */
const METHOD_FALLBACK: Record<string, string> = {
    cash: "Cash", mpesa: "M-Pesa", card: "Card", bank_transfer: "Bank transfer",
    cash_on_delivery: "Cash on delivery", inmpaybill: "I&M Paybill",
};

type MethodFamily = "cash" | "bank" | "mobile" | "card" | "remit" | "other";

function methodFamily(code: string): MethodFamily {
    const c = code.toLowerCase();
    if (c === "cash" || c === "cash_on_delivery") return "cash";
    if (c.includes("mpesa") || c.includes("airtel") || c === "mobile_money") return "mobile";
    if (c.includes("card") || c.includes("paystack") || c.includes("flutterwave")) return "card";
    if (c.includes("mukuru") || c.includes("wu_") || c.includes("moneygram") || c.includes("remit")) return "remit";
    if (c.includes("inm") || c.includes("bank") || c.includes("paybill") || c.includes("cheque")) return "bank";
    return "other";
}

function MethodIcon({ code }: { code: string }) {
    const p = { className: "w-3 h-3", fill: "none", viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round" as const, strokeLinejoin: "round" as const, "aria-hidden": true };
    let path: ReactNode;
    switch (methodFamily(code)) {
        case "cash":   path = <><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /></>; break;
        case "bank":   path = <><path d="M3 10l9-6 9 6" /><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18" /></>; break;
        case "mobile": path = <><rect x="6" y="2" width="12" height="20" rx="2" /><path d="M11 18h2" /></>; break;
        case "card":   path = <><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></>; break;
        case "remit":  path = <><path d="M4 12h14M13 6l6 6-6 6" /></>; break;
        default:       path = <><path d="M3 7h15a3 3 0 013 3v7a3 3 0 01-3 3H6a3 3 0 01-3-3V7z" /><path d="M3 7l2-3h11l2 3M16 13.5h.01" /></>;
    }
    return (
        <span className="inline-flex items-center justify-center w-4 h-4 rounded bg-surface-200 text-surface-600 ring-1 ring-white shrink-0">
            <svg {...p}>{path}</svg>
        </span>
    );
}

/** The payment badge to show, or null when there is nothing honest to say. */
export function paymentBadge(order: Order): { label: string; badge: string } | null {
    const state = order.payment_summary?.state;
    if (state === undefined) {
        // An older API build without payment_summary: say what payment_status says.
        const ps = order.payment_status as string;
        if (["cancelled", "voided"].includes(order.status) && ps !== "paid") return null;
        if (ps === "paid") return PAYMENT_BADGE.paid;
        if (ps === "partial" || ps === "deposit") return PAYMENT_BADGE.partial;
        if (ps === "refunded") return PAYMENT_BADGE.refunded;
        return PAYMENT_BADGE.unpaid;
    }
    return state ? PAYMENT_BADGE[state] : null;
}

export function PaymentBadge({ order }: { order: Order }) {
    const b = paymentBadge(order);
    if (!b) return null;
    return <span className={clsx("badge whitespace-nowrap", b.badge)}>{b.label}</span>;
}

/** "I&M Paybill" / "I&M Paybill + Cash" / "3 methods", with a tooltip listing each. */
export function PaymentMethodLine({ order }: { order: Order }) {
    const methods = order.payment_summary?.methods
        ?? (order.payment_method
            ? [{ code: order.payment_method, name: METHOD_FALLBACK[order.payment_method] ?? order.payment_method, amount: NaN, currency_code: order.currency_code }]
            : []);
    if (!methods.length) return null;

    const label = methods.length === 1 ? methods[0].name
        : methods.length === 2 ? `${methods[0].name} + ${methods[1].name}`
        : `${methods.length} methods`;
    const tooltip = methods
        .map(m => Number.isFinite(m.amount) ? `${m.name} — ${formatMoney(m.currency_code, m.amount)}` : m.name)
        .join("\n");
    const awaiting = order.payment_summary?.state === "awaiting_approval";

    return (
        <span className="flex items-center gap-1.5 min-w-0 text-2xs text-surface-600" title={awaiting ? `Awaiting approval:\n${tooltip}` : tooltip}>
            {methods.length > 1
                ? <span className="flex -space-x-1 shrink-0">{methods.slice(0, 2).map(m => <MethodIcon key={m.code + m.currency_code} code={m.code} />)}</span>
                : <MethodIcon code={methods[0].code} />}
            <span className="truncate">{label}</span>
        </span>
    );
}

// ── Items ────────────────────────────────────────────────────────────────────

const MAX_LINES = 3;

/** "2 × Handwritten (Item 1)" + "KES 9,000.00" — variant only when it adds something. */
export function itemLineParts(item: OrderItem, currency: string): { label: string; amount: string } {
    const name = item.product_name || item.sku || "Item";
    const variant = item.variant_name?.trim();
    const showVariant = variant && variant.toLowerCase() !== name.trim().toLowerCase();
    const qty = Number(item.quantity) || 0;
    const amount = item.total_price ?? item.subtotal ?? (Number(item.unit_price) || 0) * qty;
    return {
        label: `${qty > 1 ? `${qty} × ` : ""}${name}${showVariant ? ` (${variant})` : ""}`,
        amount: formatMoney(currency, amount),
    };
}

export function itemLineText(item: OrderItem, currency: string): string {
    const { label, amount } = itemLineParts(item, currency);
    return `${label}: ${amount}`;
}

export const itemCountLabel = (n: number) => `${n} item${n === 1 ? "" : "s"}`;

// ── Served by ────────────────────────────────────────────────────────────────

const AVATAR_TONES = [
    "bg-brand-100 text-brand-700",
    "bg-success-light text-success-dark",
    "bg-info-light text-info-dark",
    "bg-accent-100 text-accent-700",
    "bg-surface-200 text-surface-700",
];

function Initials({ name }: { name: string }) {
    const initials = name.split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join("").toUpperCase();
    const tone = AVATAR_TONES[(name.charCodeAt(0) || 0) % AVATAR_TONES.length];
    return (
        <span aria-hidden className={clsx("inline-flex items-center justify-center w-6 h-6 rounded-full text-[10px] font-bold shrink-0", tone)}>
            {initials || "?"}
        </span>
    );
}

export function ServedBy({ name, compact }: { name?: string | null; compact?: boolean }) {
    if (!name) return <span className="text-xs text-surface-400" title="No staff member recorded — placed by the customer">—</span>;
    return (
        <span className={clsx("flex items-center gap-2 min-w-0", compact && "gap-1.5")} title={name}>
            <Initials name={name} />
            <span className="truncate text-xs text-surface-700">{name}</span>
        </span>
    );
}

// ── The table ────────────────────────────────────────────────────────────────

/** Column count — for colSpan on date-group headers and empty rows. */
export const ORDER_TABLE_COLUMNS = 8;

/**
 * STATUS and SERVED BY get their own columns only from a 1760px window. Below that their
 * content stacks (status under TOTAL, served-by under DATE) so the table fits
 * a laptop with the sidebar open without a sideways scroll.
 */
const FULL_ONLY = "hidden min-[1760px]:table-cell";

const HEAD = "px-3 min-[1760px]:px-4";

export function OrderTableHead() {
    return (
        <thead>
            <tr>
                <th className={HEAD}>Order</th>
                <th className={HEAD}>Channel</th>
                <th className={clsx(HEAD, FULL_ONLY)}>Status</th>
                <th className={clsx(HEAD, "w-full")}>Items</th>
                <th className={clsx(HEAD, "text-right")}>Total</th>
                <th className={clsx(HEAD, FULL_ONLY)}>Served by</th>
                <th className={HEAD}>Date</th>
                <th className={HEAD}><span className="sr-only">Actions</span></th>
            </tr>
        </thead>
    );
}

const CELL = "align-top py-3.5 px-3 min-[1760px]:px-4";

const linkStop = (e: React.MouseEvent) => e.stopPropagation();

interface OrderRowProps {
    order: Order;
    /** Row-level actions (payment link, stage menu). The chevron is the row's own. */
    actions?: ReactNode;
}

export function OrderRow({ order, actions }: OrderRowProps) {
    const navigate = useNavigate();
    const href = `/sales/orders/${order.id}`;
    const channel = orderChannel(order);
    const contact = customerContact(order);
    const items = order.items ?? [];
    const shown = items.slice(0, MAX_LINES);
    const date = orderRowDate(order);
    const parts = businessDateParts(date.iso);
    const name = order.customer_name || null;

    return (
        <tr
            onClick={(e) => {
                // A text selection is not a click-through.
                if (window.getSelection()?.toString()) return;
                if (e.metaKey || e.ctrlKey) { window.open(`${import.meta.env.BASE_URL}${href.slice(1)}`, "_blank"); return; }
                navigate(href);
            }}
            className="group cursor-pointer focus-within:bg-surface-50"
        >
            {/* ORDER — who, their number, the order number */}
            <td className={CELL}>
                <div className="w-[11.5rem] min-[1760px]:w-[14rem]">
                {/* Phone sits beside the name when there is room, and wraps under
                    it when there is not — the name is never cut to fit a number. */}
                <div className="flex flex-wrap items-baseline gap-x-2 min-w-0">
                    <span className={clsx("max-w-full truncate text-[13px] leading-5", name ? "font-semibold text-surface-900" : "text-surface-500")} title={name ?? "No customer name"}>
                        {name ?? "Guest"}
                    </span>
                    {contact && (
                        <span className="max-w-full truncate text-xs leading-5 tabular-nums text-surface-500" title={contact}>
                            {contact}
                        </span>
                    )}
                </div>
                {order.customer_number && (
                    <p className="mt-0.5 text-2xs leading-4 text-surface-500 truncate" title="Customer number">
                        ID: <span className="tabular-nums text-surface-600">{order.customer_number}</span>
                    </p>
                )}
                <p className="mt-0.5 text-2xs leading-4 text-surface-500 truncate">
                    Order#:{" "}
                    <Link
                        to={href}
                        onClick={linkStop}
                        className="font-mono font-semibold text-brand-700 hover:underline focus-visible:underline rounded-sm"
                    >
                        {order.order_number}
                    </Link>
                </p>
                {order.quotation_number && (
                    <p className="mt-0.5 text-2xs leading-4 text-surface-500 truncate">
                        Quote#: <span className="font-mono">{order.quotation_number}</span>
                    </p>
                )}
                </div>
            </td>

            {/* CHANNEL — door / one payment state / how it was paid */}
            <td className={CELL}>
                <div className="w-[8.5rem]">
                <span className="flex items-center gap-1.5 text-xs font-medium text-surface-700 leading-5">
                    <span className="text-surface-500"><ChannelIcon kind={channel.kind} /></span>
                    {channel.label}
                </span>
                <div className="mt-1 flex">
                    <PaymentBadge order={order} />
                </div>
                <div className="mt-1">
                    <PaymentMethodLine order={order} />
                </div>
                </div>
            </td>

            {/* STATUS — unchanged pill (stacks under TOTAL below 1760px) */}
            <td className={clsx(CELL, FULL_ONLY)}>
                <StatusBadge status={order.status} />
            </td>

            {/* ITEMS — count link, then each line. The fluid column: it takes
                what the fixed columns leave and its lines truncate (tooltip). */}
            <td className={clsx(CELL, "w-full max-w-0 min-w-[10rem]")}>
                <Link
                    to={href}
                    onClick={linkStop}
                    className="text-xs font-medium leading-5 text-brand-700 hover:underline focus-visible:underline rounded-sm"
                >
                    {itemCountLabel(items.length)}
                </Link>
                {shown.length > 0 && (
                    <ul className="mt-0.5 space-y-0.5">
                        {shown.map((item) => {
                            const { label, amount } = itemLineParts(item, order.currency_code);
                            // The name truncates; the amount never does.
                            return (
                                <li key={item.id} className="flex gap-1 text-2xs leading-4 text-surface-600" title={`${label}: ${amount}`}>
                                    <span className="truncate">{label}:</span>
                                    <span className="shrink-0 tabular-nums text-surface-700">{amount}</span>
                                </li>
                            );
                        })}
                        {items.length > MAX_LINES && (
                            <li
                                className="text-2xs leading-4 text-surface-500"
                                title={items.slice(MAX_LINES).map(i => itemLineText(i, order.currency_code)).join("\n")}
                            >
                                +{items.length - MAX_LINES} more
                            </li>
                        )}
                    </ul>
                )}
            </td>

            {/* TOTAL */}
            <td className={clsx(CELL, "text-right whitespace-nowrap")}>
                <span className="text-[13px] leading-5 font-bold tabular-nums text-surface-900">
                    {formatMoney(order.currency_code, order.total_amount)}
                </span>
                {order.is_international && (
                    <span className="ml-1 text-info-dark align-middle" title="International order">
                        <svg className="inline w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-label="International order"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z" /></svg>
                    </span>
                )}
                <div className="mt-1 flex justify-end min-[1760px]:hidden">
                    <StatusBadge status={order.status} />
                </div>
            </td>

            {/* SERVED BY (stacks under DATE below 1760px) */}
            <td className={clsx(CELL, FULL_ONLY)}>
                <div className="w-[9rem]"><ServedBy name={order.cashier_name} /></div>
            </td>

            {/* DATE — paid, else created; Nairobi */}
            <td className={clsx(CELL, "whitespace-nowrap")} title={date.title}>
                {parts ? (
                    <div className="text-2xs leading-4 text-surface-500">
                        <p className="text-xs leading-5 font-medium text-surface-800">{parts.day}</p>
                        <p>({parts.weekday})</p>
                        <p className="tabular-nums">
                            {parts.time}
                            {date.label && <span className="text-surface-400"> · {date.label}</span>}
                        </p>
                    </div>
                ) : (
                    <span className="text-xs text-surface-400">—</span>
                )}
                {order.cashier_name && (
                    <div className="mt-1.5 max-w-[9rem] min-[1760px]:hidden">
                        <ServedBy name={order.cashier_name} compact />
                    </div>
                )}
            </td>

            {/* ACTIONS */}
            <td className={clsx(CELL, "w-px")}>
                <div className="flex items-center gap-1 justify-end">
                    {actions}
                    <Link
                        to={href}
                        onClick={linkStop}
                        aria-label={`Open order ${order.order_number}`}
                        className="inline-flex items-center justify-center w-7 h-7 rounded-md text-surface-400 transition-colors hover:text-surface-700 hover:bg-surface-200 group-hover:text-surface-600"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </Link>
                </div>
            </td>
        </tr>
    );
}

/** Placeholder rows the same height as real ones — no jump when data lands. */
export function OrderRowSkeleton({ rows = 6 }: { rows?: number }) {
    return (
        <>
            {Array.from({ length: rows }, (_, i) => (
                <tr key={i} aria-hidden className="hover:bg-transparent">
                    <td className={CELL}>
                        <div className="w-[11.5rem] min-[1760px]:w-[14rem]">
                            <div className="skeleton h-4 w-36" />
                            <div className="skeleton h-3 w-24 mt-1.5" />
                            <div className="skeleton h-3 w-32 mt-1.5" />
                        </div>
                    </td>
                    <td className={CELL}>
                        <div className="w-[8.5rem]">
                            <div className="skeleton h-4 w-16" />
                            <div className="skeleton h-4 w-14 mt-1.5 rounded-full" />
                            <div className="skeleton h-3 w-20 mt-1.5" />
                        </div>
                    </td>
                    <td className={clsx(CELL, FULL_ONLY)}><div className="skeleton h-5 w-20 rounded-full" /></td>
                    <td className={clsx(CELL, "w-full max-w-0 min-w-[10rem]")}>
                        <div className="skeleton h-4 w-12" />
                        <div className="skeleton h-3 w-3/4 mt-1.5" />
                    </td>
                    <td className={CELL}><div className="skeleton h-4 w-24 ml-auto" /></td>
                    <td className={clsx(CELL, FULL_ONLY)}><div className="w-[9rem]"><div className="skeleton h-6 w-24 rounded-full" /></div></td>
                    <td className={CELL}>
                        <div className="skeleton h-4 w-28" />
                        <div className="skeleton h-3 w-16 mt-1.5" />
                        <div className="skeleton h-3 w-10 mt-1.5" />
                    </td>
                    <td className={CELL}><div className="skeleton h-6 w-14 ml-auto" /></td>
                </tr>
            ))}
        </>
    );
}

// ── Card (below xl) ──────────────────────────────────────────────────────────

/**
 * The same row below xl — a phone, a tablet, a small laptop with the sidebar
 * open (as little as 450–700px of content): a table there scrolls sideways,
 * so each order is a card with the same facts in the same order of importance.
 */
export function OrderCard({ order }: { order: Order }) {
    const navigate = useNavigate();
    const cfg = statusConfig(order.status);
    const channel = orderChannel(order);
    const contact = customerContact(order);
    const items = order.items ?? [];
    const date = orderRowDate(order);
    const parts = businessDateParts(date.iso);

    return (
        <button
            type="button"
            onClick={() => navigate(`/sales/orders/${order.id}`)}
            className="w-full text-left px-3.5 py-3 transition-colors hover:bg-surface-50 active:bg-surface-100 focus-visible:bg-surface-50"
        >
            {/* Line 1 — who it's for, and where it stands */}
            <div className="flex items-start gap-2">
                <span className={clsx("mt-1.5 w-1.5 h-1.5 rounded-full shrink-0", cfg.dot)} />
                <p className="flex-1 min-w-0 text-[13.5px] leading-snug truncate">
                    <span className="font-bold text-surface-900">{order.customer_name || "Guest"}</span>
                    {contact && <span className="ml-1.5 text-xs text-surface-500 tabular-nums">{contact}</span>}
                </p>
                <span className={clsx("badge shrink-0", cfg.badge)}>{cfg.label}</span>
            </div>

            {/* Line 2 — door, money state, method */}
            <div className="mt-1 pl-3.5 flex items-center gap-2 min-w-0 text-[12.5px] text-surface-600">
                <span className="flex items-center gap-1 shrink-0">
                    <span className="text-surface-500"><ChannelIcon kind={channel.kind} /></span>
                    {channel.label}
                </span>
                <PaymentBadge order={order} />
                <span className="min-w-0"><PaymentMethodLine order={order} /></span>
            </div>

            {/* Line 3 — the reference, the size, the money */}
            <div className="mt-1 pl-3.5 flex items-center gap-2 text-2xs">
                <span className="min-w-0 font-mono font-bold text-brand-700 truncate">{order.order_number}</span>
                <span className="text-surface-500 shrink-0">{itemCountLabel(items.length)}</span>
                <span className="ml-auto shrink-0 tabular-nums font-bold text-surface-900 text-xs">
                    {formatMoney(order.currency_code, order.total_amount)}
                </span>
            </div>

            {/* Line 4 — when, and who served */}
            {(parts || order.cashier_name) && (
                <p className="mt-1 pl-3.5 text-2xs text-surface-500 truncate">
                    {parts && <>{date.label ?? "Paid"} {parts.day} ({parts.weekday}) {parts.time}</>}
                    {order.cashier_name && <span>{parts ? " · " : ""}{order.cashier_name}</span>}
                </p>
            )}
        </button>
    );
}

export function OrderCardSkeleton({ rows = 5 }: { rows?: number }) {
    return (
        <div className="card divide-y divide-line" aria-hidden>
            {Array.from({ length: rows }, (_, i) => (
                <div key={i} className="px-3.5 py-3">
                    <div className="skeleton h-4 w-2/3" />
                    <div className="skeleton h-3 w-1/2 mt-2" />
                    <div className="skeleton h-3 w-5/6 mt-2" />
                </div>
            ))}
        </div>
    );
}
