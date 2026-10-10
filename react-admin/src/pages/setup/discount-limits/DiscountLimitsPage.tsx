/**
 * DiscountLimitsPage — Setup › Discount limits (super admin only)
 *
 * Owner, 2026-10-10: "clerks at 10% and Admins up to 15%. Make it not
 * automatically but when needed a clerk can give up to. Create a place where
 * super admin can set this too."
 *
 * One row per role: the most a holder of that role may give as a discount at
 * the till, on an order or on a quotation. An empty box means the role uses
 * the default (the global maximum). The server enforces every limit
 * (App\Support\DiscountRule) and records each change on the audit trail;
 * saving asks the super admin to confirm it is them (step-up), which the API
 * client handles.
 *
 * Route: /settings/discount-limits
 */

import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { discountLimitsApi, type DiscountLimit } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

const QUERY_KEY = ["discount-limits"];

/** "" = use the default; otherwise the typed text, validated on save. */
type Drafts = Record<string, string>;

const toDraft = (l: DiscountLimit): string => (l.cap_percent === null ? "" : String(l.cap_percent));

function issueOf(text: string): string | null {
    const t = text.trim();
    if (t === "") return null;
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(t)) return "A number with at most two decimals.";
    const n = Number(t);
    if (n < 0 || n > 100) return "Between 0 and 100.";
    return null;
}

export default function DiscountLimitsPage() {
    const toast = useToastStore();
    const qc    = useQueryClient();

    const { data, isLoading, isError, error } = useQuery({
        queryKey: QUERY_KEY,
        queryFn:  () => discountLimitsApi.get(),
    });

    const [drafts, setDrafts] = useState<Drafts>({});
    useEffect(() => {
        if (data) setDrafts(Object.fromEntries(data.limits.map((l) => [l.role, toDraft(l)])));
    }, [data]);

    const limits = data?.limits ?? [];
    const changed = useMemo(
        () => limits.filter((l) => (drafts[l.role] ?? toDraft(l)).trim() !== toDraft(l)),
        [limits, drafts],
    );
    const issues = useMemo(
        () => Object.fromEntries(limits.map((l) => [l.role, issueOf(drafts[l.role] ?? "")])),
        [limits, drafts],
    );
    const hasIssue = Object.values(issues).some(Boolean);

    const save = useMutation({
        mutationFn: () =>
            discountLimitsApi.save(
                changed.map((l) => {
                    const t = (drafts[l.role] ?? "").trim();
                    return { role: l.role, cap_percent: t === "" ? null : Number(t) };
                }),
            ),
        onSuccess: (res) => {
            toast.success(res.message ?? "Discount limits saved.");
            qc.setQueryData(QUERY_KEY, { default_percent: res.default_percent, limits: res.limits });
        },
        onError: (e: ApiError) => {
            if (e.reason === "step_up_cancelled") return;
            toast.error(e.message);
        },
    });

    const fallback = data?.default_percent ?? 5;

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Discount limits</h1>
                    <p className="page-subtitle">
                        The most each role may give as a discount at the till, on an order or on a quotation.
                    </p>
                </div>
                <button
                    onClick={() => save.mutate()}
                    disabled={changed.length === 0 || hasIssue || save.isPending}
                    className="btn-primary gap-2 self-start sm:self-auto"
                >
                    {save.isPending && <Spinner size="sm" />}
                    Save{changed.length > 0 ? ` (${changed.length})` : ""}
                </button>
            </div>

            {/* How it works */}
            <div className="bg-brand-50 border border-brand-100 rounded-2xl px-5 py-4 space-y-1">
                <p className="text-sm font-semibold text-brand-800">How discount limits work</p>
                <p className="text-xs text-brand-600">
                    Staff can give a discount up to their role&apos;s limit when they choose to — nothing is applied
                    automatically. Super admin has no limit. Neema stays at the default.
                </p>
                <p className="text-xs text-brand-600">
                    The limit counts each line and the order as a whole, including a price typed below the catalogue.
                    Someone with several roles gets the highest of them. Leave a box empty to use the default
                    ({fallback}%). Promotions, coupons and sale prices stay at {fallback}% unless a super admin sets them.
                </p>
            </div>

            <div className="card overflow-hidden p-0">
                {isLoading ? (
                    <div className="flex items-center justify-center py-16">
                        <Spinner />
                    </div>
                ) : isError ? (
                    <p className="px-5 py-10 text-sm text-danger text-center">
                        {(error as ApiError | null)?.message ?? "The discount limits could not be loaded."}
                    </p>
                ) : limits.length === 0 ? (
                    <p className="px-5 py-10 text-sm text-surface-500 text-center">No staff roles to set a limit for.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <div className="grid grid-cols-[2fr_1fr_2fr] gap-4 px-5 py-2.5 bg-surface-50 border-b border-line min-w-[520px]">
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">Role</p>
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">Limit</p>
                            <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">Last changed</p>
                        </div>
                        <div className="divide-y divide-line min-w-[520px]">
                            {limits.map((l) => {
                                const value = drafts[l.role] ?? toDraft(l);
                                const issue = issues[l.role];
                                return (
                                    <div key={l.role} className="grid grid-cols-[2fr_1fr_2fr] gap-4 items-center px-5 py-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-surface-900 truncate">{l.display_name}</p>
                                            <p className="text-2xs text-surface-400 truncate">{l.role}</p>
                                        </div>
                                        <div>
                                            <div className="flex items-center gap-1.5">
                                                <input
                                                    type="text"
                                                    inputMode="decimal"
                                                    aria-label={`Discount limit for ${l.display_name}, percent`}
                                                    className="input w-24 text-right"
                                                    placeholder={`${fallback}`}
                                                    value={value}
                                                    onChange={(e) => setDrafts((d) => ({ ...d, [l.role]: e.target.value }))}
                                                />
                                                <span className="text-sm text-surface-500">%</span>
                                            </div>
                                            {issue && <p className="text-2xs text-danger mt-1">{issue}</p>}
                                        </div>
                                        <p className="text-xs text-surface-500">
                                            {l.cap_percent === null
                                                ? `Default (${fallback}%)`
                                                : [
                                                      l.updated_by?.name,
                                                      l.updated_at ? new Date(l.updated_at).toLocaleString() : null,
                                                  ].filter(Boolean).join(" · ") || "Set at install"}
                                        </p>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
