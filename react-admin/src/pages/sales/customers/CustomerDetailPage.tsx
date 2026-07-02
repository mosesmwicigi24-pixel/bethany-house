import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { customersApi } from "@/api/customers";
import type { Customer } from "@/api/customers";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

// ── Helpers ───────────────────────────────────────────────────────────────────

function Avatar({ name, size = "lg" }: { name: string; size?: "sm" | "lg" }) {
    const initials = name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();
    const colors = ["bg-brand-100 text-brand-700", "bg-success-light text-success-dark", "bg-purple-100 text-purple-700", "bg-warning-light text-warning-dark", "bg-info-light text-info"];
    const color = colors[name.charCodeAt(0) % colors.length];
    return (
        <div className={clsx("rounded-full flex items-center justify-center font-bold shrink-0", color,
            size === "lg" ? "w-16 h-16 text-xl" : "w-8 h-8 text-xs")}>
            {initials}
        </div>
    );
}

function Section({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
    return (
        <div className="card overflow-hidden">
            <div className="card-header">
                <h3 className="font-semibold text-sm text-surface-900">{title}</h3>
                {action}
            </div>
            <div className="card-body">{children}</div>
        </div>
    );
}

function StatCard({ label, value, sub, color }: { label: string; value: string | number; sub?: string; color?: string }) {
    return (
        <div className="bg-surface-50 rounded-xl p-3 text-center">
            <p className="text-2xs text-surface-400">{label}</p>
            <p className={clsx("font-bold text-lg mt-0.5", color ?? "text-surface-900")}>{value}</p>
            {sub && <p className="text-2xs text-surface-400 mt-0.5">{sub}</p>}
        </div>
    );
}

const ORDER_STATUS_COLORS: Record<string, string> = {
    pending_payment: "badge-warning",
    paid:            "badge-info",
    processing:      "badge-info",
    shipped:         "badge-info",
    delivered:       "badge-success",
    completed:       "badge-success",
    cancelled:       "badge-danger",
    refunded:        "badge-neutral",
    voided:          "badge-neutral",
};

// ── Main page ─────────────────────────────────────────────────────────────────

export default function CustomerDetailPage() {
    const { id }   = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast    = useToastStore();
    const qc       = useQueryClient();

    const [activeTab, setActiveTab] = useState<"orders" | "addresses" | "notes">("orders");
    const [statusDropdown, setStatusDropdown] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["customer", id],
        queryFn:  () => customersApi.get(Number(id)),
        enabled:  !!id,
    });
    const customer = data?.customer;
    const stats    = data?.stats;

    const { data: ordersData } = useQuery({
        queryKey: ["customer-orders", id],
        queryFn:  () => customersApi.orders(Number(id)),
        enabled:  !!id && activeTab === "orders",
    });
    const orders = ordersData?.data ?? [];

    const statusMutation = useMutation({
        mutationFn: (status: "active" | "inactive" | "suspended") =>
            customersApi.updateStatus(Number(id), status),
        onSuccess: () => {
            toast.success("Status updated");
            qc.invalidateQueries({ queryKey: ["customer", id] });
            qc.invalidateQueries({ queryKey: ["customers"] });
            setStatusDropdown(false);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const fmt = (n: number | null | undefined) =>
        n == null ? "-" : n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    if (!customer) return <div className="text-center text-surface-400 py-20">Customer not found.</div>;

    const fullName = customer.user
        ? `${customer.user.first_name} ${customer.user.last_name}`
        : `${customer.first_name} ${customer.last_name}`;

    return (
        <div className="flex flex-col gap-5 animate-fade-in max-w-7xl mx-auto">
            {/* ── Header ──────────────────────────────────────────────────── */}
            <div className="flex items-start gap-4">
                <button onClick={() => navigate("/sales/customers")} className="btn-ghost btn-icon btn-sm mt-1">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                </button>
                {/* Profile header card */}
                <div className="card flex-1 p-5">
                    <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4">
                        <Avatar name={fullName} size="lg" />
                        <div className="flex-1 min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold text-surface-900">{fullName}</h1>
                                <span className={clsx(
                                    "badge text-2xs capitalize",
                                    (customer.status ?? customer.user?.status) === "active" ? "badge-success" :
                                    customer.user?.status === "suspended" ? "badge-danger" : "badge-neutral",
                                )}>
                                    {customer.user?.status ?? customer.status}
                                </span>
                                {customer.customer_type === "business" && (
                                    <span className="badge badge-info text-2xs flex items-center gap-1"><svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg> Business</span>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-xs text-surface-500">
                                <span>{customer.user?.email ?? customer.email}</span>
                                {(customer.user?.phone ?? customer.phone) && <span>{customer.user?.phone ?? customer.phone}</span>}
                                {customer.company && <span>{customer.company}</span>}
                                <span>Member since {new Date(customer.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>
                            </div>
                        </div>
                        {/* Status action */}
                        <div className="flex gap-2 flex-wrap shrink-0">
                            <div className="relative">
                                <button
                                    onClick={() => setStatusDropdown((v) => !v)}
                                    className="btn-secondary btn-sm"
                                >
                                    Change Status
                                    <svg className="w-3 h-3 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                {statusDropdown && (
                                    <div className="absolute right-0 top-full mt-1 bg-white rounded-xl shadow-lg border border-surface-100 z-20 min-w-36 overflow-hidden">
                                        {(["active", "inactive", "suspended"] as const).filter((s) => s !== (customer.user?.status ?? customer.status)).map((s) => (
                                            <button
                                                key={s}
                                                onClick={() => statusMutation.mutate(s)}
                                                className="w-full px-4 py-2.5 text-left text-xs hover:bg-surface-50 capitalize transition-colors"
                                            >
                                                Set {s}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <button onClick={() => navigate(`/sales/orders?customer_id=${id}`)} className="btn-ghost btn-sm">
                                View Orders
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* ── Stats ───────────────────────────────────────────────────── */}
            {stats && (
                <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                    <StatCard label="Total Orders"   value={stats.total_orders}                    />
                    <StatCard label="Total Spent"    value={`KES ${fmt(stats.total_spent)}`} color="text-brand-600" />
                    <StatCard label="Avg. Order"     value={`KES ${fmt(stats.average_order_value)}`} />
                    <StatCard label="Online Orders"  value={stats.online_orders}  color="text-info"    />
                    <StatCard label="POS Orders"     value={stats.pos_orders}     color="text-success" />
                    <StatCard label="Cancelled"      value={stats.cancelled_orders} color="text-danger" />
                    <StatCard label="Last Order"     value={stats.last_order_date
                        ? new Date(stats.last_order_date).toLocaleDateString("en-KE", { dateStyle: "medium" })
                        : "-"} />
                </div>
            )}

            {/* ── Tabs ────────────────────────────────────────────────────── */}
            <div className="flex gap-1 border-b border-surface-200 overflow-x-auto no-scrollbar">
                {(["orders", "addresses", "notes"] as const).map((tab) => (
                    <button
                        key={tab}
                        onClick={() => setActiveTab(tab)}
                        className={clsx(
                            "px-4 py-2.5 text-sm font-medium capitalize border-b-2 -mb-px transition-colors whitespace-nowrap shrink-0",
                            activeTab === tab
                                ? "border-brand-500 text-brand-600"
                                : "border-transparent text-surface-500 hover:text-surface-700",
                        )}
                    >
                        {tab}
                    </button>
                ))}
            </div>

            {/* ── Orders tab ──────────────────────────────────────────────── */}
            {activeTab === "orders" && (
                <div className="card overflow-hidden">
                    {orders.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-32 gap-2 text-surface-400">
                            <p className="text-sm">No orders yet</p>
                        </div>
                    ) : (
                        <div className="table-wrapper rounded-none border-0 overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Channel</th>
                                        <th>Status</th>
                                        <th>Payment</th>
                                        <th className="text-right">Total</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.map((order) => (
                                        <tr key={order.id} onClick={() => navigate(`/sales/orders/${order.id}`)} className="cursor-pointer">
                                            <td><span className="font-mono text-xs font-semibold text-brand-600">{order.order_number}</span></td>
                                            <td><span className="text-xs text-surface-500 flex items-center gap-1">{(order.order_type ?? (order as any).channel) === "pos" ? <><svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"/></svg>POS</> : <><svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>Online</>}</span></td>
                                            <td>
                                                <span className={clsx("badge text-2xs capitalize", ORDER_STATUS_COLORS[order.status] ?? "badge-neutral")}>
                                                    {order.status.replace("_", " ")}
                                                </span>
                                            </td>
                                            <td><span className="text-xs text-surface-500 capitalize">{order.payment_method?.replace("_", " ")}</span></td>
                                            <td className="text-right"><span className="text-xs font-semibold">{order.currency_code} {fmt(order.total_amount)}</span></td>
                                            <td><span className="text-xs text-surface-400">{new Date(order.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {/* ── Addresses tab ───────────────────────────────────────────── */}
            {activeTab === "addresses" && (
                <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {(customer.addresses ?? []).length === 0 ? (
                        <div className="col-span-full text-center text-surface-400 py-12 text-sm">No saved addresses.</div>
                    ) : (
                        customer.addresses!.map((addr) => (
                            <div key={addr.id} className="card card-body text-xs space-y-1 relative">
                                {addr.is_default && (
                                    <span className="absolute top-3 right-3 badge-success text-2xs">Default</span>
                                )}
                                <p className="font-semibold text-surface-900">{addr.name}</p>
                                <p className="text-surface-600">{addr.address_line_1}</p>
                                {addr.address_line_2 && <p className="text-surface-600">{addr.address_line_2}</p>}
                                <p className="text-surface-600">{addr.city}{addr.state ? `, ${addr.state}` : ""}</p>
                                <p className="text-surface-600">{addr.country} {addr.postal_code}</p>
                                <p className="text-surface-400 pt-1">{addr.phone}</p>
                            </div>
                        ))
                    )}
                </div>
            )}

            {/* ── Notes tab ───────────────────────────────────────────────── */}
            {activeTab === "notes" && (
                <div className="card card-body">
                    {customer.notes ? (
                        <p className="text-sm text-surface-700 whitespace-pre-line">{customer.notes}</p>
                    ) : (
                        <p className="text-sm text-surface-400">No internal notes.</p>
                    )}
                </div>
            )}
        </div>
    );
}