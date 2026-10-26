import { get, post } from "@/api/client";
import type { Paginated } from "@/api/quotations";

export interface Invoice {
    id: number;
    invoice_number: string;
    /** Rev N since it was first issued: the copy follows its order (2026-10-16). */
    revision?: number;
    issued_at: string | null;
    due_date: string | null;
    amount: number;
    currency_code: string;
    doc_status: string; // issued | paid | void
    order: {
        id: number;
        order_number: string;
        /** The order's own status — pending | confirmed | cancelled … */
        status?: string;
        payment_status: string; // pending | partial | paid …
        /** Null for a dead order: it is not payable (2026-10-26). */
        pay_token: string | null;
        /** False when the order is cancelled / voided / refunded. */
        payable?: boolean;
    } | null;
    customer_name: string | null;
    /** The staff member who issued the invoice. */
    served_by: string | null;
    quotation: { number: string; quotation_id: number; sales_doc_id: number } | null;
}

export const invoiceApi = {
    list: (params?: Record<string, string | number>) =>
        get<Paginated<Invoice>>("/v1/admin/invoices", { params }),

    get: (id: number) => get<{ invoice: Invoice }>(`/v1/admin/invoices/${id}`),

    /** Super admin only (by role) + step-up, which the client asks for itself. */
    void: (id: number, reason: string) =>
        post<{ message: string; invoice: Invoice }>(`/v1/admin/invoices/${id}/void`, { reason }),
};
