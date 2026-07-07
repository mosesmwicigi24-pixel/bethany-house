import { get, post, patch } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface PosOutlet {
    id: number;
    name: string;
    code: string;
    address: string;
    city: string;
    phone?: string | null;
    currency_code: string;
}

export type Outlet = PosOutlet;

export interface PosVariant {
    id: number | null;  // null for simple products (synthetic variant)
    sku: string;
    variant_name: string;
    attributes: Record<string, string>;
    price: number;
    sale_price?: number | null;
    currency: string;
    stock: number;
    is_default: boolean;
    /** Effective tax rate as a percentage (e.g. 16.0 for 16%). 0 if no tax assigned. */
    tax_rate: number;
    /** Human-readable tax category name(s) e.g. "VAT", "VAT + Tourism Levy". Null = exempt. */
    tax_name?: string | null;
}

export interface PosMeasurementField {
    name: string;
    unit?: string;
    required: boolean;
}

export interface PosProduct {
    id: number;
    name: string;
    sku: string;
    is_producible: boolean;
    measurements: PosMeasurementField[];
    category: { id: number; name: string } | null;
    image_url: string | null;
    variants: PosVariant[];
}

export interface CartItem {
    variant_id: number | null;  // null for simple products (no variants)
    product_name: string;
    variant_name: string;
    sku: string;
    price: number;
    quantity: number;
    discount_type: "none" | "flat" | "percent";
    discount_value: number;
    image_url: string | null;
    /** Per-product effective tax rate as a percentage (e.g. 16.0). 0 means tax-exempt. */
    tax_rate: number;
}

export interface CashRegister {
    id: number;
    outlet_id: number;
    opened_by: string | null;
    closed_by: string | null;
    // Balances (map from DB: opening_balance, closing_balance, expected_cash)
    opening_cash: number;
    closing_cash: number | null;
    expected_cash: number;
    // Sales breakdown
    transaction_count: number;
    total_sales: number;
    total_cash_sales: number;
    total_card_sales: number;
    total_mpesa_sales: number;
    total_refunds: number;
    variance: number | null;
    status: "open" | "closed";
    notes?: string | null;
    opened_at: string;
    closed_at?: string | null;
}

export interface PosOrderItem {
    id: number;
    product_id?: number | null;
    variant_id: number | null;
    product_name: string;
    variant_name: string;
    sku: string;
    quantity: number;
    unit_price: number;
    // FIX 1: raw discount type + value now returned by the backend for lossless
    // restore. Falls back gracefully for orders created before migration
    // (discount_type will be absent/'none', discount_value will be 0).
    discount_type: "none" | "flat" | "percent";
    discount_value: number;
    discount_amount: number;
    tax_amount: number;
    /** Effective tax rate percentage for this line (e.g. 16.0). 0 = exempt. */
    tax_rate?: number;
    /** Human-readable tax category name(s) for receipt display. */
    tax_name?: string | null;
    subtotal: number;
    /** True when this order line was created as a made-to-order item. */
    is_production?: boolean;
    /** Free-text production notes entered by the cashier at checkout. */
    production_notes?: string | null;
    // FIX 4: structured measurement values persisted on the order item so they
    // survive a restore without the cashier re-entering them.
    // Null for regular (non-MTO) lines or orders created before migration.
    measurement_values?: Record<string, string> | null;
}

export interface PosSale {
    id: number;
    order_number: string;
    outlet_id: number;
    outlet_name?: string | null;
    // FIX 3: customer_id now returned so restore re-links the DB customer record
    // instead of creating a walk-in (id=0) every time.
    customer_id?: number | null;
    customer_name?: string | null;
    customer_phone?: string | null;
    customer_email?: string | null;
    cashier_name?: string | null;
    items: PosOrderItem[];
    subtotal: number;
    discount_amount: number;
    // FIX 2: raw cart-level discount type + value persisted separately from the
    // resolved discount_amount so restore doesn't conflate per-item and
    // cart-level discounts (which caused double-discounting).
    cart_discount_type: "none" | "flat" | "percent";
    cart_discount_value: number;
    tax_amount: number;
    total: number;
    prices_include_tax?: boolean;
    payment_method: string | null;
    payment_status?: string | null;
    payment_reference?: string | null;
    cash_received?: number | null;
    change_given?: number | null;
    status: string;
    notes?: string | null;
    /** HMAC token for the public /pay/:token payment page */
    payment_token?: string | null;
    created_at: string;
}

