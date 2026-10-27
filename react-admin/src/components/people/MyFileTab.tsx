import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { peopleApi } from "@/api/people";
import { DocumentsCard } from "@/components/people/DocumentsCard";
import { TrainingCard } from "@/components/people/TrainingSigningCards";
import { SensitiveCard } from "@/components/people/SensitiveCard";
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
    const [contact, setContact] = useState({ emergency_contact_name: "", emergency_contact_phone: "", emergency_contact_relationship: "", date_of_birth: "" });
    useEffect(() => {
        if (person) setContact({
            emergency_contact_name: person.profile.emergency_contact_name ?? "", emergency_contact_phone: person.profile.emergency_contact_phone ?? "",
            emergency_contact_relationship: person.profile.emergency_contact_relationship ?? "", date_of_birth: person.profile.date_of_birth ?? "",
        });
    }, [person]);
    const save = useMutation({
        mutationFn: () => peopleApi.updateMyFile({ ...contact, date_of_birth: contact.date_of_birth || null }),
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

            <div className="card">
                <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Emergency contact</h3></div>
                <div className="card-body">
                    <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <label className="text-xs text-surface-500">Name<input className="input mt-1" value={contact.emergency_contact_name} onChange={e => setContact({ ...contact, emergency_contact_name: e.target.value })} maxLength={120} /></label>
                        <label className="text-xs text-surface-500">Phone<input className="input mt-1" value={contact.emergency_contact_phone} onChange={e => setContact({ ...contact, emergency_contact_phone: e.target.value })} maxLength={32} /></label>
                        <label className="text-xs text-surface-500">Relationship<input className="input mt-1" value={contact.emergency_contact_relationship} onChange={e => setContact({ ...contact, emergency_contact_relationship: e.target.value })} maxLength={60} /></label>
                        <label className="text-xs text-surface-500">Date of birth<input type="date" className="input mt-1" value={contact.date_of_birth} onChange={e => setContact({ ...contact, date_of_birth: e.target.value })} /></label>
                    </div>
                    <div className="mt-3 flex justify-end">
                        <button onClick={() => save.mutate()} disabled={save.isPending} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save</button>
                    </div>
                </div>
            </div>

            <SensitiveCard masked={person.masked} canView canEdit={false} fetchFull={async () => (await peopleApi.mySensitive()).sensitive} />

            <TrainingCard courses={person.courses ?? []} self />

            <DocumentsCard title="My documents" documents={person.documents} fetchBlob={docId => peopleApi.fetchMyDocumentBlob(docId)} />
        </div>
    );
}
