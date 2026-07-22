import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { seasonsApi, promotionsApi, type Season } from "@/api/marketing";
import type { ApiError } from "@/types";
import { useToastStore } from "@/store/toast.store";
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
} from "@/components/setup/FormComponents";

/* Liturgical seasons drive the storefront's subtle seasonal skin (motif +
   scripture — the navy/gold brand is never recoloured) and link to a Blessed
   Friday campaign. Storefront reads the active one via GET /site/theme. */

const MOTIF_GLYPH: Record<string, string> = {
    lily: "🕊️", flame: "🔥", wheat: "🌾", star: "✦", cross: "✝",
};

const schema = z.object({
    key: z
        .string()
        .min(1, "Key is required")
        .regex(/^[a-z0-9-]+$/, "Lowercase letters, numbers and hyphens only"),
    name: z.string().min(1, "Name is required"),
    tagline: z.string().optional(),
    scripture: z.string().optional(),
    accent: z.string().optional(),
    motif: z.string().optional(),
    starts_at: z.string().optional(),
    ends_at: z.string().optional(),
    is_active: z.boolean(),
    priority: z.coerce.number().int().min(0).optional(),
    sort_order: z.coerce.number().int().min(0).optional(),
    promotion_id: z.string().optional(), // "" = none, else numeric string
});

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    key: "",
    name: "",
    tagline: "",
    scripture: "",
    accent: "#c9a227",
    motif: "",
    starts_at: "",
    ends_at: "",
    is_active: true,
    priority: 10,
    sort_order: 0,
    promotion_id: "",
};

const toLocal = (iso?: string | null) => (iso ? iso.slice(0, 16) : "");

