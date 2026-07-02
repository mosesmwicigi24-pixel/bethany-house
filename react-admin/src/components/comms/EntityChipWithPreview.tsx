/**
 * EntityChipWithPreview.tsx
 *
 * Drop-in replacement for the EntityChip in CommsHub.tsx.
 * On hover it loads rich entity data via the intelligence API and shows
 * a popover with status, meta, and a link to the entity's page.
 *
 * Place at: src/components/comms/EntityChipWithPreview.tsx
 *
 * Usage in CommsHub.tsx — replace EntityChip with this component:
 *   import { EntityChipWithPreview } from "@/components/comms/EntityChipWithPreview";
 *   // In MessageContent:
 *   {chips.map((e, i) => <EntityChipWithPreview key={i} entity={e} isOwn={isOwn} />)}
 */

import { useState, useRef } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { intelligenceApi, type EntityPreview } from "@/api/intelligence";
import type { LinkedEntity } from "@/api/channels";

// Badge colour map matching backend badge.color values
const badgeColors: Record<string, string> = {
    success: "bg-success-light text-success-dark",
    warning: "bg-warning-light text-warning-dark",
    danger:  "bg-danger-light text-danger",
    brand:   "bg-brand-50 text-brand-700",
    info:    "bg-info-light text-info-dark",
    neutral: "bg-surface-100 text-surface-500",
};

interface Props {
    entity:  LinkedEntity;
    isOwn:   boolean;
}

export function EntityChipWithPreview({ entity, isOwn }: Props) {
    const navigate     = useNavigate();
    const [hovering, setHovering] = useState(false);
    const hoverTimeout = useRef<ReturnType<typeof setTimeout>>();
    const isOrder      = entity.type === "order";

    // Only fetch when hovering — stale for 2 minutes
    const { data } = useQuery({
        queryKey: ["entity-preview", entity.type, entity.id],
        queryFn:  () => intelligenceApi.entityPreviews([{ type: entity.type, id: entity.id }]),
        enabled:  hovering,
        staleTime: 2 * 60_000,
    });

    const preview: EntityPreview | undefined = data?.previews[`${entity.type}:${entity.id}`];

    const onMouseEnter = () => {
        hoverTimeout.current = setTimeout(() => setHovering(true), 200);
    };
    const onMouseLeave = () => {
        clearTimeout(hoverTimeout.current);
        setHovering(false);
    };

    return (
        <div className="relative inline-block" onMouseEnter={onMouseEnter} onMouseLeave={onMouseLeave}>
            {/* Chip */}
            <button
                onClick={() => navigate(isOrder ? `/sales/orders/${entity.id}` : `/production/orders/${entity.id}`)}
                className={[
                    "inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-xs font-bold",
                    "border transition-colors cursor-pointer",
                    isOwn
                        ? "bg-white/15 border-white/30 text-white hover:bg-white/25"
                        : isOrder
                            ? "bg-brand-50 border-brand-200 text-brand-700 hover:bg-brand-100"
                            : "bg-purple-50 border-purple-200 text-purple-700 hover:bg-purple-100",
                ].join(" ")}
            >
                {isOrder ? (
                    <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                ) : (
                    <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                    </svg>
                )}
                {entity.label}
                <svg className="w-2.5 h-2.5 shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
            </button>

            {/* Preview popover */}
            {hovering && (
                <div className={clsx(
                    "absolute z-50 bottom-full mb-2 left-0 w-64",
                    "bg-white rounded-xl border border-surface-200 shadow-xl p-3",
                    "animate-fade-in"
                )}>
                    {!preview ? (
                        <div className="flex items-center gap-2 text-xs text-surface-400">
                            <div className="w-3 h-3 border border-surface-300 border-t-brand-500 rounded-full animate-spin"/>
                            Loading…
                        </div>
                    ) : (
                        <>
                            <div className="flex items-start gap-2 mb-2">
                                <div className={clsx("w-6 h-6 rounded-md flex items-center justify-center shrink-0",
                                    isOrder ? "bg-brand-50" : "bg-purple-50")}>
                                    {isOrder ? (
                                        <svg className="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                        </svg>
                                    ) : (
                                        <svg className="w-3.5 h-3.5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                                        </svg>
                                    )}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-bold text-surface-900 font-mono">{preview.label}</p>
                                    <p className="text-xs text-surface-500 truncate">{preview.subtitle}</p>
                                </div>
                                <span className={clsx("text-2xs font-semibold px-1.5 py-0.5 rounded-full shrink-0",
                                    badgeColors[preview.badge.color] ?? badgeColors.neutral)}>
                                    {preview.badge.label}
                                </span>
                            </div>
                            <p className="text-xs text-surface-600 font-medium">{preview.meta}</p>
                            {preview.is_overdue && (
                                <p className="text-2xs text-danger font-semibold mt-1 flex items-center gap-1">
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Overdue
                                </p>
                            )}
                            <button
                                onClick={() => navigate(preview.url)}
                                className="mt-2 w-full text-xs text-brand-600 hover:text-brand-700 font-medium text-left transition-colors"
                            >
                                Open {isOrder ? "order" : "production order"} →
                            </button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}