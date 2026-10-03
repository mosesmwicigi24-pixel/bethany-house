import type { ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { listProposals, withdrawProposal, type Proposal } from "@/api/proposals";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/*
 * The changes to a value that are waiting for signatures, or signed and
 * scheduled for a later date (Phase 3C). Shown on every edit screen of a
 * guarded value — price rows, costs, tax rates, currencies, payment methods,
 * materials, an order's deposit terms — so the editor sees that the live value
 * has NOT changed yet, who must sign, and can withdraw their own change.
 */

export const PROPOSALS_QUERY_KEY = "proposals-open";

const fmtDate = (iso: string) =>
    new Date(iso).toLocaleString("en-KE", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

function statusLine(p: Proposal): string {
    if (p.status === "scheduled") {
        return p.effective_from ? `Approved — takes effect ${fmtDate(p.effective_from)}` : "Approved — takes effect shortly";
    }
    const who = p.request?.awaiting ?? p.request?.needs?.join(", then ") ?? "an approver";
    return `This change needs approval from ${who}`;
}

export function PendingChanges({
    subjectType,
    subjectIds,
    events,
    className = "",
}: {
    subjectType: string;
    subjectIds: number[];
    /** Only these events (e.g. a cost-blind viewer's price screen leaves out product_cost_change). */
    events?: string[];
    className?: string;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const ids = subjectIds.filter((id) => Number.isFinite(id) && id > 0);

    const { data } = useQuery({
        queryKey: [PROPOSALS_QUERY_KEY, subjectType, ids.join(",")],
        queryFn: () => listProposals({ subject_type: subjectType, subject_ids: ids, status: "open" }),
        enabled: ids.length > 0,
        staleTime: 15_000,
    });

    const withdraw = useMutation({
        mutationFn: (p: Proposal) => withdrawProposal(p.id),
        onSuccess: (res) => {
            toast.success(res.message);
            qc.invalidateQueries({ queryKey: [PROPOSALS_QUERY_KEY] });
            qc.invalidateQueries({ queryKey: ["approvals-mine"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const items = (data?.data ?? []).filter((p) => !events || events.includes(p.event));
    if (items.length === 0) return null;

    return (
        <div className={`rounded-xl border border-warning/30 bg-warning-light px-3.5 py-3 space-y-2.5 ${className}`} role="status">
            {items.map((p) => (
                <div key={p.id} className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0 space-y-0.5">
                        <p className="text-sm font-semibold text-warning-dark">
                            {p.title}
                            {p.subject_label ? <span className="font-normal"> · {p.subject_label}</span> : null}
                        </p>
                        {p.changes.map((c) => (
                            <p key={c.field} className="text-xs text-surface-700">
                                {c.label}: <span className="line-through opacity-70">{c.old_display}</span> → <span className="font-semibold">{c.new_display}</span>
                            </p>
                        ))}
                        <p className="text-xs text-warning-dark">
                            {statusLine(p)}. The current value stays until then.
                        </p>
                        <p className="text-2xs text-surface-500">
                            {p.reference}{p.maker ? ` · proposed by ${p.maker.name}` : ""}{p.measure ? ` · ${p.measure}` : ""}
                        </p>
                    </div>
                    {p.can_withdraw && (
                        <button
                            type="button"
                            onClick={() => withdraw.mutate(p)}
                            disabled={withdraw.isPending}
                            className="btn-secondary btn-sm shrink-0"
                        >
                            Withdraw
                        </button>
                    )}
                </div>
            ))}
        </div>
    );
}

/** A one-line note of who signs a guarded field, shown next to it on the form. */
export function NeedsApprovalHint({ children }: { children: ReactNode }) {
    return <p className="text-2xs text-surface-500 mt-1">{children}</p>;
}
