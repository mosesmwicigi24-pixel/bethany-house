/**
 * intelligence.ts — API client for all intelligence features.
 *
 * Place at: src/api/intelligence.ts
 */

import { get, post } from "@/api/client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ReorderSuggestion {
    inventory_item_id: number;
    product_id:        number;
    product_name:      string;
    product_image:     string | null;
    sku:               string;
    variant:           { id: number; name: string; sku: string } | null;
    outlet:            { id: number; name: string } | null;
    quantity_on_hand:  number;
    quantity_available:number;
    reorder_point:     number;
    reorder_quantity:  number;
    severity:          "out_of_stock" | "low_stock";
}

export interface TailorWorkload {
    id:               number;
    name:             string;
    active_tasks:     number;
    overdue_tasks:    number;
    avg_hours_per_task: number;
    completion_rate:  number;
    workload_score:   number;
    recommendation:   "available" | "light" | "moderate" | "heavy";
}

export interface ChurnRiskCustomer {
    customer_id:       number;
    user_id:           number;
    name:              string;
    email:             string;
    phone:             string | null;
    total_orders:      number;
    lifetime_value:    number;
    loyalty_points:    number;
    last_order_at:     string;
    days_since_last:   number;
    avg_interval_days: number;
    overdue_by_days:   number;
    risk_level:        "high" | "medium";
}

export interface CountryStat {
    country_code: string;
    country_name: string;
    customers:    number;
    orders:       number;
    revenue:      number;
    currency:     string | null;
}

export interface CustomerGeography {
    countries: CountryStat[];
    summary: {
        located_customers:   number;
        unlocated_customers: number;
        distinct_countries:  number;
        top_country_code:    string | null;
        top_country_name:    string | null;
    };
}

export interface ChannelStat {
    channel:   string;
    contacts:  number;
    customers: number;
    messages:  number;
    last_seen: string | null;
    connected: boolean;
}

export interface ChannelEngagement {
    channels: ChannelStat[];
    top_customers: { customer_id: number; name: string; messages: number; channels: string[] }[];
    summary: { connected_channels: number; message_channels: number };
}

export interface MaterialShortage {
    material_id:    number;
    material_name:  string;
    material_code:  string | null;
    unit:           string;
    total_needed:   number;
    orders_needing: number;
    available:      number;
    shortfall:      number;
    severity:       "out_of_stock" | "insufficient";
}

export interface BudgetWarning {
    budget_id:           number;
    category_id:         number;
    category_name:       string;
    outlet_id:           number | null;
    outlet_name:         string;
    budgeted_amount:     number;
    actual_spend:        number;
    utilization_percent: number;
    remaining:           number;
    severity:            "warning" | "exceeded";
    period:              string;
}

export interface EntityPreview {
    type:        "order" | "production_order";
    id:          number;
    label:       string;
    status:      string;
    badge:       { label: string; color: string };
    meta:        string;
    subtitle:    string;
    url:         string;
    // order-specific
    payment_status?: string;
    created_at?:     string;
    // production-order-specific
    due_date?:   string;
    priority?:   string;
    is_overdue?: boolean;
}

// ── API ───────────────────────────────────────────────────────────────────────

const BASE = "/v1/admin/intelligence";

export const intelligenceApi = {
    reorderSuggestions: () =>
        get<{ suggestions: ReorderSuggestion[]; total: number }>(`${BASE}/reorder-suggestions`),

    triggerAutoReorder: (inventoryItemId: number) =>
        post<{ message: string; purchase_order: unknown }>(`${BASE}/auto-reorder/${inventoryItemId}`, {}),

    tailorWorkload: () =>
        get<{ tailors: TailorWorkload[] }>(`${BASE}/tailor-workload`),

    churnRisk: (limit = 50) =>
        get<{ customers: ChurnRiskCustomer[] }>(`${BASE}/churn-risk`, { params: { limit } }),

    customerGeography: () =>
        get<CustomerGeography>(`${BASE}/customer-geography`),

    channelEngagement: () =>
        get<ChannelEngagement>(`${BASE}/channel-engagement`),

    materialShortages: () =>
        get<{ shortages: MaterialShortage[] }>(`${BASE}/material-shortages`),

    materialShortagesPreflight: (productId: number, quantity: number) =>
        post<{ shortages: MaterialShortage[] }>(`${BASE}/material-shortages/preflight`, {
            product_id: productId,
            quantity,
        }),

    budgetWarnings: () =>
        get<{ warnings: BudgetWarning[] }>(`${BASE}/budget-warnings`),

    smartTasks: () =>
        get<unknown[]>(`${BASE}/smart-tasks`),

    entityPreviews: (entities: Array<{ type: string; id: number }>) =>
        post<{ previews: Record<string, EntityPreview> }>(`${BASE}/entity-previews`, { entities }),
};