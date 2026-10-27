import { useState } from "react";
import { clsx } from "clsx";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { Sensitive, SensitiveKey } from "@/api/people";
import type { ApiError } from "@/types";
import { CardHead, Icon } from "./fileKit";

/**
 * Identifiers and pay details (People PR 2). Shown masked; "Reveal" asks the
 * server, which asks for a step-up (the console's dialog handles it) and
 * writes the read to the activity log. A keeper with hr.edit + hr.view_sensitive
 * can edit after revealing. The look changed (27 Oct 2026); what is fetched,
 * when, and from where did not.
 */
const FIELDS: { key: SensitiveKey; label: string; hint?: string }[] = [
    { key: "national_id_no", label: "National ID number" },
    { key: "kra_pin", label: "KRA PIN" },
    { key: "nssf_no", label: "NSSF number" },
    { key: "shif_no", label: "SHIF number" },
    { key: "mpesa_phone", label: "M-Pesa number", hint: "For pay by M-Pesa" },
    { key: "bank_account_no", label: "Bank account number" },
];

export function SensitiveCard({ masked, canView, canEdit, fetchFull, save, titleId }: {
    masked: Sensitive;
    canView: boolean;
    canEdit: boolean;
    fetchFull: () => Promise<Sensitive>;
    save?: (data: Partial<Sensitive>) => Promise<Sensitive>;
    titleId?: string;
}) {
    const toast = useToastStore();
    const [full, setFull] = useState<Sensitive | null>(null);
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<Partial<Sensitive>>({});
    const [saving, setSaving] = useState(false);

    const reveal = async () => {
        setLoading(true);
        try { setFull(await fetchFull()); }
        catch (err) { const e = err as ApiError; if (e?.message) toast.error(e.message); }
        finally { setLoading(false); }
    };
    const startEdit = async () => {
        if (!full) await reveal();
        setDraft({}); setEditing(true);
    };
    const submit = async () => {
        if (!save) return;
        setSaving(true);
        try { setFull(await save(draft)); setEditing(false); toast.success("Saved."); }
        catch (err) { toast.error((err as ApiError)?.message ?? "Could not save."); }
        finally { setSaving(false); }
    };
    const hasAny = Object.values(masked).some(Boolean);
    const onFile = Object.values(masked).filter(Boolean).length;

    return (
        <div className="card h-full" aria-labelledby={titleId}>
            <CardHead icon="lock" tone="neutral" titleId={titleId} title="Identifiers & pay details"
                subtitle={full ? <span className="text-amber-700 font-medium">Shown in full — this view was recorded.</span> : "Encrypted at rest. Revealing them asks you to confirm it is you, and is recorded."}
                actions={<>
                    {canView && !full && <button onClick={() => void reveal()} disabled={loading} className="btn-secondary btn-sm">{loading ? <Spinner size="xs" /> : <Icon name="eye" className="w-3.5 h-3.5" />}Reveal</button>}
                    {canView && full && !editing && <button onClick={() => setFull(null)} className="btn-ghost btn-sm"><Icon name="eyeOff" className="w-3.5 h-3.5" />Hide</button>}
                    {canEdit && !editing && <button onClick={() => void startEdit()} className="btn-secondary btn-sm"><Icon name="edit" className="w-3.5 h-3.5" />Edit</button>}
                </>} />
            <div className="card-body">
                {!canView && !hasAny && <p className="text-sm text-surface-500 mb-4">Nothing on file.</p>}
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {FIELDS.map(f => {
                        const value = full ? full[f.key] : masked[f.key];
                        return (
                            <div key={f.key} className={clsx("rounded-2xl px-4 py-3 border", editing ? "border-transparent px-0 py-0" : "border-line bg-surface-50/60")}>
                                <dt className="text-2xs font-semibold uppercase tracking-wider text-surface-500 flex items-center gap-1.5">
                                    {!full && !editing && <Icon name="lock" className="w-3 h-3 text-surface-400" />}{f.label}
                                </dt>
                                <dd className="mt-1">
                                    {editing ? (
                                        <input className="input font-mono" value={draft[f.key] ?? full?.[f.key] ?? ""} onChange={e => setDraft(d => ({ ...d, [f.key]: e.target.value }))}
                                            placeholder={f.hint ?? ""} aria-label={f.label} autoComplete="off" />
                                    ) : value ? (
                                        <span className={clsx("font-mono text-[15px] select-text", full ? "text-surface-900" : "text-surface-700 tracking-wider")}>{value}</span>
                                    ) : (
                                        <span className="text-sm text-surface-400">Not on file</span>
                                    )}
                                </dd>
                            </div>
                        );
                    })}
                </dl>
                {!editing && !full && hasAny && <p className="mt-3 text-2xs text-surface-500">{onFile} of {FIELDS.length} on file · only the last characters are shown.</p>}
                {editing && (
                    <div className="mt-4 flex justify-end gap-2">
                        <button onClick={() => setEditing(false)} className="btn-ghost btn-sm">Cancel</button>
                        <button onClick={() => void submit()} disabled={saving || Object.keys(draft).length === 0} className="btn-primary btn-sm">
                            {saving && <Spinner size="xs" className="border-white/30 border-t-white" />}Save
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
