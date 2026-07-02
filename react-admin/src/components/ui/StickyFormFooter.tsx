/**
 * StickyFormFooter.tsx
 *
 * A fixed bottom bar that slides up when a form has unsaved changes.
 * Eliminates the need to scroll to the bottom of long forms to save.
 *
 * Usage (with react-hook-form):
 *
 *   const form = useForm({ ... });
 *   const isDirty = form.formState.isDirty;
 *
 *   <StickyFormFooter
 *     isDirty={isDirty}
 *     isSubmitting={form.formState.isSubmitting}
 *     onSubmit={form.handleSubmit(onSave)}
 *     onDiscard={() => { form.reset(); navigate(-1); }}
 *   />
 *
 * The footer:
 *  - Is invisible when form is clean (no layout disruption)
 *  - Slides up with animation when isDirty becomes true
 *  - Shows a spinner on the save button while submitting
 *  - Warns before discard if form is dirty
 */

import { useEffect, useState } from "react";
import { clsx } from "clsx";

interface StickyFormFooterProps {
    /** Whether the form has unsaved changes */
    isDirty: boolean;
    /** Whether a save mutation is in flight */
    isSubmitting?: boolean;
    /** Called when user clicks Save */
    onSubmit: () => void;
    /** Called when user clicks Discard - typically reset() + navigate(-1) */
    onDiscard: () => void;
    /** Custom label for the save button. Default: "Save changes" */
    saveLabel?: string;
    /** Custom label for the discard button. Default: "Discard" */
    discardLabel?: string;
    /** If true, shows a warning dialog before discarding */
    confirmDiscard?: boolean;
}

export function StickyFormFooter({
    isDirty,
    isSubmitting = false,
    onSubmit,
    onDiscard,
    saveLabel = "Save changes",
    discardLabel = "Discard",
    confirmDiscard = true,
}: StickyFormFooterProps) {
    const [visible, setVisible] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    // Delay hiding to allow slide-out animation
    useEffect(() => {
        if (isDirty) {
            setVisible(true);
        } else {
            const t = setTimeout(() => setVisible(false), 300);
            return () => clearTimeout(t);
        }
    }, [isDirty]);

    if (!visible) return null;

    const handleDiscard = () => {
        if (confirmDiscard && isDirty) {
            setConfirmOpen(true);
        } else {
            onDiscard();
        }
    };

    return (
        <>
            {/* Spacer to prevent content being hidden behind the sticky bar */}
            <div className="h-20" aria-hidden />

            {/* Sticky bar */}
            <div
                className={clsx(
                    "fixed bottom-0 left-0 right-0 z-40",
                    "transition-transform duration-300 ease-out",
                    isDirty ? "translate-y-0" : "translate-y-full",
                )}
            >
                {/* Gradient fade above bar */}
                <div className="h-6 bg-gradient-to-t from-white/80 to-transparent pointer-events-none" />

                {/* Bar */}
                <div className="bg-white border-t border-surface-200 shadow-[0_-4px_24px_rgba(0,0,0,0.08)]">
                    <div className="max-w-screen-xl mx-auto px-6 h-16 flex items-center justify-between gap-4">
                        {/* Left: unsaved indicator */}
                        <div className="flex items-center gap-2 text-sm text-surface-500">
                            <span className="w-2 h-2 rounded-full bg-warning animate-pulse shrink-0" />
                            <span className="hidden sm:inline">You have unsaved changes</span>
                            <span className="sm:hidden">Unsaved changes</span>
                        </div>

                        {/* Right: actions */}
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={handleDiscard}
                                disabled={isSubmitting}
                                className="btn btn-secondary btn-sm"
                            >
                                {discardLabel}
                            </button>
                            <button
                                type="button"
                                onClick={onSubmit}
                                disabled={isSubmitting}
                                className="btn btn-primary btn-sm min-w-[110px]"
                            >
                                {isSubmitting ? (
                                    <>
                                        <svg
                                            className="w-3.5 h-3.5 animate-spin"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <circle
                                                className="opacity-25"
                                                cx="12" cy="12" r="10"
                                                stroke="currentColor" strokeWidth="4"
                                            />
                                            <path
                                                className="opacity-75"
                                                fill="currentColor"
                                                d="M4 12a8 8 0 018-8v8H4z"
                                            />
                                        </svg>
                                        Saving…
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                                        </svg>
                                        {saveLabel}
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* Discard confirmation dialog */}
            {confirmOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div
                        className="absolute inset-0 bg-black/40 backdrop-blur-sm"
                        onClick={() => setConfirmOpen(false)}
                    />
                    <div className="relative bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm animate-scale-in">
                        <div className="w-12 h-12 rounded-full bg-warning-light flex items-center justify-center mx-auto mb-4">
                            <svg className="w-6 h-6 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                        <h3 className="text-base font-semibold text-surface-900 text-center">Discard changes?</h3>
                        <p className="mt-2 text-sm text-surface-500 text-center leading-relaxed">
                            Any unsaved changes will be permanently lost.
                        </p>
                        <div className="mt-5 flex gap-3">
                            <button
                                onClick={() => setConfirmOpen(false)}
                                className="btn btn-secondary flex-1"
                            >
                                Keep editing
                            </button>
                            <button
                                onClick={() => { setConfirmOpen(false); onDiscard(); }}
                                className="btn btn-danger flex-1"
                            >
                                Discard
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// ─── Page title dirty indicator ───────────────────────────────────────────────
// Small dot shown next to page title when form has unsaved changes.
// Usage: <DirtyIndicator isDirty={isDirty} />

export function DirtyIndicator({ isDirty }: { isDirty: boolean }) {
    if (!isDirty) return null;
    return (
        <span
            title="Unsaved changes"
            className="inline-block w-2 h-2 rounded-full bg-warning ml-2 align-middle"
        />
    );
}