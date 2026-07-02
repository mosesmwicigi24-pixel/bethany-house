import { get, post, put, patch, del } from "@/api/client";

// ─── Types ────────────────────────────────────────────────────────────────────

export type SupplierStatus = "active" | "inactive";
export type POStatus =
    | "draft"
    | "pending_approval"
    | "approved"
    | "ordered"
    | "partially_received"
    | "received"
    | "cancelled";

export interface Supplier {
    id: number;
    company_code: string | null;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
    payment_terms: string | null;
    supply_category: string | null;
    notes: string | null;
    status: SupplierStatus;
    created_at: string;
    updated_at: string;
}

export interface SupplierStats {
    total_orders: number;
    pending_orders: number;
    total_value: number;
    last_order_date: string | null;
}

export interface SupplierPerformance {
    avg_delivery_variance: number;
    on_time_deliveries: number;
    late_deliveries: number;
    quality_rate: number;
    total_received: number;
    total_rejected: number;
}

export interface SupplierContact {
    id: number;
    supplier_id: number;
    name: string;
    role: string | null;
    email: string | null;
    phone: string | null;
    notes: string | null;
    created_at: string;
}

export interface SupplierDocument {
    id: number;
    supplier_id: number;
    name: string;
    type: string | null;
    url: string;
    created_at: string;
}

export interface POItem {
    id: number;
    purchase_order_id: number;
    item_type: "product" | "material";
    item_id: number;
    quantity: number;
    quantity_received: number;
    unit_price: number;
    subtotal: number;
    total_price?: number;
    product?: { id: number; name: string; sku: string };
    material?: { id: number; name: string; unit: string };
}

export interface PurchaseOrder {
    id: number;
    po_number: string;
    supplier_id: number;
    supplier?: Supplier;
    status: POStatus;
    order_date: string | null;
    expected_delivery_date: string;
    ordered_at: string | null;
    received_at: string | null;
    approved_at: string | null;
    approved_by?: { id: number; first_name: string; last_name: string } | null;
    currency: string;
    currency_code?: string;
    subtotal: number;
    shipping_cost: number;
    shipping_amount?: number;
    tax: number;
    tax_amount?: number;
    total: number;
    total_amount?: number;
    payment_terms: string | null;
    payment_status: string | null;
    invoice_number: string | null;
    is_paid: boolean;
    notes: string | null;
    items: POItem[];
    created_by?: { id: number; first_name: string; last_name: string };
    received_by?: { id: number; first_name: string; last_name: string };
    created_at: string;
    updated_at: string;
}

export interface POStatistics {
    total: number;
    draft: number;
    pending_approval: number;
    approved: number;
    ordered: number;
    partially_received: number;
    received: number;
    cancelled: number;
    total_value: number;
    outstanding_value: number;
}

export interface GRNItem {
    po_item_id: number;
    quantity_received: number;
    quantity_rejected?: number;
    quality_status: "passed" | "rejected";
    notes?: string;
}

export interface GRN {
    id: number;
    grn_number: string;
    purchase_order_id: number;
    received_date: string | null;
    received_at?: string;
    location_type?: string;
    outlet_id?: number | null;
    notes?: string | null;
    invoice_number?: string | null;
    received_by?: { id: number; first_name: string; last_name: string } | null;
}

export interface GRNDetail {
    grn: {
        id: number;
        grn_number: string;
        purchase_order_id: number;
        received_date: string | null;
        notes: string | null;
        invoice_number?: string | null;
        outlet_id?: number | null;
        created_at: string;
        purchase_order: {
            id: number;
            po_number: string;
            supplier?: { id: number; name: string; company_code: string | null } | null;
        } | null;
        received_by: { id: number; first_name: string; last_name: string } | null;
        items: Array<{
            id: number;
            quantity_received: number;
            quantity_rejected: number;
            condition: string;
            notes: string | null;
            purchase_order_item: {
                id: number;
                item_type: string;
                description: string;
                unit_price: number;
                product: { name: string; sku: string } | null;
                material: { name: string } | null;
            } | null;
        }>;
    };
}

export interface PurchaseReturn {
    id: number;
    return_number: string;
    purchase_order_id: number;
    po_number?: string;
    supplier_name?: string;
    total_items?: number;
    notes?: string;
    created_at: string;
    items: Array<{ po_item_id: number; quantity: number; reason: string }>;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number;
        to: number;
    };
}

// ─── Supplier API ─────────────────────────────────────────────────────────────

