import React, { useState, useMemo, useCallback, useEffect, Fragment } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useLocation } from "react-router-dom";
import { clsx } from "clsx";
import { get, post, put } from "@/api/client";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import { useAuthStore } from "@/store/auth.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ═══════════════════════════════════════════════════════════════════════════════
// TYPES
// ═══════════════════════════════════════════════════════════════════════════════

interface ProductionOrder {
    id: number;
    order_number: string;
    product_id: number;
    product_name: string;
    product_image?: string;
    quantity: number;
    status: "draft" | "pending" | "in_progress" | "on_hold" | "qc_pending" | "qc_passed" | "qc_failed" | "completed" | "cancelled";
    priority: "low" | "normal" | "high" | "urgent";
    due_date: string;
    // Used for the "group by date" header rows in the orders list table.
    created_at: string;
    started_at?: string;
    completed_at?: string;
    completion_percentage: number;
    current_stage?: string;
    notes?: string;
    // Type: null = for stock, set = customer order
    customer_order_id?: number | null;
    customer_order?: { order_number: string; customer_first_name?: string | null; customer_last_name?: string | null; customer_phone?: string | null; customer_email?: string | null } | null;
    specifications?: Record<string, string>;
    measurements?: Record<string, string>;
    customer_preferences?: Record<string, string>;
    estimated_completion_date?: string;
    confirmed_at?: string;
    customer_id?: number | null;
    customer?: { id: number; first_name: string; last_name: string } | null;
    assignees?: { user_id: number; role_in_order: string; user: { first_name: string; last_name: string } }[];
    tasks: Task[];
    material_requirements?: MaterialReq[];
    material_allocations?: MaterialAlloc[];
    created_by?: { first_name: string; last_name: string };
    outlet?: { name: string } | null;
}

interface Task {
    id: number;
    production_stage_id: number;
    status: string;
    assigned_to?: number;
    estimated_hours?: number;
    actual_hours?: number;
    started_at?: string;
    completed_at?: string;
    notes?: string;
    stage: { id: number; name: string; slug: string; sort_order: number };
    assigned_to_user?: { first_name: string; last_name: string };
}

interface MaterialReq {
    material_id: number;
    material_name: string;
    material_code: string;
    unit: string;
    required: number;
    available: number;
    is_short: boolean;
    shortage: number;
}

interface MaterialAlloc {
    id: number;
    material_id: number;
    quantity_required: number;
    quantity_allocated: number;
    quantity_used: number;
    material: { id: number; name: string; code: string; unit_of_measure: string };
}

interface ProductionMessage {
    id: number;
    type: "message" | "note" | "system";
    body: string;
    created_at: string;
    user: { id: number; first_name: string; last_name: string; initials: string };
}

interface BomProduct {
    id: number;
    name: string;
    sku: string;
    slug?: string;
    status?: string;
    product_type?: string;
    is_producible?: boolean;
    brand?: string | null;
    variants_count?: number;
    category?: { id: number; name_en: string } | null;
    en_translation?: { name: string; short_description?: string } | null;
    primary_image?: { id: number; image_url: string; alt_text?: string | null } | null;
    base_price?: { regular_price: number; sale_price?: number | null; currency_code: string } | null;
    boms?: Bom[];
}

interface Bom {
    id: number;
    product_id: number;
    version: number;
    is_active: boolean;
    notes?: string;
    total_cost?: number;
    items: BomItem[];
}

interface BomItem {
    id: number;
    material_id: number;
    // Flat fields (legacy / BomTab usage)
    material_name?: string;
    material_code?: string;
    unit_cost?: number;
    total_cost?: number;
    // Nested object returned by BomController
    material?: {
        id: number;
        code: string;
        name: string;
        material_type?: string;
        unit_of_measure: string;
        cost_per_unit: number;
    };
    line_cost?: number;
    quantity: number;
    unit_of_measure: string;
    notes?: string;
    stock_on_hand?: number;
    is_short?: boolean;
}

