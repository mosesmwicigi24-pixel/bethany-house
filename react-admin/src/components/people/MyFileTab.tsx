import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { peopleApi } from "@/api/people";
import { DocumentsCard } from "@/components/people/DocumentsCard";
import { TrainingCard } from "@/components/people/TrainingSigningCards";
import { SensitiveCard } from "@/components/people/SensitiveCard";
import { EmergencyContactCard } from "@/components/people/EmergencyContactCard";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * "My employment" on the profile page (People PR 2): what the business holds
 * about me. I keep my own emergency contact and date of birth; the rest is the
 * file keeper's. My identifiers show masked until I confirm it is me.
 */
export function MyFileTab() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["my-file"], queryFn: () => peopleApi.myFile() });
    const person = data?.person;
    const [dob, setDob] = useState("");
    useEffect(() => { if (person) setDob(person.profile.date_of_birth ?? ""); }, [person]);
    const save = useMutation({
        mutationFn: () => peopleApi.updateMyFile({ date_of_birth: dob || null }),
        onSuccess: r => { qc.setQueryData(["my-file"], r); toast.success(r.message); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading || !person) return <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div>;
    const p = person.profile;

    return (
        <div className="space-y-4">
            <div className="card">
                <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Employment</h3></div>
                <div className="card-body">
                    <dl className="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-3 text-sm">
                        <div><dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Job title</dt><dd>{p.job_title ?? "—"}</dd></div>
                        <div><dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Department</dt><dd>{p.department ?? "—"}</dd></div>
                        <div><dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Type</dt><dd className="capitalize">{p.employment_type}</dd></div>
                        <div><dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Since</dt><dd>{p.hire_date ?? "—"}</dd></div>
                        <div className="col-span-2"><dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Reports to</dt><dd>{person.reports_to?.name ?? "—"}</dd></div>
                        <div className="col-span-2"><dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Pay method</dt><dd>{p.pay_method ? { mpesa: "M-Pesa", bank: "Bank", cash: "Cash" }[p.pay_method] : "—"}{p.bank_name ? ` · ${p.bank_name}` : ""}</dd></div>
                    </dl>
                    <p className="text-2xs text-surface-400 mt-3">Something wrong here? Ask whoever keeps the employee files; only they can change it.</p>
                </div>
            </div>

            <EmergencyContactCard person={person} canEdit
                save={async body => { const r = await peopleApi.updateMyFile(body); qc.setQueryData(["my-file"], r); return r.person; }} />

            <div className="card">
                <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Date of birth</h3></div>
                <div className="card-body flex flex-col sm:flex-row sm:items-end gap-3">
                    <label className="text-xs text-surface-500 flex-1 max-w-xs">Date of birth<input type="date" className="input mt-1" value={dob} max={new Date().toISOString().slice(0, 10)} onChange={e => setDob(e.target.value)} /></label>
                    <button onClick={() => save.mutate()} disabled={save.isPending || dob === (p.date_of_birth ?? "")} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save</button>
                </div>
            </div>

            <SensitiveCard masked={person.masked} canView canEdit={false} fetchFull={async () => (await peopleApi.mySensitive()).sensitive} />

            <TrainingCard courses={person.courses ?? []} self />

            <DocumentsCard title="My documents" documents={person.documents} fetchBlob={docId => peopleApi.fetchMyDocumentBlob(docId)} />
        </div>
    );
}
