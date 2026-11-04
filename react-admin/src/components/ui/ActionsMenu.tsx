import { useEffect, useRef, useState } from "react";
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
export function ActionsMenu({ items, label }: { items: ActionItem[]; label: string }) {
    const [at, setAt] = useState<{ top: number; right: number } | null>(null);
    const btn = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!at) return;
        const off = (e: MouseEvent) => { if (!menu.current?.contains(e.target as Node) && !btn.current?.contains(e.target as Node)) setAt(null); };
        const esc = (e: KeyboardEvent) => { if (e.key === "Escape") { setAt(null); btn.current?.focus(); } };
        const close = () => setAt(null);
        document.addEventListener("mousedown", off);
        document.addEventListener("keydown", esc);
        window.addEventListener("scroll", close, true);
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
        setAt({ top: r.bottom + 6, right: Math.max(8, window.innerWidth - r.right) });
    };

    return (
        <>
            <button ref={btn} type="button" onClick={toggle} aria-haspopup="menu" aria-expanded={!!at} aria-label={label}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-surface-500 hover:bg-surface-100 hover:text-surface-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                <Icon name="dots" className="w-5 h-5" strokeWidth={2.4} />
            </button>
            {at && (
                <div ref={menu} role="menu" style={{ position: "fixed", top: at.top, right: at.right }}
                    className="z-50 w-60 card-raised py-1.5 animate-slide-down"
                    onKeyDown={e => {
                        if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
                        e.preventDefault();
                        const all = [...(menu.current?.querySelectorAll<HTMLElement>("[role=menuitem]") ?? [])];
                        const i = all.indexOf(document.activeElement as HTMLElement);
                        all[(i + (e.key === "ArrowDown" ? 1 : all.length - 1)) % all.length]?.focus();
                    }}>
                    {items.map(it => (
                        <button key={it.label} role="menuitem" type="button" onClick={() => { setAt(null); it.run(); }}
                            className={clsx("dropdown-item", it.tone === "danger" && "text-danger")}>
                            <Icon name={it.icon} className="w-4 h-4" />{it.label}
                        </button>
                    ))}
                </div>
            )}
        </>
    );
}
