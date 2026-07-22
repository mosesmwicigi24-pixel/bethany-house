import { get, post, put, del } from "@/api/client";

/* ============================================================
   Marketing — liturgical seasons + Blessed Friday campaigns.
   Backend: /api/v1/admin/marketing/{seasons,promotions}
   (see SeasonController / PromotionController). List endpoints
   return { data, stats }; item endpoints return { data }.
   ============================================================ */

export interface SeasonTheme {
    accent?: string;
    accentInk?: string;
    accentSoft?: string;
    onAccent?: string;
    liturgical?: string;
    motif?: string;
}

export interface Promotion {
    id: number;
    name: string;
    description?: string | null;
    type?: string | null;
    discount_type: "percentage" | "fixed";
    discount_value: number | string;
    conditions?: Record<string, unknown> | null;
    is_active: boolean;
    starts_at?: string | null;
    ends_at?: string | null;
    priority?: number;
    is_exclusive?: boolean;
    max_uses?: number | null;
    times_used?: number;
    created_at?: string;
    updated_at?: string;
}

export interface Season {
    id: number;
    key: string;
    name: string;
    tagline?: string | null;
    scripture?: string | null;
    theme?: SeasonTheme | null;
    starts_at?: string | null;
    ends_at?: string | null;
    is_active: boolean;
    priority?: number;
    promotion_id?: number | null;
    banner_id?: number | null;
    sort_order?: number;
    promotion?: Promotion | null;
    banner?: { id: number; title: string } | null;
    created_at?: string;
    updated_at?: string;
}

export interface MarketingStats {
    total: number;
    active: number;
    running: number;
}

// Fields the CMS sends when creating/updating (server ignores the rest).
export type SeasonInput = Partial<
    Pick<
        Season,
        | "key" | "name" | "tagline" | "scripture" | "theme"
        | "starts_at" | "ends_at" | "is_active" | "priority"
        | "promotion_id" | "banner_id" | "sort_order"
    >
>;

export type PromotionInput = Partial<
    Pick<
        Promotion,
        | "name" | "description" | "type" | "discount_type" | "discount_value"
        | "is_active" | "starts_at" | "ends_at" | "priority" | "is_exclusive" | "max_uses"
    >
>;

export const seasonsApi = {
    list: () => get<{ data: Season[]; stats: MarketingStats }>("/v1/admin/marketing/seasons"),
    get: (id: number) => get<{ data: Season }>(`/v1/admin/marketing/seasons/${id}`),
    create: (body: SeasonInput) => post<{ data: Season }>("/v1/admin/marketing/seasons", body),
    update: (id: number, body: SeasonInput) => put<{ data: Season }>(`/v1/admin/marketing/seasons/${id}`, body),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/marketing/seasons/${id}`),
};

export const promotionsApi = {
    list: () => get<{ data: Promotion[]; stats: MarketingStats }>("/v1/admin/marketing/promotions"),
    get: (id: number) => get<{ data: Promotion }>(`/v1/admin/marketing/promotions/${id}`),
    create: (body: PromotionInput) => post<{ data: Promotion }>("/v1/admin/marketing/promotions", body),
    update: (id: number, body: PromotionInput) => put<{ data: Promotion }>(`/v1/admin/marketing/promotions/${id}`, body),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/marketing/promotions/${id}`),
};

/* ── Home-front content blocks (banners) ──────────────────────────────────── */

export interface Banner {
    id: number;
    title?: string | null;
    subtitle?: string | null;
    image_url?: string | null;
    mobile_image_url?: string | null;
    link_url?: string | null;
    link_text?: string | null;
    position: string;          // slot: home_hero, home_promo, shop_hero, …
    placement?: string | null; // page: homepage, shop, …
    is_active: boolean;
    open_in_new_tab?: boolean;
    sort_order?: number;       // order within the slot (slide 1, 2, 3…)
    starts_at?: string | null;
    ends_at?: string | null;
    styles?: Record<string, unknown> | null;
}

export type BannerInput = Partial<Omit<Banner, "id">>;

export const bannersApi = {
    list: (placement?: string) =>
        get<{ data: Banner[]; stats: MarketingStats }>(
            `/v1/admin/marketing/banners${placement ? `?placement=${encodeURIComponent(placement)}` : ""}`,
        ),
    get: (id: number) => get<{ data: Banner }>(`/v1/admin/marketing/banners/${id}`),
    create: (body: BannerInput) => post<{ data: Banner }>("/v1/admin/marketing/banners", body),
    update: (id: number, body: BannerInput) => put<{ data: Banner }>(`/v1/admin/marketing/banners/${id}`, body),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/marketing/banners/${id}`),
    uploadImage: (id: number, file: File, field: "image_url" | "mobile_image_url" = "image_url") => {
        const fd = new FormData();
        fd.append("image", file);
        fd.append("field", field);
        return post<{ data: Banner; url: string }>(`/v1/admin/marketing/banners/${id}/image`, fd);
    },
};
