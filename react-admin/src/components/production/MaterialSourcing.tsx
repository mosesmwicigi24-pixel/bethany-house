// Where a production job's materials come from (owner, 2026-11-18/19).
//
// For each material on the product's bill of materials the person raising the
// job says FROM STORE (reserved for the job) or NEEDS BUYING (asked of
// procurement), or splits it. Nothing here names a supplier or a price: who we
// buy from is procurement's to see (owner, 2026-11-19).
//
//   MaterialSourcingFields   the choice rows, used inside "Send to production"
//   MaterialSourcingPanel    the same on a production job's Materials tab,
//                            with where each material stands

import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

export interface MaterialNeed {
    material_id: number;
    name: string;
    unit: string | null;
    /** What still has to be found for the job. */
    required: number;
    /** Free in the store right now (on hand less held for other jobs). */
    free: number;
}

export type SourceMode = "store" | "buy" | "split";
export interface SourceChoice { mode: SourceMode; store: string }
export type SourceChoices = Record<number, SourceChoice>;

const n2 = (v: number) => Math.round(v * 100) / 100;
/** 5, 2.5, 0.25 — no trailing zeros. */
export const qty = (v: number) => n2(v).toLocaleString("en-KE", { maximumFractionDigits: 2 });

/** The sensible start: the store when it has enough, otherwise buy. */
export function defaultChoices(needs: MaterialNeed[]): SourceChoices {
    return Object.fromEntries(needs.map((m) => [m.material_id, { mode: m.free >= m.required ? "store" : "buy", store: "" } as SourceChoice]));
}

/** from store / to buy for one material under a choice. */
export function split(need: MaterialNeed, choice: SourceChoice | undefined): { store: number; buy: number } {
    const mode = choice?.mode ?? "buy";
    if (mode === "store") return { store: need.required, buy: 0 };
    if (mode === "buy") return { store: 0, buy: need.required };
    const s = Math.max(0, Math.min(need.required, n2(Number(choice?.store) || 0)));
    return { store: s, buy: n2(need.required - s) };
}

/** Why a choice cannot be saved, or null. */
export function problem(need: MaterialNeed, choice: SourceChoice | undefined): string | null {
    const { store } = split(need, choice);
    const unit = need.unit ?? "";
    if (store > need.free + 0.001) return `Only ${qty(need.free)} ${unit} free in the store.`;
    if (choice?.mode === "split") {
        const typed = Number(choice.store);
        if (!(typed > 0)) return "Enter how much comes from the store.";
        if (typed >= need.required) return `Less than ${qty(need.required)} ${unit}, or choose From store.`;
    }
    return null;
}

export function toPayload(needs: MaterialNeed[], choices: SourceChoices) {
    return needs.map((m) => {
        const s = split(m, choices[m.material_id]);
        return { material_id: m.material_id, from_store: s.store, to_buy: s.buy };
    });
}

export const hasProblem = (needs: MaterialNeed[], choices: SourceChoices) => needs.some((m) => problem(m, choices[m.material_id]) !== null);

const MODES: { key: SourceMode; label: string }[] = [
    { key: "store", label: "From store" },
    { key: "buy", label: "To buy" },
    { key: "split", label: "Split" },
];

