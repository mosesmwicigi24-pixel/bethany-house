import { useState, useCallback, useEffect, Fragment } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { ordersApi } from "@/api/orders";
import type { Order, OrderStatus, OrderFilters } from "@/api/orders";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_CONFIG: Record<string, { label: string; dot: string; badge: string }> = {
    pending: { label: "Pending Payment", dot: "bg-warning",  badge: "badge-warning" },
    paid:            { label: "Paid",             dot: "bg-info",     badge: "badge-info"    },
    processing:      { label: "Processing",       dot: "bg-brand-500",badge: "badge-info"    },
    shipped:         { label: "Shipped",          dot: "bg-brand-600",badge: "badge-info"    },
    delivered:       { label: "Delivered",        dot: "bg-success",  badge: "badge-success" },
    completed:       { label: "Completed",        dot: "bg-success",  badge: "badge-success" },
    cancelled:       { label: "Cancelled",        dot: "bg-danger",   badge: "badge-danger"  },
    refunded:        { label: "Refunded",         dot: "bg-surface-400", badge: "badge-neutral" },
    voided:          { label: "Voided",           dot: "bg-surface-400", badge: "badge-neutral" },
};

const CHANNEL_LABELS: Record<string, { label: string }> = {
    online: { label: "Online" },
    pos:    { label: "POS"    },
};

const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash:          "Cash",
    mpesa:         "M-Pesa",
    card:          "Card",
    bank_transfer: "Bank Transfer",
    cash_on_delivery: "COD",
};

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
    const config = STATUS_CONFIG[status] ?? { label: status, badge: "badge-neutral", dot: "bg-surface-400" };
    return (
        <span className={clsx("badge", config.badge)}>
            <span className={clsx("w-1.5 h-1.5 rounded-full", config.dot)} />
            {config.label}
        </span>
    );
}

// ── Payment link button ───────────────────────────────────────────────────────

function PaymentLinkButton({ order }: { order: Order }) {
    const toast = useToastStore();
    const [loading, setLoading] = useState(false);
    const [link, setLink]       = useState<string | null>(null);

    const unpaidStatuses: string[] = ["pending", "deposit", "partial", "failed"];
    const isUnpaid = unpaidStatuses.includes(order.payment_status);
    if (!isUnpaid) return null;

    const handleClick = async (e: React.MouseEvent) => {
        e.stopPropagation();
        if (link) {
            await navigator.clipboard.writeText(link).catch(() => {});
            toast.success("Copied!");
            return;
        }
        setLoading(true);
        try {
            const res = await ordersApi.getPaymentLink(order.id);
            const url = res.payment_url ?? res.url;
            setLink(url);
            await navigator.clipboard.writeText(url).catch(() => {});
            toast.success("Payment link copied to clipboard!");
        } catch (e: any) {
            toast.error(e.message ?? "Could not generate payment link");
        } finally {
            setLoading(false);
        }
    };

    return (
        <button
            onClick={handleClick}
            disabled={loading}
            title={link ? "Click to copy again" : "Generate & copy payment link"}
            className={clsx(
                "inline-flex items-center justify-center w-7 h-7 rounded-lg border transition-all",
                link
                    ? "border-blue-300 bg-blue-50 text-blue-600 hover:bg-blue-100"
                    : "border-surface-200 bg-white text-surface-400 hover:border-brand-300 hover:text-brand-600"
            )}
        >
            {loading
                ? <Spinner size="xs" />
                : link
                    ? <svg className="w-3.5 h-3.5"
                    aria-label="Copy" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            }
        </button>
    );
}

// ── Stat card ─────────────────────────────────────────────────────────────────

function StatCard({ label, value, sub, color }: { label: string; value: string | number; sub?: string; color?: string }) {
    return (
        <div className="card card-body flex flex-col gap-1">
            <p className="text-xs text-surface-500">{label}</p>
            <p className={clsx("text-xl font-bold", color ?? "text-surface-900")}>{value}</p>
            {sub && <p className="text-2xs text-surface-400">{sub}</p>}
        </div>
    );
}

// ── Filters bar ───────────────────────────────────────────────────────────────

interface FiltersBarProps {
    filters: OrderFilters;
    onChange: (key: keyof OrderFilters, value: string) => void;
    onClear: () => void;
    /** Hide the channel dropdown when the whole page is already scoped to one. */
    hideChannel?: boolean;
}

