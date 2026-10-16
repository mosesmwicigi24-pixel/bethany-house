import { useRef } from "react";
import { clsx } from "clsx";
import { FieldInput } from "@/components/setup/FormComponents";

let measurer: CanvasRenderingContext2D | null = null;

function textWidth(text: string, el: HTMLInputElement): number {
    measurer ??= document.createElement("canvas").getContext("2d");
    if (!measurer) return Number.POSITIVE_INFINITY;
    measurer.font = getComputedStyle(el).font;
    return measurer.measureText(text).width;
}

/**
 * A field holding a learned suggestion as grey text (owner, 2026-10-16):
 * typing replaces it; tapping just past its end — or the "Use" button, or →
 * on a keyboard — makes it real text. With acceptOnEndTap off (the amount),
 * only the button or → accepts it: money is never filled in by a stray tap.
 */
export function GhostInput({
    value,
    onChange,
    suggestion,
    ghostText,
    placeholder,
    acceptOnEndTap = true,
    className,
    ...rest
}: Omit<React.InputHTMLAttributes<HTMLInputElement>, "value" | "onChange"> & {
    value: string | number;
    onChange: (value: string) => void;
    /** The value accepted; null for none. */
    suggestion: string | null | undefined;
    /** What is shown, when it differs from the value (e.g. "1,900 · last time"). */
    ghostText?: string;
    acceptOnEndTap?: boolean;
}) {
    const ref = useRef<HTMLInputElement>(null);
    const showGhost = !!suggestion && String(value ?? "") === "";
    const shown = ghostText ?? suggestion ?? "";
    const accept = () => {
        if (!suggestion) return;
        onChange(suggestion);
        requestAnimationFrame(() => {
            const el = ref.current;
            if (el && el.type !== "number") el.setSelectionRange(suggestion.length, suggestion.length);
        });
    };

    return (
        <div className="relative">
            <FieldInput
                ref={ref}
                {...rest}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={showGhost ? shown : placeholder}
                className={clsx(className, showGhost && "pr-16 placeholder:text-surface-500 placeholder:italic")}
                onClick={(e) => {
                    rest.onClick?.(e);
                    if (!showGhost || !acceptOnEndTap) return;
                    const el = e.currentTarget;
                    const left = el.getBoundingClientRect().left + parseFloat(getComputedStyle(el).paddingLeft || "0");
                    // On the grey text: type over it. Just past its end: keep it.
                    if (e.clientX - left >= textWidth(shown, el) - 4) accept();
                }}
                onKeyDown={(e) => {
                    rest.onKeyDown?.(e);
                    if (showGhost && e.key === "ArrowRight") {
                        e.preventDefault();
                        accept();
                    }
                }}
            />
            {showGhost && (
                <button
                    type="button"
                    onClick={accept}
                    className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded bg-brand-50 px-2 py-1 text-2xs font-medium text-brand-700 hover:bg-brand-100"
                    aria-label={`Use the suggestion: ${shown}`}
                >
                    Use ↵
                </button>
            )}
        </div>
    );
}

/** Under a dropdown: "Usual: M-PESA · Use". */
export function SuggestedPick({ label, onUse }: { label: string | null | undefined; onUse: () => void }) {
    if (!label) return null;
    return (
        <p className="mt-1 text-2xs text-surface-500">
            Usual: <span className="font-medium text-brand-700">{label}</span>{" "}
            <button type="button" onClick={onUse} className="ml-1 rounded bg-brand-50 px-1.5 py-0.5 font-medium text-brand-700 hover:bg-brand-100">
                Use
            </button>
        </p>
    );
}
