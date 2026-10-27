import { get, post, del } from "./client";

/** Document signing (People C4 part 2). */
export interface SigningRequest {
    id: number;
    kind: "document" | "article" | "text";
    title: string;
    status: "pending" | "signed" | "declined" | "cancelled";
    due_at: string | null;
    signed_at: string | null;
    note: string | null;
    requested_by: string | null;
    created_at: string | null;
    staff_document_id: number | null;
    article_id: number | null;
    signed_document_id: number | null;
    body?: string | null;
    document_link?: { url: string; name: string | null } | null;
}

export const signingApi = {
    mine: () => get<{ data: SigningRequest[]; pending: number }>("/v1/admin/me/sign"),
    show: (id: number) => get<{ request: SigningRequest }>(`/v1/admin/me/sign/${id}`),
    sign: (id: number, signature: string) => post<{ message: string; request: SigningRequest }>(`/v1/admin/me/sign/${id}/sign`, { signature }),
    decline: (id: number, note: string) => post<{ message: string; request: SigningRequest }>(`/v1/admin/me/sign/${id}/decline`, { note }),
    forPerson: (userId: number) => get<{ data: SigningRequest[] }>(`/v1/admin/people/${userId}/signing`),
    request: (userId: number, d: { kind: "document" | "article" | "text"; staff_document_id?: number; article_id?: number; title?: string; body?: string; due_at?: string | null }) =>
        post<{ message: string; request: SigningRequest }>(`/v1/admin/people/${userId}/signing`, d),
    cancel: (userId: number, id: number) => del<{ message: string }>(`/v1/admin/people/${userId}/signing/${id}`),
};
