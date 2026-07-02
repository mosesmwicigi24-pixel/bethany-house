/**
 * DataTable.tsx - Enhanced with bulk selection
 *
 * New features over the original:
 *   1. Optional checkbox column for multi-row selection
 *   2. Sticky bulk-action bar that appears when rows are selected
 *   3. Select-all checkbox in header (with indeterminate state)
 *   4. "Items per page" selector (15 / 25 / 50 / 100)
 *   5. Page jump input for large datasets
 *   6. Row count summary ("Showing 1–15 of 247")
 *
 * Usage (with bulk actions):
 *
 *   <DataTable
 *     columns={columns}
 *     data={data}
 *     selectable
 *     selectedKeys={selected}
 *     onSelectionChange={setSelected}
 *     bulkActions={[
 *       { label: "Mark as Dispatched", icon: "truck", onClick: (keys) => dispatch(keys) },
 *       { label: "Export selected",    icon: "download", onClick: (keys) => exportRows(keys) },
 *       { label: "Delete",             icon: "trash",    onClick: (keys) => deleteMany(keys), danger: true },
 *     ]}
 *     pagination={{ page, perPage, total, onPage, onPerPage }}
 *   />
 */

import { useRef, useEffect } from "react";
import { clsx } from "clsx";
import { Spinner } from "@/components/ui/Spinner";
import type { TableColumn } from "@/types";

// ─── Types ────────────────────────────────────────────────────────────────────

export interface BulkAction {
    label: string;
    icon?: "truck" | "download" | "trash" | "check" | "x" | "edit";
    onClick: (selectedKeys: (string | number)[]) => void;
    danger?: boolean;
    /** Only shown when count matches this condition */
    showWhen?: (count: number) => boolean;
}

export interface PaginationProps {
    page: number;
    perPage: number;
    total: number;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}

interface DataTableProps<T extends Record<string, unknown>> {
    columns: TableColumn<T>[];
    data: T[];
    isLoading?: boolean;
    sortBy?: string;
    sortDir?: "asc" | "desc";
    onSort?: (key: string) => void;
    emptyMessage?: string;
    rowKey?: keyof T;
    onRowClick?: (row: T) => void;
    // ── New: selection ────────────────────────────────────────
    selectable?: boolean;
    selectedKeys?: (string | number)[];
    onSelectionChange?: (keys: (string | number)[]) => void;
    bulkActions?: BulkAction[];
    // ── New: pagination ───────────────────────────────────────
    pagination?: PaginationProps;
}

// ─── Bulk action icons ────────────────────────────────────────────────────────

function BulkIcon({ name }: { name?: string }) {
    const cls = "w-3.5 h-3.5";
    const s = { fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    switch (name) {
        case "truck":    return <svg className={cls} {...s}><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
        case "download": return <svg className={cls} {...s}><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>;
        case "trash":    return <svg className={cls} {...s}><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>;
        case "check":    return <svg className={cls} {...s}><polyline points="20 6 9 17 4 12"/></svg>;
        case "x":        return <svg className={cls} {...s}><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>;
        case "edit":     return <svg className={cls} {...s}><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>;
        default:         return null;
    }
}

// ─── Indeterminate checkbox ───────────────────────────────────────────────────

function IndeterminateCheckbox({ checked, indeterminate, onChange, label }: {
    checked: boolean;
    indeterminate?: boolean;
    onChange: (checked: boolean) => void;
    label?: string;
}) {
    const ref = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = !!indeterminate;
    }, [indeterminate]);

    return (
        <input
            ref={ref}
            type="checkbox"
            checked={checked}
            aria-label={label}
            onChange={(e) => onChange(e.target.checked)}
            className="w-4 h-4 rounded border-surface-300 text-brand-500 focus:ring-brand-500 cursor-pointer"
        />
    );
}

// ─── Bulk action bar ──────────────────────────────────────────────────────────

