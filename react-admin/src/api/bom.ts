import { get, post, put, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface BomMaterial {
    id: number;
    code: string;
    name: string;
    material_type: string;
    unit_of_measure: string;
    cost_per_unit: number;
}

export interface BomItem {
    id?: number;
    material_id: number;
    quantity: number;
    unit_of_measure: string;
    notes?: string;
    material?: BomMaterial;
    line_cost?: number;
    stock_on_hand?: number;
}

export interface Bom {
    id: number;
    product_id: number;
    product_variant_id: number | null;
    variant?: { id: number; variant_name: string; sku: string } | null;
    version: number;
    is_active: boolean;
    notes: string | null;
    items: BomItem[];
    total_cost: number;
    items_count: number;
    created_at: string;
    updated_at: string;
}

export interface BomFeasibility {
    quantity: number;
    feasible: boolean;
    shortfalls: {
        material_id: number;
        material_name: string;
        uom: string;
        required: number;
        available: number;
        shortfall: number;
    }[];
    summary: string;
}

export interface Material {
    id: number;
    code: string;
    name: string;
    description: string | null;
    material_type: string;
    unit_of_measure: string;
    cost_per_unit: number;
    is_active: boolean;
    stock_quantity?: number;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const bomApi = {
    list: (productId: number) =>
        get<{ data: Bom[]; product: any }>(
            `/v1/admin/products/${productId}/bom`,
        ),

    get: (productId: number, bomId: number) =>
        get<{ bom: Bom }>(`/v1/admin/products/${productId}/bom/${bomId}`),

    save: (
        productId: number,
        data: {
            notes?: string;
            product_variant_id?: number | null;
            items: Omit<
                BomItem,
                "id" | "material" | "line_cost" | "stock_on_hand"
            >[];
        },
    ) =>
        post<{ message: string; bom: Bom }>(
            `/v1/admin/products/${productId}/bom`,
            data,
        ),

    update: (
        productId: number,
        bomId: number,
        data: {
            notes?: string;
            items: Omit<
                BomItem,
                "id" | "material" | "line_cost" | "stock_on_hand"
            >[];
        },
    ) =>
        put<{ message: string; bom: Bom }>(
            `/v1/admin/products/${productId}/bom/${bomId}`,
            data,
        ),

    delete: (productId: number, bomId: number) =>
        del<{ message: string }>(
            `/v1/admin/products/${productId}/bom/${bomId}`,
        ),

    activate: (productId: number, bomId: number) =>
        put<{ message: string }>(
            `/v1/admin/products/${productId}/bom/${bomId}/activate`,
        ),

    feasibility: (productId: number, bomId: number, quantity: number) =>
        get<BomFeasibility>(
            `/v1/admin/products/${productId}/bom/${bomId}/feasibility`,
            { params: { quantity: String(quantity) } },
        ),
};

export const materialsSearchApi = {
    search: (q: string) =>
        get<{ data: Material[] }>("/v1/admin/inventory/materials", {
            params: { search: q, per_page: "30" },
        }),
    list: () =>
        get<{ data: Material[] }>("/v1/admin/inventory/materials", {
            params: { per_page: "200" },
        }),
};