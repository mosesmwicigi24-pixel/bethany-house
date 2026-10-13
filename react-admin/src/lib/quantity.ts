/**
 * Procurement quantities: materials are measured, products are counted.
 *
 * Fabric, tape and trims are bought by the metre — 0.25 m, 0.5 m, 0.75 m —
 * so a material line carries up to two decimal places (the columns are
 * decimal(12,2) end to end: PO items, GRN items, material_inventory,
 * material_transactions). Products are pieces and stay whole; their stock
 * lives in an integer column.
 *
 * Every procurement screen parses and shows quantities through these three
 * helpers, so the figure a line total is previewed on is the figure that is
 * sent. Before this, the PO form previewed parseFloat(qty) × price but
 * submitted parseInt(qty) — a 0.25 m line priced as a quarter metre went to
 * the server as 0.
 */

export type QtyKind = "material" | "product" | string | null | undefined;

const isMaterial = (kind: QtyKind) => kind === "material";

/** The <input step> for a line: 0.01 for materials, 1 for products. */
export function qtyStep(kind: QtyKind): string {
    return isMaterial(kind) ? "0.01" : "1";
}

/** The <input min> for a line — the smallest quantity worth a line. */
export function qtyMin(kind: QtyKind): string {
    return isMaterial(kind) ? "0.01" : "1";
}

/**
 * Raw input → the number to send. Materials round to 2 places (so float
 * noise like 0.30000000000000004 never reaches the server); products floor
 * to whole pieces. Blank or invalid → 0.
 */
export function parseQty(raw: unknown, kind: QtyKind): number {
    const n = typeof raw === "number" ? raw : parseFloat(String(raw ?? ""));
    if (!Number.isFinite(n) || n <= 0) return 0;
    return isMaterial(kind) ? Math.round(n * 100) / 100 : Math.floor(n);
}

/**
 * A stored quantity (the API sends decimals as strings, e.g. "5.00") → a
 * number. Always Number(), never `+` on the raw value: `0 + "5.00"` is the
 * string "05.00", which is how "Total Ordered" used to render as garbage.
 */
export function qtyNum(raw: unknown): number {
    const n = Number(raw ?? 0);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
}

/** Display: materials always show 2 places (0.25, 5.00); products whole. */
export function fmtQty(raw: unknown, kind: QtyKind): string {
    const n = qtyNum(raw);
    return isMaterial(kind) ? n.toFixed(2) : String(Math.round(n));
}
