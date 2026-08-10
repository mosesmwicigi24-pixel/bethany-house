// src/pages/insights/WorldMapHero.tsx
//
// The traffic-vs-money picture in one image: countries are FILLED by visits
// (brand ramp) and countries that actually bought carry a green dot sized by
// revenue. Somewhere large and pale with no dot is exactly the story the old
// two-table layout buried.
//
// The topology is imported, not fetched: src/assets/world-countries-110m.json
// ships in the repo and is bundled into this lazily-loaded route. Nothing here
// touches the network, so the strict CSP and the offline service worker are
// both satisfied.
import { useMemo, useRef, useState } from "react";
import { ComposableMap, Geographies, Geography, Marker } from "react-simple-maps";
import { clsx } from "clsx";
import { fmtKes } from "@/api/expenses";
import type { MarketRow } from "@/api/marketing";
import worldTopology from "@/assets/world-countries-110m.json";
import {
    buildVisitScale,
    fmtConversion,
    fmtInt,
    flagOf,
    MAP_NO_DATA,
    MAP_STROKE,
    nameOf,
} from "./insightsShared";

// Equal Earth, fitted offline to this exact viewBox against the non-Antarctic
// world (d3 geoEqualEarth().fitExtent). Hard-coded rather than measured at
// runtime so the map never reflows while data loads.
const MAP_WIDTH = 800;
const MAP_HEIGHT = 360;
const PROJECTION_CONFIG = { scale: 149.1, center: [0, 6.9] as [number, number] };

const HOVER_FILL = "#b23514";      // brand 700
const HOVER_FILL_EMPTY = "#dcdfd9";
const ORDER_DOT = "#16a34a";       // success 600 — money
const ORDER_DOT_RING = "#ffffff";

interface GeoFeature {
    rsmKey: string;
    properties: { iso?: string; name?: string };
    geometry: { type: string; coordinates: number[][][] | number[][][][] };
}

/**
 * A point inside the country's largest landmass, for the revenue dot.
 *
 * NOT the spherical centroid: geoCentroid area-weights every part, which drags
 * the United States into the Pacific between Alaska and Hawaii. This takes the
 * bounding-box centre of the biggest ring, unwrapping first when that ring
 * straddles the antimeridian (otherwise Russia lands in the North Sea).
 */
function markerPoint(geometry: GeoFeature["geometry"]): [number, number] | null {
    const rings: number[][][] =
        geometry.type === "Polygon"
            ? [(geometry.coordinates as number[][][])[0]]
            : geometry.type === "MultiPolygon"
              ? (geometry.coordinates as number[][][][]).map((p) => p[0])
              : [];
    if (rings.length === 0) return null;

    const area = (ring: number[][]) => {
        let a = 0;
        for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            a += (ring[j][0] + ring[i][0]) * (ring[j][1] - ring[i][1]);
        }
        return Math.abs(a / 2);
    };

    let best = rings[0];
    let bestArea = -1;
    for (const ring of rings) {
        const a = area(ring);
        if (a > bestArea) { bestArea = a; best = ring; }
    }

    let x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity;
    for (const [x, y] of best) {
        if (x < x0) x0 = x;
        if (x > x1) x1 = x;
        if (y < y0) y0 = y;
        if (y > y1) y1 = y;
    }
    if (x1 - x0 > 180) {
        x0 = Infinity; x1 = -Infinity;
        for (const [x] of best) {
            const unwrapped = x < 0 ? x + 360 : x;
            if (unwrapped < x0) x0 = unwrapped;
            if (unwrapped > x1) x1 = unwrapped;
        }
    }
    let lon = (x0 + x1) / 2;
    if (lon > 180) lon -= 360;
    return [lon, (y0 + y1) / 2];
}

interface HoverState {
    label: string;
    code?: string;
    row?: MarketRow;
    /** Percentages of the container, so the card follows without a resize listener. */
    x: number;
    y: number;
}

