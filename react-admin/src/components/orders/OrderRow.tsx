// src/components/orders/OrderRow.tsx
//
// THE order list row — one component behind Till Sales, Web Orders, Chat
// Orders and Quoted Sales (all four are OrdersListPage with a channel), so a
// row can never read one way on one page and another way on the next.
//
// Layout (owner's mock 2026-10-26, refined the same day after he saw it live):
//
//   ORDER | CHANNEL | ITEMS | TOTAL | STATUS | SERVED BY | DATE | ACTIONS
//
//   ORDER      name (the row's headline) / phone / ID / Order#
//   CHANNEL    the door, then ONE payment chip: state · method ("Paid · I&M Paybill")
//   ITEMS      "3 items" link, then receipt lines "2 × Devai ······ 800.00"
//   TOTAL      right-aligned, bold, tabular
//   STATUS     where it stands, in words (orderStage.ts) — never the raw status
//   SERVED BY  avatar + name
//   DATE       when it was PAID (latest settled payment), Nairobi time;
//              unpaid → when it was created, labelled so
//
// A CSS grid with explicit column tracks per breakpoint, not an auto-layout
// table: an auto table hands every spare pixel to one fluid column, which is
// the empty gap the owner saw between ITEMS and TOTAL. Here ITEMS has a ceiling
// and the spare width is shared evenly between columns (justify-between).
// Every row uses the same tracks, so columns line up row to row.
//
// The table never scrolls sideways with the 256px sidebar open:
//   ≥1760  all eight columns
//   ≥1440  STATUS stacks under TOTAL
//   ≥1280  STATUS under TOTAL and SERVED BY under DATE
//   <1280  OrderCard (the page switches at xl)
//
// Every value here comes from the API row as served — masking (phones) is the
// server's, and nothing on this row is summed into a figure.

