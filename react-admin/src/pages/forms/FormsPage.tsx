import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { formsApi, STATE_LABEL, type FormTemplate, type SubmissionBrief, type TodayRow } from "@/api/forms";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { SignaturePad } from "@/components/people/SignaturePad";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * /forms — a staff member's checklists and reports (People C4): what is due
 * today at my outlet, done or missed, and the report forms to send any
 * time. Filling one is a modal: ticks, numbers, text, photos, a signature.
 */
const hhmm = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }) : "");

export default function FormsPage() {
    const [params] = useSearchParams();
    const [date] = useState(params.get("date") ?? "");
    const [filling, setFilling] = useState<TodayRow | null>(null);
    const today = useQuery({ queryKey: ["forms-today", date], queryFn: () => formsApi.today(date || undefined), refetchInterval: 120_000 });
    const mine = useQuery({ queryKey: ["forms-mine"], queryFn: () => formsApi.mine(), staleTime: 60_000 });
    const rows = today.data?.data ?? [];
    const checks = rows.filter(r => r.template.kind === "checklist");
    const reports = rows.filter(r => r.template.kind === "report");
    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4 animate-fade-in">
            <div className="flex items-end justify-between gap-3">
                <div><h1 className="page-title">Checklists & forms</h1><p className="page-subtitle">{today.data ? new Date(today.data.date).toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" }) : ""}</p></div>
                {today.data?.can_manage && <Link to="/people/forms" className="text-xs text-brand-600">Manager view ›</Link>}
            </div>
            {today.isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : (
                <>
                    <section className="space-y-2">
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-surface-400">Today's checklists</h2>
                        {checks.length === 0 && <p className="text-sm text-surface-500">No checklist due today.</p>}
                        {checks.map(r => (
                            <button key={`${r.template.id}-${r.outlet.id}`} type="button" disabled={r.state === "submitted" || r.state === "late"} onClick={() => setFilling(r)}
                                className={clsx("w-full text-left rounded-2xl border p-4 flex items-center gap-3", r.state === "submitted" || r.state === "late" ? "bg-success-light/40 border-success/30" : r.state === "missed" || r.state === "overdue" ? "bg-danger/5 border-danger/30" : "bg-white border-line")}>
                                <span className={clsx("w-9 h-9 rounded-full flex items-center justify-center text-lg shrink-0", r.state === "submitted" || r.state === "late" ? "bg-success text-white" : "bg-surface-100")}>{r.state === "submitted" || r.state === "late" ? "✓" : "☐"}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block font-semibold text-surface-900">{r.template.name}</span>
                                    <span className="block text-xs text-surface-500">{r.outlet.name}{r.due_at ? ` · due ${hhmm(r.due_at)}` : ""}{r.submission ? ` · ${r.state === "late" ? "done late" : "done"} by ${r.submission.by ?? "—"} at ${hhmm(r.submission.at)}` : r.state === "missed" ? " · missed — do it now" : r.state === "overdue" ? " · overdue" : ""}{r.submission?.skipped_required ? ` · ${r.submission.skipped_required} skipped` : ""}</span>
                                </span>
                                {!(r.state === "submitted" || r.state === "late") && <span className="text-xs font-semibold text-brand-600">Fill in ›</span>}
                            </button>
                        ))}
                    </section>
                    <section className="space-y-2">
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-surface-400">Report something</h2>
                        <div className="grid grid-cols-2 gap-2">
                            {reports.map(r => (
                                <button key={`${r.template.id}-${r.outlet.id}`} type="button" onClick={() => setFilling(r)} className="rounded-2xl border border-line bg-white p-4 text-left">
                                    <span className="block font-semibold text-surface-900">{r.template.name}</span>
                                    <span className="block text-xs text-surface-500">{r.outlet.name}{r.reports_today ? ` · ${r.reports_today} today` : ""}</span>
                                </button>
                            ))}
                        </div>
                    </section>
                    {(mine.data?.data.length ?? 0) > 0 && (
                        <section className="space-y-2">
                            <h2 className="text-xs font-semibold uppercase tracking-wider text-surface-400">Yours lately</h2>
                            <ul className="rounded-2xl bg-white border border-line divide-y divide-line text-sm">
                                {mine.data!.data.slice(0, 8).map(s => <li key={s.id} className="px-4 py-2 flex items-center justify-between"><span>{s.template?.name} <span className="text-surface-400">· {s.for_date}</span></span><Tag s={s} /></li>)}
                            </ul>
                        </section>
                    )}
                </>
            )}
            {filling && <FillModal row={filling} onClose={() => setFilling(null)} />}
        </div>
    );
}

