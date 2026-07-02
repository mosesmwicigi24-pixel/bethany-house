import { get, post, put, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ProductTranslation {
    id?: number;
    language_code: string;
    name: string;
    description: string;
    short_description: string;
    specifications: Record<string, string> | null;
}

export interface ProductPrice {
    id?: number;
    product_variant_id: number | null;
    currency_code: string;
    regular_price: number;
    sale_price: number | null;
    cost_price: number | null;
    sale_start_date: string | null;
    sale_end_date: string | null;
}

export interface ProductImage {
    id: number;
    product_variant_id: number | null;
    image_url: string;
    thumbnail_url: string | null;
    alt_text: string | null;
    is_primary: boolean;
    sort_order: number;
}

export interface ProductVariant {
    id: number;
    sku: string;
    variant_name: string;
    attributes: Record<string, string>;
    weight: number | null;
    is_default: boolean;
    is_active: boolean;
    prices: ProductPrice[];
    images: ProductImage[];
}

export interface ProductSeo {
    id?: number;
    language_code: string;
    meta_title: string | null;
    meta_description: string | null;
    meta_keywords: string | null;
    canonical_url: string | null;
    og_title: string | null;
    og_description: string | null;
    og_image: string | null;
}

// Phase 2 - per-product tax rate
export interface ProductTaxRate {
    id: number;
    name: string;
    code: string;
    rate: number; // e.g. 16.00 for 16%
    tax_type: string;
    is_default: boolean;
    is_active: boolean;
}

export interface Product {
    id: number;
    uuid: string;
    category_id: number | null;
    sku: string;
    slug: string;
    product_type: "simple" | "variable" | "made_to_order";
    is_producible: boolean;
    is_featured: boolean;
    status: "draft" | "active" | "inactive" | "archived";
    published_at: string | null;
    weight: number | null;
    length: number | null;
    width: number | null;
    height: number | null;
    brand: string | null;
    tax_class: string | null;
    low_stock_threshold: number;
    sort_order: number;
    created_at: string;
    updated_at: string;
    measurements: { name: string; unit?: string; required: boolean }[];
    // Relations
    category: { id: number; name_en: string } | null;
    translations: ProductTranslation[];
    prices: ProductPrice[];
    variants: ProductVariant[];
    images: ProductImage[];
    seo: ProductSeo[];
    // Phase 2 - tax rates
    tax_rate_ids: number[];
    tax_rates: ProductTaxRate[];
    // Computed
    primary_image: ProductImage | null;
    en_translation: ProductTranslation | null;
    total_stock: number;
    variants_count: number;
}

export interface ProductListItem {
    id: number;
    uuid: string;
    sku: string;
    slug: string;
    status: string;
    product_type: string;
    is_featured: boolean;
    is_producible: boolean;
    brand: string | null;
    low_stock_threshold: number;
    total_stock: number;
    created_at: string;
    category: { id: number; name_en: string } | null;
    primary_image: ProductImage | null;
    en_translation: ProductTranslation | null;
    base_price: ProductPrice | null;
    variants_count: number;
}

export interface ProductStats {
    total: number;
    active: number;
    draft: number;
    archived: number;
    low_stock: number;
    out_of_stock: number;
    featured: number;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const productsApi = {
    // ── List & search ──────────────────────────────────────────────────────────

    list: (params?: Record<string, string>) =>
        get<{ data: ProductListItem[]; meta: any; stats: ProductStats }>(
            "/v1/admin/products",
            { params },
        ),

    search: (q: string, limit = 20) =>
        get<{ data: ProductListItem[] }>("/v1/admin/products", {
            params: { search: q, per_page: String(limit) },
        }),

    // ── Single product ─────────────────────────────────────────────────────────

    get: (id: number) => get<{ product: Product }>(`/v1/admin/products/${id}`),

    // ── Create / Update ────────────────────────────────────────────────────────

    create: (data: ProductCreatePayload) =>
        post<{ message: string; product: Product }>("/v1/admin/products", data),

    update: (id: number, data: Partial<ProductCreatePayload>) =>
        put<{ message: string; product: Product }>(
            `/v1/admin/products/${id}`,
            data,
        ),

    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/products/${id}`),

    // ── Status ─────────────────────────────────────────────────────────────────

    publish: (id: number) =>
        put<{ message: string; product: Product }>(
            `/v1/admin/products/${id}/publish`,
        ),

    archive: (id: number) =>
        put<{ message: string; product: Product }>(
            `/v1/admin/products/${id}/archive`,
        ),

    // ── Images ─────────────────────────────────────────────────────────────────

    uploadImages: (id: number, files: File[]) => {
        const form = new FormData();
        files.forEach((f) => form.append("images[]", f));
        return post<{ message: string; images: ProductImage[] }>(
            `/v1/admin/products/${id}/images`,
            form,
            { headers: { "Content-Type": undefined } },
        );
    },

    setPrimaryImage: (productId: number, imageId: number) =>
        put<{ message: string }>(
            `/v1/admin/products/${productId}/images/${imageId}/primary`,
        ),

    reorderImages: (
        productId: number,
        items: { id: number; sort_order: number }[],
    ) =>
        put<{ message: string }>(
            `/v1/admin/products/${productId}/images/reorder`,
            { images: items },
        ),

    deleteImage: (productId: number, imageId: number) =>
        del<{ message: string }>(
            `/v1/admin/products/${productId}/images/${imageId}`,
        ),

    // ── Variants ───────────────────────────────────────────────────────────────

    createVariant: (productId: number, data: VariantPayload) =>
        post<{ message: string; variant: ProductVariant }>(
            `/v1/admin/products/${productId}/variants`,
            data,
        ),

    updateVariant: (
        productId: number,
        variantId: number,
        data: Partial<VariantPayload>,
    ) =>
        put<{ message: string; variant: ProductVariant }>(
            `/v1/admin/products/${productId}/variants/${variantId}`,
            data,
        ),

    deleteVariant: (productId: number, variantId: number) =>
        del<{ message: string }>(
            `/v1/admin/products/${productId}/variants/${variantId}`,
        ),

    // ── Tax rates (Phase 2) ────────────────────────────────────────────────────

    getTaxRates: (productId: number) =>
        get<{ data: ProductTaxRate[] }>(
            `/v1/admin/products/${productId}/tax-rates`,
        ),

    syncTaxRates: (productId: number, taxRateIds: number[]) =>
        post<{ message: string; tax_rate_ids: number[] }>(
            `/v1/admin/products/${productId}/tax-rates`,
            { tax_rate_ids: taxRateIds },
        ),

    // ── Bulk import ────────────────────────────────────────────────────────────

    bulkImport: (file: File) => {
        const form = new FormData();
        form.append("file", file);
        return post<{ message: string; imported: number; errors: string[] }>(
            "/v1/admin/products/bulk-import",
            form,
            { headers: { "Content-Type": undefined } },
        );
    },

    exportTemplate: () => get<Blob>("/v1/admin/products/export-template"),
};

// ── Payload types ─────────────────────────────────────────────────────────────

export interface ProductCreatePayload {
    // Core
    sku: string;
    slug?: string;
    category_id: number | null;
    product_type: "simple" | "variable" | "made_to_order";
    status: "draft" | "active" | "inactive" | "archived";
    is_featured: boolean;
    is_producible: boolean;
    brand?: string;
    tax_class?: string;
    low_stock_threshold: number;
    // Physical
    weight?: number | null;
    length?: number | null;
    width?: number | null;
    height?: number | null;
    // Translations
    translations: {
        language_code: string;
        name: string;
        description: string;
        short_description: string;
        specifications?: Record<string, string>;
    }[];
    // Prices (base product, not variant-specific)
    prices: {
        currency_code: string;
        regular_price: number;
        sale_price?: number | null;
        cost_price?: number | null;
        sale_start_date?: string | null;
        sale_end_date?: string | null;
    }[];
    // SEO
    seo: {
        language_code: string;
        meta_title?: string;
        meta_description?: string;
        meta_keywords?: string;
        canonical_url?: string;
        og_title?: string;
        og_description?: string;
    }[];
    // Measurements (only relevant when is_producible = true)
    measurements?: {
        name: string;
        unit?: string;
        required: boolean;
    }[];
    // Phase 2 - tax rate IDs (optional; synced after save)
    tax_rate_ids?: number[];
}

export interface VariantPayload {
    sku: string;
    variant_name: string;
    attributes: Record<string, string>;
    weight?: number | null;
    is_default: boolean;
    is_active: boolean;
    prices: {
        currency_code: string;
        regular_price: number;
        sale_price?: number | null;
        cost_price?: number | null;
    }[];
}