import { useState } from "react";
import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleApi, type PersonRow } from "@/api/people";
import { Spinner } from "@/components/ui/Spinner";

/** People (Bethany People PR 2): the staff list, each row a door into the employee file. */

const TYPE_LABEL: Record<string, string> = { permanent: "Permanent", contract: "Contract", casual: "Casual", intern: "Intern" };

function Avatar({ p }: { p: PersonRow }) {
    const initials = p.name.split(" ").map(s => s[0]).filter(Boolean).slice(0, 2).join("").toUpperCase();
    return p.avatar_url
        ? <img src={p.avatar_url} alt="" className="w-9 h-9 rounded-full object-cover" />
        : <div className="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-bold">{initials}</div>;
}

export default function PeoplePage() {
    const [q, setQ] = useState("");
    const [department, setDepartment] = useState("");
    const [page, setPage] = useState(1);

    const { data, isLoading } = useQuery({
        queryKey: ["people", q, department, page],
        queryFn: () => peopleApi.list({ q: q || undefined, department: department || undefined, page: String(page), per_page: "25" }),
    });
    const { data: expiring } = useQuery({ queryKey: ["people-expiring"], queryFn: () => peopleApi.expiring(60) });

    const rows = data?.data ?? [];
    const meta = data as unknown as { current_page: number; last_page: number; total: number } | undefined;
    const departments = data?.departments ?? [];

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">People</h1>
                    <p className="page-subtitle">Employee files for the staff in your outlets: job, dates, emergency contact, documents.</p>
                </div>
                <Link to="/settings/attendance" className="btn-secondary btn-sm self-start">Attendance</Link>
            </div>

            {expiring && expiring.data.length > 0 && (
                <div className="rounded-xl bg-warning-light text-warning text-sm px-4 py-3">
                    <b>{expiring.data.length} document{expiring.data.length === 1 ? "" : "s"}</b> {expiring.data.length === 1 ? "expires" : "expire"} within 60 days or {expiring.data.length === 1 ? "has" : "have"} expired:{" "}
                    {expiring.data.slice(0, 4).map((d, i) => (
                        <span key={d.id}>{i > 0 ? ", " : ""}<Link to={`/people/${d.user?.id}`} className="underline font-semibold">{d.user?.name}</Link> ({d.title})</span>
                    ))}{expiring.data.length > 4 ? ", …" : ""}
                </div>
            )}

            <div className="flex flex-col gap-2 sm:flex-row">
                <input className="input w-full sm:max-w-xs" placeholder="Search by name or email…" value={q} onChange={e => { setQ(e.target.value); setPage(1); }} />
                <select className="input w-full sm:w-52" value={department} onChange={e => { setDepartment(e.target.value); setPage(1); }} aria-label="Department">
                    <option value="">All departments</option>
                    {departments.map(d => <option key={d} value={d}>{d}</option>)}
                </select>
            </div>

            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div>
                ) : rows.length === 0 ? (
                    <p className="p-8 text-center text-sm text-surface-400">No staff match.</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {rows.map(p => (
                            <li key={p.id}>
                                <Link to={`/people/${p.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-surface-50">
                                    <Avatar p={p} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-surface-900 truncate">
                                            {p.name}
                                            {p.status !== "active" && <span className="ml-2 badge">{p.status}</span>}
                                            {p.end_date && <span className="ml-2 text-2xs text-surface-400">left {p.end_date}</span>}
                                        </p>
                                        <p className="text-xs text-surface-500 truncate">
                                            {p.job_title ?? <span className="italic">No job title yet</span>}
                                            {p.department ? ` · ${p.department}` : ""}
                                            {p.employment_type ? ` · ${TYPE_LABEL[p.employment_type] ?? p.employment_type}` : ""}
                                            {p.outlets.length ? ` · ${p.outlets.join(", ")}` : ""}
                                        </p>
                                    </div>
                                    <div className="text-right shrink-0">
                                        <p className="text-xs text-surface-500 hidden sm:block">{p.hire_date ? `Since ${p.hire_date}` : ""}</p>
                                        <p className={clsx("text-2xs", p.expiring_count > 0 ? "text-warning font-semibold" : "text-surface-400")}>
                                            {p.documents_count} doc{p.documents_count === 1 ? "" : "s"}{p.expiring_count > 0 ? ` · ${p.expiring_count} expiring` : ""}
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-line flex items-center justify-between">
                        <p className="text-xs text-surface-500">Page {meta.current_page} of {meta.last_page} · {meta.total} people</p>
                        <div className="flex gap-1">
                            <button disabled={meta.current_page === 1} onClick={() => setPage(meta.current_page - 1)} className="btn-ghost btn-sm text-xs disabled:opacity-40">← Prev</button>
                            <button disabled={meta.current_page === meta.last_page} onClick={() => setPage(meta.current_page + 1)} className="btn-ghost btn-sm text-xs disabled:opacity-40">Next →</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
