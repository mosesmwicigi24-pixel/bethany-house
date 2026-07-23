import { get, post, put, patch, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export type OrderChannel = "online" | "pos";
export type OrderStatus =
    | "pending"
    | "pending_payment"
    | "paid"
    | "processing"
    | "confirmed"
    | "shipped"
    | "delivered"
    | "completed"
    | "cancelled"
    | "refunded"
    | "voided";

// Phase 2: 'deposit' added alongside existing values
export type PaymentStatus =
    | "pending"
    | "deposit"
    | "partial"
    | "paid"
    | "partially_refunded"
    | "refunded"
    | "failed";

export interface OrderItem {
    id: number;
    variant_id: number;
    product_name: string;
    variant_name: string;
    sku: string;
    quantity: number;
    unit_price: number;
    /** Original catalogue price before any manual adjustment. Null = no override was made. */
    original_price?: number | null;
    /** True when a cashier/admin manually overrode the unit price above the catalogue price. */
    price_adjusted?: boolean;
    discount_amount: number;
    tax_amount: number;
    /** Effective tax rate as a percentage for this line (e.g. 16.0). 0 = exempt. */
    tax_rate?: number;
    /** Human-readable tax category label(s) for receipt/invoice display. */
    tax_name?: string | null;
    subtotal: number;
    image_url?: string | null;
}

export interface OrderPayment {
    id: number;
    payment_number?: string;
    payment_method: string;
    amount: number;
    currency_code: string;
    status: string;
    provider_reference?: string | null;
    tax_inclusive: boolean;
    tax_amount_collected?: number | null;
    paid_at?: string | null;
    created_at: string;
    // International payment approval
    requires_approval?: boolean;
    approval_status?: "pending_review" | "approved" | "rejected" | null;
    // Refund
    refund_amount?: number | null;
}

export interface OrderStatusHistory {
    id: number;
    old_status: string;
    new_status: string;
    changed_by_name: string;
    notes?: string | null;
    created_at: string;
}

export interface OrderNote {
    id: number;
    note: string;
    is_internal: boolean;
    user_name: string;
    created_at: string;
}

export interface ShippingAddress {
    name: string;
    address_line_1: string;
    address_line_2?: string;
    city: string;
    state?: string;
    country: string;
    postal_code?: string;
    phone: string;
}

export interface Order {
    id: number;
    order_number: string;
    order_type: OrderChannel;
    channel?: OrderChannel;
    /** The INVOICE document this order bills, if it came from a quotation. */
    invoice_document?: { id: number; number: string; documentable_id: number } | null;
    status: string;
    payment_status: PaymentStatus;
    payment_method: string;
    currency_code: string;
    currency?: string;
    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    /** True when prices already include tax (affects receipt display) */
    prices_include_tax: boolean;
    /** Per-rate tax breakdown for display - [{rate: 16.0, amount: 480.00}, ...] */
    tax_breakdown?: { rate: number; amount: number }[];
    shipping_amount: number;
    /** Resolved shipping method name (from shipping_methods table) */
    shipping_method?: string | null;
    /** True when staff manually overrode the shipping fee */
    shipping_fee_overridden: boolean;
    shipping_fee_note?: string | null;
    total_amount: number;
    total?: number;
    /** When dispatch (hand-over) was authorized, and by whom. */
    dispatched_at?: string | null;
    dispatched_by?: number | null;
    /** Minimum deposit amount set by staff */
    deposit_amount?: number | null;
    /** Date by which the balance must be paid */
    balance_due_date?: string | null;
    /** ISO-2 country code snapshotted at order creation */
    customer_country_code?: string | null;
    /** True when this order is from an international (non-KE) customer */
    is_international?: boolean;
    /** HMAC payment token - used to construct the public /pay/:token URL */
    payment_token?: string | null;
    /** Expiry for the payment token */
    payment_token_expires_at?: string | null;
    customer_id?: number | null;
    customer_name?: string | null;
    customer_email?: string | null;
    customer_phone?: string | null;
    user_id?: number | null;
    outlet_id?: number | null;
    outlet_name?: string | null;
    cashier_name?: string | null;
    tracking_number?: string | null;
    delivery_type?: string | null;
    notes?: string | null;
    customer_notes?: string | null;
    items: OrderItem[];
    payments?: OrderPayment[];
    status_history?: OrderStatusHistory[];
    order_notes?: OrderNote[];
    shipping_address?: ShippingAddress | null;
    /** Linked production orders (present when this is a make-to-order sale) */
    production_orders?: {
        id: number;
        order_number: string;
        product_name: string;
        quantity: number;
        status: string;
        priority: string;
        due_date?: string | null;
        is_customer_order: boolean;
    }[];
    created_at: string;
    updated_at: string;
}

export interface OrderSummaryStats {
    total_orders: number;
    total_revenue: number;
    pending_count: number;
    processing_count: number;
    today_orders: number;
    today_revenue: number;
    avg_order_value: number;
    cancelled_count: number;
}

export interface OrderFilters {
    search?: string;
    status?: string;
    channel?: string;
    /** Sales channel split for the Sales nav: "pos" | "online" | "whatsapp". */
    sales_channel?: string;
    outlet_id?: number | string;
    start_date?: string;
    end_date?: string;
    sort_by?: string;
    sort_order?: "asc" | "desc";
    per_page?: number | string;
    page?: number | string;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const ordersApi = {
    list: (params?: OrderFilters) =>
        get<{
            data: Order[];
            meta: { total: number; current_page: number; last_page: number; per_page: number };
            stats: OrderSummaryStats;
        }>("/v1/admin/orders", { params: params as Record<string, string> }),

    get: (id: number) =>
        get<{ order: Order }>(`/v1/admin/orders/${id}`),

    /** Authorize dispatch (hand-over) of a paid POS sale. */
    authorizeDispatch: (id: number) =>
        post<{ message: string; order: any }>(`/v1/admin/pos/sales/${id}/dispatch`, {}),

    updateStatus: (
        id: number,
        data: { status: OrderStatus; tracking_number?: string; notes?: string },
    ) =>
        put<{ message: string; order: Order }>(`/v1/admin/orders/${id}/status`, data),

    addNote: (id: number, data: { note: string; is_internal: boolean }) =>
        post<{ message: string }>(`/v1/admin/orders/${id}/notes`, data),

    /** Attach or update the customer on an order (pending/processing/confirmed only) */
    attachCustomer: (id: number, data: {
        customer_id?:  number;
        first_name?:   string;
        last_name?:    string;
        email?:        string;
        phone?:        string;
        new_customer?: boolean | {
            first_name: string;
            last_name?:  string;
            phone:       string;
            email?:      string;
        };
    }) =>
        post<{ message: string; order: Record<string, any> }>(
            `/v1/admin/orders/${id}/attach-customer`, data
        ),

    /** Fetch the full audit trail for a single order */
    auditLog: (id: number, params?: { per_page?: number; page?: number }) =>
        get<{
            data: Array<{
                id:          number;
                action:      string;
                description: string | null;
                properties:  Record<string, any>;
                ip_address:  string | null;
                created_at:  string;
                actor_name:  string;
                actor_email: string | null;
                actor_id:    number | null;
            }>;
            total: number;
            current_page: number;
            last_page: number;
        }>(`/v1/admin/orders/${id}/audit-log`, { params: params as any }),

    activityLog: (id: number) =>
        get<{ data: Array<{
            id:          number;
            action:      string;
            description: string | null;
            properties:  Record<string, any> | null;
            ip_address:  string | null;
            created_at:  string;
            user:        { id: number; name: string; email: string } | null;
        }> }>(`/v1/admin/orders/${id}/activity-log`),

    /**
     * Void an order - admin-initiated cancellation for pending/processing/confirmed orders.
     * Restocks inventory and voids pending payments.
     * Use for test orders, duplicates, or fraud. Use 'refund' for completed orders.
     */
    voidOrder: (id: number, reason: string) =>
        post<{ message: string; order: Order }>(`/v1/admin/orders/${id}/void`, { reason }),

    refund: (id: number, data: { amount: number; reason: string; refund_shipping?: boolean }) =>
        post<{ message: string }>(`/v1/admin/orders/${id}/refund`, data),

    resendConfirmation: (id: number) =>
        post<{ message: string }>(`/v1/admin/orders/${id}/resend-confirmation`),

    generateInvoice: (id: number) =>
        post<{ message: string; invoice_url: string }>(`/v1/admin/orders/${id}/invoice`),

    /** Record a payment - supports deposit/partial/full, tax-inclusive toggle */
    addPayment: (
        id: number,
        data: {
            method: string;
            amount: number;
            reference?: string;
            phone?: string;
            tax_inclusive?: boolean;
            notes?: string;
            /** For "other" method - human-readable name e.g. "Cheque", "Wire Transfer" */
            custom_method_name?: string;
        },
    ) =>
        post<{
            message: string;
            payment: OrderPayment;
            total_paid: number;
            outstanding: number;
            payment_status: PaymentStatus;
        }>(
            `/v1/admin/orders/${id}/payments`,
            data,
        ),

    /** Manually set or update the shipping fee before payment */
    setShippingFee: (
        id: number,
        data: { amount: number; note?: string; shipping_method_id?: number | null },
    ) =>
        patch<{
            message: string;
            shipping_amount: number;
            shipping_method: string | null;
            total_amount: number;
            amount_paid: number;
            balance: number;
            payment_status: string;
            overpaid: number;
        }>(
            `/v1/admin/orders/${id}/shipping-fee`,
            data,
        ),

    /** Configure deposit terms on an order */
    setDeposit: (
        id: number,
        data: { deposit_amount: number; balance_due_date?: string },
    ) =>
        post<{ message: string; deposit_amount: number; balance_due_date: string | null }>(
            `/v1/admin/orders/${id}/set-deposit`,
            data,
        ),

    /**
     * Adjust the unit price of a single order item upwards.
     * Only allowed when the order has no payments recorded yet (payment_status = 'pending').
     * Records original_price for audit/reporting and sets price_adjusted = true.
     */
    adjustItemPrice: (orderId: number, itemId: number, newPrice: number) =>
        patch<{
            message: string;
            item: OrderItem;
            order_total: number;
            order_subtotal: number;
        }>(`/v1/admin/orders/${orderId}/items/${itemId}/price`, { unit_price: newPrice }),

    /** Raise a Made-to-Order production order for one line + capture its details. */
    raiseItemProduction: (orderId: number, itemId: number, data: {
        measurements?: Record<string, string>;
        color?: string;
        notes?: string;
        due_date?: string;
    }) =>
        post<{ message: string; production_order_id: number; production_order_number: string }>(
            `/v1/admin/orders/${orderId}/items/${itemId}/production`, data),

    exportCsv: (params?: OrderFilters) =>
        get<Blob>("/v1/admin/orders/export", {
            params: params as Record<string, string>,
            responseType: "blob",
        }),

    /**
     * Phase 4 - Generate (or refresh) the public payment link for an order.
     * The server mints an HMAC token valid for 72 hours and returns the full URL.
     */
    getPaymentLink: (id: number) =>
        get<{ payment_url: string; url: string; expires_at: string }>(
            `/v1/admin/orders/${id}/payment-link`,
        ),

    /**
     * Phase 4 - Trigger a Daraja STK query or Transaction Status check for a
     * specific M-Pesa payment record attached to this order.
     * Pass the M-Pesa receipt code if querying an offline payment.
     */
    verifyMpesa: (orderId: number, paymentId: number, transactionCode?: string) =>
        post<{ message: string; payment_status: string; payment: OrderPayment }>(
            `/v1/admin/orders/${orderId}/payments/${paymentId}/verify-mpesa`,
            transactionCode ? { transaction_code: transactionCode } : {},
        ),

    /**
     * Verify a Paystack payment directly via the Paystack API using its
     * reference code — no webhook required.
     */
    verifyPaystack: (orderId: number, paymentId: number, reference: string) =>
        post<{ message: string; payment_status: string; payment: OrderPayment }>(
            `/v1/admin/orders/${orderId}/payments/${paymentId}/verify-paystack`,
            { reference },
        ),

    /**
     * Phase 4 - Enforce currency from the country selected at order creation.
     */
    updateCurrency: (id: number, countryCode: string) =>
        post<{ currency_code: string; changed: boolean }>(
            `/v1/admin/orders/${id}/update-currency`,
            { country_code: countryCode },
        ),
};