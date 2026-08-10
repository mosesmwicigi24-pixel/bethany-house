// src/pages/insights/MarketsTable.tsx
//
// One table where there used to be two. "Where visitors come from" and "Which
// countries are buying" were the same list cut in half, side by side, sorted
// differently and with no way to read across — so the fact that the biggest
// traffic source bought nothing was invisible. Every column is sortable and the
// share bar always reflects whatever is being sorted on.
import { useMemo, useState } from "react";
import { clsx } from "clsx";
import type { MarketRow } from "@/api/marketing";
import { TH, TH_R } from "@/pages/reports/reportShared";
import {
    fmtConversion,
    fmtInt,
    fmtKesCompact,
    flagOf,
    isUnknownCode,
    nameOf,
    UNKNOWN_TOOLTIP,
} from "./insightsShared";

type SortKey = "country" | "visits" | "orders" | "revenue" | "conversion";
type SortDir = "asc" | "desc";

const COLLAPSED_ROWS = 10;
/** At or above this, "and no orders" is a fact worth marking on the row. */
const QUIET_MARKET_FLOOR = 200;

const NUMERIC_COLUMNS: Record<Exclude<SortKey, "country">, (row: MarketRow) => number> = {
    visits: (r) => r.visits,
    orders: (r) => r.orders,
    revenue: (r) => r.revenue,
    // Nulls (orders but no visits) sort to the bottom either way rather than
    // pretending to be a 0% conversion.
    conversion: (r) => r.conversion_pct ?? -1,
};

const SORTABLE_COLUMNS: { key: SortKey; label: string }[] = [
    { key: "country", label: "Country" },
    { key: "visits", label: "Visits" },
    { key: "orders", label: "Orders" },
    { key: "revenue", label: "Revenue" },
    { key: "conversion", label: "Conversion" },
];

const BAR_LABEL: Record<Exclude<SortKey, "country">, string> = {
    visits: "Visits share",
    orders: "Orders share",
    revenue: "Revenue share",
    conversion: "Conversion",
};

function SortButton({
    label,
    column,
    active,
    dir,
    onSort,
    align = "right",
}: {
    label: string;
    column: SortKey;
    active: boolean;
    dir: SortDir;
    onSort: (c: SortKey) => void;
    align?: "left" | "right";
}) {
    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className={clsx(
                "group inline-flex w-full items-center gap-1 transition-colors hover:text-surface-800",
                align === "right" ? "justify-end" : "justify-start",
                active && "text-surface-900",
            )}
        >
            <span>{label}</span>
            <span
                aria-hidden="true"
                className={clsx(
                    "text-[9px] leading-none transition-opacity",
                    active ? "opacity-100" : "opacity-0 group-hover:opacity-40",
                )}
            >
                {active && dir === "asc" ? "▲" : "▼"}
            </span>
        </button>
    );
}

