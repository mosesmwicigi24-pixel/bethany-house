/**
 * ProductionAutoAssigneesPage - Settings > Production > Auto-Assignees
 *
 * Configure which users are automatically added to every new production order
 * when it is confirmed (draft → pending). Supports outlet-scoped rules so
 * e.g. the Nairobi outlet manager only gets added to Nairobi orders.
 *
 * Route: /settings/production/auto-assignees
 */

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, del } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

// ── Types ─────────────────────────────────────────────────────────────────────

interface AutoAssigneeRule {
    id: number;
    user_id: number;
    role_in_order: string;
    outlet_id: number | null;
    is_active: boolean;
    created_at: string;
    user: {
        first_name: string;
        last_name: string;
        email?: string | null;
    } | null;
    outlet: { id: number; name: string } | null;
}

interface UserOption {
    id: number;
    first_name: string;
    last_name: string;
    email?: string | null;
}

interface OutletOption {
    id: number;
    name: string;
}

const ROLE_OPTIONS = [
    { value: "observer",      label: "Observer - notified on all updates" },
    { value: "store_manager", label: "Store Manager - sign-off authority" },
    { value: "admin",         label: "Admin - full visibility" },
    { value: "tailor",        label: "Tailor - production worker" },
    { value: "qc_inspector",  label: "QC Inspector - quality control" },
    { value: "production_manager", label: "Production Manager - manages schedule" },
];

// ── Add Rule Modal ────────────────────────────────────────────────────────────

function AddRuleModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const [userId,   setUserId]   = useState<number | "">("");
    const [role,     setRole]     = useState("observer");
    const [outletId, setOutletId] = useState<number | "">("");

    // Load staff + system users - exclude customers via exclude_type param
    const { data: usersData } = useQuery({
        queryKey: ["users-for-assignees"],
        queryFn:  () => get<{ data: UserOption[] }>("/v1/admin/users", {
            params: { per_page: "100", exclude_type: "customer" } as any,
        }),
        staleTime: 5 * 60_000,
    });

    // Load outlets
    const { data: outletsData } = useQuery({
        queryKey: ["outlets-list"],
        queryFn:  () => get<{ data: OutletOption[] }>("/v1/admin/outlets"),
        staleTime: 10 * 60_000,
    });

    const users   = usersData?.data ?? [];
    const outlets = outletsData?.data ?? [];

    const mutation = useMutation({
        mutationFn: () => post("/v1/admin/production/auto-assignees", {
            user_id:       userId,
            role_in_order: role,
            outlet_id:     outletId || null,
        }),
        onSuccess: () => { toast.success("Auto-assignee rule added"); onSaved(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    const isValid = userId !== "";

    return (
        <Modal open title="Add Auto-Assignee Rule" onClose={onClose}>
            <div className="p-5 space-y-4">
                <p className="text-xs text-surface-500">
                    This person will be automatically added to every new production order that is confirmed,
                    either globally or for a specific outlet.
                </p>

                <div>
                    <label className="label">User <span className="text-danger">*</span></label>
                    <select
                        value={userId}
                        onChange={e => setUserId(Number(e.target.value) || "")}
                        className="input"
                        autoFocus
                    >
                        <option value="">Select a user…</option>
                        {(users as UserOption[]).map(u => (
                            <option key={u.id} value={u.id}>
                                {u.first_name} {u.last_name}
                                {u.email ? ` (${u.email})` : ""}
                            </option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className="label">Role on Order</label>
                    <select value={role} onChange={e => setRole(e.target.value)} className="input">
                        {ROLE_OPTIONS.map(o => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className="label">
                        Outlet Scope
                        <span className="ml-2 text-surface-400 font-normal text-2xs">(optional - leave blank for all outlets)</span>
                    </label>
                    <select
                        value={outletId}
                        onChange={e => setOutletId(Number(e.target.value) || "")}
                        className="input"
                    >
                        <option value="">All outlets (global)</option>
                        {(outlets as OutletOption[]).map(o => (
                            <option key={o.id} value={o.id}>{o.name}</option>
                        ))}
                    </select>
                    <p className="text-2xs text-surface-400 mt-1">
                        Global rules apply to every production order regardless of outlet.
                    </p>
                </div>

                <div className="flex gap-3 pt-1">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={!isValid || mutation.isPending}
                        className="btn-primary flex-1"
                    >
                        {mutation.isPending ? "Adding…" : "Add Rule"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function ProductionAutoAssigneesPage() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const [showAdd, setShowAdd] = useState(false);

    const { data, isLoading } = useQuery<AutoAssigneeRule[]>({
        queryKey: ["production-auto-assignees"],
        queryFn:  () => get<AutoAssigneeRule[]>("/v1/admin/production/auto-assignees"),
    });

    const rules = data ?? [];

    const deleteMut = useMutation({
        mutationFn: (id: number) => del(`/v1/admin/production/auto-assignees/${id}`),
        onSuccess:  () => {
            toast.success("Rule removed");
            qc.invalidateQueries({ queryKey: ["production-auto-assignees"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const ROLE_LABELS = Object.fromEntries(ROLE_OPTIONS.map(o => [o.value, o.label.split(" - ")[0]]));

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Auto-Assignee Rules</h1>
                    <p className="page-subtitle">
                        People added automatically to every confirmed production order.
                        {rules.length > 0 && ` ${rules.length} rule${rules.length !== 1 ? "s" : ""} configured.`}
                    </p>
                </div>
                <button onClick={() => setShowAdd(true)} className="btn-primary gap-2 self-start sm:self-auto">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Rule
                </button>
            </div>

            {/* How it works callout */}
            <div className="bg-brand-50 border border-brand-100 rounded-2xl px-5 py-4 space-y-1">
                <p className="text-sm font-semibold text-brand-800">How auto-assignees work</p>
                <p className="text-xs text-brand-600">
                    When a production order is confirmed (moved from Draft to the queue), the system
                    checks these rules and adds the listed people as assignees. They immediately receive
                    a notification so they know a new order is in progress. Global rules apply to all
                    outlets; outlet-scoped rules only apply when the order originates from that outlet.
                </p>
            </div>

            {/* Rules list */}
            <div className="card overflow-hidden p-0">
                {isLoading ? (
                    <div className="flex items-center justify-center py-16">
                        <Spinner />
                    </div>
                ) : rules.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-3">
                        <div className="w-12 h-12 bg-surface-100 rounded-2xl flex items-center justify-center text-surface-400">
                            <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                        </div>
                        <p className="text-sm font-medium text-surface-500">No rules configured</p>
                        <p className="text-xs text-surface-400">Add rules to automatically notify managers and staff when orders are raised.</p>
                        <button onClick={() => setShowAdd(true)} className="btn-primary btn-sm mt-1 gap-1.5">
                            Add first rule
                        </button>
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                        {/* Table header */}
                        <div className="grid grid-cols-[2fr_2fr_1fr_auto] gap-4 px-5 py-2.5 bg-surface-50 border-b border-surface-100 min-w-[560px]">
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">User</p>
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">Role on Order</p>
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">Outlet Scope</p>
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide w-8" />
                        </div>

                        <div className="divide-y divide-surface-50 min-w-[560px]">
                            {rules.map(rule => {
                                const name = rule.user
                                    ? `${rule.user.first_name} ${rule.user.last_name}`
                                    : `User #${rule.user_id}`;
                                return (
                                    <div key={rule.id}
                                        className="grid grid-cols-[2fr_2fr_1fr_auto] gap-4 items-center px-5 py-4 hover:bg-surface-50 transition-colors">
                                        {/* User */}
                                        <div className="flex items-center gap-3 min-w-0">
                                            <div className="w-8 h-8 rounded-xl bg-brand-100 flex items-center justify-center text-brand-700 font-bold text-xs shrink-0">
                                                {name[0]?.toUpperCase()}
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-surface-900 truncate">{name}</p>
                                                {rule.user?.email && (
                                                    <p className="text-2xs text-surface-400 truncate">{rule.user.email}</p>
                                                )}
                                            </div>
                                        </div>

                                        {/* Role */}
                                        <span className="text-xs text-surface-700">
                                            {ROLE_LABELS[rule.role_in_order] ?? rule.role_in_order}
                                        </span>

                                        {/* Outlet */}
                                        <span className={clsx(
                                            "text-xs font-medium",
                                            rule.outlet_id ? "text-surface-700" : "text-surface-400 italic"
                                        )}>
                                            {rule.outlet?.name ?? "All outlets"}
                                        </span>

                                        {/* Delete */}
                                        <button
                                            onClick={() => {
                                                if (confirm(`Remove ${name} from auto-assignees?`)) {
                                                    deleteMut.mutate(rule.id);
                                                }
                                            }}
                                            disabled={deleteMut.isPending}
                                            className="btn-ghost btn-icon btn-sm text-surface-400 hover:text-danger hover:bg-danger-light"
                                            aria-label="Delete"
                                            title="Remove rule"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round"
                                                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                        </div>{/* end overflow-x-auto */}
                    </>
                )}
            </div>

            {showAdd && (
                <AddRuleModal
                    onClose={() => setShowAdd(false)}
                    onSaved={() => qc.invalidateQueries({ queryKey: ["production-auto-assignees"] })}
                />
            )}
        </div>
    );
}