export function MaterialSourcingFields({ needs, choices, onChange }: {
    needs: MaterialNeed[]; choices: SourceChoices; onChange: (next: SourceChoices) => void;
}) {
    const set = (id: number, patch: Partial<SourceChoice>) =>
        onChange({ ...choices, [id]: { ...(choices[id] ?? { mode: "buy", store: "" }), ...patch } });

    return (
        <div className="space-y-2.5">
            {needs.map((m) => {
                const choice = choices[m.material_id];
                const mode = choice?.mode ?? "buy";
                const s = split(m, choice);
                const err = problem(m, choice);
                const unit = m.unit ?? "";
                const enough = m.free >= m.required;
                return (
                    <div key={m.material_id} className="rounded-xl border border-line p-3">
                        <div className="flex items-baseline justify-between gap-3">
                            <p className="min-w-0 truncate text-sm font-semibold text-surface-900">{m.name}</p>
                            <p className="shrink-0 text-sm font-semibold tabular-nums text-surface-900">{qty(m.required)} {unit}</p>
                        </div>
                        <p className={clsx("mt-0.5 text-xs", enough ? "text-success-700" : "text-amber-700")}>
                            {m.free > 0 ? `${qty(m.free)} ${unit} free in store` : "None free in store"}{!enough && m.free > 0 ? " · not enough" : ""}
                        </p>

                        <div role="radiogroup" aria-label={`Where ${m.name} comes from`} className="mt-2 grid grid-cols-3 gap-1 rounded-lg bg-surface-100 p-1">
                            {MODES.map((o) => {
                                const off = o.key === "split" && !(m.free > 0);
                                return (
                                    <button key={o.key} type="button" role="radio" aria-checked={mode === o.key} disabled={off}
                                        onClick={() => set(m.material_id, { mode: o.key, store: o.key === "split" && !choice?.store ? String(Math.min(m.free, n2(m.required - 0.01)) || "") : choice?.store ?? "" })}
                                        className={clsx("rounded-md px-1.5 py-1.5 text-xs font-medium transition-colors",
                                            mode === o.key ? "bg-white font-semibold text-brand-700 shadow-sm" : "text-surface-600 hover:text-surface-900",
                                            off && "cursor-not-allowed opacity-40")}>
                                        {o.label}
                                    </button>
                                );
                            })}
                        </div>

                        {mode === "split" && (
                            <div className="mt-2 grid grid-cols-2 gap-2">
                                <label className="block">
                                    <span className="text-2xs font-semibold text-surface-500">From store ({unit})</span>
                                    <input type="number" inputMode="decimal" min={0} max={m.required} step="0.01" value={choice?.store ?? ""}
                                        onChange={(e) => set(m.material_id, { store: e.target.value })}
                                        className="input mt-0.5 w-full text-base sm:text-sm tabular-nums" />
                                </label>
                                <div>
                                    <span className="text-2xs font-semibold text-surface-500">To buy ({unit})</span>
                                    <p className="mt-0.5 flex h-[42px] items-center rounded-xl bg-surface-50 px-3 text-base sm:text-sm font-semibold tabular-nums text-surface-800">{qty(s.buy)}</p>
                                </div>
                            </div>
                        )}

                        {err ? (
                            <p role="alert" className="mt-2 text-xs font-medium text-danger">{err}</p>
                        ) : (
                            <p className="mt-2 text-xs text-surface-600">
                                {s.store > 0 && <span className="text-success-700">{qty(s.store)} {unit} will be reserved for this job.</span>}
                                {s.store > 0 && s.buy > 0 && " "}
                                {s.buy > 0 && <span>{qty(s.buy)} {unit} will be requested from procurement.</span>}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

// ── On the production job ─────────────────────────────────────────────────────

interface JobMaterial extends MaterialNeed {
    allocation_id: number;
    issued: number;
    reserved: number;
    to_buy: number;
    unsourced: number;
}
interface JobSourcing { status: "none" | "unsourced" | "waiting" | "ready"; materials: JobMaterial[] }

const STATUS: Record<JobSourcing["status"], { label: string; cls: string }> = {
    none:      { label: "No materials listed", cls: "bg-surface-100 text-surface-600" },
    unsourced: { label: "Materials not yet sourced", cls: "bg-danger-light text-danger" },
    waiting:   { label: "Waiting for materials", cls: "bg-amber-50 text-amber-700" },
    ready:     { label: "Materials ready", cls: "bg-success-50 text-success-700" },
};

function Chip({ tone, children }: { tone: "ok" | "wait" | "open" | "done"; children: React.ReactNode }) {
    const cls = { ok: "bg-success-50 text-success-700", wait: "bg-amber-50 text-amber-700", open: "bg-danger-light text-danger", done: "bg-surface-100 text-surface-600" }[tone];
    return <span className={clsx("inline-block rounded-full px-2 py-0.5 text-2xs font-semibold whitespace-nowrap", cls)}>{children}</span>;
}

export function MaterialSourcingPanel({ orderId, closed, canEdit }: { orderId: number; closed: boolean; canEdit: boolean }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const key = ["production-material-sourcing", orderId];
    const { data, isLoading } = useQuery({ queryKey: key, queryFn: () => get<JobSourcing>(`/v1/admin/production-orders/${orderId}/material-sourcing`) });
    const [editing, setEditing] = useState(false);
    const [choices, setChoices] = useState<SourceChoices>({});

    // What is still to be found per material, and the store stock this job
    // may draw on: what is free plus what it already holds.
    const needs: MaterialNeed[] = (data?.materials ?? [])
        .map((m) => ({ material_id: m.material_id, name: m.name, unit: m.unit, required: n2(m.required - m.issued), free: n2(m.free + m.reserved) }))
        .filter((m) => m.required > 0);

    useEffect(() => {
        if (!editing || !data) return;
        setChoices(Object.fromEntries(data.materials.filter((m) => m.required - m.issued > 0).map((m) => {
            const outstanding = n2(m.required - m.issued);
            const decided = m.reserved + m.to_buy > 0;
            const mode: SourceMode = !decided ? (m.free >= outstanding ? "store" : "buy")
                : m.to_buy <= 0 ? "store" : m.reserved <= 0 ? "buy" : "split";
            return [m.material_id, { mode, store: mode === "split" ? String(m.reserved) : "" }];
        })));
    }, [editing, data]);

    const save = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${orderId}/material-sourcing`, { materials: toPayload(needs, choices) }),
        onSuccess: () => { toast.success("Materials updated"); setEditing(false); qc.invalidateQueries({ queryKey: key }); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading || !data || data.status === "none") return null;
    const st = STATUS[data.status];

    return (
        <div className="rounded-xl border border-line p-3.5">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-sm font-semibold text-surface-900">Where the materials come from</h3>
                <span className={clsx("rounded-full px-2.5 py-0.5 text-2xs font-bold", st.cls)}>{st.label}</span>
                <span className="flex-1" />
                {canEdit && !closed && !editing && needs.length > 0 && (
                    <button type="button" onClick={() => setEditing(true)} className="btn-secondary btn-sm text-xs">
                        {data.status === "unsourced" ? "Choose sources" : "Change"}
                    </button>
                )}
            </div>

            {!editing ? (
                <ul className="mt-2.5 divide-y divide-line">
                    {data.materials.map((m) => (
                        <li key={m.allocation_id} className="flex items-start justify-between gap-3 py-2">
                            <div className="min-w-0">
                                <p className="truncate text-sm text-surface-900">{m.name}</p>
                                <p className="text-xs text-surface-500 tabular-nums">{qty(m.required)} {m.unit} needed</p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-1">
                                {m.issued > 0 && <Chip tone="done">{qty(m.issued)} {m.unit} issued</Chip>}
                                {m.reserved > 0 && <Chip tone="ok">{qty(m.reserved)} {m.unit} reserved in store</Chip>}
                                {m.to_buy > 0 && <Chip tone="wait">{qty(m.to_buy)} {m.unit} being bought</Chip>}
                                {m.unsourced > 0 && <Chip tone="open">{qty(m.unsourced)} {m.unit} not yet sourced</Chip>}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <div className="mt-3">
                    <MaterialSourcingFields needs={needs} choices={choices} onChange={setChoices} />
                    <div className="mt-3 flex justify-end gap-2">
                        <button type="button" onClick={() => setEditing(false)} className="btn-secondary btn-sm text-xs">Cancel</button>
                        <button type="button" onClick={() => save.mutate()} disabled={save.isPending || hasProblem(needs, choices)}
                            className="btn-primary btn-sm text-xs disabled:opacity-50">{save.isPending ? "Saving…" : "Save"}</button>
                    </div>
                </div>
            )}
            {data.status === "waiting" && !editing && (
                <p className="mt-1 text-xs text-surface-500">When procurement receives a delivery, the material is held for this job automatically.</p>
            )}
        </div>
    );
}
