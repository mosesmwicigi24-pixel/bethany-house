import { get } from "@/api/client";
import type { Paginated } from "@/api/quotations";

export interface Invoice {
    id: number;
    invoice_number: string;
    issued_at: string | null;
    due_date: string | null;
    amount: number;
    currency_code: string;
    doc_status: string; // issued | paid | void
    order: {
        id: number;
        order_number: string;
        payment_status: string; // pending | partial | paid …
        pay_token: string | null;
    } | null;
    customer_name: string | null;
    quotation: { number: string; quotation_id: number; sales_doc_id: number } | null;
}

export const invoiceApi = {
    list: (params?: Record<string, string | number>) =>
        get<Paginated<Invoice>>("/v1/admin/invoices", { params }),

    get: (id: number) => get<{ invoice: Invoice }>(`/v1/admin/invoices/${id}`),
};
