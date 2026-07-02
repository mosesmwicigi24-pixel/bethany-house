import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, del } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { ConfirmDialog } from "@/components/setup/FormComponents";
import { useNavigate } from "react-router-dom";

// ── Types ─────────────────────────────────────────────────────────────────────

type ModelKey = "products" | "categories" | "users" | "customers";

interface TrashedProduct {
    id: number;
    sku: string;
    product_type: string;
    status: string;
    deleted_at: string;
    translations?: { language_code: string; name: string }[];
}

interface TrashedCategory {
    id: number;
    name_en: string;
    slug: string;
    deleted_at: string;
}

interface TrashedUser {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    deleted_at: string;
}

interface TrashedCustomer {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    customer_number: string;
    deleted_at: string;
}

type TrashedItem = TrashedProduct | TrashedCategory | TrashedUser | TrashedCustomer;

interface PaginatedResponse {
    data: TrashedItem[];
    meta: { total: number; current_page: number; last_page: number; per_page: number };
}

interface Summary {
    summary: Record<ModelKey, number>;
    total: number;
}

// ── API helpers ───────────────────────────────────────────────────────────────

const trashApi = {
    summary:      ()                          => get<Summary>("/v1/admin/trash"),
    list:         (model: ModelKey, params: Record<string, string>) =>
        get<PaginatedResponse>(`/v1/admin/trash/${model}`, { params }),
    restore:      (model: ModelKey, id: number) =>
        post<{ message: string }>(`/v1/admin/trash/${model}/${id}/restore`, {}),
    forceDelete:  (model: ModelKey, id: number) =>
        del<{ message: string }>(`/v1/admin/trash/${model}/${id}`),
    restoreAll:   (model: ModelKey) =>
        post<{ message: string; count: number }>(`/v1/admin/trash/${model}/restore-all`, {}),
    emptyModel:   (model: ModelKey) =>
        del<{ message: string; count: number }>(`/v1/admin/trash/${model}/empty`),
};

// ── Config ────────────────────────────────────────────────────────────────────

const MODEL_CONFIG: Record<ModelKey, {
    label: string;
    editPath?: (id: number) => string;
    canEdit: boolean;
    icon: React.ReactNode;
    getName: (item: TrashedItem) => string;
    getMeta: (item: TrashedItem) => string;
}> = {
    products: {
        label: "Products",
        canEdit: true,
        editPath: (id) => `/catalogue/products/${id}`,
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
            </svg>
        ),
        getName: (item) => {
            const p = item as TrashedProduct;
            return p.translations?.find(t => t.language_code === "en")?.name ?? p.sku;
        },
        getMeta: (item) => `SKU: ${(item as TrashedProduct).sku} · ${(item as TrashedProduct).product_type}`,
    },
    categories: {
        label: "Categories",
        canEdit: false,
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
            </svg>
        ),
        getName: (item) => (item as TrashedCategory).name_en,
        getMeta: (item) => `Slug: ${(item as TrashedCategory).slug}`,
    },
    users: {
        label: "Users",
        canEdit: false,
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
            </svg>
        ),
        getName: (item) => {
            const u = item as TrashedUser;
            return `${u.first_name} ${u.last_name}`.trim() || u.email;
        },
        getMeta: (item) => (item as TrashedUser).email,
    },
    customers: {
        label: "Customers",
        canEdit: true,
        editPath: (id) => `/sales/customers/${id}`,
        icon: (
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
            </svg>
        ),
        getName: (item) => {
            const c = item as TrashedCustomer;
            return `${c.first_name} ${c.last_name}`.trim() || c.email;
        },
        getMeta: (item) => {
            const c = item as TrashedCustomer;
            return `${c.customer_number}${c.email ? ` · ${c.email}` : ""}`;
        },
    },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const days = Math.floor(diff / 86_400_000);
    if (days === 0) return "Today";
    if (days === 1) return "Yesterday";
    if (days < 7) return `${days} days ago`;
    if (days < 30) return `${Math.floor(days / 7)}w ago`;
    if (days < 365) return `${Math.floor(days / 30)}mo ago`;
    return `${Math.floor(days / 365)}y ago`;
}