import type { ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import type { Order, OrderItem } from "@/api/orders";
import { BUSINESS_TIME_ZONE } from "@/lib/businessDate";
import { orderStage, resolvePaymentState, TONE_CHIP, TONE_DOT, type StageTone } from "./orderStage";

// ── Stored statuses (the filter's vocabulary) ────────────────────────────────

// The stored order statuses and their names, as the Status FILTER offers them.
// Rows no longer print these — they print orderStage(), which reads status
// together with payment and delivery (owner, 2026-10-26: "Confirmed" says
// nothing useful about a sale that is paid and cleared).
//
// `pending` is deliberately "Unconfirmed", not "Pending Payment". It is an
// unconfirmed cart a customer built and abandoned, it is NOT income, and it is
// excluded from the sales report. Calling it "Pending Payment" implied the only
// missing thing was money, which is how 98 abandoned carts came to be counted
// as KES 1.1m of revenue.
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

/** The date the row shows: paid (latest settled payment), else created — and which it is. */
export function orderRowDate(order: Order): { iso: string; label: string; title: string } {
    const created = businessDateParts(order.created_at);
    const createdText = created ? `Created ${created.day}, ${created.time}` : "";
    const paidAt = order.payment_summary?.paid_at ?? null;
    const paid = businessDateParts(paidAt);
    if (paidAt && paid) {
        const label = order.payment_summary?.state === "paid" ? "Paid" : "Last payment";
        return { iso: paidAt, label, title: `${label} ${paid.day}, ${paid.time} (Nairobi) · ${createdText}` };
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

function ChannelIcon({ kind, className = "w-3.5 h-3.5" }: { kind: ChannelKind; className?: string }) {
    const p = { className: clsx(className, "shrink-0"), fill: "none", viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const, "aria-hidden": true };
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

/** The door as one unit: a small icon tile and the channel's name. */
function ChannelLabel({ order }: { order: Order }) {
    const channel = orderChannel(order);
    return (
        <span className="flex items-baseline gap-2 min-w-0">
            <span className="self-center inline-flex items-center justify-center w-5 h-5 rounded-md bg-surface-100 text-surface-600 ring-1 ring-inset ring-surface-900/5 shrink-0">
                <ChannelIcon kind={channel.kind} className="w-3 h-3" />
            </span>
            <span className="truncate text-[13px] leading-6 font-semibold text-surface-800">{channel.label}</span>
        </span>
    );
}

// ── Payment ──────────────────────────────────────────────────────────────────

/** The payment state's words and tone — the state is the server's (listPaymentSummary). */
const PAYMENT_STATE: Record<string, { label: string; tone: StageTone }> = {
    paid:               { label: "Paid",              tone: "done"   },
    partial:            { label: "Partially paid",    tone: "action" },
    unpaid:             { label: "Unpaid",            tone: "info"   },
    awaiting_approval:  { label: "Awaiting approval", tone: "info"   },
    refunded:           { label: "Refunded",          tone: "info"   },
    partially_refunded: { label: "Partly refunded",   tone: "info"   },
    failed:             { label: "Payment failed",    tone: "info"   },
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
    const p = { className: "w-3.5 h-3.5 shrink-0 opacity-80", fill: "none", viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.9, strokeLinecap: "round" as const, strokeLinejoin: "round" as const, "aria-hidden": true };
    switch (methodFamily(code)) {
        case "cash":   return <svg {...p}><rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /></svg>;
        case "bank":   return <svg {...p}><path d="M3 10l9-6 9 6" /><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18" /></svg>;
        case "mobile": return <svg {...p}><rect x="6" y="2" width="12" height="20" rx="2" /><path d="M11 18h2" /></svg>;
        case "card":   return <svg {...p}><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></svg>;
        case "remit":  return <svg {...p}><path d="M4 12h14M13 6l6 6-6 6" /></svg>;
        default:       return <svg {...p}><path d="M3 7h15a3 3 0 013 3v7a3 3 0 01-3 3H6a3 3 0 01-3-3V7z" /><path d="M3 7l2-3h11l2 3M16 13.5h.01" /></svg>;
    }
}

/** "I&M Paybill" → "I&M" when two methods share a chip. */
const shortMethod = (name: string) => name.split(/\s+/)[0] || name;

/** The methods that paid (or await approval), as the server listed them. */
function paymentMethods(order: Order) {
    return order.payment_summary?.methods
        ?? (order.payment_method
            ? [{ code: order.payment_method, name: METHOD_FALLBACK[order.payment_method] ?? order.payment_method, amount: NaN, currency_code: order.currency_code }]
            : []);
}

/**
 * The chip both the payment state and the stage use: a leading icon or dot,
 * then its parts ("Partially paid", "M-Pesa"). On one line the parts read
 * "Partially paid · M-Pesa"; when the column is too narrow the second part
 * wraps under the first instead of being cut ("M-P…"), and its separator dot
 * — which sits in the gap before it — is clipped away at the start of the line.
 */
function Chip({ tone, lead, parts, title }: { tone: StageTone; lead: ReactNode; parts: string[]; title?: string }) {
    return (
        <span
            className={clsx("inline-flex max-w-full items-baseline gap-1.5 rounded-xl px-2 py-[3px] text-xs leading-[18px] font-medium ring-1 ring-inset", TONE_CHIP[tone])}
            title={title}
        >
            {lead}
            <span className="flex min-w-0 flex-wrap items-baseline gap-x-[0.7em] overflow-hidden">
                {parts.map((part, i) => (
                    <span
                        key={i}
                        className={clsx(
                            "relative flex min-w-0 max-w-full",
                            i > 0 && "before:content-['·'] before:absolute before:right-full before:mr-[0.22em]",
                        )}
                    >
                        <span className="truncate">{part}</span>
                    </span>
                ))}
            </span>
        </span>
    );
}

const chipDot = (tone: StageTone) => (
    <span aria-hidden className={clsx("self-start mt-[6px] w-1.5 h-1.5 rounded-full shrink-0", TONE_DOT[tone])} />
);

/**
 * ONE chip for money: state and method together — "Paid · I&M Paybill",
 * "Paid · I&M + Cash", "Partially paid · M-Pesa", "Unpaid". The method's icon
 * leads the chip; with no method a dot does. The tooltip lists each method
 * with its amount.
 */
export function PaymentChip({ order }: { order: Order }) {
    const state = resolvePaymentState(order);
    if (!state) return null;
    const meta = PAYMENT_STATE[state] ?? { label: state, tone: "info" as StageTone };
    // A method is "how it was paid" — say it only when money moved or awaits approval.
    const methods = state === "unpaid" ? [] : paymentMethods(order);

    const how = methods.length === 0 ? null
        : methods.length === 1 ? methods[0].name
        : methods.length === 2 ? `${shortMethod(methods[0].name)} + ${shortMethod(methods[1].name)}`
        : `${methods.length} methods`;
    const lines = methods.map(m => Number.isFinite(m.amount) ? `${m.name} — ${formatMoney(m.currency_code, m.amount)}` : m.name);

    return (
        <Chip
            tone={meta.tone}
            lead={methods.length > 0
                ? <span className="self-start mt-[2px] inline-flex"><MethodIcon code={methods[0].code} /></span>
                : chipDot(meta.tone)}
            parts={how ? [meta.label, how] : [meta.label]}
            title={[meta.label, ...lines].join("\n")}
        />
    );
}

// ── Stage ────────────────────────────────────────────────────────────────────

/** Where the order stands — orderStage() in the same chip ("Paid · To dispatch" may wrap). */
export function StagePill({ order }: { order: Order }) {
    const stage = orderStage(order);
    return (
        <Chip
            tone={stage.tone}
            lead={chipDot(stage.tone)}
            parts={stage.label.split(" · ")}
            title={`Order status: ${statusConfig(order.status).label}`}
        />
    );
}

// ── Items ────────────────────────────────────────────────────────────────────

const MAX_LINES = 3;

/** The parts of one receipt line: quantity (only when > 1), name (+ variant when it adds something), amount. */
export function itemLineParts(item: OrderItem, currency: string) {
    const name = item.product_name || item.sku || "Item";
    const variant = item.variant_name?.trim();
    const showVariant = variant && variant.toLowerCase() !== name.trim().toLowerCase();
    const qty = Number(item.quantity) || 0;
    const amount = item.total_price ?? item.subtotal ?? (Number(item.unit_price) || 0) * qty;
    const fullName = `${name}${showVariant ? ` (${variant})` : ""}`;
    return {
        qty: qty > 1 ? `${qty} ×` : null,
        name: fullName,
        /** The figure alone — the row's TOTAL carries the currency, as a receipt does. */
        figure: money(amount),
        /** With the currency, for tooltips. */
        amount: formatMoney(currency, amount),
    };
}

export function itemLineText(item: OrderItem, currency: string): string {
    const { qty, name, amount } = itemLineParts(item, currency);
    return `${qty ? `${qty} ` : ""}${name}: ${amount}`;
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

function Initials({ name, small }: { name: string; small?: boolean }) {
    const initials = name.split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join("").toUpperCase();
    const tone = AVATAR_TONES[(name.charCodeAt(0) || 0) % AVATAR_TONES.length];
    return (
        <span
            aria-hidden
            className={clsx(
                // Top-aligned and centred on the name's FIRST line, which may wrap to two.
                "self-start inline-flex items-center justify-center rounded-full font-bold shrink-0",
                small ? "w-5 h-5 text-[9px] -mt-px" : "w-6 h-6 text-[10px] -mt-0.5",
                tone,
            )}
        >
            {initials || "?"}
        </span>
    );
}

export function ServedBy({ name, compact }: { name?: string | null; compact?: boolean }) {
    if (!name) {
        return (
            <span className={clsx("text-surface-400", compact ? "text-xs" : "text-[13px] leading-6")} title="No staff member recorded — placed by the customer">
                —
            </span>
        );
    }
    return (
        <span className={clsx("flex items-baseline min-w-0", compact ? "gap-1.5" : "gap-2")} title={`Served by ${name}`}>
            <Initials name={name} small={compact} />
            <span className={clsx("min-w-0 line-clamp-2 !overflow-clip break-words text-surface-700", compact ? "text-xs leading-[18px]" : "text-[13px] leading-5")}>{name}</span>
        </span>
    );
}

// ── The grid ─────────────────────────────────────────────────────────────────

/**
 * One set of column tracks for the header, every row and the skeleton. Widths
 * were measured against content at 12/13/15px (longest month, "KES 100,000.00")
 * and sum to fit the window minus the 256px sidebar, page gutters and a
 * scrollbar at each breakpoint's low end: 1280 → 945px, 1440 → 1105px,
 * 1760 → 1409px. ITEMS gets the largest share (≥224px at 1280, ≥248px at 1440);
 * chips, item names and served-by names wrap to a second line rather than
 * being cut, so the narrower columns lose no words.
 * ITEMS (and, from 1760, ORDER) stretch to a ceiling; what is left is shared
 * evenly by the gaps, so no single gap opens up.
 */
const GRID = clsx(
    "grid justify-between gap-x-3 px-3",
    "grid-cols-[160px_140px_minmax(224px,380px)_132px_120px_84px]",
    "min-[1440px]:grid-cols-[172px_140px_minmax(248px,440px)_132px_112px_120px_84px]",
    "min-[1760px]:grid-cols-[minmax(184px,260px)_150px_minmax(260px,460px)_132px_140px_132px_124px_84px] min-[1760px]:gap-x-5 min-[1760px]:px-4",
);

/** Own columns from these widths; below them the content stacks (see the header note). */
const STATUS_COL = "hidden min-[1760px]:block";
const SERVED_COL = "hidden min-[1440px]:block";
const STATUS_TUCKED = "min-[1760px]:hidden";
const SERVED_TUCKED = "min-[1440px]:hidden";

export function OrderTableHead() {
    const th = "text-2xs font-semibold uppercase tracking-[0.06em] text-surface-500 whitespace-nowrap";
    return (
        <div role="row" className={clsx(GRID, "items-center py-2.5 bg-surface-100 border-b border-line")}>
            <span role="columnheader" className={th}>Order</span>
            <span role="columnheader" className={th}>Channel</span>
            <span role="columnheader" className={th}>Items</span>
            <span role="columnheader" className={clsx(th, "text-right")}>Total</span>
            <span role="columnheader" className={clsx(th, STATUS_COL)}>Status</span>
            <span role="columnheader" className={clsx(th, SERVED_COL)}>Served by</span>
            <span role="columnheader" className={th}>Date</span>
            <span role="columnheader"><span className="sr-only">Actions</span></span>
        </div>
    );
}

/** The table shell: header + body, with table semantics on the grid. */
export function OrderTable({ busy, children }: { busy?: boolean; children: ReactNode }) {
    return (
        <div role="table" aria-label="Orders" aria-busy={busy || undefined} className="text-sm text-surface-700">
            <div role="rowgroup"><OrderTableHead /></div>
            <div role="rowgroup">{children}</div>
        </div>
    );
}

/**
 * "TODAY" — the date break between groups, in the header's type. No count: the
 * groups re-partition one page of results, so a count would be the page's, not
 * the day's.
 */
export function OrderGroupHeader({ label }: { label: string }) {
    return (
        <div role="row" className="px-3 min-[1760px]:px-4 py-2 bg-surface-50 border-b border-line">
            <span role="cell" className="text-2xs font-semibold uppercase tracking-[0.06em] text-surface-600">
                {label}
            </span>
        </div>
    );
}

/**
 * Cells align on their FIRST BASELINE (items-baseline), so the name, the
 * channel, "2 items", the total, the served-by name and the date all sit on one
 * line across the row whatever their size. Every first line is a 24px box.
 */
const ROW = clsx(
    GRID,
    "items-baseline py-4 border-b border-line last:border-b-0",
    "cursor-pointer transition-colors duration-100 hover:bg-surface-50 focus-within:bg-surface-50",
);

const linkStop = (e: React.MouseEvent) => e.stopPropagation();

interface OrderRowProps {
    order: Order;
    /** Row-level actions (payment link, stage menu). The chevron is the row's own. */
    actions?: ReactNode;
}

export function OrderRow({ order, actions }: OrderRowProps) {
    const navigate = useNavigate();
    const href = `/sales/orders/${order.id}`;
    const contact = customerContact(order);
    const items = order.items ?? [];
    const shown = items.slice(0, MAX_LINES);
    const date = orderRowDate(order);
    const parts = businessDateParts(date.iso);
    const name = order.customer_name || null;
    const currency = order.currency_code || "KES";

    return (
        <div
            role="row"
            onClick={(e) => {
                // A text selection is not a click-through.
                if (window.getSelection()?.toString()) return;
                if (e.metaKey || e.ctrlKey) { window.open(`${import.meta.env.BASE_URL}${href.slice(1)}`, "_blank"); return; }
                navigate(href);
            }}
            className={clsx(ROW, "group")}
        >
            {/* ORDER — who (the headline), then quieter lines: phone, ID, Order# */}
            <div role="cell" className="min-w-0">
                <p
                    className={clsx("truncate text-[15px] leading-6", name ? "font-semibold text-surface-900" : "font-medium text-surface-500")}
                    title={name ?? "No customer name"}
                >
                    {name ?? "Guest"}
                </p>
                {contact && (
                    <p className="truncate text-[13px] leading-5 tabular-nums text-surface-600" title={contact}>{contact}</p>
                )}
                <div className="mt-1 space-y-px text-xs leading-[18px] text-surface-400">
                    {order.customer_number && (
                        <p className="truncate" title="Customer number">
                            ID <span className="tabular-nums text-surface-600">{order.customer_number}</span>
                        </p>
                    )}
                    <p className="truncate">
                        Order#{" "}
                        <Link
                            to={href}
                            onClick={linkStop}
                            className="font-mono text-[11.5px] font-medium text-brand-700 rounded-sm hover:underline focus-visible:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                        >
                            {order.order_number}
                        </Link>
                    </p>
                    {order.quotation_number && (
                        <p className="truncate">
                            Quote# <span className="font-mono text-[11.5px] text-surface-600">{order.quotation_number}</span>
                        </p>
                    )}
                </div>
            </div>

            {/* CHANNEL — the door, then one chip for money */}
            <div role="cell" className="min-w-0">
                <ChannelLabel order={order} />
                <div className="mt-1.5 flex min-w-0">
                    <PaymentChip order={order} />
                </div>
            </div>

            {/* ITEMS — count link, then receipt lines (3 shown + "+N more") */}
            <div role="cell" className="min-w-0">
                <Link
                    to={href}
                    onClick={linkStop}
                    className="text-[13px] leading-6 font-semibold text-brand-700 rounded-sm hover:underline focus-visible:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                >
                    {itemCountLabel(items.length)}
                </Link>
                {shown.length > 0 && (
                    <ul className="mt-1 space-y-1">
                        {shown.map((item) => {
                            const line = itemLineParts(item, currency);
                            // The name truncates; the quantity and the amount never do.
                            return (
                                <li
                                    key={item.id}
                                    className="flex items-baseline gap-1.5 text-xs leading-[18px]"
                                    // Name wraps to two lines at most; the amount stays on the first.
                                    title={itemLineText(item, currency)}
                                >
                                    {line.qty && <span className="shrink-0 tabular-nums text-surface-400">{line.qty}</span>}
                                    <span className="min-w-0 line-clamp-2 !overflow-clip break-words text-surface-700">{line.name}</span>
                                    <span aria-hidden className="flex-1 min-w-3 border-b border-dotted border-surface-300 -translate-y-[3px]" />
                                    <span className="shrink-0 tabular-nums text-surface-800">{line.figure}</span>
                                </li>
                            );
                        })}
                        {items.length > MAX_LINES && (
                            <li
                                className="text-xs leading-[18px] text-surface-500"
                                title={items.slice(MAX_LINES).map(i => itemLineText(i, currency)).join("\n")}
                            >
                                +{items.length - MAX_LINES} more
                            </li>
                        )}
                    </ul>
                )}
            </div>

            {/* TOTAL — and, below 1760px, where it stands */}
            <div role="cell" className="min-w-0 text-right whitespace-nowrap">
                <span className="text-[15px] leading-6 tabular-nums">
                    <span className="mr-1 text-xs font-medium text-surface-500">{currency}</span>
                    <span className="font-bold text-surface-900">{money(order.total_amount)}</span>
                </span>
                {order.is_international && (
                    <span className="ml-1 text-info-dark" title="International order">
                        <svg className="inline w-3.5 h-3.5 -mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-label="International order"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z" /></svg>
                    </span>
                )}
                <div className={clsx("mt-1.5 flex justify-end", STATUS_TUCKED)}>
                    <StagePill order={order} />
                </div>
            </div>

            {/* STATUS (own column from 1760px) */}
            <div role="cell" className={clsx("min-w-0", STATUS_COL)}>
                <StagePill order={order} />
            </div>

            {/* SERVED BY (own column from 1440px) */}
            <div role="cell" className={clsx("min-w-0", SERVED_COL)}>
                <ServedBy name={order.cashier_name} />
            </div>

            {/* DATE — paid, else created; Nairobi. Served-by tucks under it below 1440px. */}
            <div role="cell" className="min-w-0 whitespace-nowrap" title={date.title}>
                {parts ? (
                    <>
                        <p className="text-[13px] leading-6 font-medium text-surface-800">{parts.day}</p>
                        <p className="text-xs leading-[18px] text-surface-500">{parts.weekday}</p>
                        <p className="text-xs leading-[18px] tabular-nums text-surface-500">
                            {parts.time}<span className="text-surface-400"> · {date.label}</span>
                        </p>
                    </>
                ) : (
                    <span className="text-[13px] leading-6 text-surface-400">—</span>
                )}
                {order.cashier_name && (
                    <div className={clsx("mt-2 max-w-full", SERVED_TUCKED)}>
                        <ServedBy name={order.cashier_name} compact />
                    </div>
                )}
            </div>

            {/* ACTIONS — top-aligned, centred on the first line */}
            <div role="cell" className="self-start -mt-0.5 flex items-center justify-end">
                {actions}
                <Link
                    to={href}
                    onClick={linkStop}
                    aria-label={`Open order ${order.order_number}`}
                    className="inline-flex items-center justify-center w-7 h-7 rounded-md text-surface-400 transition-colors hover:text-surface-700 hover:bg-surface-200 group-hover:text-surface-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </Link>
            </div>
        </div>
    );
}

/** Placeholder rows on the same tracks as real ones — no jump when data lands. */
export function OrderRowSkeleton({ rows = 6 }: { rows?: number }) {
    return (
        <>
            {Array.from({ length: rows }, (_, i) => (
                <div key={i} aria-hidden className={clsx(GRID, "items-start py-4 border-b border-line last:border-b-0")}>
                    <div>
                        <div className="skeleton h-4 w-32 mt-1" />
                        <div className="skeleton h-3 w-24 mt-2" />
                        <div className="skeleton h-3 w-28 mt-2.5" />
                    </div>
                    <div>
                        <div className="skeleton h-4 w-16 mt-1" />
                        <div className="skeleton h-6 w-32 mt-2 rounded-full" />
                    </div>
                    <div>
                        <div className="skeleton h-4 w-14 mt-1" />
                        <div className="skeleton h-3 w-full mt-2.5" />
                        <div className="skeleton h-3 w-4/5 mt-2" />
                    </div>
                    <div>
                        <div className="skeleton h-4 w-24 mt-1 ml-auto" />
                        <div className={clsx("skeleton h-6 w-20 mt-2 ml-auto rounded-full", STATUS_TUCKED)} />
                    </div>
                    <div className={STATUS_COL}><div className="skeleton h-6 w-24 rounded-full" /></div>
                    <div className={SERVED_COL}><div className="skeleton h-6 w-28 rounded-full" /></div>
                    <div>
                        <div className="skeleton h-4 w-28 mt-1" />
                        <div className="skeleton h-3 w-16 mt-2" />
                        <div className="skeleton h-3 w-20 mt-2" />
                    </div>
                    <div><div className="skeleton h-7 w-14 ml-auto" /></div>
                </div>
            ))}
        </>
    );
}

// ── Card (below xl) ──────────────────────────────────────────────────────────

/**
 * The same row below xl — a phone, a tablet, a small laptop with the sidebar
 * open (as little as 450–700px of content): a table there scrolls sideways,
 * so each order is a card with the same facts in the same order of importance,
 * and the same words as the row (stage pill, payment chip).
 */
export function OrderCard({ order }: { order: Order }) {
    const navigate = useNavigate();
    const stage = orderStage(order);
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
                <span className={clsx("mt-2 w-1.5 h-1.5 rounded-full shrink-0", TONE_DOT[stage.tone])} />
                <p className="flex-1 min-w-0 text-[15px] leading-6 truncate">
                    <span className="font-semibold text-surface-900">{order.customer_name || "Guest"}</span>
                    {contact && <span className="ml-1.5 text-xs text-surface-500 tabular-nums">{contact}</span>}
                </p>
                <span className="shrink-0"><StagePill order={order} /></span>
            </div>

            {/* Line 2 — door and money */}
            <div className="mt-1 pl-3.5 flex items-center gap-2 min-w-0">
                <span className="shrink-0"><ChannelLabel order={order} /></span>
                <span className="min-w-0 flex"><PaymentChip order={order} /></span>
            </div>

            {/* Line 3 — the reference, the size, the money */}
            <div className="mt-1.5 pl-3.5 flex items-baseline gap-2 text-xs">
                <span className="min-w-0 font-mono font-medium text-brand-700 truncate">{order.order_number}</span>
                <span className="text-surface-500 shrink-0">{itemCountLabel(items.length)}</span>
                <span className="ml-auto shrink-0 tabular-nums font-bold text-surface-900 text-[13px]">
                    {formatMoney(order.currency_code, order.total_amount)}
                </span>
            </div>

            {/* Line 4 — when, and who served */}
            {(parts || order.cashier_name) && (
                <p className="mt-1 pl-3.5 text-xs text-surface-500 truncate">
                    {parts && <>{date.label} {parts.day}, {parts.weekday} {parts.time}</>}
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
