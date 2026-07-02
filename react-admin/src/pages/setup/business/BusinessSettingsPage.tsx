import { useEffect, useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { settingsApi, currenciesApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Section, Field, useFieldAriaProps, Toggle, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { Spinner } from "@/components/ui/Spinner";
import type { BusinessSettings } from "@/types/setup";
import type { ApiError } from "@/types";

const schema = z.object({
    app_name: z.string().min(1, "Business name is required"),
    app_tagline: z.string(),
    app_email: z.string().email("Enter a valid email"),
    app_phone: z.string(),
    app_address: z.string(),
    app_city: z.string(),
    app_country: z.string(),
    app_timezone: z.string(),
    default_currency: z.string(),
    default_language: z.string(),
    order_prefix: z.string(),
    receipt_footer: z.string(),
    low_stock_threshold: z.coerce.number().min(0),
    tax_inclusive: z.boolean(),
    enable_guest_checkout: z.boolean(),
    enable_reviews: z.boolean(),
    maintenance_mode: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const TIMEZONES = [
    "Africa/Nairobi",
    "Africa/Lagos",
    "Africa/Johannesburg",
    "Africa/Cairo",
    "Europe/London",
    "Europe/Paris",
    "America/New_York",
    "America/Los_Angeles",
    "Asia/Dubai",
    "UTC",
];

export default function BusinessSettingsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [logoFile, setLogoFile] = useState<File | null>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["settings"],
        queryFn: () => settingsApi.get(),
    });

    const { data: currenciesData } = useQuery({
        queryKey: ["currencies"],
        queryFn: () => currenciesApi.list(),
    });

    const activeCurrencies =
        currenciesData?.data?.filter((c) => c.is_active) ?? [];

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            app_name: "",
            app_tagline: "",
            app_email: "",
            app_phone: "",
            app_address: "",
            app_city: "",
            app_country: "KE",
            app_timezone: "Africa/Nairobi",
            default_currency: "KES",
            default_language: "en",
            order_prefix: "BH-",
            receipt_footer: "",
            low_stock_threshold: 5,
            tax_inclusive: false,
            enable_guest_checkout: true,
            enable_reviews: true,
            maintenance_mode: false,
        },
    });

    useEffect(() => {
        if (data?.settings) {
            const s = data.settings;
            form.reset({
                app_name: s.app_name ?? "",
                app_tagline: s.app_tagline ?? "",
                app_email: s.app_email ?? "",
                app_phone: s.app_phone ?? "",
                app_address: s.app_address ?? "",
                app_city: s.app_city ?? "",
                app_country: s.app_country ?? "KE",
                app_timezone: s.app_timezone ?? "Africa/Nairobi",
                default_currency: s.default_currency ?? "KES",
                default_language: s.default_language ?? "en",
                order_prefix: s.order_prefix ?? "BH-",
                receipt_footer: s.receipt_footer ?? "",
                low_stock_threshold: Number(s.low_stock_threshold ?? 5),
                tax_inclusive: Boolean(s.tax_inclusive),
                enable_guest_checkout: s.enable_guest_checkout !== false,
                enable_reviews: s.enable_reviews !== false,
                maintenance_mode: Boolean(s.maintenance_mode),
            });
            if (s.app_logo_url) {
                const url = s.app_logo_url;
                setLogoPreview(
                    url.startsWith("http")
                        ? url
                        : `${import.meta.env.VITE_API_URL?.replace("/api", "") ?? "http://localhost:8000"}${url}`,
                );
            }
        }
    }, [data, form]);

    const saveMutation = useMutation({
        mutationFn: (values: FormValues) => settingsApi.update(values),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["settings"] });
            toast.success("Settings saved successfully.");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const logoMutation = useMutation({
        mutationFn: (file: File) => settingsApi.uploadLogo(file),
        onSuccess: (res) => {
            setLogoPreview(res.url);
            qc.invalidateQueries({ queryKey: ["settings"] });
            toast.success("Logo uploaded.");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setLogoFile(file);
        setLogoPreview(URL.createObjectURL(file));
        logoMutation.mutate(file);
    };

    const onSubmit = (values: FormValues) => saveMutation.mutate(values);

    const {
        watch,
        setValue,
        register,
        formState: { errors },
    } = form;

    if (isLoading) {
        return (
            <div className="flex items-center justify-center h-64">
                <Spinner size="lg" />
            </div>
        );
    }

    return (
        <form
            onSubmit={form.handleSubmit(onSubmit)}
            className="space-y-6 animate-fade-in max-w-3xl"
        >
            <div className="page-header">
                <h1 className="page-title">Business Settings</h1>
                <p className="page-subtitle">
                    Core configuration for Bethany House - these settings affect
                    all three modules.
                </p>
            </div>

            {/* ── Business Identity ─────────────────────────────────────────────── */}
            <Section
                title="Business Identity"
                description="Basic information displayed on receipts, emails, and the storefront."
            >
                {/* Logo */}
                <div className="flex items-center gap-5">
                    <div className="w-20 h-20 rounded-xl border-2 border-dashed border-surface-200 flex items-center justify-center overflow-hidden bg-surface-50 shrink-0">
                        {logoPreview ? (
                            <img
                                src={logoPreview}
                                alt="Logo"
                                className="w-full h-full object-contain p-1"
                            />
                        ) : (
                            <svg
                                className="w-8 h-8 text-surface-300"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={1.5}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3 3h18M3 21h18"
                                />
                            </svg>
                        )}
                    </div>
                    <div>
                        <label className="btn-secondary btn-sm cursor-pointer">
                            {logoMutation.isPending ? (
                                <Spinner size="xs" />
                            ) : (
                                "Upload Logo"
                            )}
                            <input
                                type="file"
                                className="hidden"
                                accept="image/*"
                                onChange={handleLogoChange}
                            />
                        </label>
                        <p className="text-2xs text-surface-400 mt-1.5">
                            PNG, JPG up to 2MB. Recommended: 200×200px
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field
                        label="Business Name"
                        error={errors.app_name?.message}
                        required
                    >
                        <FieldInput
                            className="input"
                            {...register("app_name")}
                            placeholder="Bethany House"
                        />
                    </Field>
                    <Field label="Tagline" error={errors.app_tagline?.message}>
                        <FieldInput
                            className="input"
                            {...register("app_tagline")}
                            placeholder="Quality fashion, made with love"
                        />
                    </Field>
                    <Field
                        label="Business Email"
                        error={errors.app_email?.message}
                        required
                    >
                        <FieldInput
                            className="input"
                            type="email"
                            {...register("app_email")}
                            placeholder="info@bethanyhouse.co.ke"
                        />
                    </Field>
                    <Field
                        label="Phone Number"
                        error={errors.app_phone?.message}
                    >
                        <FieldInput
                            className="input"
                            {...register("app_phone")}
                            placeholder="+254 700 000 000"
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <Field label="Address" className="sm:col-span-2">
                        <FieldInput
                            className="input"
                            {...register("app_address")}
                            placeholder="123 Moi Avenue"
                        />
                    </Field>
                    <Field label="City">
                        <FieldInput
                            className="input"
                            {...register("app_city")}
                            placeholder="Nairobi"
                        />
                    </Field>
                </div>
            </Section>

            {/* ── Regional Defaults ─────────────────────────────────────────────── */}
            <Section
                title="Regional Defaults"
                description="Default currency, language, and timezone used across POS, procurement, and production."
            >
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <Field label="Default Currency" required>
                        <FieldSelect
                            className="input"
                            {...register("default_currency")}
                        >
                            {activeCurrencies.length > 0 ? (
                                activeCurrencies.map((c) => (
                                    <option key={c.code} value={c.code}>
                                        {c.code} - {c.name}
                                    </option>
                                ))
                            ) : (
                                <>
                                    <option value="KES">
                                        KES - Kenyan Shilling
                                    </option>
                                    <option value="USD">USD - US Dollar</option>
                                </>
                            )}
                        </FieldSelect>
                    </Field>
                    <Field label="Default Language" required>
                        <FieldSelect
                            className="input"
                            {...register("default_language")}
                        >
                            <option value="en">English</option>
                            <option value="fr">Français</option>
                            <option value="pt">Português</option>
                        </FieldSelect>
                    </Field>
                    <Field label="Timezone" required>
                        <FieldSelect className="input" {...register("app_timezone")}>
                            {TIMEZONES.map((tz) => (
                                <option key={tz} value={tz}>
                                    {tz}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                </div>
            </Section>

            {/* ── Order & POS Settings ──────────────────────────────────────────── */}
            <Section
                title="Order & POS Settings"
                description="Controls order numbering, receipts, and sales behaviour."
            >
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field
                        label="Order Number Prefix"
                        hint="Prepended to all order numbers. E.g. 'BH-' → BH-00001"
                    >
                        <FieldInput
                            className="input"
                            {...register("order_prefix")}
                            placeholder="BH-"
                        />
                    </Field>
                    <Field
                        label="Low Stock Alert Threshold"
                        hint="Alert when stock falls at or below this quantity"
                        error={errors.low_stock_threshold?.message}
                    >
                        <FieldInput
                            className="input"
                            type="number"
                            min={0}
                            {...register("low_stock_threshold")}
                        />
                    </Field>
                </div>

                <Field
                    label="Receipt Footer Text"
                    hint="Printed at the bottom of POS receipts"
                >
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        {...register("receipt_footer")}
                        placeholder="Thank you for shopping at Bethany House! Returns accepted within 7 days with receipt."
                    />
                </Field>
            </Section>

            {/* ── Feature Flags ─────────────────────────────────────────────────── */}
            <Section
                title="Feature Flags"
                description="Enable or disable platform features."
            >
                <div className="space-y-4">
                    <Toggle
                        checked={watch("tax_inclusive")}
                        onChange={(v) => setValue("tax_inclusive", v)}
                        label="Tax-inclusive pricing"
                        description="Product prices include tax by default. The displayed price is the final price."
                    />
                    <Toggle
                        checked={watch("enable_guest_checkout")}
                        onChange={(v) => setValue("enable_guest_checkout", v)}
                        label="Allow guest checkout"
                        description="Customers can purchase without creating an account."
                    />
                    <Toggle
                        checked={watch("enable_reviews")}
                        onChange={(v) => setValue("enable_reviews", v)}
                        label="Enable product reviews"
                        description="Customers who have purchased can leave star ratings and reviews."
                    />
                    <div className="border-t border-surface-100 pt-4">
                        <Toggle
                            checked={watch("maintenance_mode")}
                            onChange={(v) => setValue("maintenance_mode", v)}
                            label="Maintenance mode"
                            description="Takes the storefront offline. Admin panel remains accessible."
                        />
                    </div>
                </div>
            </Section>

            {/* ── Save ──────────────────────────────────────────────────────────── */}
            <div className="flex justify-end">
                <button
                    type="submit"
                    disabled={saveMutation.isPending}
                    className="btn-primary"
                >
                    {saveMutation.isPending ? (
                        <Spinner
                            size="sm"
                            className="border-white/30 border-t-white"
                        />
                    ) : null}
                    Save Settings
                </button>
            </div>
        </form>
    );
}