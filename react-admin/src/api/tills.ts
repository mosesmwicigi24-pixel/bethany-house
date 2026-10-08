import { get, post, patch } from "./client";

/**
 * The till lifecycle's back office (Phase 4B): verify & finalize, reconcile,
 * correct. Every figure is the server's; this module only carries it.
 *
 * Fields marked optional are absent when `blind` is true — the viewer is the
 * till's own operator and it is not yet finalized.
 */

export type TillStage = "open" | "awaiting_verification" | "finalized" | "closed_unverified";

export interface TillDiscrepancy {
    id: number;
    amount: number;
    direction: "over" | "short";
    expected_cash: number;
    counted_cash: number;
    expected_basis_mismatch: number | null;
    reason: string;
    status: "logged" | "awaiting_approval" | string;
    approval_request_id: number | null;
    created_at: string | null;
}

export interface TillReconciliation {
    id: number;
    status: "matched" | "mismatch";
    flagged_for_finance: boolean;
    till_cash_sales: number;
    payments_cash: number;
    difference: number;
    missing_from_till_count: number;
    missing_from_till_amount: number;
    details: {
        order_mismatches: { order_id: number; till: number; payments: number }[];
        missing_from_till: { payment_id: number; order_id: number; amount: number }[];
    } | null;
    notes: string | null;
    reconciled_by: string | null;
    created_at: string | null;
}

export interface TillCorrection {
    id: number;
    cash_register_id: number;
    reason: string;
    original_actual_cash: number | null;
    original_expected_cash: number | null;
    original_variance: number | null;
    corrected_actual_cash: number | null;
    corrected_expected_cash: number | null;
    corrected_variance: number | null;
    status: string;
    opened_by: string | null;
    created_at: string | null;
}

export interface Till {
    id: number;
    register_number: string;
    register_name: string;
    outlet_id: number;
    outlet_name: string | null;
    currency_code: string;
    status: "open" | "counted" | "closed";
    stage: TillStage;
    legacy: boolean;
    blind: boolean;
    opened_by_id: number | null;
    opened_by: string | null;
    closed_by: string | null;
    opened_at: string | null;
    closed_at: string | null;
    finalized_at: string | null;
    opening_cash: number;
    transaction_count: number;
    counted_cash: number | null;
    closing_cash: number | null;
    notes: string | null;
    closing_notes: string | null;
    // Not blind:
    expected_cash?: number;
    expected_cash_at_count?: number | null;
    expected_cash_running_at_count?: number | null;
    expected_basis_mismatch?: number | null;
    variance?: number | null;
    variance_class?: "balanced" | "over" | "short" | null;
    variance_reason?: string | null;
    verification_notes?: string | null;
    verified_by?: string | null;
    verified_by_id?: number | null;
    float_vs_previous_close?: number | null;
    previous_register_id?: number | null;
    total_sales?: number;
    total_cash_sales?: number;
    total_card_sales?: number;
    total_mpesa_sales?: number;
    total_refunds?: number;
    discrepancy?: TillDiscrepancy | null;
    reconciliation?: TillReconciliation | null;
    // Detail only:
    breakdown?: {
        opening_float: number;
        cash_sales: number;
        cash_in: number;
        refunds: number;
        voids: number;
        paid_outs: number;
        expected: number;
    };
    denomination_count?: Record<string, number> | null;
    reconciliations?: TillReconciliation[];
    corrections?: TillCorrection[];
}

export interface TillListParams {
    outlet_id?: number;
    stage?: TillStage;
    reconciliation?: "none" | "matched" | "mismatch";
    discrepancy?: "any" | "logged" | "awaiting_approval";
    page?: number;
    per_page?: number;
}

export const tillsApi = {
    list: (params: TillListParams = {}) =>
        get<{ data: Till[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
            "/v1/admin/pos/tills",
            { params },
        ),

    show: (id: number) => get<{ till: Till }>(`/v1/admin/pos/tills/${id}`),

    updateNotes: (id: number, notes: string) =>
        patch<{ till: Till }>(`/v1/admin/pos/tills/${id}`, { notes }),

    finalize: (id: number, data: { variance_reason?: string; notes?: string }) =>
        post<{ message: string; till: Till }>(`/v1/admin/pos/tills/${id}/finalize`, data),

    reconcile: (id: number, data: { notes?: string }) =>
        post<{ message: string; reconciliation: TillReconciliation }>(`/v1/admin/pos/tills/${id}/reconcile`, data),

    openCorrection: (
        id: number,
        data: { reason: string; corrected_actual_cash?: number | null; corrected_expected_cash?: number | null },
    ) => post<{ message: string; correction: TillCorrection }>(`/v1/admin/pos/tills/${id}/corrections`, data),
};
