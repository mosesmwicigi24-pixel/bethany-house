/**
 * TillsPage.tsx — the till lifecycle's back office (role hardening Phase 4B).
 *
 *   open → counted (cashier's BLIND count, from the POS register modal)
 *        → finalized (outlet manager verifies: pos.till_verify)
 *        → reconciled (accountant, next day: pos.reconcile)
 *        → corrected (finance, a linked record: pos.till_correction)
 *
 * Route: /pos/tills. Who sees which till is the server's rule (a cashier her
 * own last 7 days, a manager their outlets, finance/admin all). Every figure
 * is the server's; a cashier looking at her own unfinalized till receives no
 * expected cash or variance at all (`blind`), so this page cannot show one.
 * Each action is shown only to those who hold its key — and the API checks it
 * again regardless.
 */

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { tillsApi } from "@/api/tills";
import type { Till, TillListParams, TillStage } from "@/api/tills";
import { usePermissions } from "@/hooks/usePermissions";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { StatusTabs } from "@/components/ui/StatusTabs";

// ── Helpers ───────────────────────────────────────────────────────────────────

const money = (n: number | null | undefined) =>
    n === null || n === undefined
        ? "—"
        : n.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const when = (iso: string | null | undefined) =>
    iso
        ? new Date(iso).toLocaleString("en-KE", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })
        : "—";

const STAGE_LABEL: Record<TillStage, string> = {
    open:                  "Open",
    awaiting_verification: "Awaiting verification",
    finalized:             "Finalized",
    closed_unverified:     "Closed (never verified)",
};

const STAGE_TONE: Record<TillStage, string> = {
    open:                  "bg-info-light text-info",
    awaiting_verification: "bg-warning-light text-warning-dark",
    finalized:             "bg-success-light text-success-dark",
    closed_unverified:     "bg-surface-100 text-surface-600",
};

type TabKey = "all" | "awaiting" | "open" | "to_reconcile" | "mismatch" | "discrepancy" | "finalized";

const TAB_PARAMS: Record<TabKey, TillListParams> = {
    all:          {},
    awaiting:     { stage: "awaiting_verification" },
    open:         { stage: "open" },
    to_reconcile: { reconciliation: "none" },
    mismatch:     { reconciliation: "mismatch" },
    discrepancy:  { discrepancy: "awaiting_approval" },
    finalized:    { stage: "finalized" },
};

function StageBadge({ stage }: { stage: TillStage }) {
    return (
        <span className={clsx("inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold", STAGE_TONE[stage])}>
            {STAGE_LABEL[stage]}
        </span>
    );
}

