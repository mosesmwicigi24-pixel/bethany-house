/**
 * EmptyState.tsx
 *
 * Rich, context-aware empty state component to replace generic "No records found."
 * text across all list views and tables in the platform.
 *
 * Usage:
 *   <EmptyState
 *     icon="products"
 *     title="No products yet"
 *     description="Add your first product to start selling."
 *     action={{ label: "Create Product", href: "/catalogue/products/new" }}
 *   />
 *
 *   // Or filtered empty state (no CTA, different messaging):
 *   <EmptyState
 *     icon="search"
 *     title="No results found"
 *     description="Try adjusting your search or filters."
 *     variant="filtered"
 *   />
 */

import { Link } from "react-router-dom";
import { clsx } from "clsx";

// ─── Icon library ─────────────────────────────────────────────────────────────

type IconName =
    | "products" | "orders" | "customers" | "suppliers" | "inventory"
    | "production" | "expenses" | "shipments" | "purchase-orders" | "materials"
    | "notifications" | "activity" | "search" | "transfers" | "adjustments"
    | "reports" | "users" | "roles" | "payments" | "generic";

function EmptyIcon({ name, className }: { name: IconName; className?: string }) {
    const cls = clsx("shrink-0", className);
    const s = {
        fill: "none" as const,
        viewBox: "0 0 48 48",
        stroke: "currentColor",
        strokeWidth: 1.5,
        strokeLinecap: "round" as const,
        strokeLinejoin: "round" as const,
    };

    switch (name) {
        case "products":
            return <svg className={cls} {...s}><path d="M42 32V16a4 4 0 00-2-3.46l-14-8a4 4 0 00-4 0l-14 8A4 4 0 006 16v16a4 4 0 002 3.46l14 8a4 4 0 004 0l14-8A4 4 0 0042 32z"/><polyline points="6.54 13.92 24 24.02 41.46 13.92"/><line x1="24" y1="44.16" x2="24" y2="24"/></svg>;
        case "orders":
            return <svg className={cls} {...s}><path d="M12 4H8a4 4 0 00-4 4v24a4 4 0 004 4h24a4 4 0 004-4V8a4 4 0 00-4-4h-4"/><rect x="16" y="2" width="16" height="8" rx="2"/><line x1="16" y1="20" x2="32" y2="20"/><line x1="16" y1="28" x2="26" y2="28"/></svg>;
        case "customers":
            return <svg className={cls} {...s}><path d="M40 42v-4a8 8 0 00-8-8H16a8 8 0 00-8 8v4"/><circle cx="24" cy="16" r="8"/><path d="M44 42v-4a8 8 0 00-6-7.74"/><path d="M34 6.26A8 8 0 0134 25.74"/></svg>;
        case "suppliers":
            return <svg className={cls} {...s}><rect x="2" y="6" width="30" height="26" rx="2"/><path d="M32 16h8l6 10v8h-14V16z"/><circle cx="11" cy="37" r="5"/><circle cx="37" cy="37" r="5"/></svg>;
        case "inventory":
            return <svg className={cls} {...s}><path d="M42 32V16a4 4 0 00-2-3.46l-14-8a4 4 0 00-4 0l-14 8A4 4 0 006 16v16a4 4 0 002 3.46l14 8a4 4 0 004 0l14-8A4 4 0 0042 32z"/><polyline points="6.54 13.92 24 24.02 41.46 13.92"/><line x1="24" y1="44.16" x2="24" y2="24"/><line x1="15" y1="9" x2="33" y2="19"/></svg>;
        case "production":
            return <svg className={cls} {...s}><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="28" y="4" width="16" height="16" rx="2"/><rect x="4" y="28" width="16" height="16" rx="2"/><path d="M28 36h8m4 0h-4m0 0v-8m0 8v8"/></svg>;
        case "expenses":
            return <svg className={cls} {...s}><circle cx="24" cy="24" r="20"/><path d="M24 14v20M19 18h7.5a3.5 3.5 0 010 7H20a3.5 3.5 0 000 7H28"/></svg>;
        case "shipments":
            return <svg className={cls} {...s}><rect x="2" y="6" width="30" height="26" rx="2"/><path d="M32 16h8l6 10v8h-14V16z"/><circle cx="11" cy="37" r="5"/><circle cx="37" cy="37" r="5"/><line x1="8" y1="14" x2="22" y2="14"/><line x1="8" y1="20" x2="18" y2="20"/></svg>;
        case "purchase-orders":
            return <svg className={cls} {...s}><path d="M18 10H6a4 4 0 00-4 4v24a4 4 0 004 4h24a4 4 0 004-4V30"/><path d="M42 6l-6 6m0 0l-6-6m6 6V2"/><line x1="10" y1="24" x2="26" y2="24"/><line x1="10" y1="32" x2="20" y2="32"/></svg>;
        case "materials":
            return <svg className={cls} {...s}><path d="M4 8h40v8H4z"/><path d="M8 16v22a2 2 0 002 2h28a2 2 0 002-2V16"/><line x1="20" y1="24" x2="28" y2="24"/></svg>;
        case "notifications":
            return <svg className={cls} {...s}><path d="M36 16a12 12 0 00-24 0c0 14-6 18-6 18h36s-6-4-6-18"/><path d="M27.46 42a4 4 0 01-6.92 0"/></svg>;
        case "activity":
            return <svg className={cls} {...s}><polyline points="22 12 26 12 28 24 20 24 22 36 26 36"/><circle cx="24" cy="24" r="20"/></svg>;
        case "search":
            return <svg className={cls} {...s}><circle cx="22" cy="22" r="16"/><line x1="42" y1="42" x2="33.3" y2="33.3"/><line x1="14" y1="22" x2="30" y2="22" strokeDasharray="3 3"/></svg>;
        case "transfers":
            return <svg className={cls} {...s}><path d="M34 10l8 8-8 8"/><path d="M6 18h36"/><path d="M14 30l-8 8 8 8"/><path d="M42 38H6"/></svg>;
        case "adjustments":
            return <svg className={cls} {...s}><path d="M42 32V16a4 4 0 00-2-3.46l-14-8a4 4 0 00-4 0l-14 8A4 4 0 006 16v16"/><path d="M6 32l8 8 16-16"/></svg>;
        case "reports":
            return <svg className={cls} {...s}><rect x="6" y="4" width="36" height="40" rx="2"/><line x1="14" y1="16" x2="34" y2="16"/><line x1="14" y1="24" x2="34" y2="24"/><line x1="14" y1="32" x2="24" y2="32"/></svg>;
        case "users":
            return <svg className={cls} {...s}><path d="M40 42v-4a8 8 0 00-8-8H16a8 8 0 00-8 8v4"/><circle cx="24" cy="16" r="8"/></svg>;
        case "roles":
            return <svg className={cls} {...s}><circle cx="24" cy="16" r="8"/><path d="M8 42v-4a8 8 0 018-8h16a8 8 0 018 8v4"/><path d="M18 28l4 4 8-8"/></svg>;
        case "payments":
            return <svg className={cls} {...s}><rect x="2" y="8" width="44" height="32" rx="4"/><line x1="2" y1="20" x2="46" y2="20"/><line x1="10" y1="30" x2="18" y2="30"/><rect x="30" y="28" width="8" height="4" rx="1"/></svg>;
        default: // generic
            return <svg className={cls} {...s}><rect x="6" y="6" width="36" height="36" rx="4"/><line x1="16" y1="24" x2="32" y2="24"/><line x1="24" y1="16" x2="24" y2="32"/></svg>;
    }
}

