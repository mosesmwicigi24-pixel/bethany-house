import { get, post, patch, del } from "@/api/client";

// ─── Types ────────────────────────────────────────────────────────────────────

export type CommentModel =
    | "Order"
    | "ProductionOrder"
    | "PurchaseOrder"
    | "GoodsReceivedNote"
    | "PurchaseReturn"
    | "OrderReturn"
    | "InventoryTransfer";

export interface CommentUser {
    id:       number;
    name:     string;
    initials: string;
}

export interface Comment {
    id:          number;
    parent_id:   number | null;
    type:        "comment" | "note" | "system";
    body:        string;
    plain_body:  string;
    is_internal: boolean;
    mentions:    number[];
    edited_at:   string | null;
    created_at:  string;
    user:        CommentUser | null;
    replies?:    Comment[];
}

export interface MentionUser {
    id:       number;
    name:     string;
    email:    string;
    initials: string;
}

// ─── API ──────────────────────────────────────────────────────────────────────

export const commentApi = {
    /** Fetch all comments for a model instance. */
    list: (model: CommentModel, id: number) =>
        get<{ comments: Comment[] }>(`/v1/admin/comments?model=${model}&id=${id}`),

    /** Post a new comment. */
    post: (payload: {
        model:       CommentModel;
        id:          number;
        body:        string;
        type?:       "comment" | "note";
        is_internal?: boolean;
        parent_id?:  number | null;
    }) => post<{ comment: Comment }>("/v1/admin/comments", payload),

    /** Edit own comment (within 10 min window). */
    update: (commentId: number, body: string) =>
        patch<{ comment: Comment }>(`/v1/admin/comments/${commentId}`, { body }),

    /** Soft-delete a comment. */
    delete: (commentId: number) =>
        del<{ message: string }>(`/v1/admin/comments/${commentId}`),

    /** Search users for @mention autocomplete. */
    searchUsers: (q: string) =>
        get<{ users: MentionUser[] }>(`/v1/admin/comments/users?q=${encodeURIComponent(q)}`),
};