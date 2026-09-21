import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { activityLogApi } from "@/api/profile";
import type { ActivityLogEntry } from "@/api/profile";
import { usePermissions } from "@/hooks/usePermissions";

// Shared pieces of the audit trail: the Activity Log page and the per-record
// History panel render entries the same way.

export function actionMeta(action?: string | null): { bg: string; text: string } {
    if (!action) return { bg: "bg-surface-100", text: "text-surface-500" };
    if (action.includes("failed") || action.includes("refused"))
        return { bg: "bg-danger-light", text: "text-danger" };
    if (action.includes("login")) return { bg: "bg-brand-50", text: "text-brand-600" };
    if (action.includes("created")) return { bg: "bg-success-light", text: "text-success" };
    if (action.includes("deleted") || action.includes("wipe") || action.includes("restored"))
        return { bg: "bg-danger-light", text: "text-danger" };
    if (action.includes("updated") || action.includes("settings"))
        return { bg: "bg-info-light", text: "text-info" };
    if (action.includes("password")) return { bg: "bg-warning-light", text: "text-warning" };
    if (action.includes("role") || action.includes("permission"))
        return { bg: "bg-accent-50", text: "text-accent-600" };
    return { bg: "bg-surface-100", text: "text-surface-500" };
}

export function actionLabel(action?: string | null): string {
    if (!action) return "Unknown";
    return action
        .split("_")
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(" ");
}

export function ActionBadge({ action }: { action?: string | null }) {
    const m = actionMeta(action);
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium ${m.bg} ${m.text}`}>
            {actionLabel(action)}
        </span>
    );
}

/** "App\\Models\\ProductPrice" + 12 → "Product Price #12" */
export function subjectLabel(type?: string | null, id?: number | null): string | null {
    if (!type) return null;
    const base = type.split("\\").pop() ?? type;
    const words = base.replace(/([a-z])([A-Z])/g, "$1 $2");
    return id ? `${words} #${id}` : words;
}

export function parseProps(entry: ActivityLogEntry): Record<string, unknown> {
    const p = entry.properties;
    if (!p) return {};
    if (typeof p === "string") {
        try {
            const v = JSON.parse(p);
            return v && typeof v === "object" ? (v as Record<string, unknown>) : {};
        } catch {
            return {};
        }
    }
    return p;
}

function show(v: unknown): string {
    if (v === null || v === undefined || v === "") return "—";
    if (typeof v === "boolean") return v ? "true" : "false";
    if (typeof v === "object") return JSON.stringify(v);
    return String(v);
}

type Change = { old?: unknown; new?: unknown } | string;

/** Field / before / after — the heart of "what changed". */
export function ChangeTable({ changes }: { changes: Record<string, Change> }) {
    const rows = Object.entries(changes);
    if (!rows.length) return null;
    return (
        <div className="overflow-x-auto rounded-lg border border-surface-200">
            <table className="w-full text-xs">
                <thead className="bg-surface-50 text-surface-500">
                    <tr>
                        <th className="px-3 py-2 text-left font-medium">Field</th>
                        <th className="px-3 py-2 text-left font-medium">Before</th>
                        <th className="px-3 py-2 text-left font-medium">After</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-surface-100">
                    {rows.map(([field, c]) => {
                        const hidden = typeof c === "string";   // whole pair redacted
                        return (
                            <tr key={field} className="align-top">
                                <td className="px-3 py-2 font-mono text-surface-700 whitespace-nowrap">{field}</td>
                                <td className="px-3 py-2 text-danger/80 break-all line-through decoration-danger/30">
                                    {hidden ? "[hidden]" : show((c as { old?: unknown }).old)}
                                </td>
                                <td className="px-3 py-2 text-success break-all">
                                    {hidden ? "[hidden — changed]" : show((c as { new?: unknown }).new)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

/** Everything an entry carries, rendered by shape. */
export function EntryDetails({ entry }: { entry: ActivityLogEntry }) {
    const props = parseProps(entry);
    const { changes, attributes, ...rest } = props as {
        changes?: Record<string, Change>;
        attributes?: Record<string, unknown>;
        [k: string]: unknown;
    };
    const restKeys = Object.keys(rest);

    return (
        <div className="space-y-3">
            {changes && typeof changes === "object" && <ChangeTable changes={changes} />}
            {attributes && typeof attributes === "object" && (
                <details className="rounded-lg border border-surface-200">
                    <summary className="cursor-pointer px-3 py-2 text-xs font-medium text-surface-600">
                        Record values ({Object.keys(attributes).length} fields)
                    </summary>
                    <dl className="grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 px-3 pb-3 text-xs">
                        {Object.entries(attributes).map(([k, v]) => (
                            <div key={k} className="contents">
                                <dt className="font-mono text-surface-500">{k}</dt>
                                <dd className="text-surface-800 break-all">{show(v)}</dd>
                            </div>
                        ))}
                    </dl>
                </details>
            )}
            {restKeys.length > 0 && (
                <pre className="max-h-64 overflow-auto rounded-lg bg-surface-50 p-3 text-[11px] text-surface-700">
                    {JSON.stringify(rest, null, 2)}
                </pre>
            )}
        </div>
    );
}

/**
 * Every change to one record, newest first — dropped into a detail page.
 * The API is super_admin only, so the panel renders nothing for anyone else.
 * `type` is the short model name the API accepts ("order", "product", "customer").
 */
export function RecordHistory({ type, id }: { type: string; id?: number | null }) {
    const { isSuperAdmin } = usePermissions();
    const [open, setOpen] = useState(false);
    const [page, setPage] = useState(1);

    const { data, isLoading, isError } = useQuery({
        queryKey: ["record-history", type, id, page],
        queryFn: () => activityLogApi.record(type, id as number, { page: String(page), per_page: "20" }),
        enabled: isSuperAdmin && open && !!id,
    });

    if (!isSuperAdmin || !id) return null;

    const entries = data?.data ?? [];

    return (
        <div className="card">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center justify-between px-4 py-3 text-left"
            >
                <span className="text-sm font-semibold text-surface-800">History</span>
                <span className="text-xs text-surface-400">
                    {open ? "Hide" : "Every change, who made it and when"}
                </span>
            </button>
            {open && (
                <div className="border-t border-surface-100 px-4 py-3 space-y-4">
                    {isLoading && <p className="text-xs text-surface-400">Loading…</p>}
                    {isError && <p className="text-xs text-danger">Could not load the history.</p>}
                    {!isLoading && !isError && entries.length === 0 && (
                        <p className="text-xs text-surface-400">No recorded changes yet.</p>
                    )}
                    {entries.map((e) => (
                        <div key={e.id} className="space-y-2">
                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                <ActionBadge action={e.action} />
                                <span className="text-surface-700">{e.user_name?.trim() || "System"}</span>
                                <span className="text-surface-400">
                                    {new Date(e.created_at).toLocaleString("en-GB")}
                                </span>
                                {e.ip_address && <span className="font-mono text-surface-400">{e.ip_address}</span>}
                            </div>
                            <EntryDetails entry={e} />
                        </div>
                    ))}
                    {data && data.last_page > 1 && (
                        <div className="flex items-center justify-between pt-1 text-xs">
                            <button className="btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                                ← Newer
                            </button>
                            <span className="text-surface-400">Page {data.current_page} of {data.last_page}</span>
                            <button className="btn-ghost btn-sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>
                                Older →
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