// ─── Component ────────────────────────────────────────────────────────────────

interface EmptyStateAction {
    label: string;
    href?: string;
    onClick?: () => void;
}

interface EmptyStateProps {
    icon?: IconName;
    title: string;
    description?: string;
    action?: EmptyStateAction;
    secondaryAction?: EmptyStateAction;
    /** "default" = first-time empty (show CTA). "filtered" = search/filter returned nothing. */
    variant?: "default" | "filtered";
    className?: string;
    /** How much vertical padding to apply. Default: "md" */
    size?: "sm" | "md" | "lg";
}

export function EmptyState({
    icon = "generic",
    title,
    description,
    action,
    secondaryAction,
    variant = "default",
    className,
    size = "md",
}: EmptyStateProps) {
    const paddingClass = {
        sm: "py-8",
        md: "py-14",
        lg: "py-20",
    }[size];

    const iconSize = {
        sm: "w-10 h-10",
        md: "w-14 h-14",
        lg: "w-16 h-16",
    }[size];

    return (
        <div
            className={clsx(
                "flex flex-col items-center justify-center text-center px-6",
                paddingClass,
                className,
            )}
        >
            {/* Icon container */}
            <div
                className={clsx(
                    "rounded-2xl flex items-center justify-center mb-4",
                    size === "sm" ? "w-14 h-14" : "w-20 h-20",
                    variant === "filtered"
                        ? "bg-surface-100 text-surface-400"
                        : "bg-brand-50 text-brand-400",
                )}
            >
                <EmptyIcon name={icon} className={iconSize} />
            </div>

            {/* Text */}
            <h3 className={clsx(
                "font-semibold text-surface-800",
                size === "sm" ? "text-sm" : "text-base",
            )}>
                {title}
            </h3>
            {description && (
                <p className="mt-1.5 text-sm text-surface-500 max-w-xs leading-relaxed">
                    {description}
                </p>
            )}

            {/* Actions */}
            {(action || secondaryAction) && (
                <div className="mt-5 flex items-center gap-3 flex-wrap justify-center">
                    {action && (
                        action.href ? (
                            <Link
                                to={action.href}
                                className="btn btn-primary btn-sm"
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                {action.label}
                            </Link>
                        ) : (
                            <button
                                onClick={action.onClick}
                                className="btn btn-primary btn-sm"
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                {action.label}
                            </button>
                        )
                    )}
                    {secondaryAction && (
                        secondaryAction.href ? (
                            <Link to={secondaryAction.href} className="btn btn-secondary btn-sm">
                                {secondaryAction.label}
                            </Link>
                        ) : (
                            <button onClick={secondaryAction.onClick} className="btn btn-secondary btn-sm">
                                {secondaryAction.label}
                            </button>
                        )
                    )}
                </div>
            )}
        </div>
    );
}

