/**
 * TaxRateSelector.tsx
 *
 * Reusable panel for assigning applicable tax rates to a product.
 * Used in ProductFormPage's Pricing tab.
 *
 * DEPLOY TO: src/components/products/TaxRateSelector.tsx
 *
 * Props:
 *  - productId: number | null  (null = new product not yet saved)
 *  - selectedIds: number[]     (controlled from parent form state)
 *  - onChange: (ids: number[]) => void
 *  - taxInclusive: boolean     (from business settings)
 */

import { useQuery } from "@tanstack/react-query";
import { taxRatesApi } from "@/api/setup";
import type { TaxRate } from "@/types/setup";
import { clsx } from "clsx";

interface TaxRateSelectorProps {
    selectedIds: number[];
    onChange: (ids: number[]) => void;
    taxInclusive: boolean;
    /** When true, show a compact read-only summary instead of checkboxes */
    readOnly?: boolean;
}

export default function TaxRateSelector({
    selectedIds,
    onChange,
    taxInclusive,
    readOnly = false,
}: TaxRateSelectorProps) {
    const { data, isLoading } = useQuery({
        queryKey: ["tax-rates-active"],
        queryFn: () => taxRatesApi.list(),
        staleTime: 60_000,
    });

    const allRates: TaxRate[] = (data?.data ?? []).filter((r) => r.is_active);

    const toggleRate = (id: number) => {
        if (selectedIds.includes(id)) {
            onChange(selectedIds.filter((x) => x !== id));
        } else {
            onChange([...selectedIds, id]);
        }
    };

    const selectedRates = allRates.filter((r) => selectedIds.includes(r.id));
    const combinedRate  = selectedRates.reduce((sum, r) => sum + Number(r.rate), 0);

    if (isLoading) {
        return (
            <div className="text-xs text-surface-400 py-2">
                Loading tax rates…
            </div>
        );
    }

    if (allRates.length === 0) {
        return (
            <div className="text-xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                No active tax rates configured.{" "}
                <a href="/settings/taxes" className="text-brand-500 hover:underline">
                    Set up tax rates →
                </a>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {/* Mode banner */}
            <div className={clsx(
                "flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium",
                taxInclusive
                    ? "bg-blue-50 text-blue-700 border border-blue-200"
                    : "bg-amber-50 text-amber-700 border border-amber-200",
            )}>
                <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {taxInclusive
                    ? "Tax-inclusive mode: prices set above already include tax. Tax is extracted for reporting only."
                    : "Tax-exclusive mode: tax will be added on top of the prices set above at checkout."}
            </div>

            {/* Rate checkboxes */}
            {!readOnly && (
                <div className="divide-y divide-surface-50 border border-surface-100 rounded-xl overflow-hidden">
                    {allRates.map((rate) => {
                        const checked = selectedIds.includes(rate.id);
                        return (
                            <label
                                key={rate.id}
                                className={clsx(
                                    "flex items-center gap-3 px-4 py-2.5 cursor-pointer transition-colors",
                                    checked ? "bg-brand-50" : "bg-white hover:bg-surface-50",
                                )}
                            >
                                <input
                                    type="checkbox"
                                    className="accent-brand-500 w-4 h-4 flex-shrink-0"
                                    checked={checked}
                                    onChange={() => toggleRate(rate.id)}
                                />
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium text-surface-800">
                                            {rate.name}
                                        </span>
                                        <span className="text-xs font-mono text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                            {rate.code}
                                        </span>
                                        {rate.is_default && (
                                            <span className="text-2xs font-semibold uppercase tracking-wider text-brand-600 bg-brand-50 border border-brand-200 px-1.5 py-0.5 rounded">
                                                Default
                                            </span>
                                        )}
                                    </div>
                                    {rate.country_code && (
                                        <p className="text-xs text-surface-400 mt-0.5">
                                            Applies to: {rate.country_code}
                                        </p>
                                    )}
                                </div>
                                <span className={clsx(
                                    "text-sm font-bold font-mono flex-shrink-0",
                                    checked ? "text-brand-700" : "text-surface-700",
                                )}>
                                    {Number(rate.rate).toFixed(1)}%
                                </span>
                            </label>
                        );
                    })}
                </div>
            )}

            {/* Summary */}
            {selectedRates.length > 0 && (
                <div className="flex items-center justify-between px-3 py-2 bg-surface-50 rounded-lg border border-surface-100">
                    <span className="text-xs text-surface-500">
                        {selectedRates.map((r) => r.name).join(" + ")}
                    </span>
                    <span className="text-sm font-bold text-surface-900 font-mono">
                        {combinedRate.toFixed(1)}% total
                    </span>
                </div>
            )}

            {selectedRates.length === 0 && (
                <p className="text-xs text-surface-400 italic">
                    No tax rates selected - the global default rate will apply (if configured).
                </p>
            )}
        </div>
    );
}