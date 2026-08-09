// src/api/reports.ts
import { get, post, del, tokenStorage } from "@/api/client";
import dayjs from "dayjs";
import quarterOfYear from "dayjs/plugin/quarterOfYear";
dayjs.extend(quarterOfYear);

const BASE = "/v1/admin/reports";

export interface DateRangeParams {
    start_date?: string;
    end_date?: string;
    outlet_id?: number;
    currency?: string;
}

// ── Sales ─────────────────────────────────────────────────────────────────────

export const reportsApi = {
    // Dashboard
    dashboardKpis: (days = 30) =>
        get<any>(`${BASE}/dashboard/kpis`, { params: { days } }),

    // Executive dashboard — the MetricEngine-backed command centre.
    executive: (period: string, outletId?: number) =>
        get<any>(`${BASE}/executive`, { params: { period, ...(outletId ? { outlet_id: outletId } : {}) } }),

    // Production intelligence: cycle times, bottlenecks, tailors, QC, capacity, materials.
    productionIntelligence: (from: string, to: string) =>
        get<any>(`${BASE}/production-intelligence`, { params: { period: "custom", from, to } }),

    // Inventory intelligence: valuation, ABC + cover days, dead stock, materials.
    inventoryIntelligence: (from: string, to: string) =>
        get<any>(`${BASE}/inventory-intelligence`, { params: { period: "custom", from, to } }),

    // Procurement intelligence: supplier scorecard, purchase suggestions, open POs.
    procurementIntelligence: (from: string, to: string) =>
        get<any>(`${BASE}/procurement-intelligence`, { params: { period: "custom", from, to } }),

    // Customer intelligence: segments, new vs returning, top + dormant customers.
    customerIntelligence: (from: string, to: string) =>
        get<any>(`${BASE}/customer-intelligence`, { params: { period: "custom", from, to } }),

    // Replenishment radar — per-customer product reorder cycles, "as of now"
    // (no date range: due/overdue only means anything against today).
    replenishmentRadar: (outletId?: number) =>
        get<ReplenishmentRadar>(`${BASE}/replenishment`, {
            params: { ...(outletId ? { outlet_id: outletId } : {}) },
        }),

    // Collections funnel — quote/deposit/balance: money promised but not
    // collected, "as of now" (no date range, same reasoning as the radar).
    collectionsFunnel: (outletId?: number) =>
        get<CollectionsFunnel>(`${BASE}/collections`, {
            params: { ...(outletId ? { outlet_id: outletId } : {}) },
        }),

    // Win-back economics — dormant customers scored against their own purchase
    // rhythm: KES at risk, urgency, outreach history, 30-day recovery
    // attribution. "As of now" like the radar (no date range).
    winBack: (outletId?: number) =>
        get<WinBackReport>(`${BASE}/win-back`, {
            params: { ...(outletId ? { outlet_id: outletId } : {}) },
        }),

    // Log a manual win-back contact (WhatsApp opened / call made). The server
    // stamps the auth user + a snapshot of revenue_365/days_quiet, and dedupes
    // the same identity within 24h (already_logged: true).
    logWinBackOutreach: (payload: WinBackOutreachPayload) =>
        post<WinBackOutreachResult>(`${BASE}/win-back/outreach`, payload),

    // Financial intelligence (reports.financial): earned P&L, budgets, cash flow, rails.
    financialIntelligence: (from: string, to: string) =>
        get<any>(`${BASE}/financial-intelligence`, { params: { period: "custom", from, to } }),

    // Row-level drill-down: the same query as the KPI, aggregation removed.
    drill: (metric: string, period: string, opts?: { page?: number; bucket?: string; outletId?: number }) =>
        get<any>(`${BASE}/drill/${metric}`, { params: {
            period,
            ...(opts?.page ? { page: opts.page } : {}),
            ...(opts?.bucket ? { bucket: opts.bucket } : {}),
            ...(opts?.outletId ? { outlet_id: opts.outletId } : {}),
        } }),

    // Sales
    salesSummary: (
        params: DateRangeParams & { channel?: string; compare?: 1 },
    ) => get<any>(`${BASE}/sales/summary`, { params }),

    salesByProduct: (params: DateRangeParams & { limit?: number }) =>
        get<any>(`${BASE}/sales/by-product`, { params }),

    /** Sales / cash / balance per channel, by day, week and month. */
    salesLedger: (params: DateRangeParams) =>
        get<SalesLedger>("/v1/admin/reports/sales/ledger", { params }),

    salesByCategory: (params: DateRangeParams) =>
        get<any>(`${BASE}/sales/by-category`, { params }),

    salesByCustomer: (params: DateRangeParams & { limit?: number }) =>
        get<any>(`${BASE}/sales/by-customer`, { params }),

    salesByOutlet: (params: DateRangeParams) =>
        get<any>(`${BASE}/sales/by-outlet`, { params }),

    salesByPaymentMethod: (params: DateRangeParams) =>
        get<any>(`${BASE}/sales/by-payment-method`, { params }),

    salesReturns: (params: DateRangeParams) =>
        get<any>(`${BASE}/sales/returns`, { params }),

    /**
     * Neema (AI agent) performance: leads funnel, lead→order conversion within
     * 14 days, WhatsApp-channel sales, contacts and message volume per platform.
     * Period message volume comes from daily snapshots that collect from deploy
     * day onward (`message_volume.daily_since`); all-time totals are cumulative.
     */
    salesNeema: (params: DateRangeParams) =>
        get<NeemaSalesReport>(`${BASE}/sales/neema`, { params }),

    // Customers
    customerSummary: (params: DateRangeParams) =>
        get<any>(`${BASE}/customers/summary`, { params }),

    customerAnalytics: (params: DateRangeParams & { period?: number }) =>
        get<any>(`${BASE}/customers/analytics`, { params }),

    customerLifetimeValue: (params: DateRangeParams & { limit?: number }) =>
        get<any>(`${BASE}/customers/lifetime-value`, { params }),

    customerRetention: (params: DateRangeParams) =>
        get<any>(`${BASE}/customers/retention`, { params }),

    // Inventory
    inventoryValuationBreakdown: () => get<any>(`${BASE}/inventory/valuation`),

    stockOnHand: (
        params: DateRangeParams & {
            low_stock_only?: boolean;
            category_id?: number;
        },
    ) => get<any>(`${BASE}/inventory/stock-on-hand`, { params }),

    inventoryValuation: () => get<any>(`${BASE}/inventory/valuation`),

    inventoryMovement: (params: DateRangeParams & { product_id?: number }) =>
        get<any>(`${BASE}/inventory/movement`, { params }),

    // Procurement
    purchaseOrders: (params: DateRangeParams & { status?: string }) =>
        get<any>(`${BASE}/purchase-orders`, { params }),

    // Production
    productionSummary: (params: DateRangeParams) =>
        get<any>(`${BASE}/production/summary`, { params }),

    productionEfficiency: (params: DateRangeParams) =>
        get<any>(`${BASE}/production/efficiency`, { params }),

    tailorProductivity: (params: DateRangeParams) =>
        get<any>(`${BASE}/production/tailor-productivity`, { params }),

    productionCostingSummary: (params: DateRangeParams & { status?: string }) =>
        get<any>(`${BASE}/production/costing-summary`, { params }),

    productCostingReport: (
        id: number | string,
        params?: {
            selling_price?: number;
            quantity_sold?: number;
            labour_cost?: number;
            packaging_cost?: number;
            other_costs?: number;
            delivery_cost?: number;
            commission?: number;
            marketing_cost?: number;
            payment_charges?: number;
            management_comment?: string;
        },
    ) => get<any>(`${BASE}/production/costing/${id}`, { params }),

    // Tax & Cash Flow
    taxReport: (params: DateRangeParams) =>
        get<any>(`${BASE}/financial/tax`, { params }),

    cashFlow: (params: DateRangeParams) =>
        get<any>(`${BASE}/financial/cash-flow`, { params }),

    // Financial
    profitLoss: (params: DateRangeParams & { compare?: 1 }) =>
        get<any>(`${BASE}/financial/profit-loss`, { params }),

    revenue: (params: DateRangeParams) =>
        get<any>(`${BASE}/financial/revenue`, { params }),

    expenses: (
        params: DateRangeParams & { category_id?: number; status?: string },
    ) => get<any>(`${BASE}/financial/expenses`, { params }),

    // Schedules
    listSchedules: () => get<any>(`${BASE}/schedules`),

    saveSchedule: (data: SchedulePayload) =>
        post<any>(`${BASE}/schedules`, data),

    deleteSchedule: (id: string) => del<any>(`${BASE}/schedules/${id}`),

    // ── Export / Print downloads ──────────────────────────────────────────────
    // These were previously raw URL-builders (`exportCsvUrl` / `printUrl`)
    // meant for direct browser navigation (<a href> / window.open). That
    // doesn't work in this app: auth is Bearer-token-only (see client.ts -
    // there's no Sanctum stateful/cookie middleware configured), and a plain
    // navigation never attaches the Authorization header, so every request
    // would 401. Replaced with authenticated fetch + blob download, matching
    // the same pattern usePdfDownload.tsx already uses for PDF downloads.
    //
    // reportPath: same value previously passed to exportCsvUrl/printUrl,
    // e.g. 'sales/summary', 'financial/profit-loss'.
    downloadCsv: async (
        reportPath: string,
        params: Record<string, string | number | boolean | undefined>,
    ): Promise<boolean> => {
        return downloadReportFile(
            reportPath,
            { ...params, export: "csv" },
            "csv",
        );
    },

    /**
     * Opens the report in a new tab for printing, authenticated via a
     * short-lived blob URL (object URLs inherit no cookies/headers, so this
     * is the only reliable way to get an authenticated report into a new
     * tab without a server-side session).
     */
    openPrintView: async (
        reportPath: string,
        params: Record<string, string | number | boolean | undefined>,
    ): Promise<boolean> => {
        return downloadReportFile(reportPath, { ...params, print: 1 }, "html", {
            openInNewTab: true,
        });
    },
};

