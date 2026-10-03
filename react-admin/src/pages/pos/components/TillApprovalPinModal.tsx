import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { posApi } from "@/api/pos";
import type { TillApprovalRequest } from "@/api/pos";
import type { ApiError } from "@/types";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";

/** Who a band is waiting on, in the words the till uses. */
export function bandLabel(permission: string | null | undefined): string {
    switch (permission) {
        case "pos.approve_reversal":   return "an outlet manager";
        case "approvals.finance_sign": return "finance";
        case "approvals.super_sign":   return "the owner";
        default:                        return "an approver";
    }
}

interface Props {
    approvalId: number;
    /** e.g. "Void ORD-1234" or "Refund on ORD-1234" */
    title: string;
    /** The band the request waits on now, when known. */
    awaiting?: string | null;
    onClose: () => void;
    /** Called once the request is decided (approved or rejected) on this till. */
    onDecided?: (status: TillApprovalRequest["status"]) => void;
}

/**
 * Phase 4B part 2 — an approver present at the till signs the clerk's void or
 * refund request with THEIR terminal PIN. The clerk stays signed in; the PIN
 * is never stored here. Someone who is not at the till signs from the
 * Approvals inbox instead, so "Leave for the inbox" is always an option.
 */
export default function TillApprovalPinModal({ approvalId, title, awaiting, onClose, onDecided }: Props) {
    const toast = useToastStore();
    const qc = useQueryClient();

    const [approverId, setApproverId] = useState<number | null>(null);
    const [pin, setPin] = useState("");
    const [mode, setMode] = useState<"approve" | "reject">("approve");
    const [rejectReason, setRejectReason] = useState("");
    const [waitingOn, setWaitingOn] = useState<string | null | undefined>(awaiting);
    const [error, setError] = useState<string | null>(null);
    const [note, setNote] = useState<string | null>(null);

    const { data, isLoading, refetch } = useQuery({
        queryKey: ["till-approvers", approvalId],
        queryFn: () => posApi.tillApprovers(approvalId),
    });
    const approvers = data?.data ?? [];

    const sign = useMutation({
        mutationFn: () =>
            posApi.pinSign(approvalId, {
                approver_id: approverId!,
                pin,
                decision: mode,
                ...(mode === "reject" ? { reason: rejectReason } : {}),
            }),
        onSuccess: (res) => {
            setPin("");
            setError(null);
            qc.invalidateQueries({ queryKey: ["pos-sales"] });
            if (res.request.status === "pending") {
                // One band signed; the next needs someone else.
                setApproverId(null);
                setWaitingOn(res.request.awaiting?.permission ?? null);
                setNote(res.message);
                refetch();
                return;
            }
            toast.success(res.message);
            onDecided?.(res.request.status);
            onClose();
        },
        onError: (err: ApiError) => {
            setPin("");
            setError(err.message);
        },
    });

    const canSubmit = approverId !== null && /^\d{4,6}$/.test(pin)
        && (mode === "approve" || rejectReason.trim().length >= 3) && !sign.isPending;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm flex flex-col animate-slide-up" role="dialog" aria-modal="true" aria-labelledby="till-approval-title">
                <div className="px-5 py-4 border-b border-line">
                    <h2 id="till-approval-title" className="font-bold text-surface-900">Approver at the till</h2>
                    <p className="text-xs text-surface-500">{title}</p>
                </div>

                <div className="p-5 space-y-4">
                    <div className="bg-warning-light text-warning-dark rounded-xl px-3 py-2 text-xs">
                        Waiting for {bandLabel(waitingOn)}. They enter their own PIN here — never yours.
                    </div>

                    {note && <p className="text-xs text-success">{note}</p>}

                    {isLoading ? (
                        <div className="flex justify-center py-4"><Spinner /></div>
                    ) : approvers.length === 0 ? (
                        <p className="text-xs text-surface-500">
                            Nobody who can sign this is set up to approve at this till. It stays in the Approvals inbox.
                        </p>
                    ) : (
                        <>
                            <div>
                                <label className="label" htmlFor="till-approver">Approver</label>
                                <select
                                    id="till-approver"
                                    className="input"
                                    value={approverId ?? ""}
                                    onChange={(e) => { setApproverId(e.target.value ? Number(e.target.value) : null); setError(null); }}
                                >
                                    <option value="">Choose who is approving…</option>
                                    {approvers.map((a) => (
                                        <option key={a.id} value={a.id} disabled={!a.pin_set}>
                                            {a.name}{a.pin_set ? "" : " (no PIN set — use the inbox)"}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="label" htmlFor="till-approver-pin">Approver's PIN</label>
                                <input
                                    id="till-approver-pin"
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    maxLength={6}
                                    className="input tracking-[0.4em] text-center"
                                    value={pin}
                                    onChange={(e) => { setPin(e.target.value.replace(/\D/g, "")); setError(null); }}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                {(["approve", "reject"] as const).map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        onClick={() => setMode(m)}
                                        className={clsx(
                                            "py-2 rounded-xl border text-xs font-medium capitalize transition-all",
                                            mode === m
                                                ? (m === "approve" ? "bg-brand-500 text-white border-brand-500" : "bg-danger text-white border-danger")
                                                : "bg-white border-surface-200 text-surface-600",
                                        )}
                                    >
                                        {m}
                                    </button>
                                ))}
                            </div>

                            {mode === "reject" && (
                                <textarea
                                    className="input resize-none"
                                    rows={2}
                                    placeholder="Why is it rejected?"
                                    value={rejectReason}
                                    onChange={(e) => setRejectReason(e.target.value)}
                                />
                            )}

                            {error && <p className="text-xs text-danger" role="alert">{error}</p>}
                        </>
                    )}
                </div>

                <div className="p-4 border-t border-line flex gap-3">
                    <button type="button" onClick={onClose} className="btn-secondary flex-1 btn-sm">
                        Leave for the inbox
                    </button>
                    <button
                        type="button"
                        onClick={() => sign.mutate()}
                        disabled={!canSubmit}
                        className={clsx("flex-1 btn-sm gap-2", mode === "reject" ? "btn-danger" : "btn-primary")}
                    >
                        {sign.isPending && <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />}
                        {mode === "reject" ? "Reject" : "Approve"}
                    </button>
                </div>
            </div>
        </div>
    );
}