// ─── ErrorState ───────────────────────────────────────────────────────────────
// Consistent error display for failed API queries (complements EmptyState)

interface ErrorStateProps {
    title?: string;
    description?: string;
    onRetry?: () => void;
    className?: string;
}

export function ErrorState({
    title = "Something went wrong",
    description = "We couldn't load this data. Please try again.",
    onRetry,
    className,
}: ErrorStateProps) {
    return (
        <div className={clsx("flex flex-col items-center justify-center text-center px-6 py-14", className)}>
            <div className="w-20 h-20 rounded-2xl bg-danger-light text-danger flex items-center justify-center mb-4">
                <svg className="w-10 h-10" fill="none" viewBox="0 0 48 48" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="24" cy="24" r="20"/>
                    <line x1="24" y1="16" x2="24" y2="26"/>
                    <circle cx="24" cy="34" r="1.5" fill="currentColor" strokeWidth={0}/>
                </svg>
            </div>
            <h3 className="font-semibold text-surface-800 text-base">{title}</h3>
            <p className="mt-1.5 text-sm text-surface-500 max-w-xs leading-relaxed">{description}</p>
            {onRetry && (
                <button onClick={onRetry} className="mt-5 btn btn-secondary btn-sm">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Try again
                </button>
            )}
        </div>
    );
}