// ── Authenticated report download helper ─────────────────────────────────────

async function downloadReportFile(
    reportPath: string,
    params: Record<string, string | number | boolean | undefined>,
    extension: string,
    opts: { openInNewTab?: boolean } = {},
): Promise<boolean> {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== "") q.set(k, String(v));
    });

    const base = (import.meta as any).env?.VITE_API_URL ?? "/api";
    const url = `${base}${BASE}/${reportPath}?${q.toString()}`;

    const token = tokenStorage.get() ?? "";

    const res = await fetch(url, {
        method: "GET",
        headers: {
            Authorization: `Bearer ${token}`,
            Accept: extension === "csv" ? "text/csv" : "*/*",
        },
    });

    if (!res.ok) {
        return false;
    }

    const blob = await res.blob();
    const blobUrl = URL.createObjectURL(blob);

    if (opts.openInNewTab) {
        window.open(blobUrl, "_blank");
        setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
        return true;
    }

    const match = res.headers
        .get("Content-Disposition")
        ?.match(/filename="?([^";\n]+)"?/i);
    const filename =
        match?.[1] ?? `${reportPath.replace(/\//g, "-")}.${extension}`;

    const a = document.createElement("a");
    a.href = blobUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(blobUrl), 5_000);

    return true;
}

// ── Schedule types ────────────────────────────────────────────────────────────

