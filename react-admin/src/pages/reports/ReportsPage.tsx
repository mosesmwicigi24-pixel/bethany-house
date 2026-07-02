// src/pages/reports/ReportsPage.tsx
//
// Main Reports landing page.
// Shows a high-level KPI overview, scheduling summary, and navigation tiles.

import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { reportsApi } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";
import { KpiCard } from "./reportShared";

// ─── KPI overview ──────────────────────────────────────────────────────────────

function OverviewSection() {
    const { data, isLoading } = useQuery({
        queryKey: ["report-dashboard-kpis"],
        queryFn: () => reportsApi.dashboardKpis(30),
        staleTime: 5 * 60 * 1000,
    });

    if (isLoading)
        return (
            <div className="flex justify-center py-12">
                <Spinner />
            </div>
        );

    const kpis = data?.kpis ?? {};

    return (
        <div className="space-y-3">
            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                Last 30 days
            </p>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard
                    label="Total Revenue"
                    value={`KES ${Number(kpis.sales?.total ?? 0).toLocaleString()}`}
                    sub={`${kpis.sales?.count ?? 0} paid orders`}
                    color="text-brand-600"
                />
                <KpiCard
                    label="Avg Order Value"
                    value={`KES ${Number(kpis.sales?.average ?? 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`}
                />
                <KpiCard
                    label="New Customers"
                    value={kpis.customers?.new ?? 0}
                    sub={`${kpis.customers?.total ?? 0} total`}
                    color="text-success"
                />
                <KpiCard
                    label="Active Productions"
                    value={kpis.production?.active ?? 0}
                    sub={`${kpis.production?.completed_this_period ?? 0} completed`}
                    color="text-info"
                />
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                <KpiCard
                    label="Active Products"
                    value={kpis.products?.total ?? 0}
                />
                <KpiCard
                    label="Low Stock Alerts"
                    value={kpis.products?.low_stock ?? 0}
                    color={kpis.products?.low_stock > 0 ? "text-warning" : ""}
                />
            </div>
        </div>
    );
}

// ─── Scheduled reports summary ─────────────────────────────────────────────────

function SchedulesSummary() {
    const { data } = useQuery({
        queryKey: ["report-schedules"],
        queryFn: () => reportsApi.listSchedules(),
        staleTime: 60 * 1000,
    });

    const schedules = (data?.schedules ?? []) as any[];
    if (schedules.length === 0) return null;

    const active = schedules.filter((s) => s.is_active);
    const byReport = Object.entries(
        schedules.reduce((acc: Record<string, number>, s: any) => {
            acc[s.report_type] = (acc[s.report_type] ?? 0) + 1;
            return acc;
        }, {}),
    );

    return (
        <div className="card card-body">
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                    <svg
                        className="w-4 h-4 text-brand-500"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                    </svg>
                    <p className="text-sm font-semibold text-surface-900">
                        Scheduled Reports
                    </p>
                </div>
                <span className="text-xs text-surface-400">
                    {active.length} active
                </span>
            </div>
            <div className="flex flex-wrap gap-2">
                {byReport.map(([type, count]) => (
                    <span
                        key={type}
                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-brand-50 text-brand-700 text-xs font-medium capitalize"
                    >
                        {type} · {count as number}
                    </span>
                ))}
            </div>
            <p className="text-xs text-surface-400 mt-2">
                Go to any report section to manage its schedules.
            </p>
        </div>
    );
}

// ─── Category tiles ────────────────────────────────────────────────────────────

interface ReportCategory {
    id: string;
    label: string;
    description: string;
    icon: React.ReactNode;
    path: string;
    color: string;
}

const CATEGORIES: ReportCategory[] = [
    {
        id: "sales",
        label: "Sales",
        description: "Revenue, orders, products, channels, patterns & returns",
        path: "/reports/sales",
        color: "text-indigo-600 bg-indigo-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"
                />
            </svg>
        ),
    },
    {
        id: "customers",
        label: "Customers",
        description: "Growth, segments, lifetime value, retention cohorts",
        path: "/reports/customers",
        color: "text-violet-600 bg-violet-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                />
            </svg>
        ),
    },
    {
        id: "inventory",
        label: "Inventory",
        description:
            "Stock health, outlet distribution, critical alerts, movements",
        path: "/reports/inventory",
        color: "text-amber-600 bg-amber-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"
                />
            </svg>
        ),
    },
    {
        id: "production",
        label: "Production",
        description:
            "Completion, on-time rate, tailor performance, QC failures",
        path: "/reports/production",
        color: "text-pink-600 bg-pink-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z"
                />
            </svg>
        ),
    },
    {
        id: "procurement",
        label: "Procurement",
        description:
            "Purchase orders, supplier spend, top items, fulfilment status",
        path: "/reports/procurement",
        color: "text-emerald-600 bg-emerald-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"
                />
            </svg>
        ),
    },
    {
        id: "financial",
        label: "Financial",
        description: "P&L statement, revenue vs expenses, tax, discounts",
        path: "/reports/financial",
        color: "text-blue-600 bg-blue-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
        ),
    },
];

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ReportsPage() {
    const navigate = useNavigate();
    const { can } = usePermissions();
    // Every /reports/* sub-page requires reports.view, already implied by
    // reaching this page - except /reports/financial, which requires the
    // more restricted reports.financial (see routes/api.php and
    // SyncPermissions.php: outlet_manager and procurement_officer/manager
    // deliberately get reports.view but not reports.financial). Filtering
    // the tile here so it doesn't link to a page that will 403.
    const visibleCategories = CATEGORIES.filter(
        (cat) => cat.id !== "financial" || can("reports.financial"),
    );

    return (
        <div className="space-y-8 animate-fade-in">
            <div>
                <h1 className="page-title">Reports & Analytics</h1>
                <p className="page-subtitle">
                    Business intelligence overview. Select a category to drill
                    down with custom date ranges, CSV export, and scheduling.
                </p>
            </div>

            <OverviewSection />
            <SchedulesSummary />

            <div>
                <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                    Report Categories
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {visibleCategories.map((cat) => (
                        <button
                            key={cat.id}
                            onClick={() => navigate(cat.path)}
                            className="card card-body text-left hover:shadow-md transition-shadow group flex items-start gap-4"
                        >
                            <div
                                className={clsx(
                                    "w-10 h-10 rounded-xl flex items-center justify-center shrink-0",
                                    cat.color,
                                )}
                            >
                                {cat.icon}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="font-semibold text-surface-900 group-hover:text-brand-600 transition-colors">
                                    {cat.label}
                                </p>
                                <p className="text-sm text-surface-500 mt-0.5 leading-snug">
                                    {cat.description}
                                </p>
                                <div className="flex items-center gap-3 mt-2 text-xs text-surface-400">
                                    <span className="flex items-center gap-0.5">
                                        <svg
                                            className="w-3 h-3"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
                                            />
                                        </svg>
                                        CSV export
                                    </span>
                                    <span className="flex items-center gap-0.5">
                                        <svg
                                            className="w-3 h-3"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                                            />
                                        </svg>
                                        Schedulable
                                    </span>
                                </div>
                            </div>
                            <svg
                                className="w-4 h-4 text-surface-300 group-hover:text-brand-500 transition-colors shrink-0 mt-0.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M8.25 4.5l7.5 7.5-7.5 7.5"
                                />
                            </svg>
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}