function FiltersBar({ filters, onChange, onClear, hideChannel }: FiltersBarProps) {
    const hasFilters = Object.values(filters).some(
        (v) => v !== undefined && v !== "" && v !== "created_at" && v !== "desc",
    );

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative flex-1 min-w-48">
                <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input
                    type="text"
                    placeholder="Search order #, customer, email…"
                    value={filters.search ?? ""}
                    onChange={(e) => onChange("search", e.target.value)}
                    className="input pl-9"
                />
            </div>
            <select
                value={filters.status ?? ""}
                onChange={(e) => onChange("status", e.target.value)}
                className="input w-40"
            >
                <option value="">All statuses</option>
                {Object.entries(STATUS_CONFIG).map(([k, v]) => (
                    <option key={k} value={k}>{v.label}</option>
                ))}
            </select>
            {!hideChannel && (
                <select
                    value={filters.channel ?? ""}
                    onChange={(e) => onChange("channel", e.target.value)}
                    className="input w-32"
                >
                    <option value="">All channels</option>
                    <option value="online">Online</option>
                    <option value="pos">POS</option>
                </select>
            )}
            <input
                type="date"
                value={filters.start_date ?? ""}
                onChange={(e) => onChange("start_date", e.target.value)}
                className="input w-36"
                title="From date"
            />
            <input
                type="date"
                value={filters.end_date ?? ""}
                onChange={(e) => onChange("end_date", e.target.value)}
                className="input w-36"
                title="To date"
            />
            {hasFilters && (
                <button onClick={onClear} className="btn-ghost btn-sm text-danger">
                    Clear
                </button>
            )}
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

type SalesChannel = "pos" | "online" | "whatsapp";

const CHANNEL_TITLES: Record<SalesChannel, { title: string; subtitle: string }> = {
    pos:      { title: "POS Orders",      subtitle: "orders taken at the point of sale" },
    online:   { title: "Online Orders",   subtitle: "orders placed through the storefront" },
    whatsapp: { title: "WhatsApp Orders", subtitle: "orders taken over WhatsApp" },
};

export default function OrdersPage({ channel }: { channel?: SalesChannel } = {}) {
    const navigate   = useNavigate();
    const toast      = useToastStore();
    const qc         = useQueryClient();
    const _ts = useTableState();
    const page = _ts.state.page;
    const setPage = _ts.setPage;

    // A channel-scoped view (POS / Online / WhatsApp Orders) locks sales_channel.
    const baseFilters = useCallback((): OrderFilters => ({
        sort_by: "created_at",
        sort_order: "desc",
        per_page: 25,
        ...(channel ? { sales_channel: channel } : {}),
    }), [channel]);

    const [filters, setFilters] = useState<OrderFilters>(baseFilters);

    // Re-scope when navigating between channel views (component instance reused).
    useEffect(() => { setFilters(baseFilters()); setPage(1); }, [channel, baseFilters, setPage]);

    const updateFilter = useCallback((key: keyof OrderFilters, value: string) => {
        setFilters((prev) => ({ ...prev, [key]: value || undefined }));
        setPage(1);
    }, [setPage]);

    const clearFilters = useCallback(() => {
        setFilters(baseFilters());
        setPage(1);
    }, [setPage, baseFilters]);

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["orders", filters, page],
        queryFn:  () => ordersApi.list({ ...filters, page }),
        placeholderData: (prev) => prev,
    });

    const orders = data?.data ?? [];
    const meta   = data?.meta;
    const stats  = data?.stats;

    // Group the current page of rows by created_at. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const orderGroups = groupRowsByDate(orders, (order) => order.created_at);

    const fmt = (n: number | null | undefined) =>
        (n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 });

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* ── Header ──────────────────────────────────────────────────── */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">{channel ? CHANNEL_TITLES[channel].title : "Orders"}</h1>
                    <p className="page-subtitle">
                        {meta ? `${meta.total.toLocaleString()} ${channel ? CHANNEL_TITLES[channel].subtitle : "orders"}` : ""}
                        {isFetching && !isLoading && (
                            <span className="ml-2 text-brand-500 text-xs">Refreshing…</span>
                        )}
                    </p>
                </div>
                <button
                    onClick={() => {/* export */}}
                    className="btn-secondary btn-sm gap-1.5"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Export CSV
                </button>
            </div>

            {/* ── KPI stats ───────────────────────────────────────────────── */}
            {stats && (
                <div className="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-7 gap-3">
                    <StatCard label="Total Orders"     value={stats.total_orders.toLocaleString()}                          />
                    <StatCard label="Total Revenue"    value={`KES ${fmt(stats.total_revenue)}`}  color="text-brand-600"    />
                    <StatCard label="Today's Orders"   value={stats.today_orders}                  sub={`KES ${fmt(stats.today_revenue)}`} />
                    <StatCard label="Avg. Order Value" value={`KES ${fmt(stats.avg_order_value)}`}                          />
                    <StatCard label="Pending Payment"  value={stats.pending_count}                 color="text-warning"      />
                    <StatCard label="Processing"       value={stats.processing_count}              color="text-brand-600"   />
                    <StatCard label="Cancelled"        value={stats.cancelled_count}               color="text-danger"      />
                </div>
            )}

            {/* ── Filters ─────────────────────────────────────────────────── */}
            <div className="card card-body">
                <FiltersBar filters={filters} onChange={updateFilter} onClear={clearFilters} hideChannel={!!channel} />
            </div>

            {/* ── Table ───────────────────────────────────────────────────── */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex items-center justify-center h-48">
                        <Spinner size="lg" />
                    </div>
                ) : orders.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-48 gap-3 text-surface-400">
                        <svg className="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={0.8}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                        </svg>
                        <p className="text-sm">No orders found</p>
                    </div>
                ) : (
                    <div className="table-wrapper rounded-none border-0">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Channel</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Items</th>
                                    <th className="text-right">Total</th>
                                    <th>Date</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {orderGroups.map((group) => (
                                    <Fragment key={group.key}>
                                        <DateGroupHeaderRow label={group.label} colSpan={9} />
                                        {group.items.map((order) => (
                                    <tr
                                        key={order.id}
                                        onClick={() => navigate(`/sales/orders/${order.id}`)}
                                        className="cursor-pointer"
                                    >
                                        <td>
                                            <span className="font-mono text-xs font-semibold text-brand-600">
                                                {order.order_number}
                                            </span>
                                        </td>
                                        <td>
                                            <div>
                                                <p className="font-medium text-surface-900 text-xs">
                                                    {order.customer_name ?? "-"}
                                                </p>
                                                {(() => {
                                                    const email = order.customer_email && !order.customer_email.startsWith('noemail+') ? order.customer_email : null;
                                                    const sub = email ?? order.customer_phone ?? null;
                                                    return sub ? (
                                                        <p className="text-2xs text-surface-400 truncate max-w-[160px]">{sub}</p>
                                                    ) : null;
                                                })()}
                                            </div>
                                        </td>
                                        <td>
                                            <span className="text-xs text-surface-500 flex items-center gap-1">
                                                {order.order_type === "pos"
                                                    ? <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"/></svg>
                                                    : <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                                                }
                                                {CHANNEL_LABELS[order.order_type]?.label ?? order.order_type}
                                            </span>
                                        </td>
                                        <td><StatusBadge status={order.status} /></td>
                                        <td>
                                            <span className="text-xs text-surface-600">
                                                {PAYMENT_METHOD_LABELS[order.payment_method] ?? order.payment_method}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="text-xs text-surface-600">
                                                {order.items.length} item{order.items.length !== 1 ? "s" : ""}
                                            </span>
                                        </td>
                                        <td className="text-right">
                                            <span className="font-semibold text-surface-900 text-xs">
                                                {order.currency_code} {fmt(order.total_amount)}
                                            </span>
                                            {(order as any).is_international && (
                                                <span className="ml-1.5 text-2xs font-semibold text-blue-600" title="International order">🌐</span>
                                            )}
                                        </td>
                                        <td>
                                            <span className="text-xs text-surface-500 whitespace-nowrap">
                                                {new Date(order.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-1.5 justify-end">
                                                <PaymentLinkButton order={order} />
                                                <svg className="w-4 h-4 text-surface-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                </svg>
                                            </div>
                                        </td>
                                    </tr>
                                        ))}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-surface-500">
                            Page {meta.current_page} of {meta.last_page} · {meta.total.toLocaleString()} orders
                        </p>
                        <div className="flex gap-1">
                            <button
                                onClick={() => setPage(Math.max(1, page - 1))}
                                disabled={page <= 1}
                                className="btn-secondary btn-sm"
                            >
                                ← Prev
                            </button>
                            {Array.from({ length: Math.min(5, meta.last_page) }, (_, i) => {
                                const pg = Math.max(1, Math.min(meta.last_page - 4, page - 2)) + i;
                                return (
                                    <button
                                        key={pg}
                                        onClick={() => setPage(pg)}
                                        className={clsx(
                                            "btn btn-sm w-8 h-8 p-0",
                                            pg === page ? "btn-primary" : "btn-secondary",
                                        )}
                                    >
                                        {pg}
                                    </button>
                                );
                            })}
                            <button
                                onClick={() => setPage(Math.min(meta.last_page, page + 1))}
                                disabled={page >= meta.last_page}
                                className="btn-secondary btn-sm"
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}