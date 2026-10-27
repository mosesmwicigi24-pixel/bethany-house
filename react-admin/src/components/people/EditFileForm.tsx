import { useEffect, useMemo, useState, type ReactNode } from "react";
import { clsx } from "clsx";
import { StickyFormFooter } from "@/components/ui/StickyFormFooter";
import { useUnsavedGuard } from "@/hooks/useUnsavedGuard";
import type { EmployeeProfileFields, EmploymentType, PayMethod } from "@/api/people";
import type { ApiError } from "@/types";
import { CardHead, Icon, fmtDay, tenure } from "./fileKit";

/**
 * "Edit file" (owner, 27 Oct 2026: "make the form nicer, better and
 * appealing"). The same fields the old inline grid carried — regrouped under
 * headings with a line of help each, real date and choice controls, checks
 * as you type, a save bar that appears only when something changed, and a
 * question before leaving with edits unsaved.
 *
 * The emergency contact is not here: it is edited on its own card, so this
 * form never sends it. Identifiers and pay amounts stay behind their step-up.
 */

export type FileDraft = Pick<EmployeeProfileFields,
    "job_title" | "department" | "employment_type" | "pay_group_id" | "pay_method" | "hire_date" | "end_date" | "date_of_birth" |
    "bank_name" | "bank_branch" | "bank_account_name" | "notes">;
export type FileDraftKey = keyof FileDraft;

const KEYS: FileDraftKey[] = ["job_title", "department", "employment_type", "pay_group_id", "pay_method", "hire_date", "end_date", "date_of_birth", "bank_name", "bank_branch", "bank_account_name", "notes"];
export const draftOf = (p: EmployeeProfileFields): FileDraft => Object.fromEntries(KEYS.map(k => [k, p[k] ?? null])) as FileDraft;

const TYPES: { value: EmploymentType; label: string; hint: string }[] = [
    { value: "permanent", label: "Permanent", hint: "On the books with no end date" },
    { value: "contract", label: "Contract", hint: "A fixed term" },
    { value: "casual", label: "Casual", hint: "Paid by the day or task" },
    { value: "intern", label: "Intern", hint: "Attachment or internship" },
];
const PAY: { value: PayMethod | null; label: string }[] = [
    { value: "mpesa", label: "M-Pesa" }, { value: "bank", label: "Bank" }, { value: "cash", label: "Cash" }, { value: null, label: "Not set" },
];

const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };

function check(d: FileDraft): Partial<Record<FileDraftKey, string>> {
    const e: Partial<Record<FileDraftKey, string>> = {};
    if (d.hire_date && d.end_date && d.end_date < d.hire_date) e.end_date = "The end date cannot be before the hire date.";
    if (d.date_of_birth && d.date_of_birth >= todayIso()) e.date_of_birth = "A date of birth is in the past.";
    if (d.date_of_birth && d.hire_date && d.date_of_birth >= d.hire_date) e.date_of_birth = "Born after they were hired? Check the year.";
    return e;
}

function Section({ title, help, children }: { title: string; help: ReactNode; children: ReactNode }) {
    return (
        <section className="grid grid-cols-1 lg:grid-cols-[minmax(0,15rem)_1fr] gap-x-10 gap-y-4 py-7 first:pt-0 last:pb-0 border-b border-line last:border-b-0">
            <div>
                <h4 className="text-sm font-bold text-surface-900">{title}</h4>
                <p className="mt-1 text-xs text-surface-500 leading-relaxed">{help}</p>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-4 content-start">{children}</div>
        </section>
    );
}

function Labelled({ id, label, hint, error, children, wide, optional }: { id: string; label: string; hint?: ReactNode; error?: string; children: ReactNode; wide?: boolean; optional?: boolean }) {
    return (
        <div className={clsx(wide && "sm:col-span-2")}>
            <label htmlFor={id} className="flex items-baseline justify-between text-xs font-semibold text-surface-700 mb-1.5">
                <span>{label}</span>{optional && <span className="font-normal text-surface-400">Optional</span>}
            </label>
            {children}
            {error ? <p id={`${id}-error`} className="field-error" role="alert">{error}</p> : hint ? <p id={`${id}-hint`} className="mt-1 text-2xs text-surface-500">{hint}</p> : null}
        </div>
    );
}

function Segmented<T extends string | null>({ id, label, options, value, onChange }: { id: string; label: string; options: { value: T; label: string; hint?: string }[]; value: T; onChange: (v: T) => void }) {
    return (
        <div role="radiogroup" id={id} aria-label={label} className="flex flex-wrap gap-1.5">
            {options.map(o => (
                <button key={String(o.value)} type="button" role="radio" aria-checked={value === o.value} title={o.hint}
                    onClick={() => onChange(o.value)}
                    className={clsx("rounded-full border px-3.5 py-2 text-sm font-medium transition-colors min-h-[40px]",
                        value === o.value ? "border-brand-500 bg-brand-50 text-brand-800" : "border-line bg-white text-surface-600 hover:border-surface-300")}>
                    {o.label}
                </button>
            ))}
        </div>
    );
}

