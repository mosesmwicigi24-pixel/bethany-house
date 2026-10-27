import { useEffect, useState } from "react";
import { useInfiniteQuery } from "@tanstack/react-query";
import { peopleApi, type PersonRow } from "@/api/people";

const PAGE = 25;

/**
 * Pick a staff member: search by name or email, 25 at a time, "Show more" for
 * the next 25 (owner, 2026-10-27: "make it show in batches of 25").
 * The people list returns a Laravel paginator (current_page / last_page at the
 * top level); both that and a { meta } shape are read.
 */
export default function PersonPicker({ value, onChange }: { value: string; onChange: (id: string, person?: PersonRow) => void }) {
    const [q, setQ] = useState("");
    const [term, setTerm] = useState("");
    useEffect(() => { const t = setTimeout(() => setTerm(q.trim()), 300); return () => clearTimeout(t); }, [q]);

    const query = useInfiniteQuery({
        queryKey: ["people", "picker", term],
        initialPageParam: 1,
        queryFn: ({ pageParam }) => peopleApi.list({ q: term || undefined, page: String(pageParam), per_page: String(PAGE) }),
        getNextPageParam: (last) => {
            const r = last as unknown as { current_page?: number; last_page?: number; meta?: { current_page: number; last_page: number } };
            const cur = r.meta?.current_page ?? r.current_page ?? 1;
            const end = r.meta?.last_page ?? r.last_page ?? 1;
            return cur < end ? cur + 1 : undefined;
        },
    });
    const people = query.data?.pages.flatMap((p) => p.data) ?? [];
    const total = (() => {
        const r = query.data?.pages[0] as unknown as { total?: number; meta?: { total: number } } | undefined;
        return r?.meta?.total ?? r?.total;
    })();

    return (
        <div className="mt-1 rounded-xl border border-line">
            <input
                type="search" className="input rounded-b-none border-0 border-b border-line" placeholder="Search by name or email…"
                value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search people"
            />
            <div className="max-h-56 overflow-y-auto py-1" role="listbox" aria-label="People">
                {query.isLoading && <p className="px-3 py-2 text-xs text-surface-400">Loading people…</p>}
                {query.isError && <p className="px-3 py-2 text-xs text-red-600">Couldn't load people — close and try again.</p>}
                {!query.isLoading && !query.isError && people.length === 0 && (
                    <p className="px-3 py-2 text-xs text-surface-400">{term ? `No one matches "${term}".` : "No people yet."}</p>
                )}
                {people.map((p) => {
                    const on = String(p.id) === value;
                    return (
                        <button
                            key={p.id} type="button" role="option" aria-selected={on}
                            onClick={() => onChange(String(p.id), p)}
                            className={`flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm hover:bg-surface-50 ${on ? "bg-brand-50 font-semibold text-brand-700" : "text-surface-800"}`}
                        >
                            <span className="truncate">{p.name}</span>
                            {p.job_title && <span className="shrink-0 text-2xs text-surface-400">{p.job_title}</span>}
                        </button>
                    );
                })}
                {query.hasNextPage && (
                    <button type="button" onClick={() => void query.fetchNextPage()} disabled={query.isFetchingNextPage}
                        className="w-full px-3 py-1.5 text-left text-xs font-medium text-brand-600 hover:bg-surface-50">
                        {query.isFetchingNextPage ? "Loading…" : `Show more${total ? ` (${people.length} of ${total})` : ""}`}
                    </button>
                )}
            </div>
        </div>
    );
}
