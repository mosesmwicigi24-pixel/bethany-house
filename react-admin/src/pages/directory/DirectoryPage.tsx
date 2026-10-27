import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { directoryApi, type Colleague, type DirectoryMe } from "@/api/directory";
import { channelApi } from "@/api/channels";
import { useNavigate } from "react-router-dom";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** /colleagues — the staff directory (People C5): who is who, how to reach them, and my own switches. */
export default function DirectoryPage() {
    const [q, setQ] = useState("");
    const [dept, setDept] = useState("");
    const navigate = useNavigate();
    const qc = useQueryClient();
    const toast = useToastStore();
    const list = useQuery({ queryKey: ["directory", q], queryFn: () => directoryApi.list(q), staleTime: 60_000 });
    const save = useMutation({ mutationFn: (d: Partial<DirectoryMe>) => directoryApi.saveMine(d), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["directory"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    const dm = useMutation({ mutationFn: (id: number) => channelApi.openDm(id), onSuccess: r => navigate(`/comms?channel=${r.channel.id}`), onError: (e: ApiError) => toast.error(e.message) });
    const rows = (list.data?.data ?? []).filter(c => !dept || c.department === dept);
    const me = list.data?.me;
    return (
        <div className="mx-auto w-full max-w-3xl px-4 py-4 sm:py-8 space-y-4 animate-fade-in">
            <div><h1 className="page-title">Colleagues</h1><p className="page-subtitle">Everyone at Bethany House and how to reach them.</p></div>
            <div className="flex flex-wrap gap-2">
                <input className="input flex-1 min-w-[200px]" placeholder="Search by name, job or department…" value={q} onChange={e => setQ(e.target.value)} />
                {(list.data?.departments.length ?? 0) > 0 && <select className="input" value={dept} onChange={e => setDept(e.target.value)}><option value="">All departments</option>{list.data!.departments.map(d => <option key={d} value={d}>{d}</option>)}</select>}
            </div>
            {me && (
                <div className="rounded-2xl bg-surface-50 border border-line px-4 py-3 text-xs text-surface-600 flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span className="font-semibold text-surface-800">What colleagues see about you:</span>
                    {([["show_phone", "my phone"], ["show_email", "my email"], ["show_birthday", "my birthday"]] as const).map(([k, l]) => <label key={k} className="inline-flex items-center gap-1.5"><input type="checkbox" checked={me[k]} onChange={e => save.mutate({ [k]: e.target.checked })} />{l}</label>)}
                    <span className="text-surface-400">Your name, job, department and outlet always show.</span>
                </div>
            )}
            {list.isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {rows.map(c => <Card key={c.id} c={c} onMessage={() => dm.mutate(c.id)} />)}
                    {rows.length === 0 && <p className="text-sm text-surface-500 py-8 text-center col-span-2">Nobody matches.</p>}
                </div>
            )}
        </div>
    );
}

function Card({ c, onMessage }: { c: Colleague; onMessage: () => void }) {
    return (
        <div className={clsx("rounded-2xl bg-white border p-4 flex gap-3", c.is_me ? "border-brand-200" : "border-line")}>
            {c.avatar_url ? <img src={c.avatar_url} alt="" className="w-12 h-12 rounded-full object-cover shrink-0" /> : <span className="w-12 h-12 rounded-full bg-brand-500/15 text-brand-700 font-bold flex items-center justify-center shrink-0">{c.initials}</span>}
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-surface-900 truncate">{c.name}{c.is_me && <span className="ml-2 text-2xs uppercase text-brand-600">you</span>}</p>
                <p className="text-xs text-surface-500 truncate">{[c.job_title, c.department].filter(Boolean).join(" · ") || "—"}{c.outlets.length ? ` · ${c.outlets.join(", ")}` : ""}</p>
                <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                    {c.phone && <a href={`tel:${c.phone}`} className="text-brand-600">📞 {c.phone}</a>}
                    {c.email && <a href={`mailto:${c.email}`} className="text-brand-600 truncate">✉️ {c.email}</a>}
                    {c.birthday && <span className="text-surface-500">🎂 {c.birthday}</span>}
                    {c.since && <span className="text-surface-400">since {c.since}</span>}
                </div>
                {!c.is_me && <button onClick={onMessage} className="mt-2 text-xs font-semibold text-brand-600">Message ›</button>}
            </div>
        </div>
    );
}
