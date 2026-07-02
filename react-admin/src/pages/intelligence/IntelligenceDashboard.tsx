/**
 * IntelligenceDashboard.tsx
 *
 * Central intelligence hub showing all 8 intelligence features as cards.
 * Each card is independently fetched, collapsible, and actionable.
 *
 * Place at: src/pages/intelligence/IntelligenceDashboard.tsx
 * Route:    /intelligence  (add to App.tsx and Sidebar nav)
 */

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { intelligenceApi, type ReorderSuggestion, type TailorWorkload,
         type ChurnRiskCustomer, type MaterialShortage, type BudgetWarning } from "@/api/intelligence";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";

// ── helpers ───────────────────────────────────────────────────────────────────

const fmt = (n: number) => new Intl.NumberFormat("en-KE", { style: "currency", currency: "KES", maximumFractionDigits: 0 }).format(n);
const fmtNum = (n: number) => new Intl.NumberFormat("en-KE").format(n);

function SectionCard({ title, icon, badge, badgeColor, children, action }: {
    title: string;
    icon: React.ReactNode;
    badge?: string | number;
    badgeColor?: string;
    children: React.ReactNode;
    action?: React.ReactNode;
}) {
    const [open, setOpen] = useState(true);
    return (
        <div className="card overflow-hidden">
            <div className="flex items-center gap-3 px-5 py-4 border-b border-surface-100 cursor-pointer select-none"
                 onClick={() => setOpen(v => !v)}>
                <div className="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center shrink-0">
                    {icon}
                </div>
                <h2 className="font-semibold text-surface-900 flex-1 text-sm">{title}</h2>
                {badge !== undefined && (
                    <span className={clsx("text-xs font-bold px-2 py-0.5 rounded-full", badgeColor ?? "bg-warning-light text-warning-dark")}>
                        {badge}
                    </span>
                )}
                {action && <div onClick={e => e.stopPropagation()}>{action}</div>}
                <svg className={clsx("w-4 h-4 text-surface-400 transition-transform", !open && "-rotate-90")}
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
            {open && <div>{children}</div>}
        </div>
    );
}

function EmptyRow({ message }: { message: string }) {
    return (
        <div className="py-8 text-center text-sm text-surface-400">{message}</div>
    );
}

// ── 1. Reorder suggestions ────────────────────────────────────────────────────

