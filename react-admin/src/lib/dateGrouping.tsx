// src/lib/dateGrouping.tsx
//
// Shared "group by date" behavior for server-paginated transaction tables
// (Expenses, Payments, Stock Adjustments, Stock Transfers, Purchase Orders,
// Purchase Returns, Sales Orders, Shipments, etc).
//
// IMPORTANT — this groups only the CURRENT PAGE of results, not the whole
// dataset. Pagination, sorting, and filtering all stay exactly as they were:
// the API call is untouched, "page 2 of 14" still means the same thing as
// before, and the date groups simply re-partition whatever rows came back
// for that page. A group may appear to "continue" across a page boundary
// (e.g. "15 Jun" rows split across the end of page 1 and the start of page
// 2) — that's expected and consistent with how the existing pagination
// already worked before grouping was added.
//
// Usage in a table body:
//
//   const groups = groupRowsByDate(items, (item) => item.created_at);
//
//   <tbody>
//     {groups.map(group => (
//       <Fragment key={group.key}>
//         <DateGroupHeaderRow label={group.label} colSpan={9} />
//         {group.items.map(item => <tr key={item.id}>...</tr>)}
//       </Fragment>
//     ))}
//   </tbody>

import { Fragment, type ReactNode } from "react";

export interface DateGroup<T> {
    /** Stable React key for this group - the ISO date (YYYY-MM-DD) of its items. */
    key: string;
    /** Human label shown in the header row - "Today", "Yesterday", or a full date. */
    label: string;
    items: T[];
}

/**
 * Groups an already-fetched, already-sorted page of rows by calendar date.
 * Does NOT re-sort - it assumes the input is already in the order the API
 * returned (typically date descending), and just inserts breaks where the
 * calendar date changes. If the underlying data isn't date-sorted, groups
 * may repeat non-contiguously - by design, since this never second-guesses
 * the API's ordering.
 */
/**
 * Parses a date string the way the rest of this codebase expects:
 *  - Full ISO timestamps ("2026-06-15T14:30:00Z") parse normally - they carry
 *    their own timezone info.
 *  - Bare date-only strings ("2026-06-15", as returned by report_date /
 *    return_date / received_date columns) are parsed as LOCAL midnight, not
 *    UTC midnight. New Date("2026-06-15") parses as UTC, which silently
 *    shifts the calendar date backward by a day in any timezone behind UTC -
 *    the exact bug several pages already work around individually (see
 *    EodReportsPage's own fmtDate, which does iso + "T00:00:00" for this
 *    reason). Fixing it once here means callers can pass a raw date column
 *    straight through without each one re-deriving the same workaround.
 */
function parseLocalDate(raw: string): Date {
    const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(raw);
    return dateOnly ? new Date(`${raw}T00:00:00`) : new Date(raw);
}

export function groupRowsByDate<T>(
    rows: T[],
    getDate: (row: T) => string | null | undefined,
): DateGroup<T>[] {
    const groups: DateGroup<T>[] = [];
    const today     = new Date().toDateString();
    const yesterday = new Date(Date.now() - 86_400_000).toDateString();

    for (const row of rows) {
        const raw = getDate(row);
        const d   = raw ? parseLocalDate(raw) : null;

        // Rows with no usable date get their own trailing group rather than
        // being silently dropped or merged into "Today".
        const dayKey = d && !isNaN(d.getTime()) ? d.toDateString() : "__no_date__";

        const last = groups[groups.length - 1];
        if (last && last.key === dayKey) {
            last.items.push(row);
            continue;
        }

        let label: string;
        if (dayKey === "__no_date__") {
            label = "No date";
        } else if (dayKey === today) {
            label = "Today";
        } else if (dayKey === yesterday) {
            label = "Yesterday";
        } else {
            label = d!.toLocaleDateString("en-KE", { dateStyle: "long" });
        }

        groups.push({ key: dayKey, label, items: [row] });
    }

    return groups;
}

/**
 * Sticky section header row, inserted between groups of table rows.
 * Pass the table's full column count so the header spans the whole width.
 */
export function DateGroupHeaderRow({
    label,
    colSpan,
    count,
}: {
    label: string;
    colSpan: number;
    /** Optional row count shown next to the date, e.g. "3 records" */
    count?: number;
}) {
    return (
        <tr className="bg-surface-50/80">
            <td
                colSpan={colSpan}
                className="px-4 py-2 text-2xs font-semibold text-surface-400 uppercase tracking-wide sticky left-0"
            >
                {label}
                {typeof count === "number" && (
                    <span className="ml-2 font-normal text-surface-300 normal-case tracking-normal">
                        · {count} {count === 1 ? "record" : "records"}
                    </span>
                )}
            </td>
        </tr>
    );
}

/**
 * Convenience wrapper: renders a fully-grouped tbody given rows + a date
 * accessor + a row renderer. Use this when a page's row markup is simple
 * enough not to need direct access to the groups array.
 */
export function GroupedTableBody<T>({
    rows,
    getDate,
    colSpan,
    renderRow,
    showCount = false,
}: {
    rows: T[];
    getDate: (row: T) => string | null | undefined;
    colSpan: number;
    renderRow: (row: T) => ReactNode;
    showCount?: boolean;
}) {
    const groups = groupRowsByDate(rows, getDate);
    return (
        <>
            {groups.map((group) => (
                <Fragment key={group.key}>
                    <DateGroupHeaderRow
                        label={group.label}
                        colSpan={colSpan}
                        count={showCount ? group.items.length : undefined}
                    />
                    {group.items.map((row) => renderRow(row))}
                </Fragment>
            ))}
        </>
    );
}