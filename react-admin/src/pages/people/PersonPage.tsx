import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { peopleApi, type EmployeeProfileFields, type PersonFile } from "@/api/people";
import { DocumentsCard } from "@/components/people/DocumentsCard";
import { LeaveCard } from "@/components/people/LeaveCard";
import { RecognitionsCard } from "@/components/people/RecognitionsCard";
import { SigningCard, TrainingCard } from "@/components/people/TrainingSigningCards";
import { timesheetsApi } from "@/api/timesheets";
import { SensitiveCard } from "@/components/people/SensitiveCard";
import { PayStructureCard } from "@/components/people/PayStructureCard";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** One person's employee file (People PR 2). */

type Draft = Omit<EmployeeProfileFields, "updated_at">;
const blank = (p: EmployeeProfileFields): Draft => {
    const { updated_at: _u, ...rest } = p;
    return rest;
};

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">{label}</dt>
            <dd className="mt-0.5 text-sm text-surface-900">{value ?? <span className="text-surface-300">—</span>}</dd>
        </div>
    );
}

export default function PersonPage() {
    const { id } = useParams();
    const personId = Number(id);
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["person", personId], queryFn: () => peopleApi.show(personId), enabled: Number.isFinite(personId) });
    const { data: payGroups } = useQuery({ queryKey: ["pay-groups"], queryFn: () => timesheetsApi.groups() });
    const person: PersonFile | undefined = data?.person;

    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<Draft | null>(null);
    useEffect(() => { if (person && !editing) setDraft(blank(person.profile)); }, [person, editing]);

    const save = useMutation({
        mutationFn: (d: Draft) => peopleApi.update(personId, d),
        onSuccess: r => { qc.setQueryData(["person", personId], r); qc.invalidateQueries({ queryKey: ["people"] }); setEditing(false); toast.success(r.message); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const refresh = () => qc.invalidateQueries({ queryKey: ["person", personId] });

    if (isLoading || !person || !draft) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const p = person.profile;
    const set = <K extends keyof Draft>(k: K, v: Draft[K]) => setDraft(d => (d ? { ...d, [k]: v } : d));
    const initials = person.name.split(" ").map(s => s[0]).filter(Boolean).slice(0, 2).join("").toUpperCase();

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex items-center gap-3">
                <Link to="/people" className="btn-ghost btn-sm text-xs">← People</Link>
            </div>

            <div className="card">
                <div className="card-body flex flex-col sm:flex-row sm:items-center gap-4">
                    {person.avatar_url
                        ? <img src={person.avatar_url} alt="" className="w-16 h-16 rounded-full object-cover" />
                        : <div className="w-16 h-16 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-lg font-bold">{initials}</div>}
                    <div className="min-w-0 flex-1">
                        <h1 className="text-xl font-bold text-surface-900">{person.name}</h1>
                        <p className="text-sm text-surface-500">
                            {p.job_title ?? "No job title yet"}{p.department ? ` · ${p.department}` : ""}
                            {person.outlets.length ? ` · ${person.outlets.map(o => o.name).join(", ")}` : ""}
                        </p>
                        <p className="text-xs text-surface-400 mt-0.5">
                            {person.email}{person.phone ? ` · ${person.phone}` : ""}
                            {person.reports_to ? ` · reports to ${person.reports_to.name}` : ""}
                            {person.roles.length ? ` · ${person.roles.join(", ")}` : ""}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link to={`/settings/attendance`} className="btn-secondary btn-sm">Attendance</Link>
                        {person.can.edit && !editing && <button onClick={() => setEditing(true)} className="btn-primary btn-sm">Edit file</button>}
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
                <div className="card">
                    <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Employment</h3></div>
                    <div className="card-body">
                        {editing ? (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label className="text-xs text-surface-500">Job title<input className="input mt-1" value={draft.job_title ?? ""} onChange={e => set("job_title", e.target.value)} maxLength={120} /></label>
                                <label className="text-xs text-surface-500">Department<input className="input mt-1" value={draft.department ?? ""} onChange={e => set("department", e.target.value)} maxLength={80} placeholder="Production, Sales, Finance…" /></label>
                                <label className="text-xs text-surface-500">Employment type
                                    <select className="input mt-1" value={draft.employment_type} onChange={e => set("employment_type", e.target.value as Draft["employment_type"])}>
                                        <option value="permanent">Permanent</option><option value="contract">Contract</option><option value="casual">Casual</option><option value="intern">Intern</option>
                                    </select>
                                </label>
                                <label className="text-xs text-surface-500">Pay group
                                    <select className="input mt-1" value={draft.pay_group_id ?? ""} onChange={e => set("pay_group_id", e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">Default group</option>
                                        {(payGroups?.data ?? []).map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                                    </select>
                                </label>
                                <label className="text-xs text-surface-500">Pay method
                                    <select className="input mt-1" value={draft.pay_method ?? ""} onChange={e => set("pay_method", (e.target.value || null) as Draft["pay_method"])}>
                                        <option value="">—</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option><option value="cash">Cash</option>
                                    </select>
                                </label>
                                <label className="text-xs text-surface-500">Hire date<input type="date" className="input mt-1" value={draft.hire_date ?? ""} onChange={e => set("hire_date", e.target.value || null)} /></label>
                                <label className="text-xs text-surface-500">End date<input type="date" className="input mt-1" value={draft.end_date ?? ""} onChange={e => set("end_date", e.target.value || null)} /></label>
                                <label className="text-xs text-surface-500">Date of birth<input type="date" className="input mt-1" value={draft.date_of_birth ?? ""} onChange={e => set("date_of_birth", e.target.value || null)} /></label>
                                <label className="text-xs text-surface-500">Bank name<input className="input mt-1" value={draft.bank_name ?? ""} onChange={e => set("bank_name", e.target.value)} maxLength={80} /></label>
                                <label className="text-xs text-surface-500">Bank branch<input className="input mt-1" value={draft.bank_branch ?? ""} onChange={e => set("bank_branch", e.target.value)} maxLength={80} /></label>
                                <label className="text-xs text-surface-500">Account name<input className="input mt-1" value={draft.bank_account_name ?? ""} onChange={e => set("bank_account_name", e.target.value)} maxLength={120} /></label>
                                <label className="text-xs text-surface-500 sm:col-span-2">Notes<textarea className="input mt-1" rows={3} value={draft.notes ?? ""} onChange={e => set("notes", e.target.value)} maxLength={2000} /></label>
                                <div className="sm:col-span-2 flex justify-end gap-2">
                                    <button onClick={() => { setEditing(false); setDraft(blank(p)); }} className="btn-secondary btn-sm">Cancel</button>
                                    <button onClick={() => save.mutate(draft)} disabled={save.isPending} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save</button>
                                </div>
                            </div>
                        ) : (
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-3">
                                <Row label="Job title" value={p.job_title} />
                                <Row label="Department" value={p.department} />
                                <Row label="Type" value={{ permanent: "Permanent", contract: "Contract", casual: "Casual", intern: "Intern" }[p.employment_type]} />
                                <Row label="Pay method" value={p.pay_method ? { mpesa: "M-Pesa", bank: "Bank", cash: "Cash" }[p.pay_method] : null} />
                                <Row label="Pay group" value={payGroups?.data.find(g => g.id === p.pay_group_id)?.name ?? "Default group"} />
                                <Row label="Hire date" value={p.hire_date} />
                                <Row label="End date" value={p.end_date} />
                                <Row label="Date of birth" value={p.date_of_birth} />
                                <Row label="Bank" value={p.bank_name ? `${p.bank_name}${p.bank_branch ? `, ${p.bank_branch}` : ""}${p.bank_account_name ? ` · ${p.bank_account_name}` : ""}` : null} />
                                {p.notes && <div className="col-span-2"><Row label="Notes" value={<span className="whitespace-pre-wrap">{p.notes}</span>} /></div>}
                            </dl>
                        )}
                    </div>
                </div>

                <div className="space-y-5">
                    <div className="card">
                        <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Emergency contact</h3></div>
                        <div className="card-body">
                            {editing ? (
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <label className="text-xs text-surface-500">Name<input className="input mt-1" value={draft.emergency_contact_name ?? ""} onChange={e => set("emergency_contact_name", e.target.value)} maxLength={120} /></label>
                                    <label className="text-xs text-surface-500">Phone<input className="input mt-1" value={draft.emergency_contact_phone ?? ""} onChange={e => set("emergency_contact_phone", e.target.value)} maxLength={32} /></label>
                                    <label className="text-xs text-surface-500">Relationship<input className="input mt-1" value={draft.emergency_contact_relationship ?? ""} onChange={e => set("emergency_contact_relationship", e.target.value)} maxLength={60} /></label>
                                </div>
                            ) : (
                                <dl className="grid grid-cols-3 gap-x-6 gap-y-3">
                                    <Row label="Name" value={p.emergency_contact_name} />
                                    <Row label="Phone" value={p.emergency_contact_phone} />
                                    <Row label="Relationship" value={p.emergency_contact_relationship} />
                                </dl>
                            )}
                        </div>
                    </div>

                    <SensitiveCard
                        masked={person.masked}
                        canView={person.can.view_sensitive}
                        canEdit={person.can.edit_sensitive}
                        fetchFull={async () => (await peopleApi.sensitive(personId)).sensitive}
                        save={person.can.edit_sensitive ? async d => { const r = await peopleApi.updateSensitive(personId, d); void refresh(); return r.sensitive; } : undefined}
                    />

                    <PayStructureCard userId={personId} canView={person.can.view_sensitive} />
                </div>
            </div>

            <LeaveCard userId={personId} />

            <RecognitionsCard recognitions={person.recognitions ?? []} points={person.recognition_points ?? 0} />

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <TrainingCard courses={person.courses ?? []} />
                <SigningCard userId={personId} signing={person.signing ?? []} documents={person.documents} canAsk={person.can.edit} onChanged={() => void refresh()} />
            </div>

            <DocumentsCard
                documents={person.documents}
                fetchBlob={docId => peopleApi.fetchDocumentBlob(personId, docId)}
                onUpload={person.can.edit ? async (file, kind, title, expiresAt) => { const r = await peopleApi.addDocument(personId, file, kind, title, expiresAt); toast.success(r.message); await refresh(); } : undefined}
                onRemove={person.can.edit ? async d => { try { const r = await peopleApi.removeDocument(personId, d.id); toast.success(r.message); await refresh(); } catch (e) { toast.error((e as ApiError)?.message ?? "Could not remove it."); } } : undefined}
            />
        </div>
    );
}