export function MarketsTable({ markets }: { markets: MarketRow[] }) {
    const [sort, setSort] = useState<SortKey>("revenue");
    const [dir, setDir] = useState<SortDir>("desc");
    const [expanded, setExpanded] = useState(false);

    function handleSort(column: SortKey) {
        if (column === sort) {
            setDir((d) => (d === "desc" ? "asc" : "desc"));
        } else {
            setSort(column);
            setDir(column === "country" ? "asc" : "desc");
        }
    }

    const sorted = useMemo(() => {
        const rows = [...markets];
        const sign = dir === "asc" ? 1 : -1;
        rows.sort((a, b) => {
            if (sort === "country") {
                return sign * nameOf(a.country_code).localeCompare(nameOf(b.country_code));
            }
            const read = NUMERIC_COLUMNS[sort];
            const delta = read(a) - read(b);
            // Visits break every numeric tie, so a screen full of zero-revenue
            // countries still reads largest-audience-first.
            return sign * (delta !== 0 ? delta : a.visits - b.visits);
        });
        return rows;
    }, [markets, sort, dir]);

    // The share bar tracks the active sort — sorting by revenue and then reading
    // a bar drawn from visits is how a chart lies to you.
    const barMetric: Exclude<SortKey, "country"> = sort === "country" ? "visits" : sort;
    const barMax = useMemo(
        () => Math.max(1, ...markets.map((m) => Math.max(0, NUMERIC_COLUMNS[barMetric](m)))),
        [markets, barMetric],
    );

    const visible = expanded ? sorted : sorted.slice(0, COLLAPSED_ROWS);
    const hidden = sorted.length - visible.length;

    return (
        <div className="card overflow-hidden">
            <div className="flex flex-col gap-1 px-5 pt-5 sm:flex-row sm:items-baseline sm:justify-between">
                <div>
                    <h3 className="font-semibold text-surface-900">Markets</h3>
                    <p className="mt-0.5 text-xs text-surface-500">
                        Traffic and money on the same row. Sort any column.
                    </p>
                </div>
                <p className="shrink-0 text-xs tabular-nums text-surface-400">
                    {fmtInt(markets.length)} {markets.length === 1 ? "country" : "countries"}
                </p>
            </div>

            <div className="mt-4 overflow-x-auto">
                <table className="w-full">
                    <thead>
                        <tr className="border-y border-line bg-surface-50/50">
                            {SORTABLE_COLUMNS.map(({ key, label }) => (
                                <th
                                    key={key}
                                    className={key === "country" ? TH : TH_R}
                                    aria-sort={sort === key ? (dir === "asc" ? "ascending" : "descending") : "none"}
                                >
                                    <SortButton
                                        label={label}
                                        column={key}
                                        align={key === "country" ? "left" : "right"}
                                        active={sort === key}
                                        dir={dir}
                                        onSort={handleSort}
                                    />
                                </th>
                            ))}
                            <th className={clsx(TH, "w-[22%] min-w-[7rem]")}>{BAR_LABEL[barMetric]}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {visible.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="px-4 py-12 text-center">
                                    <p className="text-sm font-medium text-surface-600">No storefront activity yet</p>
                                    <p className="mt-1 text-xs text-surface-400">
                                        Countries appear here as soon as the storefront records a visit or takes an online order.
                                    </p>
                                </td>
                            </tr>
                        ) : (
                            visible.map((row) => {
                                const buys = row.orders > 0;
                                const quiet = !buys && row.visits >= QUIET_MARKET_FLOOR;
                                const unknown = isUnknownCode(row.country_code);
                                const share = Math.max(0, NUMERIC_COLUMNS[barMetric](row));

                                return (
                                    <tr
                                        key={row.country_code}
                                        className={clsx(
                                            "transition-colors hover:bg-surface-50",
                                            // A hairline, not a fill: the row that
                                            // made money is findable at a glance
                                            // without turning the table into a
                                            // traffic light.
                                            buys && "bg-success-light/25 shadow-[inset_2px_0_0_0_#16a34a]",
                                        )}
                                    >
                                        <td className="whitespace-nowrap px-4 py-2.5">
                                            <span className="mr-2 align-middle text-base">{flagOf(row.country_code)}</span>
                                            <span
                                                className={clsx(
                                                    "align-middle font-medium",
                                                    unknown ? "text-surface-500 decoration-dotted underline-offset-4" : "text-surface-800",
                                                    unknown && "underline",
                                                )}
                                                title={unknown ? UNKNOWN_TOOLTIP : undefined}
                                            >
                                                {nameOf(row.country_code)}
                                            </span>
                                        </td>
                                        <td className={clsx("px-4 py-2.5 text-right tabular-nums", quiet ? "text-surface-500" : "font-semibold text-surface-900")}>
                                            {fmtInt(row.visits)}
                                        </td>
                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {buys ? (
                                                <span className="font-semibold text-surface-900">{fmtInt(row.orders)}</span>
                                            ) : quiet ? (
                                                <span className="badge badge-neutral whitespace-nowrap font-normal">no orders yet</span>
                                            ) : (
                                                <span className="text-surface-300">0</span>
                                            )}
                                        </td>
                                        <td className={clsx("whitespace-nowrap px-4 py-2.5 text-right tabular-nums", buys ? "text-surface-800" : "text-surface-300")}>
                                            {fmtKesCompact(row.revenue)}
                                        </td>
                                        <td
                                            className={clsx(
                                                "px-4 py-2.5 text-right tabular-nums",
                                                buys ? "font-semibold text-success-dark" : "text-surface-400",
                                            )}
                                            title={row.conversion_pct === null ? "No visits recorded for this country, so conversion cannot be calculated." : undefined}
                                        >
                                            {fmtConversion(row.conversion_pct)}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <div className="h-1.5 w-full overflow-hidden rounded-full bg-surface-100">
                                                <div
                                                    className="h-full rounded-full transition-all"
                                                    style={{
                                                        width: `${Math.min(100, (share / barMax) * 100)}%`,
                                                        backgroundColor: barMetric === "visits" ? "#f05423" : "#16a34a",
                                                    }}
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {sorted.length > COLLAPSED_ROWS && (
                <div className="border-t border-line px-5 py-3">
                    <button
                        type="button"
                        onClick={() => setExpanded((v) => !v)}
                        className="text-xs font-semibold text-brand-700 transition-colors hover:text-brand-800"
                    >
                        {expanded
                            ? `Show top ${COLLAPSED_ROWS} only`
                            : `Show all ${fmtInt(sorted.length)} countries (${fmtInt(hidden)} more)`}
                    </button>
                </div>
            )}
        </div>
    );
}