export type ReportType =
    | "sales"
    | "customers"
    | "inventory"
    | "financial"
    | "production"
    | "procurement";
export type ScheduleFrequency = "daily" | "weekly" | "monthly";
export type ExportFormat = "csv" | "pdf";

export interface SchedulePayload {
    name: string;
    report_type: ReportType;
    frequency: ScheduleFrequency;
    recipients: string[];
    format: ExportFormat;
    filters?: Record<string, any>;
    is_active?: boolean;
}

export interface ReportSchedule extends SchedulePayload {
    id: string;
    created_by: number;
    created_at: string;
}

// ── Date preset helpers ───────────────────────────────────────────────────────

export type DatePreset =
    | "today"
    | "yesterday"
    | "this_week"
    | "last_week"
    | "last_7_days"
    | "last_30_days"
    | "last_60_days"
    | "last_90_days"
    | "this_month"
    | "last_month"
    | "this_quarter"
    | "last_quarter"
    | "this_year"
    | "last_year"
    | "custom";

export function datePresetRange(preset: DatePreset): {
    start: string;
    end: string;
} {
    const fmt = (d: dayjs.Dayjs) => d.format("YYYY-MM-DD");
    const now = dayjs();

    switch (preset) {
        case "today":
            return {
                start: fmt(now.startOf("day")),
                end: fmt(now.endOf("day")),
            };
        case "yesterday":
            return {
                start: fmt(now.subtract(1, "day").startOf("day")),
                end: fmt(now.subtract(1, "day").endOf("day")),
            };
        case "this_week":
            return {
                start: fmt(now.startOf("week")),
                end: fmt(now.endOf("week")),
            };
        case "last_week":
            return {
                start: fmt(now.subtract(1, "week").startOf("week")),
                end: fmt(now.subtract(1, "week").endOf("week")),
            };
        case "last_7_days":
            return {
                start: fmt(now.subtract(6, "day").startOf("day")),
                end: fmt(now.endOf("day")),
            };
        case "last_30_days":
            return {
                start: fmt(now.subtract(29, "day").startOf("day")),
                end: fmt(now.endOf("day")),
            };
        case "last_60_days":
            return {
                start: fmt(now.subtract(59, "day").startOf("day")),
                end: fmt(now.endOf("day")),
            };
        case "last_90_days":
            return {
                start: fmt(now.subtract(89, "day").startOf("day")),
                end: fmt(now.endOf("day")),
            };
        case "this_month":
            return {
                start: fmt(now.startOf("month")),
                end: fmt(now.endOf("month")),
            };
        case "last_month":
            return {
                start: fmt(now.subtract(1, "month").startOf("month")),
                end: fmt(now.subtract(1, "month").endOf("month")),
            };
        case "this_quarter":
            return {
                start: fmt(now.startOf("quarter")),
                end: fmt(now.endOf("quarter")),
            };
        case "last_quarter":
            return {
                start: fmt(now.subtract(1, "quarter").startOf("quarter")),
                end: fmt(now.subtract(1, "quarter").endOf("quarter")),
            };
        case "this_year":
            return {
                start: fmt(now.startOf("year")),
                end: fmt(now.endOf("year")),
            };
        case "last_year":
            return {
                start: fmt(now.subtract(1, "year").startOf("year")),
                end: fmt(now.subtract(1, "year").endOf("year")),
            };
        default:
            return {
                start: fmt(now.startOf("month")),
                end: fmt(now.endOf("month")),
            };
    }
}

