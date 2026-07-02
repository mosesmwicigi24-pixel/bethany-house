import { get, post, put, patch, del } from "./client";
import type {
    BusinessSettings,
    Currency,
    CurrencyFormData,
    Language,
    LanguageFormData,
    TaxRate,
    TaxRateFormData,
    OutletSetup,
    OutletFormData,
    PaymentMethodSetup,
    PaymentMethodFormData,
    RoleSetup,
    RoleFormData,
    PermissionGroup,
    UserSetup,
    UserFormData,
    ShippingZone,
    ShippingMethod,
} from "@/types/setup";
import type { PaginatedResponse } from "@/types";

// ─── Business Settings ────────────────────────────────────────────────────────

export const settingsApi = {
    get: () => get<{ settings: BusinessSettings }>("/v1/admin/settings"),

    update: (data: Partial<BusinessSettings>) =>
        put<{ message: string; settings: BusinessSettings }>(
            "/v1/admin/settings",
            data,
        ),

    uploadLogo: (file: File) => {
        const form = new FormData();
        form.append("logo", file);
        return post<{ message: string; url: string }>(
            "/v1/admin/settings/logo",
            form,
            {
                headers: { "Content-Type": undefined }, // let axios set multipart/form-data + boundary
            },
        );
    },
};

// ─── Currencies ───────────────────────────────────────────────────────────────

export const currenciesApi = {
    list: () => get<{ data: Currency[] }>("/v1/admin/currencies-management"),

    create: (data: CurrencyFormData) =>
        post<{ message: string; currency: Currency }>(
            "/v1/admin/currencies-management",
            data,
        ),

    update: (id: number, data: Partial<CurrencyFormData>) =>
        put<{ message: string; currency: Currency }>(
            `/v1/admin/currencies-management/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/currencies-management/${id}`),

    setDefault: (id: number) =>
        put<{ message: string; currency: Currency }>(
            `/v1/admin/currencies-management/${id}/set-default`,
        ),

    toggle: (id: number) =>
        put<{ message: string; currency: Currency }>(
            `/v1/admin/currencies-management/${id}/toggle`,
        ),

    updateRates: (id: number, rate: number) =>
        put<{ message: string }>(
            `/v1/admin/currencies-management/${id}/rates`,
            { exchange_rate: rate },
        ),
};

// ─── Languages ────────────────────────────────────────────────────────────────

export const languagesApi = {
    list: () => get<{ data: Language[] }>("/v1/admin/languages"),

    create: (data: LanguageFormData) =>
        post<{ message: string; language: Language }>(
            "/v1/admin/languages",
            data,
        ),

    update: (id: number, data: Partial<LanguageFormData>) =>
        put<{ message: string; language: Language }>(
            `/v1/admin/languages/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/languages/${id}`),

    setDefault: (id: number) =>
        put<{ message: string; language: Language }>(
            `/v1/admin/languages/${id}/set-default`,
        ),

    toggle: (id: number) =>
        put<{ message: string; language: Language }>(
            `/v1/admin/languages/${id}/toggle`,
        ),
};

// ─── Tax Rates ────────────────────────────────────────────────────────────────

export const taxRatesApi = {
    list: () => get<{ data: TaxRate[] }>("/v1/admin/tax-rates"),

    create: (data: TaxRateFormData) =>
        post<{ message: string; tax_rate: TaxRate }>(
            "/v1/admin/tax-rates",
            data,
        ),

    update: (id: number, data: Partial<TaxRateFormData>) =>
        put<{ message: string; tax_rate: TaxRate }>(
            `/v1/admin/tax-rates/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/tax-rates/${id}`),

    toggle: (id: number) =>
        put<{ message: string; tax_rate: TaxRate }>(
            `/v1/admin/tax-rates/${id}/toggle`,
        ),
};

// ─── Outlets ──────────────────────────────────────────────────────────────────

export const outletsApi = {
    list: () => get<{ data: OutletSetup[] }>("/v1/admin/outlets"),

    get: (id: number) =>
        get<{ outlet: OutletSetup }>(`/v1/admin/outlets/${id}`),

    create: (data: OutletFormData) =>
        post<{ message: string; outlet: OutletSetup }>(
            "/v1/admin/outlets",
            data,
        ),

    update: (id: number, data: Partial<OutletFormData>) =>
        put<{ message: string; outlet: OutletSetup }>(
            `/v1/admin/outlets/${id}`,
            data,
        ),

    delete: (id: number) => del<{ message: string }>(`/v1/admin/outlets/${id}`),

    statistics: (id: number) =>
        get<{ statistics: Record<string, unknown> }>(
            `/v1/admin/outlets/${id}/statistics`,
        ),
};

// ─── Payment Methods ──────────────────────────────────────────────────────────

