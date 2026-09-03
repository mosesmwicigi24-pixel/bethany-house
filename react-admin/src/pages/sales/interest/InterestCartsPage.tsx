/**
 * InterestCartsPage — /sales/interest-carts
 *
 * The interest ledger: every cart Neema mirrors in from the website and
 * social channels, keyed by the cross-channel token ("BH-XXXX") that the
 * WhatsApp handoff message hands the customer. A customer writes "cart
 * BH-0QVP2358" — staff paste it here and see their items in five seconds.
 *
 * Pipeline, not sales: these are expressions of interest. Nothing here is
 * revenue, cash, or a receivable, and the subtotal is the cart snapshot the
 * customer saw — not a figure the business reports.
 */

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get } from "@/api/client";
import { Spinner } from "@/components/ui/Spinner";

interface InterestItem {
    slug: string;
    quantity: number;
    size?: string;
    measurements?: Record<string, string>;
}

interface InterestCartRow {
    id: number;
    token: string;
    channel: string;
    last_channel: string | null;
    status: string;
    name: string | null;
    phone: string | null;
    church: string | null;
    items: InterestItem[];
    subtotal: string | null;
    currency: string | null;
    order_ref: string | null;
    source_path: string | null;
    updated_at: string;
}

interface Paged {
    data: InterestCartRow[];
    current_page: number;
    last_page: number;
    total: number;
}

const STATUS_CHIP: Record<string, string> = {
    active_cart:      "bg-brand-50 text-brand-700 border-brand-200",
    checkout_started: "bg-warning-light text-warning-dark border-warning/30",
    online_order:     "bg-success-light text-success-dark border-success/30",
    whatsapp_order:   "bg-success-light text-success-dark border-success/30",
    abandoned:        "bg-surface-100 text-surface-500 border-surface-200",
};

const STATUS_LABEL: Record<string, string> = {
    active_cart:      "Active cart",
    checkout_started: "Checkout started",
    online_order:     "Ordered online",
    whatsapp_order:   "Ordered on WhatsApp",
    abandoned:        "Abandoned",
};

const CHANNELS = ["web", "whatsapp", "messenger", "instagram", "facebook"];

function deslug(slug: string): string {
    return slug.replace(/-/g, " ").replace(/\b\w/g, c => c.toUpperCase());
}

export default function InterestCartsPage() {
    const [q, setQ]           = useState("");
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("");
    const [channel, setChannel] = useState("");
    const [page, setPage]     = useState(1);

    const { data, isLoading } = useQuery<Paged>({
        queryKey: ["interest-carts", search, status, channel, page],
        queryFn: () => get<Paged>("/v1/admin/interest-carts", {
            params: {
                q:        search || undefined,
                status:   status || undefined,
                channel:  channel || undefined,
                page:     String(page),
            } as any,
        }),
        placeholderData: prev => prev,
    });

    const rows = data?.data ?? [];

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            <div>
                <h1 className="page-title">Interest Carts</h1>
                <p className="page-subtitle">
                    Carts from the website and Neema — paste the "cart BH-…" code from a
                    customer's WhatsApp message to see what they were buying.
                </p>
            </div>

            {/* Search + filters */}
            <form
                onSubmit={e => { e.preventDefault(); setSearch(q.trim()); setPage(1); }}
                className="flex flex-col sm:flex-row gap-2"
            >
                <input
                    value={q}
                    onChange={e => setQ(e.target.value)}
                    placeholder="BH-0QVP2358, phone, or name…"
                    className="input flex-1"
                    aria-label="Search interest carts"
                />
                <div className="flex gap-2">
                    <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} className="input" aria-label="Status">
                        <option value="">All statuses</option>
                        {Object.entries(STATUS_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <select value={channel} onChange={e => { setChannel(e.target.value); setPage(1); }} className="input capitalize" aria-label="Channel">
                        <option value="">All channels</option>
                        {CHANNELS.map(c => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <button type="submit" className="btn-primary btn-sm px-4">Search</button>
                </div>
            </form>

            <div className="card overflow-hidden p-0">
                {isLoading ? (
                    <div className="flex items-center justify-center py-20"><Spinner size="lg" /></div>
                ) : rows.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 px-6 text-center text-surface-400 gap-2">
                        <p className="text-sm font-medium text-surface-500">
                            {search ? "No cart matches that search" : "No interest carts yet"}
                        </p>
                        <p className="text-xs max-w-sm">
                            {search
                                ? "Check the code for typos — tokens look like BH-0QVP2358. Carts started before this ledger went live were never captured."
                                : "As customers build carts on the website, they appear here automatically."}
                        </p>
                    </div>
                ) : (
                    <div className="divide-y divide-line">
                        {rows.map(r => (
                            <div key={r.id} className="px-4 sm:px-5 py-3.5 flex flex-col gap-2">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-mono text-sm font-bold text-surface-900">{r.token}</span>
                                    <span className={clsx(
                                        "text-2xs font-bold px-2 py-0.5 rounded-full border",
                                        STATUS_CHIP[r.status] ?? STATUS_CHIP.abandoned,
                                    )}>
                                        {STATUS_LABEL[r.status] ?? r.status}
                                    </span>
                                    <span className="text-2xs font-semibold text-surface-500 capitalize bg-surface-100 border border-surface-200 rounded-full px-2 py-0.5">
                                        {r.channel}
                                    </span>
                                    <span className="text-2xs text-surface-400 ml-auto whitespace-nowrap">
                                        {new Date(r.updated_at).toLocaleString("en-KE", { dateStyle: "medium", timeStyle: "short" })}
                                    </span>
                                </div>

                                <div className="text-sm text-surface-700">
                                    {(r.items ?? []).map((it, i) => (
                                        <span key={i}>
                                            {i > 0 && <span className="text-surface-300"> · </span>}
                                            {deslug(it.slug)}
                                            <span className="text-surface-400"> ×{it.quantity}{it.size ? ` (${it.size})` : ""}</span>
                                        </span>
                                    ))}
                                </div>

                                <div className="flex items-center gap-3 flex-wrap text-xs text-surface-500">
                                    {(r.name || r.phone) && (
                                        <span className="font-medium text-surface-700">
                                            {r.name ?? "Unknown name"}{r.phone ? ` · ${r.phone}` : ""}
                                        </span>
                                    )}
                                    {!r.name && !r.phone && (
                                        <span className="italic">No contact yet — ask on WhatsApp</span>
                                    )}
                                    {r.subtotal !== null && (
                                        <span>Cart value {r.currency ?? ""} {Number(r.subtotal).toLocaleString()}</span>
                                    )}
                                    {r.order_ref && (
                                        <span>→ order <span className="font-mono font-semibold text-surface-700">{r.order_ref}</span></span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {data && data.last_page > 1 && (
                    <div className="px-5 py-3 border-t border-line flex items-center justify-between">
                        <p className="text-xs text-surface-400">Page {data.current_page} of {data.last_page} · {data.total.toLocaleString()} carts</p>
                        <div className="flex gap-2">
                            <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1} className="btn-secondary btn-sm">← Prev</button>
                            <button onClick={() => setPage(p => Math.min(data.last_page, p + 1))} disabled={page >= data.last_page} className="btn-secondary btn-sm">Next →</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
