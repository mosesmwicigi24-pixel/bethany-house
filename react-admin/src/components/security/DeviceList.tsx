import { useState } from "react";
import { Spinner } from "@/components/ui/Spinner";
import type { DeviceRow } from "@/types";

/**
 * The devices someone has signed in on (named sign-in devices). Used on the
 * person's own profile and in an administrator's sign-in security view.
 * The type ("iPhone · Chrome") is what the browser reports; the name is the
 * one given here. An administrator's name wins: the person cannot change it.
 */
export function DeviceList({
    rows,
    loading,
    asAdmin,
    busy,
    onRename,
    onRemove,
}: {
    rows: DeviceRow[];
    loading: boolean;
    asAdmin: boolean;
    busy: boolean;
    onRename: (id: number, name: string | null) => void;
    onRemove: (id: number) => void;
}) {
    const [editing, setEditing] = useState<number | null>(null);
    const [draft, setDraft] = useState("");

    if (loading) return <Spinner size="sm" />;
    if (rows.length === 0) return <p className="text-sm text-surface-400">No devices yet. A device appears after its next sign-in.</p>;

    const save = (id: number) => {
        const name = draft.trim();
        onRename(id, name.length ? name : null);
        setEditing(null);
    };

    return (
        <ul className="divide-y divide-line rounded-lg border border-line">
            {rows.map((d) => {
                const locked = !asAdmin && d.name_source === "admin";
                return (
                    <li key={d.id} className="p-3 space-y-2">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-medium text-sm text-surface-800 truncate">
                                    {d.name ?? <span className="text-surface-500 italic">Not named</span>}
                                    {d.is_current && <span className="badge badge-success text-2xs ml-2">This device</span>}
                                </p>
                                <p className="text-xs text-surface-500">
                                    {d.facts}
                                    {d.name_source === "admin" && d.named_by && ` · named by ${d.named_by}`}
                                    {d.name_source === "self" && asAdmin && " · named by them"}
                                </p>
                                <p className="text-xs text-surface-400">
                                    Last signed in {new Date(d.last_seen).toLocaleString()} · {d.active_sessions} active session{d.active_sessions === 1 ? "" : "s"}
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-1">
                                {!locked && editing !== d.id && (
                                    <button
                                        onClick={() => { setEditing(d.id); setDraft(d.name ?? ""); }}
                                        disabled={busy}
                                        className="btn-ghost btn-sm"
                                    >
                                        {d.name ? "Rename" : "Name it"}
                                    </button>
                                )}
                                {!d.is_current && (
                                    <button
                                        onClick={() => {
                                            if (window.confirm(`Remove ${d.label}? Its sessions end, and the next sign-in on it is treated as a new device.`)) onRemove(d.id);
                                        }}
                                        disabled={busy}
                                        className="btn-ghost btn-sm text-danger"
                                    >
                                        Remove
                                    </button>
                                )}
                            </div>
                        </div>
                        {editing === d.id && (
                            <div className="flex gap-2">
                                <input
                                    autoFocus
                                    value={draft}
                                    maxLength={60}
                                    onChange={(e) => setDraft(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === "Enter") save(d.id); if (e.key === "Escape") setEditing(null); }}
                                    className="input flex-1"
                                    placeholder={asAdmin ? "e.g. Till 1 — Nairobi shop" : "e.g. My work laptop"}
                                />
                                <button onClick={() => save(d.id)} disabled={busy || (draft.trim().length > 0 && draft.trim().length < 2)} className="btn-primary btn-sm">Save</button>
                                <button onClick={() => setEditing(null)} className="btn-ghost btn-sm">Cancel</button>
                            </div>
                        )}
                        {locked && <p className="text-2xs text-surface-400">Named by an administrator — ask them to change it.</p>}
                    </li>
                );
            })}
        </ul>
    );
}