// ── Model list tab ────────────────────────────────────────────────────────────

function ModelTab({
    model,
    config,
}: {
    model: ModelKey;
    config: typeof MODEL_CONFIG[ModelKey];
}) {
    const toast    = useToastStore();
    const qc       = useQueryClient();
    const navigate = useNavigate();

    const [search, setSearch]                 = useState("");
    const [page, setPage]                     = useState(1);
    const [confirmRestore, setConfirmRestore] = useState<TrashedItem | null>(null);
    const [confirmDelete, setConfirmDelete]   = useState<TrashedItem | null>(null);
    const [confirmRestoreAll, setConfirmRestoreAll] = useState(false);
    const [confirmEmptyModel, setConfirmEmptyModel] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["trash", model, search, page],
        queryFn: () =>
            trashApi.list(model, {
                ...(search ? { search } : {}),
                page: String(page),
                per_page: "20",
            }),
    });

    const invalidate = useCallback(() => {
        qc.invalidateQueries({ queryKey: ["trash"] });
    }, [qc]);

    const restoreMutation = useMutation({
        mutationFn: (id: number) => trashApi.restore(model, id),
        onSuccess: (_, id) => {
            toast.success("Restored successfully.");
            setConfirmRestore(null);
            invalidate();
        },
        onError: () => { toast.error("Failed to restore."); setConfirmRestore(null); },
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => trashApi.forceDelete(model, id),
        onSuccess: () => {
            toast.success("Permanently deleted.");
            setConfirmDelete(null);
            invalidate();
        },
        onError: () => { toast.error("Failed to delete."); setConfirmDelete(null); },
    });

    const restoreAllMutation = useMutation({
        mutationFn: () => trashApi.restoreAll(model),
        onSuccess: (res) => {
            toast.success(res.message);
            setConfirmRestoreAll(false);
            invalidate();
        },
        onError: () => { toast.error("Failed to restore all."); setConfirmRestoreAll(false); },
    });

    const emptyMutation = useMutation({
        mutationFn: () => trashApi.emptyModel(model),
        onSuccess: (res) => {
            toast.success(res.message);
            setConfirmEmptyModel(false);
            invalidate();
        },
        onError: () => { toast.error("Failed to empty trash."); setConfirmEmptyModel(false); },
    });

    const items = data?.data ?? [];
    const meta  = data?.meta;

    return (
        <div className="space-y-4">
            {/* Toolbar */}
            <div className="flex items-center gap-3 flex-wrap">
                <div className="relative flex-1 min-w-48">
                    <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                        type="search"
                        placeholder={`Search deleted ${config.label.toLowerCase()}…`}
                        value={search}
                        onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                        className="input pl-9 text-sm w-full"
                    />
                </div>
                {items.length > 0 && (
                    <div className="flex gap-2 shrink-0">
                        <button
                            onClick={() => setConfirmRestoreAll(true)}
                            className="btn-secondary btn-sm gap-1.5"
                        >
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                            Restore All
                        </button>
                        <button
                            onClick={() => setConfirmEmptyModel(true)}
                            className="btn-sm border border-danger/30 text-danger bg-danger-light hover:bg-danger/10 gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
                        >
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Empty Bin
                        </button>
                    </div>
                )}
            </div>

            {/* List */}
            {isLoading ? (
                <div className="flex items-center justify-center py-16">
                    <Spinner size="lg" />
                </div>
            ) : items.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 gap-3 text-surface-400">
                    <div className="w-14 h-14 rounded-2xl bg-surface-100 flex items-center justify-center">
                        <svg className="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.25}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                    </div>
                    <p className="text-sm font-medium text-surface-500">
                        {search ? `No deleted ${config.label.toLowerCase()} match "${search}"` : `No deleted ${config.label.toLowerCase()}`}
                    </p>
                    {search && (
                        <button onClick={() => setSearch("")} className="text-xs text-brand-600 hover:underline">
                            Clear search
                        </button>
                    )}
                </div>
            ) : (
                <>
                    <div className="divide-y divide-surface-50 border border-surface-100 rounded-xl overflow-hidden">
                        {items.map((item) => (
                            <div
                                key={item.id}
                                className="flex items-center gap-3 px-4 py-3 bg-white hover:bg-surface-50 transition-colors"
                            >
                                {/* Icon */}
                                <div className="w-8 h-8 rounded-lg bg-surface-100 text-surface-500 flex items-center justify-center shrink-0">
                                    {config.icon}
                                </div>

                                {/* Info */}
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-semibold text-surface-900 truncate">
                                        {config.getName(item)}
                                    </p>
                                    <p className="text-2xs text-surface-400 truncate">
                                        {config.getMeta(item)}
                                    </p>
                                </div>

                                {/* Deleted at */}
                                <span className="text-2xs text-surface-400 shrink-0 hidden sm:block">
                                    Deleted {timeAgo(item.deleted_at)}
                                </span>

                                {/* Actions */}
                                <div className="flex items-center gap-1.5 shrink-0">
                                    {/* Edit (only for restoreable-and-editable models like products) */}
                                    {config.canEdit && config.editPath && (
                                        <button
                                            onClick={() => {
                                                // Restore first, then navigate to edit
                                                restoreMutation.mutate(item.id, {
                                                    onSuccess: () => navigate(config.editPath!(item.id)),
                                                });
                                            }}
                                            title="Restore & Edit"
                                            className="flex items-center gap-1 text-2xs px-2 py-1 rounded-lg bg-brand-50 text-brand-700 hover:bg-brand-100 transition-colors font-medium"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                            Restore & Edit
                                        </button>
                                    )}

                                    {/* Restore */}
                                    <button
                                        onClick={() => setConfirmRestore(item)}
                                        title="Restore"
                                        className="flex items-center gap-1 text-2xs px-2 py-1 rounded-lg bg-success-light text-success-dark hover:bg-success/10 transition-colors font-medium"
                                    >
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                                        Restore
                                    </button>

                                    {/* Permanent delete */}
                                    <button
                                        onClick={() => setConfirmDelete(item)}
                                        title="Delete permanently"
                                        className="w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-danger hover:bg-danger-light transition-colors"
                                        aria-label="Delete permanently"
                                    >
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Pagination */}
                    {meta && meta.last_page > 1 && (
                        <div className="flex items-center justify-between text-xs text-surface-500">
                            <span>
                                {((meta.current_page - 1) * meta.per_page) + 1}–{Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total}
                            </span>
                            <div className="flex gap-1">
                                <button
                                    onClick={() => setPage(p => p - 1)}
                                    disabled={meta.current_page === 1}
                                    className="px-2.5 py-1 rounded border border-surface-200 disabled:opacity-40 hover:bg-surface-50"
                                >
                                    ← Prev
                                </button>
                                <button
                                    onClick={() => setPage(p => p + 1)}
                                    disabled={meta.current_page === meta.last_page}
                                    className="px-2.5 py-1 rounded border border-surface-200 disabled:opacity-40 hover:bg-surface-50"
                                >
                                    Next →
                                </button>
                            </div>
                        </div>
                    )}
                </>
            )}

            {/* Confirm restore */}
            <ConfirmDialog
                open={!!confirmRestore}
                onClose={() => setConfirmRestore(null)}
                onConfirm={() => confirmRestore && restoreMutation.mutate(confirmRestore.id)}
                isLoading={restoreMutation.isPending}
                title={`Restore ${config.label.slice(0, -1)}`}
                message={`Restore "${confirmRestore ? config.getName(confirmRestore) : ""}"? It will become active again.`}
                confirmLabel="Restore"
            />

            {/* Confirm permanent delete */}
            <ConfirmDialog
                open={!!confirmDelete}
                onClose={() => setConfirmDelete(null)}
                onConfirm={() => confirmDelete && deleteMutation.mutate(confirmDelete.id)}
                isLoading={deleteMutation.isPending}
                title="Delete Permanently"
                message={`Permanently delete "${confirmDelete ? config.getName(confirmDelete) : ""}"? This cannot be undone and all associated data will be removed.`}
                confirmLabel="Delete Forever"
            />

            {/* Confirm restore all */}
            <ConfirmDialog
                open={confirmRestoreAll}
                onClose={() => setConfirmRestoreAll(false)}
                onConfirm={() => restoreAllMutation.mutate()}
                isLoading={restoreAllMutation.isPending}
                title={`Restore All ${config.label}`}
                message={`Restore all ${meta?.total ?? "deleted"} ${config.label.toLowerCase()}? They will all become active again.`}
                confirmLabel="Restore All"
            />

            {/* Confirm empty */}
            <ConfirmDialog
                open={confirmEmptyModel}
                onClose={() => setConfirmEmptyModel(false)}
                onConfirm={() => emptyMutation.mutate()}
                isLoading={emptyMutation.isPending}
                title={`Empty ${config.label} Bin`}
                message={`Permanently delete all ${meta?.total ?? ""} deleted ${config.label.toLowerCase()}? This cannot be undone.`}
                confirmLabel="Empty Bin"
            />
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function TrashPage() {
    const [activeModel, setActiveModel] = useState<ModelKey>("products");

    const { data: summaryData, isLoading: summaryLoading } = useQuery({
        queryKey: ["trash", "summary"],
        queryFn: () => trashApi.summary(),
        staleTime: 30_000,
    });

    const summary = summaryData?.summary;
    const total   = summaryData?.total ?? 0;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-xl font-bold text-surface-900 flex items-center gap-2">
                        <span className="w-8 h-8 rounded-xl bg-danger-light flex items-center justify-center">
                            <svg className="w-4 h-4 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </span>
                        Recycle Bin
                    </h1>
                    <p className="text-sm text-surface-500 mt-0.5">
                        {summaryLoading ? "Loading…" : total === 0
                            ? "No deleted items."
                            : `${total} deleted item${total !== 1 ? "s" : ""} across all models.`}
                    </p>
                </div>
                <div className="text-2xs text-surface-400 bg-warning-light border border-warning/30 rounded-xl px-3 py-2 max-w-xs text-right">
                    Items deleted more than 30 days ago should be emptied regularly to keep the database clean.
                </div>
            </div>

            {/* Model tabs */}
            <div className="card overflow-hidden">
                <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                    {(Object.entries(MODEL_CONFIG) as [ModelKey, typeof MODEL_CONFIG[ModelKey]][]).map(([key, cfg]) => {
                        const count = summary?.[key] ?? 0;
                        return (
                            <button
                                key={key}
                                onClick={() => setActiveModel(key)}
                                className={clsx(
                                    "flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap shrink-0",
                                    activeModel === key
                                        ? "border-brand-500 text-brand-700 bg-brand-50/50"
                                        : "border-transparent text-surface-500 hover:text-surface-800 hover:bg-surface-50",
                                )}
                            >
                                {cfg.icon}
                                {cfg.label}
                                {count > 0 && (
                                    <span className={clsx(
                                        "text-2xs font-bold px-1.5 py-0.5 rounded-full min-w-[1.25rem] text-center",
                                        activeModel === key
                                            ? "bg-brand-100 text-brand-700"
                                            : "bg-surface-100 text-surface-500",
                                    )}>
                                        {count}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                <div className="p-5">
                    <ModelTab
                        key={activeModel}
                        model={activeModel}
                        config={MODEL_CONFIG[activeModel]}
                    />
                </div>
            </div>

            {/* Info banner */}
            <div className="rounded-xl bg-surface-50 border border-surface-100 px-4 py-3 text-xs text-surface-500 space-y-1">
                <p className="font-semibold text-surface-700">About the Recycle Bin</p>
                <p>Items are soft-deleted when removed — they stay here until permanently deleted or restored. Restoring puts the item back exactly as it was. Permanent deletion removes all associated data and cannot be undone.</p>
                <p>Products can be restored and immediately edited. Other models are restored to their last known state.</p>
            </div>
        </div>
    );
}