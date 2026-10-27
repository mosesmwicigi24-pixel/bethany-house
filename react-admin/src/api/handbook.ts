import { get, post, put, del } from "./client";

/** The handbook (People C4). */
export interface Article {
    id: number;
    title: string;
    slug: string;
    category: string | null;
    outlet: { id: number; name: string | null } | null;
    must_read: boolean;
    post_id: number | null;
    published_at: string | null;
    updated_at: string | null;
    excerpt: string;
    read_at: string | null;
    acknowledged_at: string | null;
    body?: string;
}

export interface ArticleInput { title: string; body: string; category?: string | null; outlet_id?: number | null; must_read?: boolean; publish?: boolean }

export const handbookApi = {
    list: (q?: string) => get<{ data: Article[]; can_edit: boolean; categories: string[] }>("/v1/admin/handbook", { params: { q: q || undefined } }),
    show: (id: number) => get<{ article: Article }>(`/v1/admin/handbook/${id}`),
    create: (d: ArticleInput) => post<{ message: string; article: Article }>("/v1/admin/handbook", d),
    update: (id: number, d: Partial<ArticleInput>) => put<{ message: string; article: Article }>(`/v1/admin/handbook/${id}`, d),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/handbook/${id}`),
};
