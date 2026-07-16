import { get, post, patch, del } from "@/api/client";

// ─── Types ────────────────────────────────────────────────────────────────────

export type ChannelType = "dm" | "space" | "announcement";

export interface ChannelUser {
    id:       number;
    name:     string;
    initials: string;
    role?:    "member" | "admin";
}

export interface LastMessage {
    id:         number;
    body:       string;
    user_name:  string | null;
    created_at: string;
}

export interface Channel {
    id:               number;
    type:             ChannelType;
    name:             string;
    description?:     string | null;
    slug?:            string | null;
    is_private:       boolean;
    context_type?:    "production_order" | "order" | null;
    context_id?:      number | null;
    last_activity_at: string | null;
    last_message?:    LastMessage | null;
    members:          ChannelUser[] | number;
    unread_count?:    number;
    /**
     * Set when the current user has dismissed this thread from their own
     * sidebar (order/production-order threads only). Null once a new
     * message arrives after dismissal, or after calling undismiss().
     * list() already excludes dismissed channels entirely - this field is
     * mainly useful for get()/dismiss()/undismiss() response handling.
     */
    dismissed_at?:    string | null;
}

// Entity tag embedded in a message via the # trigger
export interface LinkedEntity {
    type:     "order" | "production_order" | "eod_report";
    id:       number;
    label:    string;    // e.g. "#ORD-1234"
}

// Entity search result from GET /channels/entity-search
export interface EntitySearchResult {
    type:     "order" | "production_order" | "eod_report";
    id:       number;
    label:    string;    // e.g. "#ORD-1234"
    subtitle: string;    // customer name or product name
    status:   string;
    meta:     string;    // amount or qty
    url:      string;    // deep-link for navigation on tap
}

// Rich preview data for entity chips — populated by IntelligenceService::entityChipPreviews()
export interface EntityPreview {
    type:        "order" | "production_order";
    id:          number;
    label:       string;
    status:      string;
    badge:       { label: string; color: string };
    meta:        string;
    subtitle:    string;
    url:         string;
    payment_status?: string;
    created_at?:     string;
    due_date?:   string;
    priority?:   string;
    is_overdue?: boolean;
}

export interface ChannelMessage {
    id:              number;
    channel_id:      number;
    reply_to_id:     number | null;
    reply_to?:       { id: number; body: string; user_name: string | null } | null;
    type:            "text" | "system";
    body:            string;
    mentions:        number[];
    linked_entities: LinkedEntity[];
    entity_previews: Record<string, EntityPreview>;
    attachments:     unknown[];
    reactions:       Record<string, number[]>;
    edited_at:       string | null;
    created_at:      string;
    user:            ChannelUser | null;
}

export interface ChannelAttachment {
    path:      string;
    name:      string;
    size:      number;
    mime_type: string;
    is_image:  boolean;
    url:       string;
}

// ─── API ──────────────────────────────────────────────────────────────────────

