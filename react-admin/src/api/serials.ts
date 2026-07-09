import { get } from "@/api/client";

export type SerialStatus =
    | "in_production"
    | "in_stock"
    | "sold"
    | "dispatched"
    | "returned"
    | "cancelled";

export interface ProductSerial {
    id: number;
    serial_number: string;
    status: SerialStatus;
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
    page?: number;
    per_page?: number;
}

export const serialsApi = {
    list: (params?: SerialFilters) =>
        get<{
            data: ProductSerial[];
            meta: { current_page: number; last_page: number; total: number; per_page: number };
            summary: Record<string, number>;
        }>("/v1/admin/product-serials", { params: params as any }),

    get: (id: number) =>
        get<{ serial: ProductSerial; timeline: { event: string; at: string | null; ref: string | null }[] }>(
            `/v1/admin/product-serials/${id}`,
        ),
};
