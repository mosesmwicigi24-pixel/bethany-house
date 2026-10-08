import { get, post } from "@/api/client";

/*
 * Proposals (Phase 3C): changes to money-relevant values — prices, costs, tax
 * rates, exchange rates, payment settlement, deposit terms — that take effect
 * only once the required people have signed them in the Approvals inbox.
 */

export type ProposalStatus = "pending" | "scheduled" | "applied" | "rejected" | "expired" | "cancelled";

export interface ProposalChange {
    field: string;
    label: string;
    old: unknown;
    new: unknown;
    old_display: string;
    new_display: string;
}

export interface Proposal {
    id: number;
    reference: string;
    event: string;
    title: string;
    subject_type: string;
    subject_id: number;
    subject_label: string | null;
    changes: ProposalChange[];
    measure: string | null;
    unit: "percent" | "kes" | "none" | null;
    effective_from: string | null;
    status: ProposalStatus;
    direct: boolean;
    maker: { id: number; name: string } | null;
    note: string | null;
    applied_at: string | null;
    created_at: string | null;
    request: {
        id: number;
        version: number;
        status: string;
        needs: string[];
        awaiting: string | null;
        rejected_reason: string | null;
        expires_at: string | null;
    } | null;
    can_withdraw: boolean;
}

export interface ProposalListParams {
    event?: string;
    subject_type?: string;
    subject_id?: number;
    subject_ids?: number[];
    status?: "open" | "all";
    mine?: boolean;
    limit?: number;
}

export function listProposals(params: ProposalListParams): Promise<{ data: Proposal[]; count: number }> {
    const { subject_ids, mine, ...rest } = params;
    return get("/v1/admin/proposals", {
        params: {
            ...rest,
            ...(subject_ids && subject_ids.length ? { subject_ids: subject_ids.join(",") } : {}),
            ...(mine ? { mine: 1 } : {}),
        },
    });
}

export interface ProposeBody {
    event: string;
    subject_id: number;
    changes: Record<string, unknown>;
    effective_from?: string | null;
    note?: string | null;
}

export function proposeChange(body: ProposeBody): Promise<{ message: string; proposal: Proposal | null }> {
    return post("/v1/admin/proposals", body);
}

export function previewChange(body: ProposeBody): Promise<{ direct: boolean; needs: string[]; message: string }> {
    return post("/v1/admin/proposals/preview", body);
}

export function withdrawProposal(id: number): Promise<{ message: string; proposal: Proposal }> {
    return post(`/v1/admin/proposals/${id}/withdraw`, {});
}

/** A save response's proposals that still wait (not applied at once). */
export function waitingOf(proposals: (Proposal | null | undefined)[] | null | undefined): Proposal[] {
    return (proposals ?? []).filter((p): p is Proposal => !!p && p.status !== "applied");
}

/** Did a save response raise a change that waits for approval? (Reads `proposal` or `proposals`.) */
export function waitsForApproval(res: unknown): boolean {
    const r = (res ?? {}) as { proposal?: Proposal | null; proposals?: Proposal[] | null };
    return waitingOf([r.proposal, ...(r.proposals ?? [])]).length > 0;
}
