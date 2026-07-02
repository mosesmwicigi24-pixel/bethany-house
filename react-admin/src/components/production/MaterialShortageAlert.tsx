/**
 * MaterialShortageAlert.tsx
 *
 * Inline material shortage pre-flight check for the new production order form.
 * Shows a warning if creating this order would cause material shortages across
 * the production queue.
 *
 * Place at: src/components/production/MaterialShortageAlert.tsx
 *
 * Usage inside the new production order form:
 *   import { MaterialShortageAlert } from "@/components/production/MaterialShortageAlert";
 *
 *   // After product and quantity fields:
 *   {productId && quantity > 0 && (
 *       <MaterialShortageAlert productId={productId} quantity={quantity} />
 *   )}
 */

import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { intelligenceApi, type MaterialShortage } from "@/api/intelligence";

interface Props {
    productId: number;
    quantity:  number;
}

export function MaterialShortageAlert({ productId, quantity }: Props) {
    const { data, isLoading } = useQuery({
        queryKey: ["material-preflight", productId, quantity],
        queryFn:  () => intelligenceApi.materialShortagesPreflight(productId, quantity),
        staleTime: 30_000,
        enabled:  productId > 0 && quantity > 0,
    });

    const shortages = data?.shortages ?? [];

    if (isLoading) return (
        <div className="flex items-center gap-2 text-xs text-surface-400 py-1">
            <div className="w-3 h-3 border border-surface-300 border-t-brand-500 rounded-full animate-spin"/>
            Checking material availability…
        </div>
    );

    if (shortages.length === 0) return (
        <div className="flex items-center gap-2 px-3 py-2 rounded-xl bg-success-light/40 border border-success/20 text-xs text-success-dark">
            <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
            All materials available for this order
        </div>
    );

    const hasOutOfStock = shortages.some((s: MaterialShortage) => s.severity === "out_of_stock");

    return (
        <div className={clsx(
            "rounded-xl border p-3 space-y-2",
            hasOutOfStock
                ? "bg-danger-light/30 border-danger/20"
                : "bg-warning-light/40 border-warning/20"
        )}>
            <div className="flex items-center gap-2">
                <svg className={clsx("w-4 h-4 shrink-0", hasOutOfStock ? "text-danger" : "text-warning-dark")}
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <p className={clsx("text-xs font-semibold", hasOutOfStock ? "text-danger" : "text-warning-dark")}>
                    {hasOutOfStock
                        ? `${shortages.length} material shortage(s) will block production`
                        : `${shortages.length} material(s) may be insufficient`
                    }
                </p>
            </div>

            <div className="space-y-1">
                {shortages.map((s: MaterialShortage) => (
                    <div key={s.material_id} className="flex items-center justify-between text-xs">
                        <span className="text-surface-700 font-medium">{s.material_name}</span>
                        <span className={clsx("font-semibold", hasOutOfStock ? "text-danger" : "text-warning-dark")}>
                            Need {s.total_needed} {s.unit} · Have {s.available} {s.unit} · Short {s.shortfall} {s.unit}
                        </span>
                    </div>
                ))}
            </div>

            {hasOutOfStock && (
                <p className="text-xs text-danger/80">
                    Raise a purchase order for the missing materials before confirming this production order.
                </p>
            )}
        </div>
    );
}