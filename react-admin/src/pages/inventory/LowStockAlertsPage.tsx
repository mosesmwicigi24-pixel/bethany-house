import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { lowStockApi } from "@/api/lowStock";
import type { LowStockAlert } from "@/api/lowStock";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ── Low stock summary widget (reusable on Dashboard) ─────────────────────────

export function LowStockWidget() {
    const { data, isLoading } = useQuery({
        queryKey: ["low-stock-summary"],
        queryFn: () => lowStockApi.list("all"),
        refetchInterval: 60_000,
    });

    const summary = data?.summary;
    const critical = (data?.data ?? [])
        .filter((a) => a.severity === "out_of_stock")
        .slice(0, 5);

    if (isLoading)
        return (
            <div className="card p-4">
                <Spinner size="sm" />
            </div>
        );

    return (
        <div className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-surface-100 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-surface-900">
                    Low Stock Alerts
                </h3>
                {summary && summary.total > 0 && (
                    <span className="text-xs font-medium bg-danger-light text-danger px-2 py-0.5 rounded-full">
                        {summary.total} alert{summary.total !== 1 ? "s" : ""}
                    </span>
                )}
            </div>

            {!summary || summary.total === 0 ? (
                <div className="px-4 py-6 text-center">
                    <p className="text-sm text-success font-medium">
                        ✓ All stock levels are healthy
                    </p>
                </div>
            ) : (
                <>
                    {/* Summary row */}
                    <div className="grid grid-cols-2 divide-x divide-y divide-surface-100 border-b border-surface-100 sm:grid-cols-4 sm:divide-y-0">
                        {[
                            {
                                label: "Products Out",
                                value: summary.products_out_of_stock,
                                color: "text-danger",
                            },
                            {
                                label: "Products Low",
                                value: summary.products_low_stock,
                                color: "text-warning",
                            },
                            {
                                label: "Materials Out",
                                value: summary.materials_out_of_stock,
                                color: "text-danger",
                            },
                            {
                                label: "Materials Low",
                                value: summary.materials_low_stock,
                                color: "text-warning",
                            },
                        ].map((s) => (
                            <div
                                key={s.label}
                                className="py-3 px-3 text-center"
                            >
                                <p
                                    className={clsx(
                                        "text-lg font-bold",
                                        s.color,
                                    )}
                                >
                                    {s.value}
                                </p>
                                <p className="text-2xs text-surface-400 mt-0.5 leading-tight">
                                    {s.label}
                                </p>
                            </div>
                        ))}
                    </div>

                    {/* Critical items */}
                    {critical.length > 0 && (
                        <div className="divide-y divide-surface-50">
                            {critical.map((alert, i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <div className="w-2 h-2 rounded-full bg-danger shrink-0" />
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-surface-900 truncate">
                                            {alert.type === "product"
                                                ? alert.product?.name
                                                : alert.material?.name}
                                        </p>
                                        <p className="text-xs text-surface-400">
                                            {alert.type === "product"
                                                ? `${alert.outlet?.name} · ${alert.variant?.variant_name ?? "Base"}`
                                                : `${alert.material?.category ?? "Material"}`}
                                        </p>
                                    </div>
                                    <span className="text-xs font-bold text-danger tabular-nums shrink-0">
                                        {alert.quantity} {alert.unit ?? ""}
                                    </span>
                                </div>
                            ))}
                            {summary.total > 5 && (
                                <div className="px-4 py-2 text-center">
                                    <p className="text-xs text-surface-400">
                                        +{summary.total - 5} more alerts
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

// ── Threshold edit modal ──────────────────────────────────────────────────────

function ThresholdModal({
    alert,
    onClose,
}: {
    alert: LowStockAlert;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [reorderPoint, setReorderPoint] = useState(alert.reorder_point);
    const [reorderQuantity, setReorderQuantity] = useState(0);

    const mutation = useMutation({
        mutationFn: () =>
            lowStockApi.updateThreshold(alert.id, {
                reorder_point: reorderPoint,
                reorder_quantity: reorderQuantity,
            }),
        onSuccess: () => {
            toast.success("Threshold updated.");
            qc.invalidateQueries({ queryKey: ["low-stock"] });
            qc.invalidateQueries({ queryKey: ["low-stock-summary"] });
            qc.invalidateQueries({ queryKey: ["stock-levels"] });
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const name =
        alert.type === "product" ? alert.product?.name : alert.material?.name;

    return (
        <Modal
            open
            onClose={onClose}
            title="Edit Alert Threshold"
            size="sm"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending}
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Save
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="text-sm">
                    <p className="font-semibold text-surface-900">{name}</p>
                    {alert.type === "product" && (
                        <p className="text-surface-400 text-xs mt-0.5">
                            {alert.outlet?.name}{" "}
                            {alert.variant && `· ${alert.variant.variant_name}`}
                        </p>
                    )}
                    <p className="text-surface-400 text-xs mt-0.5">
                        Current stock:{" "}
                        <span className="font-semibold text-surface-700">
                            {alert.quantity}
                        </span>
                    </p>
                </div>
                <Field
                    label="Reorder Point"
                    hint="Alert fires when stock falls to this level"
                >
                    <FieldInput
                        type="number"
                        min="0"
                        className="input"
                        value={reorderPoint}
                        onChange={(e) =>
                            setReorderPoint(parseInt(e.target.value) || 0)
                        }
                    />
                </Field>
                <Field
                    label="Reorder Quantity"
                    hint="Suggested purchase quantity"
                >
                    <FieldInput
                        type="number"
                        min="0"
                        className="input"
                        value={reorderQuantity}
                        onChange={(e) =>
                            setReorderQuantity(parseInt(e.target.value) || 0)
                        }
                        placeholder="0"
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function LowStockAlertsPage() {
    const [typeFilter, setTypeFilter] = useState<
        "all" | "products" | "materials"
    >("all");
    const [severityFilter, setSeverityFilter] = useState<
        "" | "out_of_stock" | "low_stock"
    >("");
    const [editingAlert, setEditingAlert] = useState<LowStockAlert | null>(
        null,
    );
    const { can } = usePermissions();
    const canAdjust = can("inventory.adjust");

    const { data, isLoading, refetch } = useQuery({
        queryKey: ["low-stock", typeFilter],
        queryFn: () => lowStockApi.list(typeFilter),
        refetchInterval: 60_000,
    });

    const alerts = data?.data ?? [];
    const summary = data?.summary;

    const filtered = severityFilter
        ? alerts.filter((a) => a.severity === severityFilter)
        : alerts;

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Low Stock Alerts</h1>
                    <p className="page-subtitle">
                        {summary
                            ? `${summary.total} alert${summary.total !== 1 ? "s" : ""} · ${summary.products_out_of_stock + summary.materials_out_of_stock} out of stock · auto-refreshes every minute`
                            : "Loading…"}
                    </p>
                </div>
                <button
                    onClick={() => refetch()}
                    className="btn-secondary btn-sm text-xs self-start"
                >
                    ↺ Refresh
                </button>
            </div>

            {/* Summary cards */}
            {summary && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        {
                            label: "Products Out",
                            value: summary.products_out_of_stock,
                            color: "text-danger",
                            bg: "bg-danger-light",
                            filter: "out_of_stock" as const,
                        },
                        {
                            label: "Products Low",
                            value: summary.products_low_stock,
                            color: "text-warning",
                            bg: "bg-warning-light",
                            filter: "low_stock" as const,
                        },
                        {
                            label: "Materials Out",
                            value: summary.materials_out_of_stock,
                            color: "text-danger",
                            bg: "bg-danger-light",
                            filter: "out_of_stock" as const,
                        },
                        {
                            label: "Materials Low",
                            value: summary.materials_low_stock,
                            color: "text-warning",
                            bg: "bg-warning-light",
                            filter: "low_stock" as const,
                        },
                    ].map((s, i) => (
                        <div key={i} className="card p-4 text-center">
                            <p className={clsx("text-2xl font-bold", s.color)}>
                                {s.value}
                            </p>
                            <p className="text-xs text-surface-500 mt-0.5">
                                {s.label}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {/* Filters */}
            <div className="flex flex-wrap gap-3">
                <div className="flex gap-1 bg-surface-100 rounded-xl p-1">
                    {(["all", "products", "materials"] as const).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTypeFilter(t)}
                            className={clsx(
                                "px-3 py-1.5 text-xs font-medium rounded-lg transition-colors capitalize",
                                typeFilter === t
                                    ? "bg-white text-surface-900 shadow-sm"
                                    : "text-surface-500 hover:text-surface-700",
                            )}
                        >
                            {t}
                        </button>
                    ))}
                </div>
                <div className="flex gap-1 bg-surface-100 rounded-xl p-1">
                    {(
                        [
                            ["", "All"],
                            ["out_of_stock", "Out of Stock"],
                            ["low_stock", "Low Stock"],
                        ] as const
                    ).map(([v, l]) => (
                        <button
                            key={v}
                            onClick={() => setSeverityFilter(v as any)}
                            className={clsx(
                                "px-3 py-1.5 text-xs font-medium rounded-lg transition-colors",
                                severityFilter === v
                                    ? "bg-white text-surface-900 shadow-sm"
                                    : "text-surface-500 hover:text-surface-700",
                            )}
                        >
                            {l}
                        </button>
                    ))}
                </div>
            </div>

            {/* Alerts list */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="text-center py-16">
                        {alerts.length === 0 ? (
                            <p className="text-success font-medium">
                                ✓ No stock alerts - all levels healthy
                            </p>
                        ) : (
                            <p className="text-surface-400 text-sm">
                                No alerts match current filters.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px]">
                        <thead>
                            <tr className="border-b border-surface-100 bg-surface-50/50">
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Item
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                    Type
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                    Location
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Stock
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                    Threshold
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Severity
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {filtered.map((alert, i) => (
                                <tr
                                    key={i}
                                    className={clsx(
                                        "hover:bg-surface-50/50 transition-colors",
                                        alert.severity === "out_of_stock" &&
                                            "bg-danger-light/20",
                                    )}
                                >
                                    {/* Item */}
                                    <td className="px-4 py-3">
                                        {alert.type === "product" ? (
                                            <div className="flex items-center gap-2.5">
                                                <div className="w-8 h-8 rounded-lg bg-surface-100 overflow-hidden shrink-0 border border-surface-200">
                                                    {alert.product
                                                        ?.image_url ? (
                                                        <img
                                                            src={
                                                                alert.product
                                                                    .image_url
                                                            }
                                                            className="w-full h-full object-cover"
                                                            alt=""
                                                        />
                                                    ) : (
                                                        <div className="w-full h-full flex items-center justify-center">
                                                            <svg
                                                                className="w-4 h-4 text-surface-300"
                                                                fill="none"
                                                                viewBox="0 0 24 24"
                                                                stroke="currentColor"
                                                                strokeWidth={
                                                                    1.5
                                                                }
                                                            >
                                                                <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                            </svg>
                                                        </div>
                                                    )}
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium text-surface-900">
                                                        {alert.product?.name}
                                                    </p>
                                                    <div className="flex gap-1.5 mt-0.5">
                                                        <span className="text-xs font-mono text-surface-400">
                                                            {alert.product?.sku}
                                                        </span>
                                                        {alert.variant && (
                                                            <span className="text-xs text-surface-500 bg-surface-100 px-1.5 rounded">
                                                                {
                                                                    alert
                                                                        .variant
                                                                        .variant_name
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ) : (
                                            <div>
                                                <p className="text-sm font-medium text-surface-900">
                                                    {alert.material?.name}
                                                </p>
                                                <p className="text-xs font-mono text-surface-400">
                                                    {alert.material?.code}
                                                </p>
                                            </div>
                                        )}
                                    </td>

                                    {/* Type */}
                                    <td className="px-4 py-3 hidden sm:table-cell">
                                        <span
                                            className={clsx(
                                                "text-xs font-medium px-2 py-0.5 rounded-full",
                                                alert.type === "product"
                                                    ? "bg-brand-50 text-brand-600"
                                                    : "bg-purple-50 text-purple-600",
                                            )}
                                        >
                                            {alert.type === "product"
                                                ? "Product"
                                                : "Material"}
                                        </span>
                                        {alert.type === "material" &&
                                            alert.material?.category && (
                                                <p className="text-2xs text-surface-400 mt-0.5">
                                                    {alert.material.category}
                                                </p>
                                            )}
                                    </td>

                                    {/* Location */}
                                    <td className="px-4 py-3 text-sm text-surface-600 hidden md:table-cell">
                                        {alert.type === "product"
                                            ? (alert.outlet?.name ?? "-")
                                            : alert.by_outlet
                                                  ?.map(
                                                      (o) =>
                                                          `${o.outlet_name}: ${o.quantity_on_hand}`,
                                                  )
                                                  .join(", ") || "No stock"}
                                    </td>

                                    {/* Stock */}
                                    <td className="px-4 py-3 text-right">
                                        <span
                                            className={clsx(
                                                "text-base font-bold tabular-nums",
                                                alert.severity ===
                                                    "out_of_stock"
                                                    ? "text-danger"
                                                    : "text-warning",
                                            )}
                                        >
                                            {alert.quantity} {alert.unit ?? ""}
                                        </span>
                                        {alert.type === "product" &&
                                            alert.quantity_available !==
                                                undefined &&
                                            alert.quantity_available !==
                                                alert.quantity && (
                                                <p className="text-xs text-surface-400">
                                                    {alert.quantity_available}{" "}
                                                    avail
                                                </p>
                                            )}
                                    </td>

                                    {/* Threshold */}
                                    <td className="px-4 py-3 text-right text-sm text-surface-500 tabular-nums hidden sm:table-cell">
                                        {alert.reorder_point > 0
                                            ? `≤ ${alert.reorder_point}`
                                            : "-"}
                                    </td>

                                    {/* Severity */}
                                    <td className="px-4 py-3 text-center">
                                        <span
                                            className={clsx(
                                                "text-xs font-semibold px-2.5 py-1 rounded-full",
                                                alert.severity ===
                                                    "out_of_stock"
                                                    ? "bg-danger-light text-danger"
                                                    : "bg-warning-light text-warning",
                                            )}
                                        >
                                            {alert.severity === "out_of_stock"
                                                ? <span className="inline-flex items-center gap-1"><svg className="w-2.5 h-2.5 fill-danger" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg> Out</span>
                                                : <span className="inline-flex items-center gap-1"><svg className="w-2.5 h-2.5 fill-warning" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg> Low</span>
                                            }
                                        </span>
                                    </td>

                                    {/* Action */}
                                    <td className="px-4 py-3 text-right hidden sm:table-cell">
                                        {alert.type === "product" && canAdjust && (
                                            <button
                                                onClick={() =>
                                                    setEditingAlert(alert)
                                                }
                                                className="btn-ghost btn-sm text-xs"
                                            >
                                                Set Threshold
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </div>
                )}
            </div>

            {editingAlert && (
                <ThresholdModal
                    alert={editingAlert}
                    onClose={() => setEditingAlert(null)}
                />
            )}
        </div>
    );
}