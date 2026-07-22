import { useMemo, useState, type ChangeEvent } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { bannersApi, type Banner, type BannerInput } from "@/api/marketing";
import { productsApi } from "@/api/products";
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
    { key: "product_poster", label: "Poster", hint: "The editorial banner — headline + spec strip + image. One per product.", titleLabel: "Headline", subLabel: "Spec strip (use  |  between specs)", small: "Eyebrow" },
    { key: "product_feature", label: "Take a closer look", hint: "Interactive feature cards, each with its own image (the Apple pattern). Order = 1, 2, 3…", titleLabel: "Feature label", subLabel: "Description", small: "—" },
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

export default function ProductPagesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [slug, setSlug] = useState("");
    const [search, setSearch] = useState("");
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
    const products = useMemo(() => {
        const rows = (productData?.data ?? []).map((p) => ({
            slug: p.slug,
            sku: p.sku,
            name: p.en_translation?.name ?? p.slug,
        }));
        const q = search.trim().toLowerCase();
        return q ? rows.filter((r) => `${r.name} ${r.sku} ${r.slug}`.toLowerCase().includes(q)) : rows;
    }, [productData, search]);

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
                <Field label="Product" hint="Choose the SKU to customise">
                    <input
                        className="input mb-2"
                        placeholder="Search by name or SKU…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <select className="input" value={slug} onChange={(e) => setSlug(e.target.value)}>
                        <option value="">— Select a product —</option>
                        {products.map((p) => (
                            <option key={p.slug} value={p.slug}>{p.name} · {p.sku}</option>
                        ))}
                    </select>
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
