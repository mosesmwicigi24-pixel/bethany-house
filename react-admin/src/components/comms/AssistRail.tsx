import { useEffect, useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { channelApi, type AssistAction } from "@/api/channels";
import type { ApiError } from "@/types";

/**
 * The Assist sheet (WhatsApp Business direction, owner 23 Oct 2026).
 *
 * Opened from the ✦ inside the composer pill. On a phone it is a bottom sheet
 * with a grab handle; on a desk it is a card that floats above the composer
 * (the parent is position:relative). Four actions, one tap each; the answer
 * stays in the sheet with Insert and Copy until you dismiss it, so you can
 * read it against the thread. The answers are in English or Kiswahili.
 *
 * It used to be a chip row that lived permanently above the composer; that
 * row was the first thing the keyboard pushed off a phone screen.
 */
export function AssistSheet({ channelId, draft, hasOrder, onInsert, open, onClose, variant = "sheet" }: {
    channelId: number;
    /** The text in the composer right now; "Translate" works on it. A getter
     *  when the sheet lives outside the composer (the desktop info panel). */
    draft: string | (() => string);
    /** An order thread can draft the customer's message. */
    hasOrder: boolean;
    onInsert: (text: string) => void;
    open: boolean;
    onClose: () => void;
    /** "sheet": a phone bottom sheet / a desk card above the composer.
     *  "panel": inline inside the desktop info panel, always open. */
    variant?: "sheet" | "panel";
}) {
    const readDraft = () => (typeof draft === "function" ? draft() : draft);
    const [result, setResult] = useState<null | { title: string; text: string; options?: string[] }>(null);
    const [language, setLanguage] = useState<"en" | "sw">("en");
    const [error, setError] = useState<string | null>(null);

    const run = useMutation({
        mutationFn: (p: { action: AssistAction; title: string; audience?: "customer" | "team" }) =>
            channelApi.assist(channelId, {
                action: p.action,
                language,
                ...(p.action === "translate" ? { text: readDraft() } : {}),
                ...(p.audience ? { audience: p.audience } : {}),
            }).then(r => ({ ...r, title: p.title })),
        onSuccess: (r) => { setError(null); setResult({ title: r.title, text: r.text, options: r.options }); },
        onError: (e: ApiError) => setError(e.message || "Assist could not answer."),
    });

    // Escape closes, as every sheet in the console does.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("keydown", onKey);
        return () => document.removeEventListener("keydown", onKey);
    }, [open, onClose]);

    if (!open) return null;
    const panel = variant === "panel";
    const draftNow = typeof draft === "function" ? "" : draft;

    const card = (label: string, hint: string, action: AssistAction, title: string, extra?: { audience?: "customer" | "team"; disabled?: boolean }) => (
        <button
            type="button"
            disabled={run.isPending || extra?.disabled}
            onClick={() => run.mutate({ action, title, audience: extra?.audience })}
            className="text-left rounded-xl bg-surface-50 hover:bg-brand-50 disabled:opacity-40 disabled:hover:bg-surface-50 px-3 py-2.5 transition-colors"
        >
            <span className="block text-sm font-bold text-surface-900">{label}</span>
            <span className="block text-2xs text-surface-500 leading-tight mt-0.5">{hint}</span>
        </button>
    );

    const copy = async (text: string) => {
        try { await navigator.clipboard.writeText(text); } catch { /* the sheet still shows the text to select */ }
    };

    return (
        <>
            {/* Phone backdrop; on a desk the card floats and a click elsewhere is harmless. */}
            {!panel && <div className="fixed inset-0 z-40 bg-black/30 sm:hidden" onClick={onClose} aria-hidden="true" />}
            <div
                role={panel ? "region" : "dialog"} aria-modal={panel ? undefined : true} aria-label="Assist" aria-busy={run.isPending}
                className={clsx(
                    "text-sm",
                    panel
                        ? "bg-transparent"
                        : "z-50 bg-white fixed inset-x-0 bottom-0 rounded-t-[20px] shadow-[0_-8px_30px_rgba(0,0,0,0.2)] px-4 pt-2 sm:absolute sm:inset-x-0 sm:bottom-full sm:mb-2 sm:rounded-2xl sm:border sm:border-surface-200 sm:shadow-xl sm:pt-3",
                )}
                style={panel ? undefined : { paddingBottom: "max(16px, env(safe-area-inset-bottom, 0px))" }}
            >
                {!panel && <div className="mx-auto w-9 h-1 rounded-full bg-surface-200 mb-2.5 sm:hidden" aria-hidden="true" />}
                <div className="flex items-center gap-2 mb-2.5">
                    {!panel && <span className="text-[15px] font-bold text-surface-900">✦ Assist</span>}
                    <button type="button" onClick={() => setLanguage(l => (l === "en" ? "sw" : "en"))}
                        className="ml-auto px-2.5 py-1 rounded-full text-2xs font-bold text-surface-600 border border-surface-200 hover:bg-surface-50"
                        title="Answers in English or Kiswahili" aria-label={language === "en" ? "Answers in English. Switch to Kiswahili" : "Answers in Kiswahili. Switch to English"}>
                        {language === "en" ? "EN · sw" : "en · SW"}
                    </button>
                    {!panel && (
                        <button type="button" onClick={onClose} aria-label="Close Assist"
                            className="w-8 h-8 rounded-full text-surface-400 hover:bg-surface-100 text-sm">✕</button>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-2">
                    {card("Summarise", "What was agreed, in five lines", "summarise", "Summary of this thread")}
                    {card(language === "sw" ? "Translate to Kiswahili" : "Translate to English", panel ? "What you have typed" : draftNow.trim() ? "What you have typed" : "Type the message first", "translate", "Translation", { disabled: !panel && !draftNow.trim() })}
                    {hasOrder && card("Draft to customer", "From the order, polite and short", "draft", "Message to the customer", { audience: "customer" })}
                    {card("Suggest replies", "Three things you could say next", "replies", "Replies to pick from")}
                </div>
                {run.isPending && <p className="text-2xs text-surface-400 mt-2" aria-live="polite">Thinking…</p>}
                {error && <p className="text-2xs text-danger mt-2" role="alert">{error}</p>}

                {result && (
                    <div role="region" aria-live="polite" aria-label={result.title}
                        className="mt-3 rounded-xl bg-brand-50/70 border border-brand-100 p-3">
                        <div className="flex items-center justify-between gap-2 mb-1">
                            <span className="text-2xs font-bold uppercase tracking-wider text-brand-600">{result.title}</span>
                            <button type="button" onClick={() => setResult(null)} aria-label="Clear answer" className="w-6 h-6 rounded-full text-surface-400 hover:bg-white text-xs">✕</button>
                        </div>
                        {result.options && result.options.length > 0 ? (
                            <div className="flex flex-col gap-1.5">
                                {result.options.map((o, i) => (
                                    <button key={i} type="button" onClick={() => { onInsert(o); if (!panel) onClose(); }}
                                        className="text-left px-3 py-2 rounded-xl bg-white border border-surface-200 hover:border-brand-300 text-surface-800">
                                        {o}
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <>
                                <p className="whitespace-pre-wrap text-surface-800 leading-relaxed select-text max-h-48 overflow-y-auto">{result.text}</p>
                                <div className="flex gap-2 mt-2.5">
                                    <button type="button" onClick={() => { onInsert(result.text); if (!panel) onClose(); }}
                                        className="flex-1 sm:flex-none px-4 py-2 rounded-full text-xs font-bold bg-brand-500 text-white hover:bg-brand-600">
                                        Insert
                                    </button>
                                    <button type="button" onClick={() => copy(result.text)}
                                        className="flex-1 sm:flex-none px-4 py-2 rounded-full text-xs font-semibold bg-white border border-surface-200 text-surface-700 hover:bg-surface-50">
                                        Copy
                                    </button>
                                </div>
                            </>
                        )}
                        <p className="text-2xs text-surface-400 mt-2">Written by Assist from this thread. Read it before you send it.</p>
                    </div>
                )}
            </div>
        </>
    );
}
