/**
 * EodReportSettingsPage.tsx
 *
 * Configure scheduled delivery of consolidated EoD reports to the admin.
 * Supports two channels: email and Slack (via webhook URL).
 * The schedule is a time-of-day (e.g. 21:00) for a nightly summary of that
 * day's submitted cashier reports — sent by a Laravel scheduled command.
 *
 * Route: /pos/eod-settings
 * Permission: settings.edit
 */

import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";

// ── Types ─────────────────────────────────────────────────────────────────────

interface OutletOption {
    id: number;
    name: string;
}

type Frequency = "daily" | "weekly" | "off";

interface EodDeliverySettings {
    email_enabled:    boolean;
    email_recipients: string;       // comma-separated list
    email_frequency:  Frequency;
    email_time:       string;       // "HH:MM"
    slack_enabled:    boolean;
    slack_webhook:    string;
    slack_frequency:  Frequency;
    slack_time:       string;       // "HH:MM"
    outlet_ids:       number[];     // empty = all outlets
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const FREQ_LABELS: Record<Frequency, string> = {
    daily:  "Daily",
    weekly: "Weekly (Mon–Fri)",
    off:    "Disabled",
};

// ── Channel card ──────────────────────────────────────────────────────────────

function ChannelCard({
    icon,
    title,
    description,
    enabled,
    onToggle,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
    enabled: boolean;
    onToggle: () => void;
    children: React.ReactNode;
}) {
    return (
        <div className={clsx(
            "rounded-2xl border transition-all",
            enabled ? "border-brand-200 bg-brand-50/30" : "border-surface-100 bg-surface-50",
        )}>
            <div className="flex items-start gap-4 p-5">
                <div className={clsx(
                    "w-10 h-10 rounded-xl flex items-center justify-center shrink-0",
                    enabled ? "bg-brand-100 text-brand-600" : "bg-surface-100 text-surface-400",
                )}>
                    {icon}
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h3 className="font-semibold text-surface-900 text-sm">{title}</h3>
                            <p className="text-xs text-surface-400 mt-0.5">{description}</p>
                        </div>
                        {/* Toggle */}
                        <button
                            type="button"
                            onClick={onToggle}
                            className={clsx(
                                "relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400",
                                enabled ? "bg-brand-500" : "bg-surface-200",
                            )}
                            aria-pressed={enabled}
                        >
                            <span
                                className={clsx(
                                    "inline-block h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200 ease-in-out mt-0.5",
                                    enabled ? "translate-x-5" : "translate-x-0.5",
                                )}
                            />
                        </button>
                    </div>

                    {enabled && (
                        <div className="mt-4 space-y-3">
                            {children}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function EodReportSettingsPage() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { can } = usePermissions();
    const canEdit = can("settings.edit");

    const [form, setForm] = useState<EodDeliverySettings>({
        email_enabled:    false,
        email_recipients: "",
        email_frequency:  "daily",
        email_time:       "21:00",
        slack_enabled:    false,
        slack_webhook:    "",
        slack_frequency:  "daily",
        slack_time:       "21:00",
        outlet_ids:       [],
    });

    // ── Load existing settings ────────────────────────────────────────────────

    const { data, isLoading } = useQuery({
        queryKey: ["eod-delivery-settings"],
        queryFn: () => get<{ settings: EodDeliverySettings }>("/v1/admin/pos/reports/eod-settings"),
        staleTime: 60_000,
    });

    useEffect(() => {
        if (!data?.settings) return;
        const s = data.settings;
        setForm({
            email_enabled:    s.email_enabled    ?? false,
            email_recipients: s.email_recipients ?? "",
            email_frequency:  s.email_frequency  ?? "daily",
            email_time:       s.email_time       ?? "21:00",
            slack_enabled:    s.slack_enabled    ?? false,
            slack_webhook:    s.slack_webhook    ?? "",
            slack_frequency:  s.slack_frequency  ?? "daily",
            slack_time:       s.slack_time       ?? "21:00",
            outlet_ids:       s.outlet_ids       ?? [],
        });
    }, [data]);

    // ── Load outlets for scoping ──────────────────────────────────────────────

    const { data: outletsData } = useQuery({
        queryKey: ["pos-outlets-filter"],
        queryFn: () => get<{ data: OutletOption[] }>("/v1/admin/pos/outlets"),
        staleTime: 5 * 60_000,
    });
    const outlets = outletsData?.data ?? [];

    // ── Save ─────────────────────────────────────────────────────────────────

    const saveMutation = useMutation({
        mutationFn: () => post("/v1/admin/pos/reports/eod-settings", form),
        onSuccess: () => {
            toast.success("EoD delivery settings saved.");
            qc.invalidateQueries({ queryKey: ["eod-delivery-settings"] });
        },
        onError: (err: { message: string }) => {
            toast.error(err.message || "Failed to save settings.");
        },
    });

    // ── Test send ────────────────────────────────────────────────────────────

    const testMutation = useMutation({
        mutationFn: (payload: { channel: "email" | "slack"; email_recipients?: string; slack_webhook?: string }) =>
            post<{ message: string }>("/v1/admin/pos/reports/eod-settings/test", payload),
        onSuccess: (_, { channel }) => toast.success(`Test ${channel} sent!`),
        onError: (err: { message: string }) => toast.error(err.message || "Test send failed."),
    });

    const set = <K extends keyof EodDeliverySettings>(key: K, val: EodDeliverySettings[K]) =>
        setForm((prev) => ({ ...prev, [key]: val }));

    const toggleOutlet = (id: number) =>
        setForm((prev) => ({
            ...prev,
            outlet_ids: prev.outlet_ids.includes(id)
                ? prev.outlet_ids.filter((x) => x !== id)
                : [...prev.outlet_ids, id],
        }));

    if (isLoading) {
        return (
            <div className="flex items-center justify-center h-64">
                <Spinner size="lg" />
            </div>
        );
    }

    return (
        <div className="max-w-2xl mx-auto px-6 py-8 space-y-8">

            {/* Page header */}
            <div>
                <h1 className="text-xl font-bold text-surface-900">EoD Report Delivery</h1>
                <p className="text-xs text-surface-400 mt-1">
                    Configure automatic delivery of consolidated end-of-day cashier reports.
                    The report includes all submitted EoD summaries for the day across selected outlets.
                </p>
            </div>

            {/* Outlet scope */}
            {outlets.length > 0 && (
                <div>
                    <h2 className="text-sm font-semibold text-surface-700 mb-2">Outlet Scope</h2>
                    <p className="text-xs text-surface-400 mb-3">
                        Choose which outlets to include. Leave all unchecked to include every outlet.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {outlets.map((o) => {
                            const checked = form.outlet_ids.includes(o.id);
                            return (
                                <button
                                    key={o.id}
                                    type="button"
                                    onClick={() => toggleOutlet(o.id)}
                                    className={clsx(
                                        "px-3 py-1.5 rounded-lg border text-xs font-medium transition-all",
                                        checked
                                            ? "bg-brand-100 border-brand-300 text-brand-700"
                                            : "bg-surface-50 border-surface-200 text-surface-600 hover:border-surface-300",
                                    )}
                                >
                                    {checked ? "✓ " : ""}{o.name}
                                </button>
                            );
                        })}
                    </div>
                    {form.outlet_ids.length === 0 && (
                        <p className="text-2xs text-surface-400 mt-2 italic">All outlets included.</p>
                    )}
                </div>
            )}

            {/* Email channel */}
            <ChannelCard
                icon={
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                }
                title="Email"
                description="Send a formatted HTML report to one or more email addresses."
                enabled={form.email_enabled}
                onToggle={() => set("email_enabled", !form.email_enabled)}
            >
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">
                        Recipients <span className="text-surface-400 font-normal">(comma-separated)</span>
                    </label>
                    <input
                        type="text"
                        value={form.email_recipients}
                        onChange={(e) => set("email_recipients", e.target.value)}
                        placeholder="admin@bethanyhouse.co.ke, manager@bethanyhouse.co.ke"
                        className="input text-xs w-full"
                    />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">Frequency</label>
                        <select
                            value={form.email_frequency}
                            onChange={(e) => set("email_frequency", e.target.value as Frequency)}
                            className="input text-xs w-full"
                        >
                            {(Object.keys(FREQ_LABELS) as Frequency[]).map((f) => (
                                <option key={f} value={f}>{FREQ_LABELS[f]}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">Send time</label>
                        <input
                            type="time"
                            value={form.email_time}
                            onChange={(e) => set("email_time", e.target.value)}
                            className="input text-xs w-full"
                        />
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => testMutation.mutate({ channel: "email", email_recipients: form.email_recipients })}
                    disabled={testMutation.isPending || !form.email_recipients?.trim() || !canEdit}
                    className="btn-secondary btn-sm text-xs gap-1.5"
                >
                    {testMutation.isPending ? (
                        <div className="w-3.5 h-3.5 border-2 border-current border-t-transparent rounded-full animate-spin" />
                    ) : (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                    )}
                    Send test email
                </button>
            </ChannelCard>

            {/* Slack channel */}
            <ChannelCard
                icon={
                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M6 15a2 2 0 01-2 2 2 2 0 01-2-2 2 2 0 012-2h2v2zM7 15a2 2 0 012-2 2 2 0 012 2v5a2 2 0 01-2 2 2 2 0 01-2-2v-5zM9 7a2 2 0 012-2 2 2 0 012 2 2 2 0 01-2 2H9V7zM9 9a2 2 0 01-2 2H2a2 2 0 01-2-2 2 2 0 012-2h5a2 2 0 012 2zM17 9a2 2 0 012-2 2 2 0 012 2 2 2 0 01-2 2h-2V9zM15 9a2 2 0 01-2 2 2 2 0 01-2-2V4a2 2 0 012-2 2 2 0 012 2v5zM15 17a2 2 0 012-2h5a2 2 0 012 2 2 2 0 01-2 2h-5a2 2 0 01-2-2zM15 15a2 2 0 01-2-2 2 2 0 012-2 2 2 0 012 2v2h-2z" />
                    </svg>
                }
                title="Slack"
                description="Post a summary to a Slack channel using an Incoming Webhook."
                enabled={form.slack_enabled}
                onToggle={() => set("slack_enabled", !form.slack_enabled)}
            >
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">
                        Incoming Webhook URL
                    </label>
                    <input
                        type="url"
                        value={form.slack_webhook}
                        onChange={(e) => set("slack_webhook", e.target.value)}
                        placeholder="https://hooks.slack.com/services/..."
                        className="input text-xs w-full font-mono"
                    />
                    <p className="text-2xs text-surface-400 mt-1">
                        Create one at <span className="font-medium">api.slack.com/apps → Incoming Webhooks</span>.
                    </p>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">Frequency</label>
                        <select
                            value={form.slack_frequency}
                            onChange={(e) => set("slack_frequency", e.target.value as Frequency)}
                            className="input text-xs w-full"
                        >
                            {(Object.keys(FREQ_LABELS) as Frequency[]).map((f) => (
                                <option key={f} value={f}>{FREQ_LABELS[f]}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">Send time</label>
                        <input
                            type="time"
                            value={form.slack_time}
                            onChange={(e) => set("slack_time", e.target.value)}
                            className="input text-xs w-full"
                        />
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => testMutation.mutate({ channel: "slack", slack_webhook: form.slack_webhook })}
                    disabled={testMutation.isPending || !form.slack_webhook?.trim() || !canEdit}
                    className="btn-secondary btn-sm text-xs gap-1.5"
                >
                    {testMutation.isPending ? (
                        <div className="w-3.5 h-3.5 border-2 border-current border-t-transparent rounded-full animate-spin" />
                    ) : (
                        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M6 15a2 2 0 01-2 2 2 2 0 01-2-2 2 2 0 012-2h2v2zM7 15a2 2 0 012-2 2 2 0 012 2v5a2 2 0 01-2 2 2 2 0 01-2-2v-5z" />
                        </svg>
                    )}
                    Send test message
                </button>
            </ChannelCard>

            {/* Save footer */}
            <div className="flex justify-end pt-2">
                <button
                    type="button"
                    onClick={() => saveMutation.mutate()}
                    disabled={saveMutation.isPending || !canEdit}
                    className="btn-primary gap-2"
                >
                    {saveMutation.isPending && (
                        <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    )}
                    Save Settings
                </button>
            </div>
        </div>
    );
}