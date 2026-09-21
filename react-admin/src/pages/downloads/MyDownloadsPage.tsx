import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { downloadsApi, takeApprovedDownload, type DownloadRequest } from "@/api/downloads";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";

// The downloads a staff member has asked for, and the approved ones waiting to
// be taken. An approval opens exactly the download that was asked for, once.

export const STATUS_STYLE: Record<string, { label: string; cls: string }> = {
    pending:    { label: "Waiting for approval", cls: "bg-warning-light text-warning" },
    approved:   { label: "Approved — ready",     cls: "bg-success-light text-success" },
    denied:     { label: "Not approved",         cls: "bg-danger-light text-danger" },
    downloaded: { label: "Downloaded",           cls: "bg-surface-100 text-surface-600" },
    expired:    { label: "Expired",              cls: "bg-surface-100 text-surface-400" },
    cancelled:  { label: "Cancelled",            cls: "bg-surface-100 text-surface-400" },
    auto:       { label: "Owner",                cls: "bg-brand-50 text-brand-600" },
    held:       { label: "Not yet requested",    cls: "bg-surface-100 text-surface-500" },
    failed:     { label: "Failed",               cls: "bg-danger-light text-danger" },
};

export function StatusPill({ status }: { status: string }) {
    const s = STATUS_STYLE[status] ?? { label: status, cls: "bg-surface-100 text-surface-600" };
    return <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ${s.cls}`}>{s.label}</span>;
}

export function filtersOf(r: DownloadRequest): string {
    return Object.entries(r.payload ?? {})
        .filter(([, v]) => v !== "" && v !== null && v !== undefined)
        .map(([k, v]) => `${k}: ${typeof v === "object" ? JSON.stringify(v) : String(v)}`)
        .join(" · ");
}

export default function MyDownloadsPage() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [page, setPage] = useState(1);
    const [taking, setTaking] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["my-downloads", page],
        queryFn: () => downloadsApi.mine({ page: String(page) }),
        refetchInterval: 30_000,   // an approval shows up without a reload
    });

    const cancel = useMutation({
        mutationFn: (uuid: string) => downloadsApi.cancel(uuid),
        onSuccess: () => { toast.success("Request cancelled."); qc.invalidateQueries({ queryKey: ["my-downloads"] }); },
        onError: (e: any) => toast.error(e?.message ?? "Could not cancel."),
    });

    const take = async (r: DownloadRequest) => {
        setTaking(r.uuid);
        try {
            await takeApprovedDownload(r);
            qc.invalidateQueries({ queryKey: ["my-downloads"] });
        } catch (e: any) {
            toast.error(e?.message ?? "The download could not be taken.");
        } finally {
            setTaking(null);
        }
    };

    const rows = data?.data ?? [];

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header">
                <h1 className="page-title">My downloads</h1>
                <p className="page-subtitle">
                    Files taken out of Bethany Hub are approved first — except invoices, quotations and receipts.
                    Approved downloads can be taken once, within 24 hours.
                </p>
            </div>

            <div className="card divide-y divide-surface-100">
                {isLoading && <p className="p-4 text-sm text-surface-400">Loading…</p>}
                {!isLoading && rows.length === 0 && (
                    <p className="p-6 text-sm text-surface-400">
                        No download requests yet. When a download needs approval you'll be asked for a reason, and it will appear here.
                    </p>
                )}
                {rows.map((r) => (
                    <div key={r.uuid} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium text-surface-800">{r.label}</p>
                                <StatusPill status={r.status} />
                            </div>
                            {filtersOf(r) && <p className="font-mono text-[11px] text-surface-400 break-all">{filtersOf(r)}</p>}
                            <p className="text-xs text-surface-500">
                                Asked {new Date(r.created_at).toLocaleString("en-GB")} · “{r.reason}”
                            </p>
                            {r.decider && (
                                <p className="text-xs text-surface-500">
                                    {r.status === "denied" ? "Declined" : "Approved"} by {r.decider.first_name} {r.decider.last_name}
                                    {r.decision_note ? ` — ${r.decision_note}` : ""}
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 gap-2">
                            {r.status === "approved" && (
                                <button onClick={() => take(r)} disabled={taking === r.uuid} className="btn-primary btn-sm">
                                    {taking === r.uuid && <Spinner size="xs" className="border-white/30 border-t-white" />}
                                    Download now
                                </button>
                            )}
                            {(r.status === "pending" || r.status === "approved") && (
                                <button onClick={() => cancel.mutate(r.uuid)} className="btn-ghost btn-sm text-xs">Cancel</button>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-xs">
                    <button className="btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>← Newer</button>
                    <span className="text-surface-400">Page {data.current_page} of {data.last_page}</span>
                    <button className="btn-ghost btn-sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Older →</button>
                </div>
            )}
        </div>
    );
}
