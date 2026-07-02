import { get, post, put } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ReasonCode {
    label: string;
    direction: "increase" | "decrease" | "either";
    requires_approval: boolean;
}

export interface Adjustment {
    id: number;
    transaction_type: string;
    reason_code: string;
    reason_label: string;
    reference_number: string | null;
    quantity_change: number;
    quantity_before: number;
    quantity_after: number;
    notes: string | null;
    status: "pending_approval" | "approved" | "rejected";
    approval_notes: string | null;
    created_at: string;
    approved_at: string | null;
    created_by: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    inventory_item: {
        id: number;
        quantity_on_hand: number;
        product: {
            id: number;
            sku: string;
            name: string;
            image_url: string | null;
        } | null;
        variant: {
            id: number;
            sku: string;
            variant_name: string;
        } | null;
        outlet: {
            id: number;
            name: string;
        } | null;
    } | null;
}

export interface AdjustmentStats {
    total: number;
    pending_approval: number;
    approved: number;
    rejected: number;
    total_shrinkage: number;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const adjustmentsApi = {
    list: (params?: Record<string, string>) =>
        get<{
            data: Adjustment[];
            meta: any;
            stats: AdjustmentStats;
            reason_codes: Record<string, ReasonCode>;
        }>("/v1/admin/inventory/adjustments", { params }),

    get: (id: number) =>
        get<{ adjustment: Adjustment }>(
            `/v1/admin/inventory/adjustments/${id}`,
        ),

    pending: () =>
        get<{ data: Adjustment[]; count: number }>(
            "/v1/admin/inventory/adjustments/pending",
        ),

    create: (data: {
        inventory_item_id: number;
        quantity_change: number;
        reason_code: string;
        notes?: string;
        reference_number?: string;
    }) =>
        post<{
            message: string;
            adjustment: Adjustment;
            requires_approval: boolean;
        }>("/v1/admin/inventory/adjustments", data),

    approve: (id: number, notes?: string) =>
        put<{ message: string; adjustment: Adjustment }>(
            `/v1/admin/inventory/adjustments/${id}/approve`,
            { notes },
        ),

    reject: (id: number, reason: string) =>
        put<{ message: string }>(
            `/v1/admin/inventory/adjustments/${id}/reject`,
            { reason },
        ),

    reverse: (id: number, notes?: string) =>
        put<{ message: string; reversal: Adjustment }>(
            `/v1/admin/inventory/adjustments/${id}/reverse`,
            { notes },
        ),

    reasonCodes: () =>
        get<{ data: Record<string, ReasonCode> }>(
            "/v1/admin/inventory/adjustments/reason-codes",
        ),
};