export function EditFileForm({ initial, payGroups, departments, maskedAccount, focus, saving, onSave, onClose, serverErrors }: {
    initial: FileDraft;
    payGroups: { id: number; name: string }[];
    departments: string[];
    /** "••••567" — shown beside the bank fields so the keeper knows which account this is. */
    maskedAccount: string | null;
    focus?: FileDraftKey;
    saving: boolean;
    onSave: (d: FileDraft) => void;
    onClose: () => void;
    serverErrors?: ApiError["errors"];
}) {
    const [d, setD] = useState<FileDraft>(initial);
    const [touched, setTouched] = useState<Partial<Record<FileDraftKey, boolean>>>({});
    const dirtyKeys = useMemo(() => KEYS.filter(k => (d[k] ?? null) !== (initial[k] ?? null) && !((d[k] ?? "") === "" && (initial[k] ?? "") === "")), [d, initial]);
    const dirty = dirtyKeys.length > 0;
    const local = check(d);
    const errorFor = (k: FileDraftKey) => (touched[k] || serverErrors?.[k] ? local[k] ?? serverErrors?.[k]?.[0] : undefined);
    const set = <K extends FileDraftKey>(k: K, v: FileDraft[K]) => { setD(x => ({ ...x, [k]: v })); setTouched(t => ({ ...t, [k]: true })); };
    const [showBank, setShowBank] = useState(initial.pay_method === "bank" || !!(initial.bank_name || initial.bank_branch || initial.bank_account_name));
    useUnsavedGuard(dirty);

    useEffect(() => {
        const el = document.getElementById(`ef-${focus ?? "job_title"}`);
        el?.focus({ preventScroll: true });
    }, [focus]);

    const submit = () => {
        const all = Object.fromEntries(KEYS.map(k => [k, true]));
        setTouched(all);
        if (Object.keys(check(d)).length) return;
        const clean = Object.fromEntries(KEYS.map(k => { const v = d[k]; return [k, typeof v === "string" ? (v.trim() || null) : v]; })) as FileDraft;
        clean.employment_type = d.employment_type;
        onSave(clean);
    };
    const cancel = () => { if (!dirty || window.confirm("Discard your changes to this file?")) onClose(); };
    const t = tenure(d.hire_date, d.end_date);
    const input = (k: FileDraftKey, extra?: string) => clsx("input", extra, errorFor(k) && "input-error");
    const aria = (k: FileDraftKey) => ({ "aria-invalid": !!errorFor(k), "aria-describedby": errorFor(k) ? `ef-${k}-error` : `ef-${k}-hint` });

    return (
        <div className="card animate-fade-in">
            <CardHead icon="edit" title="Edit employee file"
                subtitle={dirty ? <span className="text-warning-700 font-medium">{dirtyKeys.length} unsaved change{dirtyKeys.length === 1 ? "" : "s"}</span> : "Changes are recorded in the audit trail with your name."}
                actions={<>
                    <button type="button" onClick={cancel} className="btn-ghost btn-sm">{dirty ? "Cancel" : "Done"}</button>
                    <button type="button" onClick={submit} disabled={!dirty || saving} className="btn-primary btn-sm hidden sm:inline-flex">Save</button>
                </>} />
            <form className="card-body" onSubmit={e => { e.preventDefault(); submit(); }} noValidate>
                <Section title="Role & placement" help="What they do and where it sits. The department groups people on the roster and the staff list.">
                    <Labelled id="ef-job_title" label="Job title" hint="As it should read on letters and the directory.">
                        <input id="ef-job_title" className={input("job_title")} value={d.job_title ?? ""} maxLength={120} onChange={e => set("job_title", e.target.value)} placeholder="e.g. Senior Tailor" {...aria("job_title")} />
                    </Labelled>
                    <Labelled id="ef-department" label="Department" hint={departments.length ? "Pick one in use, or type a new one." : "e.g. Production, Sales, Finance."}>
                        <input id="ef-department" list="ef-departments" className={input("department")} value={d.department ?? ""} maxLength={80} onChange={e => set("department", e.target.value)} placeholder="Production, Sales, Finance…" {...aria("department")} />
                        <datalist id="ef-departments">{departments.map(x => <option key={x} value={x} />)}</datalist>
                    </Labelled>
                    <Labelled id="ef-employment_type" label="Employment type" wide hint={TYPES.find(x => x.value === d.employment_type)?.hint}>
                        <Segmented id="ef-employment_type" label="Employment type" options={TYPES} value={d.employment_type} onChange={v => set("employment_type", v)} />
                    </Labelled>
                    <Labelled id="ef-pay_group_id" label="Pay group" hint="Decides the timesheet period and the payroll run they fall in.">
                        <select id="ef-pay_group_id" className={input("pay_group_id")} value={d.pay_group_id ?? ""} onChange={e => set("pay_group_id", e.target.value ? Number(e.target.value) : null)} {...aria("pay_group_id")}>
                            <option value="">Default group</option>
                            {payGroups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                        </select>
                    </Labelled>
                </Section>

                <Section title="Key dates" help="The hire date drives leave accrual and the time-with-us line. Leave the end date empty while they work here.">
                    <Labelled id="ef-hire_date" label="Hire date" error={errorFor("hire_date")} hint={t ? (t.future ? t.words : t.words === "Under a month" ? "Just started" : `${t.words} with Bethany`) : "Their first working day."}>
                        <input id="ef-hire_date" type="date" className={input("hire_date")} value={d.hire_date ?? ""} max={d.end_date ?? undefined} onChange={e => set("hire_date", e.target.value || null)} {...aria("hire_date")} />
                    </Labelled>
                    <Labelled id="ef-end_date" label="End date" optional error={errorFor("end_date")} hint={d.end_date ? `Last day ${fmtDay(d.end_date)}` : "Empty while employed."}>
                        <input id="ef-end_date" type="date" className={input("end_date")} value={d.end_date ?? ""} min={d.hire_date ?? undefined} onChange={e => set("end_date", e.target.value || null)} {...aria("end_date")} />
                    </Labelled>
                    <Labelled id="ef-date_of_birth" label="Date of birth" optional error={errorFor("date_of_birth")} hint="Only the day and month show in the directory, and only if they allow it.">
                        <input id="ef-date_of_birth" type="date" className={input("date_of_birth")} value={d.date_of_birth ?? ""} max={todayIso()} onChange={e => set("date_of_birth", e.target.value || null)} {...aria("date_of_birth")} />
                    </Labelled>
                </Section>

                <Section title="How they're paid" help={<>The method payroll pays them by. Account and M-Pesa numbers are kept encrypted under <span className="font-semibold text-surface-700">Identifiers &amp; pay details</span>.</>}>
                    <Labelled id="ef-pay_method" label="Pay method" wide>
                        <Segmented id="ef-pay_method" label="Pay method" options={PAY} value={d.pay_method} onChange={v => { set("pay_method", v); if (v === "bank") setShowBank(true); }} />
                    </Labelled>
                    {showBank ? (<>
                        <Labelled id="ef-bank_name" label="Bank">
                            <input id="ef-bank_name" className={input("bank_name")} value={d.bank_name ?? ""} maxLength={80} onChange={e => set("bank_name", e.target.value)} placeholder="e.g. KCB, Equity, Co-op" {...aria("bank_name")} />
                        </Labelled>
                        <Labelled id="ef-bank_branch" label="Branch" optional>
                            <input id="ef-bank_branch" className={input("bank_branch")} value={d.bank_branch ?? ""} maxLength={80} onChange={e => set("bank_branch", e.target.value)} placeholder="e.g. Moi Avenue" {...aria("bank_branch")} />
                        </Labelled>
                        <Labelled id="ef-bank_account_name" label="Account holder" wide hint={maskedAccount ? <>Account number on file: <span className="font-mono">{maskedAccount}</span></> : "The account number itself is added under Identifiers & pay details."}>
                            <input id="ef-bank_account_name" className={input("bank_account_name")} value={d.bank_account_name ?? ""} maxLength={120} onChange={e => set("bank_account_name", e.target.value)} placeholder="Name exactly as the bank has it" {...aria("bank_account_name")} />
                        </Labelled>
                    </>) : (
                        <div className="sm:col-span-2">
                            <button type="button" onClick={() => setShowBank(true)} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline">
                                <Icon name="plus" className="w-4 h-4" strokeWidth={2.2} />Add bank details
                            </button>
                        </div>
                    )}
                </Section>

                <Section title="Notes" help="Anything the next file keeper should know. Seen by everyone who can open this file.">
                    <Labelled id="ef-notes" label="Notes" wide optional hint={`${(d.notes ?? "").length} / 2000`}>
                        <textarea id="ef-notes" rows={4} className={clsx(input("notes"), "resize-y min-h-[112px]")} value={d.notes ?? ""} maxLength={2000} onChange={e => set("notes", e.target.value)} {...aria("notes")} />
                    </Labelled>
                </Section>
                <button type="submit" className="sr-only">Save</button>
            </form>
            <StickyFormFooter isDirty={dirty} isSubmitting={saving} onSubmit={submit} onDiscard={onClose} saveLabel="Save file" />
        </div>
    );
}
