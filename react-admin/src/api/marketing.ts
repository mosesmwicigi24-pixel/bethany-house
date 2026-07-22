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