export const supplierApi = {
    list: (params?: Record<string, string | number>) =>
        get<PaginatedResponse<Supplier>>("/v1/admin/suppliers", { params }),

    export: () =>
        get<Blob>("/v1/admin/suppliers/export"),

    get: (id: number) =>
        get<{
            supplier: Supplier;
            stats: SupplierStats;
            recent_orders: PurchaseOrder[];
        }>(`/v1/admin/suppliers/${id}`),

    create: (data: Partial<Supplier>) =>
        post<{ message: string; supplier: Supplier }>("/v1/admin/suppliers", data),

    update: (id: number, data: Partial<Supplier>) =>
        put<{ message: string; supplier: Supplier }>(
            `/v1/admin/suppliers/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/suppliers/${id}`),

    performance: (id: number) =>
        get<SupplierPerformance>(`/v1/admin/suppliers/${id}/performance`),

    purchaseOrders: (id: number, params?: Record<string, string>) =>
        get<PaginatedResponse<PurchaseOrder>>(
            `/v1/admin/suppliers/${id}/purchase-orders`,
            { params },
        ),

    updateRating: (id: number, rating: number) =>
        put<{ message: string }>(`/v1/admin/suppliers/${id}/rating`, { rating }),

    contacts: (id: number) =>
        get<{ data: SupplierContact[] }>(`/v1/admin/suppliers/${id}/contacts`),

    addContact: (id: number, data: Partial<SupplierContact>) =>
        post<{ message: string; contact: SupplierContact }>(
            `/v1/admin/suppliers/${id}/contacts`,
            data,
        ),

    documents: (id: number) =>
        get<{ data: SupplierDocument[] }>(`/v1/admin/suppliers/${id}/documents`),

    uploadDocument: (id: number, formData: FormData) =>
        post<{ message: string; document: SupplierDocument }>(
            `/v1/admin/suppliers/${id}/documents`,
            formData,
        ),
};

// ─── Purchase Order API ───────────────────────────────────────────────────────

export const purchaseOrderApi = {
    list: (params?: Record<string, string | number>) =>
        get<PaginatedResponse<PurchaseOrder>>("/v1/admin/purchase-orders", {
            params,
        }),

    statistics: () =>
        get<POStatistics>("/v1/admin/purchase-orders/statistics"),

    get: (id: number) =>
        get<{ purchase_order: PurchaseOrder; receiving_history: GRN[] }>(
            `/v1/admin/purchase-orders/${id}`,
        ),

    create: (data: {
        supplier_id: number;
        expected_delivery_date: string;
        currency: string;
        shipping_cost?: number;
        tax?: number;
        payment_terms?: string;
        notes?: string;
        items: Array<{
            type: "product" | "material";
            item_id: number;
            quantity: number;
            unit_price: number;
        }>;
    }) =>
        post<{ message: string; purchase_order: PurchaseOrder }>(
            "/v1/admin/purchase-orders",
            data,
        ),

    update: (id: number, data: Partial<PurchaseOrder>) =>
        put<{ message: string; purchase_order: PurchaseOrder }>(
            `/v1/admin/purchase-orders/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/purchase-orders/${id}`),

    updateStatus: (id: number, status: POStatus, notes?: string) =>
        patch<{ message: string; purchase_order: PurchaseOrder }>(
            `/v1/admin/purchase-orders/${id}/status`,
            { status, notes },
        ),

    receive: (
        id: number,
        data: {
            items: GRNItem[];
            location_type: "warehouse" | "outlet";
            outlet_id?: number;
            notes?: string;
        },
    ) =>
        post<{
            message: string;
            receipt_number: string;
            purchase_order: PurchaseOrder;
        }>(`/v1/admin/purchase-orders/${id}/receive`, data),

    createReturn: (
        id: number,
        data: {
            items: Array<{
                po_item_id: number;
                quantity: number;
                reason: string;
            }>;
            notes?: string;
        },
    ) =>
        post<{ message: string; return_number: string }>(
            `/v1/admin/purchase-orders/${id}/return`,
            data,
        ),

    submit: (id: number) =>
        post<{ message: string; purchase_order: PurchaseOrder }>(
            `/v1/admin/purchase-orders/${id}/submit`,
            {},
        ),

    approve: (id: number, notes?: string) =>
        post<{ message: string; purchase_order: PurchaseOrder }>(
            `/v1/admin/purchase-orders/${id}/approve`,
            { notes },
        ),

    reject: (id: number, reason: string) =>
        post<{ message: string; purchase_order: PurchaseOrder }>(
            `/v1/admin/purchase-orders/${id}/reject`,
            { reason },
        ),

    cancel: (id: number, reason: string) =>
        post<{ message: string; purchase_order: PurchaseOrder }>(
            `/v1/admin/purchase-orders/${id}/cancel`,
            { reason },
        ),

    auditLog: (id: number) =>
        get<{ logs: Array<{
            id: number; event: string; label: string; description: string;
            properties: Record<string, any>; actor_name: string;
            actor_email: string; ip_address: string; created_at: string;
        }> }>(`/v1/admin/purchase-orders/${id}/audit-log`),
};

// ─── GRN API ──────────────────────────────────────────────────────────────────

export const grnApi = {
    list: (params?: Record<string, string | number>) =>
        get<PaginatedResponse<GRN>>("/v1/admin/grn", { params }),

    get: (id: number) =>
        get<GRNDetail>(`/v1/admin/grn/${id}`),

    print: (id: number) =>
        post<{ message: string }>(`/v1/admin/grn/${id}/print`, {}),

    pdf: (id: number) =>
        get<Blob>(`/v1/admin/grn/${id}/pdf`),
};

// ─── Purchase Returns API ─────────────────────────────────────────────────────

export const purchaseReturnApi = {
    list: (params?: Record<string, string | number>) =>
        get<PaginatedResponse<PurchaseReturn>>("/v1/admin/purchase-returns", {
            params,
        }),

    get: (id: number) =>
        get<PurchaseReturn>(`/v1/admin/purchase-returns/${id}`),
};