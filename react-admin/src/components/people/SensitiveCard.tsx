import { useState } from "react";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { Sensitive, SensitiveKey } from "@/api/people";
import type { ApiError } from "@/types";

/**
 * Identifiers and pay details (People PR 2). Shown masked; "Reveal" asks the
 * server, which asks for a step-up (the console's dialog handles it) and
 * writes the read to the activity log. A keeper with hr.edit + hr.view_sensitive
 * can edit after revealing.
 */
const FIELDS: { key: SensitiveKey; label: string; hint?: string }[] = [
    { key: "national_id_no", label: "National ID number" },
    { key: "kra_pin", label: "KRA PIN" },
    { key: "nssf_no", label: "NSSF number" },
    { key: "shif_no", label: "SHIF number" },
    { key: "mpesa_phone", label: "M-Pesa number", hint: "For pay by M-Pesa" },
    { key: "bank_account_no", label: "Bank account number" },
];

export function SensitiveCard({ masked, canView, canEdit, fetchFull, save }: {
    masked: Sensitive;
    canView: boolean;
    canEdit: boolean;
    fetchFull: () => Promise<Sensitive>;
    save?: (data: Partial<Sensitive>) => Promise<Sensitive>;
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

    return (
        <div className="card">
            <div className="card-header flex items-center justify-between gap-2">
                <div>
                    <h3 className="font-semibold text-sm text-surface-900">Identifiers &amp; pay details</h3>
                    <p className="text-2xs text-surface-500">Encrypted at rest. Revealing them is confirmed and recorded.</p>
                </div>
                <div className="flex gap-2">
                    {canView && !full && <button onClick={() => void reveal()} disabled={loading} className="btn-secondary btn-sm">{loading ? <Spinner size="xs" /> : "Reveal"}</button>}
                    {canView && full && !editing && <button onClick={() => setFull(null)} className="btn-ghost btn-sm text-xs">Hide</button>}
                    {canEdit && !editing && <button onClick={() => void startEdit()} className="btn-secondary btn-sm">Edit</button>}
                </div>
            </div>
            <div className="card-body">
                {!canView && !hasAny && <p className="text-sm text-surface-400">Nothing on file.</p>}
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                    {FIELDS.map(f => (
                        <div key={f.key}>
                            <dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">{f.label}</dt>
                            <dd className="mt-0.5">
                                {editing ? (
                                    <input className="input font-mono" value={draft[f.key] ?? full?.[f.key] ?? ""} onChange={e => setDraft(d => ({ ...d, [f.key]: e.target.value }))}
                                        placeholder={f.hint ?? ""} aria-label={f.label} autoComplete="off" />
                                ) : (
                                    <span className="font-mono text-sm text-surface-900 select-text">{(full ? full[f.key] : masked[f.key]) ?? <span className="text-surface-300">—</span>}</span>
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>
                {editing && (
                    <div className="mt-4 flex justify-end gap-2">
                        <button onClick={() => setEditing(false)} className="btn-secondary btn-sm">Cancel</button>
                        <button onClick={() => void submit()} disabled={saving || Object.keys(draft).length === 0} className="btn-primary btn-sm">
                            {saving && <Spinner size="xs" className="border-white/30 border-t-white" />}Save
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
