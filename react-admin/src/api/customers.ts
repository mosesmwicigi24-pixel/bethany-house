import { get, post, put, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface Customer {
    id: number;
    // user_id is null for phone-only / walk-in customers created from admin
    user_id: number | null;
    customer_number: string;
    // Denormalised copies (stored on customers table directly)
    first_name: string;
    last_name: string;
    // Email is optional - phone-only customers may not have one
    email?: string | null;
    phone?: string | null;
    // Customer-specific fields
    customer_type: "individual" | "business";
    company?: string | null;
    tax_id?: string | null;
    preferred_language: string;
    preferred_currency: string;
    status: "active" | "inactive" | "suspended";
    notes?: string | null;
    loyalty_points?: number;
    credit_limit?: number;
    // True when this customer has login credentials for the online portal
    is_portal_user?: boolean;
    // Computed / joined
    total_orders?: number;
    total_spent?: number;
    last_order_date?: string | null;
    created_at: string;
    // user is null for walk-in customers with no portal account
    user: {
        id: number;
        first_name: string;
        last_name: string;
        email?: string | null;
        phone?: string | null;
        status: "active" | "inactive" | "suspended";
        avatar_url?: string | null;
        created_at: string;
    } | null;
    addresses?: CustomerAddress[];
}

export interface CustomerAddress {
    id: number;
    name: string;
    address_line_1: string;
    address_line_2?: string | null;
    city: string;
    state?: string | null;
    country: string;
    postal_code?: string | null;
    phone: string;
    is_default: boolean;
}

export interface CustomerStats {
    total_orders: number;
    total_spent: number;
    average_order_value: number;
    last_order_date: string | null;
    online_orders: number;
    pos_orders: number;
    cancelled_orders: number;
}

export interface CustomerFormData {
    first_name: string;
    last_name: string;
    // Optional - phone-only customers don't need an email
    email?: string;
    phone?: string;
    type?: "individual" | "business";
    company_name?: string;
    tax_number?: string;
    preferred_language?: string;
    preferred_currency?: string;
    notes?: string;
}

/** Minimal payload for the quick-create inline widget */
export interface CustomerQuickCreateData {
    first_name: string;
    last_name: string;
    email?: string;
    phone?: string;
}

/** Returned by quickCreate - just enough to link to an order */
export interface CustomerQuickResult {
    id: number;
    first_name: string;
    last_name: string;
    email?: string | null;
    phone?: string | null;
    customer_number: string;
    is_portal_user: boolean;
}

export interface CustomerFilters {
    search?: string;
    status?: string;
    type?: string;
    sort_by?: string;
    sort_order?: "asc" | "desc";
    per_page?: number | string;
    page?: number | string;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const customersApi = {
    list: (params?: CustomerFilters) =>
        get<{
            data: Customer[];
            meta: { total: number; current_page: number; last_page: number };
            summary: {
                total: number;
                active: number;
                inactive: number;
                new_this_month: number;
            };
        }>("/v1/admin/customers", { params: params as Record<string, string> }),

    get: (id: number) =>
        get<{ customer: Customer; stats: CustomerStats }>(
            `/v1/admin/customers/${id}`,
        ),

    create: (data: CustomerFormData) =>
        post<{ message: string; customer: Customer }>(
            "/v1/admin/customers",
            data,
        ),

    update: (id: number, data: Partial<CustomerFormData>) =>
        put<{ message: string; customer: Customer }>(
            `/v1/admin/customers/${id}`,
            data,
        ),

    updateStatus: (id: number, status: "active" | "inactive" | "suspended") =>
        put<{ message: string }>(`/v1/admin/customers/${id}/status`, {
            status,
        }),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/customers/${id}`),

    orders: (id: number, params?: Record<string, string>) =>
        get<{ data: import("./orders").Order[]; meta: { total: number } }>(
            `/v1/admin/customers/${id}/orders`,
            { params },
        ),

    /**
     * Quick-create a customer inline (used during order / production-order intake).
     * Only first_name + last_name + (email OR phone) required.
     */
    quickCreate: (data: CustomerQuickCreateData) =>
        post<{ message: string; customer: CustomerQuickResult }>(
            "/v1/admin/customers/quick-create",
            data,
        ),

    /**
     * Send a portal activation / password-reset link to an existing customer.
     * Creates a User account if one doesn't exist yet.
     * Requires the customer to have an email address.
     */
    inviteToPortal: (id: number) =>
        post<{ message: string }>(
            `/v1/admin/customers/${id}/invite-to-portal`,
            {},
        ),
};