function VarianceText({ till }: { till: Till }) {
    if (till.blind || till.variance === null || till.variance === undefined) {
        return <span className="text-surface-400">—</span>;
    }
    const v = till.variance;
    return (
        <span className={clsx("font-semibold", Math.abs(v) < 0.005 ? "text-success" : v > 0 ? "text-info" : "text-danger")}>
            {Math.abs(v) < 0.005 ? "Balanced" : `${v > 0 ? "+" : "−"}${money(Math.abs(v))}`}
        </span>
    );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function TillsPage() {
    const { can } = usePermissions();
    const canVerify    = can("pos.till_verify");
    const canReconcile = can("pos.reconcile");
    const canCorrect   = can("pos.till_correction");

    const [tab, setTab] = useState<TabKey>(canVerify ? "awaiting" : canReconcile ? "to_reconcile" : "all");
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const tabs = [
        { value: "all", label: "All" },
        ...(canVerify || canReconcile || canCorrect ? [{ value: "awaiting", label: "Awaiting verification", color: "warning" as const }] : []),
        { value: "open", label: "Open" },
        ...(canReconcile || canCorrect ? [
            { value: "to_reconcile", label: "To reconcile" },
            { value: "mismatch", label: "Mismatches", color: "danger" as const },
        ] : []),
        ...(canVerify || canReconcile || canCorrect ? [{ value: "discrepancy", label: "Discrepancies held", color: "danger" as const }] : []),
        { value: "finalized", label: "Finalized", color: "success" as const },
    ];

    const { data, isLoading, isError } = useQuery({
        queryKey: ["tills", tab, page],
        queryFn: () => tillsApi.list({ ...TAB_PARAMS[tab], page }),
        placeholderData: (prev) => prev,
    });

    const tills = data?.data ?? [];
    const meta = data?.meta;

    return (
        <div className="flex h-full">
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
                <div className="px-4 sm:px-6 pt-5 pb-4 border-b border-line">
                    <h1 className="text-lg sm:text-xl font-bold text-surface-900">Tills</h1>
                    <p className="text-xs text-surface-400 mt-0.5">
                        Cashiers count blind; an outlet manager verifies and finalizes; the accountant reconciles
                        against payments. A finalized till never changes — finance corrects it with a linked record.
                    </p>
                    <div className="mt-4">
                        <StatusTabs
                            value={tab}
                            onChange={(v) => { setTab(v as TabKey); setPage(1); }}
                            tabs={tabs}
                        />
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto">
                    {isLoading ? (
                        <div className="flex items-center justify-center h-48"><Spinner size="lg" /></div>
                    ) : isError ? (
                        <div className="flex items-center justify-center h-48 text-sm text-surface-400">Failed to load tills.</div>
                    ) : tills.length === 0 ? (
                        <div className="flex items-center justify-center h-48 text-sm text-surface-400">No tills here.</div>
                    ) : (
                        <>
                            <table className="w-full text-xs">
                                <thead className="bg-surface-50 text-surface-500 text-2xs uppercase tracking-wide">
                                    <tr>
                                        <th className="text-left px-4 py-2.5">Till</th>
                                        <th className="text-left px-4 py-2.5 hidden md:table-cell">Cashier</th>
                                        <th className="text-left px-4 py-2.5 hidden md:table-cell">Opened</th>
                                        <th className="text-left px-4 py-2.5">Stage</th>
                                        <th className="text-right px-4 py-2.5">Counted</th>
                                        <th className="text-right px-4 py-2.5">Variance</th>
                                        <th className="text-left px-4 py-2.5 hidden lg:table-cell">Reconciled</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {tills.map((t) => (
                                        <tr
                                            key={t.id}
                                            onClick={() => setSelectedId(t.id === selectedId ? null : t.id)}
                                            className={clsx("cursor-pointer", selectedId === t.id ? "bg-brand-50" : "hover:bg-surface-50")}
                                        >
                                            <td className="px-4 py-2.5">
                                                <p className="font-semibold text-surface-900">#{t.id} · {t.outlet_name}</p>
                                                {t.legacy && <p className="text-2xs text-warning-dark">Opened before verification existed</p>}
                                            </td>
                                            <td className="px-4 py-2.5 hidden md:table-cell">{t.opened_by ?? "—"}</td>
                                            <td className="px-4 py-2.5 hidden md:table-cell">{when(t.opened_at)}</td>
                                            <td className="px-4 py-2.5"><StageBadge stage={t.stage} /></td>
                                            <td className="px-4 py-2.5 text-right">{t.counted_cash !== null ? money(t.counted_cash) : "—"}</td>
                                            <td className="px-4 py-2.5 text-right"><VarianceText till={t} /></td>
                                            <td className="px-4 py-2.5 hidden lg:table-cell">
                                                {t.reconciliation
                                                    ? <span className={t.reconciliation.status === "matched" ? "text-success" : "text-danger font-semibold"}>
                                                        {t.reconciliation.status === "matched" ? "Matched" : "Mismatch"}
                                                      </span>
                                                    : <span className="text-surface-400">—</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {meta && meta.last_page > 1 && (
                                <div className="px-4 py-3 border-t border-line flex items-center justify-between">
                                    <p className="text-xs text-surface-500">Page {meta.current_page} of {meta.last_page} · {meta.total} tills</p>
                                    <div className="flex gap-1">
                                        <button disabled={meta.current_page === 1} onClick={() => setPage(meta.current_page - 1)} className="btn-ghost btn-sm text-xs disabled:opacity-40">← Prev</button>
                                        <button disabled={meta.current_page === meta.last_page} onClick={() => setPage(meta.current_page + 1)} className="btn-ghost btn-sm text-xs disabled:opacity-40">Next →</button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>

            {selectedId !== null && (
                <TillDrawer id={selectedId} onClose={() => setSelectedId(null)} />
            )}
        </div>
    );
}

// ── Detail drawer ─────────────────────────────────────────────────────────────

function TillDrawer({ id, onClose }: { id: number; onClose: () => void }) {
    const { can } = usePermissions();
    const me = useAuthStore((s) => s.user?.id);
    const toast = useToastStore();
    const qc = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ["till", id],
        queryFn: () => tillsApi.show(id),
    });
    const till = data?.till;

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["till", id] });
        qc.invalidateQueries({ queryKey: ["tills"] });
    };
    const fail = (e: { message: string }) => toast.error(e.message);

    // Verify & finalize
    const [varianceReason, setVarianceReason] = useState("");
    const [verifyNotes, setVerifyNotes] = useState("");
    const finalizeMutation = useMutation({
        mutationFn: () => tillsApi.finalize(id, {
            ...(varianceReason.trim() ? { variance_reason: varianceReason.trim() } : {}),
            ...(verifyNotes.trim() ? { notes: verifyNotes.trim() } : {}),
        }),
        onSuccess: (res) => { toast.success(res.message); refresh(); },
        onError: fail,
    });

    // Reconcile
    const [recNotes, setRecNotes] = useState("");
    const reconcileMutation = useMutation({
        mutationFn: () => tillsApi.reconcile(id, recNotes.trim() ? { notes: recNotes.trim() } : {}),
        onSuccess: (res) => { toast.success(res.message); setRecNotes(""); refresh(); },
        onError: fail,
    });

    // Correction
    const [corrReason, setCorrReason] = useState("");
    const [corrActual, setCorrActual] = useState("");
    const [corrExpected, setCorrExpected] = useState("");
    const correctionMutation = useMutation({
        mutationFn: () => tillsApi.openCorrection(id, {
            reason: corrReason.trim(),
            corrected_actual_cash:   corrActual   !== "" ? Number(corrActual)   : null,
            corrected_expected_cash: corrExpected !== "" ? Number(corrExpected) : null,
        }),
        onSuccess: (res) => {
            toast.success(res.message);
            setCorrReason(""); setCorrActual(""); setCorrExpected("");
            refresh();
        },
        onError: fail,
    });

    // The operator's own notes, before finalization
    const [notes, setNotes] = useState<string | null>(null);
    const notesMutation = useMutation({
        mutationFn: () => tillsApi.updateNotes(id, notes ?? ""),
        onSuccess: () => { toast.success("Notes saved."); setNotes(null); refresh(); },
        onError: fail,
    });

    const isMine = till?.opened_by_id === me;
    const hasVariance = !!till && !till.blind && Math.abs(till.variance ?? 0) >= 0.005;

    return (
        <div className="fixed inset-0 z-40 flex flex-col bg-white sm:static sm:inset-auto sm:z-auto sm:w-[460px] sm:shrink-0 sm:border-l sm:border-line sm:overflow-hidden">
            <div className="px-5 py-4 border-b border-line shrink-0 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-bold text-surface-900 text-sm truncate">{till ? `Till #${till.id}` : "Till"}</h2>
                    {till && <p className="text-xs text-surface-400 mt-0.5 truncate">{till.outlet_name} · {till.opened_by ?? "—"}</p>}
                </div>
                <button onClick={onClose} className="btn-ghost btn-icon btn-sm shrink-0" aria-label="Close">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div className="flex-1 overflow-y-auto p-5 space-y-5 text-xs">
                {isLoading || !till ? (
                    <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <StageBadge stage={till.stage} />
                            {till.legacy && <span className="text-2xs text-warning-dark font-medium">Opened before verification existed</span>}
                        </div>

                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5">
                            <dt className="text-surface-500">Opened</dt><dd className="text-right">{when(till.opened_at)}</dd>
                            <dt className="text-surface-500">Counted</dt><dd className="text-right">{when(till.closed_at)}</dd>
                            {till.finalized_at && (<><dt className="text-surface-500">Finalized</dt><dd className="text-right">{when(till.finalized_at)}{till.verified_by ? ` · ${till.verified_by}` : ""}</dd></>)}
                            <dt className="text-surface-500">Transactions</dt><dd className="text-right">{till.transaction_count}</dd>
                        </dl>

                        {till.blind ? (
                            <div className="rounded-xl bg-surface-50 p-3 space-y-1.5">
                                <Row label="Opening float" value={money(till.opening_cash)} />
                                <Row label="Your count" value={money(till.counted_cash)} />
                                <p className="text-2xs text-surface-500 pt-1.5 border-t border-surface-200">
                                    {till.stage === "open"
                                        ? "This till is open. When you close it you count the drawer blind."
                                        : "Your count is with your outlet manager. You will see how the till came out once it is finalized."}
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-xl bg-surface-50 p-3 space-y-1.5">
                                <Row label="Opening float" value={money(till.breakdown?.opening_float ?? till.opening_cash)} />
                                {till.breakdown && (
                                    <>
                                        <Row label="+ Cash sales" value={money(till.breakdown.cash_sales)} />
                                        {till.breakdown.cash_in > 0 && <Row label="+ Cash in" value={money(till.breakdown.cash_in)} />}
                                        <Row label="− Cash refunds" value={money(till.breakdown.refunds)} />
                                        <Row label="− Voids" value={money(till.breakdown.voids)} />
                                        <Row label="− Paid-outs" value={money(till.breakdown.paid_outs)} />
                                    </>
                                )}
                                <Row strong label="Expected cash" value={money(till.expected_cash_at_count ?? till.breakdown?.expected)} />
                                <Row strong label="Counted" value={money(till.counted_cash)} />
                                <div className="flex justify-between border-t border-surface-200 pt-1.5">
                                    <span className="font-semibold text-surface-900">Variance</span>
                                    <VarianceText till={till} />
                                </div>
                                {till.expected_basis_mismatch !== null && till.expected_basis_mismatch !== undefined && (
                                    <p className="text-2xs text-warning-dark pt-1">
                                        The drawer's ledger and its old running total disagree by {money(Math.abs(till.expected_basis_mismatch))}
                                        {" "}(running total {money(till.expected_cash_running_at_count)}). Likely sales from before the ledger
                                        existed — check before deciding the variance.
                                    </p>
                                )}
                                {till.float_vs_previous_close !== null && till.float_vs_previous_close !== undefined && Math.abs(till.float_vs_previous_close) >= 0.005 && (
                                    <p className="text-2xs text-surface-500 pt-1">
                                        Opening float differed from the outlet's previous count by {till.float_vs_previous_close > 0 ? "+" : "−"}{money(Math.abs(till.float_vs_previous_close))}.
                                    </p>
                                )}
                            </div>
                        )}

                        {till.discrepancy && (
                            <div className={clsx("rounded-xl border p-3 space-y-1", till.discrepancy.status === "awaiting_approval" ? "border-danger/40 bg-danger-light" : "border-line")}>
                                <p className="font-semibold text-surface-900">
                                    Discrepancy: {till.discrepancy.direction} {money(Math.abs(till.discrepancy.amount))}
                                    {" · "}{till.discrepancy.status === "awaiting_approval" ? "held for approval" : "logged"}
                                </p>
                                <p className="text-surface-600">{till.discrepancy.reason}</p>
                            </div>
                        )}

                        {till.variance_reason && !till.discrepancy && (
                            <p className="text-surface-600"><span className="font-semibold">Reason:</span> {till.variance_reason}</p>
                        )}

                        {/* Operator's notes, before finalization */}
                        {isMine && (till.stage === "open" || till.stage === "awaiting_verification") && (
                            <section className="space-y-2">
                                <label className="label">Your notes on this till</label>
                                <textarea
                                    value={notes ?? till.closing_notes ?? ""}
                                    onChange={(e) => setNotes(e.target.value)}
                                    rows={2}
                                    className="input resize-none"
                                />
                                {notes !== null && (
                                    <button onClick={() => notesMutation.mutate()} disabled={notesMutation.isPending} className="btn-secondary btn-sm">
                                        Save notes
                                    </button>
                                )}
                            </section>
                        )}

                        {/* Step 2b: verify & finalize */}
                        {can("pos.till_verify") && till.stage === "awaiting_verification" && (
                            <section className="rounded-xl border border-line p-3 space-y-2.5">
                                <h3 className="font-semibold text-surface-900">Verify &amp; finalize</h3>
                                {isMine ? (
                                    <p className="text-surface-500">You counted this till, so another manager must verify it.</p>
                                ) : (
                                    <>
                                        <p className="text-surface-500">
                                            Finalizing locks the till: its figures can never change again.
                                            {hasVariance && " A variance above 100 is held for approval."}
                                        </p>
                                        {hasVariance && (
                                            <div>
                                                <label className="label">Why is it {(till.variance ?? 0) > 0 ? "over" : "short"}? (required)</label>
                                                <textarea value={varianceReason} onChange={(e) => setVarianceReason(e.target.value)} rows={2} className="input resize-none" />
                                            </div>
                                        )}
                                        <div>
                                            <label className="label">Notes <span className="text-surface-400 font-normal">(optional)</span></label>
                                            <textarea value={verifyNotes} onChange={(e) => setVerifyNotes(e.target.value)} rows={2} className="input resize-none" />
                                        </div>
                                        <button
                                            onClick={() => finalizeMutation.mutate()}
                                            disabled={finalizeMutation.isPending || (hasVariance && varianceReason.trim().length < 3)}
                                            className="btn-primary btn-sm w-full disabled:opacity-40"
                                        >
                                            {finalizeMutation.isPending ? "Finalizing…" : "Verify and finalize"}
                                        </button>
                                    </>
                                )}
                            </section>
                        )}

                        {/* Step 4: reconcile */}
                        {can("pos.reconcile") && till.stage === "finalized" && (
                            <section className="rounded-xl border border-line p-3 space-y-2.5">
                                <h3 className="font-semibold text-surface-900">Reconcile against payments</h3>
                                <p className="text-surface-500">
                                    Checks every cash sale this till recorded against the payments ledger, and looks for
                                    cash the cashier took that reached no till. Mismatches are flagged for finance.
                                </p>
                                <textarea value={recNotes} onChange={(e) => setRecNotes(e.target.value)} rows={2} placeholder="Notes (e.g. bank slip reference)" className="input resize-none" />
                                <button onClick={() => reconcileMutation.mutate()} disabled={reconcileMutation.isPending} className="btn-primary btn-sm w-full disabled:opacity-40">
                                    {reconcileMutation.isPending ? "Reconciling…" : "Record reconciliation"}
                                </button>
                            </section>
                        )}

                        {(till.reconciliations ?? []).length > 0 && (
                            <section className="space-y-2">
                                <h3 className="font-semibold text-surface-900">Reconciliations</h3>
                                {till.reconciliations!.map((r) => (
                                    <div key={r.id} className={clsx("rounded-lg border p-2.5 space-y-1", r.status === "matched" ? "border-line" : "border-danger/40 bg-danger-light")}>
                                        <p className="font-semibold">{r.status === "matched" ? "Matched" : "Mismatch — flagged for finance"} · {when(r.created_at)}{r.reconciled_by ? ` · ${r.reconciled_by}` : ""}</p>
                                        <Row label="Till cash sales" value={money(r.till_cash_sales)} />
                                        <Row label="Payments ledger" value={money(r.payments_cash)} />
                                        {r.missing_from_till_count > 0 && <Row label={`Cash that reached no till (${r.missing_from_till_count})`} value={money(r.missing_from_till_amount)} />}
                                        {r.details?.order_mismatches?.map((m) => (
                                            <p key={m.order_id} className="text-2xs text-danger">Order #{m.order_id}: till {money(m.till)} vs payments {money(m.payments)}</p>
                                        ))}
                                        {r.notes && <p className="text-surface-600">{r.notes}</p>}
                                    </div>
                                ))}
                            </section>
                        )}

                        {/* Step 5: a linked correction, never a reopen */}
                        {can("pos.till_correction") && till.stage === "finalized" && (
                            <section className="rounded-xl border border-line p-3 space-y-2.5">
                                <h3 className="font-semibold text-surface-900">Open a correction</h3>
                                <p className="text-surface-500">A finalized till is never reopened. A correction is a new record pointing at it; the till itself stays as it was counted.</p>
                                <textarea value={corrReason} onChange={(e) => setCorrReason(e.target.value)} rows={2} placeholder="Reason (required)" className="input resize-none" />
                                <div className="grid grid-cols-2 gap-2">
                                    <div>
                                        <label className="label">Corrected count</label>
                                        <input type="number" min={0} step={0.01} value={corrActual} onChange={(e) => setCorrActual(e.target.value)} className="input" />
                                    </div>
                                    <div>
                                        <label className="label">Corrected expected</label>
                                        <input type="number" min={0} step={0.01} value={corrExpected} onChange={(e) => setCorrExpected(e.target.value)} className="input" />
                                    </div>
                                </div>
                                <button onClick={() => correctionMutation.mutate()} disabled={correctionMutation.isPending || corrReason.trim().length < 10} className="btn-secondary btn-sm w-full disabled:opacity-40">
                                    {correctionMutation.isPending ? "Recording…" : "Record correction"}
                                </button>
                            </section>
                        )}

                        {(till.corrections ?? []).length > 0 && (
                            <section className="space-y-2">
                                <h3 className="font-semibold text-surface-900">Corrections</h3>
                                {till.corrections!.map((c) => (
                                    <div key={c.id} className="rounded-lg border border-line p-2.5 space-y-1">
                                        <p className="font-semibold">#{c.id} · {when(c.created_at)}{c.opened_by ? ` · ${c.opened_by}` : ""}</p>
                                        <p className="text-surface-600">{c.reason}</p>
                                        <Row label="Variance" value={`${money(c.original_variance)} → ${money(c.corrected_variance)}`} />
                                    </div>
                                ))}
                            </section>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={clsx("flex justify-between", strong ? "font-semibold text-surface-900" : "text-surface-600")}>
            <span>{label}</span>
            <span>{value}</span>
        </div>
    );
}
