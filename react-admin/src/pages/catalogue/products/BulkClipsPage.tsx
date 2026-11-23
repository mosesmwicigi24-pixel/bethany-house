import { useCallback, useMemo, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { productsApi } from "@/api/products";
import type { ProductListItem } from "@/api/products";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";

/**
 * Bulk product clips.
 *
 * Drop a folder's worth of short clips; each file is matched to a product by
 * its NAME — `BH-CH-001.mp4` (SKU) or `chalice-royale.mov` (slug) — then
 * every matched file goes up through the same per-product endpoint the
 * product form uses (POST /admin/products/{id}/video), two at a time. The
 * Hub converts each on its queue; this page polls until every clip has
 * settled and says, per file, what happened.
 *
 * Nothing new on the server: same endpoint, same permission (products.edit),
 * same 100 MB ceiling and queue conversion. An unmatched file is never
 * guessed — the owner picks its product from a list, or it is skipped.
 */

const ALLOWED_EXT = ["mp4", "mov", "m4v", "webm"];
const MAX_BYTES = 100 * 1024 * 1024;
const CONCURRENCY = 2;
const POLL_MS = 3000;
const POLL_TRIES = 60; // ~3 minutes per clip, as on the product form

type RowStatus =
    | "match"       // matched, waiting to be uploaded
    | "replace"     // matched, product already has a clip → this replaces it
    | "nomatch"     // no product found by filename; owner must pick one
    | "uploading"
    | "converting"
    | "done"
    | "failed"
    | "skipped";

interface Row {
    id: string;
    file: File;
    key: string;             // normalised filename stem used for matching
    productId: number | null;
    status: RowStatus;
    pct: number;
    note?: string;
}

/** `BH-CH-001 (1).MOV` → `bh-ch-001`; `Chalice Royale.mp4` → `chalice-royale`. */
function stem(name: string): string {
    return name
        .toLowerCase()
        .replace(/\.[a-z0-9]+$/, "")
        .replace(/\s*\(\d+\)\s*$/, "")   // "name (1)" copy counters
        .replace(/\s*copy\s*$/, "")
        .trim()
        .replace(/[\s_]+/g, "-")
        .replace(/-+/g, "-")
        .replace(/^-|-$/g, "");
}

const norm = (s: string | null | undefined) => (s ? stem(s) : "");

function matchProduct(key: string, products: ProductListItem[]): ProductListItem | null {
    if (!key) return null;
    const bySku = new Map(products.map((p) => [norm(p.sku), p]));
    const bySlug = new Map(products.map((p) => [norm(p.slug), p]));
    // Exact SKU, then exact slug.
    const exact = bySku.get(key) ?? bySlug.get(key);
    if (exact) return exact;
    // "chalice-royale-2" / "chalice-royale-front" → chalice-royale, when that
    // is the ONLY slug the name extends. Never pick between several.
    const prefixed = products.filter((p) => {
        const s = norm(p.slug);
        return s && key.startsWith(s + "-");
    });
    if (prefixed.length === 1) return prefixed[0];
    return null;
}

const fmtSize = (b: number) => (b >= 1024 * 1024 ? `${(b / 1024 / 1024).toFixed(1)} MB` : `${Math.round(b / 1024)} KB`);

const STATUS: Record<RowStatus, { label: string; cls: string }> = {
    match:      { label: "Ready",             cls: "bg-success-light text-success" },
    replace:    { label: "Replaces clip",     cls: "bg-warning-light text-warning" },
    nomatch:    { label: "Choose a product",  cls: "bg-surface-100 text-surface-600" },
    uploading:  { label: "Uploading",         cls: "bg-surface-100 text-surface-700" },
    converting: { label: "Converting",        cls: "bg-surface-100 text-surface-700" },
    done:       { label: "Done",              cls: "bg-success-light text-success" },
    failed:     { label: "Failed",            cls: "bg-danger-light text-danger" },
    skipped:    { label: "Skipped",           cls: "bg-surface-100 text-surface-400" },
};

/** Every product the admin may attach a clip to, across all list pages. */
async function loadAllProducts(): Promise<ProductListItem[]> {
    const out: ProductListItem[] = [];
    const perPage = 500;
    for (let page = 1; page <= 20; page++) {
        const res = await productsApi.list({ per_page: String(perPage), page: String(page) });
        out.push(...res.data);
        const last = Number(res.meta?.last_page ?? 1);
        if (page >= last || res.data.length < perPage) break;
    }
    return out.filter((p) => p.status !== "archived");
}

export default function BulkClipsPage() {
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const inputRef = useRef<HTMLInputElement>(null);
    const [rows, setRows] = useState<Row[]>([]);
    const [running, setRunning] = useState(false);
    const [dragOver, setDragOver] = useState(false);

    const { data: products, isLoading, error } = useQuery({
        queryKey: ["products", "all-for-clips"],
        queryFn: loadAllProducts,
        staleTime: 60_000,
    });
    const byId = useMemo(() => new Map((products ?? []).map((p) => [p.id, p])), [products]);
    const sorted = useMemo(
        () => [...(products ?? [])].sort((a, b) =>
            (a.en_translation?.name ?? a.slug).localeCompare(b.en_translation?.name ?? b.slug)),
        [products],
    );

    const patch = useCallback((id: string, p: Partial<Row>) => {
        setRows((rs) => rs.map((r) => (r.id === id ? { ...r, ...p } : r)));
    }, []);

    const initialStatus = (product: ProductListItem | null, file: File): { status: RowStatus; note?: string } => {
        const ext = file.name.split(".").pop()?.toLowerCase() ?? "";
        if (!ALLOWED_EXT.includes(ext)) return { status: "failed", note: "Not a video we take: use MP4, MOV, M4V or WebM." };
        if (file.size > MAX_BYTES) return { status: "failed", note: "Over 100 MB. Trim it, or export at 1080p instead of 4K." };
        if (!product) return { status: "nomatch" };
        return { status: product.video_url ? "replace" : "match" };
    };

    const addFiles = useCallback((list: FileList | File[]) => {
        if (!products) return;
        const files = Array.from(list);
        setRows((prev) => {
            const have = new Set(prev.map((r) => `${r.file.name}:${r.file.size}`));
            const next = [...prev];
            for (const file of files) {
                if (have.has(`${file.name}:${file.size}`)) continue;
                const key = stem(file.name);
                const product = matchProduct(key, products);
                next.push({
                    id: `${file.name}:${file.size}:${file.lastModified}`,
                    file, key,
                    productId: product?.id ?? null,
                    pct: 0,
                    ...initialStatus(product, file),
                });
            }
            return next;
        });
    }, [products]); // eslint-disable-line react-hooks/exhaustive-deps

    const assign = (row: Row, productId: number | null) => {
        const product = productId ? byId.get(productId) ?? null : null;
        patch(row.id, { productId, pct: 0, ...initialStatus(product, row.file) });
    };

    const remove = (id: string) => setRows((rs) => rs.filter((r) => r.id !== id));
    const clearDone = () => setRows((rs) => rs.filter((r) => r.status !== "done" && r.status !== "skipped"));

    /** Rows that will go up when the owner presses Upload. */
    const queue = rows.filter((r) => r.productId && (r.status === "match" || r.status === "replace" || r.status === "failed"));
    const unmatched = rows.filter((r) => r.status === "nomatch").length;

    // Two files aimed at one product: the second would silently overwrite the
    // first on the server. Say so, and hold both back until one is changed.
    const dupes = useMemo(() => {
        const seen = new Map<number, number>();
        for (const r of queue) seen.set(r.productId!, (seen.get(r.productId!) ?? 0) + 1);
        return new Set([...seen.entries()].filter(([, n]) => n > 1).map(([id]) => id));
    }, [queue]);

    const uploadOne = async (row: Row) => {
        const id = row.productId!;
        patch(row.id, { status: "uploading", pct: 0, note: undefined });
        try {
            const res = await productsApi.uploadVideo(id, row.file, (pct) => patch(row.id, { pct }));
            if (res.video_status !== "processing") {
                patch(row.id, { status: "done", pct: 100 });
                return;
            }
            patch(row.id, { status: "converting", pct: 100 });
            for (let i = 0; i < POLL_TRIES; i++) {
                await new Promise((r) => setTimeout(r, POLL_MS));
                const s = await productsApi.videoStatus(id);
                if (s.video_status === "processing") continue;
                if (s.video_status === "failed") {
                    patch(row.id, { status: "failed", note: "Could not be converted. Export as MP4 (H.264) and try again." });
                } else {
                    patch(row.id, { status: "done" });
                }
                return;
            }
            patch(row.id, { status: "failed", note: "Still converting after 3 minutes — check the product shortly." });
        } catch (e: any) {
            patch(row.id, { status: "failed", note: e?.errors?.video?.[0] ?? e?.message ?? "Upload failed." });
        }
    };

    const runAll = async () => {
        const todo = queue.filter((r) => !dupes.has(r.productId!));
        if (!todo.length) return;
        setRunning(true);
        // A small worker pool: two uploads in flight keeps a slow connection
        // busy without starving the Hub's upload workers.
        let next = 0;
        const worker = async () => {
            while (next < todo.length) {
                const row = todo[next++];
                await uploadOne(row);
            }
        };
        await Promise.all(Array.from({ length: Math.min(CONCURRENCY, todo.length) }, worker));
        setRunning(false);
        qc.invalidateQueries({ queryKey: ["products"] });
        toast.success(`Finished: ${todo.length} clip${todo.length === 1 ? "" : "s"} processed. See each row for its result.`);
    };

    const onDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setDragOver(false);
        if (e.dataTransfer.files?.length) addFiles(e.dataTransfer.files);
    };

    const done = rows.filter((r) => r.status === "done").length;
    const failed = rows.filter((r) => r.status === "failed").length;

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Product clips</h1>
                    <p className="page-subtitle">
                        Upload many clips at once. Name each file after the product&apos;s SKU or slug —
                        <code className="mx-1 rounded bg-surface-100 px-1.5 py-0.5 text-xs">BH-CH-001.mp4</code>
                        or
                        <code className="mx-1 rounded bg-surface-100 px-1.5 py-0.5 text-xs">chalice-royale.mov</code>
                        — and it finds its product. Each clip is converted for the web (720p, first 6 seconds, silent).
                    </p>
                </div>
                <div className="flex gap-2 shrink-0">
                    <button onClick={() => navigate("/catalogue/products")} className="btn-secondary btn-sm">← Products</button>
                </div>
            </div>

            {isLoading && (
                <div className="flex items-center gap-3 text-sm text-surface-600"><Spinner size="sm" /> Loading the catalogue…</div>
            )}
            {error && (
                <div className="rounded-xl border border-danger/30 bg-danger-light px-4 py-3 text-sm text-danger">
                    Could not load the product list. Reload the page to try again.
                </div>
            )}

            {products && (
                <label
                    className={clsx(
                        "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition",
                        dragOver ? "border-primary bg-primary/5" : "border-line bg-white hover:border-surface-400",
                        running && "pointer-events-none opacity-60",
                    )}
                    onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={onDrop}
                >
                    <span className="text-3xl" aria-hidden="true">▶</span>
                    <span className="text-sm font-semibold text-surface-900">Drop clips here, or click to choose</span>
                    <span className="text-xs text-surface-500">
                        MP4, MOV, M4V or WebM · up to 100 MB each · {products.length} products to match against
                    </span>
                    <input
                        ref={inputRef}
                        type="file"
                        multiple
                        accept="video/mp4,video/quicktime,video/x-m4v,video/webm,.mp4,.mov,.m4v,.webm"
                        className="sr-only"
                        disabled={running}
                        onChange={(e) => { if (e.target.files?.length) addFiles(e.target.files); e.target.value = ""; }}
                    />
                </label>
            )}

            {rows.length > 0 && (
                <div className="overflow-hidden rounded-2xl border border-line bg-white">
                    <div className="flex flex-col gap-2 border-b border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-surface-700">
                            <b>{rows.length}</b> file{rows.length === 1 ? "" : "s"}
                            {" · "}<b>{queue.filter((r) => !dupes.has(r.productId!)).length}</b> ready
                            {unmatched > 0 && <> · <b className="text-warning">{unmatched}</b> need a product</>}
                            {done > 0 && <> · <b className="text-success">{done}</b> done</>}
                            {failed > 0 && <> · <b className="text-danger">{failed}</b> failed</>}
                        </p>
                        <div className="flex gap-2">
                            {(done > 0 || rows.some((r) => r.status === "skipped")) && !running && (
                                <button className="btn-ghost btn-sm" onClick={clearDone}>Clear finished</button>
                            )}
                            <button
                                className="btn-primary btn-sm"
                                disabled={running || queue.filter((r) => !dupes.has(r.productId!)).length === 0}
                                onClick={runAll}
                            >
                                {running ? <><Spinner size="sm" /> Uploading…</> : `Upload ${queue.filter((r) => !dupes.has(r.productId!)).length} clip${queue.length === 1 ? "" : "s"}`}
                            </button>
                        </div>
                    </div>

                    <ul className="divide-y divide-line">
                        {rows.map((row) => {
                            const product = row.productId ? byId.get(row.productId) : undefined;
                            const dup = row.productId ? dupes.has(row.productId) : false;
                            const st = STATUS[row.status];
                            const busy = row.status === "uploading" || row.status === "converting";
                            return (
                                <li key={row.id} className="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1.4fr)_auto] sm:items-center">
                                    {/* File */}
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-surface-900" title={row.file.name}>{row.file.name}</p>
                                        <p className="text-xs text-surface-500">{fmtSize(row.file.size)}{row.key && <> · looked for <code>{row.key}</code></>}</p>
                                        {busy && (
                                            <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-surface-100">
                                                <div
                                                    className={clsx("h-full rounded-full transition-all", row.status === "converting" ? "animate-pulse bg-warning" : "bg-primary")}
                                                    style={{ width: `${row.status === "converting" ? 100 : row.pct}%` }}
                                                />
                                            </div>
                                        )}
                                    </div>

                                    {/* Product */}
                                    <div className="flex min-w-0 items-center gap-2">
                                        {product?.primary_image?.image_url ? (
                                            <img src={product.primary_image.image_url} alt="" className="h-10 w-10 shrink-0 rounded-lg object-cover bg-surface-100" />
                                        ) : (
                                            <span className="h-10 w-10 shrink-0 rounded-lg bg-surface-100" aria-hidden="true" />
                                        )}
                                        <select
                                            className="w-full min-w-0 rounded-lg border border-line bg-white px-2 py-1.5 text-sm"
                                            value={row.productId ?? ""}
                                            disabled={busy || row.status === "done"}
                                            onChange={(e) => assign(row, e.target.value ? Number(e.target.value) : null)}
                                            aria-label={`Product for ${row.file.name}`}
                                        >
                                            <option value="">— choose a product —</option>
                                            {sorted.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {(p.en_translation?.name ?? p.slug)} · {p.sku}{p.video_url ? " · has clip" : ""}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Status */}
                                    <div className="flex items-center justify-between gap-2 sm:justify-end">
                                        <div className="text-right">
                                            <span className={clsx("inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold", st.cls)}>
                                                {row.status === "uploading" ? `Uploading ${row.pct}%` : st.label}
                                            </span>
                                            {dup && !busy && row.status !== "done" && (
                                                <p className="mt-1 max-w-[220px] text-xs text-danger">Another file is set to this product too — change one of them.</p>
                                            )}
                                            {row.note && <p className="mt-1 max-w-[220px] text-xs text-surface-600">{row.note}</p>}
                                            {row.status === "done" && product && (
                                                <button className="mt-1 text-xs font-semibold text-primary hover:underline" onClick={() => navigate(`/catalogue/products/${product.id}`)}>
                                                    Open product →
                                                </button>
                                            )}
                                        </div>
                                        {!busy && row.status !== "done" && (
                                            <button className="btn-ghost btn-sm text-xs" onClick={() => remove(row.id)} aria-label={`Remove ${row.file.name}`}>✕</button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            {rows.length === 0 && products && (
                <p className="text-xs text-surface-500">
                    Tip: on a phone, rename each clip to the product&apos;s SKU before sharing the folder to your computer.
                    A product that already has a clip shows &ldquo;Replaces clip&rdquo; — the old one stays live until the new one is ready.
                </p>
            )}
        </div>
    );
}
