import { get, post, put } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface StockItem {
    id: number;
    product_id: number;
    product_variant_id: number | null;
    outlet_id: number;
    quantity_on_hand: number;
    quantity_reserved: number;
    quantity_available: number;
    reorder_point: number;
    reorder_quantity: number;
    last_counted_at: string | null;
    status: "in_stock" | "low_stock" | "out_of_stock";
    product: {
        id: number;
        sku: string;
        name: string;
        image_url: string | null;
    } | null;
    variant: {
        id: number;
        sku: string;
        variant_name: string;
        attributes: Record<string, string>;
    } | null;
    outlet: {
        id: number;
        name: string;
        code: string | null;
    } | null;
}

export interface StockTransaction {
    id: number;
    transaction_type: string;
    reference_type: string | null;
    reference_id: number | null;
    quantity_change: number;
    quantity_before: number;
    quantity_after: number;
    notes: string | null;
    created_at: string;
    created_by: { id: number; name: string } | null;
}

export interface StockStats {
    total_skus: number;
    in_stock: number;
    low_stock: number;
    out_of_stock: number;
}

export interface OpeningStockEntry {
    product_id: number;
    product_variant_id?: number | null;
    outlet_id: number;
    quantity: number;
    reorder_point?: number;
    reorder_quantity?: number;
    notes?: string;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const stockApi = {
    list: (params?: Record<string, string>) =>
        get<{ data: StockItem[]; meta: any; stats: StockStats }>(
            "/v1/admin/inventory/stock-levels",
            { params },
        ),

    get: (id: number) =>
        get<{ item: StockItem; transactions: StockTransaction[] }>(
            `/v1/admin/inventory/stock-levels/${id}`,
        ),

    byProduct: (productId: number) =>
        get<{ product: any; data: StockItem[]; by_outlet: any[]; totals: any }>(
            `/v1/admin/inventory/stock-levels/by-product/${productId}`,
        ),

    history: (id: number, params?: Record<string, string>) =>
        get<{ item: StockItem; data: StockTransaction[]; meta: any }>(
            `/v1/admin/inventory/stock-levels/${id}/history`,
            { params },
        ),

    setOpeningStock: (entries: OpeningStockEntry[]) =>
        post<{ message: string; data: StockItem[] }>(
            "/v1/admin/inventory/stock-levels/opening",
            { entries },
        ),

    update: (
        id: number,
        data: { reorder_point?: number; reorder_quantity?: number },
    ) =>
        put<{ message: string; item: StockItem }>(
            `/v1/admin/inventory/stock-levels/${id}`,
            data,
        ),
};