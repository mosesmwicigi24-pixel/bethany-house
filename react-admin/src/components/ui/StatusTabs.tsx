/**
 * StatusTabs.tsx
 *
 * Horizontal pill/tab row for filtering list views by status.
 * Replaces hidden filter dropdowns with always-visible status filters,
 * following the pattern already used in the POS category tabs.
 *
 * Usage:
 *   const [status, setStatus] = useState("all");
 *
 *   <StatusTabs
 *     value={status}
 *     onChange={setStatus}
 *     tabs={ORDER_STATUS_TABS}
 *   />
 *
 *   // Pre-built tab configs for common modules:
 *   import { ORDER_STATUS_TABS, SHIPMENT_STATUS_TABS } from "./StatusTabs";
 */

import { clsx } from "clsx";

// ─── Types ────────────────────────────────────────────────────────────────────

export interface StatusTab {
    value: string;
    label: string;
    /** Optional live count shown as a badge */
    count?: number;
    /** Optional colour override for the active state */
    color?: "brand" | "success" | "warning" | "danger" | "info" | "neutral";
}

interface StatusTabsProps {
    value: string;
    onChange: (value: string) => void;
    tabs: StatusTab[];
    className?: string;
    /** "pills" = rounded pill style (default). "underline" = tab underline style. */
    variant?: "pills" | "underline";
}

// ─── Color maps ───────────────────────────────────────────────────────────────

const activeColorMap: Record<string, string> = {
    brand:   "bg-brand-500 text-white border-brand-500",
    success: "bg-success text-white border-success",
    warning: "bg-warning text-white border-warning",
    danger:  "bg-danger text-white border-danger",
    info:    "bg-info text-white border-info",
    neutral: "bg-surface-600 text-white border-surface-600",
};

const activeBadgeMap: Record<string, string> = {
    brand:   "bg-white/25 text-white",
    success: "bg-white/25 text-white",
    warning: "bg-white/25 text-white",
    danger:  "bg-white/25 text-white",
    info:    "bg-white/25 text-white",
    neutral: "bg-white/25 text-white",
};

// ─── Component ────────────────────────────────────────────────────────────────

export function StatusTabs({
    value,
    onChange,
    tabs,
    className,
    variant = "pills",
}: StatusTabsProps) {
    if (variant === "underline") {
        return (
            <div className={clsx("flex gap-1 border-b border-surface-100 overflow-x-auto no-scrollbar", className)}>
                {tabs.map((tab) => {
                    const isActive = tab.value === value;
                    return (
                        <button
                            key={tab.value}
                            onClick={() => onChange(tab.value)}
                            className={clsx(
                                "flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium whitespace-nowrap",
                                "border-b-2 -mb-px transition-colors duration-150",
                                isActive
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700 hover:border-surface-300",
                            )}
                        >
                            {tab.label}
                            {tab.count !== undefined && (
                                <span className={clsx(
                                    "inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-2xs font-bold",
                                    isActive
                                        ? "bg-brand-100 text-brand-700"
                                        : "bg-surface-100 text-surface-500",
                                )}>
                                    {tab.count > 999 ? "999+" : tab.count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        );
    }

    // Pills variant (default)
    return (
        <div className={clsx("flex gap-1.5 flex-wrap", className)}>
            {tabs.map((tab) => {
                const isActive = tab.value === value;
                const color = tab.color ?? "brand";
                return (
                    <button
                        key={tab.value}
                        onClick={() => onChange(tab.value)}
                        className={clsx(
                            "inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium",
                            "border transition-all duration-150 whitespace-nowrap",
                            isActive
                                ? activeColorMap[color]
                                : "bg-white text-surface-600 border-surface-200 hover:bg-surface-50 hover:border-surface-300",
                        )}
                    >
                        {tab.label}
                        {tab.count !== undefined && (
                            <span className={clsx(
                                "inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-2xs font-bold",
                                isActive ? activeBadgeMap[color] : "bg-surface-100 text-surface-500",
                            )}>
                                {tab.count > 999 ? "999+" : tab.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

// ─── Pre-built tab configs ────────────────────────────────────────────────────
// Import the one you need in each page. Counts are wired up per-page.

export const ORDER_STATUS_TABS: StatusTab[] = [
    { value: "all",        label: "All Orders",   color: "neutral" },
    { value: "pending",    label: "Pending",       color: "warning" },
    { value: "confirmed",  label: "Confirmed",     color: "info"    },
    { value: "processing", label: "Processing",    color: "info"    },
    { value: "shipped",    label: "Shipped",       color: "brand"   },
    { value: "delivered",  label: "Delivered",     color: "success" },
    { value: "cancelled",  label: "Cancelled",     color: "danger"  },
    { value: "returned",   label: "Returned",      color: "danger"  },
];

export const SHIPMENT_STATUS_TABS: StatusTab[] = [
    { value: "all",               label: "All",              color: "neutral" },
    { value: "pending_dispatch",  label: "Pending Dispatch", color: "warning" },
    { value: "in_transit",        label: "In Transit",       color: "brand"   },
    { value: "out_for_delivery",  label: "Out for Delivery", color: "info"    },
    { value: "delivered",         label: "Delivered",        color: "success" },
    { value: "failed",            label: "Failed",           color: "danger"  },
    { value: "returned",          label: "Returned",         color: "danger"  },
];

export const PRODUCTION_STATUS_TABS: StatusTab[] = [
    { value: "all",         label: "All",          color: "neutral" },
    { value: "draft",       label: "Draft",        color: "neutral" },
    { value: "queued",      label: "Queued",       color: "warning" },
    { value: "in_progress", label: "In Progress",  color: "info"    },
    { value: "qc_pending",  label: "QC Pending",   color: "warning" },
    { value: "completed",   label: "Completed",    color: "success" },
    { value: "cancelled",   label: "Cancelled",    color: "danger"  },
];

export const PURCHASE_ORDER_STATUS_TABS: StatusTab[] = [
    { value: "all",        label: "All",          color: "neutral" },
    { value: "draft",      label: "Draft",        color: "neutral" },
    { value: "sent",       label: "Sent",         color: "info"    },
    { value: "partial",    label: "Partial",      color: "warning" },
    { value: "received",   label: "Received",     color: "success" },
    { value: "cancelled",  label: "Cancelled",    color: "danger"  },
];

export const EXPENSE_STATUS_TABS: StatusTab[] = [
    { value: "all",       label: "All",          color: "neutral" },
    { value: "draft",     label: "Draft",        color: "neutral" },
    { value: "submitted", label: "Submitted",    color: "info"    },
    { value: "approved",  label: "Approved",     color: "success" },
    { value: "rejected",  label: "Rejected",     color: "danger"  },
    { value: "paid",      label: "Paid",         color: "success" },
];

export const LOW_STOCK_TABS: StatusTab[] = [
    { value: "all",      label: "All Alerts",   color: "neutral" },
    { value: "critical", label: "Critical",     color: "danger"  },
    { value: "low",      label: "Low",          color: "warning" },
    { value: "resolved", label: "Resolved",     color: "success" },
];