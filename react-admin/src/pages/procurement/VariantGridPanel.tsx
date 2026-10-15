import { useEffect, useMemo, useRef, useState } from "react";
import { clsx } from "clsx";
import type { POItemCosts } from "@/api/procurement";

/**
 * "Add all variants" — order a variable product by variant in one go.
 *
 * One quantity box per variant (blank = not ordered). When the variants differ
 * on exactly two attributes and every pair is one variant (Colour × Size), the
 * boxes sit in a grid: colours down, sizes across. Anything else — one
 * attribute, three, or irregular combinations — is a plain list. Tab moves
 * across the boxes in reading order.
 *
 * Each variant's unit price starts from its KES book cost in the PO's currency
 * (the server's figure: PurchaseOrderController::itemCosts) and stays
 * editable. A variant with no cost has no price — it is never silently 0; the
 * buyer must type one before that variant can be added.
 */

export interface VariantPick {
    variantId: number;
    label: string;
    quantity: number;
    unitPrice: number;
    kesCost: number | null;
    priceAuto: boolean;
}

type Variant = POItemCosts["variants"][number];

const money = (v: number) => v.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const priceText = (v: number | null) => (v === null ? "" : v.toFixed(2));
const COLOUR = /colou?r/i;

/** The variant's distinguishing attribute values, e.g. "Navy · L". */
function labelOf(v: Variant, keys: string[]): string {
    const parts = keys.map((k) => v.attributes?.[k]).filter((x): x is string => !!x && String(x).trim() !== "");
    return parts.length ? parts.join(" · ") : (v.variant_name || v.sku);
}

/** Rows × columns when the variants form a clean two-attribute grid; else null. */
function gridAxes(variants: Variant[], keys: string[]) {
    if (keys.length !== 2) return null;
    const distinct = (k: string) => Array.from(new Set(variants.map((v) => String(v.attributes?.[k] ?? ""))));
    let [rowKey, colKey] = keys;
    // Colour reads down the side; otherwise the longer list does.
    if (COLOUR.test(colKey) || (!COLOUR.test(rowKey) && distinct(colKey).length > distinct(rowKey).length)) {
        [rowKey, colKey] = [colKey, rowKey];
    }
    const cell = new Map<string, Variant>();
    for (const v of variants) {
        const r = String(v.attributes?.[rowKey] ?? "");
        const c = String(v.attributes?.[colKey] ?? "");
        const key = `${r}\u0000${c}`;
        if (r === "" || c === "" || cell.has(key)) return null;   // irregular: list instead
        cell.set(key, v);
    }

    return { rowKey, colKey, rows: distinct(rowKey), cols: distinct(colKey), cell };
}

