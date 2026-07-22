import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { promotionsApi, type Promotion } from "@/api/marketing";
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
    FieldTextarea,
} from "@/components/setup/FormComponents";

/* Blessed Friday campaigns = hub promotions. This is where the owner sets each
   season's discount (10–20%) + window. The discount is applied server-side
   (never on the storefront); a season links to one of these. */

const schema = z
    .object({
        name: z.string().min(1, "Name is required"),
        description: z.string().optional(),
        discount_type: z.enum(["percentage", "fixed"]),
        discount_value: z.coerce.number().min(0, "Must be 0 or more"),
        starts_at: z.string().optional(),
        ends_at: z.string().optional(),
        is_active: z.boolean(),
        priority: z.coerce.number().int().min(0).optional(),
    })
    .refine((v) => v.discount_type !== "percentage" || v.discount_value <= 100, {
        path: ["discount_value"],
        message: "A percentage cannot exceed 100.",
    });

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    name: "",
    description: "",
    discount_type: "percentage",
    discount_value: 15,
    starts_at: "",
    ends_at: "",
    is_active: false,
    priority: 0,
};

// datetime-local wants "YYYY-MM-DDTHH:mm"; the API returns full ISO.
const toLocal = (iso?: string | null) => (iso ? iso.slice(0, 16) : "");

export default function CampaignsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Promotion | null>(null);
    const [deleting, setDeleting] = useState<Promotion | null>(null);

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
        queryKey: ["marketing-promotions"],
        queryFn: () => promotionsApi.list(),
    });
    const promotions = data?.data ?? [];
    const stats = data?.stats;

    const openCreate = () => {
        reset(DEFAULTS);
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = (p: Promotion) => {
        reset({
            name: p.name,
            description: p.description ?? "",
            discount_type: p.discount_type,
            discount_value: Number(p.discount_value),
            starts_at: toLocal(p.starts_at),
            ends_at: toLocal(p.ends_at),
            is_active: p.is_active,
            priority: p.priority ?? 0,
        });
        setEditing(p);
        setModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (v: FormValues) => {
            const body = {
                ...v,
                starts_at: v.starts_at || null,
                ends_at: v.ends_at || null,
            };
            return editing ? promotionsApi.update(editing.id, body) : promotionsApi.create(body);
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["marketing-promotions"] });
            toast.success(editing ? "Campaign updated." : "Campaign created.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => promotionsApi.remove(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["marketing-promotions"] });
            toast.success("Campaign removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const isRunning = (p: Promotion) => {
        if (!p.is_active) return false;
        const now = Date.now();
        if (p.starts_at && new Date(p.starts_at).getTime() > now) return false;
        if (p.ends_at && new Date(p.ends_at).getTime() < now) return false;
        return true;
    };
    const discountLabel = (p: Promotion) =>
        p.discount_type === "percentage"
            ? `${Number(p.discount_value)}% off`
            : `KES ${Number(p.discount_value)} off`;
    const windowLabel = (p: Promotion) => {
        const f = (s?: string | null) => (s ? new Date(s).toLocaleDateString() : "—");
        return `${f(p.starts_at)} → ${f(p.ends_at)}`;
    };

    return (
        <div>
            <div className="page-header">
                <div>
                    <h1 className="page-title">Campaigns</h1>
                    <p className="text-sm text-surface-500 mt-1">
                        Blessed Friday sales — set the discount and its window. Applied server-side,
                        then attach one to a season.
                    </p>
                </div>
                <button className="btn-primary" onClick={openCreate}>
                    New Campaign
                </button>
            </div>

            {isLoading ? (
                <div className="flex justify-center py-16">
                    <Spinner size="lg" />
                </div>
            ) : promotions.length === 0 ? (
                <EmptyState
                    title="No campaigns yet"
                    description="Create a Blessed Friday campaign, then link it to a season on the Seasons page."
                    action={
                        <button className="btn-primary" onClick={openCreate}>
                            New Campaign
                        </button>
                    }
                />
            ) : (
                <Section title={`Campaigns${stats ? ` · ${stats.running} running` : ""}`}>
                    <div className="divide-y divide-surface-200">
                        {promotions.map((p) => (
                            <div key={p.id} className="flex items-center gap-4 py-3">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold truncate">{p.name}</span>
                                        {isRunning(p) ? <StatusBadge active /> : null}
                                    </div>
                                    <div className="text-sm text-surface-500">
                                        {discountLabel(p)} · {windowLabel(p)}
                                    </div>
                                </div>
                                <button className="btn-ghost btn-sm" onClick={() => openEdit(p)}>
                                    Edit
                                </button>
                                <button className="btn-ghost btn-sm" onClick={() => setDeleting(p)}>
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
                title={editing ? `Edit ${editing.name}` : "New Campaign"}
                size="md"
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
                    <Field label="Name" error={errors.name?.message} required>
                        <FieldInput
                            className="input"
                            {...register("name")}
                            placeholder="Blessed Friday — Harvest"
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Discount type">
                            <FieldSelect className="input" {...register("discount_type")}>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed (KES)</option>
                            </FieldSelect>
                        </Field>
                        <Field
                            label="Discount value"
                            error={errors.discount_value?.message}
                            required
                        >
                            <FieldInput
                                className="input"
                                type="number"
                                step="0.01"
                                {...register("discount_value")}
                            />
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Starts at">
                            <FieldInput
                                className="input"
                                type="datetime-local"
                                {...register("starts_at")}
                            />
                        </Field>
                        <Field label="Ends at" error={errors.ends_at?.message}>
                            <FieldInput
                                className="input"
                                type="datetime-local"
                                {...register("ends_at")}
                            />
                        </Field>
                    </div>
                    <Field label="Description" hint="Optional internal note">
                        <FieldTextarea className="input" rows={2} {...register("description")} />
                    </Field>
                    <Toggle
                        checked={watch("is_active")}
                        onChange={(v) => setValue("is_active", v)}
                        label="Active"
                        description="When on and within its window, the discount runs."
                    />
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Campaign"
                message={`Remove "${deleting?.name}"? This cannot be undone.`}
                confirmLabel="Remove"
                variant="danger"
            />
        </div>
    );
}