export const DATE_PRESETS: { value: DatePreset; label: string }[] = [
    { value: "today", label: "Today" },
    { value: "yesterday", label: "Yesterday" },
    { value: "this_week", label: "This Week" },
    { value: "last_week", label: "Last Week" },
    { value: "last_7_days", label: "Last 7 Days" },
    { value: "last_30_days", label: "Last 30 Days" },
    { value: "last_60_days", label: "Last 60 Days" },
    { value: "last_90_days", label: "Last 90 Days" },
    { value: "this_month", label: "This Month" },
    { value: "last_month", label: "Last Month" },
    { value: "this_quarter", label: "This Quarter" },
    { value: "last_quarter", label: "Last Quarter" },
    { value: "this_year", label: "This Year" },
    { value: "last_year", label: "Last Year" },
    { value: "custom", label: "Custom Range" },
];

// ── Sales ledger ──────────────────────────────────────────────────────────────
// sales = paid + balance on every row; see ReportController::salesLedger for why
// paid is attributed to the order's period rather than the payment's.
export interface LedgerFigures {
    orders: number;
    sales: number;
    paid: number;
    balance: number;
}
export interface LedgerBucket {
    period: string;
    total: LedgerFigures;
    by_channel: Record<"pos" | "online" | "whatsapp", LedgerFigures>;
}
export interface SalesLedger {
    period: { start: string; end: string; currency: string };
    channels: (LedgerFigures & { channel: "pos" | "online" | "whatsapp"; label: string })[];
    daily: { date: string; orders: number; sales: number; paid: number; credit: number }[];
    weekly: LedgerBucket[];
    monthly: LedgerBucket[];
}

