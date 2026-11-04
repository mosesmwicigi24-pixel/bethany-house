import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { commissionsApi, kes, COMMISSION_LABEL, COMMISSION_TONE, type Commission, type CommissionStatus } from "@/api/payroll";
import PersonPicker from "@/components/people/PersonPicker";
import { ActionsMenu, type ActionItem } from "@/components/ui/ActionsMenu";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Payroll › Commissions (owner, 2026-11-03/04). Finance records a commission
 * against a transaction; a super admin approves it; the next pay run adds it
 * on top of salary; paying that payslip marks it paid and the balance goes
 * back to zero. PAYE on commission is one switch, a super admin's — off for now.
 */
export function CommissionsPanel() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const navigate = useNavigate();
    const [status, setStatus] = useState<CommissionStatus | "open" | "">("open");
    const [recording, setRecording] = useState(false);
    const [voiding, setVoiding] = useState<Commission | null>(null);
    const { data, isLoading } = useQuery({ queryKey: ["commissions", status], queryFn: () => commissionsApi.list({ status: status || undefined }) });
    const refresh = () => { void qc.invalidateQueries({ queryKey: ["commissions"] }); void qc.invalidateQueries({ queryKey: ["payroll-people"] }); };
    const onErr = (e: ApiError) => toast.error(e.message);
    const approve = useMutation({ mutationFn: (id: number) => commissionsApi.approve(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const paye = useMutation({ mutationFn: (on: boolean) => commissionsApi.setPaye(on), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });

    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;

    const menu = (c: Commission): ActionItem[] => [
        ...(c.can_approve ? [{ label: "Approve", icon: "check" as const, run: () => approve.mutate(c.id) }] : []),
        ...(c.can_void ? [{ label: "Void", icon: "x" as const, run: () => setVoiding(c), tone: "danger" as const }] : []),
        { label: "Open employee file", icon: "user", run: () => navigate(`/people/${c.user.id}`) },
    ];

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-surface-600">PAYE on commission:</span>
                    <span className={clsx("rounded-full px-2 py-0.5 text-2xs font-semibold", data.paye_applies ? "bg-info-light text-info" : "bg-surface-100 text-surface-600")}>
                        {data.paye_applies ? "On" : "Off"}
                    </span>
                    {data.can_approve && (
                        <button type="button" disabled={paye.isPending} onClick={() => paye.mutate(!data.paye_applies)} className="btn-ghost btn-sm">
                            {data.paye_applies ? "Turn off" : "Turn on"}
                        </button>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    <select className="input input-sm max-w-[220px]" value={status} onChange={e => setStatus(e.target.value as CommissionStatus | "open" | "")} aria-label="Show">
                        <option value="open">Open (awaiting approval or owed)</option>
                        <option value="recorded">Awaiting approval</option>
                        <option value="approved">Approved · next pay run</option>
                        <option value="paid">Paid</option>
                        <option value="void">Void</option>
                        <option value="">All</option>
                    </select>
                    {data.can_record && <button type="button" className="btn-primary btn-sm whitespace-nowrap" onClick={() => setRecording(true)}>Record commission</button>}
                </div>
            </div>

            <div className="grid grid-cols-3 gap-2">
                <Stat label="Awaiting approval" value={data.totals.awaiting_approval} tone="warning" />
                <Stat label="Owed · next pay run" value={data.totals.owed} tone="info" />
                <Stat label="Paid" value={data.totals.paid} tone="success" />
            </div>

            {/* Phones: cards. */}
            <div className="space-y-2 md:hidden">
                {data.data.map(c => (
                    <div key={c.id} className="card"><div className="card-body space-y-1.5">
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-semibold text-surface-900">{c.user.name}</span>
                            <div className="flex items-center gap-1"><span className="tabular-nums font-semibold">KES {kes(c.amount)}</span><ActionsMenu items={menu(c)} label={`Actions for ${c.transaction_ref}`} /></div>
                        </div>
                        <p className="text-2xs text-surface-500">{c.transaction_ref} · earned {fmtDate(c.earned_on)}</p>
                        <StatusChip c={c} />
                    </div></div>
                ))}
            </div>

            <div className="card overflow-hidden hidden md:block">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-surface-50 text-2xs uppercase tracking-wider text-surface-500">
                            <tr>
                                <th className="px-3 py-2 text-left">Earned</th>
                                <th className="px-3 py-2 text-left">Staff</th>
                                <th className="px-3 py-2 text-left">Transaction</th>
                                <th className="px-3 py-2 text-right">Amount</th>
                                <th className="px-3 py-2 text-left">Status</th>
                                <th className="px-3 py-2 text-left">Sign-offs</th>
                                <th className="px-2 py-2"><span className="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {data.data.map(c => (
                                <tr key={c.id} className="hover:bg-surface-50/60">
                                    <td className="px-3 py-2.5 text-2xs text-surface-600 whitespace-nowrap">{fmtDate(c.earned_on)}</td>
                                    <td className="px-3 py-2.5 font-medium text-surface-900">{c.user.name}</td>
                                    <td className="px-3 py-2.5">
                                        {c.order ? <Link to={`/sales/orders/${c.order.id}`} className="text-brand-700 hover:underline">{c.transaction_ref}</Link> : c.transaction_ref}
                                        {c.note && <div className="text-2xs text-surface-500 truncate max-w-[220px]">{c.note}</div>}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums font-semibold">{kes(c.amount)}</td>
                                    <td className="px-3 py-2.5"><StatusChip c={c} /></td>
                                    <td className="px-3 py-2.5 text-2xs text-surface-500">
                                        Recorded by {c.recorded_by ?? "—"}{c.approved_by && <><br />Approved by {c.approved_by}</>}
                                    </td>
                                    <td className="px-2 py-2.5 text-right"><ActionsMenu items={menu(c)} label={`Actions for ${c.transaction_ref}`} /></td>
                                </tr>
                            ))}
                            {data.data.length === 0 && <tr><td colSpan={7} className="px-3 py-8 text-center text-surface-500">No commissions here yet.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>

            {recording && <RecordCommissionDialog onClose={() => setRecording(false)} />}
            {voiding && <VoidDialog c={voiding} onClose={() => setVoiding(null)} onDone={refresh} />}
        </div>
    );
}

function StatusChip({ c }: { c: Commission }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <span className={clsx("inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold", COMMISSION_TONE[c.status])}>{COMMISSION_LABEL[c.status]}</span>
            {c.status === "paid" && c.paid_on && <span className="text-2xs text-surface-500">{fmtDate(c.paid_on)}{c.run ? ` · ${c.run}` : ""}</span>}
            {c.status === "approved" && c.run && <span className="text-2xs text-surface-500">on {c.run}</span>}
            {c.status === "void" && c.void_reason && <span className="text-2xs text-surface-500">{c.void_reason}</span>}
        </div>
    );
}