export function WorldMapHero({ markets }: { markets: MarketRow[] }) {
    const [hover, setHover] = useState<HoverState | null>(null);
    const frame = useRef<HTMLDivElement | null>(null);

    const byCode = useMemo(() => {
        const map = new Map<string, MarketRow>();
        for (const row of markets) map.set(row.country_code.toUpperCase(), row);
        return map;
    }, [markets]);

    const scale = useMemo(() => buildVisitScale(markets.map((m) => m.visits)), [markets]);
    const maxRevenue = useMemo(() => Math.max(0, ...markets.map((m) => m.revenue)), [markets]);

    // Dot radius on a sqrt scale, so AREA tracks money — the standard fix for
    // proportional symbols otherwise reading several times too large.
    const dotRadius = (revenue: number) =>
        maxRevenue > 0 ? 3 + 6 * Math.sqrt(revenue / maxRevenue) : 3;

    const active = markets.filter((m) => m.visits > 0 || m.orders > 0).length;

    function trackHover(event: React.MouseEvent, next: Omit<HoverState, "x" | "y">) {
        const box = frame.current?.getBoundingClientRect();
        if (!box) return;
        setHover({
            ...next,
            x: ((event.clientX - box.left) / box.width) * 100,
            y: ((event.clientY - box.top) / box.height) * 100,
        });
    }

    return (
        <div className="card overflow-hidden">
            <div className="flex flex-col gap-1 px-5 pt-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 className="font-semibold text-surface-900">
                        Where the world browses, and where it buys
                    </h3>
                    <p className="mt-0.5 text-xs text-surface-500">
                        Shading is visits. Green dots mark the countries that placed an order, sized by revenue.
                    </p>
                </div>
                <p className="shrink-0 text-xs tabular-nums text-surface-400 sm:pt-1">
                    {fmtInt(active)} countries with activity
                </p>
            </div>

            {/* Below ~560px the countries stop being distinguishable, so the map
                keeps its size and scrolls sideways rather than shrinking into a
                smudge. The tooltip is anchored to the inner frame, so it travels
                with the map when scrolled. */}
            <div className="mt-2 overflow-x-auto">
            <div ref={frame} className="relative min-w-[560px]" onMouseLeave={() => setHover(null)}>
                <ComposableMap
                    projection="geoEqualEarth"
                    projectionConfig={PROJECTION_CONFIG}
                    width={MAP_WIDTH}
                    height={MAP_HEIGHT}
                    style={{ width: "100%", height: "auto", display: "block" }}
                >
                    <Geographies geography={worldTopology}>
                        {({ geographies }: { geographies: GeoFeature[] }) => {
                            // Antarctica is 8% of the map's ink and 0% of the
                            // market; the viewBox is fitted without it.
                            const shown = geographies.filter((geo) => geo.properties.iso !== "AQ");

                            const shapes = shown.map((geo) => {
                                const code = geo.properties.iso;
                                const row = code ? byCode.get(code) : undefined;
                                return (
                                    <Geography
                                        key={geo.rsmKey}
                                        geography={geo}
                                        fill={row ? scale.colorFor(row.visits) : MAP_NO_DATA}
                                        stroke={MAP_STROKE}
                                        strokeWidth={0.4}
                                        onMouseMove={(e: React.MouseEvent) =>
                                            trackHover(e, {
                                                label: code ? nameOf(code) : (geo.properties.name ?? "Unknown"),
                                                code,
                                                row,
                                            })
                                        }
                                        onMouseLeave={() => setHover(null)}
                                        style={{
                                            default: { outline: "none" },
                                            hover: { outline: "none", fill: row ? HOVER_FILL : HOVER_FILL_EMPTY },
                                            pressed: { outline: "none" },
                                        }}
                                    />
                                );
                            });

                            // Painted after every fill, so the dots are never
                            // covered by a neighbouring country.
                            const dots = shown.flatMap((geo) => {
                                const code = geo.properties.iso;
                                const row = code ? byCode.get(code) : undefined;
                                if (!row || row.orders === 0) return [];
                                const point = markerPoint(geo.geometry);
                                if (!point) return [];
                                return [
                                    <Marker key={`dot-${geo.rsmKey}`} coordinates={point}>
                                        <circle
                                            r={dotRadius(row.revenue)}
                                            fill={ORDER_DOT}
                                            fillOpacity={0.85}
                                            stroke={ORDER_DOT_RING}
                                            strokeWidth={1.2}
                                            style={{ pointerEvents: "none" }}
                                        />
                                    </Marker>,
                                ];
                            });

                            return <>{shapes}{dots}</>;
                        }}
                    </Geographies>
                </ComposableMap>

                {hover && (
                    <div
                        role="tooltip"
                        // The card clips its overflow, so the card anchors itself
                        // against whichever edge the cursor is near instead of
                        // always centring and disappearing off the side or top.
                        className={clsx(
                            "pointer-events-none absolute z-10 w-max max-w-[15rem] rounded-lg bg-surface-900/95 px-3 py-2 text-xs text-white shadow-lg",
                            hover.x < 20 ? "translate-x-0" : hover.x > 80 ? "-translate-x-full" : "-translate-x-1/2",
                            hover.y < 30 ? "translate-y-0" : "-translate-y-full",
                        )}
                        style={{
                            left: `${hover.x}%`,
                            top: hover.y < 30 ? `calc(${hover.y}% + 14px)` : `calc(${hover.y}% - 10px)`,
                        }}
                    >
                        <p className="font-semibold">
                            <span className="mr-1.5">{flagOf(hover.code)}</span>
                            {hover.label}
                        </p>
                        {hover.row ? (
                            <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 tabular-nums">
                                <dt className="text-surface-300">Visits</dt>
                                <dd className="text-right">{fmtInt(hover.row.visits)}</dd>
                                <dt className="text-surface-300">Orders</dt>
                                <dd className="text-right">{fmtInt(hover.row.orders)}</dd>
                                <dt className="text-surface-300">Revenue</dt>
                                <dd className="text-right">{hover.row.revenue ? fmtKes(hover.row.revenue) : "—"}</dd>
                                <dt className="text-surface-300">Conversion</dt>
                                <dd className="text-right">{fmtConversion(hover.row.conversion_pct)}</dd>
                            </dl>
                        ) : (
                            <p className="mt-0.5 text-surface-300">No visits or orders in this period.</p>
                        )}
                    </div>
                )}
            </div>
            </div>

            {/* Legend */}
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-line px-5 py-3">
                <div className="flex items-center gap-2">
                    <span className="text-2xs font-semibold uppercase tracking-wider text-surface-500">Visits</span>
                    <div className="flex flex-wrap items-center gap-1">
                        <span
                            className="h-2.5 w-4 rounded-sm ring-1 ring-inset ring-black/5"
                            style={{ backgroundColor: MAP_NO_DATA }}
                        />
                        <span className="text-2xs tabular-nums text-surface-400">0</span>
                        {scale.buckets.map((bucket) => (
                            <span key={bucket.max} className="ml-1 flex items-center gap-1">
                                <span
                                    className="h-2.5 w-4 rounded-sm ring-1 ring-inset ring-black/5"
                                    style={{ backgroundColor: bucket.color }}
                                />
                                <span className="text-2xs tabular-nums text-surface-400">≤{fmtInt(bucket.max)}</span>
                            </span>
                        ))}
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span className="text-2xs font-semibold uppercase tracking-wider text-surface-500">Revenue</span>
                    <span className="flex items-center gap-1.5">
                        <svg width="22" height="12" aria-hidden="true">
                            <circle cx="5" cy="6" r="2.5" fill={ORDER_DOT} fillOpacity={0.85} />
                            <circle cx="15" cy="6" r="5" fill={ORDER_DOT} fillOpacity={0.85} />
                        </svg>
                        <span className="text-2xs text-surface-400">dot area ∝ revenue</span>
                    </span>
                </div>
            </div>
        </div>
    );
}
