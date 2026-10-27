import { useEffect, useRef, useState } from "react";
import { clsx } from "clsx";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { waNumber } from "@/lib/whatsapp";
import type { EmergencyContactKey, EmployeeProfileFields, PersonFile } from "@/api/people";
import type { ApiError } from "@/types";
import { Avatar, CardHead, Chip, CopyButton, EmptyLine, Icon, WhatsAppIcon } from "./fileKit";
import { PhoneField, formatPhone, phoneProblem, useDialCountries } from "./PhoneField";

/**
 * The emergency contact, edited where it is read (owner, 27 Oct 2026: "make
 * editable emergency contact"). Up to two people — the first to call, and the
 * one to try when the first does not answer. Saving sends only these six
 * fields, so nothing else on the file can be changed from here.
 *
 * Who may edit is the server's answer (person.can.edit_contact): the file
 * keeper (hr.edit, PUT /people/{id}) or the person themselves (PUT /me/file).
 */

export const RELATIONSHIPS = ["Spouse", "Parent", "Sibling", "Child", "Relative", "Friend"] as const;

type Contacts = Pick<EmployeeProfileFields, EmergencyContactKey>;
type Slot = "emergency_contact" | "emergency_contact_2";
const SLOTS: Slot[] = ["emergency_contact", "emergency_contact_2"];

interface Draft { name: string; phone: string; relationship: string; other: string }

function toDraft(p: Contacts, slot: Slot): Draft {
    const rel = p[`${slot}_relationship`] ?? "";
    const known = (RELATIONSHIPS as readonly string[]).includes(rel);
    return { name: p[`${slot}_name`] ?? "", phone: p[`${slot}_phone`] ?? "", relationship: rel ? (known ? rel : "Other") : "", other: known ? "" : rel };
}
const relOf = (d: Draft) => (d.relationship === "Other" ? d.other.trim() || "Other" : d.relationship);
const isBlank = (d: Draft) => !d.name.trim() && !d.phone.trim() && !d.relationship;

