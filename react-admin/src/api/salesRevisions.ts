import { get, post, put } from "./client";
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
        amount?: number;
        currency_code?: string;
    }) => put<{ message: string }>(`/v1/admin/orders/${orderId}/payments/${paymentId}/revise`, data),
    /** The owner deletes a payment, softly: voided and hidden, the record kept. */
    removePayment: (orderId: number, paymentId: number, reason: string) =>
        post<{ message: string; payment_status: string; balance: number }>(`/v1/admin/orders/${orderId}/payments/${paymentId}/remove`, { reason }),

    quotationRevisions: (quotationId: number) =>
        get<{ current_revision: number; revisions: DocumentRevision[] }>(`/v1/admin/quotations/${quotationId}/revisions`),
};
