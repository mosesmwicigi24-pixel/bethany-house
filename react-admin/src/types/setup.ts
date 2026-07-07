// ─── Business / System Settings ───────────────────────────────────────────────

export interface BusinessSettings {
    app_name: string;
    app_tagline: string;
    app_email: string;
    app_phone: string;
    app_address: string;
    app_city: string;
    app_country: string;
    app_timezone: string;
    app_logo_url: string | null;
    app_favicon_url: string | null;
    default_currency: string;
    default_language: string;
    tax_inclusive: boolean;
    order_prefix: string;
    receipt_footer: string;
    low_stock_threshold: number;
    enable_guest_checkout: boolean;
    enable_reviews: boolean;
    maintenance_mode: boolean;
}

// ─── Currency ──────────────────────────────────────────────────────────────────
// Actual DB columns: id, code, name, symbol, decimal_places, exchange_rate,
//                   is_base, is_active, is_default*, symbol_position*,
//                   thousand_separator*, decimal_separator*
// (* added by migration)

export interface Currency {
    id: number;
    code: string;
    name: string;
    symbol: string;
    exchange_rate: number;
    decimal_places: number;
    thousand_separator: string;
    decimal_separator: string;
    symbol_position: "before" | "after";
    is_base: boolean; // original column
    is_default: boolean; // added by migration (mirrors is_base)
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface CurrencyFormData {
    code: string;
    name: string;
    symbol: string;
    exchange_rate: number;
    decimal_places: number;
    thousand_separator: string;
    decimal_separator: string;
    symbol_position: "before" | "after";
    is_default: boolean;
    is_active: boolean;
}

// ─── Language ──────────────────────────────────────────────────────────────────
// Actual DB columns: id, code, name, native_name*, is_default, is_active,
//                   sort_order, direction*, flag*
// (* added by migration)

export interface Language {
    id: number;
    code: string;
    name: string;
    native_name: string;
    direction: "ltr" | "rtl";
    flag: string;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
    created_at: string;
    updated_at: string;
}

export interface LanguageFormData {
    code: string;
    name: string;
    native_name: string;
    direction: "ltr" | "rtl";
    flag: string;
    is_default: boolean;
    is_active: boolean;
}

// ─── Tax Rate ──────────────────────────────────────────────────────────────────
// Actual DB columns: id, name, country_code, state_province, rate,
//                   tax_type (original), is_active,
//                   type*, code*, applies_to*, is_default*
// (* added by migration)

export interface TaxRate {
    id: number;
    name: string;
    code: string;
    rate: number;
    tax_type: string; // original column
    type: "percentage" | "fixed"; // added by migration
    applies_to: "all" | "products" | "shipping";
    country_code: string | null;
    state_province: string | null;
    is_default: boolean;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface TaxRateFormData {
    name: string;
    code: string;
    rate: number;
    type: "percentage" | "fixed";
    applies_to: "all" | "products" | "shipping";
    country_code: string | null;
    is_default: boolean;
    is_active: boolean;
}

// ─── Outlet ────────────────────────────────────────────────────────────────────
// All required columns already exist in DB ✓

export interface OutletSetup {
    id: number;
    code: string;
    name: string;
    outlet_type: "store" | "warehouse" | "outlet" | "workshop";
    /** Sales channel this outlet represents, for grouping orders in the nav. */
    sales_channel?: "pos" | "whatsapp" | "online";
    email: string | null;
    phone: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    state_province: string | null;
    postal_code: string | null;
    country_code: string;
    latitude: number | null;
    longitude: number | null;
    geofence_radius_meters: number | null;
    is_active: boolean;
    is_pickup_location: boolean;
    operating_hours: Record<string, { open: string; close: string }> | null;
    users_count?: number;
    created_at: string;
    updated_at: string;
}

export interface OutletFormData {
    code: string;
    name: string;
    outlet_type: "store" | "warehouse" | "outlet" | "workshop";
    sales_channel: "pos" | "whatsapp" | "online";
    email: string;
    phone: string;
    address_line1: string;
    address_line2: string;
    city: string;
    state_province: string;
    postal_code: string;
    country_code: string;
    is_active: boolean;
    is_pickup_location: boolean;
    latitude?: number | null;
    longitude?: number | null;
    geofence_radius_meters?: number | null;
}

// ─── Payment Method ────────────────────────────────────────────────────────────
// Actual DB columns: id, code, name, description, provider, is_active,
//                   supported_currencies, configuration (not config),
//                   sort_order, display_order, is_default*, type*, icon*
// (* added by migration)

export interface PaymentMethodSetup {
    id: number;
    name: string;
    code: string;
    type: "mobile_money" | "card" | "cash" | "bank_transfer";
    provider: string | null;
    description: string | null;
    icon: string | null;
    is_active: boolean;
    is_default: boolean;
    // Effective approval policy from the backend: true → the payment is held for
    // admin approval; false → it settles immediately (cash, I&M, M-Pesa, card).
    requires_approval?: boolean;
    sort_order: number;
    display_order: number;
    configuration: Record<string, string> | null; // DB column name
    config?: Record<string, string> | null; // key returned by backend format() helper
    supported_currencies: string[];
    created_at: string;
    updated_at: string;
}

export interface PaymentMethodFormData {
    name: string;
    code: string;
    type: "mobile_money" | "card" | "cash" | "bank_transfer";
    provider: string;
    description: string;
    is_active: boolean;
    is_default: boolean;
    requires_approval: boolean;
    sort_order: number;
    supported_currencies: string[];
}

// ─── Role & Permission ─────────────────────────────────────────────────────────
// Actual DB columns: id, name, guard_name, user_type, description, is_active,
//                   display_name*, is_system*
// (* added by migration)

export interface RoleSetup {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    guard_name: string;
    user_type: "system" | "staff" | "customer";
    is_active: boolean;
    is_system: boolean;
    users_count: number;
    permissions: PermissionSetup[];
    created_at: string;
    updated_at: string;
}

export interface PermissionSetup {
    id: number;
    name: string;
    display_name: string;
    group: string;
    description: string | null;
}

export interface PermissionGroup {
    group: string;
    permissions: PermissionSetup[];
}

export interface RoleFormData {
    name: string;
    display_name: string;
    description: string;
    user_type: "system" | "staff" | "customer";
    is_active: boolean;
    permissions: number[];
}

// ─── User (setup context) ──────────────────────────────────────────────────────

export interface UserSetup {
    id: number;
    uuid: string;
    first_name: string;
    last_name: string;
    name: string;
    email: string;
    phone: string | null;
    user_type: "system" | "staff" | "customer";
    status: "active" | "inactive" | "suspended";
    must_setup_2fa: boolean;
    two_factor_enabled: boolean;
    last_login_at: string | null;
    created_at: string;
    roles: { id: number; name: string; display_name: string }[];
    outlet: { id: number; name: string } | null;
}

export interface UserFormData {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    password: string;
    password_confirmation: string;
    user_type: "system" | "staff";
    status: "active" | "inactive";
    role_ids: number[];
    outlet_id: number | null;
    must_setup_2fa: boolean;
}

// ─── Setup wizard steps ───────────────────────────────────────────────────────

export type SetupStep =
    | "business"
    | "currencies"
    | "languages"
    | "taxes"
    | "outlets"
    | "payment-methods"
    | "roles"
    | "users"
    | "complete";

// ── Add these interfaces to your @/types/setup.ts file ───────────────────────

export interface ShippingZone {
    id:          number;
    name:        string;
    description: string | null;
    is_active:   boolean;
    countries:   string[];         // array of ISO country codes (from pivot)
    created_at:  string;
    updated_at:  string;
}

export interface ShippingMethod {
    id:               number;
    shipping_zone_id: number;
    zone_name?:       string | null;   // joined from shipping_zones
    name:             string;
    description:      string | null;
    delivery_time:    string | null;   // e.g. "2–5 business days"
    cost_type:        "flat_rate" | "free" | "percentage";
    flat_rate:        number;          // rate used for flat_rate and percentage types
    min_order_amount: number | null;
    is_active:        boolean;
    sort_order:       number;
    created_at:       string;
    updated_at:       string;
}