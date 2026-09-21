import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { downloadsApi, openArchivedCopy, type DownloadRequest } from "@/api/downloads";
import { usersApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { StatusPill, filtersOf } from "./MyDownloadsPage";

// Download approval — for the owner and the managers he delegates to.
//   Waiting        approve / deny what staff asked for (never your own)
//   Every download (owner) every file that left, of every kind, with its export id
//   Approvers      (owner) who else may approve

type Tab = "waiting" | "all" | "approvers";

const person = (u?: { first_name: string; last_name: string } | null) =>
    u ? `${u.first_name} ${u.last_name}`.trim() : "—";

const CATEGORY: Record<string, string> = {
    gated: "Needs approval",
    exempt: "Invoice / quotation / receipt",
    never_attach: "Database backup",
};

function Waiting({ openUuid }: { openUuid: string | null }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [denying, setDenying] = useState<DownloadRequest | null>(null);
    const [note, setNote] = useState("");

    const { data, isLoading } = useQuery({
        queryKey: ["download-queue"],
        queryFn: () => downloadsApi.queue(),
        refetchInterval: 30_000,
    });

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["download-queue"] });
        qc.invalidateQueries({ queryKey: ["download-capabilities"] });
    };
    const approve = useMutation({
        mutationFn: (uuid: string) => downloadsApi.approve(uuid),
        onSuccess: () => { toast.success("Approved. They can take it now."); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Could not approve."),
    });
    const deny = useMutation({
        mutationFn: ({ uuid, note }: { uuid: string; note: string }) => downloadsApi.deny(uuid, note),
        onSuccess: () => { toast.success("Declined."); setDenying(null); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Could not decline."),
    });

    useEffect(() => {
        if (openUuid) document.getElementById(`dl-${openUuid}`)?.scrollIntoView({ behavior: "smooth", block: "center" });
    }, [openUuid, data]);

    const rows = data?.data ?? [];

    return (
        <>
            <div className="card divide-y divide-surface-100">
                {isLoading && <p className="p-4 text-sm text-surface-400">Loading…</p>}
                {!isLoading && rows.length === 0 && <p className="p-6 text-sm text-surface-400">Nothing waiting.</p>}
                {rows.map((r) => (
                    <div
                        key={r.uuid}
                        id={`dl-${r.uuid}`}
                        className={`flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between ${openUuid === r.uuid ? "bg-brand-50/50" : ""}`}
                    >
                        <div className="min-w-0 space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium text-surface-800">{r.label}</p>
                                <StatusPill status={r.status} />
                            </div>
                            <p className="text-sm text-surface-700">
                                {person(r.user)} <span className="text-surface-400">· {r.user?.email}</span>
                            </p>
                            {filtersOf(r) && <p className="font-mono text-[11px] text-surface-400 break-all">{filtersOf(r)}</p>}
                            <p className="text-sm text-surface-600">“{r.reason}”</p>
                            <p className="text-xs text-surface-400">
                                Asked {new Date(r.updated_at).toLocaleString("en-GB")}
                                {r.decider && ` · ${r.status === "denied" ? "declined" : "approved"} by ${person(r.decider)}${r.decision_note ? ` — ${r.decision_note}` : ""}`}
                            </p>
                        </div>
                        {r.status === "pending" && (
                            <div className="flex shrink-0 gap-2">
                                <button onClick={() => { setDenying(r); setNote(""); }} className="btn-secondary btn-sm text-danger">Decline</button>
                                <button onClick={() => approve.mutate(r.uuid)} disabled={approve.isPending} className="btn-primary btn-sm">Approve</button>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            <Modal
                open={!!denying}
                onClose={() => setDenying(null)}
                title="Decline this download"
                size="sm"
                footer={
                    <>
                        <button onClick={() => setDenying(null)} className="btn-secondary btn-sm">Cancel</button>
                        <button
                            onClick={() => denying && deny.mutate({ uuid: denying.uuid, note: note.trim() })}
                            disabled={note.trim().length < 3 || deny.isPending}
                            className="btn-danger btn-sm"
                        >Decline</button>
                    </>
                }
            >
                <div className="space-y-2 text-sm">
                    <p className="text-surface-600">{denying && `${person(denying.user)} — ${denying.label}`}</p>
                    <label className="label">Tell them why</label>
                    <textarea className="input min-h-[80px]" value={note} onChange={(e) => setNote(e.target.value)} autoFocus />
                </div>
            </Modal>
        </>
    );
}

function Ledger({ openUuid }: { openUuid: string | null }) {
    const toast = useToastStore();
    const [page, setPage] = useState(1);
    const [category, setCategory] = useState("");
    const params: Record<string, string> = {
        page: String(page),
        ...(category && { category }),
        ...(openUuid && { uuid: openUuid }),
    };
    const { data, isLoading } = useQuery({ queryKey: ["download-ledger", params], queryFn: () => downloadsApi.all(params) });

    const open = async (r: DownloadRequest) => {
        try { await openArchivedCopy(r); } catch (e: any) { toast.error(e?.message ?? "No archived copy."); }
    };

    return (
        <>
            <div className="card p-4 flex flex-wrap items-center gap-3">
                <select className="input max-w-xs" value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }}>
                    <option value="">Every kind</option>
                    <option value="gated">Needs approval</option>
                    <option value="never_attach">Database backups</option>
                    <option value="exempt">Invoices, quotations, receipts</option>
                </select>
                {openUuid && <span className="text-xs text-surface-500">Showing one download from a link.</span>}
            </div>
            <div className="card overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-surface-50 text-xs text-surface-500">
                        <tr>
                            <th className="px-4 py-2 text-left font-medium">When</th>
                            <th className="px-4 py-2 text-left font-medium">Who</th>
                            <th className="px-4 py-2 text-left font-medium">What</th>
                            <th className="px-4 py-2 text-left font-medium">Approval</th>
                            <th className="px-4 py-2 text-left font-medium">Export id</th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-surface-100">
                        {isLoading && <tr><td className="px-4 py-3 text-surface-400" colSpan={6}>Loading…</td></tr>}
                        {(data?.data ?? []).map((r) => (
                            <tr key={r.uuid} className="align-top">
                                <td className="px-4 py-2 text-xs text-surface-600 whitespace-nowrap">
                                    {r.downloaded_at ? new Date(r.downloaded_at).toLocaleString("en-GB") : <StatusPill status={r.status} />}
                                </td>
                                <td className="px-4 py-2">{person(r.user)}</td>
                                <td className="px-4 py-2">
                                    <p className="text-surface-800">{r.label}</p>
                                    <p className="text-xs text-surface-400">{CATEGORY[r.category] ?? r.category}{r.file_name ? ` · ${r.file_name}` : ""}</p>
                                    {r.reason && <p className="text-xs text-surface-500">“{r.reason}”</p>}
                                </td>
                                <td className="px-4 py-2 text-xs text-surface-600">
                                    {r.auto_approved ? "You" : r.decider ? `Approved by ${person(r.decider)}` : r.shadow ? "Not held (recording only)" : r.category === "exempt" ? "Not needed" : <StatusPill status={r.status} />}
                                </td>
                                <td className="px-4 py-2 font-mono text-xs text-surface-500">{r.export_id ?? "—"}</td>
                                <td className="px-4 py-2 text-right">
                                    {r.archive_path && <button onClick={() => open(r)} className="btn-ghost btn-sm text-xs">Archived copy</button>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-xs">
                    <button className="btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>← Newer</button>
                    <span className="text-surface-400">Page {data.current_page} of {data.last_page} · {data.total} downloads</span>
                    <button className="btn-ghost btn-sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Older →</button>
                </div>
            )}
        </>
    );
}

function Approvers() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [pick, setPick] = useState("");
    const { data } = useQuery({ queryKey: ["download-approvers"], queryFn: () => downloadsApi.approvers() });
    const { data: staff } = useQuery({
        queryKey: ["download-approver-candidates"],
        queryFn: () => usersApi.list({ per_page: "100", status: "active" }),
    });

    const add = useMutation({
        mutationFn: (id: number) => downloadsApi.addApprover(id),
        onSuccess: () => { toast.success("They can now approve downloads."); setPick(""); qc.invalidateQueries({ queryKey: ["download-approvers"] }); },
        onError: (e: any) => toast.error(e?.message ?? "Could not add."),
    });
    const remove = useMutation({
        mutationFn: (id: number) => downloadsApi.removeApprover(id),
        onSuccess: () => { toast.success("Removed."); qc.invalidateQueries({ queryKey: ["download-approvers"] }); },
        onError: (e: any) => toast.error(e?.message ?? "Could not remove."),
    });

    const delegated = new Set((data?.delegates ?? []).map((d) => d.user.id));
    const candidates = (staff?.data ?? []).filter(
        (u) => u.user_type !== "customer" && u.id !== data?.owner?.id && !delegated.has(u.id),
    );

    return (
        <div className="card divide-y divide-surface-100">
            <div className="p-4 text-sm text-surface-600">
                You approve every download. A manager you add here can approve other people's downloads — never their own.
                Only you can change this list.
            </div>
            {data?.owner && (
                <div className="flex items-center justify-between p-4">
                    <div><p className="font-medium text-surface-800">{person(data.owner)}</p><p className="text-xs text-surface-400">{data.owner.email}</p></div>
                    <span className="text-xs font-medium text-brand-600">Owner</span>
                </div>
            )}
            {(data?.delegates ?? []).map((d) => (
                <div key={d.id} className="flex items-center justify-between p-4">
                    <div><p className="font-medium text-surface-800">{person(d.user)}</p><p className="text-xs text-surface-400">{d.user.email}</p></div>
                    <button onClick={() => remove.mutate(d.user.id)} className="btn-ghost btn-sm text-xs text-danger">Remove</button>
                </div>
            ))}
            <div className="flex flex-col gap-2 p-4 sm:flex-row">
                <select className="input flex-1" value={pick} onChange={(e) => setPick(e.target.value)}>
                    <option value="">Add a manager…</option>
                    {candidates.map((u) => <option key={u.id} value={u.id}>{u.first_name} {u.last_name} — {u.email}</option>)}
                </select>
                <button onClick={() => pick && add.mutate(Number(pick))} disabled={!pick || add.isPending} className="btn-primary btn-sm">Add approver</button>
            </div>
        </div>
    );
}

export default function DownloadApprovalsPage() {
    const [params] = useSearchParams();
    const openUuid = params.get("open");
    const { data: caps, isLoading } = useQuery({ queryKey: ["download-capabilities"], queryFn: () => downloadsApi.capabilities() });
    // Links from the owner's copy emails point at a finished download (the ledger);
    // links from approval requests point at the Waiting tab.
    const [tab, setTab] = useState<Tab>(params.get("view") === "all" ? "all" : "waiting");

    if (isLoading) return <p className="p-6 text-sm text-surface-400">Loading…</p>;
    if (!caps?.can_approve) {
        return (
            <div className="card p-6 text-sm text-surface-600">
                Only the owner and the managers he delegates to approve downloads.
            </div>
        );
    }

    const tabs: [Tab, string][] = caps.is_owner
        ? [["waiting", `Waiting${caps.pending ? ` (${caps.pending})` : ""}`], ["all", "Every download"], ["approvers", "Approvers"]]
        : [["waiting", `Waiting${caps.pending ? ` (${caps.pending})` : ""}`]];

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header">
                <h1 className="page-title">Download approvals</h1>
                <p className="page-subtitle">
                    Every file taken out of Bethany Hub, except invoices, quotations and receipts, waits here for approval.
                    {!caps.enforcing && " Approval is recording-only for now: downloads are not held yet, but each one is recorded."}
                </p>
            </div>
            <div className="flex gap-1 border-b border-surface-200">
                {tabs.map(([k, label]) => (
                    <button
                        key={k}
                        onClick={() => setTab(k)}
                        className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-surface-500 hover:text-surface-700"}`}
                    >{label}</button>
                ))}
            </div>
            {tab === "waiting" && <Waiting openUuid={openUuid} />}
            {tab === "all" && caps.is_owner && <Ledger openUuid={openUuid} />}
            {tab === "approvers" && caps.is_owner && <Approvers />}
        </div>
    );
}
