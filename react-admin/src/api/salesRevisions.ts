import { get, put } from "./client";
import type { DocumentRevision } from "./procurement";

// Revisions of issued sales documents (owner, 2026-10-16).
export const salesRevisionApi = {
    orderHistory: (orderId: number) =>
        get<{
            invoice: { number: string; status: string; revision: number; revisions: DocumentRevision[] } | null;
            payments: Record<string, DocumentRevision[]>;
        }>(`/v1/admin/orders/${orderId}/document-revisions`),

    // Super admin only: the amount is never changed here (void and record again).
    revisePayment: (orderId: number, paymentId: number, data: {
        reason: string;
        provider_reference?: string | null;
        phone_number?: string | null;
        payment_method?: string;
        paid_at?: string | null;
    }) => put<{ message: string }>(`/v1/admin/orders/${orderId}/payments/${paymentId}/revise`, data),

    quotationRevisions: (quotationId: number) =>
        get<{ current_revision: number; revisions: DocumentRevision[] }>(`/v1/admin/quotations/${quotationId}/revisions`),
};
