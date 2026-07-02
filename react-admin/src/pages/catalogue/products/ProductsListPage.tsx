import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { productsApi } from "@/api/products";
import type { ProductListItem, ProductStats } from "@/api/products";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { ConfirmDialog } from "@/components/setup/FormComponents";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ── Status config ─────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
    draft: { label: "Draft", bg: "bg-surface-100", text: "text-surface-600" },
    active: { label: "Active", bg: "bg-success-light", text: "text-success" },
    inactive: {
        label: "Inactive",
        bg: "bg-warning-light",
        text: "text-warning",
    },
    archived: {
        label: "Archived",
        bg: "bg-surface-100",
        text: "text-surface-400",
    },
} as const;

// ── Bulk import modal ─────────────────────────────────────────────────────────

function BulkImportModal({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [file, setFile] = useState<File | null>(null);
    const [result, setResult] = useState<{
        imported: number;
        errors: string[];
    } | null>(null);

    const importMutation = useMutation({
        mutationFn: (f: File) => productsApi.bulkImport(f),
        onSuccess: (res) => {
            setResult({ imported: res.imported, errors: res.errors });
            qc.invalidateQueries({ queryKey: ["products"] });
            if (res.imported > 0)
                toast.success(`${res.imported} product(s) imported.`);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const downloadTemplate = async () => {
        const res = await fetch("/api/v1/admin/products/export-template", {
            headers: {
                Authorization: `Bearer ${localStorage.getItem("token")}`,
            },
        });
        const blob = await res.blob();
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = "products-import-template.csv";
        a.click();
    };

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg">
                <div className="flex items-center justify-between px-6 py-4 border-b border-surface-100">
                    <h2 className="text-base font-semibold text-surface-900">
                        Bulk Import Products
                    </h2>
                    <button
                        onClick={onClose}
                        className="btn-ghost btn-sm text-surface-400"
                    >
                        ✕
                    </button>
                </div>
                <div className="p-6 space-y-4">
                    {!result ? (
                        <>
                            <div className="bg-surface-50 border border-surface-100 rounded-xl p-4 space-y-2">
                                <p className="text-sm font-medium text-surface-700">
                                    Instructions
                                </p>
                                <ol className="text-xs text-surface-500 space-y-1 list-decimal list-inside">
                                    <li>Download the CSV template below</li>
                                    <li>
                                        Fill in your product data (one product
                                        per row)
                                    </li>
                                    <li>
                                        Required columns: sku, name_en,
                                        description_en, price_kes, category_id,
                                        status
                                    </li>
                                    <li>Upload the completed file</li>
                                </ol>
                                <button
                                    onClick={downloadTemplate}
                                    className="btn-secondary btn-sm mt-2"
                                >
                                    ⬇ Download Template
                                </button>
                            </div>

                            <div
                                className={clsx(
                                    "border-2 border-dashed rounded-xl p-8 text-center transition-colors",
                                    file
                                        ? "border-brand-300 bg-brand-50"
                                        : "border-surface-200 hover:border-surface-300",
                                )}
                            >
                                {file ? (
                                    <div>
                                        <p className="text-sm font-medium text-brand-700">
                                            {file.name}
                                        </p>
                                        <p className="text-xs text-surface-400 mt-1">
                                            {(file.size / 1024).toFixed(1)} KB
                                        </p>
                                        <button
                                            onClick={() => setFile(null)}
                                            className="text-xs text-danger mt-2 hover:underline"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                ) : (
                                    <label className="cursor-pointer">
                                        <p className="text-sm text-surface-500">
                                            Click or drag to upload CSV file
                                        </p>
                                        <p className="text-xs text-surface-400 mt-1">
                                            Max 10 MB
                                        </p>
                                        <input
                                            type="file"
                                            accept=".csv,.txt"
                                            className="hidden"
                                            onChange={(e) =>
                                                setFile(
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </label>
                                )}
                            </div>

                            <div className="flex justify-end gap-2">
                                <button
                                    onClick={onClose}
                                    className="btn-secondary btn-sm"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={() =>
                                        file && importMutation.mutate(file)
                                    }
                                    disabled={!file || importMutation.isPending}
                                    className="btn-primary btn-sm"
                                >
                                    {importMutation.isPending && (
                                        <Spinner
                                            size="xs"
                                            className="border-white/30 border-t-white"
                                        />
                                    )}
                                    Import Products
                                </button>
                            </div>
                        </>
                    ) : (
                        <div className="space-y-4">
                            <div
                                className={clsx(
                                    "rounded-xl p-4",
                                    result.imported > 0
                                        ? "bg-success-light"
                                        : "bg-surface-50",
                                )}
                            >
                                <p className="text-sm font-semibold text-surface-900">
                                    {result.imported > 0
                                        ? `✓ ${result.imported} product(s) imported`
                                        : "No products imported"}
                                </p>
                            </div>
                            {result.errors.length > 0 && (
                                <div className="bg-danger-light rounded-xl p-4 max-h-48 overflow-y-auto">
                                    <p className="text-xs font-semibold text-danger mb-2">
                                        {result.errors.length} error(s):
                                    </p>
                                    {result.errors.map((e, i) => (
                                        <p
                                            key={i}
                                            className="text-xs text-danger/80"
                                        >
                                            {e}
                                        </p>
                                    ))}
                                </div>
                            )}
                            <div className="flex justify-end gap-2">
                                <button
                                    onClick={() => {
                                        setResult(null);
                                        setFile(null);
                                    }}
                                    className="btn-secondary btn-sm"
                                >
                                    Import More
                                </button>
                                <button
                                    onClick={onClose}
                                    className="btn-primary btn-sm"
                                >
                                    Done
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── FilterChip ───────────────────────────────────────────────────────────────

function FilterChip({ label, onRemove }: { label: string; onRemove: () => void }) {
    return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-brand-50 border border-brand-200 text-brand-700 text-2xs font-medium">
            {label}
            <button
                onClick={onRemove}
                className="ml-0.5 text-brand-400 hover:text-brand-700 leading-none"
                aria-label="Remove filter"
            >
                ×
            </button>
        </span>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

export default function ProductsListPage() {
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const table = useTableState({
        defaultSortBy: "created_at",
        defaultPerPage: 20,
    });

    // ── Filter state ──────────────────────────────────────────────────────────
    const [statusFilter,     setStatusFilter]     = useState("");
    const [typeFilter,       setTypeFilter]       = useState("");
    const [categoryFilter,   setCategoryFilter]   = useState("");
    const [brandFilter,      setBrandFilter]      = useState("");
    const [featuredFilter,   setFeaturedFilter]   = useState("");
    const [producibleFilter, setProducibleFilter] = useState("");
    const [priceMin,         setPriceMin]         = useState("");
    const [priceMax,         setPriceMax]         = useState("");
    const [showMoreFilters,  setShowMoreFilters]  = useState(false);

    const [importOpen, setImportOpen] = useState(false);
    const [deleting, setDeleting] = useState<ProductListItem | null>(null);
    const { can } = usePermissions();
    const canCreate = can("products.create");
    const canImport = can("products.import");
    const canDelete = can("products.delete");

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(statusFilter     && { status:       statusFilter }),
        ...(typeFilter       && { product_type: typeFilter }),
        ...(categoryFilter   && { category_id:  categoryFilter }),
        ...(brandFilter      && { brand:        brandFilter }),
        ...(featuredFilter   && { is_featured:  featuredFilter }),
        ...(producibleFilter && { is_producible: producibleFilter }),
        ...(priceMin         && { price_min:    priceMin }),
        ...(priceMax         && { price_max:    priceMax }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["products", params],
        queryFn: () => productsApi.list(params),
    });

    const products   = data?.data       ?? [];
    const meta       = data?.meta;
    const stats      = data?.stats;
    // brands and categories come from the first (unfiltered) response and stay
    // stable via a separate reference query so dropdowns never empty themselves
    // when a filter is active.
    const { data: filterMeta } = useQuery({
        queryKey: ["products-filter-meta"],
        queryFn: () => productsApi.list({ per_page: "1" }),
        staleTime: 5 * 60 * 1000,
    });
    const availableBrands     = (filterMeta as any)?.brands     as string[]                          ?? [];
    const availableCategories = (filterMeta as any)?.categories as { id: number; name_en: string }[] ?? [];

    const deleteMutation = useMutation({
        mutationFn: (id: number) => productsApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["products"] });
            toast.success("Product deleted.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const hasActiveFilters =
        !!table.state.search || !!statusFilter || !!typeFilter ||
        !!categoryFilter || !!brandFilter || !!featuredFilter ||
        !!producibleFilter || !!priceMin || !!priceMax;

    const clearAllFilters = () => {
        table.setSearch("");
        setStatusFilter("");
        setTypeFilter("");
        setCategoryFilter("");
        setBrandFilter("");
        setFeaturedFilter("");
        setProducibleFilter("");
        setPriceMin("");
        setPriceMax("");
    };

    // Count active secondary filters for the "More filters" badge
    const moreFilterCount = [
        categoryFilter, brandFilter, featuredFilter,
        producibleFilter, priceMin, priceMax,
    ].filter(Boolean).length;

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Products</h1>
                    <p className="page-subtitle">
                        {stats
                            ? `${stats.total} products · ${stats.active} active · ${stats.draft} draft`
                            : "Loading…"}
                    </p>
                </div>
                <div className="flex gap-2 shrink-0">
                    {canImport && (
                        <button
                            onClick={() => setImportOpen(true)}
                            className="btn-secondary btn-sm"
                        >
                            ⬆ Bulk Import
                        </button>
                    )}
                    {canCreate && (
                        <button
                            onClick={() => navigate("/catalogue/products/new")}
                            className="btn-primary"
                        >
                            + New Product
                        </button>
                    )}
                </div>
            </div>

            {/* Stats bar */}
            {stats && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        { label: "Total",    value: stats.total,    color: "" },
                        { label: "Active",   value: stats.active,   color: "text-success" },
                        { label: "Draft",    value: stats.draft,    color: "text-warning" },
                        { label: "Archived", value: stats.archived, color: "text-surface-400" },
                    ].map((s) => (
                        <div key={s.label} className="card p-4 text-center">
                            <p className={clsx("text-2xl font-bold", s.color || "text-surface-900")}>
                                {s.value}
                            </p>
                            <p className="text-xs text-surface-500 mt-0.5">{s.label}</p>
                        </div>
                    ))}
                </div>
            )}

            {/* ── Filters ───────────────────────────────────────────────────── */}
            <div className="card p-4 space-y-3">
                {/* Row 1 — search + primary dropdowns + toggle */}
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    {/* Search */}
                    <div className="relative flex-1 min-w-[180px] sm:max-w-xs">
                        <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-surface-400 pointer-events-none"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round"
                                d="M21 21l-4.35-4.35M17 11A6 6 0 105 11a6 6 0 0012 0z"/>
                        </svg>
                        <input
                            className="input pl-8 w-full"
                            placeholder="Search by name, SKU, brand…"
                            value={table.state.search}
                            onChange={(e) => table.setSearch(e.target.value)}
                        />
                    </div>

                    {/* Status */}
                    <select
                        className="input sm:w-36"
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="draft">Draft</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>
                    </select>

                    {/* Type */}
                    <select
                        className="input sm:w-44"
                        value={typeFilter}
                        onChange={(e) => setTypeFilter(e.target.value)}
                    >
                        <option value="">All types</option>
                        <option value="simple">Simple</option>
                        <option value="variable">Variable</option>
                        <option value="made_to_order">Made to Order</option>
                    </select>

                    {/* More filters toggle */}
                    <button
                        onClick={() => setShowMoreFilters((v) => !v)}
                        className={clsx(
                            "btn-secondary btn-sm flex items-center gap-1.5 shrink-0",
                            showMoreFilters && "border-brand-400 bg-brand-50 text-brand-700",
                        )}
                    >
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 4h18M6 12h12M10 20h4"/>
                        </svg>
                        Filters
                        {moreFilterCount > 0 && (
                            <span className="ml-0.5 bg-brand-500 text-white text-2xs font-bold rounded-full w-4 h-4 flex items-center justify-center">
                                {moreFilterCount}
                            </span>
                        )}
                    </button>

                    {/* Clear */}
                    {hasActiveFilters && (
                        <button onClick={clearAllFilters} className="btn-ghost btn-sm text-xs text-danger shrink-0">
                            ✕ Clear all
                        </button>
                    )}
                </div>

                {/* Row 2 — extended filters (collapsible) */}
                {showMoreFilters && (
                    <div className="pt-3 border-t border-surface-100 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        {/* Category */}
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-semibold text-surface-500 uppercase tracking-wide">
                                Category
                            </label>
                            <select
                                className="input text-sm"
                                value={categoryFilter}
                                onChange={(e) => setCategoryFilter(e.target.value)}
                            >
                                <option value="">All categories</option>
                                {availableCategories.map((c) => (
                                    <option key={c.id} value={String(c.id)}>{c.name_en}</option>
                                ))}
                            </select>
                        </div>

                        {/* Brand */}
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-semibold text-surface-500 uppercase tracking-wide">
                                Brand
                            </label>
                            <select
                                className="input text-sm"
                                value={brandFilter}
                                onChange={(e) => setBrandFilter(e.target.value)}
                            >
                                <option value="">All brands</option>
                                {availableBrands.map((b) => (
                                    <option key={b} value={b}>{b}</option>
                                ))}
                            </select>
                        </div>

                        {/* Featured */}
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-semibold text-surface-500 uppercase tracking-wide">
                                Featured
                            </label>
                            <select
                                className="input text-sm"
                                value={featuredFilter}
                                onChange={(e) => setFeaturedFilter(e.target.value)}
                            >
                                <option value="">Any</option>
                                <option value="true">Featured only</option>
                                <option value="false">Not featured</option>
                            </select>
                        </div>

                        {/* Production */}
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-semibold text-surface-500 uppercase tracking-wide">
                                Production
                            </label>
                            <select
                                className="input text-sm"
                                value={producibleFilter}
                                onChange={(e) => setProducibleFilter(e.target.value)}
                            >
                                <option value="">Any</option>
                                <option value="true">Producible only</option>
                                <option value="false">Non-producible</option>
                            </select>
                        </div>

                        {/* Price min */}
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-semibold text-surface-500 uppercase tracking-wide">
                                Min price (KES)
                            </label>
                            <input
                                type="number"
                                min={0}
                                step={1}
                                className="input text-sm"
                                placeholder="0"
                                value={priceMin}
                                onChange={(e) => setPriceMin(e.target.value)}
                            />
                        </div>

                        {/* Price max */}
                        <div className="flex flex-col gap-1">
                            <label className="text-2xs font-semibold text-surface-500 uppercase tracking-wide">
                                Max price (KES)
                            </label>
                            <input
                                type="number"
                                min={0}
                                step={1}
                                className="input text-sm"
                                placeholder="Any"
                                value={priceMax}
                                onChange={(e) => setPriceMax(e.target.value)}
                            />
                        </div>
                    </div>
                )}

                {/* Active filter chips */}
                {hasActiveFilters && (
                    <div className="flex flex-wrap gap-1.5 pt-1">
                        {table.state.search && (
                            <FilterChip label={`"${table.state.search}"`} onRemove={() => table.setSearch("")} />
                        )}
                        {statusFilter && (
                            <FilterChip label={`Status: ${statusFilter}`} onRemove={() => setStatusFilter("")} />
                        )}
                        {typeFilter && (
                            <FilterChip label={`Type: ${typeFilter.replace("_", " ")}`} onRemove={() => setTypeFilter("")} />
                        )}
                        {categoryFilter && (
                            <FilterChip
                                label={`Category: ${availableCategories.find((c) => String(c.id) === categoryFilter)?.name_en ?? categoryFilter}`}
                                onRemove={() => setCategoryFilter("")}
                            />
                        )}
                        {brandFilter && (
                            <FilterChip label={`Brand: ${brandFilter}`} onRemove={() => setBrandFilter("")} />
                        )}
                        {featuredFilter && (
                            <FilterChip label={featuredFilter === "true" ? "Featured" : "Not featured"} onRemove={() => setFeaturedFilter("")} />
                        )}
                        {producibleFilter && (
                            <FilterChip label={producibleFilter === "true" ? "Producible" : "Non-producible"} onRemove={() => setProducibleFilter("")} />
                        )}
                        {(priceMin || priceMax) && (
                            <FilterChip
                                label={`Price: ${priceMin ? `KES ${Number(priceMin).toLocaleString()}` : "0"} – ${priceMax ? `KES ${Number(priceMax).toLocaleString()}` : "∞"}`}
                                onRemove={() => { setPriceMin(""); setPriceMax(""); }}
                            />
                        )}
                    </div>
                )}
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : products.length === 0 ? (
                    <div className="text-center py-16">
                        <p className="text-surface-400 text-sm mb-3">
                            {hasActiveFilters
                                ? "No products match your filters."
                                : "No products yet."}
                        </p>
                        {!hasActiveFilters && (
                            <div className="flex gap-2 justify-center">
                                {canImport && (
                                    <button
                                        onClick={() => setImportOpen(true)}
                                        className="btn-secondary btn-sm"
                                    >
                                        Import CSV
                                    </button>
                                )}
                                {canCreate && (
                                    <button
                                        onClick={() =>
                                            navigate("/catalogue/products/new")
                                        }
                                        className="btn-primary btn-sm"
                                    >
                                    Create first product
                                </button>
                                )}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px]">
                        <thead>
                            <tr className="border-b border-surface-100 bg-surface-50/50">
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Product
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                    SKU
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                    Category
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Price (KES)
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                                    Type
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {products.map((product) => {
                                const status =
                                    STATUS_CONFIG[
                                        product.status as keyof typeof STATUS_CONFIG
                                    ] ?? STATUS_CONFIG.draft;
                                return (
                                    <tr
                                        key={product.id}
                                        className="hover:bg-surface-50/50 transition-colors cursor-pointer"
                                        onClick={() =>
                                            navigate(
                                                `/catalogue/products/${product.id}`,
                                            )
                                        }
                                    >
                                        {/* Product */}
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                <div className="w-10 h-10 rounded-lg overflow-hidden shrink-0 bg-surface-100 border border-surface-200">
                                                    {product.primary_image ? (
                                                        <img
                                                            src={
                                                                product
                                                                    .primary_image
                                                                    .image_url
                                                            }
                                                            alt={
                                                                product
                                                                    .en_translation
                                                                    ?.name
                                                            }
                                                            className="w-full h-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="w-full h-full flex items-center justify-center">
                                                            <svg
                                                                className="w-5 h-5 text-surface-300"
                                                                fill="none"
                                                                viewBox="0 0 24 24"
                                                                stroke="currentColor"
                                                                strokeWidth={
                                                                    1.5
                                                                }
                                                            >
                                                                <path
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                                                                />
                                                            </svg>
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium text-surface-900 truncate max-w-[180px] sm:max-w-[220px]">
                                                        {product.en_translation
                                                            ?.name ??
                                                            "(no name)"}
                                                    </p>
                                                    <div className="flex items-center gap-1.5 mt-0.5">
                                                        {product.is_featured && (
                                                            <span className="text-amber-400 text-xs">
                                                                ★
                                                            </span>
                                                        )}
                                                        {product.is_producible && (
                                                            <span className="text-2xs bg-purple-50 text-purple-600 px-1.5 rounded">
                                                                Production
                                                            </span>
                                                        )}
                                                        {product.brand && (
                                                            <span className="text-2xs text-surface-400">
                                                                {product.brand}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        {/* SKU */}
                                        <td className="px-4 py-3 hidden sm:table-cell">
                                            <span className="text-xs font-mono text-surface-600 bg-surface-100 px-2 py-0.5 rounded">
                                                {product.sku}
                                            </span>
                                        </td>

                                        {/* Category */}
                                        <td className="px-4 py-3 hidden md:table-cell">
                                            <span className="text-sm text-surface-600">
                                                {product.category?.name_en ??
                                                    "-"}
                                            </span>
                                        </td>

                                        {/* Price */}
                                        <td className="px-4 py-3 text-right">
                                            {product.base_price ? (
                                                <div>
                                                    <p className="text-sm font-semibold text-surface-900">
                                                        {Number(
                                                            product.base_price
                                                                .regular_price,
                                                        ).toLocaleString()}
                                                    </p>
                                                    {product.base_price
                                                        .sale_price && (
                                                        <p className="text-xs text-success">
                                                            Sale:{" "}
                                                            {Number(
                                                                product
                                                                    .base_price
                                                                    .sale_price,
                                                            ).toLocaleString()}
                                                        </p>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-xs text-surface-300">
                                                    -
                                                </span>
                                            )}
                                        </td>

                                        {/* Type */}
                                        <td className="px-4 py-3 text-center hidden lg:table-cell">
                                            <span className="text-xs text-surface-500 capitalize">
                                                {product.product_type.replace(
                                                    "_",
                                                    " ",
                                                )}
                                            </span>
                                            {product.variants_count > 0 && (
                                                <p className="text-2xs text-surface-400">
                                                    {product.variants_count}{" "}
                                                    variants
                                                </p>
                                            )}
                                        </td>

                                        {/* Status */}
                                        <td className="px-4 py-3 text-center">
                                            <span
                                                className={clsx(
                                                    "text-xs font-medium px-2.5 py-1 rounded-full",
                                                    status.bg,
                                                    status.text,
                                                )}
                                            >
                                                {status.label}
                                            </span>
                                        </td>

                                        {/* Actions */}
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1 justify-end">
                                                <button
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        navigate(
                                                            `/catalogue/products/${product.id}`,
                                                        );
                                                    }}
                                                    className="btn-ghost btn-sm"
                                                    aria-label="Edit"
                                                    title="Edit"
                                                >
                                                    <EditIcon />
                                                </button>
                                                {canDelete && (
                                                    <button
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setDeleting(product);
                                                        }}
                                                        className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                                        aria-label="Delete"
                                                        title="Delete"
                                                    >
                                                        <TrashIcon />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    </div>
                )}

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-surface-100 flex items-center justify-between">
                        <p className="text-xs text-surface-500">
                            Showing {meta.from}–{meta.to} of {meta.total}
                        </p>
                        <div className="flex gap-1">
                            <button
                                disabled={meta.current_page === 1}
                                onClick={() =>
                                    table.setPage(meta.current_page - 1)
                                }
                                className="btn-ghost btn-sm text-xs disabled:opacity-40"
                            >
                                ← Prev
                            </button>
                            <button
                                disabled={meta.current_page === meta.last_page}
                                onClick={() =>
                                    table.setPage(meta.current_page + 1)
                                }
                                className="btn-ghost btn-sm text-xs disabled:opacity-40"
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* Bulk import modal */}
            <BulkImportModal
                open={importOpen}
                onClose={() => setImportOpen(false)}
            />

            {/* Delete confirm */}
            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Delete Product"
                message={`Delete "${deleting?.en_translation?.name ?? deleting?.sku}"? This cannot be undone. Archive it instead if it has order history.`}
                confirmLabel="Delete"
            />
        </div>
    );
}

const EditIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
        />
    </svg>
);
const TrashIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
        />
    </svg>
);