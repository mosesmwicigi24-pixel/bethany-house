// src/components/orders/orderStage.ts
//
// What the order list says about where an order STANDS — one short label and a
// tone — derived for display only from facts already on the list row:
//
//   status          the stored order status (OrderStatusMachine's vocabulary)
//   payment state   payment_summary.state (OrderController::listPaymentSummary),
//                   or the order's payment_status on an older API build
//   sales_bucket    till | web | chat | quoted
//   delivery_type   'delivery' | 'pickup' — set by storefront checkout and by
//                   staff-raised orders (OrderController::store); null on till,
//                   chat and quotation conversions
//   dispatched_at   the till's hand-over authorization (PosController::authorizeDispatch)
//
// Nothing here changes a status, a payment or a report; it only words them.
//
// Owner, 2026-10-26: "Confirmed" is a poor label for a sale that is paid and
// cleared. The stored status stays `confirmed`; the row now says what that
// means for the person reading the list.
//
// Three deliberate limits — the row never claims more than the record proves:
//
//   • "To dispatch" only where the order records a delivery (delivery_type
//     'delivery'). Shipment records are not loaded on the list and are almost
//     never created (1 in production on 2026-10-26); chat and quoted orders
//     carry no delivery_type. Anything uncertain says "Paid", not "To dispatch".
//
//   • A paid, confirmed till sale says "Paid", not "Collected". The hub has a
//     hand-over control: until someone authorizes dispatch, the order page shows
//     "Awaiting dispatch authorization" for that same sale, and the list must
//     not contradict it. Once authorized (dispatched_at set, status completed)
//     the row says "Collected".
//
//   • `pending` stays "Unconfirmed", never "Awaiting payment". A pending order
//     is an unconfirmed cart that is not income (see STATUS_CONFIG in
//     OrderRow.tsx — "Pending Payment" is how 98 abandoned carts were once read
//     as KES 1.1m of sales). "Awaiting payment" is said only of a CONFIRMED
//     order whose money has not come, which is a real receivable.
//
// The console has no unit-test runner; the mapping is a pure function so it can
// be exercised directly (see the PR for the cases run against it).

import type { OrderListPaymentState } from "@/api/orders";

/** green = done · amber = needs someone to act · neutral = information · red = stopped */
export type StageTone = "done" | "action" | "info" | "stopped";

export interface Stage {
    label: string;
    tone: StageTone;
}

/** The facts the stage reads — a structural subset of Order, so tests need no fixtures. */
export interface StageInput {
    status: string;
    payment_status?: string | null;
    payment_summary?: { state: OrderListPaymentState | null } | null;
    sales_bucket?: string | null;
    order_type?: string | null;
    delivery_type?: string | null;
    dispatched_at?: string | null;
}

const DEAD = ["cancelled", "voided"];

/**
 * The ONE payment state for the row: the server's derived state, or — on an
 * older API build with no payment_summary — what payment_status says.
 * Null means there is nothing honest to say (a dead order holding no money).
 */
export function resolvePaymentState(o: StageInput): OrderListPaymentState | null {
    if (o.payment_summary !== undefined && o.payment_summary !== null) return o.payment_summary.state;
    const ps = o.payment_status ?? "";
    if (DEAD.includes(o.status) && ps !== "paid") return null;
    if (ps === "paid") return "paid";
    if (ps === "partial" || ps === "deposit") return "partial";
    if (ps === "refunded") return "refunded";
    if (ps === "partially_refunded") return "partially_refunded";
    if (ps === "pending_approval") return "awaiting_approval";
    if (ps === "failed") return "failed";
    return "unpaid";
}

function isTill(o: StageInput): boolean {
    return o.sales_bucket ? o.sales_bucket === "till" : o.order_type === "pos";
}

/** Where the order stands, in the reader's words. Display only. */
export function orderStage(o: StageInput): Stage {
    const s = o.status;
    const pay = resolvePaymentState(o);
    const dispatched = !!o.dispatched_at;

    // Stopped or reversed — nothing else about it matters on a list.
    if (s === "cancelled") return { label: "Cancelled", tone: "stopped" };
    if (s === "voided")    return { label: "Voided",    tone: "stopped" };
    if (s === "refunded" || pay === "refunded") return { label: "Refunded", tone: "info" };

    // Finished, or out of the door.
    if (s === "completed") return { label: isTill(o) && dispatched ? "Collected" : "Completed", tone: "done" };
    if (s === "delivered") return { label: "Delivered", tone: "done" };
    if (s === "shipped")   return { label: "Dispatched", tone: "info" };

    if (pay === "partially_refunded") return { label: "Part-refunded", tone: "info" };

    // Not yet accepted. Unconfirmed is not income — see the header note.
    if (s === "pending" || s === "pending_payment") {
        if (pay === "awaiting_approval") return { label: "Awaiting approval", tone: "action" };
        if (pay === "partial")           return { label: "Part-paid",         tone: "action" };
        if (pay === "paid")              return { label: "Paid · To confirm", tone: "action" };
        return { label: "Unconfirmed", tone: "info" };
    }

    // Accepted (confirmed / processing / a legacy `paid` status): money first.
    if (pay === "awaiting_approval") return { label: "Awaiting approval", tone: "action" };
    if (pay === "failed")            return { label: "Payment failed",    tone: "action" };
    if (pay === "partial")           return { label: "Part-paid",         tone: "action" };
    if (pay !== "paid")              return { label: "Awaiting payment",  tone: "action" };

    // Paid. Say what is left only when the order records it.
    if (o.delivery_type === "delivery" && !dispatched) return { label: "Paid · To dispatch", tone: "action" };
    if (s === "processing") return { label: "Paid · In progress", tone: "info" };
    return { label: "Paid", tone: "done" };
}

/** Chip classes per tone — one vocabulary for the payment chip and the stage pill. */
export const TONE_CHIP: Record<StageTone, string> = {
    done:    "bg-success-50 text-success-700 ring-success-600/15",
    action:  "bg-amber-50 text-amber-800 ring-amber-600/20",
    info:    "bg-surface-100 text-surface-700 ring-surface-900/10",
    stopped: "bg-danger-50 text-danger-700 ring-danger-600/15",
};

export const TONE_DOT: Record<StageTone, string> = {
    done:    "bg-success-vivid",
    action:  "bg-amber",
    info:    "bg-surface-400",
    stopped: "bg-danger",
};