function ReorderSuggestions() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { can } = usePermissions();
    const canDraftPO = can("procurement.create");
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "reorder"],
        queryFn:  intelligenceApi.reorderSuggestions,
        staleTime: 60_000,
    });

    const trigger = useMutation({
        mutationFn: (id: number) => intelligenceApi.triggerAutoReorder(id),
        onSuccess: (res) => {
            toast.success("Draft PO created — review in Procurement.");
            qc.invalidateQueries({ queryKey: ["intelligence", "reorder"] });
        },
        onError: (e: any) => toast.error(e.message),
    });

    const suggestions = data?.suggestions ?? [];

    return (
        <SectionCard
            title="Reorder Suggestions"
            icon={<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>}
            badge={suggestions.length || undefined}
            badgeColor={suggestions.length > 0 ? "bg-danger-light text-danger" : undefined}
        >
            {isLoading ? <div className="py-6 flex justify-center"><Spinner /></div> :
             suggestions.length === 0 ? <EmptyRow message="All stock levels healthy — no reorders needed." /> : (
                <div className="divide-y divide-surface-50">
                    {suggestions.map((s: ReorderSuggestion) => (
                        <div key={s.inventory_item_id} className="flex items-center gap-3 px-5 py-3">
                            {s.product_image ? (
                                <img src={s.product_image} alt={s.product_name} className="w-9 h-9 rounded-lg object-cover border border-surface-100 shrink-0"/>
                            ) : (
                                <div className="w-9 h-9 rounded-lg bg-surface-100 flex items-center justify-center shrink-0 text-surface-300">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                                </div>
                            )}
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-surface-900 truncate">{s.product_name}</p>
                                <p className="text-xs text-surface-400">{s.sku}{s.outlet ? ` · ${s.outlet.name}` : ""}</p>
                            </div>
                            <div className="text-right shrink-0">
                                <span className={clsx("text-xs font-bold px-2 py-0.5 rounded-full",
                                    s.severity === "out_of_stock" ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark")}>
                                    {s.severity === "out_of_stock" ? "Out of stock" : `${s.quantity_on_hand} left`}
                                </span>
                                <p className="text-xs text-surface-400 mt-0.5">Reorder: {s.reorder_quantity || "?"} units</p>
                            </div>
                            {canDraftPO && (
                            <button
                                onClick={() => trigger.mutate(s.inventory_item_id)}
                                disabled={trigger.isPending}
                                className="btn-primary btn-sm text-xs shrink-0"
                            >
                                Draft PO
                            </button>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

// ── 2. Tailor workload ────────────────────────────────────────────────────────

const workloadColors: Record<string, string> = {
    available: "bg-success-light text-success-dark",
    light:     "bg-brand-50 text-brand-700",
    moderate:  "bg-warning-light text-warning-dark",
    heavy:     "bg-danger-light text-danger",
};

function TailorWorkloadCard() {
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "workload"],
        queryFn:  intelligenceApi.tailorWorkload,
        staleTime: 30_000,
    });

    const tailors = data?.tailors ?? [];

    return (
        <SectionCard
            title="Tailor Workload"
            icon={<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>}
        >
            {isLoading ? <div className="py-6 flex justify-center"><Spinner /></div> :
             tailors.length === 0 ? <EmptyRow message="No active tailors found." /> : (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead><tr>
                            <th>Tailor</th>
                            <th>Active Tasks</th>
                            <th>Overdue</th>
                            <th>Avg Hours/Task</th>
                            <th>Completion Rate</th>
                            <th>Workload</th>
                        </tr></thead>
                        <tbody>
                            {tailors.map((t: TailorWorkload) => (
                                <tr key={t.id}>
                                    <td className="font-medium text-surface-900">{t.name}</td>
                                    <td>{t.active_tasks}</td>
                                    <td>
                                        {t.overdue_tasks > 0
                                            ? <span className="text-danger font-semibold">{t.overdue_tasks}</span>
                                            : <span className="text-surface-400">0</span>}
                                    </td>
                                    <td>{t.avg_hours_per_task}h</td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1 bg-surface-100 rounded-full h-1.5 max-w-[80px]">
                                                <div className="bg-brand-500 h-1.5 rounded-full"
                                                     style={{ width: `${t.completion_rate}%` }}/>
                                            </div>
                                            <span className="text-xs text-surface-500">{t.completion_rate}%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span className={clsx("text-xs font-semibold px-2 py-0.5 rounded-full capitalize",
                                            workloadColors[t.recommendation])}>
                                            {t.recommendation}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}

// ── 3. Churn risk ─────────────────────────────────────────────────────────────

function ChurnRiskCard() {
    const navigate = useNavigate();
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "churn"],
        queryFn:  () => intelligenceApi.churnRisk(20),
        staleTime: 5 * 60_000,
    });

    const customers = data?.customers ?? [];
    const highRisk  = customers.filter((c: ChurnRiskCustomer) => c.risk_level === "high").length;

    return (
        <SectionCard
            title="Customer Churn Risk"
            icon={<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>}
            badge={highRisk > 0 ? `${highRisk} high risk` : customers.length > 0 ? customers.length : undefined}
            badgeColor={highRisk > 0 ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark"}
        >
            {isLoading ? <div className="py-6 flex justify-center"><Spinner /></div> :
             customers.length === 0 ? <EmptyRow message="No at-risk customers detected." /> : (
                <div className="divide-y divide-surface-50">
                    {customers.slice(0, 10).map((c: ChurnRiskCustomer) => (
                        <div key={c.customer_id}
                             className="flex items-center gap-3 px-5 py-3 hover:bg-surface-50/50 cursor-pointer transition-colors"
                             onClick={() => navigate(`/sales/customers/${c.customer_id}`)}>
                            <div className="w-8 h-8 rounded-full bg-surface-200 flex items-center justify-center text-xs font-bold text-surface-600 shrink-0">
                                {c.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-surface-900 truncate">{c.name}</p>
                                <p className="text-xs text-surface-400">{c.email} · {c.total_orders} orders · {fmt(c.lifetime_value)} LTV</p>
                            </div>
                            <div className="text-right shrink-0">
                                <p className="text-xs font-semibold text-surface-700">{c.days_since_last}d since last order</p>
                                <p className="text-xs text-surface-400">{c.overdue_by_days}d overdue (avg {c.avg_interval_days}d)</p>
                            </div>
                            <span className={clsx("text-xs font-bold px-2 py-0.5 rounded-full shrink-0",
                                c.risk_level === "high" ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark")}>
                                {c.risk_level}
                            </span>
                        </div>
                    ))}
                    {customers.length > 10 && (
                        <div className="px-5 py-3 text-xs text-surface-400 text-center">
                            +{customers.length - 10} more at-risk customers
                        </div>
                    )}
                </div>
            )}
        </SectionCard>
    );
}

// ── 4. Material shortages ─────────────────────────────────────────────────────

function MaterialShortagesCard() {
    const navigate = useNavigate();
    const { can } = usePermissions();
    const canCreatePO = can("procurement.create");
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "materials"],
        queryFn:  intelligenceApi.materialShortages,
        staleTime: 60_000,
    });

    const shortages = data?.shortages ?? [];
    const outOfStock = shortages.filter((s: MaterialShortage) => s.severity === "out_of_stock").length;

    return (
        <SectionCard
            title="Material Shortage Pre-flight"
            icon={<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>}
            badge={shortages.length > 0 ? (outOfStock > 0 ? `${outOfStock} out of stock` : `${shortages.length} short`) : undefined}
            badgeColor={outOfStock > 0 ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark"}
            action={shortages.length > 0 && canCreatePO ? (
                <button onClick={() => navigate("/procurement/purchase-orders/new")}
                    className="btn-primary btn-sm text-xs">
                    Create PO
                </button>
            ) : undefined}
        >
            {isLoading ? <div className="py-6 flex justify-center"><Spinner /></div> :
             shortages.length === 0 ? <EmptyRow message="All materials sufficient for current production queue." /> : (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead><tr>
                            <th>Material</th>
                            <th>Orders Needing</th>
                            <th>Total Needed</th>
                            <th>Available</th>
                            <th>Shortfall</th>
                            <th>Status</th>
                        </tr></thead>
                        <tbody>
                            {shortages.map((s: MaterialShortage) => (
                                <tr key={s.material_id}>
                                    <td>
                                        <p className="font-medium text-surface-900">{s.material_name}</p>
                                        {s.material_code && <p className="text-xs text-surface-400">{s.material_code}</p>}
                                    </td>
                                    <td>{s.orders_needing}</td>
                                    <td>{fmtNum(s.total_needed)} {s.unit}</td>
                                    <td>{fmtNum(s.available)} {s.unit}</td>
                                    <td className="text-danger font-semibold">{fmtNum(s.shortfall)} {s.unit}</td>
                                    <td>
                                        <span className={clsx("text-xs font-bold px-2 py-0.5 rounded-full",
                                            s.severity === "out_of_stock" ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark")}>
                                            {s.severity === "out_of_stock" ? "Out of stock" : "Insufficient"}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}

// ── 6. Budget warnings ────────────────────────────────────────────────────────

function BudgetWarningsCard() {
    const navigate = useNavigate();
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "budgets"],
        queryFn:  intelligenceApi.budgetWarnings,
        staleTime: 5 * 60_000,
    });

    const warnings  = data?.warnings ?? [];
    const exceeded  = warnings.filter((w: BudgetWarning) => w.severity === "exceeded").length;

    return (
        <SectionCard
            title="Expense Budget Warnings"
            icon={<svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>}
            badge={warnings.length > 0 ? (exceeded > 0 ? `${exceeded} exceeded` : `${warnings.length} warning`) : undefined}
            badgeColor={exceeded > 0 ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark"}
        >
            {isLoading ? <div className="py-6 flex justify-center"><Spinner /></div> :
             warnings.length === 0 ? <EmptyRow message="All expense budgets within healthy limits." /> : (
                <div className="divide-y divide-surface-50">
                    {warnings.map((w: BudgetWarning) => (
                        <div key={w.budget_id}
                             className="flex items-center gap-4 px-5 py-3 hover:bg-surface-50/50 cursor-pointer transition-colors"
                             onClick={() => navigate("/expenses")}>
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2">
                                    <p className="text-sm font-medium text-surface-900">{w.category_name}</p>
                                    <span className="text-xs text-surface-400">{w.outlet_name}</span>
                                </div>
                                <div className="flex items-center gap-2 mt-1">
                                    <div className="flex-1 bg-surface-100 rounded-full h-1.5 max-w-[160px]">
                                        <div className={clsx("h-1.5 rounded-full transition-all",
                                            w.severity === "exceeded" ? "bg-danger" : "bg-warning")}
                                             style={{ width: `${Math.min(w.utilization_percent, 100)}%` }}/>
                                    </div>
                                    <span className="text-xs text-surface-500">{Math.round(w.utilization_percent)}%</span>
                                </div>
                            </div>
                            <div className="text-right shrink-0">
                                <p className="text-xs font-semibold text-surface-700">{fmt(w.actual_spend)} / {fmt(w.budgeted_amount)}</p>
                                <p className="text-xs text-surface-400">
                                    {w.severity === "exceeded"
                                        ? <span className="text-danger">Over by {fmt(w.actual_spend - w.budgeted_amount)}</span>
                                        : <span>{fmt(w.remaining)} remaining</span>
                                    }
                                </p>
                            </div>
                            <span className={clsx("text-xs font-bold px-2 py-0.5 rounded-full shrink-0",
                                w.severity === "exceeded" ? "bg-danger-light text-danger" : "bg-warning-light text-warning-dark")}>
                                {w.severity}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function IntelligenceDashboard() {
    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header">
                <h1 className="page-title">Intelligence</h1>
                <p className="page-subtitle">
                    Proactive signals across stock, production, customers, and finance
                </p>
            </div>

            <div className="grid grid-cols-1 gap-5">
                <ReorderSuggestions />
                <TailorWorkloadCard />
                <ChurnRiskCard />
                <MaterialShortagesCard />
                <BudgetWarningsCard />
            </div>
        </div>
    );
}