function BulkActionBar({ count, actions, onClear }: {
    count: number;
    actions: BulkAction[];
    onClear: () => void;
}) {
    const visibleActions = actions.filter((a) => !a.showWhen || a.showWhen(count));
    return (
        <div className="flex items-center gap-3 px-4 py-2.5 bg-brand-50 border-b border-brand-100 animate-slide-down">
            <span className="text-sm font-medium text-brand-700">
                {count} {count === 1 ? "item" : "items"} selected
            </span>
            <div className="flex items-center gap-2 ml-2">
                {visibleActions.map((action) => (
                    <button
                        key={action.label}
                        onClick={() => action.onClick([])}   // caller injects selectedKeys via closure
                        className={clsx(
                            "inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors",
                            action.danger
                                ? "bg-danger-light text-danger hover:bg-red-100 border border-danger/20"
                                : "bg-white text-surface-700 hover:bg-surface-50 border border-surface-200",
                        )}
                    >
                        <BulkIcon name={action.icon} />
                        {action.label}
                    </button>
                ))}
            </div>
            <button
                onClick={onClear}
                className="ml-auto text-xs text-brand-500 hover:text-brand-700 transition-colors"
                aria-label="Clear selection"
            >
                Clear
            </button>
        </div>
    );
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows({ cols, rows, selectable }: { cols: number; rows: number; selectable?: boolean }) {
    return (
        <>
            {Array.from({ length: rows }).map((_, i) => (
                <tr key={i}>
                    {selectable && <td className="w-10 px-4 py-3"><div className="skeleton w-4 h-4 rounded" /></td>}
                    {Array.from({ length: cols }).map((_, j) => (
                        <td key={j} className="px-4 py-3">
                            <div className="skeleton h-4 rounded" style={{ width: j === 0 ? "60%" : j % 2 === 0 ? "80%" : "40%" }} />
                        </td>
                    ))}
                </tr>
            ))}
        </>
    );
}

// ─── Per-page selector ────────────────────────────────────────────────────────

const PER_PAGE_OPTIONS = [15, 25, 50, 100];

// ─── Sort icon ────────────────────────────────────────────────────────────────

function SortIcon({ active, dir }: { active: boolean; dir?: "asc" | "desc" }) {
    return (
        <span className={clsx("inline-flex flex-col gap-px ml-0.5", active ? "text-brand-500" : "text-surface-300")}>
            <svg className="w-2.5 h-2.5" viewBox="0 0 10 6" fill="currentColor" opacity={!active || dir === "asc" ? 1 : 0.3}>
                <path d="M5 0L9.33 6H.67L5 0z" />
            </svg>
            <svg className="w-2.5 h-2.5 -mt-1" viewBox="0 0 10 6" fill="currentColor" opacity={!active || dir === "desc" ? 1 : 0.3}>
                <path d="M5 6L.67 0H9.33L5 6z" />
            </svg>
        </span>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export function DataTable<T extends Record<string, unknown>>({
    columns,
    data,
    isLoading,
    sortBy,
    sortDir,
    onSort,
    emptyMessage = "No records found.",
    rowKey = "id" as keyof T,
    onRowClick,
    selectable = false,
    selectedKeys = [],
    onSelectionChange,
    bulkActions = [],
    pagination,
}: DataTableProps<T>) {
    const allKeys = data.map((row) => row[rowKey] as string | number);
    const allSelected = allKeys.length > 0 && allKeys.every((k) => selectedKeys.includes(k));
    const someSelected = allKeys.some((k) => selectedKeys.includes(k));

    const toggleAll = (checked: boolean) => {
        onSelectionChange?.(checked ? allKeys : []);
    };

    const toggleRow = (key: string | number) => {
        if (selectedKeys.includes(key)) {
            onSelectionChange?.(selectedKeys.filter((k) => k !== key));
        } else {
            onSelectionChange?.([...selectedKeys, key]);
        }
    };

    // Inject selectedKeys into bulk action onClick handlers
    const wiredActions: BulkAction[] = bulkActions.map((a) => ({
        ...a,
        onClick: () => a.onClick(selectedKeys),
    }));

    const totalPages = pagination ? Math.ceil(pagination.total / pagination.perPage) : 1;

    return (
        <div className="flex flex-col gap-0">
            {/* Bulk action bar */}
            {selectable && selectedKeys.length > 0 && (
                <BulkActionBar
                    count={selectedKeys.length}
                    actions={wiredActions}
                    onClear={() => onSelectionChange?.([])}
                />
            )}

            {/* Table */}
            <div className="table-wrapper">
                <table className="table">
                    <thead>
                        <tr>
                            {selectable && (
                                <th className="w-10 px-4">
                                    <IndeterminateCheckbox
                                        checked={allSelected}
                                        indeterminate={someSelected && !allSelected}
                                        onChange={toggleAll}
                                        label="Select all rows"
                                    />
                                </th>
                            )}
                            {columns.map((col) => (
                                <th
                                    key={String(col.key)}
                                    style={col.width ? { width: col.width } : undefined}
                                    className={clsx(col.sortable && onSort && "cursor-pointer select-none hover:text-surface-700")}
                                    onClick={() => col.sortable && onSort?.(String(col.key))}
                                >
                                    <span className="inline-flex items-center gap-1">
                                        {col.label}
                                        {col.sortable && onSort && (
                                            <SortIcon
                                                active={sortBy === String(col.key)}
                                                dir={sortBy === String(col.key) ? sortDir : undefined}
                                            />
                                        )}
                                    </span>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {isLoading ? (
                            <SkeletonRows cols={columns.length} rows={pagination?.perPage ?? 8} selectable={selectable} />
                        ) : data.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length + (selectable ? 1 : 0)} className="text-center py-12 text-surface-400">
                                    {emptyMessage}
                                </td>
                            </tr>
                        ) : (
                            data.map((row) => {
                                const key = row[rowKey] as string | number;
                                const isSelected = selectedKeys.includes(key);
                                return (
                                    <tr
                                        key={String(key)}
                                        onClick={() => onRowClick?.(row)}
                                        className={clsx(
                                            onRowClick && "cursor-pointer",
                                            isSelected && "bg-brand-50/60",
                                        )}
                                    >
                                        {selectable && (
                                            <td
                                                className="w-10 px-4"
                                                onClick={(e) => { e.stopPropagation(); toggleRow(key); }}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => toggleRow(key)}
                                                    className="w-4 h-4 rounded border-surface-300 text-brand-500 focus:ring-brand-500 cursor-pointer"
                                                />
                                            </td>
                                        )}
                                        {columns.map((col) => (
                                            <td key={String(col.key)}>
                                                {col.render ? col.render(row) : String(row[col.key as keyof T] ?? "-")}
                                            </td>
                                        ))}
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {/* Pagination footer - driven by the PaginationProps passed to DataTable */}
            {pagination && (
                <div className="flex items-center justify-between gap-4 px-4 py-3 border-t border-surface-100 bg-white rounded-b-xl flex-wrap">
                    {/* Left: count summary + per-page */}
                    <div className="flex items-center gap-4 text-xs text-surface-500">
                        <span>
                            Showing{" "}
                            <strong className="text-surface-700">
                                {Math.min((pagination.page - 1) * pagination.perPage + 1, pagination.total)}–{Math.min(pagination.page * pagination.perPage, pagination.total)}
                            </strong>{" "}
                            of <strong className="text-surface-700">{pagination.total.toLocaleString()}</strong>
                        </span>
                        <div className="flex items-center gap-1.5">
                            <span>Rows:</span>
                            <select
                                value={pagination.perPage}
                                onChange={(e) => pagination.onPerPage(Number(e.target.value))}
                                className="input py-0.5 px-2 text-xs w-16"
                            >
                                {PER_PAGE_OPTIONS.map((n) => (
                                    <option key={n} value={n}>{n}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Right: page navigation */}
                    <div className="flex items-center gap-1.5">
                        <button
                            onClick={() => pagination.onPage(1)}
                            disabled={pagination.page <= 1}
                            className="btn-icon btn-ghost btn-sm disabled:opacity-30"
                            aria-label="First page"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M11 19l-7-7 7-7M18 19l-7-7 7-7"/></svg>
                        </button>
                        <button
                            onClick={() => pagination.onPage(pagination.page - 1)}
                            disabled={pagination.page <= 1}
                            className="btn-icon btn-ghost btn-sm disabled:opacity-30"
                            aria-label="Previous page"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>

                        {/* Page indicator */}
                        <div className="flex items-center gap-1 text-xs text-surface-600">
                            <span>Page</span>
                            <input
                                type="number"
                                min={1}
                                max={totalPages}
                                value={pagination.page}
                                onChange={(e) => {
                                    const v = parseInt(e.target.value);
                                    if (v >= 1 && v <= totalPages) pagination.onPage(v);
                                }}
                                className="input py-0.5 px-2 text-xs w-14 text-center"
                            />
                            <span>of {totalPages}</span>
                        </div>

                        <button
                            onClick={() => pagination.onPage(pagination.page + 1)}
                            disabled={pagination.page >= totalPages}
                            className="btn-icon btn-ghost btn-sm disabled:opacity-30"
                            aria-label="Next page"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button
                            onClick={() => pagination.onPage(totalPages)}
                            disabled={pagination.page >= totalPages}
                            className="btn-icon btn-ghost btn-sm disabled:opacity-30"
                            aria-label="Last page"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13 5l7 7-7 7M6 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
// ─── Standalone Pagination ────────────────────────────────────────────────────
//
// Legacy-compatible named export used by pages that import
// { DataTable, Pagination } from "@/components/ui/DataTable".
//
// Props match the original Pagination component interface:
//   page, lastPage, total, from, to, isLoading?, onPage
//
// Pages that already pass these props continue to work unchanged.

interface StandalonePaginationProps {
    page: number;
    lastPage: number;
    total: number;
    from: number;
    to: number;
    isLoading?: boolean;
    onPage: (page: number) => void;
}

function getPageNumbers(current: number, last: number): (number | "...")[] {
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const pages: (number | "...")[] = [1];
    if (current > 3) pages.push("...");
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) {
        pages.push(i);
    }
    if (current < last - 2) pages.push("...");
    pages.push(last);
    return pages;
}

export function Pagination({
    page,
    lastPage,
    total,
    from,
    to,
    isLoading,
    onPage,
}: StandalonePaginationProps) {
    if (lastPage <= 1) return null;

    return (
        <div className="flex items-center justify-between px-4 py-3 border-t border-surface-100 text-xs text-surface-500">
            <span>
                {isLoading ? (
                    <span className="skeleton h-3 w-24 rounded inline-block" />
                ) : (
                    <>Showing {from}–{to} of {total}</>
                )}
            </span>
            <div className="flex items-center gap-1">
                <button
                    onClick={() => onPage(1)}
                    disabled={page === 1 || isLoading}
                    className="btn-ghost btn-sm px-2 disabled:opacity-30"
                    aria-label="First page"
                >
                    «
                </button>
                <button
                    onClick={() => onPage(page - 1)}
                    disabled={page === 1 || isLoading}
                    className="btn-ghost btn-sm px-2 disabled:opacity-30"
                    aria-label="Previous page"
                >
                    ‹
                </button>

                {getPageNumbers(page, lastPage).map((p, i) =>
                    p === "..." ? (
                        <span key={`ellipsis-${i}`} className="px-2 text-surface-400">…</span>
                    ) : (
                        <button
                            key={p}
                            onClick={() => onPage(Number(p))}
                            disabled={isLoading}
                            className={clsx(
                                "btn btn-sm w-8 h-8 p-0 text-xs",
                                p === page
                                    ? "bg-brand-500 text-white hover:bg-brand-600"
                                    : "btn-ghost",
                            )}
                        >
                            {p}
                        </button>
                    ),
                )}

                <button
                    onClick={() => onPage(page + 1)}
                    disabled={page === lastPage || isLoading}
                    className="btn-ghost btn-sm px-2 disabled:opacity-30"
                    aria-label="Next page"
                >
                    ›
                </button>
                <button
                    onClick={() => onPage(lastPage)}
                    disabled={page === lastPage || isLoading}
                    className="btn-ghost btn-sm px-2 disabled:opacity-30"
                    aria-label="Last page"
                >
                    »
                </button>
            </div>
        </div>
    );
}