/**
 * Report pages and who may open them (Role Hardening Plan §6, Phase 3A).
 *
 * One permission per page replaced the single front door `reports.view`.
 * A FILE from a page (CSV, PDF, a schedule) needs view of that page AND
 * `reports.export`, or `reports.export_supply` on Inventory / Procurement.
 *
 * Mirrors backend/app/Support/ReportPages.php and the report.page:<page>
 * groups in backend/routes/api.php — the server is the authority; this only
 * keeps the screen from offering what the server will refuse.
 */

export type ReportPage =
    | "executive"
    | "sales"
    | "customers"
    | "financial"
    | "production"
    | "inventory"
    | "procurement"
    | "performance"
    | "signals"
    | "data_quality"
    | "explorer";

export const REPORT_PAGE_PERMISSION: Record<ReportPage, string> = {
    executive:    "reports.executive",
    sales:        "reports.sales",
    customers:    "reports.customers",
    financial:    "reports.financial",
    production:   "reports.production",
    inventory:    "reports.inventory",
    procurement:  "reports.procurement",
    performance:  "reports.performance",
    signals:      "reports.signals",
    data_quality: "reports.data_quality",
    explorer:     "reports.explorer",
};

const SUPPLY_PAGES: ReportPage[] = ["inventory", "procurement"];

/** The screen each /reports route renders. */
const ROUTE_PAGE: Record<string, ReportPage> = {
    "/reports":              "executive",
    "/reports/sales":        "sales",
    "/reports/customers":    "customers",
    "/reports/finance":      "financial",
    "/reports/production":   "production",
    "/reports/inventory":    "inventory",
    "/reports/procurement":  "procurement",
    "/reports/performance":  "performance",
    "/reports/signals":      "signals",
    "/reports/data-quality": "data_quality",
    "/reports/explorer":     "explorer",
};

/**
 * The page an API report path belongs to ("sales/summary", "stockout-loss",
 * "/v1/admin/reports/explorer"…), as routes/api.php groups them. The Neema
 * tab is Customers & Neema although its path starts with sales.
 */
const ENDPOINT_PAGE: Record<string, ReportPage> = {
    "executive": "executive", "engine-room": "executive",
    "sales": "sales", "outcomes": "sales", "collections": "sales", "attach-rates": "sales",
    "international": "sales", "order-pipeline": "sales", "dashboard": "sales",
    "customers": "customers", "customer-intelligence": "customers", "replenishment": "customers",
    "institutions": "customers", "win-back": "customers", "second-purchase": "customers",
    "outreach-log": "customers",
    "financial": "financial", "financial-intelligence": "financial",
    "production": "production", "production-intelligence": "production",
    "inventory": "inventory", "inventory-intelligence": "inventory", "stockout-loss": "inventory",
    "purchase-orders": "procurement", "procurement-intelligence": "procurement", "seasonal-demand": "procurement",
    "performance": "performance",
    "data-quality": "data_quality",
    "explorer": "explorer",
};

type Can = (permission: string) => boolean;

export function reportPageOfRoute(path: string): ReportPage | null {
    const bare = path.split(/[?#]/)[0].replace(/\/+$/, "") || "/";
    return ROUTE_PAGE[bare] ?? null;
}

export function reportPageOfEndpoint(path: string): ReportPage | null {
    const bare = path.split("?")[0].replace(/^\/?(v1\/admin\/reports\/)?/, "").replace(/^\/+/, "");
    if (bare === "sales/neema" || bare.startsWith("sales/neema/")) return "customers";
    return ENDPOINT_PAGE[bare.split("/")[0]] ?? null;
}

export function canViewReport(can: Can, page: ReportPage | null): boolean {
    return page !== null && can(REPORT_PAGE_PERMISSION[page]);
}

/** May the viewer take a file out of this page? Unknown page → reports.export alone. */
export function canExportReport(can: Can, page: ReportPage | null): boolean {
    if (page === null) return can("reports.export");
    if (!canViewReport(can, page)) return false;
    return can("reports.export") || (SUPPLY_PAGES.includes(page) && can("reports.export_supply"));
}

/** A link to a /reports screen is offered only to someone who may open it; other links pass. */
export function mayOpenReportLink(can: Can, to: string): boolean {
    const page = reportPageOfRoute(to);
    return page === null || canViewReport(can, page);
}
