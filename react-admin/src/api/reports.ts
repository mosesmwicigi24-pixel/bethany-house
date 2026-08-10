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

    // Attach-rate intelligence — basket affinities + missed-attach KES over a
    // trailing window (default 180 days). Cached 10 min server-side.
    attachRates: (outletId?: number, days?: number) =>
        get<AttachRatesReport>(`${BASE}/attach-rates`, {
            params: {
                ...(outletId ? { outlet_id: outletId } : {}),
                ...(days ? { days } : {}),
            },
        }),

    // Stock-out revenue loss — reconstructed zero-stock windows over a
    // trailing window (default 90 days), velocity over in-stock days only,
    // est. KES lost + live "bleeding now" rate. Cached 10 min server-side.
    stockoutLoss: (outletId?: number, days?: number) =>
        get<StockoutLossReport>(`${BASE}/stockout-loss`, {
            params: {
                ...(outletId ? { outlet_id: outletId } : {}),
                ...(days ? { days } : {}),
            },
        }),

    // Seasonal demand planning — liturgical seasons ahead (120-day horizon),
    // per-product lift from combined hub + legacy history, stock gaps and
    // supplier-lead-time order-by dates. Cached 10 min server-side.
    seasonalDemand: (outletId?: number) =>
        get<SeasonalDemandReport>(`${BASE}/seasonal-demand`, {
            params: { ...(outletId ? { outlet_id: outletId } : {}) },
        }),

    // Institutional accounts — churches/institutions as accounts: rollups,
    // buyer-turnover risk, cohort cross-sell. Heuristic identification
    // (customer_type='business' or an institution keyword in the name).
    // "As of now" (no date range). Cached 10 min server-side.
    institutionalAccounts: (outletId?: number) =>
        get<InstitutionalAccountsReport>(`${BASE}/institutions`, {
            params: { ...(outletId ? { outlet_id: outletId } : {}) },
        }),

    // International corridor — diaspora & regional orders (non-KES currency OR
    // is_international) over a trailing window (default 180 days). Native
    // per-currency rollups, country grouping, corridor top products, 6-month
    // trend. KES equivalents only when real rates are configured. Cached 10
    // min server-side.
    internationalCorridor: (outletId?: number, days?: number) =>
        get<InternationalCorridorReport>(`${BASE}/international`, {
            params: {
                ...(outletId ? { outlet_id: outletId } : {}),
                ...(days ? { days } : {}),
            },
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

    // Outreach log — the unified audit feed: automated replenishment-radar
    // WhatsApp pings merged with manual win-back outreach, newest first,
    // capped at 100 rows, with per-row outcome attribution ("won back +KES X"
    // / "reordered"). "As of now" like the radar (no date range).
    outreachLog: () => get<OutreachLogReport>(`${BASE}/outreach-log`),

    // Engine Room — the Overview strip: the six revenue-engine summaries in
    // one lightweight call (each engine is cached or summary-sized on the
    // server; an engine that fails arrives as null and renders a "—" card
    // instead of breaking the Overview).
    engineRoom: (outletId?: number) =>
        get<EngineRoomSummaries>(`${BASE}/engine-room`, {
            params: { ...(outletId ? { outlet_id: outletId } : {}) },
        }),

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

// ── Attach-rate intelligence (basket affinity) ────────────────────────────────
// Baskets = distinct products per order (trailing window, sales truth).
// attach_rate = P(companion | anchor); lift = attach_rate / P(companion).
// missed_revenue_estimate = missed baskets × attach_rate × avg companion line
// value — an expected-value PROXY of what asking would have earned, not
// booked money.

export interface AttachCompanion {
    product_id: number;
    name: string;
    sku: string | null;
    /** % of the anchor's baskets that also contained this companion. */
    attach_rate_pct: number;
    /** How much likelier than chance the pairing is (1.0 = coincidence). */
    lift: number;
    /** Avg KES the companion earns per basket where it attached. */
    avg_value: number;
    /** Anchor baskets where the companion was NOT attached. */
    missed: number;
    missed_revenue_estimate: number;
}

export interface AttachAnchor {
    product_id: number;
    name: string;
    baskets: number;
    companions: AttachCompanion[];
    /** Sum of the companions' missed-revenue estimates (the ranking key). */
    missed_revenue_estimate: number;
}

export interface AttachRatesReport {
    summary: {
        total_baskets: number;
        multi_item_pct: number;
        avg_basket_items: number;
        missed_revenue_estimate_total: number;
        top_pair: { anchor: string; companion: string; attach_rate: number } | null;
    };
    anchors: AttachAnchor[];
}

// ── Stock-out revenue loss ────────────────────────────────────────────────────
// Zero-stock windows reconstructed from the inventory ledger; a product is
// "out" only while ALL its outlets' stock rows sat at ≤ 0. Velocity = units
// sold ÷ in-stock days. confidence 'low' = the product's stock rows have no
// transaction history at all — only its CURRENT out-streak (from updated_at)
// is claimed, and its KES stay out of the measured summary totals.

export interface StockoutLossRow {
    product_id: number;
    name: string;
    currently_out: boolean;
    /** Days of the CURRENT out-streak (0 when in stock; may exceed the window). */
    out_streak_days: number;
    /** Days at zero inside the trailing window. */
    out_days_window: number;
    /** Units sold per IN-stock day. */
    velocity_per_day: number;
    avg_price: number;
    /** out_days_window × velocity × avg_price. */
    est_lost_revenue: number;
    /** velocity × avg_price while currently out, else 0. */
    est_daily_loss: number;
    confidence: "measured" | "low";
}

export interface StockoutLossReport {
    summary: {
        products_currently_out: number;
        /** KES/day bleeding right now (measured-confidence products only). */
        est_daily_loss_now: number;
        /** KES lost across the window (measured-confidence products only). */
        est_lost_revenue_window: number;
        low_confidence_count: number;
    };
    products: StockoutLossRow[];
}

// ── Seasonal demand planning ──────────────────────────────────────────────────
// The liturgical calendar (computed, not the storefront CMS seasons) crossed
// with combined sales history: hub orders UNION imported legacy POS daily
// aggregates. holy_week is exposed as a sub-window of lent (sub_of: 'lent')
// and excluded from summary totals so the same units are not counted twice.

export interface SeasonalDemandProductRow {
    product_id: number;
    name: string;
    /** Seasonal units/day ÷ ordinary-time units/day (capped server-side). */
    lift: number;
    projected_units: number;
    stock: number;
    gap: number;
    lead_days: number;
    /** Season start minus the product's supplier lead time. */
    order_by: string;
    /** Negative = the order window has already passed. */
    days_until_order_by: number;
    est_gap_value: number;
    /** 'hub' = trailing-60d hub velocity × lift; 'historical' = legacy rate. */
    basis: "hub" | "historical";
}

export interface SeasonalDemandSeason {
    key: string;
    label: string;
    start: string;
    end: string;
    days_until_start: number;
    length_days: number;
    /** Present on holy_week only — it rides inside lent. */
    sub_of?: string;
    products: SeasonalDemandProductRow[];
}

export interface SeasonalDemandReport {
    seasons: SeasonalDemandSeason[];
    summary: {
        next_season: { key: string; label: string; start: string } | null;
        total_gap_value: number;
        /** Rows with a gap whose order-by date is within 14 days (or past). */
        urgent_orders: number;
        /** 0 until legacy history is imported (drives the unlock empty state). */
        history_depth_days: number;
    };
}

// ── Institutional accounts ────────────────────────────────────────────────────
// Churches/institutions identified heuristically (customer_type='business' or
// an institution keyword — PCEA/AIC/CHAPEL/CHURCH/… — in the name), rolled up
// on the canonical customer key over the trailing 365 days.

export interface InstitutionCrossSell {
    product_id: number;
    name: string;
    /** % of ALL identified institutions that buy this product. */
    adoption_pct: number;
}

export interface InstitutionalAccountRow {
    /** Canonical customer key (customer_id, else normalized phone, else email). */
    ckey: string;
    name: string;
    phone: string | null;
    revenue_365: number;
    orders_365: number;
    last_order_at: string;
    days_quiet: number;
    /** Quiet 60+ days AND >= KES 10,000 trailing-365 revenue. */
    risk: boolean;
    /** Distinct phone/email combos seen on this account's orders — >1 means the buyer changed. */
    buyer_contacts: number;
    products_bought: number;
    top_product: string | null;
    /** Top 3 cohort-popular products this institution has never bought. */
    cross_sell: InstitutionCrossSell[];
}

export interface InstitutionalAccountsReport {
    summary: {
        /** Whole identified cohort (the accounts list is capped at 30). */
        institutions: number;
        revenue_365_total: number;
        /** Institution revenue as % of ALL trailing-365 sales revenue. */
        share_of_total_revenue_pct: number;
        at_risk_count: number;
        at_risk_value: number;
    };
    accounts: InstitutionalAccountRow[];
}

// ── International corridor ────────────────────────────────────────────────────
// Diaspora & regional orders: non-KES currency OR is_international. Native
// currencies are never silently summed — kes_equivalent fields are null
// whenever the currencies table lacks a real configured rate.

export interface CorridorCurrencyRow {
    currency: string;
    orders: number;
    revenue_native: number;
    /** Settled payments net of refunds, only where payment currency == order currency. */
    paid_native: number;
    customers: number;
    avg_order_native: number;
    /** null when no usable configured rate exists for this currency. */
    kes_equivalent: number | null;
}

export interface CorridorCountryRow {
    /** ISO-2 country code, or 'unknown' when the order carried none. */
    country: string;
    country_name: string;
    orders: number;
    customers: number;
    /** Per-currency native revenue map, e.g. { USD: 1200, ZMW: 5400 }. */
    revenue: Record<string, number>;
}

export interface CorridorProductRow {
    product_id: number;
    name: string;
    /** Corridor orders this product appears in. */
    orders: number;
    units: number;
}

export interface CorridorTrendPoint {
    /** YYYY-MM, trailing 6 calendar months, zero-filled. */
    month: string;
    orders: number;
    /** null when rates are unavailable — never a partial conversion. */
    kes_equivalent: number | null;
}

export interface InternationalCorridorReport {
    summary: {
        corridor_orders: number;
        corridor_customers: number;
        currencies_active: number;
        /** Corridor orders as % of ALL sales-truth orders in the window. */
        share_of_all_orders_pct: number;
        /** null when any active corridor currency has no configured rate. */
        kes_equivalent_total: number | null;
        rates_unavailable: boolean;
    };
    currencies: CorridorCurrencyRow[];
    countries: CorridorCountryRow[];
    top_products: CorridorProductRow[];
    trend: CorridorTrendPoint[];
    window_days: number;
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

// ── Engine room ───────────────────────────────────────────────────────────────
// The six revenue-engine summaries the Overview strip renders. Each key is the
// engine's own summary shape, or null when that engine failed server-side —
// one broken engine degrades to a "—" card, never a broken Overview.

export interface EngineRoomSummaries {
    collections: CollectionsFunnel["summary"] | null;
    stockout: StockoutLossReport["summary"] | null;
    winback: WinBackReport["summary"] | null;
    attach: AttachRatesReport["summary"] | null;
    replenishment: ReplenishmentRadar["summary"] | null;
    seasonal: SeasonalDemandReport["summary"] | null;
}

// ── Outreach log ──────────────────────────────────────────────────────────────
// The unified recent-outreach audit feed: automated radar pings + manual
// win-back outreach merged newest-first (cap 100).

export interface OutreachLogRow {
    at: string;
    type: "radar_ping" | "winback";
    /** Customer name when known; falls back to the canonical phone. */
    name: string;
    phone: string | null;
    /** Always "whatsapp" for radar pings; the logged channel otherwise. */
    channel: "whatsapp" | "call" | "other";
    /** Radar pings only — the product the reminder was about. */
    product_name: string | null;
    /** Ping status (sent | failed | skipped_*) — "logged" for manual outreach. */
    status: string;
    /** The user who logged the outreach; "automation" for radar pings. */
    by: string;
    /** "won back +KES X" / "reordered" when attributable, else "—". */
    outcome: string;
}

export interface OutreachLogReport {
    summary: {
        /** Automated pings SENT in the last 30 days. */
        pings_30d: number;
        /** Manual win-back contacts logged in the last 30 days. */
        outreach_30d: number;
        /** Automated pings that FAILED in the last 30 days. */
        failures_30d: number;
        /** 30d outreach rows with an attributed order within 30d after. */
        won_back_30d: number;
    };
    rows: OutreachLogRow[];
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
