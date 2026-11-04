import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { payrollApi, kes, KIND_LABEL, type PayPerson } from "@/api/payroll";
import { Modal } from "@/components/ui/Modal";
import { type IconName } from "@/components/people/fileKit";
import { ActionsMenu, type ActionItem } from "@/components/ui/ActionsMenu";
import { RecordCommissionDialog } from "@/components/people/CommissionsPanel";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Payroll › People & pay (owner, 2026-11-03): everyone on payroll on one
 * screen — salary in force, whether government deductions apply, what they
 * owe on advances, and when they were last paid.
 *
 * Government deductions (PAYE, NSSF, SHIF/SHA, Housing Levy) are one switch
 * per person, OFF by default because staff are on contracts. "Add government
 * deductions" when someone becomes permanent; "Stop" if they go back to a
 * contract. Each change starts from a date, so earlier payslips keep what
 * applied then.
 */
export function PeopleAndPay() {
    const { data, isLoading } = useQuery({ queryKey: ["payroll-people"], queryFn: payrollApi.people });
    const [q, setQ] = useState("");
    const [acting, setActing] = useState<PayPerson | null>(null);
    const [commissionFor, setCommissionFor] = useState<PayPerson | null>(null);

    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const rows = data.data.filter(p => !q.trim() || p.name.toLowerCase().includes(q.trim().toLowerCase()));
    const enrolled = data.data.filter(p => p.deductions.applies).length;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-surface-600 max-w-2xl">
                    Government deductions — PAYE, NSSF, SHIF (SHA) and the Housing Levy — apply only to people accounts add them for.
                    <span className="ml-1 font-semibold text-surface-800">{enrolled} of {data.data.length}</span> currently have them.
                </p>
                <input className="input input-sm max-w-[220px]" placeholder="Search staff" value={q} onChange={e => setQ(e.target.value)} aria-label="Search staff" />
            </div>

            {/* Phones: one card per person. */}
            <div className="space-y-2 md:hidden">
                {rows.map(p => (
                    <div key={p.id} className="card"><div className="card-body space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <Link to={`/people/${p.id}`} className="font-semibold text-surface-900">{p.name}</Link>
                            <div className="flex items-center gap-1">
                                <span className="text-sm tabular-nums">{p.salary ? `KES ${kes(p.salary.base_amount)}` : <span className="text-2xs text-warning-dark">No salary set</span>}</span>
                                <RowMenu p={p} canEdit={data.can_edit} onDeductions={setActing} onCommission={setCommissionFor} />
                            </div>
                        </div>
                        <Deductions p={p} />
                        <p className="text-2xs text-surface-500">
                            {p.last_paid ? <>Last paid KES {kes(p.last_paid.net)} · {fmtDate(p.last_paid.paid_on)}</> : "Not paid yet"}
                            {p.advances_owed > 0 && <> · owes KES {kes(p.advances_owed)}</>}
                            {p.commission.owed > 0 && <> · commission due KES {kes(p.commission.owed)}</>}
                        </p>
                    </div></div>
                ))}
            </div>

            <div className="card overflow-hidden hidden md:block">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-surface-50 text-2xs uppercase tracking-wider text-surface-500">
                            <tr>
                                <th className="px-3 py-2 text-left">Staff</th>
                                <th className="px-3 py-2 text-left">Salary</th>
                                <th className="px-3 py-2 text-left">Government deductions</th>
                                <th className="px-3 py-2 text-right">Commission owed</th>
                                <th className="px-3 py-2 text-right">Advances owed</th>
                                <th className="px-3 py-2 text-left">Last paid</th>
                                <th className="px-2 py-2"><span className="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {rows.map(p => (
                                <tr key={p.id} className="hover:bg-surface-50/60">
                                    <td className="px-3 py-2.5">
                                        <Link to={`/people/${p.id}`} className="font-semibold text-surface-900 hover:text-brand-700">{p.name}</Link>
                                    </td>
                                    <td className="px-3 py-2.5 tabular-nums">
                                        {p.salary
                                            ? <><span className="font-medium">KES {kes(p.salary.base_amount)}</span> <span className="text-2xs text-surface-500">{KIND_LABEL[p.salary.kind].toLowerCase()} · from {fmtDate(p.salary.effective_from)}</span></>
                                            : <Link to={`/people/${p.id}`} className="text-2xs text-warning-dark hover:underline">No salary set — set it on their file</Link>}
                                    </td>
                                    <td className="px-3 py-2.5"><Deductions p={p} /></td>
                                    <td className="px-3 py-2.5 text-right tabular-nums">
                                        {p.commission.owed > 0 ? kes(p.commission.owed) : <span className="text-surface-400">—</span>}
                                        {p.commission.pending > 0 && <div className="text-2xs text-warning-dark">+{kes(p.commission.pending)} awaiting approval</div>}
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums">{p.advances_owed > 0 ? kes(p.advances_owed) : <span className="text-surface-400">—</span>}</td>
                                    <td className="px-3 py-2.5 text-2xs text-surface-600">
                                        {p.last_paid
                                            ? <><span className="font-semibold text-surface-800 tabular-nums">KES {kes(p.last_paid.net)}</span> · {fmtDate(p.last_paid.paid_on)}{p.last_paid.method ? ` · ${p.last_paid.method}` : ""}{p.last_paid.run ? ` · ${p.last_paid.run}` : ""}</>
                                            : <span className="text-surface-400">Not paid yet</span>}
                                    </td>
                                    <td className="px-2 py-2.5 text-right"><RowMenu p={p} canEdit={data.can_edit} onDeductions={setActing} onCommission={setCommissionFor} /></td>
                                </tr>
                            ))}
                            {rows.length === 0 && <tr><td colSpan={7} className="px-3 py-8 text-center text-surface-500">No staff match.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>

            {acting && <DeductionsDialog person={acting} onClose={() => setActing(null)} />}
            {commissionFor && <RecordCommissionDialog person={{ id: commissionFor.id, name: commissionFor.name }} onClose={() => setCommissionFor(null)} />}
        </div>
    );
}

