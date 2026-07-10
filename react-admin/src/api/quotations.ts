import { get, post, put, del } from "@/api/client";

export type QuotationStatus =
    | "draft"
    | "sent"
    | "accepted"
    | "declined"
    | "expired"
    | "converted";

export interface QuotationItem {
    id: number;
    quotation_id: number;
    product_id: number | null;
    product_variant_id: number | null;
    sku: string | null;
    product_name: string;
    variant_name: string | null;
    quantity: number;
    unit_price: number;
    discount_amount: number;
    tax_amount: number;
    total_price: number;
}

export interface Quotation {
    id: number;
    quote_number: string | null;
    user_id: number | null;
    outlet_id: number | null;
    source: "admin" | "storefront";
    status: QuotationStatus;
    currency_code: string;
    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    total_amount: number;
    customer_email: string | null;
    customer_phone: string | null;
    customer_first_name: string | null;
    customer_last_name: string | null;
    valid_until: string | null;
    notes: string | null;
    terms: string | null;
    converted_order_id: number | null;
    issued_at: string | null;
    accepted_at: string | null;
    items?: QuotationItem[];
    created_at: string;
    updated_at: string;
}

/** Laravel's flat paginator shape (meta fields at top level). */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface QuotationItemInput {
    product_id?: number | null;
    product_variant_id?: number | null;
    product_name: string;
    sku?: string | null;
    quantity: number;
    unit_price: number;
    discount_amount?: number;
}

export interface QuotationInput {
    outlet_id?: number | null;
    customer_first_name?: string | null;
    customer_last_name?: string | null;
    customer_email?: string | null;
    customer_phone?: string | null;
    valid_until?: string | null;
    notes?: string | null;
    terms?: string | null;
    items: QuotationItemInput[];
}

export const quotationApi = {
    list: (params?: Record<string, string | number>) =>
        get<Paginated<Quotation>>("/v1/admin/quotations", { params }),

    get: (id: number) =>
        get<{ quotation: Quotation }>(`/v1/admin/quotations/${id}`),

    create: (data: QuotationInput) =>
        post<{ quotation: Quotation }>("/v1/admin/quotations", data),

    update: (id: number, data: QuotationInput) =>
        put<{ quotation: Quotation }>(`/v1/admin/quotations/${id}`, data),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/quotations/${id}`),

    issue: (id: number) =>
        post<{ message: string; quotation: Quotation; document: unknown }>(
            `/v1/admin/quotations/${id}/issue`,
            {},
        ),

    accept: (id: number, dueInDays?: number) =>
        post<{ message: string; order: { id: number }; invoice: { number: string } }>(
            `/v1/admin/quotations/${id}/accept`,
            dueInDays != null ? { due_in_days: dueInDays } : {},
        ),
};
