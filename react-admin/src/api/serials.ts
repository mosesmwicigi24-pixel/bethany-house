import { get, post } from "@/api/client";

export type SerialStatus =
    | "in_production"
    | "in_stock"
    | "sold"
    | "dispatched"
    | "returned"
    | "cancelled"
    | "missing";

export interface ProductSerial {
    id: number;
    serial_number: string;
    status: SerialStatus;
    stocked_at?: string | null;
    days_in_stock?: number | null;
    aged?: boolean;
    product_id: number;
    product_name: string | null;
    product_sku: string | null;
    production_order_id: number | null;
    production_order_number: string | null;
    outlet_id: number | null;
    outlet_name: string | null;
    order_id: number | null;
    order_number: string | null;
    sold_at: string | null;
    dispatched_at: string | null;
    created_at: string | null;
}

export interface SerialFilters {
    status?: string;
    product_id?: number | string;
    production_order_id?: number | string;
    search?: string;
    aged?: string | number;
    page?: number;
    per_page?: number;
}

export interface ReconcileResult {
    message: string;
    matched_count: number;
    missing: { id: number; serial_number: string }[];
    unexpected: string[];
    flagged_missing: boolean;
}

export const serialsApi = {
    list: (params?: SerialFilters) =>
        get<{
            data: ProductSerial[];
            meta: { current_page: number; last_page: number; total: number; per_page: number };
            summary: Record<string, number>;
            aged_count: number;
            aging_days: number;
        }>("/v1/admin/product-serials", { params: params as any }),

    get: (id: number) =>
        get<{ serial: ProductSerial; timeline: { event: string; at: string | null; ref: string | null }[] }>(
            `/v1/admin/product-serials/${id}`,
        ),

    reconcile: (data: { product_id: number; outlet_id?: number | null; serials: string[]; flag_missing?: boolean }) =>
        post<ReconcileResult>("/v1/admin/product-serials/reconcile", data),
};
