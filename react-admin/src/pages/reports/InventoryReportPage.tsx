// src/pages/reports/InventoryReportPage.tsx
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { reportsApi } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";
import dayjs from "dayjs";
import {
    BarChart,
    Bar,
    PieChart,
    Pie,
    Cell,
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    Legend,
} from "recharts";
import {
    KPI_GRID,
    KpiCard,
    ReportPdfButton,
    SectionHeader,
    TableWrapper,
    EmptyRow,
    ReportActionBar,
    ExportCsvButton,
    DateRangePicker,
    ReportPageHeader,
    useDateRange,
    StockPill,
    ProgressBar,
    CHART_COLORS,
    TH,
    TH_R,
} from "./reportShared";

// Movement type classification
const OUT_TYPES = new Set([
    "sale",
    "adjustment_out",
    "transfer_out",
    "damaged",
    "expired",
    "return_to_supplier",
]);

export default function InventoryReportPage() {
    const dr = useDateRange("this_month");
    const [lowOnly, setLowOnly] = useState(false);
    const [activeTab, setActiveTab] = useState<
        "overview" | "stock" | "movements" | "intelligence"
    >("overview");
    const [movTypeFilter, setMovTypeFilter] = useState("");

    const stockQuery = useQuery({
        queryKey: ["report-stock", lowOnly],
        queryFn: () => reportsApi.stockOnHand({ low_stock_only: lowOnly }),
    });
    const valuationQuery = useQuery({
        queryKey: ["report-valuation"],
        queryFn: () => reportsApi.inventoryValuationBreakdown(),
    });
    const movementQuery = useQuery({
        queryKey: ["report-movement", dr.start, dr.end],
        queryFn: () => reportsApi.inventoryMovement(dr.params),
        enabled: !!dr.start && !!dr.end,
    });

    const isLoading = stockQuery.isLoading;
    if (isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );

    const t = stockQuery.data?.totals ?? {};
    const items = stockQuery.data?.items ?? [];
    // EnhancedReportController returns breakdown[] with {category_name, outlet_name, sku_count, total_units, total_retail_value}
    const valuationBreakdown = valuationQuery.data?.breakdown ?? [];
    const grandTotals = valuationQuery.data?.grand_totals ?? {};
    // Group by outlet for the outlet table
    const byOutlet = Object.values(
        valuationBreakdown.reduce((acc: any, row: any) => {
            const key = row.outlet_name ?? "Warehouse";
            if (!acc[key]) acc[key] = { outlet_name: key, item_count: 0, total_quantity: 0, total_value: 0 };
            acc[key].item_count    += Number(row.sku_count ?? 0);
            acc[key].total_quantity += Number(row.total_units ?? 0);
            acc[key].total_value   += Number(row.total_retail_value ?? 0);
            return acc;
        }, {})
    ) as any[];

    // Movement data
    const allTransactions = movementQuery.data?.transactions ?? [];
    const byType = movementQuery.data?.by_type ?? [];
    const transactions = movTypeFilter
        ? allTransactions.filter((tx: any) => tx.type === movTypeFilter)
        : allTransactions;

    // Stock health breakdown from items
    const inStockCount = items.filter(
        (i: any) => i.stock_status === "in_stock",
    ).length;
    const lowStockCount = items.filter(
        (i: any) => i.stock_status === "low_stock",
    ).length;
    const outOfStock = items.filter(
        (i: any) => i.stock_status === "out_of_stock",
    ).length;

    const statusPie = [
        { name: "In Stock", value: inStockCount, color: "#10B981" },
        { name: "Low Stock", value: lowStockCount, color: "#F59E0B" },
        { name: "Out of Stock", value: outOfStock, color: "#EF4444" },
    ].filter((d) => d.value > 0);

    // Category distribution from items
    const byCategory = Object.entries(
        items.reduce(
            (
                acc: Record<string, { qty: number; items: number }>,
                item: any,
            ) => {
                const cat = item.category_name ?? "Uncategorized";
                if (!acc[cat]) acc[cat] = { qty: 0, items: 0 };
                acc[cat].qty += item.quantity_available;
                acc[cat].items += 1;
                return acc;
            },
            {},
        ),
    )
        .map(([cat, d]) => ({ category: cat, ...(d as any) }))
        .sort((a, b) => b.qty - a.qty)
        .slice(0, 8);

    // Urgency: items most critically below reorder point
    const urgentItems = [...items]
        .filter(
            (i: any) =>
                i.stock_status !== "in_stock" && i.low_stock_threshold > 0,
        )
        .map((i: any) => ({
            ...i,
            shortage_pct:
                i.low_stock_threshold > 0
                    ? Math.round(
                          (1 - i.quantity_available / i.low_stock_threshold) *
                              100,
                      )
                    : 0,
        }))
        .sort((a, b) => b.shortage_pct - a.shortage_pct)
        .slice(0, 20);

    // Outlet quantity chart
    const outletQtyData = byOutlet.map((o: any) => ({
        name:
            o.outlet_name?.length > 16
                ? o.outlet_name.substring(0, 14) + "…"
                : o.outlet_name,
        qty: o.total_quantity,
        items: o.item_count,
    }));

    // Movement trend by day
    const movTrend = allTransactions.reduce(
        (acc: Record<string, { in: number; out: number }>, tx: any) => {
            const day = dayjs(tx.created_at).format("MM-DD");
            if (!acc[day]) acc[day] = { in: 0, out: 0 };
            if (OUT_TYPES.has(tx.type)) acc[day].out += Math.abs(tx.quantity);
            else acc[day].in += Math.abs(tx.quantity);
            return acc;
        },
        {},
    );
    const movTrendData = Object.entries(movTrend)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([date, d]) => ({ date, ...(d as Record<string, unknown>) }));

    const maxQty = Math.max(...items.map((i: any) => i.quantity), 1);

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Inventory Report"
                subtitle="Stock health, outlet distribution, low-stock alerts, and movement history."
                reportType="inventory"
                exportPath="inventory/stock-on-hand"
                params={{ low_stock_only: lowOnly }}
                preset={dr.preset}
                start={dr.start}
                end={dr.end}
                onPresetChange={dr.handlePreset}
                onStartChange={dr.setStart}
                onEndChange={dr.setEnd}
            />

            {/* KPIs */}
            <div className={KPI_GRID}>
                <KpiCard label="Total SKUs" value={t.total_items ?? 0} />
                <KpiCard
                    label="In Stock"
                    value={inStockCount}
                    color="text-success"
                />
                <KpiCard
                    label="Low Stock"
                    value={t.low_stock_count ?? 0}
                    color="text-warning"
                />
                <KpiCard
                    label="Out of Stock"
                    value={t.out_of_stock_count ?? 0}
                    color="text-danger"
                />
            </div>

            {/* Tabs */}
            <div className="border-b border-surface-100 overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {(["overview", "stock", "movements", "intelligence"] as const).map(
                        (tab) => (
                            <button
                                key={tab}
                                onClick={() => setActiveTab(tab)}
                                className={clsx(
                                    "px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap shrink-0 transition-colors capitalize",
                                    activeTab === tab
                                        ? "border-brand-500 text-brand-600"
                                        : "border-transparent text-surface-500 hover:text-surface-700",
                                )}
                            >
                                {tab}
                            </button>
                        ),
                    )}
                </nav>
            </div>

            {/* ── OVERVIEW TAB ── */}
            {activeTab === "intelligence" && (
                <InventoryIntelligence start={dr.start} end={dr.end} />
            )}

            {activeTab === "overview" && (
                <div className="space-y-6">
                    {/* Health pie + category distribution */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {statusPie.length > 0 && (
                            <div className="card p-5">
                                <SectionHeader title="Stock Health" />
                                <ResponsiveContainer width="100%" height={200}>
                                    <PieChart>
                                        <Pie
                                            data={statusPie}
                                            dataKey="value"
                                            nameKey="name"
                                            cx="50%"
                                            cy="50%"
                                            outerRadius={75}
                                            label={({ name, percent }: any) =>
                                                `${name} ${(percent * 100).toFixed(0)}%`
                                            }
                                            labelLine={false}
                                        >
                                            {statusPie.map((d, i) => (
                                                <Cell key={i} fill={d.color} />
                                            ))}
                                        </Pie>
                                        <Tooltip />
                                    </PieChart>
                                </ResponsiveContainer>
                                <div className="grid grid-cols-1 gap-3 mt-4 sm:grid-cols-3">
                                    {[
                                        {
                                            label: "In Stock",
                                            value: inStockCount,
                                            color: "text-success",
                                        },
                                        {
                                            label: "Low Stock",
                                            value: lowStockCount,
                                            color: "text-warning",
                                        },
                                        {
                                            label: "Out of Stock",
                                            value: outOfStock,
                                            color: "text-danger",
                                        },
                                    ].map((s) => (
                                        <div
                                            key={s.label}
                                            className="text-center"
                                        >
                                            <p
                                                className={clsx(
                                                    "text-xl font-bold tabular-nums",
                                                    s.color,
                                                )}
                                            >
                                                {s.value}
                                            </p>
                                            <p className="text-xs text-surface-400 mt-0.5">
                                                {s.label}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {byCategory.length > 0 && (
                            <div className="card p-5">
                                <SectionHeader title="Available Units by Category" />
                                <ResponsiveContainer width="100%" height={240}>
                                    <BarChart
                                        data={byCategory}
                                        layout="vertical"
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            stroke="#F1F5F9"
                                            horizontal={false}
                                        />
                                        <XAxis
                                            type="number"
                                            tick={{ fontSize: 11 }}
                                        />
                                        <YAxis
                                            type="category"
                                            dataKey="category"
                                            tick={{ fontSize: 10 }}
                                            width={110}
                                        />
                                        <Tooltip />
                                        <Bar
                                            dataKey="qty"
                                            name="Available Units"
                                            fill={CHART_COLORS[0]}
                                            radius={[0, 3, 3, 0]}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>

                    {/* Outlet distribution */}
                    {outletQtyData.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Stock Distribution by Outlet" />
                            <ResponsiveContainer width="100%" height={200}>
                                <BarChart data={outletQtyData}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#F1F5F9"
                                    />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 11 }}
                                    />
                                    <YAxis tick={{ fontSize: 11 }} width={40} />
                                    <Tooltip />
                                    <Legend />
                                    <Bar
                                        dataKey="qty"
                                        name="Total Units"
                                        fill={CHART_COLORS[0]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="items"
                                        name="SKUs"
                                        fill={CHART_COLORS[4]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    {/* Critical low-stock urgency */}
                    {urgentItems.length > 0 && (
                        <div className="card overflow-hidden">
                            <div className="px-5 pt-5 pb-4">
                                <SectionHeader title="🔴 Critical Stock Alerts">
                                    <ExportCsvButton
                                        path="inventory/stock-on-hand"
                                        params={{ low_stock_only: true }}
                                    />
                                </SectionHeader>
                                <p className="text-sm text-surface-500 -mt-2">
                                    Items below or at their reorder threshold,
                                    ranked by urgency.
                                </p>
                            </div>
                            <TableWrapper>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-y border-surface-100 bg-surface-50/50">
                                            <th className={TH}>Product</th>
                                            <th className={TH}>SKU</th>
                                            <th className={TH}>Outlet</th>
                                            <th className={TH_R}>Available</th>
                                            <th className={TH_R}>Reorder At</th>
                                            <th className={TH_R}>Shortage</th>
                                            <th
                                                className={TH}
                                                style={{ width: 140 }}
                                            >
                                                Urgency
                                            </th>
                                            <th className={TH}>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {urgentItems.map((item: any) => (
                                            <tr
                                                key={`${item.variant_id}-${item.outlet_name}`}
                                                className={clsx(
                                                    "hover:bg-surface-50/50 transition-colors",
                                                    item.stock_status ===
                                                        "out_of_stock"
                                                        ? "bg-danger-light/10"
                                                        : "",
                                                )}
                                            >
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {item.product_name}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                        {item.variant_sku ??
                                                            "-"}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-600">
                                                    {item.outlet_name}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums font-semibold">
                                                    <span
                                                        className={clsx(
                                                            item.quantity_available ===
                                                                0
                                                                ? "text-danger"
                                                                : "text-warning",
                                                        )}
                                                    >
                                                        {
                                                            item.quantity_available
                                                        }
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-500">
                                                    {item.low_stock_threshold}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    <span className="text-danger font-medium">
                                                        {Math.max(
                                                            0,
                                                            item.low_stock_threshold -
                                                                item.quantity_available,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <ProgressBar
                                                            value={
                                                                item.shortage_pct
                                                            }
                                                            max={100}
                                                            color={
                                                                item.shortage_pct >=
                                                                100
                                                                    ? "#EF4444"
                                                                    : "#F59E0B"
                                                            }
                                                        />
                                                        <span
                                                            className={clsx(
                                                                "text-xs font-medium tabular-nums w-9 text-right",
                                                                item.shortage_pct >=
                                                                    100
                                                                    ? "text-danger"
                                                                    : "text-warning",
                                                            )}
                                                        >
                                                            {item.shortage_pct}%
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StockPill
                                                        status={
                                                            item.stock_status
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </TableWrapper>
                        </div>
                    )}
                </div>
            )}

            {/* ── STOCK TAB ── */}
            {activeTab === "stock" && (
                <div className="space-y-4">
                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-4">
                            <SectionHeader title="Stock on Hand">
                                <div className="flex items-center gap-3">
                                    <label className="flex items-center gap-2 text-sm text-surface-600 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            checked={lowOnly}
                                            onChange={(e) =>
                                                setLowOnly(e.target.checked)
                                            }
                                            className="rounded accent-brand-500"
                                        />
                                        Low stock only
                                    </label>
                                    <ExportCsvButton
                                        path="inventory/stock-on-hand"
                                        params={{ low_stock_only: lowOnly }}
                                    />
                                </div>
                            </SectionHeader>
                        </div>
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
                                        <th className={TH}>Product</th>
                                        <th className={TH}>Variant / SKU</th>
                                        <th className={TH}>Category</th>
                                        <th className={TH}>Outlet</th>
                                        <th className={TH_R}>On Hand</th>
                                        <th className={TH_R}>Reserved</th>
                                        <th className={TH_R}>Available</th>
                                        <th className={TH_R}>Reorder At</th>
                                        <th
                                            className={TH}
                                            style={{ width: 130 }}
                                        >
                                            Stock Level
                                        </th>
                                        <th className={TH}>Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {stockQuery.isLoading ? (
                                        <tr>
                                            <td
                                                colSpan={10}
                                                className="px-4 py-8 text-center"
                                            >
                                                <Spinner />
                                            </td>
                                        </tr>
                                    ) : items.length === 0 ? (
                                        <EmptyRow cols={10} />
                                    ) : (
                                        items.slice(0, 100).map((item: any) => (
                                            <tr
                                                key={`${item.variant_id}-${item.outlet_name}`}
                                                className={clsx(
                                                    "hover:bg-surface-50/50 transition-colors",
                                                    item.stock_status ===
                                                        "out_of_stock"
                                                        ? "bg-danger-light/10"
                                                        : "",
                                                )}
                                            >
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {item.product_name}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="text-sm text-surface-600">
                                                        {item.variant_name ??
                                                            "-"}
                                                    </p>
                                                    <p className="text-xs font-mono text-surface-400 mt-0.5">
                                                        {item.variant_sku ??
                                                            "-"}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500">
                                                    {item.category_name ?? "-"}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-600">
                                                    {item.outlet_name}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                    {item.quantity}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-400">
                                                    {item.quantity_reserved}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums font-medium">
                                                    <span
                                                        className={clsx(
                                                            item.quantity_available ===
                                                                0
                                                                ? "text-danger"
                                                                : item.stock_status ===
                                                                    "low_stock"
                                                                  ? "text-warning"
                                                                  : "text-success",
                                                        )}
                                                    >
                                                        {
                                                            item.quantity_available
                                                        }
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-400">
                                                    {item.low_stock_threshold ??
                                                        "-"}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {item.low_stock_threshold >
                                                    0 ? (
                                                        <ProgressBar
                                                            value={
                                                                item.quantity_available
                                                            }
                                                            max={Math.max(
                                                                item.low_stock_threshold *
                                                                    2,
                                                                item.quantity_available,
                                                            )}
                                                            color={
                                                                item.quantity_available ===
                                                                0
                                                                    ? "#EF4444"
                                                                    : item.stock_status ===
                                                                        "low_stock"
                                                                      ? "#F59E0B"
                                                                      : "#10B981"
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-xs text-surface-300">
                                                            No threshold
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StockPill
                                                        status={
                                                            item.stock_status
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>

                    {/* Inventory valuation grand totals */}
                    {grandTotals.total_retail_value > 0 && (
                        <div className="grid grid-cols-3 gap-3">
                            <div className="card card-body">
                                <p className="text-xs text-surface-500">Total Retail Value</p>
                                <p className="text-2xl font-bold text-surface-900 tabular-nums mt-1">{fmtKes(grandTotals.total_retail_value)}</p>
                            </div>
                            <div className="card card-body">
                                <p className="text-xs text-surface-500">Total Units</p>
                                <p className="text-2xl font-bold text-surface-900 tabular-nums mt-1">{Number(grandTotals.total_units ?? 0).toLocaleString()}</p>
                            </div>
                            <div className="card card-body">
                                <p className="text-xs text-surface-500">Total SKUs</p>
                                <p className="text-2xl font-bold text-surface-900 tabular-nums mt-1">{Number(grandTotals.total_sku_count ?? 0).toLocaleString()}</p>
                            </div>
                        </div>
                    )}

                    {/* Category valuation chart */}
                    {valuationBreakdown.length > 0 && (() => {
                        const byCat = Object.values(
                            valuationBreakdown.reduce((acc: any, row: any) => {
                                const k = row.category_name ?? "Uncategorised";
                                if (!acc[k]) acc[k] = { name: k, value: 0 };
                                acc[k].value += Number(row.total_retail_value ?? 0);
                                return acc;
                            }, {})
                        ) as any[];
                        if (byCat.length === 0) return null;
                        return (
                            <div className="card p-5">
                                <SectionHeader title="Stock Value by Category" />
                                <ResponsiveContainer width="100%" height={240}>
                                    <BarChart data={byCat.sort((a: any, b: any) => b.value - a.value).slice(0, 12)} layout="vertical">
                                        <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" horizontal={false} />
                                        <XAxis type="number" tickFormatter={(v: number) => `${(v/1000).toFixed(0)}K`} tick={{ fontSize: 11 }} />
                                        <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} width={120} />
                                        <Tooltip formatter={(v) => fmtKes(v as number)} />
                                        <Bar dataKey="value" name="Retail Value" fill={CHART_COLORS[2]} radius={[0, 3, 3, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        );
                    })()}

                    {/* Outlet valuation table */}
                    {byOutlet.length > 0 && (
                        <div className="card overflow-hidden">
                            <div className="px-5 pt-5 pb-4">
                                <SectionHeader title="Inventory by Outlet" />
                            </div>
                            <TableWrapper>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-y border-surface-100 bg-surface-50/50">
                                            <th className={TH}>Outlet</th>
                                            <th className={TH_R}>SKUs</th>
                                            <th className={TH_R}>
                                                Total Units
                                            </th>
                                            <th className={TH_R}>Est. Value</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {byOutlet.map((row: any, i: number) => (
                                            <tr
                                                key={i}
                                                className="hover:bg-surface-50/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {row.outlet_name ??
                                                        "Warehouse"}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {row.item_count}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums font-semibold">
                                                    {row.total_quantity}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-400 text-sm">
                                                    {row.total_value !== null
                                                        ? fmtKes(
                                                              row.total_value,
                                                          )
                                                        : "-"}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </TableWrapper>
                        </div>
                    )}
                </div>
            )}

            {/* ── MOVEMENTS TAB ── */}
            {activeTab === "movements" && (
                <div className="space-y-6">
                    {/* Movement type summary */}
                    {byType.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="card p-5">
                                <SectionHeader title="Movement by Type" />
                                <div className="space-y-3 mt-2">
                                    {byType.map((bt: any, i: number) => {
                                        const maxUnits = Math.max(
                                            ...byType.map(
                                                (x: any) => x.total_units,
                                            ),
                                            1,
                                        );
                                        return (
                                            <div key={bt.type}>
                                                <div className="flex justify-between text-sm mb-1">
                                                    <span
                                                        className={clsx(
                                                            "capitalize font-medium",
                                                            OUT_TYPES.has(
                                                                bt.type,
                                                            )
                                                                ? "text-danger"
                                                                : "text-success",
                                                        )}
                                                    >
                                                        {bt.type?.replace(
                                                            /_/g,
                                                            " ",
                                                        )}
                                                    </span>
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs text-surface-400">
                                                            {bt.count}×
                                                        </span>
                                                        <span
                                                            className={clsx(
                                                                "font-semibold tabular-nums",
                                                                OUT_TYPES.has(
                                                                    bt.type,
                                                                )
                                                                    ? "text-danger"
                                                                    : "text-success",
                                                            )}
                                                        >
                                                            {OUT_TYPES.has(
                                                                bt.type,
                                                            )
                                                                ? "−"
                                                                : "+"}
                                                            {bt.total_units}
                                                        </span>
                                                    </div>
                                                </div>
                                                <ProgressBar
                                                    value={bt.total_units}
                                                    max={maxUnits}
                                                    color={
                                                        OUT_TYPES.has(bt.type)
                                                            ? "#EF4444"
                                                            : "#10B981"
                                                    }
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {movTrendData.length > 0 && (
                                <div className="card p-5">
                                    <SectionHeader title="Daily Movement Trend" />
                                    <ResponsiveContainer
                                        width="100%"
                                        height={200}
                                    >
                                        <LineChart data={movTrendData}>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke="#F1F5F9"
                                            />
                                            <XAxis
                                                dataKey="date"
                                                tick={{ fontSize: 10 }}
                                            />
                                            <YAxis
                                                tick={{ fontSize: 11 }}
                                                width={36}
                                            />
                                            <Tooltip />
                                            <Legend />
                                            <Line
                                                type="monotone"
                                                dataKey="in"
                                                stroke="#10B981"
                                                strokeWidth={2}
                                                dot={false}
                                                name="In"
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="out"
                                                stroke="#EF4444"
                                                strokeWidth={2}
                                                dot={false}
                                                name="Out"
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Transactions table */}
                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-4">
                            <SectionHeader title="Movement Log">
                                <div className="flex items-center gap-2">
                                    <select
                                        className="input w-40 text-sm"
                                        value={movTypeFilter}
                                        onChange={(e) =>
                                            setMovTypeFilter(e.target.value)
                                        }
                                    >
                                        <option value="">All types</option>
                                        {byType.map((bt: any) => (
                                            <option
                                                key={bt.type}
                                                value={bt.type}
                                            >
                                                {bt.type?.replace(/_/g, " ")}
                                            </option>
                                        ))}
                                    </select>
                                    <ExportCsvButton
                                        path="inventory/movement"
                                        params={dr.params}
                                    />
                                </div>
                            </SectionHeader>
                        </div>
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
                                        <th className={TH}>Date & Time</th>
                                        <th className={TH}>Product</th>
                                        <th className={TH}>SKU</th>
                                        <th className={TH}>Type</th>
                                        <th className={TH}>
                                            Reference / Notes
                                        </th>
                                        <th className={TH_R}>Qty Change</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {movementQuery.isLoading ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-8 text-center"
                                            >
                                                <Spinner />
                                            </td>
                                        </tr>
                                    ) : transactions.length === 0 ? (
                                        <EmptyRow cols={6} />
                                    ) : (
                                        transactions
                                            .slice(0, 100)
                                            .map((tx: any, i: number) => (
                                                <tr
                                                    key={i}
                                                    className="hover:bg-surface-50/50 transition-colors"
                                                >
                                                    <td className="px-4 py-3 text-sm text-surface-500 whitespace-nowrap">
                                                        {dayjs(
                                                            tx.created_at,
                                                        ).format(
                                                            "D MMM YYYY HH:mm",
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 font-medium text-surface-900">
                                                        {tx.product_name}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                            {tx.sku ?? "-"}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={clsx(
                                                                "px-2 py-0.5 rounded text-xs font-medium capitalize",
                                                                OUT_TYPES.has(
                                                                    tx.type,
                                                                )
                                                                    ? "bg-danger-light text-danger"
                                                                    : "bg-success-light text-success",
                                                            )}
                                                        >
                                                            {tx.type?.replace(
                                                                /_/g,
                                                                " ",
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-surface-500 max-w-xs truncate">
                                                        {tx.reference ?? "-"}
                                                    </td>
                                                    <td
                                                        className={clsx(
                                                            "px-4 py-3 text-right font-semibold tabular-nums",
                                                            OUT_TYPES.has(
                                                                tx.type,
                                                            )
                                                                ? "text-danger"
                                                                : "text-success",
                                                        )}
                                                    >
                                                        {OUT_TYPES.has(tx.type)
                                                            ? `−${Math.abs(tx.quantity)}`
                                                            : `+${Math.abs(tx.quantity)}`}
                                                    </td>
                                                </tr>
                                            ))
                                    )}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Intelligence tab ─────────────────────────────────────────────────────────
// MetricEngine-backed: real valuation (cost + retail from product_prices),
// ABC classes with days-of-cover, stockout risks, dead stock, material health.

function InventoryIntelligence({ start, end }: { start: string; end: string }) {
    const { data, isLoading } = useQuery({
        queryKey: ["inventory-intelligence", start, end],
        queryFn: () => reportsApi.inventoryIntelligence(start, end),
        enabled: !!start && !!end,
        staleTime: 60_000,
    });
    if (isLoading || !data) return <div className="flex justify-center py-16"><Spinner /></div>;
    const { health, abc, stockout_risks, dead_stock, materials } = data;
    const CLASS_CLR: Record<string, string> = { A: "bg-emerald-100 text-emerald-700", B: "bg-amber-100 text-amber-700", C: "bg-surface-100 text-surface-500" };

    return (
        <div className="space-y-6">
            <div className={KPI_GRID}>
                <KpiCard label="Stock at Cost" value={fmtKes(health.cost_value)} sub={`${health.units} units · ${health.skus} SKUs`} />
                <KpiCard label="Stock at Retail" value={fmtKes(health.retail_value)} sub="if everything sold at list" />
                <KpiCard label="Low / Out" value={`${health.low_stock} / ${health.out_of_stock}`} color={health.out_of_stock > 0 ? "text-danger" : "text-warning"} sub="low stock / out of stock" />
                <KpiCard label="Material Stock" value={fmtKes(materials.cost_value)} sub={`${materials.materials} materials at unit cost`} />
            </div>

            {stockout_risks.length > 0 && (
                <div className="card card-body border border-red-200 bg-red-50/40">
                    <SectionHeader title="⚠ Stockout risk — best sellers running dry" />
                    <div className="space-y-1.5 mt-1">
                        {stockout_risks.map((r: any) => (
                            <div key={r.product_id} className="flex items-center gap-3 text-xs">
                                <span className="font-medium text-surface-800 flex-1 truncate">{r.product}</span>
                                <span className="text-surface-500">{r.on_hand} left</span>
                                <span className="font-bold text-red-700 tabular-nums">~{r.cover_days}d cover</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div className="card card-body">
                <SectionHeader title="ABC — where the revenue actually comes from" />
                <div className="flex gap-2 mt-1 mb-3">
                    {(["A", "B", "C"] as const).map(c => (
                        <span key={c} className={clsx("text-2xs font-bold px-2 py-1 rounded-full", CLASS_CLR[c])}>
                            {c}: {abc.classes[c].count} items · {fmtKes(abc.classes[c].revenue)}
                        </span>
                    ))}
                </div>
                <TableWrapper>
                    <table className="w-full text-xs">
                        <thead><tr>
                            <th className={TH}>Product</th><th className={TH}>Class</th>
                            <th className={TH_R}>Revenue</th><th className={TH_R}>Share</th>
                            <th className={TH_R}>Sold</th><th className={TH_R}>On Hand</th><th className={TH_R}>Cover</th>
                        </tr></thead>
                        <tbody className="divide-y divide-surface-50">
                            {abc.items.map((i: any) => (
                                <tr key={i.product_id}>
                                    <td className="px-3 py-2 font-medium text-surface-800">{i.product}</td>
                                    <td className="px-3 py-2"><span className={clsx("text-2xs font-bold px-1.5 py-0.5 rounded", CLASS_CLR[i.class])}>{i.class}</span></td>
                                    <td className="px-3 py-2 text-right tabular-nums">{fmtKes(i.revenue)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums text-surface-500">{i.share_pct}%</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{i.units_sold}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{i.on_hand}</td>
                                    <td className={clsx("px-3 py-2 text-right tabular-nums font-semibold",
                                        i.cover_days != null && i.cover_days < 7 ? "text-danger" : "text-surface-600")}>
                                        {i.cover_days != null ? `${i.cover_days}d` : "—"}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="card card-body">
                    <SectionHeader title="Dead stock (nothing sold in 90 days)" />
                    {dead_stock.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">Nothing gathering dust.</p>
                    ) : (
                        <div className="space-y-1.5 mt-1">
                            {dead_stock.map((d: any) => (
                                <div key={d.product_id} className="flex items-center gap-3 text-xs">
                                    <span className="font-medium text-surface-800 flex-1 truncate">{d.product}</span>
                                    <span className="text-2xs text-surface-400">{d.last_sold ? `last ${new Date(d.last_sold).toLocaleDateString("en-KE", { month: "short", year: "2-digit" })}` : "never sold"}</span>
                                    <span className="font-bold tabular-nums text-surface-700">{d.units} pcs</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
                <div className="card card-body">
                    <SectionHeader title="Materials below reorder" />
                    {materials.below_reorder.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">All materials above their reorder points.</p>
                    ) : (
                        <div className="space-y-1.5 mt-1">
                            {materials.below_reorder.map((m: any) => (
                                <div key={m.id} className="flex items-center gap-3 text-xs">
                                    <span className="font-medium text-surface-800 flex-1 truncate">{m.name}</span>
                                    <span className="tabular-nums text-danger font-bold">{Number(m.available).toLocaleString()} {m.unit}</span>
                                    <span className="text-2xs text-surface-400">reorder at {Number(m.reorder_point).toLocaleString()}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