export interface DailySummary {
    date: string;
    total_sales: number;
    total_transactions: number;
    total_returns: number;
    net_sales: number;
    cash_sales: number;
    card_sales: number;
    mpesa_sales: number;
    other_sales: number;
    average_transaction: number;
    top_products: { name: string; qty: number; revenue: number }[];
    hourly_breakdown: { hour: number; sales: number; transactions: number }[];
}

export interface SplitPayment {
    method: string;
    amount: number;
    reference?: string;
    cash_received?: number;
    proof_url?: string;         // URL of uploaded proof-of-payment file
}

export interface PosShippingMethod {
    id: number;
    name: string;
    description?: string | null;
    delivery_time?: string | null;
    cost_type: "flat_rate" | "free" | "percentage";
    flat_rate: number;
    is_active: boolean;
    zone_name?: string | null;
}

export interface CreateSalePayload {
    outlet_id: number;
    customer_first_name?: string;
    customer_last_name?: string;
    customer_phone?: string;
    customer_email?: string;
    items: {
        variant_id: number | null;  // null for simple products
        product_id?: number;        // required when variant_id is null
        quantity: number;
        unit_price: number;
        discount_type: "none" | "flat" | "percent";
        discount_value: number;
    }[];
    cart_discount_type: "none" | "flat" | "percent";
    cart_discount_value: number;
    // Single payment (backwards compat)
    payment_method?: string;
    payment_reference?: string;
    cash_received?: number;
    // Split / partial payments (takes precedence when provided)
    payments?: SplitPayment[];
    notes?: string;
    tax_rate_id?: number;
    // Shipping
    shipping_amount?: number;
    shipping_method_id?: number;
    shipping_address?: string;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const posApi = {
    outlets: () =>
        get<{ data: PosOutlet[] }>("/v1/admin/pos/outlets"),

    registerStatus: (outlet_id: number) =>
        get<{
            register: CashRegister | null;
            has_open_register: boolean;
            eod_submitted: boolean;
        }>(
            "/v1/admin/pos/register/status",
            { params: { outlet_id: String(outlet_id) } },
        ),

    openRegister: (data: { outlet_id: number; opening_cash: number; notes?: string }) =>
        post<{ message: string; register: CashRegister }>("/v1/admin/pos/register/open", data),

    closeRegister: (data: { outlet_id: number; closing_cash: number; notes?: string; denomination_count?: Record<number, number> }) =>
        post<{ message: string; register: CashRegister; variance: number }>(
            "/v1/admin/pos/register/close",
            data,
        ),

    registerHistory: (outlet_id: number, params?: Record<string, string>) =>
        get<{ data: CashRegister[] }>("/v1/admin/pos/register/history", {
            params: { outlet_id: String(outlet_id), ...params },
        }),

    products: (outlet_id: number, category_id?: number) =>
        get<{ data: PosProduct[]; meta: { total: number; current_page: number; last_page: number } }>(
            "/v1/admin/pos/products",
            { params: { outlet_id: String(outlet_id), ...(category_id ? { category_id: String(category_id) } : {}) } },
        ),

    searchProducts: (q: string, outlet_id: number) =>
        get<{ data: PosProduct[] }>("/v1/admin/pos/products/search", {
            params: { q, outlet_id: String(outlet_id) },
        }),

    createSale: (data: CreateSalePayload) =>
        post<{ message: string; order: PosSale; change: number }>("/v1/admin/pos/sales", data),

    sales: (outlet_id: number, params?: Record<string, string>) =>
        get<{ data: PosSale[]; meta: { total: number } }>("/v1/admin/pos/sales", {
            params: { outlet_id: String(outlet_id), ...params },
        }),

    saleDetail: (id: number) =>
        get<{ sale: PosSale }>(`/v1/admin/pos/sales/${id}`),

    voidSale: (id: number, reason: string) =>
        post<{ message: string }>(`/v1/admin/pos/sales/${id}/void`, { reason }),

    /**
     * Phase 2 two-step checkout: create the order without a payment so that
     * the payment modal has a real order ID to work against.
     */
    createPendingOrder: (data: Record<string, any>) =>
        post<{
            message: string;
            order_id: number;
            order_number: string;
            total_amount: number;
            currency_code: string;
            is_deposit: boolean;
            deposit_amount: number | null;
            order: PosSale;
            production_orders: string[];
        }>("/v1/admin/pos/pending-order", data),

    /**
     * Update an existing pending (unpaid) POS order - items, discounts,
     * shipping, and customer - without creating a new order or voiding.
     * Used when the cashier resumes a sale and makes changes to it.
     */
    updatePendingOrder: (id: number, data: Record<string, any>) =>
        patch<{
            message: string;
            order_id: number;
            order_number: string;
            total_amount: number;
            currency_code: string;
            order: PosSale;
        }>(`/v1/admin/pos/pending-order/${id}`, data),

    /**
     * Phase 2 two-step checkout: record payment(s) against a pending order.
     * Accepts FormData (with proof_of_payment file) or a plain object.
     */
    recordPosPay: (orderId: number, data: FormData | Record<string, any>) =>
        post<{
            message: string;
            order: PosSale;
            change: number;
            payment_status: string;
            needs_approval: boolean;
            proof_uploaded: boolean;
        }>(`/v1/admin/pos/pending-order/${orderId}/pay`, data as any),

    /**
     * Fetch any existing unpaid pending POS order for the given outlet/cashier.
     * Called on outlet selection so the cashier can resume from where they left
     * off after a page refresh or tab switch - without creating a duplicate order.
     * Returns null when no open pending order exists for the outlet.
     */
    getPendingOrder: (outletId: number) =>
        get<{
            order_id: number;
            order_number: string;
            total_amount: number;
            currency_code: string;
            order: PosSale;
        } | null>(`/v1/admin/pos/pending-order/open?outlet_id=${outletId}`),

    emailReceipt: (id: number, email: string) =>
        post<{ message: string }>(`/v1/admin/pos/sales/${id}/email-receipt`, { email }),

    processReturn: (data: {
        original_order_id: number;
        items: { variant_id: number | null; quantity: number }[];
        reason: string;
        refund_method: string;
    }) =>
        post<{ message: string; return_number: string; refund_amount: number }>(
            "/v1/admin/pos/returns",
            data,
        ),

    returns: (outlet_id: number, params?: Record<string, string>) =>
        get<{ data: unknown[] }>("/v1/admin/pos/returns", {
            params: { outlet_id: String(outlet_id), ...params },
        }),

    dailySummary: (outlet_id: number, date?: string) =>
        get<{ summary: DailySummary }>("/v1/admin/pos/reports/daily", {
            params: { outlet_id: String(outlet_id), ...(date ? { date } : {}) },
        }),

    getUserEodReport: (outlet_id: number, date: string) =>
        get<{
            summary: {
                date: string;
                outlet_id: number;
                register_id: number | null;
                orders: {
                    id: number;
                    order_number: string;
                    customer_name: string;
                    total_amount: number;
                    amount_paid: number;
                    balance: number;
                    payment_status: string;
                }[];
                existing_report: {
                    id: number;
                    order_notes: Record<string, string>;
                    sentiments: string;
                    submitted_at: string | null;
                } | null;
            };
        }>(
            "/v1/admin/pos/reports/user-eod",
            { params: { outlet_id: String(outlet_id), date } },
        ),

    saveUserEodReport: (data: {
        outlet_id: number;
        date: string;
        register_id: number;
        order_notes: Record<string, string>;
        sentiments: string;
    }) =>
        post<{ message: string; report_id: number }>(
            "/v1/admin/pos/reports/user-eod",
            data,
        ),

    searchCustomers: (q: string) =>
        get<{ data: { id: number; name: string; phone: string; email: string }[] }>(
            "/v1/admin/pos/customers/search",
            { params: { q } },
        ),

    // Uses the POS-scoped route (pos.access permission), NOT
    // /v1/admin/shipping/methods, which requires settings.view — a
    // back-office permission pos_clerk / outlet_manager don't have and
    // shouldn't need just to ring up a delivery sale.
    shippingMethods: () =>
        get<{ data: PosShippingMethod[] }>("/v1/admin/pos/shipping-methods"),
};