export const paymentMethodsApi = {
    // Admin CRUD (Settings → Payment Methods). Requires admin/super_admin.
    list: () =>
        get<{ data: PaymentMethodSetup[] }>(
            "/v1/admin/payment-methods-management",
        ),

    // Public, currency-aware list of active methods for checkout flows
    // (POS, storefront). No special permission required — any authenticated
    // (or guest) request can read this. Use this for PaymentModal /
    // PosPage, NOT `list()`, which is admin-only and will 403 for
    // pos_clerk / outlet_manager / regular staff.
    availableForSale: (currency?: string) =>
        get<{ data: PaymentMethodSetup[] }>(
            "/v1/payment-methods",
            currency ? { params: { currency } } : undefined,
        ),

    get: (id: number) =>
        get<{ payment_method: PaymentMethodSetup }>(
            `/v1/admin/payment-methods-management/${id}`,
        ),

    create: (data: PaymentMethodFormData) =>
        post<{ message: string; payment_method: PaymentMethodSetup }>(
            "/v1/admin/payment-methods-management",
            data,
        ),

    update: (id: number, data: Partial<PaymentMethodFormData>) =>
        put<{ message: string; payment_method: PaymentMethodSetup }>(
            `/v1/admin/payment-methods-management/${id}`,
            data,
        ),

    updateConfig: (id: number, config: Record<string, string>) =>
        put<{ message: string }>(
            `/v1/admin/payment-methods-management/${id}/config`,
            { configuration: config },
        ),

    toggle: (id: number) =>
        put<{ message: string; payment_method: PaymentMethodSetup }>(
            `/v1/admin/payment-methods-management/${id}/toggle`,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/payment-methods-management/${id}`),
};

// ─── Roles & Permissions ──────────────────────────────────────────────────────

export const rolesApi = {
    list: () => get<{ data: RoleSetup[] }>("/v1/admin/roles"),

    get: (id: number) => get<{ role: RoleSetup }>(`/v1/admin/roles/${id}`),

    create: (data: RoleFormData) =>
        post<{ message: string; role: RoleSetup }>("/v1/admin/roles", data),

    update: (id: number, data: Partial<RoleFormData>) =>
        put<{ message: string; role: RoleSetup }>(
            `/v1/admin/roles/${id}`,
            data,
        ),

    delete: (id: number) => del<{ message: string }>(`/v1/admin/roles/${id}`),

    syncPermissions: (id: number, permissionIds: number[]) =>
        post<{ message: string; role: RoleSetup }>(
            `/v1/admin/roles/${id}/permissions`,
            {
                permissions: permissionIds,
            },
        ),

    duplicate: (id: number) =>
        post<{ message: string; role: RoleSetup }>(
            `/v1/admin/roles/${id}/duplicate`,
        ),
};

export const permissionsApi = {
    listGrouped: () =>
        get<{ data: PermissionGroup[] }>("/v1/admin/permissions"),
};

// ─── Users (setup) ────────────────────────────────────────────────────────────

export const usersApi = {
    list: (params?: Record<string, string>) =>
        get<PaginatedResponse<UserSetup>>("/v1/admin/users", { params }),

    get: (id: number) => get<{ user: UserSetup }>(`/v1/admin/users/${id}`),

    create: (data: UserFormData) =>
        post<{ message: string; user: UserSetup }>("/v1/admin/users", data),

    update: (id: number, data: Partial<UserFormData>) =>
        put<{ message: string; user: UserSetup }>(
            `/v1/admin/users/${id}`,
            data,
        ),

    delete: (id: number) => del<{ message: string }>(`/v1/admin/users/${id}`),

    updateStatus: (id: number, status: string) =>
        put<{ message: string }>(`/v1/admin/users/${id}/status`, { status }),

    resetPassword: (id: number) =>
        post<{ message: string }>(`/v1/admin/users/${id}/reset-password`),

    byRole: (role: string) =>
        get<{ data: UserSetup[] }>(`/v1/admin/users/role/${role}`),

    promoteToStaff: (id: number, data: { role_ids: number[]; outlet_id?: number | null }) =>
        post<{ message: string; user: UserSetup }>(
            `/v1/admin/users/${id}/promote-to-staff`,
            data,
        ),
};

// ─── Shipping ─────────────────────────────────────────────────────────────────

export const shippingApi = {
    zones: () =>
        get<ShippingZone[]>("/v1/admin/shipping/zones"),

    showZone: (id: number) =>
        get<{ zone: ShippingZone; methods: ShippingMethod[] }>(`/v1/admin/shipping/zones/${id}`),

    createZone: (data: { name: string; description?: string | null; countries: string[] }) =>
        post<{ message: string; zone: ShippingZone }>("/v1/admin/shipping/zones", data),

    updateZone: (id: number, data: Partial<{ name: string; description: string | null; countries: string[] }>) =>
        put<{ message: string; zone: ShippingZone }>(`/v1/admin/shipping/zones/${id}`, data),

    deleteZone: (id: number) =>
        del<{ message: string }>(`/v1/admin/shipping/zones/${id}`),

    methods: (zoneId?: number) =>
        get<ShippingMethod[]>("/v1/admin/shipping/methods", {
            params: zoneId ? { zone_id: String(zoneId) } : undefined,
        }),

    createMethod: (data: {
        zone_id:          number;
        name:             string;
        description?:     string | null;
        delivery_time?:   string | null;
        cost_type:        "flat_rate" | "free" | "percentage";
        flat_rate:        number;
        min_order_amount?: number | null;
        is_active:        boolean;
        sort_order?:      number;
    }) =>
        post<{ message: string; method: ShippingMethod }>("/v1/admin/shipping/methods", data),

    updateMethod: (id: number, data: Partial<{
        name:             string;
        description:      string | null;
        delivery_time:    string | null;
        cost_type:        "flat_rate" | "free" | "percentage";
        flat_rate:        number;
        min_order_amount: number | null;
        is_active:        boolean;
        sort_order:       number;
    }>) =>
        put<{ message: string; method: ShippingMethod }>(`/v1/admin/shipping/methods/${id}`, data),

    deleteMethod: (id: number) =>
        del<{ message: string }>(`/v1/admin/shipping/methods/${id}`),
};