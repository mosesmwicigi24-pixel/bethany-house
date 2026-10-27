import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { automationsApi, type Automation } from "@/api/people-c2";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Setup › Automations (People C2). One row per alert: a switch, the
 * threshold, who hears it. Read with attendance.view_team; save with
 * settings.edit. Each row saves on its own.
 */
const RECIPIENTS: { value: Automation["recipients"]; label: string; hint: string }[] = [
    { value: "managers", label: "The person's managers", hint: "Whoever they report to, and anyone who manages attendance in their outlets." },
    { value: "hr", label: "HR", hint: "Everyone who can edit employee files." },
    { value: "owner", label: "The owner", hint: "Super admins only." },
    { value: "finance", label: "Finance", hint: "Everyone who can read the money reports (the owner included). Needs an email address on their profile for the CSV." },
];

export default function AutomationsPage() {
    const { can } = usePermissions();
    const canEdit = can("settings.edit");
    const { data, isLoading } = useQuery({ queryKey: ["automations"], queryFn: () => automationsApi.list() });
    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    return (
        <div className="space-y-5 animate-fade-in max-w-3xl">
            <div>
                <h1 className="page-title">Automations</h1>
                <p className="page-subtitle">What the Hub tells a manager without being asked. The reminders to the person themselves (clock in, clock out) are on the <Link to="/settings/attendance-rules" className="text-brand-600 underline">attendance rules</Link>.</p>
            </div>
            {!canEdit && <p className="rounded-xl bg-surface-100 text-surface-600 text-xs px-3 py-2">You can read these. Changing them needs the Edit settings permission.</p>}
            <div className="space-y-3">
                {data.automations.map(a => <Row key={a.key} a={a} people={data.people} canEdit={canEdit} />)}
            </div>
            <PointValue value={data.recognition_point_kes} canEdit={canEdit} />
            <p className="text-xs text-surface-500">Alerts arrive as Hub notifications (and push, where it is on). Missed shift and overtime are checked every fifteen minutes; outside the zone at the punch; documents each morning at 07:15.</p>
        </div>
    );
}

function Row({ a, people, canEdit }: { a: Automation; people: { id: number; name: string }[]; canEdit: boolean }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [r, setR] = useState(a);
    const [addId, setAddId] = useState("");
    const save = useMutation({
        mutationFn: (data: Partial<Automation>) => automationsApi.save(a.key, data),
        onSuccess: res => { toast.success(res.message); setR(res.automation); qc.invalidateQueries({ queryKey: ["automations"] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const dirty = r.threshold !== a.threshold || r.recipients !== a.recipients || JSON.stringify(r.extra_user_ids) !== JSON.stringify(a.extra_user_ids);
    const nameOf = (id: number) => people.find(p => p.id === id)?.name ?? `#${id}`;
    return (
        <div className={clsx("card", !r.enabled && "opacity-70")}>
            <div className="card-body space-y-3">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h3 className="font-semibold text-sm text-surface-900">{r.label}</h3>
                        <p className="text-sm text-surface-600">{r.text}</p>
                    </div>
                    <button type="button" role="switch" aria-checked={r.enabled} disabled={!canEdit || save.isPending}
                        onClick={() => { const enabled = !r.enabled; setR({ ...r, enabled }); save.mutate({ enabled }); }}
                        className={clsx("relative shrink-0 w-11 h-6 rounded-full transition", r.enabled ? "bg-brand-600" : "bg-surface-300", !canEdit && "cursor-not-allowed")}>
                        <span className={clsx("absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition", r.enabled ? "left-[22px]" : "left-0.5")} />
                        <span className="sr-only">{r.enabled ? "On" : "Off"}</span>
                    </button>
                </div>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-surface-700">
                    {r.unit && (
                        <label className="inline-flex items-center gap-2">After
                            <input type="number" min={r.min} max={r.max} disabled={!canEdit} value={r.threshold} onChange={e => setR({ ...r, threshold: Number(e.target.value) })} className="input w-20 px-2 py-1 text-sm" />
                            {r.unit}
                        </label>
                    )}
                    {r.has_recipients && <label className="inline-flex items-center gap-2">Tell
                        <select disabled={!canEdit} value={r.recipients} onChange={e => setR({ ...r, recipients: e.target.value as Automation["recipients"] })} className="input px-2 py-1 text-sm" title={RECIPIENTS.find(x => x.value === r.recipients)?.hint}>
                            {RECIPIENTS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </label>}
                    {r.has_recipients && <span className="inline-flex items-center gap-2 flex-wrap">and also
                        {r.extra_user_ids.map(id => (
                            <span key={id} className="inline-flex items-center gap-1 rounded-full bg-surface-100 px-2 py-0.5 text-xs">{nameOf(id)}
                                {canEdit && <button type="button" aria-label={`Remove ${nameOf(id)}`} onClick={() => setR({ ...r, extra_user_ids: r.extra_user_ids.filter(x => x !== id) })} className="text-surface-400 hover:text-danger">✕</button>}
                            </span>
                        ))}
                        {canEdit && (
                            <select value={addId} onChange={e => { const id = Number(e.target.value); if (id && !r.extra_user_ids.includes(id)) setR({ ...r, extra_user_ids: [...r.extra_user_ids, id] }); setAddId(""); }} className="input px-2 py-1 text-xs" aria-label="Add a person">
                                <option value="">+ a person</option>
                                {people.filter(p => !r.extra_user_ids.includes(p.id)).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        )}
                        {!canEdit && r.extra_user_ids.length === 0 && <span className="text-surface-400">nobody else</span>}
                    </span>}
                    {canEdit && dirty && <button type="button" onClick={() => save.mutate({ threshold: r.threshold, recipients: r.recipients, extra_user_ids: r.extra_user_ids })} disabled={save.isPending} className="btn-primary btn-sm ml-auto">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save</button>}
                </div>
            </div>
        </div>
    );
}

/** What a recognition point is worth on the payslip (People C3). 0 = points are just points. */
function PointValue({ value, canEdit }: { value: number; canEdit: boolean }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [kes, setKes] = useState(String(value));
    const save = useMutation({ mutationFn: () => automationsApi.setPointValue(Number(kes)), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["automations"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    return (
        <div className="card"><div className="card-body flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-surface-700">
            <span><b className="text-surface-900">Recognition points.</b> A point on a shout-out is worth KES</span>
            <input type="number" min={0} max={100000} step="0.01" disabled={!canEdit} value={kes} onChange={e => setKes(e.target.value)} className="input w-28 px-2 py-1 text-sm" />
            <span>on the payslip of the period it was given in, as taxable pay. 0 means points are just points.</span>
            {canEdit && Number(kes) !== value && <button onClick={() => save.mutate()} disabled={save.isPending} className="btn-primary btn-sm ml-auto">Save</button>}
        </div></div>
    );
}
