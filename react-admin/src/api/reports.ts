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