// ── Replenishment radar ───────────────────────────────────────────────────────
// One row per (customer, product) pair with a detected reorder rhythm whose
// due window has opened. Established rows sort before provisional; within a
// tier it is overdue-first, then expected value desc.

export interface ReplenishmentDueRow {
    /** Canonical customer key (customer_id, else normalized phone, else email). */
    ckey: string;
    name: string;
    phone: string | null;
    product_id: number;
    product_name: string;
    /**
     * established — 3+ steady purchases, a confirmed rhythm (automation acts).
     * provisional — exactly 2 purchases with a believable gap; confirms on the
     * 3rd order. Shown muted; automated pings never touch these.
     */
    tier: "established" | "provisional";
    /** Median days between this customer's purchases of this product. */
    cycle_days: number;
    purchase_events: number;
    last_purchase_at: string;
    next_due_at: string;
    /** Days past next_due_at; negative = due in N days. */
    days_over: number;
    status: "overdue" | "due_soon";
    /** Average KES spent on this product per purchase event. */
    avg_value: number;
    /** When the automated reorder ping last reached (or last tried) this pair. */
    last_pinged_at: string | null;
    last_ping_status: "sent" | "failed" | null;
}

export interface ReplenishmentRadar {
    summary: {
        due_customers: number;
        due_pairs: number;
        /** Due rows in the provisional tier (subset of due_pairs). */
        provisional_pairs: number;
        /** ALL pairs at exactly 2 purchase dates, due or not — the pipeline. */
        maturing_pairs: number;
        expected_revenue: number;
        /** Automated reorder pings sent in the last 30 days. */
        pings_30d: number;
    };
    due: ReplenishmentDueRow[];
}

// ── Collections funnel ────────────────────────────────────────────────────────
// Quote → deposit → balance: every shilling promised but not collected, staged.
// Balance figures share MetricEngine::openBalances()/outstandingAging() with
// the executive Overview, so the two surfaces always agree.

export interface OpenQuoteRow {
    id: number;
    number: string;
    customer: string;
    phone: string | null;
    value: number;
    /** Days since the quote was issued (issue date, else creation). */
    age_days: number;
    status: string;
    expires_at: string | null;
}

export interface StalledDepositRow {
    id: number;
    number: string;
    customer: string;
    phone: string | null;
    total: number;
    deposit_paid: number;
    balance_due: number;
    /** Days since money last moved on this order (order date if never). */
    days_since_last_payment: number;
}

export interface UnpaidBalanceRow {
    id: number;
    number: string;
    customer: string;
    phone: string | null;
    total: number;
    paid: number;
    balance: number;
    days_outstanding: number;
    bucket: "0_30" | "31_60" | "61_90" | "90_plus";
}

export interface CollectionsAgingBucket {
    key: "0_30" | "31_60" | "61_90" | "90_plus";
    label: string;
    amount: number;
    orders: number;
}

