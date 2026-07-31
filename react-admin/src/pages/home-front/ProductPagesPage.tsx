import { useMemo, useRef, useState, type ChangeEvent } from "react";
import { createPortal } from "react-dom";
import { clsx } from "clsx";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { bannersApi, type Banner, type BannerInput } from "@/api/marketing";
import { productsApi } from "@/api/products";
import { get } from "@/api/client";
import type { ApiError } from "@/types";
import { useToastStore } from "@/store/toast.store";
import { compressImage } from "@/utils/compressImage";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Section,
    Field,
    Toggle,
    StatusBadge,
    ConfirmDialog,
    EmptyState,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";

/* Apple/oraimo-style product-page storytelling, per SKU. Each section is a
   banner keyed by placement=product:<slug>; the storefront PDP reads it and
   falls back to its built-in content when empty. */

interface Slot {
    key: string;
    label: string;
    hint: string;
    titleLabel: string;
    subLabel: string;
    small: string; // eyebrow / icon field label
}

const SLOTS: Slot[] = [
    { key: "product_highlight", label: "Highlights", hint: "Skimmable auto-advancing cards near the top — one claim + image each (the “Get the highlights” strip).", titleLabel: "Headline", subLabel: "Short text", small: "Tab label" },
    { key: "product_poster", label: "Poster", hint: "The editorial banner — headline + spec strip + image. One per product.", titleLabel: "Headline", subLabel: "Spec strip (use  |  between specs)", small: "Eyebrow" },
    { key: "product_feature", label: "Take a closer look", hint: "Interactive feature cards, each with its own image (the Apple pattern). Order = 1, 2, 3…", titleLabel: "Feature label", subLabel: "Description", small: "—" },
    { key: "product_chapter", label: "Story chapters", hint: "Cinematic editorial sections — eyebrow + big headline + copy over a full-bleed image (the Health/Fitness pattern). Stack a few in order.", titleLabel: "Headline", subLabel: "Copy", small: "Eyebrow" },
    { key: "product_pillar", label: "Best place to buy", hint: "The value cards below the story (icon + title + text).", titleLabel: "Title", subLabel: "Text", small: "Icon (emoji)" },
];
const SLOT_BY_KEY = Object.fromEntries(SLOTS.map((s) => [s.key, s]));

const schema = z.object({
    position: z.string().min(1),
    sort_order: z.coerce.number().int().min(0).optional(),
    title: z.string().optional(),
    subtitle: z.string().optional(),
    small: z.string().optional(),
    is_active: z.boolean(),
});
type FormValues = z.infer<typeof schema>;
const DEFAULTS: FormValues = { position: "product_feature", sort_order: 1, title: "", subtitle: "", small: "", is_active: true };

/* ── Product picker ──────────────────────────────────────────────────────────
   A native <select> can't group with colour or style its rows, so this is a
   custom listbox: products bucketed under their ROOT category, each header
   tinted with the category's own colour/icon, searchable, with the SKU shown
   quietly beside the name. Rendered through a portal so it escapes any
   clipping ancestor. */

interface PickerItem { slug: string; sku: string; name: string; }
interface PickerGroup { name: string; color: string | null; icon: string | null; items: PickerItem[]; }

const isHex = (c?: string | null): c is string => !!c && /^#[0-9a-fA-F]{6}$/.test(c);
const accentOf = (c: string | null) => (isHex(c) ? c : "#4f46e5");
const tintOf = (c: string | null) => (isHex(c) ? `${c}14` : "#f1f5f9");
const hairlineOf = (c: string | null) => (isHex(c) ? `${c}33` : "#e2e8f0");

