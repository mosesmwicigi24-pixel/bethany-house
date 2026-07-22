import { useState, type ChangeEvent } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { bannersApi, type Banner, type BannerInput } from "@/api/marketing";
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

/* Home-front content-block editor. Every marketing block on the storefront is a
   banner, placed in a slot (position) and ordered within it (sort_order = the
   hero slider's "position 1, 2, 3"). */

interface Slot {
    key: string;
    placement: string;
    label: string;
    hint: string;
}

const SLOTS: Slot[] = [
    { key: "home_hero", placement: "homepage", label: "Hero Slider", hint: "Full-width rotating slides at the very top — order = slide 1, 2, 3…" },
    { key: "home_promo", placement: "homepage", label: "Promo Strips", hint: "The two mid-page offer bands." },
    { key: "shop_hero", placement: "shop", label: "Shop Page Hero", hint: "The banner across the top of the /shop page." },
    { key: "home_category", placement: "homepage", label: "Category Tiles", hint: "The “Shop by category” cards." },
    { key: "home_testimonial", placement: "homepage", label: "Testimonials", hint: "The “Loved at the altar” quotes." },
    { key: "home_pillar", placement: "homepage", label: "Why Bethany Pillars", hint: "The four value pillars." },
    { key: "home_newsletter", placement: "homepage", label: "Newsletter", hint: "The “Grace in every detail” sign-up block." },
];
const SLOT_BY_KEY = Object.fromEntries(SLOTS.map((s) => [s.key, s]));

const schema = z.object({
    position: z.string().min(1, "Slot is required"),
    sort_order: z.coerce.number().int().min(0).optional(),
    title: z.string().optional(),
    subtitle: z.string().optional(),
    eyebrow: z.string().optional(),
    link_url: z.string().optional(),
    link_text: z.string().optional(),
    theme: z.string().optional(),
    is_active: z.boolean(),
});
type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    position: "home_hero",
    sort_order: 1,
    title: "",
    subtitle: "",
    eyebrow: "",
    link_url: "",
    link_text: "",
    theme: "",
    is_active: true,
};

