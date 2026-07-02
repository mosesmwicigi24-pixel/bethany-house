import { get, post, put, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface Category {
    id: number;
    parent_id: number | null;
    slug: string;
    name_en: string;
    name_sw: string | null;
    name_fr: string | null;
    name_pt: string | null;
    description_en: string | null;
    description_sw: string | null;
    description_fr: string | null;
    description_pt: string | null;
    image_url: string | null;
    icon: string | null;
    color: string | null;
    sort_order: number;
    is_active: boolean;
    show_in_menu: boolean;
    show_in_storefront: boolean;
    featured: boolean;
    meta_title: string | null;
    meta_description: string | null;
    meta_keywords: string | null;
    products_count: number;
    breadcrumb: string;
    parent: { id: number; name_en: string } | null;
    children?: Category[];
    created_at: string;
    updated_at: string;
}

export interface CategoryFormData {
    name_en: string;
    name_sw?: string;
    name_fr?: string;
    name_pt?: string;
    description_en?: string;
    description_sw?: string;
    description_fr?: string;
    description_pt?: string;
    slug?: string;
    parent_id?: number | null;
    icon?: string;
    color?: string;
    is_active: boolean;
    show_in_menu: boolean;
    show_in_storefront: boolean;
    featured: boolean;
    sort_order?: number;
    meta_title?: string;
    meta_description?: string;
    meta_keywords?: string;
}

export interface CategoryStats {
    total: number;
    active: number;
    featured: number;
    root: number;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const categoriesApi = {
    /** Admin: full list with stats */
    list: (params?: Record<string, string>) =>
        get<{ data: Category[]; stats: CategoryStats }>(
            "/v1/admin/categories",
            { params },
        ),

    /** Admin: tree structure */
    tree: () =>
        get<{ data: Category[]; stats: CategoryStats }>(
            "/v1/admin/categories",
            {
                params: { tree: "true" },
            },
        ),

    /** Admin: single category */
    get: (id: number) =>
        get<{ category: Category }>(`/v1/admin/categories/${id}`),

    /** Admin: create */
    create: (data: CategoryFormData) =>
        post<{ message: string; category: Category }>(
            "/v1/admin/categories",
            data,
        ),

    /** Admin: update */
    update: (id: number, data: Partial<CategoryFormData>) =>
        put<{ message: string; category: Category }>(
            `/v1/admin/categories/${id}`,
            data,
        ),

    /** Admin: delete */
    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/categories/${id}`),

    /** Admin: toggle active status */
    toggle: (id: number) =>
        put<{ message: string; category: Category }>(
            `/v1/admin/categories/${id}/toggle`,
        ),

    /** Admin: reorder */
    reorder: (items: { id: number; sort_order: number }[]) =>
        put<{ message: string }>("/v1/admin/categories/reorder", {
            categories: items,
        }),

    /** Admin: upload image */
    uploadImage: (id: number, file: File) => {
        const form = new FormData();
        form.append("image", file);
        return post<{ message: string; image_url: string }>(
            `/v1/admin/categories/${id}/image`,
            form,
            { headers: { "Content-Type": undefined } },
        );
    },

    /** Admin: delete image */
    deleteImage: (id: number) =>
        del<{ message: string }>(`/v1/admin/categories/${id}/image`),
};