function Tag({ s }: { s: SubmissionBrief }) {
    return <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", s.status === "late" ? "bg-warning-light text-warning" : s.status === "missed" ? "bg-danger/10 text-danger" : "bg-success-light text-success")}>{STATE_LABEL[s.status]}{s.skipped_required ? ` · ${s.skipped_required} skipped` : ""}</span>;
}

export function FillModal({ row, onClose, onDone }: { row: TodayRow; onClose: () => void; onDone?: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const t: FormTemplate = row.template;
    const [answers, setAnswers] = useState<Record<string, unknown>>({});
    const [photos, setPhotos] = useState<Record<string, File>>({});
    const [notes, setNotes] = useState("");
    const [signature, setSignature] = useState<string | null>(null);
    const save = useMutation({
        mutationFn: () => formsApi.submit({ template_id: t.id, outlet_id: row.outlet.id, answers, notes, signature, photos }),
        onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["forms-today"] }); qc.invalidateQueries({ queryKey: ["forms-mine"] }); onDone?.(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const set = (k: string, v: unknown) => setAnswers(a => ({ ...a, [k]: v }));
    const missing = t.items.filter(i => i.required && (i.type === "photo" ? !photos[i.key] : i.type === "check" ? answers[i.key] !== true : answers[i.key] === undefined || answers[i.key] === "" || answers[i.key] === null));
    return (
        <Modal open onClose={onClose} title={t.name} footer={<div className="flex items-center justify-between gap-2 w-full"><span className="text-xs text-surface-500">{missing.length ? `${missing.length} required item${missing.length > 1 ? "s" : ""} blank — your manager will see that.` : "All required items done."}</span><button onClick={() => save.mutate()} disabled={save.isPending || (t.requires_signature && !signature)} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}{t.kind === "report" ? "Send" : "Done"}</button></div>}>
            <div className="space-y-3 text-sm">
                <p className="text-xs text-surface-500">{row.outlet.name}{row.due_at ? ` · due ${hhmm(row.due_at)}` : ""}</p>
                {t.items.map(i => (
                    <div key={i.key} className="rounded-xl border border-line p-3">
                        {i.type === "check" && <label className="flex items-center gap-3"><input type="checkbox" className="w-5 h-5" checked={answers[i.key] === true} onChange={e => set(i.key, e.target.checked)} /><span className="font-medium text-surface-900">{i.label}{i.required && <span className="text-danger"> *</span>}</span></label>}
                        {i.type === "number" && <label className="block"><span className="font-medium text-surface-900">{i.label}{i.required && <span className="text-danger"> *</span>}</span><input type="number" inputMode="decimal" step="any" className="input mt-1 w-full" value={(answers[i.key] as string) ?? ""} onChange={e => set(i.key, e.target.value)} /></label>}
                        {i.type === "text" && <label className="block"><span className="font-medium text-surface-900">{i.label}{i.required && <span className="text-danger"> *</span>}</span><textarea rows={3} className="input mt-1 w-full" value={(answers[i.key] as string) ?? ""} onChange={e => set(i.key, e.target.value)} maxLength={2000} /></label>}
                        {i.type === "photo" && <label className="block"><span className="font-medium text-surface-900">{i.label}{i.required && <span className="text-danger"> *</span>}</span><input type="file" accept="image/*" capture="environment" className="block mt-1 text-xs" onChange={e => { const f = e.target.files?.[0]; setPhotos(p => { const n = { ...p }; if (f) n[i.key] = f; else delete n[i.key]; return n; }); }} />{photos[i.key] && <span className="text-xs text-success">📷 {photos[i.key].name}</span>}</label>}
                    </div>
                ))}
                <label className="block"><span className="text-xs text-surface-500">Notes (optional)</span><textarea rows={2} className="input mt-1 w-full" value={notes} onChange={e => setNotes(e.target.value)} maxLength={2000} /></label>
                {t.requires_signature && <div><p className="font-medium text-surface-900 mb-1">Signature <span className="text-danger">*</span></p><SignaturePad onChange={setSignature} /></div>}
            </div>
        </Modal>
    );
}