export interface CollectionsFunnel {
    summary: {
        /** Headline: open-quote value + total outstanding balance. */
        money_on_table: number;
        open_quotes: { count: number; value: number; avg_age_days: number };
        stalled_deposits: { count: number; deposit_held: number; balance_due: number };
        unpaid_balances: { count: number; value: number };
        aging: {
            buckets: CollectionsAgingBucket[];
            deposits_held: { orders: number; amount: number };
        };
        conversion: {
            /** % of quotes raised in the last 90 days that became orders. */
            quotes_converted_rate_90d: number | null;
            avg_days_quote_to_order: number | null;
            avg_days_deposit_to_paid: number | null;
        };
    };
    open_quotes: OpenQuoteRow[];
    stalled_deposits: StalledDepositRow[];
    unpaid_balances: UnpaidBalanceRow[];
}

// ── Win-back economics ────────────────────────────────────────────────────────
// A customer is dormant when their silence is long relative to their OWN
// median inter-purchase gap (min 45 days quiet), not a flat 60-day rule.
// urgency = days_quiet / median_gap ("3.2× overdue").

export interface WinBackCustomerRow {
    /** Canonical customer key (customer_id, else normalized phone, else email). */
    ckey: string;
    customer_id: number | null;
    name: string;
    phone: string | null;
    /** Trailing-365-day spend — the annual value at risk for this customer. */
    revenue_365: number;
    orders_365: number;
    last_order_at: string;
    days_quiet: number;
    /** Median days between this customer's purchase days. */
    median_gap_days: number;
    /** days_quiet / median_gap — 2.0 means twice their usual gap has passed. */
    urgency: number;
    last_outreach: {
        channel: "whatsapp" | "call" | "other";
        at: string;
        by_name: string | null;
    } | null;
    /** First order placed within 30 days AFTER the latest outreach, if any. */
    recovered: {
        order_number: string;
        amount: number;
        at: string;
    } | null;
}

export interface WinBackReport {
    summary: {
        /** Whole dormant cohort (the list itself is capped at 50). */
        customers_at_risk: number;
        annual_value_at_risk: number;
        contacted_30d: number;
        /** Distinct customers contacted in the last 90d who ordered within 30d after. */
        won_back_90d: number;
        /** Sum of distinct first-orders-after-outreach (90d window). */
        recovered_revenue_90d: number;
        /** won_back_90d / distinct customers contacted in 90d. */
        win_back_rate_pct: number;
    };
    customers: WinBackCustomerRow[];
}

export interface WinBackOutreachPayload {
    customer_id?: number | null;
    phone?: string | null;
    name: string;
    channel: "whatsapp" | "call" | "other";
    /** Fallback snapshot values — the server recomputes from orders when it can. */
    revenue_365?: number;
    days_quiet?: number;
}

export interface WinBackOutreachResult {
    already_logged: boolean;
    outreach: {
        id: number;
        customer_id: number | null;
        phone: string | null;
        name: string;
        channel: string;
        created_at: string;
    };
}

// ── Neema performance ─────────────────────────────────────────────────────────
export type MessagingChannel = "whatsapp" | "messenger" | "instagram" | "facebook";

export interface NeemaSalesReport {
    period: { start: string; end: string; currency: string };
    leads: {
        total: number;
        by_status: Record<"new" | "assigned" | "quoted" | "won" | "lost", number>;
        by_intent: { intent: string; count: number }[];
        won_rate: number | null;
    };
    lead_conversion: {
        converted: number;
        conversion_rate: number | null;
        orders: number;
        revenue: number;
        window_days: number;
    };
    whatsapp_sales: { orders: number; revenue: number; paid: number; balance: number };
    contacts: {
        channel: MessagingChannel;
        new_contacts: number;
        active_contacts: number;
        contacts: number;
        matched: number;
        match_rate: number | null;
    }[];
    message_volume: {
        channels: {
            channel: MessagingChannel;
            period: { messages: number; inbound: number };
            all_time: { messages: number; inbound: number };
        }[];
        /** Date daily snapshots began collecting; period figures before this are structurally zero. */
        daily_since: string | null;
    };
}
