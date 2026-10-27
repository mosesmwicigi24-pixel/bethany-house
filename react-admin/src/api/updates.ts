import { get, post, put, del, api } from "./client";

/** The Updates feed (People C3). */

export type PostKind = "update" | "recognition" | "celebration" | "event";
export type Rsvp = "yes" | "no" | "maybe";

export interface PostPerson { id: number; name: string; avatar_url: string | null }

export interface FeedPost {
    id: number;
    kind: PostKind;
    title: string | null;
    body: string | null;
    has_photo: boolean;
    author: PostPerson | null;
    recipient: PostPerson | null;
    outlet: { id: number; name: string } | null;
    must_read: boolean;
    comments_enabled: boolean;
    points: number | null;
    event_at: string | null;
    event_place: string | null;
    publish_at: string | null;
    published_at: string | null;
    comments_count: number;
    reads_count: number;
    rsvp_yes_count: number;
    me: { read_at: string | null; acknowledged_at: string | null; rsvp: Rsvp | null };
    can_manage: boolean;
    is_author: boolean;
}

export interface PostComment { id: number; body: string; at: string; mine: boolean; user: PostPerson | null }

export interface ReaderRow { id: number; name: string; read_at: string | null; acknowledged_at: string | null; rsvp: Rsvp | null }
export interface Readers { audience: number; read: ReaderRow[]; acknowledged: ReaderRow[]; pending: ReaderRow[]; rsvp: { yes: number; no: number; maybe: number } }

export interface PostDetail extends FeedPost { comments: PostComment[]; readers?: Readers }

export interface Feed { data: FeedPost[]; has_more: boolean; page: number; must_read_pending: number; can_post: boolean; scheduled: number }

export interface ComposeInput {
    kind: "update" | "recognition" | "event";
    title?: string;
    body?: string;
    outlet_id?: number | null;
    must_read?: boolean;
    comments_enabled?: boolean;
    publish_at?: string | null;
    event_at?: string | null;
    event_place?: string | null;
    recipient_id?: number | null;
    points?: number | null;
    photo?: File | null;
}

export const updatesApi = {
    feed: (page = 1, kind?: PostKind) => get<Feed>("/v1/admin/updates", { params: { page, kind: kind || undefined } }),
    scheduled: () => get<{ data: FeedPost[] }>("/v1/admin/updates/scheduled"),
    show: (id: number) => get<{ post: PostDetail }>(`/v1/admin/updates/${id}`),
    people: () => get<{ data: { id: number; name: string }[] }>("/v1/admin/updates/people"),
    create: async (input: ComposeInput) => {
        const fd = new FormData();
        for (const [k, v] of Object.entries(input)) {
            if (v === undefined || v === null || v === "") continue;
            if (k === "photo") fd.append("photo", v as File);
            else if (typeof v === "boolean") fd.append(k, v ? "1" : "0");
            else fd.append(k, String(v));
        }
        const r = await api.post<{ message: string; post: FeedPost }>("/v1/admin/updates", fd);
        return r.data;
    },
    update: (id: number, data: Partial<Pick<ComposeInput, "title" | "body" | "must_read" | "comments_enabled" | "publish_at" | "event_at" | "event_place">>) =>
        put<{ message: string; post: FeedPost }>(`/v1/admin/updates/${id}`, data),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/updates/${id}`),
    read: (id: number) => post<{ ok: true }>(`/v1/admin/updates/${id}/read`, {}),
    acknowledge: (id: number) => post<{ message: string; acknowledged_at: string; must_read_pending: number }>(`/v1/admin/updates/${id}/acknowledge`, {}),
    comment: (id: number, body: string) => post<{ message: string; comment: PostComment }>(`/v1/admin/updates/${id}/comments`, { body }),
    removeComment: (id: number, cid: number) => del<{ message: string }>(`/v1/admin/updates/${id}/comments/${cid}`),
    rsvp: (id: number, answer: Rsvp) => post<{ message: string; answer: Rsvp; rsvp: Readers["rsvp"] }>(`/v1/admin/updates/${id}/rsvp`, { answer }),
    readers: (id: number) => get<{ readers: Readers }>(`/v1/admin/updates/${id}/readers`),
    photoLink: (id: number) => get<{ url: string; expires_at: string }>(`/v1/admin/updates/${id}/photo`),
};

export const KIND_LABEL: Record<PostKind, string> = { update: "Update", recognition: "Recognition", celebration: "Celebration", event: "Event" };
