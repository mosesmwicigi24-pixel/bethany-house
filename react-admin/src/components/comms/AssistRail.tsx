import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { channelApi, type AssistAction } from "@/api/channels";
import type { ApiError } from "@/types";

/**
 * The Assist rail above the composer (Concierge direction, owner 22 Oct 2026).
 * Four chips; each one tap; the answer lands in a sheet with Insert and Copy.
 * It appears only when the composer is empty or holds a draft to translate,
 * and never takes the keyboard's room: the rail scrolls sideways in one row.
 */
export function AssistRail({ channelId, draft, hasOrder, onInsert }: {
    channelId: number;
    /** The text in the composer right now; "Translate" works on it. */
    draft: string;
    /** An order thread can draft the customer's message. */
    hasOrder: boolean;
    onInsert: (text: string) => void;
}) {
    const [open, setOpen] = useState<null | { title: string; text: string; options?: string[] }>(null);
    const [hidden, setHidden] = useState(false);
    const [language, setLanguage] = useState<"en" | "sw">("en");
    const [error, setError] = useState<string | null>(null);

    const run = useMutation({
        mutationFn: (p: { action: AssistAction; title: string; audience?: "customer" | "team" }) =>
            channelApi.assist(channelId, {
                action: p.action,
                language,
                ...(p.action === "translate" ? { text: draft } : {}),
                ...(p.audience ? { audience: p.audience } : {}),
            }).then(r => ({ ...r, title: p.title })),
        onSuccess: (r) => { setError(null); setOpen({ title: r.title, text: r.text, options: r.options }); },
        onError: (e: ApiError) => setError(e.message || "Assist could not answer."),
    });

    if (hidden) return null;

    const chip = (label: string, action: AssistAction, title: string, extra?: { audience?: "customer" | "team"; disabled?: boolean; hint?: string }) => (
        <button
            type="button"
            disabled={run.isPending || extra?.disabled}
            title={extra?.hint}
            onClick={() => run.mutate({ action, title, audience: extra?.audience })}
            className="shrink-0 inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-brand-50 text-brand-700 hover:bg-brand-100 disabled:opacity-40 transition-colors whitespace-nowrap"
        >
            {label}
        </button>
    );

    const copy = async (text: string) => {
        try { await navigator.clipboard.writeText(text); } catch { /* the sheet still shows the text to select */ }
    };

    return (
        <>
            <div className="flex items-center gap-1.5 overflow-x-auto scroll-touch pb-1.5 -mx-0.5 px-0.5" role="toolbar" aria-label="Assist" aria-busy={run.isPending}>
                <span className="shrink-0 text-2xs font-bold uppercase tracking-wider text-brand-500 pr-0.5" aria-hidden>✦</span>
                {chip("Summarise", "summarise", "Summary of this thread")}
                {chip(language === "sw" ? "Translate to Kiswahili" : "Translate to English", "translate", "Translation", { disabled: !draft.trim(), hint: draft.trim() ? undefined : "Type the message first" })}
                {hasOrder && chip("Draft to customer", "draft", "Message to the customer", { audience: "customer" })}
                {chip("Suggest replies", "replies", "Replies to pick from")}
                <button type="button" onClick={() => setLanguage(l => (l === "en" ? "sw" : "en"))}
                    className="shrink-0 px-2 py-1.5 rounded-full text-2xs font-bold text-surface-500 hover:bg-surface-100"
                    title="Answers in English or Kiswahili">
                    {language === "en" ? "EN" : "SW"}
                </button>
                <button type="button" onClick={() => setHidden(true)} aria-label="Hide Assist"
                    className="shrink-0 ml-auto w-6 h-6 rounded-full text-surface-400 hover:bg-surface-100 text-xs">✕</button>
                {run.isPending && <span className="shrink-0 text-2xs text-surface-400">Thinking…</span>}
            </div>
            {error && <p className="text-2xs text-danger px-1 pb-1.5">{error}</p>}

            {open && (
                <div role="region" aria-live="polite" aria-label={open.title}
                    onKeyDown={(e) => { if (e.key === "Escape") setOpen(null); }}
                    className="mb-2 rounded-2xl border border-brand-100 bg-brand-50/60 p-3 text-sm">
                    <div className="flex items-center justify-between gap-2 mb-1.5">
                        <span className="text-2xs font-bold uppercase tracking-wider text-brand-600">✦ {open.title}</span>
                        <button type="button" onClick={() => setOpen(null)} aria-label="Close" className="w-6 h-6 rounded-full text-surface-400 hover:bg-white text-xs">✕</button>
                    </div>
                    {open.options && open.options.length > 0 ? (
                        <div className="flex flex-col gap-1.5">
                            {open.options.map((o, i) => (
                                <button key={i} type="button" onClick={() => { onInsert(o); setOpen(null); }}
                                    className="text-left px-3 py-2 rounded-xl bg-white border border-surface-200 hover:border-brand-300 text-surface-800">
                                    {o}
                                </button>
                            ))}
                        </div>
                    ) : (
                        <>
                            <p className="whitespace-pre-wrap text-surface-800 leading-relaxed select-text">{open.text}</p>
                            <div className="flex gap-2 mt-2">
                                <button type="button" onClick={() => { onInsert(open.text); setOpen(null); }}
                                    className={clsx("px-3 py-1.5 rounded-full text-xs font-bold bg-brand-500 text-white hover:bg-brand-600")}>
                                    Insert into message
                                </button>
                                <button type="button" onClick={() => copy(open.text)}
                                    className="px-3 py-1.5 rounded-full text-xs font-semibold bg-white border border-surface-200 text-surface-700 hover:bg-surface-50">
                                    Copy
                                </button>
                            </div>
                        </>
                    )}
                    <p className="text-2xs text-surface-400 mt-2">Written by Assist from this thread. Read it before you send it.</p>
                </div>
            )}
        </>
    );
}