export function EmergencyContactCard({ person, canEdit, save, titleId }: {
    person: PersonFile;
    canEdit: boolean;
    /** Sends the six contact fields; resolves with the saved file. */
    save: (data: Contacts) => Promise<PersonFile>;
    titleId?: string;
}) {
    const toast = useToastStore();
    const countries = useDialCountries();
    const p = person.profile;
    const [editing, setEditing] = useState(false);
    const [drafts, setDrafts] = useState<Draft[]>(() => SLOTS.map(s => toDraft(p, s)));
    const [showSecond, setShowSecond] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const firstInput = useRef<HTMLInputElement>(null);

    const saved = SLOTS.map(s => toDraft(p, s)).map((d, i) => ({ ...d, slot: SLOTS[i] })).filter(d => !isBlank(d));

    const start = () => {
        const d = SLOTS.map(s => toDraft(p, s));
        setDrafts(d); setErrors({}); setShowSecond(!isBlank(d[1])); setEditing(true);
    };
    useEffect(() => { if (editing) firstInput.current?.focus(); }, [editing]);

    const set = (i: number, patch: Partial<Draft>) => {
        setDrafts(ds => ds.map((d, k) => (k === i ? { ...d, ...patch } : d)));
        const slot = SLOTS[i];
        setErrors(e => { const n = { ...e }; Object.keys(patch).forEach(k => delete n[`${slot}_${k === "other" ? "relationship" : k}`]); return n; });
    };

    const validate = (ds: Draft[]): Record<string, string> => {
        const e: Record<string, string> = {};
        ds.forEach((d, i) => {
            const slot = SLOTS[i];
            if (i === 1 && !showSecond) return;
            if (d.phone.trim() && !d.name.trim()) e[`${slot}_name`] = "Add a name for this number, so whoever calls knows who will answer.";
            const problem = phoneProblem(d.phone);
            if (problem && d.phone !== (p[`${slot}_phone`] ?? "")) e[`${slot}_phone`] = problem;
            if (d.relationship === "Other" && d.other.trim().length > 60) e[`${slot}_relationship`] = "Keep it under 60 characters.";
        });
        return e;
    };

    const submit = async () => {
        const e = validate(drafts);
        setErrors(e);
        if (Object.keys(e).length) return;
        // A second contact on its own moves up to first.
        let ds = showSecond ? drafts : [drafts[0], { name: "", phone: "", relationship: "", other: "" }];
        if (isBlank(ds[0]) && !isBlank(ds[1])) ds = [ds[1], ds[0]];
        const body = {} as Contacts;
        ds.forEach((d, i) => {
            const slot = SLOTS[i];
            body[`${slot}_name`] = d.name.trim() || null;
            body[`${slot}_phone`] = d.phone.trim() || null;
            body[`${slot}_relationship`] = d.relationship ? relOf(d) : null;
        });
        setSaving(true);
        try {
            await save(body);
            setEditing(false);
            toast.success("Emergency contact saved.");
        } catch (err) {
            const ae = err as ApiError;
            if (ae?.errors) setErrors(Object.fromEntries(Object.entries(ae.errors).map(([k, v]) => [k, v[0]])));
            toast.error(ae?.message ?? "Could not save the contact.");
        } finally { setSaving(false); }
    };

    return (
        <div className="card h-full" aria-labelledby={titleId}>
            <CardHead icon="heart" tone="brand" titleId={titleId} title="Emergency contact"
                subtitle="Who to call if something happens at work."
                actions={canEdit && !editing && saved.length > 0 ? (
                    <button onClick={start} className="btn-secondary btn-sm"><Icon name="edit" className="w-3.5 h-3.5" />Edit</button>
                ) : undefined} />
            <div className="card-body">
                {!editing ? (
                    saved.length === 0 ? (
                        <EmptyLine icon="heart" text={canEdit ? "No emergency contact yet. Add the person to call first — a spouse, a parent, someone who will pick up." : "No emergency contact on file."}
                            action={canEdit ? <button onClick={start} className="btn-primary btn-sm"><Icon name="plus" className="w-3.5 h-3.5" strokeWidth={2.4} />Add a contact</button> : undefined} />
                    ) : (
                        <ul className="space-y-3">
                            {saved.map((c, i) => {
                                const phone = p[`${c.slot}_phone`];
                                const wa = waNumber(phone);
                                return (
                                    <li key={c.slot} className="rounded-2xl border border-line p-4 flex flex-wrap items-center gap-3">
                                        <div className="flex items-center gap-3 min-w-[13rem] flex-1">
                                            <Avatar name={c.name || "?"} size="md" />
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <p className="font-semibold text-surface-900">{c.name || <span className="text-surface-400 font-normal">No name</span>}</p>
                                                    {c.relationship && <Chip tone="neutral" className="!py-0.5">{relOf(c)}</Chip>}
                                                    {saved.length > 1 && <span className="text-2xs font-semibold uppercase tracking-wider text-surface-400">{i === 0 ? "Call first" : "Then"}</span>}
                                                </div>
                                                {phone
                                                    ? <p className="mt-0.5 flex items-center gap-1 text-sm text-surface-600 tabular-nums whitespace-nowrap">{formatPhone(phone, countries)}<CopyButton value={phone} label={`Copy ${c.name || "contact"}'s number`} /></p>
                                                    : <p className="mt-0.5 text-sm text-surface-400">No number yet{canEdit && <> · <button onClick={start} className="font-semibold text-brand-700 hover:underline">Add</button></>}</p>}
                                            </div>
                                        </div>
                                        {phone && (
                                            <div className="flex gap-2 shrink-0 max-sm:w-full">
                                                <a href={`tel:${phone.replace(/[^\d+]/g, "")}`} className="btn-secondary btn-sm flex-1 sm:flex-none" aria-label={`Call ${c.name}`}>
                                                    <Icon name="phone" className="w-3.5 h-3.5" />Call
                                                </a>
                                                {wa && (
                                                    <a href={`https://wa.me/${wa}`} target="_blank" rel="noopener noreferrer" className="btn-ghost btn-sm flex-1 sm:flex-none border-2 !border-line text-success-700 hover:!bg-success-50" aria-label={`WhatsApp ${c.name}`}>
                                                        <WhatsAppIcon className="w-4 h-4" />WhatsApp
                                                    </a>
                                                )}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )
                ) : (
                    <form onSubmit={e => { e.preventDefault(); void submit(); }} onKeyDown={e => { if (e.key === "Escape") setEditing(false); }} className="space-y-5" noValidate>
                        {SLOTS.map((slot, i) => {
                            if (i === 1 && !showSecond) return null;
                            const d = drafts[i];
                            const err = (k: string) => errors[`${slot}_${k}`];
                            return (
                                <fieldset key={slot} className={clsx("space-y-3", i === 1 && "pt-5 border-t border-line")}>
                                    <div className="flex items-center justify-between">
                                        <legend className="text-xs font-bold uppercase tracking-wider text-surface-500">{i === 0 ? "First to call" : "If they don't answer"}</legend>
                                        {i === 1 && <button type="button" onClick={() => { setShowSecond(false); set(1, { name: "", phone: "", relationship: "", other: "" }); }} className="text-xs font-medium text-surface-500 hover:text-danger">Remove</button>}
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2 gap-3">
                                        <div>
                                            <label htmlFor={`${slot}-name`} className="block text-xs font-semibold text-surface-700 mb-1.5">Full name</label>
                                            <input id={`${slot}-name`} ref={i === 0 ? firstInput : undefined} className={clsx("input", err("name") && "input-error")} value={d.name} maxLength={120}
                                                onChange={e => set(i, { name: e.target.value })} autoComplete="off" placeholder="e.g. Jane Wanjiru"
                                                aria-invalid={!!err("name")} aria-describedby={err("name") ? `${slot}-name-error` : undefined} />
                                            {err("name") && <p id={`${slot}-name-error`} className="field-error" role="alert">{err("name")}</p>}
                                        </div>
                                        <PhoneField key={`${slot}-${editing}`} id={`${slot}-phone`} label="Phone" value={d.phone} onChange={v => set(i, { phone: v })} error={err("phone")} />
                                    </div>
                                    <div>
                                        <span id={`${slot}-rel-label`} className="block text-xs font-semibold text-surface-700 mb-1.5">Relationship</span>
                                        <div role="radiogroup" aria-labelledby={`${slot}-rel-label`} className="flex flex-wrap gap-1.5">
                                            {[...RELATIONSHIPS, "Other"].map(r => (
                                                <button key={r} type="button" role="radio" aria-checked={d.relationship === r}
                                                    onClick={() => set(i, { relationship: d.relationship === r ? "" : r })}
                                                    className={clsx("rounded-full border px-3 py-1.5 text-xs font-medium transition-colors",
                                                        d.relationship === r ? "border-brand-500 bg-brand-50 text-brand-800" : "border-line bg-white text-surface-600 hover:border-surface-300")}>
                                                    {r}
                                                </button>
                                            ))}
                                        </div>
                                        {d.relationship === "Other" && (
                                            <input aria-label="Relationship, in your words" className={clsx("input mt-2", err("relationship") && "input-error")} value={d.other} maxLength={60}
                                                onChange={e => set(i, { other: e.target.value })} placeholder="e.g. Cousin, Neighbour, Pastor" />
                                        )}
                                        {err("relationship") && <p className="field-error" role="alert">{err("relationship")}</p>}
                                    </div>
                                </fieldset>
                            );
                        })}
                        {!showSecond && (
                            <button type="button" onClick={() => setShowSecond(true)} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline">
                                <Icon name="plus" className="w-4 h-4" strokeWidth={2.2} />Add a second contact
                            </button>
                        )}
                        <div className="flex items-center justify-end gap-2 pt-1">
                            <button type="button" onClick={() => setEditing(false)} disabled={saving} className="btn-ghost btn-sm">Cancel</button>
                            <button type="submit" disabled={saving} className="btn-primary btn-sm min-w-[96px]">
                                {saving && <Spinner size="xs" className="border-white/30 border-t-white" />}Save
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