function Stat({ label, value, tone }: { label: string; value: number; tone: "warning" | "info" | "success" }) {
    return (
        <div className="card"><div className="card-body !py-3">
            <p className="text-2xs text-surface-500">{label}</p>
            <p className={clsx("text-lg font-bold tabular-nums", tone === "warning" ? "text-warning-dark" : tone === "info" ? "text-info" : "text-success-dark")}>KES {kes(value)}</p>
        </div></div>
    );
}

/** Record a commission — from the Commissions tab, or a person's ⋯ menu in People & pay. */
export function RecordCommissionDialog({ person, onClose }: { person?: { id: number; name: string }; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [f, setF] = useState({ user_id: person ? String(person.id) : "", transaction_ref: "", amount: "", earned_on: new Date().toLocaleDateString("en-CA"), note: "" });
    const save = useMutation({
        mutationFn: () => commissionsApi.record({ user_id: Number(f.user_id), transaction_ref: f.transaction_ref.trim(), amount: Number(f.amount), earned_on: f.earned_on, note: f.note.trim() || undefined }),
        onSuccess: r => { toast.success(r.message); void qc.invalidateQueries({ queryKey: ["commissions"] }); void qc.invalidateQueries({ queryKey: ["payroll-people"] }); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const ok = !!f.user_id && f.transaction_ref.trim().length >= 2 && Number(f.amount) > 0 && !!f.earned_on;

    return (
        <Modal open onClose={onClose} size="sm" title={person ? `Record commission · ${person.name}` : "Record commission"}
            footer={<button onClick={() => save.mutate()} disabled={!ok || save.isPending} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Record</button>}>
            <div className="space-y-3">
                {!person && <div className="text-xs text-surface-500">Staff member<PersonPicker value={f.user_id} onChange={id => setF(s => ({ ...s, user_id: id }))} /></div>}
                <label className="block text-xs text-surface-500">Transaction ID
                    <input className="input mt-1" maxLength={80} value={f.transaction_ref} onChange={e => setF(s => ({ ...s, transaction_ref: e.target.value }))} placeholder="Order number, M-Pesa code or receipt" />
                </label>
                <div className="grid grid-cols-2 gap-2">
                    <label className="block text-xs text-surface-500">Commission (KES)
                        <input className="input mt-1" inputMode="decimal" value={f.amount} onChange={e => setF(s => ({ ...s, amount: e.target.value.replace(/[^\d.]/g, "") }))} placeholder="0" />
                    </label>
                    <label className="block text-xs text-surface-500">Date earned
                        <input type="date" className="input mt-1" max={new Date().toLocaleDateString("en-CA")} value={f.earned_on} onChange={e => setF(s => ({ ...s, earned_on: e.target.value }))} />
                    </label>
                </div>
                <label className="block text-xs text-surface-500">Note (optional)
                    <input className="input mt-1" maxLength={500} value={f.note} onChange={e => setF(s => ({ ...s, note: e.target.value }))} />
                </label>
                <p className="text-2xs text-surface-500">A super admin approves it; it is then paid on top of salary on the next pay run.</p>
            </div>
        </Modal>
    );
}

function VoidDialog({ c, onClose, onDone }: { c: Commission; onClose: () => void; onDone: () => void }) {
    const toast = useToastStore();
    const [reason, setReason] = useState("");
    const save = useMutation({
        mutationFn: () => commissionsApi.void(c.id, reason.trim()),
        onSuccess: r => { toast.success(r.message); onDone(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    return (
        <Modal open onClose={onClose} size="sm" title={`Void commission · ${c.transaction_ref}`}
            footer={<button onClick={() => save.mutate()} disabled={reason.trim().length < 3 || save.isPending} className="btn-danger btn-sm">Void</button>}>
            <label className="block text-xs text-surface-500">Reason (kept in the audit log)
                <input className="input mt-1" maxLength={500} value={reason} onChange={e => setReason(e.target.value)} placeholder="e.g. order cancelled" />
            </label>
        </Modal>
    );
}

const fmtDate = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