export const channelApi = {
    /** List all my channels. */
    list: () =>
        get<{ channels: Channel[] }>("/v1/admin/channels"),

    /** Create a new Space. */
    create: (payload: {
        name:        string;
        description?: string;
        is_private?:  boolean;
        member_ids?:  number[];
    }) => post<{ channel: Channel }>("/v1/admin/channels", payload),

    /** Get channel detail with members list. */
    get: (id: number) =>
        get<{ channel: Channel }>(`/v1/admin/channels/${id}`),

    /** Update a Space name/description/privacy. */
    update: (id: number, payload: { name?: string; description?: string; is_private?: boolean }) =>
        patch<{ channel: Channel }>(`/v1/admin/channels/${id}`, payload),

    /** Archive a Space. */
    delete: (id: number) =>
        del<{ message: string }>(`/v1/admin/channels/${id}`),

    /** Open or find a DM with a user. */
    openDm: (userId: number) =>
        post<{ channel: Channel }>("/v1/admin/channels/dm", { user_id: userId }),

    /** Add a member to a Space. */
    addMember: (channelId: number, userId: number, role?: "member" | "admin") =>
        post<{ message: string }>(`/v1/admin/channels/${channelId}/members`, { user_id: userId, role }),

    /** Remove a member from a Space. */
    removeMember: (channelId: number, userId: number) =>
        del<{ message: string }>(`/v1/admin/channels/${channelId}/members/${userId}`),

    /** Paginated message history. */
    messages: (channelId: number, before?: number) =>
        get<{ messages: ChannelMessage[]; has_more: boolean; oldest_id: number | null }>(
            `/v1/admin/channels/${channelId}/messages`,
            { params: { per_page: 50, ...(before ? { before } : {}) } }
        ),

    /** Send a message. */
    send: (channelId: number, payload: { body: string; reply_to_id?: number | null }) =>
        post<{ message: ChannelMessage }>(`/v1/admin/channels/${channelId}/messages`, payload),

    /** Edit a message. */
    edit: (channelId: number, msgId: number, body: string) =>
        patch<{ message: ChannelMessage }>(`/v1/admin/channels/${channelId}/messages/${msgId}`, { body }),

    /** Delete a message. */
    deleteMessage: (channelId: number, msgId: number) =>
        del<{ message: string }>(`/v1/admin/channels/${channelId}/messages/${msgId}`),

    /** Toggle a reaction. */
    react: (channelId: number, msgId: number, emoji: string) =>
        post<{ reactions: Record<string, number[]> }>(
            `/v1/admin/channels/${channelId}/messages/${msgId}/react`,
            { emoji }
        ),

    /** Upload a file attachment, returns metadata + serve URL. */
    uploadAttachment: (file: File) => {
        const form = new FormData();
        form.append("file", file);
        return import("@/api/client").then(({ tokenStorage }) => {
            const token = tokenStorage.get();
            const apiUrl = (import.meta as any).env?.VITE_API_URL ?? "http://localhost:8000";
            const base = apiUrl.replace(/\/api$/, ""); // strip trailing /api if present
            return fetch(`${base}/api/v1/admin/channels/attachments`, {
                method: "POST",
                headers: token ? { Authorization: `Bearer ${token}` } : {},
                body: form,
            }).then(async r => {
                if (!r.ok) {
                    const err = await r.text();
                    throw new Error(`Upload failed: ${r.status} ${err}`);
                }
                return r.json() as Promise<ChannelAttachment>;
            });
        });
    },

    /** Mark channel as fully read. */
    markRead: (channelId: number) =>
        post<{ message: string }>(`/v1/admin/channels/${channelId}/read`, {}),

    /**
     * Dismiss an order/production-order thread from my own sidebar. Per-user
     * only - other members still see it normally. Automatically reappears
     * the moment a new message lands in the channel; can also be reversed
     * immediately via undismiss() below.
     */
    dismiss: (channelId: number) =>
        post<{ message: string }>(`/v1/admin/channels/${channelId}/dismiss`, {}),

    /** Manually restore a dismissed thread before any new message arrives. */
    undismiss: (channelId: number) =>
        post<{ message: string }>(`/v1/admin/channels/${channelId}/undismiss`, {}),

    /** Find or create a context channel for a specific entity (e.g. production order). */
    findOrCreateContext: (contextType: "production_order" | "order", contextId: number) =>
        post<{ channel: Channel }>("/v1/admin/channels/context", {
            context_type: contextType,
            context_id:   contextId,
        }),

    /**
     * Search orders + production orders for # entity tagging.
     * q: search string  (matches order number, customer name, product name)
     * types: filter to specific entity types (defaults to all)
     */
    entitySearch: (q: string, types?: Array<"order" | "production_order">) =>
        get<{ results: EntitySearchResult[] }>("/v1/admin/channels/entity-search", {
            params: { q, ...(types ? { types } : {}) },
        }),
};