function ProductPicker({
    value,
    groups,
    onChange,
}: {
    value: string;
    groups: PickerGroup[];
    onChange: (slug: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState("");
    const btnRef = useRef<HTMLButtonElement>(null);
    const [coords, setCoords] = useState({ top: 0, left: 0, width: 360 });

    const selected = useMemo(
        () => groups.flatMap((g) => g.items).find((p) => p.slug === value) ?? null,
        [groups, value],
    );

    const openMenu = () => {
        const r = btnRef.current?.getBoundingClientRect();
        if (r) {
            const width = Math.max(r.width, 288);
            setCoords({
                top: Math.min(r.bottom + 6, window.innerHeight - 380),
                left: Math.min(r.left, window.innerWidth - width - 8),
                width,
            });
        }
        setQuery("");
        setOpen(true);
    };

    const q = query.trim().toLowerCase();
    const filtered = useMemo(() => {
        if (!q) return groups;
        return groups
            .map((g) => ({ ...g, items: g.items.filter((p) => `${p.name} ${p.sku}`.toLowerCase().includes(q)) }))
            .filter((g) => g.items.length > 0);
    }, [groups, q]);

    return (
        <>
            <button
                ref={btnRef}
                type="button"
                onClick={openMenu}
                className="input w-full flex items-center justify-between gap-2 text-left"
            >
                {selected ? (
                    <span className="min-w-0 flex items-baseline gap-2">
                        <span className="font-medium text-surface-900 truncate">{selected.name}</span>
                        <span className="text-[11px] font-mono text-surface-400 shrink-0">{selected.sku}</span>
                    </span>
                ) : (
                    <span className="text-surface-400">— Select a product —</span>
                )}
                <svg className="w-4 h-4 text-surface-400 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth={1.75}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 8l4 4 4-4" />
                </svg>
            </button>

            {open &&
                createPortal(
                    <>
                        <div className="fixed inset-0 z-[60]" onClick={() => setOpen(false)} />
                        <div
                            className="fixed z-[61] bg-white rounded-2xl border border-surface-200 shadow-2xl overflow-hidden flex flex-col"
                            style={{ top: coords.top, left: coords.left, width: coords.width, maxHeight: "22rem" }}
                        >
                            <div className="p-2 border-b border-line shrink-0">
                                <input
                                    autoFocus
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search product or SKU…"
                                    className="w-full px-3 py-2 text-sm rounded-lg bg-surface-50 border border-surface-200 outline-none focus:border-brand-400 focus:bg-white placeholder:text-surface-400"
                                />
                            </div>

                            <div className="overflow-y-auto">
                                {filtered.length === 0 ? (
                                    <p className="text-sm text-surface-400 text-center py-8">No products found.</p>
                                ) : (
                                    filtered.map((g) => {
                                        const accent = accentOf(g.color);
                                        return (
                                            <div key={g.name}>
                                                <div
                                                    className="sticky top-0 z-10 flex items-center gap-2 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.08em]"
                                                    style={{
                                                        background: tintOf(g.color),
                                                        color: accent,
                                                        borderTop: `1px solid ${hairlineOf(g.color)}`,
                                                        borderBottom: `1px solid ${hairlineOf(g.color)}`,
                                                    }}
                                                >
                                                    {g.icon ? (
                                                        <span className="text-sm leading-none">{g.icon}</span>
                                                    ) : (
                                                        <span className="w-1.5 h-1.5 rounded-full" style={{ background: accent }} />
                                                    )}
                                                    <span className="truncate">{g.name}</span>
                                                    <span className="ml-auto text-[10px] font-semibold opacity-70">{g.items.length}</span>
                                                </div>
                                                {g.items.map((p, i) => (
                                                    <button
                                                        key={p.slug}
                                                        type="button"
                                                        onClick={() => {
                                                            onChange(p.slug);
                                                            setOpen(false);
                                                        }}
                                                        className={clsx(
                                                            "w-full flex items-baseline justify-between gap-3 px-3 py-2 text-left border-b border-surface-50 last:border-0 transition-colors",
                                                            p.slug === value
                                                                ? "!bg-brand-100"
                                                                : i % 2 === 1
                                                                  ? "bg-surface-50/50 hover:bg-brand-50/70"
                                                                  : "bg-white hover:bg-brand-50/70",
                                                        )}
                                                    >
                                                        <span
                                                            className={clsx(
                                                                "text-sm truncate",
                                                                p.slug === value ? "font-semibold text-brand-800" : "font-medium text-surface-800",
                                                            )}
                                                        >
                                                            {p.name}
                                                        </span>
                                                        <span className="text-[11px] font-mono text-surface-400 shrink-0">{p.sku}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>
                    </>,
                    document.body,
                )}
        </>
    );
}

export default function ProductPagesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [slug, setSlug] = useState("");
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Banner | null>(null);
    const [deleting, setDeleting] = useState<Banner | null>(null);
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [imagePreview, setImagePreview] = useState("");

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: DEFAULTS });
    const { register, handleSubmit, watch, setValue, reset, formState: { errors } } = form;

    const { data: productData } = useQuery({
        queryKey: ["products-slim"],
        queryFn: () => productsApi.list({ per_page: "300" }),
    });
    // Category tree → each category id's ROOT category (name + colour + icon),
    // so the picker groups products the way the catalogue is organised.
    const { data: categoryTree } = useQuery({
        queryKey: ["category-tree"],
        queryFn: () => get<any>("/v1/admin/categories", { params: { tree: "true" } }),
        staleTime: 300_000,
    });
    const rootMeta = useMemo(() => {
        const map = new Map<number, { name: string; color: string | null; icon: string | null }>();
        const walk = (node: any, root: any) => {
            map.set(node.id, { name: root.name_en, color: root.color ?? null, icon: root.icon ?? null });
            (node.children ?? []).forEach((c: any) => walk(c, root));
        };
        (categoryTree?.data ?? []).forEach((root: any) => walk(root, root));
        return map;
    }, [categoryTree]);

    const productGroups = useMemo<PickerGroup[]>(() => {
        const groups = new Map<string, PickerGroup>();
        for (const p of productData?.data ?? []) {
            const meta =
                (p.category?.id ? rootMeta.get(p.category.id) : undefined) ??
                (p.category ? { name: p.category.name_en, color: null, icon: null } : { name: "Uncategorised", color: null, icon: null });
            if (!groups.has(meta.name)) groups.set(meta.name, { name: meta.name, color: meta.color, icon: meta.icon, items: [] });
            groups.get(meta.name)!.items.push({ slug: p.slug, sku: p.sku, name: p.en_translation?.name ?? p.slug });
        }
        for (const g of groups.values())
            g.items.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" }));
        return [...groups.values()].sort((a, b) =>
            a.name === "Uncategorised" ? 1 : b.name === "Uncategorised" ? -1 : a.name.localeCompare(b.name),
        );
    }, [productData, rootMeta]);

    const placement = slug ? `product:${slug}` : "";
    const { data: bannerData, isLoading } = useQuery({
        queryKey: ["product-banners", placement],
        queryFn: () => bannersApi.list(placement),
        enabled: !!placement,
    });
    const banners = bannerData?.data ?? [];
    const bySlot = (key: string) =>
        banners.filter((b) => b.position === key).sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));

    const openCreate = (s: Slot) => {
        const rows = bySlot(s.key);
        const nextOrder = (rows.length ? rows[rows.length - 1].sort_order ?? 0 : 0) + 1;
        reset({ ...DEFAULTS, position: s.key, sort_order: nextOrder });
        setEditing(null); setImageFile(null); setImagePreview(""); setModalOpen(true);
    };
    const openEdit = (b: Banner) => {
        const st = (b.styles ?? {}) as Record<string, string>;
        reset({
            position: b.position, sort_order: b.sort_order ?? 0,
            title: b.title ?? "", subtitle: b.subtitle ?? "",
            small: st.eyebrow ?? st.icon ?? "", is_active: b.is_active,
        });
        setEditing(b); setImageFile(null); setImagePreview(b.image_url ?? ""); setModalOpen(true);
    };

    const onPickImage = async (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const c = await compressImage(file);
        setImageFile(c); setImagePreview(URL.createObjectURL(c));
    };

    const saveMutation = useMutation({
        mutationFn: async (v: FormValues) => {
            const body: BannerInput = {
                position: v.position,
                placement,
                sort_order: v.sort_order ?? 0,
                title: v.title || null,
                subtitle: v.subtitle || null,
                is_active: v.is_active,
                // small field feeds both eyebrow (poster) and icon (pillar).
                styles: { ...((editing?.styles as Record<string, unknown>) ?? {}), eyebrow: v.small || undefined, icon: v.small || undefined },
            };
            const saved = editing ? await bannersApi.update(editing.id, body) : await bannersApi.create(body);
            if (imageFile) await bannersApi.uploadImage(saved.data.id, imageFile);
            return saved;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["product-banners", placement] });
            toast.success(editing ? "Section updated." : "Section added.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => bannersApi.remove(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["product-banners", placement] });
            toast.success("Section removed."); setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const slot = SLOT_BY_KEY[watch("position")] ?? SLOTS[1];

    return (
        <div>
            <div className="page-header">
                <div>
                    <h1 className="page-title">Product Pages</h1>
                    <p className="text-sm text-surface-500 mt-1">
                        Give any product an Apple-style page — a poster, “Take a closer look” features and
                        value pillars. Pick a product, then build its sections. Gallery images are managed
                        under Catalog → Products → Images.
                    </p>
                </div>
            </div>

            <div className="mb-6 max-w-xl">
                <Field label="Product" hint="Grouped by category — search or pick a product to customise">
                    <ProductPicker value={slug} groups={productGroups} onChange={setSlug} />
                </Field>
            </div>

            {!slug ? (
                <EmptyState title="Pick a product" description="Select a product above to build its page sections." />
            ) : isLoading ? (
                <div className="flex justify-center py-16"><Spinner size="lg" /></div>
            ) : (
                <div className="space-y-6">
                    {SLOTS.map((s) => {
                        const rows = bySlot(s.key);
                        return (
                            <Section
                                key={s.key}
                                title={s.label}
                                description={s.hint}
                                actions={<button className="btn-secondary btn-sm" onClick={() => openCreate(s)}>+ Add</button>}
                            >
                                {rows.length === 0 ? (
                                    <div className="text-sm text-surface-400 py-2">No entries — the storefront shows its built-in content.</div>
                                ) : (
                                    <div className="divide-y divide-surface-200">
                                        {rows.map((b) => (
                                            <div key={b.id} className="flex items-center gap-3 py-3">
                                                <span className="w-7 h-7 rounded-full bg-surface-100 text-surface-600 text-xs font-bold flex items-center justify-center flex-none">{b.sort_order ?? 0}</span>
                                                {b.image_url ? (
                                                    <img src={b.image_url} alt="" className="w-16 h-11 object-cover rounded border border-surface-200 flex-none" />
                                                ) : (
                                                    <div className="w-16 h-11 rounded border border-dashed border-surface-300 flex-none" />
                                                )}
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-semibold truncate">{b.title || "(untitled)"}</span>
                                                        {b.is_active ? <StatusBadge active /> : null}
                                                    </div>
                                                    {b.subtitle ? <div className="text-sm text-surface-500 truncate">{b.subtitle}</div> : null}
                                                </div>
                                                <button className="btn-ghost btn-sm" onClick={() => openEdit(b)}>Edit</button>
                                                <button className="btn-ghost btn-sm" onClick={() => setDeleting(b)}>Delete</button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </Section>
                        );
                    })}
                </div>
            )}

            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${slot.label}` : `Add ${slot.label}`}
                size="lg"
                footer={
                    <>
                        <button onClick={() => setModalOpen(false)} className="btn-secondary btn-sm">Cancel</button>
                        <button onClick={handleSubmit((v) => saveMutation.mutate(v))} disabled={saveMutation.isPending} className="btn-primary btn-sm">
                            {saveMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : null}
                            {editing ? "Save" : "Add"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field label="Image" hint="Feature / poster image (JPG, PNG or WebP)">
                        <div className="flex items-center gap-3">
                            {imagePreview ? (
                                <img src={imagePreview} alt="" className="w-28 h-18 object-cover rounded border border-surface-200" />
                            ) : (
                                <div className="w-28 h-18 rounded border border-dashed border-surface-300 flex items-center justify-center text-xs text-surface-400">No image</div>
                            )}
                            <label className="btn-secondary btn-sm cursor-pointer">
                                {imagePreview ? "Change image" : "Upload image"}
                                <input type="file" accept="image/*" className="hidden" onChange={onPickImage} />
                            </label>
                        </div>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Section" error={errors.position?.message} required>
                            <FieldSelect className="input" {...register("position")}>
                                {SLOTS.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
                            </FieldSelect>
                        </Field>
                        <Field label="Position (order)" hint="1 = first">
                            <FieldInput className="input" type="number" {...register("sort_order")} />
                        </Field>
                    </div>
                    {slot.small !== "—" ? (
                        <Field label={slot.small}>
                            <FieldInput className="input" {...register("small")} placeholder={slot.key === "product_pillar" ? "⛪" : "Made to Measure"} />
                        </Field>
                    ) : null}
                    <Field label={slot.titleLabel}>
                        <FieldInput className="input" {...register("title")} />
                    </Field>
                    <Field label={slot.subLabel}>
                        <FieldTextarea className="input" rows={2} {...register("subtitle")} />
                    </Field>
                    <Toggle checked={watch("is_active")} onChange={(v) => setValue("is_active", v)} label="Visible" description="Show this section on the product page." />
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Section"
                message={`Remove "${deleting?.title || "this section"}"?`}
                confirmLabel="Remove"
                variant="danger"
            />
        </div>
    );
}
