/**
 * TailorWorkloadPanel.tsx
 *
 * Shows tailor workload at assignment time inside the production order
 * assign modal. Helps managers pick the least-loaded available tailor.
 *
 * Place at: src/components/production/TailorWorkloadPanel.tsx
 *
 * Usage inside the production assign modal:
 *   import { TailorWorkloadPanel } from "@/components/production/TailorWorkloadPanel";
 *   // Add above the tailor selector:
 *   <TailorWorkloadPanel onSelect={(tailorId) => setSelectedTailor(tailorId)} />
 */

import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { intelligenceApi, type TailorWorkload } from "@/api/intelligence";
import { Spinner } from "@/components/ui/Spinner";

const workloadBg: Record<string, string> = {
    available: "border-success/30 bg-success-light/20",
    light:     "border-brand-200 bg-brand-50/40",
    moderate:  "border-warning/30 bg-warning-light/20",
    heavy:     "border-danger/20 bg-danger-light/10",
};
const workloadDot: Record<string, string> = {
    available: "bg-success",
    light:     "bg-brand-500",
    moderate:  "bg-warning",
    heavy:     "bg-danger",
};

interface Props {
    /** Called when user clicks a tailor card — passes tailor ID */
    onSelect?: (tailorId: number) => void;
    /** Currently selected tailor ID (for highlight) */
    selectedId?: number | null;
}

export function TailorWorkloadPanel({ onSelect, selectedId }: Props) {
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "workload"],
        queryFn:  intelligenceApi.tailorWorkload,
        staleTime: 30_000,
    });

    const tailors = data?.tailors ?? [];

    if (isLoading) return (
        <div className="py-4 flex items-center justify-center">
            <Spinner size="sm" />
            <span className="ml-2 text-xs text-surface-400">Loading workloads…</span>
        </div>
    );

    if (tailors.length === 0) return (
        <p className="text-xs text-surface-400 py-2">No active tailors found.</p>
    );

    return (
        <div>
            <p className="text-xs font-semibold text-surface-500 uppercase tracking-widest mb-2">
                Tailor Availability
            </p>
            <div className="grid grid-cols-1 gap-1.5 max-h-48 overflow-y-auto pr-1">
                {tailors.map((t: TailorWorkload) => (
                    <button
                        key={t.id}
                        type="button"
                        onClick={() => onSelect?.(t.id)}
                        className={clsx(
                            "flex items-center gap-3 px-3 py-2 rounded-xl border text-left transition-all",
                            "hover:shadow-sm active:scale-[0.99]",
                            selectedId === t.id
                                ? "border-brand-500 bg-brand-50 ring-1 ring-brand-500"
                                : workloadBg[t.recommendation]
                        )}
                    >
                        {/* Avatar */}
                        <div className="w-7 h-7 rounded-full bg-surface-200 flex items-center justify-center text-xs font-bold text-surface-600 shrink-0">
                            {t.name.charAt(0)}
                        </div>

                        {/* Name + tasks */}
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-surface-900 truncate">{t.name}</p>
                            <p className="text-2xs text-surface-400">
                                {t.active_tasks} active
                                {t.overdue_tasks > 0 && (
                                    <span className="text-danger font-semibold"> · {t.overdue_tasks} overdue</span>
                                )}
                                {" · "}{t.completion_rate}% rate
                            </p>
                        </div>

                        {/* Workload indicator */}
                        <div className="flex items-center gap-1.5 shrink-0">
                            <span className={clsx("w-2 h-2 rounded-full", workloadDot[t.recommendation])} />
                            <span className="text-2xs font-semibold text-surface-600 capitalize">
                                {t.recommendation}
                            </span>
                        </div>
                    </button>
                ))}
            </div>
        </div>
    );
}