function Deductions({ p }: { p: PayPerson }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className={clsx("inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold",
                p.deductions.applies ? "bg-success-light text-success-dark" : "bg-surface-100 text-surface-600")}>
                {p.deductions.applies ? `Deductions applied${p.deductions.since ? ` since ${fmtDate(p.deductions.since)}` : ""}` : "No deductions · contract"}
            </span>
            {p.deductions.next && (
                <span className="text-2xs text-info">{p.deductions.next.applies ? "Start" : "Stop"} {fmtDate(p.deductions.next.from)}</span>
            )}
        </div>
    );
}

/** The row's ⋯ menu: deductions, salary, commission, the employee file. */
function RowMenu({ p, canEdit, onDeductions, onCommission }: { p: PayPerson; canEdit: boolean; onDeductions: (p: PayPerson) => void; onCommission: (p: PayPerson) => void }) {
    const navigate = useNavigate();
    const items: ActionItem[] = [
        ...(canEdit ? [p.deductions.applies
            ? { label: "Stop government deductions", icon: "x" as IconName, run: () => onDeductions(p), tone: "danger" as const }
            : { label: "Add government deductions", icon: "plus" as IconName, run: () => onDeductions(p) }] : []),
        ...(canEdit ? [{ label: "Record commission", icon: "star" as IconName, run: () => onCommission(p) }] : []),
        { label: p.salary ? "Change salary" : "Set salary", icon: "wallet", run: () => navigate(`/people/${p.id}#pay`) },
        { label: "Open employee file", icon: "user", run: () => navigate(`/people/${p.id}`) },
    ];
    return <ActionsMenu items={items} label={`Actions for ${p.name}`} />;
}

function DeductionsDialog({ person, onClose }: { person: PayPerson; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const adding = !person.deductions.applies;
    const firstOfNextMonth = (() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth() + 1, 1).toLocaleDateString("en-CA"); })();
    const [from, setFrom] = useState(firstOfNextMonth);
    const [reason, setReason] = useState(adding ? "Made permanent" : "Back on contract");
    const save = useMutation({
        mutationFn: () => payrollApi.setDeductions(person.id, { applies: adding, effective_from: from, reason: reason.trim() }),
        onSuccess: r => { toast.success(r.message); void qc.invalidateQueries({ queryKey: ["payroll-people"] }); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open onClose={onClose} size="sm"
            title={adding ? `Add government deductions · ${person.name}` : `Stop government deductions · ${person.name}`}
            footer={<button onClick={() => save.mutate()} disabled={save.isPending || reason.trim().length < 3 || !from} className={adding ? "btn-primary btn-sm" : "btn-danger btn-sm"}>
                {save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}{adding ? "Add all four" : "Stop all four"}
            </button>}>
            <div className="space-y-3 text-sm">
                <p className="text-surface-600">
                    {adding
                        ? <>PAYE, NSSF, SHIF (SHA) and the Housing Levy will be deducted on every pay run whose period ends on or after this date, and the employer's NSSF and Housing Levy added to the labour cost.</>
                        : <>No PAYE, NSSF, SHIF (SHA) or Housing Levy on pay runs whose period ends on or after this date. Kenyan law requires these for employees; stop them only for someone on a contract.</>}
                </p>
                <label className="block text-xs text-surface-500">From
                    <input type="date" className="input mt-1" value={from} onChange={e => setFrom(e.target.value)} />
                </label>
                <label className="block text-xs text-surface-500">Reason (kept in the audit log)
                    <input className="input mt-1" maxLength={500} value={reason} onChange={e => setReason(e.target.value)} />
                </label>
                <p className="text-2xs text-surface-500">Payslips already approved keep what applied to them. You will be asked to confirm it is you.</p>
            </div>
        </Modal>
    );
}

const fmtDate = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