export default function HomeContentPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Banner | null>(null);
    const [deleting, setDeleting] = useState<Banner | null>(null);
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [imagePreview, setImagePreview] = useState<string>("");

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: DEFAULTS });
    const { register, handleSubmit, watch, setValue, reset, formState: { errors } } = form;

    const { data, isLoading } = useQuery({
        queryKey: ["home-banners"],
        queryFn: () => bannersApi.list(),
    });
    const banners = data?.data ?? [];
    const bySlot = (key: string) =>
        banners.filter((b) => b.position === key).sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));

    const openCreate = (slot: Slot) => {
        const rows = bySlot(slot.key);
        const nextOrder = (rows.length ? rows[rows.length - 1].sort_order ?? 0 : 0) + 1;
        reset({ ...DEFAULTS, position: slot.key, sort_order: nextOrder });
        setEditing(null);
        setImageFile(null);
        setImagePreview("");
        setModalOpen(true);
    };

    const openEdit = (b: Banner) => {
        const styles = (b.styles ?? {}) as Record<string, string>;
        reset({
            position: b.position,
            sort_order: b.sort_order ?? 0,
            title: b.title ?? "",
            subtitle: b.subtitle ?? "",
            eyebrow: styles.eyebrow ?? "",
            link_url: b.link_url ?? "",
            link_text: b.link_text ?? "",
            theme: styles.theme ?? "",
            is_active: b.is_active,
        });
        setEditing(b);
        setImageFile(null);
        setImagePreview(b.image_url ?? "");
        setModalOpen(true);
    };

    const onPickImage = async (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const compressed = await compressImage(file);
        setImageFile(compressed);
        setImagePreview(URL.createObjectURL(compressed));
    };

    const saveMutation = useMutation({
        mutationFn: async (v: FormValues) => {
            const body: BannerInput = {
                position: v.position,
                placement: SLOT_BY_KEY[v.position]?.placement ?? "homepage",
                sort_order: v.sort_order ?? 0,
                title: v.title || null,
                subtitle: v.subtitle || null,
                link_url: v.link_url || null,
                link_text: v.link_text || null,
                is_active: v.is_active,
                // Preserve any rich style fields (marks, plate, second CTA) that
                // the form doesn't edit — only override eyebrow + theme.
                styles: {
                    ...((editing?.styles as Record<string, unknown>) ?? {}),
                    eyebrow: v.eyebrow || undefined,
                    theme: v.theme || undefined,
                },
            };
            const saved = editing
                ? await bannersApi.update(editing.id, body)
                : await bannersApi.create(body);
            if (imageFile) {
                await bannersApi.uploadImage(saved.data.id, imageFile);
            }
            return saved;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["home-banners"] });
            toast.success(editing ? "Block updated." : "Block added.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => bannersApi.remove(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["home-banners"] });
            toast.success("Block removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <div>
            <div className="page-header">
                <div>
                    <h1 className="page-title">Home Page</h1>
                    <p className="text-sm text-surface-500 mt-1">
                        Edit every marketing block on the storefront — upload images, set the order
                        (slide 1, 2, 3…) and the wording. Empty slots keep the built-in content.
                    </p>
                </div>
            </div>

            {isLoading ? (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            ) : (
                <div className="space-y-6">
                    {SLOTS.map((slot) => {
                        const rows = bySlot(slot.key);
                        return (
                            <Section
                                key={slot.key}
                                title={slot.label}
                                description={slot.hint}
                                actions={
                                    <button className="btn-secondary btn-sm" onClick={() => openCreate(slot)}>
                                        + Add
                                    </button>
                                }
                            >
                                {rows.length === 0 ? (
                                    <div className="text-sm text-surface-400 py-2">
                                        No entries — the storefront shows its built-in content here.
                                    </div>
                                ) : (
                                    <div className="divide-y divide-surface-200">
                                        {rows.map((b) => (
                                            <div key={b.id} className="flex items-center gap-3 py-3">
                                                <span className="w-7 h-7 rounded-full bg-surface-100 text-surface-600 text-xs font-bold flex items-center justify-center flex-none">
                                                    {b.sort_order ?? 0}
                                                </span>
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
                title={editing ? `Edit ${SLOT_BY_KEY[watch("position")]?.label ?? "block"}` : `Add to ${SLOT_BY_KEY[watch("position")]?.label ?? "block"}`}
                size="lg"
                footer={
                    <>
                        <button onClick={() => setModalOpen(false)} className="btn-secondary btn-sm">Cancel</button>
                        <button
                            onClick={handleSubmit((v) => saveMutation.mutate(v))}
                            disabled={saveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {saveMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : null}
                            {editing ? "Save" : "Add"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field label="Image" hint="Upload the slide / block image (JPG, PNG or WebP)">
                        <div className="flex items-center gap-3">
                            {imagePreview ? (
                                <img src={imagePreview} alt="" className="w-28 h-18 object-cover rounded border border-surface-200" />
                            ) : (
                                <div className="w-28 h-18 rounded border border-dashed border-surface-300 flex items-center justify-center text-xs text-surface-400">
                                    No image
                                </div>
                            )}
                            <label className="btn-secondary btn-sm cursor-pointer">
                                {imagePreview ? "Change image" : "Upload image"}
                                <input type="file" accept="image/*" className="hidden" onChange={onPickImage} />
                            </label>
                        </div>
                    </Field>

                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Slot" error={errors.position?.message} required>
                            <FieldSelect className="input" {...register("position")}>
                                {SLOTS.map((s) => (
                                    <option key={s.key} value={s.key}>{s.label}</option>
                                ))}
                            </FieldSelect>
                        </Field>
                        <Field label="Position (order)" hint="1 = first slide/card in this slot">
                            <FieldInput className="input" type="number" {...register("sort_order")} />
                        </Field>
                    </div>

                    <Field label="Eyebrow" hint="Small label above the headline (optional)">
                        <FieldInput className="input" {...register("eyebrow")} placeholder="Made to Measure" />
                    </Field>
                    <Field label="Headline">
                        <FieldInput className="input" {...register("title")} placeholder="Tailored for the pulpit." />
                    </Field>
                    <Field label="Sub-text">
                        <FieldTextarea className="input" rows={2} {...register("subtitle")} placeholder="Gowns, cassocks and chasubles measured in Nairobi." />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Button label">
                            <FieldInput className="input" {...register("link_text")} placeholder="Book a fitting" />
                        </Field>
                        <Field label="Button link">
                            <FieldInput className="input" {...register("link_url")} placeholder="/shop" />
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Theme" hint="Optional style hint (e.g. dark, ivory, slate)">
                            <FieldInput className="input" {...register("theme")} placeholder="dark" />
                        </Field>
                        <div className="flex items-end">
                            <Toggle
                                checked={watch("is_active")}
                                onChange={(v) => setValue("is_active", v)}
                                label="Visible"
                                description="Show this block on the site."
                            />
                        </div>
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Block"
                message={`Remove "${deleting?.title || "this block"}"? This cannot be undone.`}
                confirmLabel="Remove"
                variant="danger"
            />
        </div>
    );
}
