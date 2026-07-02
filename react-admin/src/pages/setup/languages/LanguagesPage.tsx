import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { languagesApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Section,
    Field,
    useFieldAriaProps,
    Toggle,
    StatusBadge,
    DefaultBadge,
    ConfirmDialog,
    EmptyState,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import type { Language, LanguageFormData } from "@/types/setup";
import type { ApiError } from "@/types";

const schema = z.object({
    code: z.string().min(2).max(10),
    name: z.string().min(1, "Name is required"),
    native_name: z.string().min(1, "Native name is required"),
    direction: z.enum(["ltr", "rtl"]),
    flag: z.string().optional().default(""),
    is_default: z.boolean(),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    code: "",
    name: "",
    native_name: "",
    direction: "ltr",
    flag: "",
    is_default: false,
    is_active: true,
};

const PRESETS: Partial<Record<string, FormValues>> = {
    en: {
        code: "en",
        name: "English",
        native_name: "English",
        direction: "ltr",
        flag: "🇬🇧",
        is_default: true,
        is_active: true,
    },
    fr: {
        code: "fr",
        name: "French",
        native_name: "Français",
        direction: "ltr",
        flag: "🇫🇷",
        is_default: false,
        is_active: true,
    },
    pt: {
        code: "pt",
        name: "Portuguese",
        native_name: "Português",
        direction: "ltr",
        flag: "🇵🇹",
        is_default: false,
        is_active: true,
    },
    sw: {
        code: "sw",
        name: "Swahili",
        native_name: "Kiswahili",
        direction: "ltr",
        flag: "🇰🇪",
        is_default: false,
        is_active: true,
    },
    ar: {
        code: "ar",
        name: "Arabic",
        native_name: "العربية",
        direction: "rtl",
        flag: "🇸🇦",
        is_default: false,
        is_active: false,
    },
};

export default function LanguagesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Language | null>(null);
    const [deleting, setDeleting] = useState<Language | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["languages"],
        queryFn: () => languagesApi.list(),
    });

    const languages = data?.data ?? [];

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: DEFAULTS,
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    const openCreate = () => {
        reset(DEFAULTS);
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = (l: Language) => {
        reset({
            code: l.code,
            name: l.name,
            native_name: l.native_name,
            direction: l.direction,
            flag: l.flag,
            is_default: l.is_default,
            is_active: l.is_active,
        });
        setEditing(l);
        setModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (values: FormValues) =>
            editing
                ? languagesApi.update(editing.id, values)
                : languagesApi.create(values),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["languages"] });
            toast.success(editing ? "Language updated." : "Language added.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMutation = useMutation({
        mutationFn: (id: number) => languagesApi.toggle(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["languages"] }),
        onError: (err: ApiError) => toast.error(err.message),
    });

    const defaultMutation = useMutation({
        mutationFn: (id: number) => languagesApi.setDefault(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["languages"] });
            toast.success("Default language updated.");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => languagesApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["languages"] });
            toast.success("Language removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <div className="space-y-6 animate-fade-in max-w-4xl">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Languages</h1>
                    <p className="page-subtitle">
                        English is the default. Add French and Portuguese per
                        the SRS. All three are required for full storefront
                        support.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                    + Add Language
                </button>
            </div>

            <Section title="Configured Languages">
                {isLoading ? (
                    <div className="flex justify-center py-10">
                        <Spinner size="lg" />
                    </div>
                ) : languages.length === 0 ? (
                    <EmptyState
                        title="No languages configured"
                        description="Add English first (set as default), then French and Portuguese."
                        action={
                            <button
                                onClick={openCreate}
                                className="btn-primary btn-sm"
                            >
                                Add Language
                            </button>
                        }
                    />
                ) : (
                    <div className="divide-y divide-surface-50">
                        {languages.map((lang) => (
                            <div
                                key={lang.id}
                                className="flex items-center gap-4 py-3.5 first:pt-0 last:pb-0"
                            >
                                <div className="w-10 h-10 rounded-xl bg-surface-100 flex items-center justify-center shrink-0 text-xl">
                                    {lang.flag || "🌐"}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-semibold text-sm text-surface-900">
                                            {lang.name}
                                        </span>
                                        <span className="text-xs text-surface-400">
                                            {lang.native_name}
                                        </span>
                                        <span className="badge badge-neutral text-2xs uppercase">
                                            {lang.code}
                                        </span>
                                        {lang.is_default && <DefaultBadge />}
                                        <StatusBadge active={lang.is_active} />
                                        {lang.direction === "rtl" && (
                                            <span className="badge badge-warning text-2xs">
                                                RTL
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                    {!lang.is_default && (
                                        <button
                                            onClick={() =>
                                                defaultMutation.mutate(lang.id)
                                            }
                                            className="btn-ghost btn-sm text-xs"
                                        >
                                            Set default
                                        </button>
                                    )}
                                    <button
                                        onClick={() =>
                                            toggleMutation.mutate(lang.id)
                                        }
                                        className="btn-ghost btn-sm text-xs"
                                    >
                                        {lang.is_active ? "Disable" : "Enable"}
                                    </button>
                                    <button
                                        onClick={() => openEdit(lang)}
                                        className="btn-ghost btn-sm"
                                        aria-label="Edit"
                                    >
                                        <EditIcon />
                                    </button>
                                    {!lang.is_default && (
                                        <button
                                            onClick={() => setDeleting(lang)}
                                            className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                            aria-label="Delete"
                                        >
                                            <TrashIcon />
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Section>

            {/* ── Modal ─────────────────────────────────────────────────────────── */}
            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : "Add Language"}
                size="sm"
                footer={
                    <>
                        <button
                            onClick={() => setModalOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleSubmit((v) =>
                                saveMutation.mutate(v),
                            )}
                            disabled={saveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {saveMutation.isPending ? (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            ) : null}
                            {editing ? "Save" : "Add Language"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    {!editing && (
                        <div>
                            <p className="label mb-2">Quick preset</p>
                            <div className="flex gap-2 flex-wrap">
                                {Object.entries(PRESETS).map(
                                    ([code, preset]) => (
                                        <button
                                            key={code}
                                            type="button"
                                            onClick={() =>
                                                reset({
                                                    ...DEFAULTS,
                                                    ...preset,
                                                })
                                            }
                                            className="btn-secondary btn-sm text-xs"
                                        >
                                            {preset!.flag} {preset!.name}
                                        </button>
                                    ),
                                )}
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="Code"
                            error={errors.code?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${errors.code ? "input-error" : ""}`}
                                {...register("code")}
                                placeholder="en"
                                disabled={!!editing}
                            />
                        </Field>
                        <Field label="Flag emoji">
                            <FieldInput
                                className="input"
                                {...register("flag")}
                                placeholder="🇬🇧"
                            />
                        </Field>
                        <Field
                            label="Name (English)"
                            error={errors.name?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${errors.name ? "input-error" : ""}`}
                                {...register("name")}
                                placeholder="English"
                            />
                        </Field>
                        <Field
                            label="Native name"
                            error={errors.native_name?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${errors.native_name ? "input-error" : ""}`}
                                {...register("native_name")}
                                placeholder="English"
                            />
                        </Field>
                        <Field label="Text direction" className="col-span-2">
                            <FieldSelect
                                className="input"
                                {...register("direction")}
                            >
                                <option value="ltr">Left to Right (LTR)</option>
                                <option value="rtl">Right to Left (RTL)</option>
                            </FieldSelect>
                        </Field>
                    </div>

                    <div className="space-y-3 pt-2 border-t border-surface-100">
                        <Toggle
                            checked={watch("is_active")}
                            onChange={(v) => setValue("is_active", v)}
                            label="Active"
                        />
                        <Toggle
                            checked={watch("is_default")}
                            onChange={(v) => setValue("is_default", v)}
                            label="Set as default language"
                        />
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Language"
                message={`Remove ${deleting?.name}? Content in this language will no longer be served.`}
                confirmLabel="Remove"
            />
        </div>
    );
}

const EditIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
        />
    </svg>
);
const TrashIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
        />
    </svg>
);