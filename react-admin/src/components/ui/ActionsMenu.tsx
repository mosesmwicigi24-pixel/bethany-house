import { useEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from "react";
import { clsx } from "clsx";
import { Icon, type IconName } from "@/components/people/fileKit";

export interface ActionItem {
    label: string;
    icon: IconName;
    run: () => void;
    tone?: "danger";
}

/**
 * A row's ⋯ menu (owner, 2026-11-03: "three dots… to make the UI neat and
 * clean"). Every action on a row lives here, so tables stay a clean read.
 * Positioned fixed so a table's horizontal scroll never clips it; arrow keys
 * move between items and Escape closes it.
 */
/** `trigger="gear"` shows a settings gear instead of ⋯ (owner, 2026-11-15: a
 *  setup row's settings behind one gear on a phone).
 *
 *  On a phone the menu opens as a bottom sheet (owner, 2026-11-15: the
 *  dropdown sat over the row "like a squeezed column"): full width, large
 *  tap targets, the row named at the top, and Cancel. `title` names the row
 *  in the sheet; it falls back to `label`. */
export function ActionsMenu({ items, label, title, trigger = "dots" }: { items: ActionItem[]; label: string; title?: string; trigger?: "dots" | "gear" }) {
    const [at, setAt] = useState<{ top: number; right: number; sheet: boolean } | null>(null);
    const btn = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!at) return;
        const off = (e: MouseEvent) => { if (!menu.current?.contains(e.target as Node) && !btn.current?.contains(e.target as Node)) setAt(null); };
        const esc = (e: KeyboardEvent) => { if (e.key === "Escape") { setAt(null); btn.current?.focus(); } };
        const close = () => setAt(null);
        document.addEventListener("mousedown", off);
        document.addEventListener("keydown", esc);
        // A sheet covers the page, so a scroll behind it cannot move its anchor.
        if (!at.sheet) window.addEventListener("scroll", close, true);
        window.addEventListener("resize", close);
        menu.current?.querySelector<HTMLElement>("[role=menuitem]")?.focus();
        return () => {
            document.removeEventListener("mousedown", off); document.removeEventListener("keydown", esc);
            window.removeEventListener("scroll", close, true); window.removeEventListener("resize", close);
        };
    }, [at]);

    if (items.length === 0) return null;
    const toggle = () => {
        if (at) { setAt(null); return; }
        const r = btn.current!.getBoundingClientRect();
        const sheet = window.matchMedia?.("(max-width: 639px)").matches ?? false;
        setAt({ top: r.bottom + 6, right: Math.max(8, window.innerWidth - r.right), sheet });
    };

    const arrows = (e: ReactKeyboardEvent) => {
        if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
        e.preventDefault();
        const all = [...(menu.current?.querySelectorAll<HTMLElement>("[role=menuitem]") ?? [])];
        const i = all.indexOf(document.activeElement as HTMLElement);
        all[(i + (e.key === "ArrowDown" ? 1 : all.length - 1)) % all.length]?.focus();
    };

    return (
        <>
            <button ref={btn} type="button" onClick={toggle} aria-haspopup="menu" aria-expanded={!!at} aria-label={label}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-surface-500 hover:bg-surface-100 hover:text-surface-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                <Icon name={trigger === "gear" ? "gear" : "dots"} className="w-5 h-5" strokeWidth={trigger === "gear" ? 1.8 : 2.4} />
            </button>
            {at && !at.sheet && (
                <div ref={menu} role="menu" style={{ position: "fixed", top: at.top, right: at.right }}
                    className="z-50 w-60 card-raised py-1.5 animate-slide-down" onKeyDown={arrows}>
                    {items.map(it => (
                        <button key={it.label} role="menuitem" type="button" onClick={() => { setAt(null); it.run(); }}
                            className={clsx("dropdown-item", it.tone === "danger" && "text-danger")}>
                            <Icon name={it.icon} className="w-4 h-4" />{it.label}
                        </button>
                    ))}
                </div>
            )}
            {at?.sheet && (
                <div className="fixed inset-0 z-50 flex items-end bg-black/40 animate-fade-in" onClick={() => setAt(null)}>
                    <div ref={menu} role="menu" aria-label={title ?? label} onClick={e => e.stopPropagation()} onKeyDown={arrows}
                        className="w-full rounded-t-2xl bg-white px-3 pt-2 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shadow-[0_-8px_30px_rgba(0,0,0,0.2)] animate-slide-up">
                        <div className="mx-auto mb-2 h-1 w-10 rounded-full bg-surface-200" aria-hidden />
                        <p className="px-3 pb-2 text-sm font-semibold text-surface-900 truncate">{title ?? label}</p>
                        <div className="divide-y divide-line overflow-hidden rounded-xl border border-line">
                            {items.map(it => (
                                <button key={it.label} role="menuitem" type="button" onClick={() => { setAt(null); it.run(); }}
                                    className={clsx("flex w-full items-center gap-3 px-4 py-3.5 text-left text-[15px] font-medium active:bg-surface-100 focus-visible:bg-surface-100 focus-visible:outline-none",
                                        it.tone === "danger" ? "text-danger" : "text-surface-800")}>
                                    <Icon name={it.icon} className="w-5 h-5 shrink-0" />{it.label}
                                </button>
                            ))}
                        </div>
                        <button type="button" onClick={() => { setAt(null); btn.current?.focus(); }}
                            className="mt-2 w-full rounded-xl bg-surface-100 py-3.5 text-[15px] font-semibold text-surface-700 active:bg-surface-200">
                            Cancel
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