export function VariantGridPanel({
    data,
    currency,
    onAdd,
    onCancel,
}: {
    data: POItemCosts;
    currency: string;
    onAdd: (picks: VariantPick[]) => void;
    onCancel: () => void;
}) {
    const variants = data.variants;

    // Attributes that tell the variants apart (a value shared by all is noise).
    const keys = useMemo(() => {
        const all = Array.from(new Set(variants.flatMap((v) => Object.keys(v.attributes ?? {}))));
        return all.filter((k) => new Set(variants.map((v) => String(v.attributes?.[k] ?? ""))).size > 1);
    }, [variants]);
    const axes = useMemo(() => gridAxes(variants, keys), [variants, keys]);

    const [qty, setQty] = useState<Record<number, string>>({});
    const [price, setPrice] = useState<Record<number, string>>(
        () => Object.fromEntries(variants.map((v) => [v.id, priceText(v.unit_price)])),
    );
    const [touched, setTouched] = useState<Record<number, boolean>>({});
    const [allPrice, setAllPrice] = useState("");
    // The grid shows quantities; per-variant prices are edited in the list.
    const [listView, setListView] = useState(axes === null);

    const firstBox = useRef<HTMLInputElement | null>(null);
    useEffect(() => { firstBox.current?.focus(); }, [listView]);

    const qtyOf   = (id: number) => { const n = Math.floor(Number(qty[id])); return Number.isFinite(n) && n > 0 ? n : 0; };
    const priceOf = (id: number) => (price[id] ?? "").trim() === "" ? null : Number(price[id]);

    const chosen   = variants.filter((v) => qtyOf(v.id) > 0);
    const unpriced = chosen.filter((v) => { const p = priceOf(v.id); return p === null || !Number.isFinite(p) || p < 0; });
    const pieces   = chosen.reduce((s, v) => s + qtyOf(v.id), 0);
    const total    = chosen.reduce((s, v) => s + qtyOf(v.id) * (priceOf(v.id) ?? 0), 0);
    const anyMissingCost = variants.some((v) => v.unit_price === null);

    const setAll = (raw: string) => {
        setAllPrice(raw);
        if (raw.trim() === "" || !Number.isFinite(Number(raw))) return;
        setPrice(Object.fromEntries(variants.map((v) => [v.id, raw])));
        setTouched(Object.fromEntries(variants.map((v) => [v.id, true])));
    };

    const qtyBox = (v: Variant, idx: number, compact: boolean) => (
        <input
            ref={idx === 0 ? firstBox : undefined}
            type="number"
            inputMode="numeric"
            min="0"
            step="1"
            aria-label={`Quantity — ${labelOf(v, keys)}`}
            placeholder="0"
            className={clsx("input text-center text-sm py-1", compact ? "w-16" : "w-20")}
            value={qty[v.id] ?? ""}
            onChange={(e) => setQty((q) => ({ ...q, [v.id]: e.target.value }))}
        />
    );

    const priceBox = (v: Variant) => {
        const missing = qtyOf(v.id) > 0 && priceOf(v.id) === null;
        return (
            <input
                type="number"
                inputMode="decimal"
                min="0"
                step="0.01"
                aria-label={`Unit price (${currency}) — ${labelOf(v, keys)}`}
                placeholder={v.unit_price === null ? "No cost — enter" : ""}
                className={clsx("input w-28 text-right text-sm py-1", (missing || (v.unit_price === null && !price[v.id])) && "input-error")}
                value={price[v.id] ?? ""}
                onChange={(e) => {
                    setPrice((p) => ({ ...p, [v.id]: e.target.value }));
                    setTouched((t) => ({ ...t, [v.id]: true }));
                }}
            />
        );
    };

    let boxIndex = 0;

    return (
        <div className="rounded-xl border border-brand-200 bg-brand-50/40 p-3 space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-sm font-semibold text-surface-900">
                        Add all variants — {data.product.name}
                    </p>
                    <p className="text-xs text-surface-500">
                        {variants.length} variants · enter a quantity for each one you are ordering; blank = not ordered.
                    </p>
                </div>
                {axes !== null && (
                    <button type="button" className="text-xs font-semibold text-brand-600 hover:underline"
                        onClick={() => setListView((v) => !v)}>
                        {listView ? "Show as grid" : "Set prices per variant"}
                    </button>
                )}
            </div>

            {(!data.cost_visible || data.rate_missing || anyMissingCost) && (
                <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    {!data.cost_visible
                        ? "Cost prices are not visible to you — enter the unit prices."
                        : data.rate_missing
                          ? `There is no ${currency} reporting rate, so costs cannot be converted — enter the unit prices.`
                          : "Some variants have no cost on file — enter their unit price before adding them."}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2 text-xs">
                <label className="text-surface-600" htmlFor="po-variant-all-price">Unit price ({currency}) for all:</label>
                <input
                    id="po-variant-all-price"
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="0.01"
                    className="input w-28 text-right text-sm py-1"
                    placeholder="optional"
                    value={allPrice}
                    onChange={(e) => setAll(e.target.value)}
                />
            </div>

            <div className="overflow-x-auto -mx-1 px-1">
                {!listView && axes ? (
                    <table className="text-sm border-separate border-spacing-1">
                        <thead>
                            <tr>
                                <th className="text-left text-2xs font-semibold text-surface-500 uppercase pr-2">
                                    {axes.rowKey} \ {axes.colKey}
                                </th>
                                {axes.cols.map((c) => (
                                    <th key={c} className="text-center text-xs font-semibold text-surface-700 px-1">{c}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {axes.rows.map((r) => (
                                <tr key={r}>
                                    <th scope="row" className="text-left text-xs font-semibold text-surface-700 pr-2 whitespace-nowrap">{r}</th>
                                    {axes.cols.map((c) => {
                                        const v = axes.cell.get(`${r}\u0000${c}`);
                                        if (!v) return <td key={c} className="text-center text-surface-300">—</td>;
                                        const p = priceOf(v.id);
                                        return (
                                            <td key={c} className="text-center align-top">
                                                {qtyBox(v, boxIndex++, true)}
                                                <div className={clsx("text-2xs mt-0.5 tabular-nums", p === null ? "text-amber-700" : "text-surface-400")}>
                                                    {p === null ? "no price" : `@ ${money(p)}`}
                                                </div>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-2xs uppercase text-surface-500">
                                <th className="text-left font-semibold py-1">Variant</th>
                                <th className="text-center font-semibold py-1">Qty</th>
                                <th className="text-right font-semibold py-1">Unit price ({currency})</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {variants.map((v) => (
                                <tr key={v.id}>
                                    <td className="py-1.5 pr-2">
                                        <p className="font-medium text-surface-800 leading-tight">{labelOf(v, keys)}</p>
                                        <p className="text-2xs font-mono text-surface-400">{v.sku}</p>
                                    </td>
                                    <td className="py-1.5 text-center">{qtyBox(v, boxIndex++, false)}</td>
                                    <td className="py-1.5 text-right">{priceBox(v)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {unpriced.length > 0 && (
                <p className="text-xs text-danger">
                    Enter a unit price for: {unpriced.map((v) => labelOf(v, keys)).join(", ")}
                    {!listView && " (Set prices per variant)"}
                </p>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 pt-1 border-t border-brand-100">
                <p className="text-xs text-surface-600 tabular-nums">
                    {chosen.length} variant{chosen.length === 1 ? "" : "s"} · {pieces} piece{pieces === 1 ? "" : "s"}
                    {chosen.length > 0 && unpriced.length === 0 && <> · {currency} {money(total)}</>}
                </p>
                <div className="flex gap-2">
                    <button type="button" className="btn-secondary btn-sm" onClick={onCancel}>Cancel</button>
                    <button
                        type="button"
                        className="btn-primary btn-sm"
                        disabled={chosen.length === 0 || unpriced.length > 0}
                        onClick={() => onAdd(chosen.map((v) => ({
                            variantId: v.id,
                            label: `${data.product.name} — ${labelOf(v, keys)}`,
                            quantity: qtyOf(v.id),
                            unitPrice: priceOf(v.id) as number,
                            kesCost: v.kes_cost,
                            priceAuto: !touched[v.id] && v.unit_price !== null,
                        })))}
                    >
                        Add to order
                    </button>
                </div>
            </div>
        </div>
    );
}
