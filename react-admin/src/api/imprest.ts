import { get, post, put } from "./client";

// The imprest (petty-cash float). All money moves on the server
// (App\Services\ImprestService); the console only asks and shows.

export interface ImprestTopup {
    id: number;
    uuid: string;
    status: "pending" | "sent" | "received" | "declined" | "cancelled";
    requested_amount: string | null;
    balance_at_request: string | null;
    reason: string | null;
    sent_amount: string | null;
    sent_method: string | null;
    sent_reference: string | null;
    sent_at: string | null;
    sent_note: string | null;
    received_amount: string | null;
    received_at: string | null;
    receipt_note: string | null;
    decline_reason: string | null;
    created_at: string;
    requested_by: number | null;
    requester?: Person | null;
    sender?: Person | null;
    receiver?: Person | null;
    decliner?: Person | null;
}

export interface Person { id: number; first_name: string; last_name: string; email?: string }

export interface ImprestAccountView {
    id: number;
    name: string;
    outlet: { id: number; name: string } | null;
    custodian: Person | null;
    currency_code: string;
    float_amount: string;
    balance: string;
    low_balance_percent: number;
    low_threshold: string;
    is_low: boolean;
    suggested_topup: string;
    open_topup: ImprestTopup | null;
    pending_counts: number;
    unresolved_expenses: number;
    you_are_custodian: boolean;
    can_read_book: boolean;
}

export interface ImprestLedgerRow {
    id: number;
    type: "opening" | "top_up" | "expense" | "expense_adjustment" | "cash_returned" | "count_adjustment";
    amount: string;
    balance_after: string;
    note: string | null;
    created_at: string;
    creator?: Person | null;
    expense?: { id: number; reference_number: string; title: string; status: string; imprest_resolution: string | null } | null;
}

export interface ImprestCount {
    id: number;
    counted_amount: string;
    book_balance: string;
    variance: string;
    note: string | null;
    status: "pending" | "approved" | "rejected";
    created_at: string;
    counter?: Person | null;
    decider?: Person | null;
    decision_note: string | null;
}

interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const B = "/v1/admin/expenses/imprest";

export const imprestApi = {
    summary: () => get<{ accounts: ImprestAccountView[]; is_super_admin: boolean; can_set_up: boolean }>(B),
    setup: (d: { name: string; custodian_id: number; float_amount: number; opening_balance: number; low_balance_percent?: number }) =>
        post<{ account: ImprestAccountView }>(B, d),
    update: (id: number, d: Partial<{ name: string; custodian_id: number; float_amount: number; low_balance_percent: number }>) =>
        put<{ account: ImprestAccountView }>(`${B}/${id}`, d),
    statement: (id: number, page = 1) => get<Page<ImprestLedgerRow>>(`${B}/${id}/statement`, { params: { page } }),
    topups: (id: number) => get<Page<ImprestTopup>>(`${B}/${id}/topups`),
    unreplenished: (id: number) =>
        get<{ data: Array<{ id: number; reference_number: string; title: string; amount_kes: string; status: string; expense_date: string; created_by?: Person }>; total: string }>(
            `${B}/${id}/unreplenished`,
        ),
    counts: (id: number) => get<Page<ImprestCount>>(`${B}/${id}/counts`),
    unresolved: () => get<Array<{ id: number; reference_number: string; title: string; amount_kes: string; status: string; created_by?: Person }>>(`${B}/unresolved`),

    requestTopup: (id: number, d: { amount?: number; reason?: string }) => post<{ request: ImprestTopup }>(`${B}/${id}/topups`, d),
    cancelTopup: (uuid: string) => post(`${B}/topups/${uuid}/cancel`),
    send: (uuid: string, d: SendInput) => post(`${B}/topups/${uuid}/send`, d),
    sendDirect: (id: number, d: SendInput) => post(`${B}/${id}/topups/direct`, d),
    decline: (uuid: string, reason: string) => post(`${B}/topups/${uuid}/decline`, { reason }),
    receive: (uuid: string, d: { amount: number; note?: string }) => post(`${B}/topups/${uuid}/receive`, d),

    count: (id: number, d: { counted_amount: number; note?: string }) => post<{ message: string; count: ImprestCount }>(`${B}/${id}/counts`, d),
    decideCount: (countId: number, approve: boolean, note?: string) => post(`${B}/counts/${countId}/decide`, { approve, note }),
    resolve: (expenseId: number, resolution: "returned" | "written_off", note?: string) =>
        post(`/v1/admin/expenses/${expenseId}/imprest-resolution`, { resolution, note }),
};

export interface SendInput { amount: number; method: "mpesa" | "cash" | "bank_transfer" | "other"; reference?: string; note?: string }

export const kes = (v: string | number | null | undefined) =>
    `KES ${Number(v ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