interface QCRecord {
    id: number;
    production_order_id: number;
    order_number?: string;
    product_name?: string;
    passed: boolean;
    passed_quantity: number;
    failed_quantity: number;
    defect_types?: string[];
    notes?: string;
    checked_by?: string;
    created_at: string;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════════════════════

const STATUS_CFG: Record<string, { label: string; bg: string; text: string; dot: string }> = {
    draft:       { label: "Draft",       bg: "bg-surface-50",      text: "text-surface-400",   dot: "bg-surface-300"  },
    pending:     { label: "Pending",     bg: "bg-surface-100",     text: "text-surface-600",   dot: "bg-surface-400"  },
    in_progress: { label: "In Progress", bg: "bg-brand-50",        text: "text-brand-700",     dot: "bg-brand-500"    },
    on_hold:     { label: "On Hold",     bg: "bg-warning-light",   text: "text-warning-dark",  dot: "bg-warning"      },
    qc_pending:  { label: "QC Pending",  bg: "bg-purple-50",       text: "text-purple-700",    dot: "bg-purple-500"   },
    qc_passed:   { label: "QC Passed",   bg: "bg-success-light",   text: "text-success-dark",  dot: "bg-success"      },
    qc_failed:   { label: "QC Failed",   bg: "bg-danger-light",    text: "text-danger",        dot: "bg-danger"       },
    completed:   { label: "Completed",   bg: "bg-success-light",   text: "text-success-dark",  dot: "bg-success"      },
    cancelled:   { label: "Cancelled",   bg: "bg-surface-100",     text: "text-surface-400",   dot: "bg-surface-300"  },
};

const PRIORITY_CFG: Record<string, { label: string; cls: string }> = {
    low:    { label: "Low",    cls: "text-surface-400 bg-surface-50 border-surface-200"   },
    normal: { label: "Normal", cls: "text-brand-600 bg-brand-50 border-brand-200"         },
    high:   { label: "High",   cls: "text-warning-dark bg-warning-light border-warning/30" },
    urgent: { label: "Urgent", cls: "text-danger bg-danger-light border-danger/30"        },
};

const STAGE_ICONS: Record<string, string> = {
    cutting:      "cut",
    stitching:    "needle",
    sewing:       "needle",
    finishing:    "star",
    quality_check:"search",
    embroidery:   "needle",
    pressing:     "layers",
};

const StageIcon = ({ slug, className = "w-4 h-4" }: { slug?: string; className?: string }) => {
    const s = { className, fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    const name = slug ? (STAGE_ICONS[slug] ?? "gear") : "gear";
    if (name === "cut")    return <svg {...s}><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>;
    if (name === "needle") return <svg {...s}><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>;
    if (name === "star")   return <svg {...s}><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>;
    if (name === "search") return <svg {...s}><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;
    if (name === "layers") return <svg {...s}><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>;
    // gear (default)
    return <svg {...s}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>;
};

const DEFECT_TYPES = [
    "Stitching issue", "Wrong measurements", "Fabric defect",
    "Color inconsistency", "Finishing issue", "Missing component", "Other"
];

const fmtNum = (n: number, dp = 0) => n.toLocaleString("en-KE", { minimumFractionDigits: dp, maximumFractionDigits: dp > 0 ? dp : 3 });
const daysUntil = (d: string) => Math.ceil((new Date(d).getTime() - Date.now()) / 86_400_000);

/**
 * Parse a "key:value, key:value" string into a Record<string,string>.
 * Returns undefined if the input is empty so the backend ignores the field.
 */
function parseMeasurements(raw: string): Record<string, string> | undefined {
    if (!raw.trim()) return undefined;
    const result: Record<string, string> = {};
    raw.split(",").forEach(pair => {
        const [k, ...rest] = pair.split(":");
        const key = k?.trim();
        const val = rest.join(":").trim();
        if (key && val) result[key] = val;
    });
    return Object.keys(result).length > 0 ? result : undefined;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SHARED UI ATOMS
// ═══════════════════════════════════════════════════════════════════════════════

function StatusBadge({ status }: { status: string }) {
    const c = STATUS_CFG[status] ?? { label: status, bg: "bg-surface-100", text: "text-surface-500", dot: "bg-surface-400" };
    return (
        <span className={clsx("inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-2xs font-semibold", c.bg, c.text)}>
            <span className={clsx("w-1.5 h-1.5 rounded-full shrink-0", c.dot)} />
            {c.label}
        </span>
    );
}

function PriorityBadge({ priority }: { priority: string }) {
    const c = PRIORITY_CFG[priority] ?? { label: priority, cls: "text-surface-400 bg-surface-50 border-surface-200" };
    return <span className={clsx("text-2xs font-bold px-1.5 py-0.5 rounded border uppercase tracking-wide", c.cls)}>{c.label}</span>;
}

function OrderTypePill({ isCustomer }: { isCustomer: boolean }) {
    return isCustomer
        ? <span className="inline-flex items-center gap-1 text-2xs font-semibold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600 border border-indigo-100">
            <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
            Custom Order
          </span>
        : <span className="inline-flex items-center gap-1 text-2xs font-semibold px-2 py-0.5 rounded-full bg-teal-50 text-teal-600 border border-teal-100">
            <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0h-1.5m1.5 0h1.5" /></svg>
            For Stock
          </span>;
}

function ProgressBar({ pct, colorClass = "bg-brand-500" }: { pct: number; colorClass?: string }) {
    return (
        <div className="h-1.5 bg-surface-100 rounded-full overflow-hidden">
            <div className={clsx("h-full rounded-full transition-all duration-500", colorClass)}
                style={{ width: `${Math.min(100, Math.max(0, pct))}%` }} />
        </div>
    );
}

function DueBadge({ date }: { date: string }) {
    const d = daysUntil(date);
    if (d < 0)  return <span className="text-2xs font-medium text-danger">Overdue {Math.abs(d)}d</span>;
    if (d === 0) return <span className="text-2xs font-medium text-warning-dark">Due today</span>;
    if (d <= 2)  return <span className="text-2xs font-medium text-warning-dark">Due in {d}d</span>;
    return <span className="text-2xs text-surface-400">{new Date(date).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>;
}

function SectionHead({ title }: { title: string }) {
    return <h4 className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">{title}</h4>;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CREATE ORDER MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function CreateOrderModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
    const toast = useToastStore();
    const [orderType, setOrderType] = useState<"stock" | "customer">("stock");
    const [form, setForm] = useState({
        product_id: "", product_variant_id: "", quantity: "1",
        priority: "normal", due_date: "", customer_order_id: "",
        outlet_id: "", notes: "", specifications: "",
        measurements: "", customer_preferences: "",
    });
    const [materialWarnings, setMaterialWarnings] = useState<MaterialReq[]>([]);
    const set = (k: string, v: string) => setForm(p => ({ ...p, [k]: v }));

    const { data: productsData } = useQuery({
        queryKey: ["production-products-list"],
        queryFn: () => get<any>("/v1/admin/products", { params: { per_page: "200", is_producible: "1" } }),
        staleTime: 60_000,
    });
    const { data: ordersData } = useQuery({
        queryKey: ["open-orders-list"],
        // comma-separated statuses - OrderController now supports whereIn for these
        queryFn: () => get<any>("/v1/admin/orders", { params: { status: "processing,pending,pending_payment", per_page: "200" } }),
        enabled: orderType === "customer",
        staleTime: 30_000,
    });
    const { data: outletsData } = useQuery({
        queryKey: ["outlets-list"],
        queryFn: () => get<any>("/v1/admin/outlets"),
        staleTime: 60_000,
    });

    const products = productsData?.data ?? [];
    const orders   = (ordersData?.data ?? []) as any[];
    const outlets  = outletsData?.data ?? [];

    // When a customer order is selected, auto-fill product/qty from its first item
    const handleOrderSelect = (orderId: string) => {
        set("customer_order_id", orderId);
        if (!orderId) return;
        const o = orders.find((x: any) => String(x.id) === orderId);
        if (!o) return;
        const firstItem = o.items?.[0];
        if (firstItem) {
            if (!form.product_id && firstItem.product_id) set("product_id", String(firstItem.product_id));
            if (!form.quantity || form.quantity === "1") set("quantity", String(firstItem.quantity ?? 1));
        }
    };

    const mutation = useMutation({
        mutationFn: () => post("/v1/admin/production-orders", {
            product_id:           Number(form.product_id),
            product_variant_id:   form.product_variant_id ? Number(form.product_variant_id) : undefined,
            quantity:             Number(form.quantity),
            priority:             form.priority,
            due_date:             form.due_date,
            outlet_id:            form.outlet_id ? Number(form.outlet_id) : undefined,
            notes:                form.notes || undefined,
            // customer order linkage
            is_customer_order:    orderType === "customer",
            customer_order_id:    orderType === "customer" && form.customer_order_id ? Number(form.customer_order_id) : null,
            // measurements as key-value pairs (entered as "waist:32,length:28")
            measurements:         parseMeasurements(form.measurements),
            customer_preferences: parseMeasurements(form.customer_preferences),
        }),
        onSuccess: (res: any) => {
            if (res.warnings?.length) {
                setMaterialWarnings(res.material_requirements?.filter((r: MaterialReq) => r.is_short) ?? []);
            }
            toast.success("Production order created");
            onCreated();
            onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const minDate = new Date(); minDate.setDate(minDate.getDate() + 1);
    const isValid = form.product_id && Number(form.quantity) > 0 && form.due_date &&
        (orderType === "stock" || form.customer_order_id);

    return (
        <Modal open title="New Production Order" onClose={onClose} size="lg">
            <div className="p-5 space-y-5">
                {/* Type selector */}
                <div>
                    <SectionHead title="Production Type" />
                    <div className="grid grid-cols-2 gap-3">
                        {[
                            { key: "stock",    label: "For Stock / Inventory", desc: "Produce to replenish finished goods",  icon: "box"  },
                            { key: "customer", label: "Customer Order",         desc: "Produce for a specific sales order",  icon: "user" },
                        ].map(opt => (
                            <button key={opt.key} onClick={() => setOrderType(opt.key as any)}
                                className={clsx("p-3 rounded-xl border-2 text-left transition-all",
                                    orderType === opt.key ? "border-brand-500 bg-brand-50" : "border-surface-200 hover:border-surface-300")}>
                                <div className="mb-0.5 text-surface-500">
                                    {opt.icon === "box"
                                        ? <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                                        : <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    }
                                </div>
                                <p className={clsx("text-xs font-semibold", orderType === opt.key ? "text-brand-700" : "text-surface-900")}>{opt.label}</p>
                                <p className="text-2xs text-surface-400 mt-0.5">{opt.desc}</p>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Customer order selector */}
                {orderType === "customer" && (
                    <div className="space-y-2">
                        <label className="label">
                            Sales Order to Link <span className="text-danger">*</span>
                        </label>
                        <select
                            value={form.customer_order_id}
                            onChange={e => handleOrderSelect(e.target.value)}
                            className="input"
                        >
                            <option value="">Select a sales order…</option>
                            {orders.length === 0 && (
                                <option disabled value="">No open orders found</option>
                            )}
                            {orders.map((o: any) => {
                                const customer = [o.customer_first_name, o.customer_last_name].filter(Boolean).join(" ") || "Guest";
                                const phone = o.customer_phone ? ` · ${o.customer_phone}` : "";
                                return (
                                    <option key={o.id} value={o.id}>
                                        {o.order_number} - {customer}{phone} ({o.status})
                                    </option>
                                );
                            })}
                        </select>
                        {orders.length === 0 && orderType === "customer" && (
                            <p className="text-2xs text-warning-dark bg-warning-light rounded-lg px-3 py-2">
                                No pending or processing orders found. The sales order must exist before raising a production order for it.
                            </p>
                        )}
                        {form.customer_order_id && (() => {
                            const o = orders.find((x: any) => String(x.id) === form.customer_order_id);
                            if (!o) return null;
                            return (
                                <div className="rounded-xl bg-indigo-50 border border-indigo-200 px-3 py-2.5 space-y-1">
                                    <div className="flex items-center gap-2">
                                        <svg className="w-3.5 h-3.5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                                        <span className="text-xs font-semibold text-indigo-800">{o.order_number}</span>
                                        <span className="text-2xs text-indigo-500 capitalize">{o.status}</span>
                                    </div>
                                    <div className="text-2xs text-indigo-600 space-y-0.5">
                                        {o.customer_first_name && (
                                            <p>Customer: {[o.customer_first_name, o.customer_last_name].filter(Boolean).join(" ")}</p>
                                        )}
                                        {o.customer_phone && <p>Phone: {o.customer_phone}</p>}
                                        <p>Total: {o.currency_code} {Number(o.total_amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</p>
                                        {o.items?.length > 0 && (
                                            <p>{o.items.length} item{o.items.length !== 1 ? "s" : ""} - product will be pre-filled below</p>
                                        )}
                                    </div>
                                </div>
                            );
                        })()}
                    </div>
                )}

                {/* Product */}
                <div className="grid grid-cols-2 gap-4">
                    <div className="col-span-2">
                        <label className="label">Product <span className="text-danger">*</span></label>
                        <select value={form.product_id} onChange={e => set("product_id", e.target.value)} className="input">
                            <option value="">Select product…</option>
                            {products.map((p: any) => (
                                <option key={p.id} value={p.id}>{p.name ?? p.sku}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="label">Quantity <span className="text-danger">*</span></label>
                        <input type="number" min={1} value={form.quantity} onChange={e => set("quantity", e.target.value)} className="input" />
                    </div>
                    <div>
                        <label className="label">Priority <span className="text-danger">*</span></label>
                        <select value={form.priority} onChange={e => set("priority", e.target.value)} className="input">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">⚡ Urgent</option>
                        </select>
                    </div>
                    <div>
                        <label className="label">Due Date <span className="text-danger">*</span></label>
                        <input type="date" value={form.due_date} min={minDate.toISOString().split("T")[0]}
                            onChange={e => set("due_date", e.target.value)} className="input" />
                    </div>
                    <div>
                        <label className="label">Destination Outlet <span className="text-surface-400 text-2xs">(optional)</span></label>
                        <select value={form.outlet_id} onChange={e => set("outlet_id", e.target.value)} className="input">
                            <option value="">General Warehouse</option>
                            {outlets.map((o: any) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </select>
                    </div>
                </div>

                {/* Measurements (customer orders) */}
                {orderType === "customer" && (
                    <div>
                        <label className="label">
                            Measurements
                            <span className="ml-2 text-surface-400 font-normal text-2xs">key:value pairs, comma separated - e.g. waist:32, length:28, chest:40</span>
                        </label>
                        <input type="text" value={form.measurements}
                            onChange={e => set("measurements", e.target.value)}
                            className="input font-mono text-xs"
                            placeholder="waist:32, length:28, chest:40, hips:38" />
                    </div>
                )}

                {/* Customer preferences */}
                {orderType === "customer" && (
                    <div>
                        <label className="label">
                            Customer Preferences
                            <span className="ml-2 text-surface-400 font-normal text-2xs">e.g. color:navy blue, lining:silk, buttons:gold</span>
                        </label>
                        <input type="text" value={form.customer_preferences}
                            onChange={e => set("customer_preferences", e.target.value)}
                            className="input font-mono text-xs"
                            placeholder="color:navy blue, lining:silk, buttons:gold" />
                    </div>
                )}

                <div>
                    <label className="label">Notes / Special Instructions</label>
                    <textarea value={form.notes} onChange={e => set("notes", e.target.value)}
                        rows={2} className="input resize-none" placeholder="Custom measurements, fabric preferences, special handling…" />
                </div>

                {materialWarnings.length > 0 && (
                    <div className="rounded-xl bg-warning-light p-3 text-xs text-warning-dark space-y-1">
                        <p className="font-semibold">⚠ Material shortages detected:</p>
                        {materialWarnings.map(w => (
                            <p key={w.material_id}>
                                {w.material_name}: need {fmtNum(w.required)} {w.unit}, available {fmtNum(w.available)} {w.unit}
                            </p>
                        ))}
                        <p className="opacity-70">You can still create the order - allocate materials before starting.</p>
                    </div>
                )}

                <div className="flex gap-3 pt-1">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={!isValid || mutation.isPending}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Creating…" : "Create Production Order"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ASSIGN TASKS MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function NoTasksPrompt({ orderId, onCreated }: { orderId: number; onCreated: () => void }) {
    const toast = useToastStore();
    const mut = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${orderId}/regenerate-tasks`, {}),
        onSuccess: (res: any) => { toast.success(res.message ?? "Tasks generated"); onCreated(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    return (
        <div className="text-center py-6 space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-surface-100 flex items-center justify-center mx-auto">
                <svg className="w-6 h-6 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                </svg>
            </div>
            <div>
                <p className="text-sm font-semibold text-surface-700">No production tasks yet</p>
                <p className="text-xs text-surface-400 mt-1">
                    This order has no stages assigned. This usually happens when the order was confirmed before
                    production stages were configured.
                </p>
            </div>
            <button onClick={() => mut.mutate()} disabled={mut.isPending} className="btn-primary btn-sm mx-auto">
                {mut.isPending ? "Generating…" : "Generate Tasks from Active Stages"}
            </button>
        </div>
    );
}

function AssignModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [assignments, setAssignments] = useState<Record<number, string>>({});
    const [hours, setHours] = useState<Record<number, string>>({});

    // Fetch the order fresh so tasks are guaranteed to be loaded with current status
    const { data: freshData, isLoading: loadingOrder } = useQuery({
        queryKey: ["production-order-assign", order.id],
        queryFn: () => get<{ order: ProductionOrder }>(`/v1/admin/production-orders/${order.id}`),
        staleTime: 0,
    });
    const freshOrder = (freshData as any)?.order as ProductionOrder | undefined;

    // Fetch staff + system users (same approach as auto-assignees)
    const { data: tailorsData } = useQuery({
        queryKey: ["staff-users-list"],
        queryFn: () => get<any>("/v1/admin/users", { params: { exclude_type: "customer", per_page: "100" } }),
        staleTime: 60_000,
        retry: false,
    });
    const tailors = tailorsData?.data ?? [];

    // Use fresh tasks - filter out only truly finished stages
    const rawTasks = freshOrder?.tasks ?? order.tasks ?? [];
    // Broaden filter: only exclude tasks that are explicitly done/failed/cancelled
    const DONE_STATUSES = ["completed", "failed", "cancelled", "skipped"];
    const activeTasks = [...rawTasks]
        .filter(t => {
            const s = (typeof t.status === "string" ? t.status : "").toLowerCase();
            return !DONE_STATUSES.includes(s);
        })
        .sort((a, b) => (a.stage?.sort_order ?? 0) - (b.stage?.sort_order ?? 0));

    const mutation = useMutation({
        mutationFn: () => {
            // Build assignments - prefer what the user picked in the form,
            // fall back to the existing assignee so pre-populated values are kept.
            const payload = activeTasks
                .map(t => {
                    const existingId = typeof t.assigned_to === "object"
                        ? (t.assigned_to as any)?.id
                        : t.assigned_to;
                    const tailorId = Number(assignments[t.id] ?? existingId ?? "") || undefined;
                    return {
                        task_id:         t.id,
                        tailor_id:       tailorId,
                        estimated_hours: hours[t.id] ? Number(hours[t.id]) : (t.estimated_hours ?? undefined),
                    };
                })
                .filter(a => a.tailor_id); // only send rows that have someone assigned

            // Backend requires at least one assignment
            if (payload.length === 0) {
                return Promise.reject({ message: "Please assign at least one stage to a team member before saving." });
            }

            return post(`/v1/admin/production-orders/${order.id}/assign`, { assignments: payload });
        },
        onSuccess: () => { toast.success("Assignments saved"); onSaved(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title={`Assign Tasks - ${order.order_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                <p className="text-xs text-surface-500">
                    Assign task owners to each production stage. You can reassign any stage that hasn't been completed yet.
                </p>

                {loadingOrder ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : rawTasks.length === 0 ? (
                    <NoTasksPrompt orderId={order.id} onCreated={() => {
                        // Refetch the fresh order so tasks appear
                        qc.invalidateQueries({ queryKey: ["production-order-assign", order.id] });
                        qc.invalidateQueries({ queryKey: ["production-order", order.id] });
                    }} />
                ) : activeTasks.length === 0 ? (
                    <div className="text-center py-6">
                        <p className="text-sm font-medium text-surface-500">All stages completed</p>
                        <p className="text-xs text-surface-400 mt-1">There are no pending or in-progress stages left to assign.</p>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {/* Column headers */}
                        <div className="grid grid-cols-12 gap-3 px-3 text-2xs font-bold text-surface-400 uppercase tracking-wide">
                            <span className="col-span-4">Stage</span>
                            <span className="col-span-5">Assign to</span>
                            <span className="col-span-3">Est. hours</span>
                        </div>
                        {activeTasks.map(task => {
                            // assigned_to may be an integer or a user object (toArray() serialisation quirk)
                            const currentAssigneeId = typeof task.assigned_to === "object"
                                ? (task.assigned_to as any)?.id?.toString() ?? ""
                                : task.assigned_to?.toString() ?? "";
                            return (
                                <div key={task.id} className="grid grid-cols-12 gap-3 items-center p-3 rounded-xl bg-surface-50 border border-surface-100">
                                    <div className="col-span-4">
                                        <p className="text-sm font-semibold text-surface-900 flex items-center gap-1.5">
                                            <StageIcon slug={task.stage?.slug} className="w-3.5 h-3.5 text-surface-500" />
                                            {task.stage?.name ?? `Stage ${task.production_stage_id}`}
                                        </p>
                                        <span className={clsx("text-2xs font-medium mt-0.5",
                                            task.status === "in_progress" ? "text-brand-600" : "text-surface-400")}>
                                            {task.status === "in_progress" ? "In progress" : "Pending"}
                                        </span>
                                    </div>
                                    <select
                                        value={assignments[task.id] ?? currentAssigneeId}
                                        onChange={e => setAssignments(p => ({ ...p, [task.id]: e.target.value }))}
                                        className="input col-span-5 text-sm">
                                        <option value="">- Unassigned -</option>
                                        {tailors.map((t: any) => (
                                            <option key={t.id} value={t.id}>{t.first_name} {t.last_name}</option>
                                        ))}
                                    </select>
                                    <div className="relative col-span-3">
                                        <input type="number" min={0} step={0.5} placeholder="0"
                                            value={hours[task.id] ?? (task.estimated_hours?.toString() ?? "")}
                                            onChange={e => setHours(p => ({ ...p, [task.id]: e.target.value }))}
                                            className="input text-sm pr-7 w-full" />
                                        <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-2xs text-surface-400">h</span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                <div className="flex gap-3 pt-1">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || loadingOrder || activeTasks.length === 0}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : "Save Assignments"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ISSUE MATERIALS MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function IssueMaterialsModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const allocs = order.material_allocations ?? [];
    const [qtys, setQtys] = useState<Record<number, string>>(() => {
        const init: Record<number, string> = {};
        allocs.forEach(a => {
            const rem = Math.max(0, a.quantity_required - a.quantity_allocated);
            if (rem > 0) init[a.id] = String(rem);
        });
        return init;
    });

    const mutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${order.id}/materials`, {
            allocations: Object.entries(qtys)
                .filter(([, v]) => Number(v) > 0)
                .map(([id, qty]) => ({ allocation_id: Number(id), quantity: Number(qty) })),
        }),
        onSuccess: () => { toast.success("Materials issued to production"); onSaved(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (allocs.length === 0) return (
        <Modal open title="Issue Materials" onClose={onClose}>
            <div className="p-8 text-center text-surface-400 text-sm">No material allocations found for this order.</div>
        </Modal>
    );

    return (
        <Modal open title={`Issue Materials - ${order.order_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4 overflow-x-auto">
                <div className="grid grid-cols-12 gap-2 text-2xs font-bold text-surface-400 uppercase tracking-wide px-2 min-w-[440px]">
                    <span className="col-span-4">Material</span>
                    <span className="col-span-2 text-right">Required</span>
                    <span className="col-span-2 text-right">Allocated</span>
                    <span className="col-span-2 text-right">Remaining</span>
                    <span className="col-span-2 text-right">Issue Now</span>
                </div>
                {allocs.map(a => {
                    const rem = Math.max(0, a.quantity_required - a.quantity_allocated);
                    const u   = a.material.unit_of_measure;
                    const pct = a.quantity_required > 0 ? (a.quantity_allocated / a.quantity_required * 100) : 0;
                    return (
                        <div key={a.id} className={clsx("rounded-xl p-3 space-y-2",
                            rem <= 0 ? "bg-success-light/40" : "bg-surface-50")}>
                            <div className="grid grid-cols-12 gap-2 items-center text-xs">
                                <div className="col-span-4">
                                    <p className="font-semibold text-surface-900">{a.material.name}</p>
                                    <p className="text-2xs text-surface-400">{a.material.code} · {u}</p>
                                </div>
                                <span className="col-span-2 text-right tabular-nums text-surface-600">{fmtNum(a.quantity_required)}</span>
                                <span className={clsx("col-span-2 text-right tabular-nums font-semibold",
                                    pct >= 100 ? "text-success" : "text-warning-dark")}>{fmtNum(a.quantity_allocated)}</span>
                                <span className="col-span-2 text-right tabular-nums text-surface-600">{fmtNum(rem)}</span>
                                <input type="number" min={0} max={rem} step={0.001}
                                    value={qtys[a.id] ?? "0"} disabled={rem <= 0}
                                    onChange={e => setQtys(p => ({ ...p, [a.id]: e.target.value }))}
                                    className="col-span-2 input text-right text-xs py-1.5 disabled:opacity-40" />
                            </div>
                            <ProgressBar pct={pct} colorClass={pct >= 100 ? "bg-success" : "bg-brand-500"} />
                        </div>
                    );
                })}
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || Object.values(qtys).every(v => !Number(v))}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Issuing…" : "Issue Materials"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// QC MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function QCModal({ order, onClose, onDone }: { order: ProductionOrder; onClose: () => void; onDone: () => void }) {
    const toast = useToastStore();
    const [form, setForm] = useState({
        passed: true, passed_quantity: order.quantity, failed_quantity: 0,
        notes: "", defect_types: [] as string[],
    });

    const mutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${order.id}/qc`, form),
        onSuccess: () => {
            toast.success(form.passed ? "QC Passed!" : "QC Failed - order on hold");
            onDone(); onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const toggleDefect = (d: string) =>
        setForm(p => ({
            ...p, defect_types: p.defect_types.includes(d)
                ? p.defect_types.filter(x => x !== d) : [...p.defect_types, d],
        }));

    return (
        <Modal open title={`Quality Control - ${order.order_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                <div className="flex rounded-xl overflow-hidden border border-surface-200">
                    <button onClick={() => setForm(p => ({ ...p, passed: true }))}
                        className={clsx("flex-1 py-3 text-sm font-semibold flex items-center justify-center gap-2 transition-colors",
                            form.passed ? "bg-success text-white" : "bg-white text-surface-500 hover:bg-surface-50")}>
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        Pass
                    </button>
                    <button onClick={() => setForm(p => ({ ...p, passed: false }))}
                        className={clsx("flex-1 py-3 text-sm font-semibold flex items-center justify-center gap-2 transition-colors border-l border-surface-200",
                            !form.passed ? "bg-danger text-white" : "bg-white text-surface-500 hover:bg-surface-50")}>
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        Fail
                    </button>
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="label">Passed Quantity</label>
                        <input type="number" min={0} max={order.quantity}
                            value={form.passed_quantity}
                            onChange={e => setForm(p => ({ ...p, passed_quantity: Number(e.target.value) }))}
                            className="input" />
                    </div>
                    <div>
                        <label className="label">Rejected / Failed Quantity</label>
                        <input type="number" min={0}
                            value={form.failed_quantity}
                            onChange={e => setForm(p => ({ ...p, failed_quantity: Number(e.target.value) }))}
                            className="input" />
                    </div>
                </div>

                {!form.passed && (
                    <div>
                        <label className="label">Defect Types</label>
                        <div className="flex flex-wrap gap-2">
                            {DEFECT_TYPES.map(d => (
                                <button key={d} onClick={() => toggleDefect(d)}
                                    className={clsx("px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors",
                                        form.defect_types.includes(d)
                                            ? "bg-danger border-danger text-white"
                                            : "border-surface-200 text-surface-600 hover:border-danger/40")}>
                                    {d}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <div>
                    <label className="label">Inspector Notes</label>
                    <textarea value={form.notes} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))}
                        rows={3} className="input resize-none"
                        placeholder={form.passed ? "Any observations, measurements verified…" : "Describe the issues found in detail…"} />
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
                        className={clsx("flex-1 btn font-semibold", form.passed ? "btn-primary" : "btn-danger")}>
                        {mutation.isPending ? "Recording…" : form.passed ? "Mark as Passed" : "Mark as Failed"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// COMPLETE MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function CompleteModal({ order, onClose, onDone }: { order: ProductionOrder; onClose: () => void; onDone: () => void }) {
    const toast = useToastStore();
    const [outletId, setOutletId] = useState(order.outlet?.name ? "" : "");
    const [finalQty, setFinalQty] = useState(String(order.quantity));

    const { data: outletsData } = useQuery({
        queryKey: ["outlets-list"],
        queryFn: () => get<any>("/v1/admin/outlets"),
        staleTime: 60_000,
    });
    const outlets = outletsData?.data ?? [];

    const mutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${order.id}/complete`, {
            outlet_id: outletId ? Number(outletId) : undefined,
            final_quantity: Number(finalQty),
        }),
        onSuccess: () => { toast.success(`${finalQty} unit(s) added to inventory`); onDone(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title="Complete Production & Stock" onClose={onClose}>
            <div className="p-5 space-y-4">
                <div className="bg-success-light border border-success/20 rounded-xl p-4">
                    <p className="text-sm font-semibold text-success-dark">Ready for inventory</p>
                    <p className="text-xs text-success-dark/70 mt-0.5">QC has passed. Finished goods will be added to the selected location.</p>
                </div>
                <div>
                    <label className="label">Final Quantity Produced</label>
                    <input type="number" min={1} max={order.quantity} value={finalQty}
                        onChange={e => setFinalQty(e.target.value)} className="input" />
                    <p className="text-2xs text-surface-400 mt-1">Production target was {order.quantity} unit(s)</p>
                </div>
                <div>
                    <label className="label">Add to Outlet / Location</label>
                    <select value={outletId} onChange={e => setOutletId(e.target.value)} className="input">
                        <option value="">Main Warehouse (default)</option>
                        {outlets.map((o: any) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Processing…" : "Complete & Add to Inventory"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}


// ═══════════════════════════════════════════════════════════════════════════════
// ACTIVITY LOG + CHAT
// ═══════════════════════════════════════════════════════════════════════════════

function ActivityLog({ orderId, currentUserId }: { orderId: number; currentUserId?: number }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [body, setBody] = useState("");
    const [type, setType] = useState<"message" | "note">("message");
    const bottomRef = React.useRef<HTMLDivElement>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["production-messages", orderId],
        queryFn: () => get<{ messages: ProductionMessage[] }>(`/v1/admin/production-orders/${orderId}/messages`),
        staleTime: 0,
        refetchInterval: 15_000,
    });
    const messages: ProductionMessage[] = data?.messages ?? [];

    const sendMut = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${orderId}/messages`, { body: body.trim(), type }),
        onSuccess: () => {
            setBody("");
            qc.invalidateQueries({ queryKey: ["production-messages", orderId] });
            setTimeout(() => bottomRef.current?.scrollIntoView({ behavior: "smooth" }), 100);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const handleKey = (e: React.KeyboardEvent) => {
        if (e.key === "Enter" && !e.shiftKey && body.trim()) {
            e.preventDefault();
            sendMut.mutate();
        }
    };

    const fmtTime = (ts: string) => {
        const d = new Date(ts);
        const now = new Date();
        const sameDay = d.toDateString() === now.toDateString();
        return sameDay
            ? d.toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" })
            : d.toLocaleString("en-KE", { dateStyle: "short", timeStyle: "short" });
    };

    const AVATAR_COLORS = [
        "bg-blue-500", "bg-purple-500", "bg-pink-500", "bg-orange-500",
        "bg-teal-500", "bg-indigo-500", "bg-rose-500", "bg-amber-500",
    ];
    const avatarColor = (userId: number) => AVATAR_COLORS[userId % AVATAR_COLORS.length];

    if (isLoading) return <div className="flex justify-center py-8"><Spinner /></div>;

    return (
        <div className="flex flex-col h-full min-h-0">
            {/* Messages area */}
            <div className="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-3">
                {messages.length === 0 ? (
                    <div className="text-center py-10 text-surface-300">
                        <svg className="w-10 h-10 mx-auto mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <p className="text-sm font-medium text-surface-400">No activity yet</p>
                        <p className="text-xs text-surface-300 mt-1">Start the conversation below</p>
                    </div>
                ) : (
                    messages.map(msg => {
                        const isOwn = msg.user.id === currentUserId;
                        const isNote = msg.type === "note";
                        const isSystem = msg.type === "system";
                        if (isSystem) return (
                            <div key={msg.id} className="flex items-center gap-2 text-2xs text-surface-400">
                                <div className="flex-1 h-px bg-surface-100" />
                                <span>{msg.body}</span>
                                <div className="flex-1 h-px bg-surface-100" />
                            </div>
                        );
                        return (
                            <div key={msg.id} className={clsx("flex gap-2.5", isOwn ? "flex-row-reverse" : "flex-row")}>
                                <div className={clsx("w-7 h-7 rounded-full flex items-center justify-center text-white text-2xs font-bold shrink-0 mt-0.5", avatarColor(msg.user.id))}>
                                    {msg.user.initials}
                                </div>
                                <div className={clsx("max-w-[78%]", isOwn ? "items-end" : "items-start", "flex flex-col gap-0.5")}>
                                    <div className="flex items-center gap-1.5 px-0.5">
                                        {!isOwn && <span className="text-2xs font-semibold text-surface-700">{msg.user.first_name}</span>}
                                        {isNote && <span className="text-2xs bg-amber-100 text-amber-700 font-semibold px-1.5 py-0.5 rounded-full">Note</span>}
                                        <span className="text-2xs text-surface-300">{fmtTime(msg.created_at)}</span>
                                    </div>
                                    <div className={clsx(
                                        "px-3 py-2 rounded-2xl text-xs leading-relaxed whitespace-pre-wrap",
                                        isOwn
                                            ? "bg-brand-500 text-white rounded-tr-sm"
                                            : isNote
                                            ? "bg-amber-50 border border-amber-200 text-amber-900 rounded-tl-sm"
                                            : "bg-surface-100 text-surface-900 rounded-tl-sm"
                                    )}>
                                        {msg.body}
                                    </div>
                                </div>
                            </div>
                        );
                    })
                )}
                <div ref={bottomRef} />
            </div>

            {/* Input area */}
            <div className="shrink-0 border-t border-surface-100 p-3 space-y-2">
                {/* Type selector */}
                <div className="flex gap-1">
                    {(["message", "note"] as const).map(t => (
                        <button key={t} onClick={() => setType(t)}
                            className={clsx("text-2xs px-2.5 py-1 rounded-full font-medium transition-all",
                                type === t
                                    ? t === "note" ? "bg-amber-100 text-amber-700" : "bg-brand-100 text-brand-700"
                                    : "text-surface-400 hover:text-surface-600")}>
                            {t === "note" ? "📝 Internal Note" : "💬 Message"}
                        </button>
                    ))}
                </div>
                <div className="flex gap-2 items-end">
                    <textarea
                        value={body}
                        onChange={e => setBody(e.target.value)}
                        onKeyDown={handleKey}
                        placeholder={type === "note" ? "Add an internal note…" : "Type a message… (Enter to send)"}
                        rows={2}
                        className="flex-1 input text-xs resize-none py-2"
                    />
                    <button
                        onClick={() => sendMut.mutate()}
                        disabled={!body.trim() || sendMut.isPending}
                        className="btn-primary btn-sm px-3 py-2 h-auto self-end disabled:opacity-40"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// ORDER DETAIL SIDE PANEL
// ═══════════════════════════════════════════════════════════════════════════════

function OrderDetail({ orderId, onClose, onUpdated }: { orderId: number; onClose: () => void; onUpdated: () => void }) {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const [modal, setModal] = useState<"assign" | "materials" | "qc" | "complete" | null>(null);
    const [detailTab, setDetailTab] = useState<"details" | "activity">("details");

    const currentUserId = useAuthStore(s => s.user?.id ?? null);
    const { can } = usePermissions();
    const canManageAssignees = can("production.manage_assignees");
    const canSubmitQc = can("production.submit_qc");
    const canApproveQc = can("production.approve_qc");
    const canConfirmOrder = can("production.confirm_order");

    const { data, isLoading } = useQuery({
        queryKey: ["production-order", orderId],
        queryFn:  () => get<{ order: ProductionOrder }>(`/v1/admin/production-orders/${orderId}`),
        staleTime: 0,
    });
    const order = (data as any)?.order as ProductionOrder | undefined;

    const refresh = useCallback(() => {
        qc.invalidateQueries({ queryKey: ["production-order", orderId] });
        onUpdated();
    }, [orderId, qc, onUpdated]);

    const taskMutation = useMutation({
        mutationFn: ({ taskId, action }: { taskId: number; action: "start" | "complete" | "pause" }) =>
            put(`/v1/tailor/tasks/${taskId}/status`, { action }),
        onSuccess: (_, vars) => {
            const msg = vars.action === "complete" ? "Stage marked complete!" :
                        vars.action === "pause"    ? "Stage paused" : "Stage started!";
            toast.success(msg);
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message ?? "Failed to update task"),
    });

    const cancelMut = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${orderId}/cancel`, {}),
        onSuccess: () => { toast.success("Order cancelled"); refresh(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const confirmMut = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${orderId}/confirm`, {}),
        onSuccess: () => { toast.success("Order confirmed - now in production queue"); refresh(); onUpdated(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading || !order) return (
        <div className="flex items-center justify-center h-48"><Spinner size="lg" /></div>
    );

    const isCustomer = !!order.customer_order_id;
    const sortedTasks = [...(order.tasks ?? [])].sort((a, b) => (a.stage?.sort_order ?? 0) - (b.stage?.sort_order ?? 0));

    return (
        <div className="flex flex-col h-full overflow-hidden">
            {/* Panel header */}
            <div className="p-4 border-b border-surface-100 bg-white shrink-0">
                <div className="flex items-start gap-2 justify-between">
                    <div className="flex-1 min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5 mb-1">
                            <span className="font-mono text-xs font-bold text-surface-500">{order.order_number}</span>
                            <StatusBadge status={order.status} />
                            <PriorityBadge priority={order.priority} />
                            <OrderTypePill isCustomer={isCustomer} />
                        </div>
                        <h2 className="font-bold text-base text-surface-900 truncate">{order.product_name}</h2>
                        <div className="flex items-center gap-3 mt-0.5 text-xs text-surface-500">
                            <span>Qty: <strong>{order.quantity}</strong></span>
                            <DueBadge date={order.due_date} />
                        </div>
                    </div>
                    <button onClick={onClose} className="btn-ghost btn-icon btn-sm shrink-0"
aria-label="Close">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                {/* Progress */}
                <div className="mt-3">
                    <div className="flex justify-between text-2xs text-surface-400 mb-1">
                        <span>{order.current_stage ?? "Not started"}</span>
                        <span>{order.completion_percentage}%</span>
                    </div>
                    <ProgressBar pct={order.completion_percentage} />
                </div>

                {/* Action buttons */}
                <div className="flex flex-wrap gap-1.5 mt-3">
                    {["pending", "in_progress"].includes(order.status) && canManageAssignees && (
                        <button onClick={() => setModal("assign")} className="btn-primary btn-sm">Assign Tasks</button>
                    )}
                    {["pending", "in_progress"].includes(order.status) && canManageAssignees && (
                        <button onClick={() => setModal("materials")} className="btn-secondary btn-sm">Issue Materials</button>
                    )}
                    {order.status === "qc_pending" && canSubmitQc && (
                        <button onClick={() => setModal("qc")}
                            className="btn-sm bg-purple-500 text-white hover:bg-purple-600 rounded-lg px-3 font-medium transition-colors">
                            Quality Check
                        </button>
                    )}
                    {order.status === "qc_passed" && canApproveQc && (
                        <button onClick={() => setModal("complete")}
                            className="btn-sm bg-success text-white hover:bg-success/90 rounded-lg px-3 font-medium transition-colors">
                            Complete & Stock
                        </button>
                    )}
                    {order.status === "draft" && canConfirmOrder && (
                        <button onClick={() => confirmMut.mutate()} disabled={confirmMut.isPending}
                            className="btn-sm bg-brand-500 text-white hover:bg-brand-600 transition-colors rounded-lg px-3 py-1.5 font-semibold text-xs">
                            {confirmMut.isPending ? "Confirming…" : "✓ Confirm Order"}
                        </button>
                    )}
                    {(order.status === "pending" || order.status === "draft") && canConfirmOrder && (
                        <button onClick={() => cancelMut.mutate()} disabled={cancelMut.isPending}
                            className="btn-secondary btn-sm text-danger border-danger/20 hover:bg-danger-light ml-auto">
                            Cancel
                        </button>
                    )}
                </div>
            </div>

            {/* Scrollable body */}
            <div className="flex-1 overflow-y-auto p-4 space-y-5">

                {/* Linked Sales Order */}
                {isCustomer && (
                    <div>
                        <SectionHead title="Linked Sales Order" />
                        {order.customer_order ? (
                            <a
                                href={`/sales/orders/${order.customer_order_id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 hover:bg-indigo-100 transition-colors group"
                            >
                                <div className="w-8 h-8 rounded-lg bg-indigo-500 text-white flex items-center justify-center shrink-0">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-bold text-indigo-800 font-mono">{order.customer_order.order_number}</p>
                                    {(order.customer_order.customer_first_name || order.customer_order.customer_last_name) && (
                                        <p className="text-xs text-indigo-700 mt-0.5 font-medium">{[order.customer_order.customer_first_name, order.customer_order.customer_last_name].filter(Boolean).join(" ")}</p>
                                    )}
                                    {order.customer_order.customer_phone && (
                                        <p className="text-2xs text-indigo-500">{order.customer_order.customer_phone}</p>
                                    )}
                                    <p className="text-2xs text-indigo-400 mt-0.5">Click to view the full sales order →</p>
                                </div>
                                <svg className="w-4 h-4 text-indigo-400 group-hover:translate-x-0.5 transition-transform shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                </svg>
                            </a>
                        ) : (
                            <div className="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3">
                                <p className="text-xs text-indigo-600 font-semibold">Order ID: {order.customer_order_id}</p>
                                <p className="text-2xs text-indigo-400 mt-0.5">Sales order details could not be loaded.</p>
                            </div>
                        )}
                    </div>
                )}

                {/* Stage pipeline */}
                <div>
                    <SectionHead title="Stages" />
                    <div className="space-y-2">
                        {sortedTasks.map((task, i) => {
                            const done    = task.status === "completed";
                            const active  = task.status === "in_progress";
                            const failed  = task.status === "failed";

                            const assignedId = typeof task.assigned_to === "object"
                                ? (task.assigned_to as any)?.id
                                : task.assigned_to;
                            const isMyTask = currentUserId !== null && assignedId === currentUserId &&
                                !["completed", "failed", "cancelled"].includes(task.status);
                            const canStart    = isMyTask && (task.status === "pending" || task.status === "paused");
                            const canComplete = isMyTask && task.status === "in_progress";
                            const canPause    = isMyTask && task.status === "in_progress";

                            return (
                                <div key={task.id}
                                    className={clsx("rounded-xl border p-3 flex items-start gap-3 transition-all",
                                        done     ? "border-success/20 bg-success-light/30" :
                                        active   ? "border-brand-200 bg-brand-50 shadow-sm" :
                                        failed   ? "border-danger/20 bg-danger-light/30" :
                                        isMyTask ? "border-brand-100 bg-white" :
                                        "border-surface-100 bg-white")}>
                                    <div className={clsx("w-7 h-7 rounded-full flex items-center justify-center text-sm shrink-0 font-bold mt-0.5",
                                        done   ? "bg-success text-white" :
                                        active ? "bg-brand-500 text-white" :
                                        failed ? "bg-danger text-white" :
                                        "bg-surface-100 text-surface-400")}>
                                        {done ? "✓" : failed ? "✗" : <StageIcon slug={task.stage?.slug} className="w-3.5 h-3.5" />}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center justify-between gap-2 flex-wrap">
                                            <p className="text-sm font-semibold text-surface-900">{task.stage?.name}</p>
                                            <div className="flex items-center gap-2 shrink-0">
                                                {isMyTask && (
                                                    <span className="text-2xs font-semibold text-brand-600 bg-brand-50 border border-brand-200 px-1.5 py-0.5 rounded-full">
                                                        My task
                                                    </span>
                                                )}
                                                <span className={clsx("text-2xs font-semibold",
                                                    done ? "text-success" : active ? "text-brand-600" :
                                                    failed ? "text-danger" : "text-surface-400")}>
                                                    {task.status}
                                                </span>
                                            </div>
                                        </div>
                                        {(() => {
                                            const u = task.assigned_to_user
                                                ?? (typeof task.assigned_to === "object" && task.assigned_to !== null
                                                    ? (task.assigned_to as any)
                                                    : null);
                                            return u
                                                ? <p className="text-2xs text-surface-500 mt-0.5">
                                                    {u.first_name} {u.last_name}
                                                    {task.estimated_hours ? ` · Est. ${task.estimated_hours}h` : ""}
                                                    {task.actual_hours ? ` · Actual ${task.actual_hours}h` : ""}
                                                  </p>
                                                : <p className="text-2xs text-surface-300 italic mt-0.5">Unassigned</p>;
                                        })()}
                                        {task.notes && <p className="text-2xs text-surface-400 mt-1 italic">{task.notes}</p>}

                                        {isMyTask && (
                                            <div className="flex items-center gap-1.5 mt-2.5">
                                                {canStart && (
                                                    <button
                                                        onClick={() => taskMutation.mutate({ taskId: task.id, action: "start" })}
                                                        disabled={taskMutation.isPending}
                                                        className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-brand-500 text-white text-xs font-semibold hover:bg-brand-600 transition-colors disabled:opacity-50"
                                                    >
                                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                                                        </svg>
                                                        {task.status === "paused" ? "Resume" : "Start"}
                                                    </button>
                                                )}
                                                {canComplete && (
                                                    <button
                                                        onClick={() => taskMutation.mutate({ taskId: task.id, action: "complete" })}
                                                        disabled={taskMutation.isPending}
                                                        className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-success text-white text-xs font-semibold hover:bg-green-700 transition-colors disabled:opacity-50"
                                                    >
                                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                        </svg>
                                                        Mark done
                                                    </button>
                                                )}
                                                {canPause && (
                                                    <button
                                                        onClick={() => taskMutation.mutate({ taskId: task.id, action: "pause" })}
                                                        disabled={taskMutation.isPending}
                                                        className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-surface-100 text-surface-600 text-xs font-semibold hover:bg-surface-200 transition-colors disabled:opacity-50"
                                                    >
                                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                                                        </svg>
                                                        Pause
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Material requirements */}
                {(order.material_requirements?.length ?? 0) > 0 && (
                    <div>
                        <SectionHead title="Material Requirements" />
                        <div className="space-y-1.5">
                            {order.material_requirements!.map(r => (
                                <div key={r.material_id}
                                    className={clsx("flex items-center gap-2 p-2.5 rounded-lg text-xs",
                                        r.is_short ? "bg-danger-light" : "bg-surface-50")}>
                                    <div className={clsx("w-2 h-2 rounded-full shrink-0", r.is_short ? "bg-danger" : "bg-success")} />
                                    <span className="flex-1 font-medium text-surface-900">{r.material_name}</span>
                                    <span className="text-surface-500">{fmtNum(r.required)} {r.unit}</span>
                                    <span className={clsx("font-semibold", r.is_short ? "text-danger" : "text-success")}>
                                        {fmtNum(r.available)} avail
                                    </span>
                                    {r.is_short && <span className="text-danger font-bold">⚠ short {fmtNum(r.shortage)}</span>}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Allocations */}
                {(order.material_allocations?.length ?? 0) > 0 && (
                    <div>
                        <SectionHead title="Material Allocations" />
                        <div className="space-y-2">
                            {order.material_allocations!.map(a => {
                                const pct = a.quantity_required > 0 ? Math.min(100, a.quantity_allocated / a.quantity_required * 100) : 0;
                                return (
                                    <div key={a.id} className="bg-surface-50 rounded-lg p-2.5">
                                        <div className="flex justify-between text-xs mb-1.5">
                                            <span className="font-medium text-surface-900">{a.material.name}</span>
                                            <span className="text-surface-500 tabular-nums">
                                                {fmtNum(a.quantity_allocated)}/{fmtNum(a.quantity_required)} {a.material.unit_of_measure}
                                            </span>
                                        </div>
                                        <ProgressBar pct={pct} colorClass={pct >= 100 ? "bg-success" : "bg-brand-500"} />
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

            </div>

            {/* ── Detail tabs: Details / Activity ── */}
            <div className="shrink-0 flex border-t border-surface-100 bg-white">
                {(["details", "activity"] as const).map(tab => (
                    <button key={tab} onClick={() => setDetailTab(tab)}
                        className={clsx(
                            "flex-1 py-2.5 text-xs font-semibold transition-colors border-b-2",
                            detailTab === tab
                                ? "border-brand-500 text-brand-600"
                                : "border-transparent text-surface-400 hover:text-surface-700"
                        )}>
                        {tab === "details" ? "📋 Details & Specs" : "💬 Activity"}
                    </button>
                ))}
            </div>

            {/* Details tab */}
            {detailTab === "details" && (
                <div className="flex-1 overflow-y-auto p-4 space-y-4">
                    {/* Measurements */}
                    {order.measurements && Object.keys(order.measurements).length > 0 && (
                        <div>
                            <SectionHead title="Measurements" />
                            <div className="bg-purple-50 rounded-xl p-3 grid grid-cols-2 gap-x-4 gap-y-1.5">
                                {Object.entries(order.measurements).map(([k, v]) => (
                                    <div key={k} className="flex gap-1.5 text-xs">
                                        <span className="text-purple-500 shrink-0 capitalize w-24 truncate">{k.replace(/_/g, " ")}</span>
                                        <span className="font-bold text-purple-900">{v}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Specifications */}
                    {order.specifications && Object.keys(order.specifications).length > 0 && (
                        <div>
                            <SectionHead title="Specifications" />
                            <div className="bg-surface-50 rounded-xl p-3 space-y-1.5">
                                {Object.entries(order.specifications).map(([k, v]) => (
                                    <div key={k} className="flex gap-2 text-xs">
                                        <span className="text-surface-400 w-32 shrink-0 capitalize">{k.replace(/_/g, " ")}</span>
                                        <span className="font-medium text-surface-900 flex-1">{v as string}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Customer Preferences */}
                    {order.customer_preferences && Object.keys(order.customer_preferences).length > 0 && (
                        <div>
                            <SectionHead title="Customer Preferences" />
                            <div className="bg-indigo-50 rounded-xl p-3 space-y-1.5">
                                {Object.entries(order.customer_preferences).map(([k, v]) => (
                                    <div key={k} className="flex gap-2 text-xs">
                                        <span className="text-indigo-400 w-32 shrink-0 capitalize">{k.replace(/_/g, " ")}</span>
                                        <span className="font-medium text-indigo-900 flex-1">{v as string}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Notes */}
                    {order.notes && (
                        <div>
                            <SectionHead title="Notes" />
                            <p className="text-xs text-surface-600 bg-surface-50 rounded-xl p-3 whitespace-pre-wrap leading-relaxed">{order.notes}</p>
                        </div>
                    )}

                    {/* Empty state */}
                    {!order.measurements && !order.specifications && !order.customer_preferences && !order.notes && (
                        <div className="text-center py-10 text-surface-300">
                            <p className="text-sm font-medium text-surface-400">No details recorded</p>
                            <p className="text-xs mt-1">Measurements, specifications and notes will appear here when added.</p>
                        </div>
                    )}
                </div>
            )}

            {/* Activity tab */}
            {detailTab === "activity" && (
                <div className="flex-1 min-h-0 flex flex-col overflow-hidden">
                    <ActivityLog orderId={orderId} />
                </div>
            )}

            {modal === "assign"    && <AssignModal order={order} onClose={() => setModal(null)} onSaved={refresh} />}
            {modal === "materials" && <IssueMaterialsModal order={order} onClose={() => setModal(null)} onSaved={refresh} />}
            {modal === "qc"        && <QCModal  order={order} onClose={() => setModal(null)} onDone={refresh} />}
            {modal === "complete"  && <CompleteModal order={order} onClose={() => setModal(null)} onDone={refresh} />}
        </div>
    );
}


// ═══════════════════════════════════════════════════════════════════════════════
// ORDER DETAIL MODAL
// ═══════════════════════════════════════════════════════════════════════════════

function OrderDetailModal({ orderId, onClose, onUpdated }: { orderId: number; onClose: () => void; onUpdated: () => void }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />
            <div className="relative z-10 w-full max-w-2xl max-h-[90vh] bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col">
                <OrderDetail orderId={orderId} onClose={onClose} onUpdated={onUpdated} />
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB: PRODUCTION ORDERS
// ═══════════════════════════════════════════════════════════════════════════════

function ProductionOrdersTab() {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const { can } = usePermissions();
    const canRaiseOrder = can("production.raise_order");
    const [showCreate, setShowCreate] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [filters, setFilters] = useState({ status: "", priority: "", type: "", search: "" });
    const [page, setPage] = useState(1);
    const perPage = 25;
    const setF = (k: string, v: string) => {
        setFilters(p => ({ ...p, [k]: v }));
        setPage(1); // any filter change resets to page 1, same as the other list pages
    };

    const { data, isLoading } = useQuery({
        queryKey: ["production-orders", filters, page],
        queryFn: () => {
            // "overdue" is not a real DB status — the backend expects ?overdue=1
            // and applies: WHERE due_date < now() AND status NOT IN (completed, cancelled).
            // Sending ?status=overdue returns nothing. Translate here before the request.
            const { status, ...rest } = filters;
            const params: Record<string, string> = Object.fromEntries(
                Object.entries(rest).filter(([, v]) => v)
            );
            if (status === "overdue") {
                params.overdue = "1";
            } else if (status) {
                params.status = status;
            }
            params.page = String(page);
            params.per_page = String(perPage);
            return get<any>("/v1/admin/production-orders", { params });
        },
        staleTime: 0,
        placeholderData: (prev) => prev,
    });
    const orders: ProductionOrder[] = data?.data ?? [];
    const stats = data?.stats ?? {};
    const meta  = data?.meta;

    // Group the current page of rows by created_at. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const orderGroups = groupRowsByDate(orders, (o) => o.created_at);

    const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ["production-orders"] }), [qc]);

    return (
        <div className="flex flex-col gap-4 min-h-0 flex-1">
            {/* Stats */}
            <div className="grid grid-cols-2 sm:grid-cols-6 gap-2">
                {[
                    { key: "draft",       label: "Draft",       color: "text-surface-400" },
                    { key: "pending",     label: "Pending",     color: "text-surface-700" },
                    { key: "in_progress", label: "In Progress", color: "text-brand-600"   },
                    { key: "qc_pending",  label: "QC",          color: "text-purple-600"  },
                    { key: "completed",   label: "Completed",   color: "text-success"     },
                    { key: "overdue",     label: "Overdue",     color: "text-danger"      },
                ].map(({ key, label, color }) => (
                    <button key={key} onClick={() => setF("status", filters.status === key ? "" : key)}
                        className={clsx("card p-3 text-left hover:shadow-sm transition-all",
                            filters.status === key && "ring-2 ring-brand-400 ring-offset-1")}>
                        <p className="text-2xs text-surface-400">{label}</p>
                        <p className={clsx("text-2xl font-bold mt-0.5", color)}>{stats[key] ?? 0}</p>
                    </button>
                ))}
            </div>

            {/* Filters + create */}
            <div className="flex flex-wrap gap-2 items-center">
                <div className="relative flex-1 min-w-40">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    <input value={filters.search} onChange={e => setF("search", e.target.value)}
                        placeholder="Search…" className="input pl-8 text-sm" />
                </div>
                <select value={filters.type} onChange={e => setF("type", e.target.value)} className="input w-36 text-sm">
                    <option value="">All Types</option>
                    <option value="stock">For Stock</option>
                    <option value="customer">Customer Orders</option>
                </select>
                <select value={filters.priority} onChange={e => setF("priority", e.target.value)} className="input w-32 text-sm">
                    <option value="">All Priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="normal">Normal</option>
                    <option value="low">Low</option>
                </select>
                <select value={filters.status} onChange={e => setF("status", e.target.value)} className="input w-36 text-sm">
                    <option value="">All Statuses</option>
                    {Object.entries(STATUS_CFG).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
                    <option value="overdue">Overdue</option>
                </select>
                <button onClick={() => { setFilters({ status: "", priority: "", type: "", search: "" }); setPage(1); }}
                    className="btn-ghost btn-sm text-surface-400">Clear</button>
                {canRaiseOrder && (
                <button onClick={() => setShowCreate(true)} className="btn-primary btn-sm gap-1.5 ml-auto">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New Order
                </button>
                )}
            </div>

            {/* Table */}
            <div className="flex-1 min-h-0 overflow-auto card">
                    {isLoading ? (
                        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-surface-50 border-b border-surface-100 sticky top-0">
                                <tr>
                                    {["Order #", "Type", "Product", "Qty", "Priority", "Status", "Progress", "Due", ""].map((h, i) => (
                                        <th key={h || i} className={`px-3 py-3 text-left text-2xs font-bold text-surface-400 uppercase tracking-wider whitespace-nowrap ${["Type","Priority","Progress"].includes(h) ? "hidden md:table-cell" : ""} ${["Due"].includes(h) ? "hidden sm:table-cell" : ""}`}>{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {orderGroups.map((group) => (
                                    <Fragment key={group.key}>
                                        <DateGroupHeaderRow label={group.label} colSpan={9} />
                                        {group.items.map(o => {
                                    const days = daysUntil(o.due_date);
                                    const isCustomer = !!o.customer_order_id;
                                    return (
                                        <tr key={o.id} onClick={() => navigate(`/production/orders/${o.id}`)}
                                            className="cursor-pointer hover:bg-surface-50 transition-colors">
                                            <td className="px-3 py-3">
                                                <span className="font-mono text-xs font-bold text-brand-600">{o.order_number}</span>
                                            </td>
                                            <td className="px-3 py-3">
                                                <OrderTypePill isCustomer={isCustomer} />
                                            </td>
                                            <td className="px-3 py-3 max-w-44">
                                                <p className="font-medium text-surface-900 truncate text-xs">{o.product_name}</p>
                                                {isCustomer && o.customer_order && (
                                                    <p className="text-2xs text-indigo-500">{o.customer_order.order_number}</p>
                                                )}
                                                {isCustomer && o.customer_order && (o.customer_order.customer_first_name || o.customer_order.customer_last_name) && (
                                                    <p className="text-2xs text-indigo-400 truncate">{[o.customer_order.customer_first_name, o.customer_order.customer_last_name].filter(Boolean).join(" ")}{o.customer_order.customer_phone ? ` · ${o.customer_order.customer_phone}` : ""}</p>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-surface-600 tabular-nums">{o.quantity}</td>
                                            <td className="px-3 py-3"><PriorityBadge priority={o.priority} /></td>
                                            <td className="px-3 py-3"><StatusBadge status={o.status} /></td>
                                            <td className="px-3 py-3 w-28">
                                                <ProgressBar pct={o.completion_percentage} />
                                                <p className="text-2xs text-surface-400 mt-0.5 tabular-nums">{o.completion_percentage}%</p>
                                            </td>
                                            <td className={clsx("px-3 py-3 text-xs font-medium",
                                                days < 0 ? "text-danger" : days <= 2 ? "text-warning-dark" : "text-surface-400")}>
                                                {days < 0 ? `${Math.abs(days)}d ago` : days === 0 ? "Today" : `${days}d`}
                                            </td>
                                            <td className="px-3 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                                <button
                                                    onClick={() => navigate(`/production/orders/${o.id}`)}
                                                    className="btn-ghost btn-icon btn-sm"
                                                    title="View detail"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                        })}
                                    </Fragment>
                                ))}
                                {orders.length === 0 && !isLoading && (
                                    <tr><td colSpan={9} className="text-center py-16 text-surface-400">No production orders found</td></tr>
                                )}
                            </tbody>
                        </table>
                    )}
                </div>

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between shrink-0">
                        <p className="text-xs text-surface-500">
                            Showing {meta.from}–{meta.to} of {meta.total}
                        </p>
                        <div className="flex gap-1">
                            <button
                                disabled={meta.current_page === 1}
                                onClick={() => setPage(meta.current_page - 1)}
                                className="btn-ghost btn-sm text-xs disabled:opacity-40"
                            >
                                ← Prev
                            </button>
                            <button
                                disabled={meta.current_page === meta.last_page}
                                onClick={() => setPage(meta.current_page + 1)}
                                className="btn-ghost btn-sm text-xs disabled:opacity-40"
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                )}

            {selectedId && (
                <OrderDetailModal orderId={selectedId} onClose={() => setSelectedId(null)} onUpdated={refresh} />
            )}

            {showCreate && (
                <CreateOrderModal onClose={() => setShowCreate(false)} onCreated={refresh} />
            )}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB: WORK IN PROGRESS
// ═══════════════════════════════════════════════════════════════════════════════

interface WIPUser {
    id: number;
    first_name: string;
    last_name: string;
}

function WIPTab({
    selectedUserId,
    setSelectedUserId,
    productionUsers,
    canViewFull,
    isWorker,
}: {
    selectedUserId: "all" | "mine" | string;
    setSelectedUserId: (v: "all" | "mine" | string) => void;
    productionUsers: WIPUser[];
    canViewFull: boolean;
    isWorker: boolean;
}) {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const location = useLocation();
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const refresh = useCallback(() => {
        qc.invalidateQueries({ queryKey: ["production-orders-wip"] });
        qc.invalidateQueries({ queryKey: ["wip-user-tasks"] });
    }, [qc]);

    // If navigated here from the detail page with a specific order to open,
    // auto-select it so the workflow modal opens immediately.
    useEffect(() => {
        const openOrderId = (location.state as any)?.openOrderId;
        if (openOrderId) {
            setSelectedId(openOrderId);
            window.history.replaceState({}, "");
        }
    }, [location.state]);

    // ── Full WIP orders (admin / "all" view) ────────────────────────────────
    const { data, isLoading: ordersLoading } = useQuery({
        queryKey: ["production-orders-wip"],
        queryFn: () => get<any>("/v1/admin/production-orders", {
            params: { status: "in_progress,on_hold,qc_pending,qc_passed,qc_failed", per_page: "100" },
        }),
        enabled: canViewFull && selectedUserId === "all",
        staleTime: 0,
        refetchInterval: 30_000,
    });
    const allOrders: ProductionOrder[] = data?.data ?? [];

    // ── Tasks for the selected user (own tasks or admin per-user) ────────────
    const { data: userTasksData, isLoading: tasksLoading } = useQuery({
        queryKey: ["wip-user-tasks", selectedUserId],
        queryFn: () => {
            if (selectedUserId === "mine") {
                // Worker fetches own tasks — returns array directly
                return get<any[]>("/v1/tailor/tasks?include_completed=true");
            }
            // Admin filtering by specific user
            return get<{ data: any[] }>("/v1/admin/production-tasks", {
                params: { tailor_id: selectedUserId, per_page: "200" } as any,
            }).then(r => r.data);
        },
        enabled: selectedUserId !== "all",
        staleTime: 0,
        refetchInterval: 30_000,
    });
    const userTasks: any[] = Array.isArray(userTasksData) ? userTasksData : (userTasksData ?? []);

    // ── When a user is selected, re-fetch the WIP orders filtered by the
    //    production_order IDs that appear in their tasks ─────────────────────
    const filteredOrderIds = useMemo(() => {
        if (selectedUserId === "all") return null;
        return new Set(userTasks.map((t: any) => t.production_order?.id ?? t.production_order_id).filter(Boolean));
    }, [userTasks, selectedUserId]);

    const { data: filteredOrdersData, isLoading: filteredLoading } = useQuery({
        queryKey: ["production-orders-wip-filtered", Array.from(filteredOrderIds ?? []).sort().join(",")],
        queryFn: () => get<any>("/v1/admin/production-orders", {
            params: {
                status: "in_progress,on_hold,qc_pending,qc_passed,qc_failed",
                per_page: "100",
            },
        }),
        enabled: selectedUserId !== "all" && (filteredOrderIds?.size ?? 0) > 0,
        staleTime: 0,
        refetchInterval: 30_000,
    });

    // Client-side filter: only keep orders that have tasks assigned to the selected user
    const filteredOrders: ProductionOrder[] = useMemo(() => {
        if (!filteredOrdersData?.data || !filteredOrderIds) return [];
        return (filteredOrdersData.data as ProductionOrder[]).filter(o => filteredOrderIds.has(o.id));
    }, [filteredOrdersData, filteredOrderIds]);

    const orders = selectedUserId === "all" ? allOrders : filteredOrders;
    const isLoading = selectedUserId === "all"
        ? ordersLoading
        : tasksLoading || filteredLoading;

    // ── Resolve the selected user's display name ─────────────────────────────
    const selectedUserName = useMemo(() => {
        if (selectedUserId === "all")  return null;
        if (selectedUserId === "mine") return "My orders";
        const u = productionUsers.find(u => String(u.id) === selectedUserId);
        return u ? `${u.first_name} ${u.last_name}` : null;
    }, [selectedUserId, productionUsers]);

    // ── Group into pipeline columns ──────────────────────────────────────────
    const cols = [
        { key: "in_progress", label: "In Progress" },
        { key: "on_hold",     label: "On Hold" },
        { key: "qc_pending",  label: "QC Check" },
        { key: "qc_passed",   label: "QC Passed" },
        { key: "qc_failed",   label: "QC Failed" },
    ];

    const byStatus = useMemo(() => {
        const m: Record<string, ProductionOrder[]> = {};
        cols.forEach(c => { m[c.key] = []; });
        orders.forEach(o => { if (m[o.status]) m[o.status].push(o); });
        return m;
    }, [orders]);

    return (
        <div className="flex flex-col gap-3 h-full min-h-0">

            {/* Filter context — shown inline below header on mobile when a user is selected */}
            {selectedUserName && (
                <div className="flex items-center gap-2 sm:hidden shrink-0">
                    <span className="text-xs text-surface-500">
                        Showing orders for <strong className="text-surface-800">{selectedUserName}</strong>
                        {" "}
                        <span className="text-surface-400">({orders.length} order{orders.length !== 1 ? "s" : ""})</span>
                    </span>
                    {canViewFull && (
                        <button
                            onClick={() => setSelectedUserId("all")}
                            className="text-2xs text-danger font-semibold flex items-center gap-0.5"
                        >
                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Clear
                        </button>
                    )}
                </div>
            )}

            {selectedUserId !== "all" && !isLoading && (filteredOrderIds?.size ?? 0) === 0 && (
                <span className="text-xs text-surface-400 italic shrink-0">No active orders assigned</span>
            )}

            {/* ── Kanban board ─────────────────────────────────────────────── */}
            <div className="flex-1 min-h-0 flex gap-4 overflow-x-auto">
                {isLoading ? (
                    <div className="flex justify-center py-16 w-full"><Spinner size="lg" /></div>
                ) : (
                    <div className="flex gap-3 h-full pb-4" style={{ minWidth: `${cols.length * 260}px` }}>
                        {cols.map(col => {
                            const colOrders = byStatus[col.key] ?? [];
                            const cfg = STATUS_CFG[col.key];
                            return (
                                <div key={col.key} className="flex flex-col w-[260px] shrink-0">
                                    <div className="flex items-center gap-2 mb-3 px-1">
                                        <span className={clsx("w-2.5 h-2.5 rounded-full shrink-0", cfg?.dot ?? "bg-surface-300")} />
                                        <span className="text-sm font-semibold text-surface-700">{col.label}</span>
                                        <span className="ml-auto text-xs text-surface-400 bg-surface-100 rounded-full px-2 py-0.5 font-medium">
                                            {colOrders.length}
                                        </span>
                                    </div>
                                    <div className="flex flex-col gap-2.5 overflow-y-auto flex-1 pr-0.5">
                                        {colOrders.map(o => {
                                            const isCustomer = !!o.customer_order_id;
                                            const days = daysUntil(o.due_date);
                                            return (
                                                <div key={o.id} onClick={() => setSelectedId(o.id === selectedId ? null : o.id)}
                                                    className={clsx("card p-3 cursor-pointer hover:shadow-md transition-all active:scale-[0.98]",
                                                        selectedId === o.id && "ring-2 ring-brand-400")}>
                                                    <div className="flex items-start justify-between gap-2 mb-2">
                                                        <div className="flex-1 min-w-0">
                                                            <div className="flex items-center gap-1.5 flex-wrap">
                                                                <span className="font-mono text-2xs font-bold text-surface-500">{o.order_number}</span>
                                                                <PriorityBadge priority={o.priority} />
                                                            </div>
                                                            <p className="text-xs font-semibold text-surface-900 mt-0.5 truncate">{o.product_name}</p>
                                                        </div>
                                                        <OrderTypePill isCustomer={isCustomer} />
                                                    </div>

                                                    {/* Stage mini-pipeline */}
                                                    <div className="flex gap-1 mb-2">
                                                        {[...(o.tasks ?? [])].sort((a, b) => (a.stage?.sort_order ?? 0) - (b.stage?.sort_order ?? 0)).map(t => (
                                                            <div key={t.id} title={t.stage?.name}
                                                                className={clsx("flex-1 h-1.5 rounded-full",
                                                                    t.status === "completed"   ? "bg-success" :
                                                                    t.status === "in_progress" ? "bg-brand-500 animate-pulse" :
                                                                    t.status === "failed"      ? "bg-danger" :
                                                                    "bg-surface-100")} />
                                                        ))}
                                                    </div>

                                                    <div className="flex items-center justify-between text-2xs">
                                                        <span className="text-surface-500">Qty: {o.quantity}</span>
                                                        <span className={clsx(days < 0 ? "text-danger font-medium" : days <= 2 ? "text-warning-dark" : "text-surface-400")}>
                                                            {days < 0 ? `${Math.abs(days)}d overdue` : days === 0 ? "Today" : `${days}d`}
                                                        </span>
                                                    </div>

                                                    {o.current_stage && (
                                                        <p className="text-2xs text-surface-400 mt-1.5 flex items-center gap-1">
                                                            <StageIcon slug={o.current_stage.toLowerCase().replace(" ", "_")} className="w-3 h-3" />
                                                            {o.current_stage}
                                                        </p>
                                                    )}
                                                    <div className="flex justify-end mt-2">
                                                        <button
                                                            onClick={(e) => { e.stopPropagation(); navigate(`/production/orders/${o.id}`); }}
                                                            className="text-2xs text-brand-500 hover:text-brand-700 font-semibold"
                                                        >
                                                            View Detail →
                                                        </button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                        {colOrders.length === 0 && (
                                            <div className="text-center py-10 text-surface-200 text-2xs border-2 border-dashed border-surface-100 rounded-xl">
                                                Empty
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {selectedId && (
                <OrderDetailModal orderId={selectedId} onClose={() => setSelectedId(null)} onUpdated={refresh} />
            )}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB: BILL OF MATERIALS
// ═══════════════════════════════════════════════════════════════════════════════

function BOMTab() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { can } = usePermissions();
    const canEditProducts = can("products.edit");
    const [search, setSearch] = useState("");
    const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
    const [editingBom, setEditingBom] = useState<Bom | null>(null);
    const [showCreate, setShowCreate] = useState(false);

    const { data: productsData, isLoading: loadingProducts } = useQuery({
        queryKey: ["bom-products", search],
        queryFn: () => get<any>("/v1/admin/products", {
            params: { is_producible: "1", search: search || undefined, per_page: "100" },
        }),
        staleTime: 60_000,
    });
    const products: BomProduct[] = productsData?.data ?? [];

    const { data: bomData, isLoading: loadingBoms } = useQuery({
        queryKey: ["bom-detail", selectedProductId],
        queryFn: () => get<any>(`/v1/admin/products/${selectedProductId}/bom`),
        enabled: !!selectedProductId,
        staleTime: 0,
    });
    const boms: Bom[] = bomData?.data ?? [];
    const selectedProduct = bomData?.product ?? products.find(p => p.id === selectedProductId);

    const activateMut = useMutation({
        mutationFn: ({ productId, bomId }: { productId: number; bomId: number }) =>
            put(`/v1/admin/products/${productId}/bom/${bomId}/activate`, {}),
        onSuccess: () => { toast.success("BOM activated"); qc.invalidateQueries({ queryKey: ["bom-detail", selectedProductId] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    // On mobile: show either the product list OR the BOM detail, not both side-by-side
    const showingDetail = !!selectedProductId;

    return (
        <div className="flex gap-4 flex-1 min-h-0">

            {/* ── Product list ─────────────────────────────────────────────── */}
            {/* Desktop: always visible. Mobile: visible only when no product selected */}
            <div className={clsx(
                "flex flex-col gap-3 sm:w-72 sm:shrink-0",
                showingDetail ? "hidden sm:flex" : "flex w-full"
            )}>
                <div className="relative">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    <input value={search} onChange={e => setSearch(e.target.value)}
                        placeholder="Search products…" className="input pl-8 text-sm" />
                </div>
                <div className="card overflow-y-auto flex-1 p-0">
                    {loadingProducts ? (
                        <div className="flex justify-center py-8"><Spinner /></div>
                    ) : products.length === 0 ? (
                        <p className="text-xs text-surface-400 text-center py-8 px-4">No producible products found</p>
                    ) : (
                        <div className="divide-y divide-surface-50">
                            {products.map(p => {
                                const name = p.en_translation?.name ?? p.name ?? p.sku;
                                const price = p.base_price?.regular_price;
                                const isSelected = selectedProductId === p.id;
                                return (
                                    <button key={p.id} onClick={() => setSelectedProductId(p.id)}
                                        className={clsx(
                                            "w-full text-left px-3 py-3 transition-colors hover:bg-surface-50/80 flex items-start gap-3",
                                            isSelected && "bg-brand-50 border-l-[3px] border-brand-500",
                                        )}>
                                        {/* Product image / placeholder */}
                                        <div className="w-10 h-10 rounded-lg shrink-0 overflow-hidden bg-surface-100 flex items-center justify-center">
                                            {p.primary_image ? (
                                                <img src={p.primary_image.image_url} alt={p.primary_image.alt_text ?? name}
                                                    className="w-full h-full object-cover" />
                                            ) : (
                                                <svg className="w-5 h-5 text-surface-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                                                </svg>
                                            )}
                                        </div>

                                        {/* Info */}
                                        <div className="flex-1 min-w-0">
                                            <p className={clsx("text-xs font-semibold truncate leading-tight",
                                                isSelected ? "text-brand-700" : "text-surface-900")}>
                                                {name}
                                            </p>
                                            <p className="text-2xs text-surface-400 font-mono mt-0.5 truncate">{p.sku}</p>
                                            <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                                                {p.category && (
                                                    <span className="text-2xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded-md truncate max-w-[90px]">
                                                        {p.category.name_en}
                                                    </span>
                                                )}
                                                {price != null && (
                                                    <span className="text-2xs font-semibold text-surface-600 tabular-nums">
                                                        KES {price.toLocaleString()}
                                                    </span>
                                                )}
                                            </div>
                                        </div>

                                        {/* Mobile: chevron hint */}
                                        <svg className="w-4 h-4 text-surface-300 shrink-0 mt-1 sm:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                                        </svg>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            {/* ── BOM detail ───────────────────────────────────────────────── */}
            {/* Desktop: always visible. Mobile: visible only when a product is selected */}
            <div className={clsx(
                "flex-1 min-w-0 flex flex-col gap-4",
                showingDetail ? "flex" : "hidden sm:flex"
            )}>
                {!selectedProductId ? (
                    <div className="flex-1 flex items-center justify-center text-surface-300">
                        <div className="text-center">
                            <svg className="w-16 h-16 mx-auto mb-3 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={0.8}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                            <p className="text-sm font-medium">Select a product to view its BOM</p>
                        </div>
                    </div>
                ) : loadingBoms ? (
                    <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                ) : (
                    <>
                        {/* Header: back button on mobile + product name + action */}
                        <div className="flex items-center gap-3">
                            {/* Back to list — mobile only */}
                            <button
                                onClick={() => setSelectedProductId(null)}
                                className="sm:hidden w-8 h-8 rounded-lg bg-surface-100 flex items-center justify-center text-surface-600 active:bg-surface-200 shrink-0"
                                aria-label="Back"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <div className="flex-1 min-w-0">
                                <h3 className="font-bold text-surface-900 truncate">{selectedProduct?.name ?? selectedProduct?.sku}</h3>
                                <p className="text-xs text-surface-400">{boms.length} BOM version{boms.length !== 1 ? "s" : ""}</p>
                            </div>
                            {canEditProducts && (
                            <button onClick={() => setShowCreate(true)}
                                className="btn-primary btn-sm gap-1.5 shrink-0">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                <span className="hidden sm:inline">New BOM Version</span>
                                <span className="sm:hidden">New</span>
                            </button>
                            )}
                        </div>

                        {boms.length === 0 && (
                            <div className="card p-10 text-center text-surface-400">
                                <p className="text-sm">No BOM defined yet.</p>
                                {canEditProducts && (
                                <button onClick={() => setShowCreate(true)} className="btn-primary btn-sm mt-4">Create First BOM</button>
                                )}
                            </div>
                        )}

                        <div className="space-y-4 overflow-y-auto flex-1 pb-4">
                            {boms.map(bom => (
                                <div key={bom.id} className={clsx("card overflow-hidden",
                                    bom.is_active && "ring-2 ring-brand-300")}>
                                    <div className="px-4 py-3 border-b border-surface-100 flex items-center justify-between gap-3">
                                        <div className="flex items-center gap-2 min-w-0">
                                            <span className="text-sm font-bold text-surface-900">v{bom.version}</span>
                                            {bom.is_active && (
                                                <span className="text-2xs font-bold px-2 py-0.5 bg-brand-500 text-white rounded-full shrink-0">Active</span>
                                            )}
                                            {bom.notes && <span className="text-xs text-surface-400 italic truncate">{bom.notes}</span>}
                                        </div>
                                        <div className="flex gap-2 shrink-0">
                                            {!bom.is_active && (
                                                <button onClick={() => activateMut.mutate({ productId: selectedProductId!, bomId: bom.id })}
                                                    className="btn-secondary btn-sm">Activate</button>
                                            )}
                                            <button onClick={() => setEditingBom(bom)} className="btn-ghost btn-sm">Edit</button>
                                        </div>
                                    </div>

                                    {/* Desktop: full table */}
                                    <div className="hidden sm:block overflow-x-auto">
                                        <table className="w-full text-xs">
                                            <thead className="bg-surface-50 border-b border-surface-50">
                                                <tr>
                                                    {["Material", "Code", "Qty", "Unit", "Unit Cost", "Total Cost", "Stock"].map(h => (
                                                        <th key={h} className="px-3 py-2 text-left text-2xs font-bold text-surface-400 uppercase tracking-wide">{h}</th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-surface-50">
                                                {(bom.items ?? []).map(item => (
                                                    <tr key={item.id} className={clsx(item.is_short && "bg-danger-light/30")}>
                                                        <td className="px-3 py-2 font-medium text-surface-900">
                                                            {item.material?.name ?? item.material_name ?? "-"}
                                                        </td>
                                                        <td className="px-3 py-2 font-mono text-surface-400">
                                                            {item.material?.code ?? item.material_code ?? "-"}
                                                        </td>
                                                        <td className="px-3 py-2 tabular-nums">{fmtNum(item.quantity)}</td>
                                                        <td className="px-3 py-2 text-surface-500">{item.unit_of_measure}</td>
                                                        <td className="px-3 py-2 tabular-nums text-surface-600">
                                                            {item.material?.cost_per_unit != null
                                                                ? `KES ${fmtNum(item.material.cost_per_unit, 2)}`
                                                                : item.unit_cost != null
                                                                ? `KES ${fmtNum(item.unit_cost, 2)}`
                                                                : "-"}
                                                        </td>
                                                        <td className="px-3 py-2 tabular-nums font-semibold text-surface-900">
                                                            {item.line_cost != null
                                                                ? `KES ${fmtNum(item.line_cost, 2)}`
                                                                : item.total_cost != null
                                                                ? `KES ${fmtNum(item.total_cost, 2)}`
                                                                : "-"}
                                                        </td>
                                                        <td className={clsx("px-3 py-2 tabular-nums font-semibold",
                                                            item.is_short ? "text-danger" : "text-success")}>
                                                            {item.stock_on_hand != null ? fmtNum(item.stock_on_hand) : "-"}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                            {bom.total_cost != null && (
                                                <tfoot>
                                                    <tr className="border-t border-surface-200 bg-surface-50">
                                                        <td colSpan={5} className="px-3 py-2 text-xs font-bold text-right text-surface-700">Total BOM Cost</td>
                                                        <td className="px-3 py-2 text-xs font-bold text-surface-900 tabular-nums">KES {fmtNum(bom.total_cost, 2)}</td>
                                                        <td />
                                                    </tr>
                                                </tfoot>
                                            )}
                                        </table>
                                    </div>

                                    {/* Mobile: material cards */}
                                    <div className="sm:hidden divide-y divide-surface-50">
                                        {(bom.items ?? []).map(item => {
                                            const materialName = item.material?.name ?? item.material_name ?? "-";
                                            const code = item.material?.code ?? item.material_code ?? "-";
                                            const unitCost = item.material?.cost_per_unit ?? item.unit_cost;
                                            const totalCost = item.line_cost ?? item.total_cost;
                                            return (
                                                <div key={item.id} className={clsx(
                                                    "px-4 py-3",
                                                    item.is_short && "bg-danger-light/20"
                                                )}>
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div className="min-w-0">
                                                            <p className="text-sm font-semibold text-surface-900 truncate">{materialName}</p>
                                                            <p className="text-2xs text-surface-400 font-mono">{code}</p>
                                                        </div>
                                                        {item.is_short && (
                                                            <span className="text-2xs font-bold text-danger bg-danger-light px-1.5 py-0.5 rounded shrink-0">
                                                                Low stock
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="grid grid-cols-3 gap-2 mt-2">
                                                        <div>
                                                            <p className="text-2xs text-surface-400">Qty</p>
                                                            <p className="text-xs font-semibold text-surface-900 tabular-nums">{fmtNum(item.quantity)} {item.unit_of_measure}</p>
                                                        </div>
                                                        <div>
                                                            <p className="text-2xs text-surface-400">Unit cost</p>
                                                            <p className="text-xs font-semibold text-surface-900 tabular-nums">
                                                                {unitCost != null ? `KES ${fmtNum(unitCost, 2)}` : "-"}
                                                            </p>
                                                        </div>
                                                        <div>
                                                            <p className="text-2xs text-surface-400">Total</p>
                                                            <p className="text-xs font-semibold text-surface-900 tabular-nums">
                                                                {totalCost != null ? `KES ${fmtNum(totalCost, 2)}` : "-"}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    {item.stock_on_hand != null && (
                                                        <div className="mt-1.5 flex items-center gap-1.5">
                                                            <span className="text-2xs text-surface-400">Stock:</span>
                                                            <span className={clsx("text-2xs font-semibold tabular-nums",
                                                                item.is_short ? "text-danger" : "text-success")}>
                                                                {fmtNum(item.stock_on_hand)} {item.unit_of_measure}
                                                            </span>
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                        {bom.total_cost != null && (
                                            <div className="px-4 py-3 bg-surface-50 flex items-center justify-between border-t border-surface-100">
                                                <span className="text-xs font-bold text-surface-700">Total BOM Cost</span>
                                                <span className="text-xs font-bold text-surface-900 tabular-nums">KES {fmtNum(bom.total_cost, 2)}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </div>

            {/* Create/Edit BOM Modal */}
            {(showCreate || editingBom) && (
                <BOMEditModal
                    productId={selectedProductId!}
                    bom={editingBom}
                    onClose={() => { setShowCreate(false); setEditingBom(null); }}
                    onSaved={() => { qc.invalidateQueries({ queryKey: ["bom-detail", selectedProductId] }); setShowCreate(false); setEditingBom(null); }}
                />
            )}
        </div>
    );
}

function BOMEditModal({ productId, bom, onClose, onSaved }: {
    productId: number; bom: Bom | null; onClose: () => void; onSaved: () => void;
}) {
    const toast = useToastStore();
    const [notes, setNotes] = useState(bom?.notes ?? "");
    const [items, setItems] = useState<Partial<BomItem>[]>(
        bom?.items?.map(i => ({ ...i })) ?? [{ material_id: 0, quantity: 1, unit_of_measure: "" }]
    );

    const { data: materialsData } = useQuery({
        queryKey: ["materials-list"],
        queryFn: () => get<any>("/v1/admin/inventory/materials", { params: { per_page: "100", is_active: "1" } }),
        staleTime: 60_000,
    });
    const materials: any[] = materialsData?.data ?? [];

    const setItem = (i: number, k: string, v: any) =>
        setItems(p => { const n = [...p]; n[i] = { ...n[i], [k]: v }; return n; });

    const autoFillUnit = (i: number, materialId: number) => {
        const mat = materials.find((m: any) => m.id === materialId);
        if (mat) setItems(p => {
            const n = [...p];
            n[i] = { ...n[i], material_id: materialId, unit_of_measure: mat.unit_of_measure };
            return n;
        });
    };

    const mutation = useMutation({
        mutationFn: () => bom
            ? put(`/v1/admin/products/${productId}/bom/${bom.id}`, { notes, items })
            : post(`/v1/admin/products/${productId}/bom`, { notes, items }),
        onSuccess: () => { toast.success(bom ? "BOM updated" : "BOM created"); onSaved(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title={bom ? `Edit BOM v${bom.version}` : "New BOM"} onClose={onClose} size="xl">
            <div className="p-4 sm:p-5 space-y-4">
                {/* Items */}
                <div>
                    <div className="flex items-center justify-between mb-3">
                        <SectionHead title="Materials" />
                        <button onClick={() => setItems(p => [...p, { material_id: 0, quantity: 1, unit_of_measure: "" }])}
                            className="text-2xs text-brand-500 hover:underline font-medium">+ Add Row</button>
                    </div>

                    {/* Desktop: compact inline grid */}
                    <div className="hidden sm:block space-y-2">
                        <div className="grid grid-cols-12 gap-2 px-2 text-2xs font-bold text-surface-400 uppercase tracking-wide">
                            <span className="col-span-5">Material</span>
                            <span className="col-span-2 text-right">Qty</span>
                            <span className="col-span-2">Unit</span>
                            <span className="col-span-2">Notes</span>
                            <span />
                        </div>
                        {items.map((item, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2 items-center bg-surface-50 rounded-lg p-2">
                                <select className="col-span-5 input text-sm"
                                    value={item.material_id ?? ""}
                                    onChange={e => autoFillUnit(i, Number(e.target.value))}>
                                    <option value="">Select material…</option>
                                    {materials.map((m: any) => <option key={m.id} value={m.id}>{m.name} ({m.code})</option>)}
                                </select>
                                <input type="number" min={0.001} step={0.001}
                                    value={item.quantity ?? ""} onChange={e => setItem(i, "quantity", Number(e.target.value))}
                                    className="col-span-2 input text-sm text-right" placeholder="Qty" />
                                <input type="text" value={item.unit_of_measure ?? ""}
                                    onChange={e => setItem(i, "unit_of_measure", e.target.value)}
                                    className="col-span-2 input text-sm" placeholder="m/kg/pcs" />
                                <input type="text" value={item.notes ?? ""}
                                    onChange={e => setItem(i, "notes", e.target.value)}
                                    className="col-span-2 input text-sm" placeholder="Note…" />
                                <button onClick={() => setItems(p => p.filter((_, x) => x !== i))}
                                    className="col-span-1 flex items-center justify-center text-surface-300 hover:text-danger transition-colors"
                                    aria-label="Remove row">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        ))}
                    </div>

                    {/* Mobile: stacked cards per row */}
                    <div className="sm:hidden space-y-3">
                        {items.map((item, i) => (
                            <div key={i} className="bg-surface-50 rounded-xl p-3 space-y-2.5">
                                {/* Row header: index + remove */}
                                <div className="flex items-center justify-between">
                                    <span className="text-2xs font-bold text-surface-400 uppercase tracking-wide">
                                        Material {i + 1}
                                    </span>
                                    <button
                                        onClick={() => setItems(p => p.filter((_, x) => x !== i))}
                                        className="flex items-center gap-1 text-2xs text-danger font-medium"
                                        aria-label="Remove row"
                                    >
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Remove
                                    </button>
                                </div>

                                {/* Material select — full width */}
                                <div>
                                    <label className="text-2xs font-semibold text-surface-500 mb-1 block">Material</label>
                                    <select className="input text-sm w-full"
                                        value={item.material_id ?? ""}
                                        onChange={e => autoFillUnit(i, Number(e.target.value))}>
                                        <option value="">Select material…</option>
                                        {materials.map((m: any) => <option key={m.id} value={m.id}>{m.name} ({m.code})</option>)}
                                    </select>
                                </div>

                                {/* Qty + Unit side by side */}
                                <div className="grid grid-cols-2 gap-2">
                                    <div>
                                        <label className="text-2xs font-semibold text-surface-500 mb-1 block">Quantity</label>
                                        <input type="number" min={0.001} step={0.001}
                                            value={item.quantity ?? ""}
                                            onChange={e => setItem(i, "quantity", Number(e.target.value))}
                                            className="input text-sm w-full" placeholder="e.g. 2.5" />
                                    </div>
                                    <div>
                                        <label className="text-2xs font-semibold text-surface-500 mb-1 block">Unit</label>
                                        <input type="text"
                                            value={item.unit_of_measure ?? ""}
                                            onChange={e => setItem(i, "unit_of_measure", e.target.value)}
                                            className="input text-sm w-full" placeholder="m / kg / pcs" />
                                    </div>
                                </div>

                                {/* Notes — full width */}
                                <div>
                                    <label className="text-2xs font-semibold text-surface-500 mb-1 block">Note <span className="text-surface-300 font-normal">(optional)</span></label>
                                    <input type="text"
                                        value={item.notes ?? ""}
                                        onChange={e => setItem(i, "notes", e.target.value)}
                                        className="input text-sm w-full" placeholder="Any note for this material…" />
                                </div>
                            </div>
                        ))}

                        {items.length === 0 && (
                            <p className="text-xs text-surface-400 text-center py-4">No materials yet — tap + Add Row to begin.</p>
                        )}
                    </div>
                </div>

                <div>
                    <label className="label">BOM Notes</label>
                    <input type="text" value={notes} onChange={e => setNotes(e.target.value)}
                        placeholder="e.g. Standard production run, Summer collection…" className="input" />
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || items.length === 0 || items.some(i => !i.material_id)}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : bom ? "Update BOM" : "Create BOM"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TAB: QUALITY CONTROL
// ═══════════════════════════════════════════════════════════════════════════════

function QualityControlTab() {
    const qc = useQueryClient();
    const navigate = useNavigate();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [filterStatus, setFilterStatus] = useState<"all" | "pending" | "passed" | "failed">("pending");
    const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ["production-qc"] }), [qc]);

    // Fetch orders that are at or past QC stage
    const { data, isLoading } = useQuery({
        queryKey: ["production-qc", filterStatus],
        queryFn: () => {
            const statusMap: Record<string, string> = {
                pending: "qc_pending",
                passed:  "qc_passed",
                failed:  "qc_failed",
                all:     "qc_pending,qc_passed,qc_failed,completed",
            };
            return get<any>("/v1/admin/production-orders", {
                params: { status: statusMap[filterStatus], per_page: "100" },
            });
        },
        staleTime: 0,
        refetchInterval: 20_000,
    });

    const orders: ProductionOrder[] = data?.data ?? [];
    const stats = {
        pending: orders.filter(o => o.status === "qc_pending").length,
        passed:  orders.filter(o => o.status === "qc_passed").length,
        failed:  orders.filter(o => o.status === "qc_failed").length,
    };

    return (
        <div className="flex-1 min-h-0 flex flex-col gap-4">
                {/* QC stats */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {[
                        { key: "pending", label: "Awaiting QC",   color: "text-purple-600",     bg: "bg-purple-50"    },
                        { key: "passed",  label: "QC Passed",     color: "text-success",        bg: "bg-success-light" },
                        { key: "failed",  label: "QC Failed",     color: "text-danger",         bg: "bg-danger-light" },
                    ].map(({ key, label, color, bg }) => (
                        <div key={key} className={clsx("card p-4 text-left", bg)}>
                            <p className="text-2xs text-surface-500 font-medium">{label}</p>
                            <p className={clsx("text-3xl font-bold mt-1", color)}>{(stats as any)[key]}</p>
                        </div>
                    ))}
                </div>

                {/* Filter tabs */}
                <div className="flex gap-1 bg-surface-100 p-1 rounded-xl w-fit">
                    {[
                        { k: "pending", label: "Awaiting" },
                        { k: "passed",  label: "Passed"   },
                        { k: "failed",  label: "Failed"   },
                        { k: "all",     label: "All"      },
                    ].map(({ k, label }) => (
                        <button key={k} onClick={() => setFilterStatus(k as any)}
                            className={clsx("px-4 py-1.5 rounded-lg text-xs font-semibold transition-all",
                                filterStatus === k ? "bg-white text-surface-900 shadow-sm" : "text-surface-500 hover:text-surface-700")}>
                            {label}
                        </button>
                    ))}
                </div>

                {/* Orders list */}
                {isLoading ? (
                    <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                ) : orders.length === 0 ? (
                    <div className="flex-1 flex items-center justify-center text-surface-300">
                        <div className="text-center py-16">
                            <div className="flex items-center justify-center w-16 h-16 bg-surface-100 rounded-2xl mb-3 text-surface-400">
                                <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            </div>
                            <p className="text-sm font-medium text-surface-400">
                                {filterStatus === "pending" ? "No orders awaiting QC" : `No ${filterStatus} QC results`}
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="overflow-y-auto flex-1 space-y-2 pb-4">
                        {orders.map(o => {
                            const isCustomer = !!o.customer_order_id;
                            const days = daysUntil(o.due_date);
                            return (
                                <div key={o.id} onClick={() => setSelectedId(o.id === selectedId ? null : o.id)}
                                    className={clsx("card p-4 cursor-pointer hover:shadow-md transition-all",
                                        selectedId === o.id && "ring-2 ring-brand-400")}>
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex flex-wrap items-center gap-2 mb-1">
                                                <span className="font-mono text-xs font-bold text-surface-500">{o.order_number}</span>
                                                <StatusBadge status={o.status} />
                                                <PriorityBadge priority={o.priority} />
                                                <OrderTypePill isCustomer={isCustomer} />
                                            </div>
                                            <p className="font-semibold text-sm text-surface-900">{o.product_name}</p>
                                            <div className="flex gap-4 mt-1 text-xs text-surface-500">
                                                <span>Qty: <strong className="text-surface-900">{o.quantity}</strong></span>
                                                {isCustomer && o.customer_order && (
                                                    <span className="text-indigo-600">{o.customer_order.order_number}</span>
                                                )}
                                                <DueBadge date={o.due_date} />
                                            </div>
                                        </div>

                                        {/* QC action inline for pending */}
                                        {o.status === "qc_pending" && (
                                            <div className="text-xs text-purple-600 font-semibold bg-purple-50 rounded-xl px-3 py-2 shrink-0 flex items-center gap-1.5">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                Inspect Now
                                            </div>
                                        )}
                                        {o.status === "qc_passed" && (
                                            <div className="text-xs text-success font-semibold flex items-center gap-1.5">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                                Ready to Stock
                                            </div>
                                        )}
                                        {o.status === "qc_failed" && (
                                            <div className="text-xs text-danger font-semibold flex items-center gap-1.5">
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                                Rework Needed
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex justify-end mt-2 pt-2 border-t border-surface-50">
                                        <button
                                            onClick={(e) => { e.stopPropagation(); navigate(`/production/orders/${o.id}`); }}
                                            className="text-2xs text-brand-500 hover:text-brand-700 font-semibold"
                                        >
                                            View Detail →
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

            {selectedId && (
                <OrderDetailModal orderId={selectedId} onClose={() => setSelectedId(null)} onUpdated={refresh} />
            )}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// INDIVIDUAL PAGE COMPONENTS
// Each is a standalone route - no shared tab shell.
// ═══════════════════════════════════════════════════════════════════════════════

function PageShell({ title, subtitle, children, headerRight }: {
    title: string;
    subtitle: string;
    children: React.ReactNode;
    headerRight?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col h-full animate-fade-in" style={{ height: "calc(100vh - 112px)" }}>
            <div className="shrink-0 mb-4">
                {/* On desktop, title and headerRight sit on the same row.
                    On mobile, headerRight drops below the subtitle. */}
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                    <div>
                        <h1 className="page-title">{title}</h1>
                        <p className="page-subtitle">{subtitle}</p>
                    </div>
                    {headerRight && (
                        <div className="shrink-0">{headerRight}</div>
                    )}
                </div>
            </div>
            <div className="flex-1 min-h-0 flex flex-col">
                {children}
            </div>
        </div>
    );
}

export default function ProductionOrdersPage() {
    return (
        <PageShell title="Production Orders" subtitle="Create, confirm and track all production orders">
            <ProductionOrdersTab />
        </PageShell>
    );
}

export function ProductionWIPPage() {
    const { can } = usePermissions();
    const canViewFull = can("production.view");
    const isWorker    = !canViewFull && can("production.worker");

    // Pre-select the logged-in user so they immediately see their own tasks.
    // Workers are always locked to "mine"; admins/managers default to themselves
    // but can switch to any user via the dropdown.
    const currentUserId = useAuthStore(s => s.user?.id ?? null);
    const canViewUsers  = can("users.view");

    const [selectedUserId, setSelectedUserId] = useState<"all" | "mine" | string>(
        isWorker ? "mine" : currentUserId ? String(currentUserId) : "all"
    );

    // Fetch production users for the dropdown (admin only, requires users.view)
    const { data: usersData } = useQuery({
        queryKey: ["wip-production-users"],
        queryFn: () => get<{ data: WIPUser[] }>("/v1/admin/users", {
            params: { exclude_type: "customer", per_page: "100" } as any,
        }),
        enabled: canViewFull && canViewUsers,
        staleTime: 5 * 60_000,
        retry: false,
    });
    const productionUsers: WIPUser[] = usersData?.data ?? [];

    // Build the filter UI node that goes into the page header
    const currentUser = useAuthStore(s => s.user);
    const currentUserName = currentUser
        ? `${currentUser.first_name} ${(currentUser as any).last_name ?? ""}`.trim()
        : "";

    const filterNode = (
        <div className="flex items-center gap-2 flex-wrap">

            {/* ── User filter ─────────────────────────────────────────────────
                canViewUsers = true  → full dropdown (All Workers + user list)
                canViewFull only     → static name pill (no permission to browse users)
                isWorker             → static "My Orders" pill
            ──────────────────────────────────────────────────────────────── */}

            {canViewFull && canViewUsers && (
                <div className="relative">
                    <select
                        value={selectedUserId}
                        onChange={e => setSelectedUserId(e.target.value)}
                        className={clsx(
                            "appearance-none pl-8 pr-8 py-1.5 rounded-xl text-xs font-semibold border transition-all cursor-pointer",
                            selectedUserId !== "all"
                                ? "bg-brand-500 text-white border-brand-500"
                                : "bg-white text-surface-700 border-surface-200 hover:border-surface-300"
                        )}
                    >
                        <option value="all">All Workers</option>
                        {/* "Me" shortcut — always at the top of the list */}
                        {currentUserId && (
                            <option value={String(currentUserId)}>
                                Me ({currentUserName})
                            </option>
                        )}
                        {productionUsers
                            .filter(u => u.id !== currentUserId)
                            .map(u => (
                                <option key={u.id} value={String(u.id)}>
                                    {u.first_name} {u.last_name}
                                </option>
                            ))}
                    </select>
                    <svg className={clsx("pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5",
                        selectedUserId !== "all" ? "text-white" : "text-surface-400")}
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                    </svg>
                    <svg className={clsx("pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 w-3 h-3",
                        selectedUserId !== "all" ? "text-white/70" : "text-surface-400")}
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            )}

            {/* Has production.view but NOT users.view — show own name as a static pill */}
            {canViewFull && !canViewUsers && currentUserName && (
                <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-500 text-white text-xs font-semibold">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                    </svg>
                    {currentUserName}
                </div>
            )}

            {isWorker && (
                <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-50 border border-brand-200 text-brand-700 text-xs font-semibold">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                    </svg>
                    My Orders
                </div>
            )}

            {/* Clear button — only shown to users who can switch filters */}
            {canViewFull && canViewUsers && selectedUserId !== "all" && (
                <button
                    onClick={() => setSelectedUserId("all")}
                    className="hidden sm:flex items-center gap-0.5 text-2xs font-semibold text-danger hover:text-danger/80"
                >
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Clear
                </button>
            )}
        </div>
    );

    return (
        <PageShell
            title="Work in Progress"
            subtitle="Live kanban view of orders moving through production stages"
            headerRight={filterNode}
        >
            <WIPTab
                selectedUserId={selectedUserId}
                setSelectedUserId={setSelectedUserId}
                productionUsers={productionUsers}
                canViewFull={canViewFull}
                isWorker={isWorker}
            />
        </PageShell>
    );
}

export function ProductionBOMPage() {
    return (
        <PageShell title="Bill of Materials" subtitle="Define and manage the materials required to produce each product">
            <BOMTab />
        </PageShell>
    );
}

export function ProductionQCPage() {
    return (
        <PageShell title="Quality Control" subtitle="Inspect finished goods, record results and release to inventory">
            <QualityControlTab />
        </PageShell>
    );
}