export default function SeasonsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Season | null>(null);
    const [deleting, setDeleting] = useState<Season | null>(null);

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: DEFAULTS });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    const { data, isLoading } = useQuery({
        queryKey: ["marketing-seasons"],
        queryFn: () => seasonsApi.list(),
    });
    const seasons = data?.data ?? [];

    // Campaigns for the "link a promotion" dropdown.
    const { data: promoData } = useQuery({
        queryKey: ["marketing-promotions"],
        queryFn: () => promotionsApi.list(),
    });
    const promotions = promoData?.data ?? [];

    const openCreate = () => {
        reset(DEFAULTS);
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = (s: Season) => {
        reset({
            key: s.key,
            name: s.name,
            tagline: s.tagline ?? "",
            scripture: s.scripture ?? "",
            accent: s.theme?.accent ?? "#c9a227",
            motif: s.theme?.motif ?? "",
            starts_at: toLocal(s.starts_at),
            ends_at: toLocal(s.ends_at),
            is_active: s.is_active,
            priority: s.priority ?? 10,
            sort_order: s.sort_order ?? 0,
            promotion_id: s.promotion_id ? String(s.promotion_id) : "",
        });
        setEditing(s);
        setModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (v: FormValues) => {
            const body = {
                key: v.key,
                name: v.name,
                tagline: v.tagline || null,
                scripture: v.scripture || null,
                theme: { accent: v.accent || undefined, motif: v.motif || undefined },
                starts_at: v.starts_at || null,
                ends_at: v.ends_at || null,
                is_active: v.is_active,
                priority: v.priority ?? 0,
                sort_order: v.sort_order ?? 0,
                promotion_id: v.promotion_id ? Number(v.promotion_id) : null,
            };
            return editing ? seasonsApi.update(editing.id, body) : seasonsApi.create(body);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["marketing-seasons"] });
            toast.success(editing ? "Season updated." : "Season created.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => seasonsApi.remove(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["marketing-seasons"] });
            toast.success("Season removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const isRunning = (s: Season) => {
        if (!s.is_active) return false;
        const now = Date.now();
        if (s.starts_at && new Date(s.starts_at).getTime() > now) return false;
        if (s.ends_at && new Date(s.ends_at).getTime() < now) return false;
        return true;
    };
    const windowLabel = (s: Season) => {
        const f = (v?: string | null) => (v ? new Date(v).toLocaleDateString() : "—");
        return `${f(s.starts_at)} → ${f(s.ends_at)}`;
    };

    return (
        <div>
            <div className="page-header">
                <div>
                    <h1 className="page-title">Seasons</h1>
                    <p className="text-sm text-surface-500 mt-1">
                        The storefront's subtle seasonal skin — motif + scripture only, the brand
                        colours never change. Link a campaign to run its Blessed Friday discount.
                    </p>
                </div>
                <button className="btn-primary" onClick={openCreate}>
                    New Season
                </button>
            </div>

            {isLoading ? (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            ) : seasons.length === 0 ? (
                <EmptyState
                    title="No seasons yet"
                    description="Add a liturgical season with its dates, scripture and motif."
                    action={
                        <button className="btn-primary" onClick={openCreate}>
                            New Season
                        </button>
                    }
                />
            ) : (
                <Section title="Seasons">
                    <div className="divide-y divide-surface-200">
                        {seasons.map((s) => (
                            <div key={s.id} className="flex items-center gap-4 py-3">
                                <span
                                    className="text-lg w-6 text-center flex-none"
                                    style={s.theme?.accent ? { color: s.theme.accent } : undefined}
                                    aria-hidden
                                >
                                    {MOTIF_GLYPH[s.theme?.motif ?? ""] ?? "✦"}
                                </span>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold truncate">{s.name}</span>
                                        {isRunning(s) ? <StatusBadge active /> : null}
                                    </div>
                                    <div className="text-sm text-surface-500 truncate">
                                        {windowLabel(s)}
                                        {s.promotion ? ` · ${s.promotion.name}` : " · no campaign"}
                                    </div>
                                </div>
                                <button className="btn-ghost btn-sm" onClick={() => openEdit(s)}>
                                    Edit
                                </button>
                                <button className="btn-ghost btn-sm" onClick={() => setDeleting(s)}>
                                    Delete
                                </button>
                            </div>
                        ))}
                    </div>
                </Section>
            )}

            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : "New Season"}
                size="lg"
                footer={
                    <>
                        <button onClick={() => setModalOpen(false)} className="btn-secondary btn-sm">
                            Cancel
                        </button>
                        <button
                            onClick={handleSubmit((v) => saveMutation.mutate(v))}
                            disabled={saveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {saveMutation.isPending ? (
                                <Spinner size="xs" className="border-white/30 border-t-white" />
                            ) : null}
                            {editing ? "Save" : "Create"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Key" error={errors.key?.message} required hint="e.g. harvest">
                            <FieldInput className="input font-mono text-sm" {...register("key")} placeholder="harvest" />
                        </Field>
                        <Field label="Name" error={errors.name?.message} required>
                            <FieldInput className="input" {...register("name")} placeholder="Harvest Thanksgiving" />
                        </Field>
                    </div>
                    <Field label="Tagline" hint="Optional short line">
                        <FieldInput className="input" {...register("tagline")} placeholder="First-fruits — a season of thanksgiving." />
                    </Field>
                    <Field label="Scripture" hint="Shown on the seasonal strip">
                        <FieldInput className="input" {...register("scripture")} placeholder="“Honour the Lord with the firstfruits…” — Proverbs 3:9" />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Accent (motif tint)" hint="Tints only the small motif glyph">
                            <div className="flex gap-2">
                                <FieldInput
                                    type="color"
                                    className="w-10 h-10 rounded cursor-pointer border border-surface-200 p-0.5"
                                    value={watch("accent") || "#c9a227"}
                                    onChange={(e) => setValue("accent", e.target.value)}
                                />
                                <FieldInput className="input flex-1 font-mono text-sm" {...register("accent")} placeholder="#c9a227" />
                            </div>
                        </Field>
                        <Field label="Motif">
                            <FieldSelect className="input" {...register("motif")}>
                                <option value="">None</option>
                                <option value="lily">Lily 🕊️ (Easter)</option>
                                <option value="flame">Flame 🔥 (Pentecost)</option>
                                <option value="wheat">Wheat 🌾 (Harvest)</option>
                                <option value="star">Star ✦ (Advent)</option>
                                <option value="cross">Cross ✝</option>
                            </FieldSelect>
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Starts at">
                            <FieldInput className="input" type="datetime-local" {...register("starts_at")} />
                        </Field>
                        <Field label="Ends at" error={errors.ends_at?.message}>
                            <FieldInput className="input" type="datetime-local" {...register("ends_at")} />
                        </Field>
                    </div>
                    <Field
                        label="Blessed Friday campaign"
                        hint="The discount that runs during this season (optional)"
                    >
                        <FieldSelect className="input" {...register("promotion_id")}>
                            <option value="">None (theme only)</option>
                            {promotions.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Priority" hint="Higher wins if windows overlap">
                            <FieldInput className="input" type="number" {...register("priority")} />
                        </Field>
                        <Field label="Sort order">
                            <FieldInput className="input" type="number" {...register("sort_order")} />
                        </Field>
                    </div>
                    <Toggle
                        checked={watch("is_active")}
                        onChange={(v) => setValue("is_active", v)}
                        label="Enabled"
                        description="When on and within its window, the storefront wears this season."
                    />
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Season"
                message={`Remove "${deleting?.name}"? This cannot be undone.`}
                confirmLabel="Remove"
                variant="danger"
            />
        </div>
    );
}
