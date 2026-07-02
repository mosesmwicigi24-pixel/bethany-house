import { get, post, put, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface RawMaterial {
    id: number;
    code: string;
    name: string;
    description: string | null;
    category: string | null; // actual DB column
    material_type: string | null; // alias = category
    unit_of_measure: string;
    unit_cost: number; // actual DB column
    cost_per_unit: number; // alias = unit_cost
    reorder_point: number;
    is_active: boolean;
    total_stock: number;
    stock_status: "in_stock" | "low_stock" | "out_of_stock";
    stock_value: number;
    supplier: null; // column doesn't exist in DB
    inventory?: MaterialInventoryRecord[];
    created_at: string;
    updated_at: string;
}

export interface MaterialInventoryRecord {
    id: number;
    material_id: number;
    outlet_id: number;
    quantity_on_hand: number;
    last_counted_at: string | null;
    outlet: { id: number; name: string; code: string | null } | null;
}

export interface MaterialTransaction {
    id: number;
    transaction_type: string;
    type_label: string;
    quantity_change: number;
    quantity_before: number;
    quantity_after: number;
    unit_cost: number | null;
    notes: string | null;
    outlet: { id: number; name: string } | null;
    created_at: string;
    created_by: { id: number; name: string } | null;
}

export interface MaterialStats {
    total: number;
    active: number;
    low_stock: number;
    out_of_stock: number;
    categories: string[];
    types?: string[];
}

export interface BomUsage {
    product_id: number;
    sku: string;
    product_name: string | null;
    quantity: number;
    unit_of_measure: string;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const rawMaterialsApi = {
    list: (params?: Record<string, string>) =>
        get<{ data: RawMaterial[]; meta: any; stats: MaterialStats }>(
            "/v1/admin/inventory/materials",
            { params },
        ),

    get: (id: number) =>
        get<{
            material: RawMaterial;
            transactions: MaterialTransaction[];
            bom_usage: BomUsage[];
        }>(`/v1/admin/inventory/materials/${id}`),

    create: (data: {
        code: string;
        name: string;
        description?: string;
        category?: string;
        unit_of_measure: string;
        unit_cost: number;
        reorder_point?: number;
        is_active: boolean;
    }) =>
        post<{ message: string; material: RawMaterial }>(
            "/v1/admin/inventory/materials",
            data,
        ),

    update: (
        id: number,
        data: Partial<{
            code: string;
            name: string;
            description: string;
            category: string;
            unit_of_measure: string;
            unit_cost: number;
            reorder_point: number;
            is_active: boolean;
        }>,
    ) =>
        put<{ message: string; material: RawMaterial }>(
            `/v1/admin/inventory/materials/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/inventory/materials/${id}`),

    receive: (
        id: number,
        data: {
            outlet_id: number;
            quantity: number;
            transaction_type:
                | "opening_stock"
                | "purchase"
                | "adjustment"
                | "transfer_in";
            unit_cost?: number;
            notes?: string;
            reference?: string;
        },
    ) =>
        post<{ message: string; inventory: MaterialInventoryRecord }>(
            `/v1/admin/inventory/materials/${id}/receive`,
            data,
        ),

    adjust: (
        id: number,
        data: {
            outlet_id: number;
            quantity_change: number;
            transaction_type:
                | "adjustment"
                | "damaged"
                | "correction"
                | "transfer_out";
            notes: string;
        },
    ) =>
        post<{ message: string; inventory: MaterialInventoryRecord }>(
            `/v1/admin/inventory/materials/${id}/adjust`,
            data,
        ),

    transactions: (id: number, params?: Record<string, string>) =>
        get<{ data: MaterialTransaction[]; meta: any }>(
            `/v1/admin/inventory/materials/${id}/transactions`,
            { params },
        ),

    lowStock: () =>
        get<{ data: RawMaterial[]; count: number }>(
            "/v1/admin/inventory/materials/low-stock",
        ),
};