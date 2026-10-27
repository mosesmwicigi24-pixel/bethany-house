import { useEffect, useRef, useState, type ReactNode } from "react";
import { clsx } from "clsx";
import { useToastStore } from "@/store/toast.store";

/**
 * Shared pieces of the employee file (owner, 27 Oct 2026: "improve the UI for
 * this page"): the card head with its icon, a label/value field that offers
 * "Not set — Add" instead of a dash, copy buttons, chips and the date words.
 * Built on the hub's own tokens (card, badge, btn, surface/brand ramps).
 */

// ─── Icons (24px stroke, currentColor) ────────────────────────────────────────

const PATHS = {
    user: "M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.1a7.5 7.5 0 0115 0A18 18 0 0112 21.75a18 18 0 01-7.5-1.65z",
    briefcase: "M20.25 14.15v4.25c0 1.1-.8 2.03-1.88 2.18-2.08.28-4.2.42-6.37.42s-4.29-.14-6.37-.42a2.2 2.2 0 01-1.88-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.66V9.07c0-1.07-.77-2-1.83-2.16a48 48 0 00-3.42-.4M20.25 14.15a2.18 2.18 0 01-1.75.77H5.5c-.68 0-1.3-.3-1.75-.77m0 0A2.18 2.18 0 013 12.49V9.07c0-1.07.77-2 1.83-2.16a48 48 0 013.42-.4m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v1.26m7.5 0a48.7 48.7 0 00-7.5 0",
    heart: "M21 8.25c0-2.49-2.1-4.5-4.69-4.5-1.93 0-3.6 1.13-4.31 2.73-.72-1.6-2.38-2.73-4.31-2.73C5.1 3.75 3 5.76 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z",
    lock: "M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z",
    calendar: "M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5",
    file: "M19.5 14.25v-2.63a3.38 3.38 0 00-3.38-3.37h-1.5A1.13 1.13 0 0113.5 7.13v-1.5a3.38 3.38 0 00-3.38-3.38H8.25m2.25 0H5.63c-.62 0-1.13.5-1.13 1.13v17.25c0 .62.5 1.12 1.13 1.12h12.75c.62 0 1.12-.5 1.12-1.12V11.25a9 9 0 00-9-9z",
    cap: "M4.26 10.15a60.4 60.4 0 00-.49 6.35A48.6 48.6 0 0112 20.9a48.6 48.6 0 018.23-4.41 60.5 60.5 0 00-.49-6.35m-15.48 0A50.6 50.6 0 002.6 9.6 59.9 59.9 0 0112 3.5a59.9 59.9 0 019.4 6.1c-.55.18-1.1.36-1.66.56m-15.48 0a50.7 50.7 0 017.74 3.43 50.7 50.7 0 017.74-3.43M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.68A55.4 55.4 0 0112 8.44m-7.01 11.31A5.97 5.97 0 006.75 15.5v-1.5",
    pen: "M16.86 4.49l1.69-1.69a1.88 1.88 0 112.65 2.65L10.58 16.07a4.5 4.5 0 01-1.9 1.13L6 18l.8-2.68a4.5 4.5 0 011.13-1.9l8.93-8.93zm0 0L19.5 7.13",
    star: "M11.48 3.5a.56.56 0 011.04 0l2.12 5.11a.56.56 0 00.48.35l5.52.44c.5.04.7.66.32.99l-4.2 3.6a.56.56 0 00-.18.56l1.28 5.38a.56.56 0 01-.84.61l-4.72-2.88a.56.56 0 00-.59 0l-4.72 2.88a.56.56 0 01-.84-.61l1.28-5.38a.56.56 0 00-.18-.56l-4.2-3.6a.56.56 0 01.32-.99l5.52-.44a.56.56 0 00.48-.35l2.12-5.11z",
    phone: "M2.25 6.75c0 8.28 6.72 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.37c0-.52-.35-.97-.85-1.09l-4.42-1.1c-.44-.11-.9.05-1.17.41l-.97 1.29c-.28.37-.77.53-1.2.38a12.04 12.04 0 01-7.14-7.14c-.16-.43 0-.92.38-1.2l1.29-.97c.36-.27.52-.73.41-1.17l-1.1-4.42a1.12 1.12 0 00-1.09-.85H4.5A2.25 2.25 0 002.25 4.5v2.25z",
    mail: "M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.24a2.25 2.25 0 01-1.07 1.92l-7.5 4.61a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.92V6.75",
    copy: "M15.75 17.25v3.38c0 .62-.5 1.12-1.12 1.12h-9.75c-.63 0-1.13-.5-1.13-1.12V7.88c0-.63.5-1.13 1.13-1.13H6.75a9.06 9.06 0 011.5.12m7.5 10.38h3.38c.62 0 1.12-.5 1.12-1.13V11.25c0-4.46-3.24-8.16-7.5-8.88a9.06 9.06 0 00-1.5-.12H9.38c-.62 0-1.13.5-1.13 1.13v3.49m7.5 10.38H9.38c-.62 0-1.13-.5-1.13-1.13V6.87m11.5 4.38v-1.88a3.38 3.38 0 00-3.38-3.37h-1.5a1.13 1.13 0 01-1.12-1.13v-1.5a3.38 3.38 0 00-3.38-3.37H9.75",
    check: "M4.5 12.75l6 6 9-13.5",
    store: "M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.35m-16.5 11.65V9.35m0 0a3 3 0 003.75-.62 3 3 0 004.5 0 3 3 0 004.5 0 3 3 0 003.75.62m-16.5 0a3 3 0 01-.62-4.72L4.32 3.66A2.25 2.25 0 015.91 3h12.18c.6 0 1.17.24 1.59.66l1.19 1.19a3 3 0 01-.62 4.72",
    clock: "M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z",
    wallet: "M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3",
    sun: "M12 3v2.25m6.36.39l-1.59 1.59M21 12h-2.25m-.39 6.36l-1.59-1.59M12 18.75V21m-4.77-4.23l-1.59 1.59M5.25 12H3m4.23-4.77L5.64 5.64M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z",
    dots: "M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z",
    plus: "M12 4.5v15m7.5-7.5h-15",
    upload: "M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5",
    edit: "M16.86 4.49l1.69-1.69a1.88 1.88 0 112.65 2.65L6.83 19.82a4.5 4.5 0 01-1.9 1.13L2.25 21.75l.8-2.68a4.5 4.5 0 011.13-1.9L16.86 4.49z",
    x: "M6 18L18 6M6 6l12 12",
    link: "M13.19 8.69a4.5 4.5 0 011.24 7.24l-4.5 4.5a4.5 4.5 0 01-6.36-6.36l1.76-1.76m13.35-.62l1.76-1.76a4.5 4.5 0 00-6.36-6.36l-4.5 4.5a4.5 4.5 0 001.24 7.24",
    shield: "M9 12.75L11.25 15 15 9.75m-3-7.04A11.96 11.96 0 013.6 6 12 12 0 003 9.75c0 5.59 3.82 10.29 9 11.62 5.18-1.33 9-6.03 9-11.62 0-1.31-.21-2.57-.6-3.75h-.15c-3.2 0-6.1-1.25-8.25-3.29z",
    eye: "M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3 9.96 7.18c.07.2.07.43 0 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3-9.96-7.18zM15 12a3 3 0 11-6 0 3 3 0 016 0z",
    eyeOff: "M3.98 8.22A10.48 10.48 0 001.93 12C3.23 16.34 7.24 19.5 12 19.5c1 0 1.97-.14 2.88-.4M6.23 6.23A10.45 10.45 0 0112 4.5c4.76 0 8.77 3.16 10.07 7.5a10.52 10.52 0 01-4.29 5.77M6.23 6.23L3 3m3.23 3.23l3.65 3.65m7.89 7.89L21 21m-3.23-3.23l-3.65-3.65m0 0a3 3 0 10-4.24-4.24m4.24 4.24L9.88 9.88",
    arrowLeft: "M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18",
    chevronRight: "M8.25 4.5l7.5 7.5-7.5 7.5",
    image: "M2.25 15.75l5.16-5.16a2.25 2.25 0 013.18 0l5.16 5.16m-1.5-1.5l1.41-1.41a2.25 2.25 0 013.18 0l2.91 2.91M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21zM14.25 8.25h.01v.01h-.01v-.01zm.38 0a.38.38 0 11-.75 0 .38.38 0 01.75 0z",
    sparkle: "M9.81 15.9L9 18.75l-.81-2.85a4.5 4.5 0 00-3.09-3.09L2.25 12l2.85-.81a4.5 4.5 0 003.09-3.09L9 5.25l.81 2.85a4.5 4.5 0 003.09 3.09L15.75 12l-2.85.81a4.5 4.5 0 00-3.09 3.09zM18.26 8.72L18 9.75l-.26-1.03a3.38 3.38 0 00-2.46-2.46L14.25 6l1.03-.26a3.38 3.38 0 002.46-2.46L18 2.25l.26 1.03a3.38 3.38 0 002.46 2.46L21.75 6l-1.03.26a3.38 3.38 0 00-2.46 2.46z",
} as const;
export type IconName = keyof typeof PATHS;

export function Icon({ name, className = "w-4 h-4", strokeWidth = 1.7 }: { name: IconName; className?: string; strokeWidth?: number }) {
    return (
        <svg className={clsx("shrink-0", className)} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={strokeWidth} aria-hidden>
            <path strokeLinecap="round" strokeLinejoin="round" d={PATHS[name]} />
        </svg>
    );
}

/** WhatsApp's mark, simplified — the action reads faster with its own glyph. */
export function WhatsAppIcon({ className = "w-4 h-4" }: { className?: string }) {
    return (
        <svg className={clsx("shrink-0", className)} viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 004.74 1.21c5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.15a8.2 8.2 0 01-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.23 8.23 0 01-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 4.54 0 8.24 3.7 8.24 8.24 0 4.54-3.7 8.24-8.24 8.24zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.16.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.42.08-.16.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.42h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.22-.16-.47-.28z" />
        </svg>
    );
}

// ─── Words for dates ──────────────────────────────────────────────────────────

/** "2023-01-01" → a local date (no UTC shift: Nairobi midnight stays the same day). */
export function parseDay(iso: string | null | undefined): Date | null {
    if (!iso) return null;
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? null : d;
}

/** "1 Jan 2023" */
export function fmtDay(iso: string | null | undefined): string {
    const d = parseDay(iso);
    return d ? d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "";
}

/** Whole months between two dates (the day-of-month decides the last one). */
function monthsBetween(from: Date, to: Date): number {
    let m = (to.getFullYear() - from.getFullYear()) * 12 + (to.getMonth() - from.getMonth());
    if (to.getDate() < from.getDate()) m -= 1;
    return m;
}

/** "3 yrs 10 mos", "1 yr", "5 mos", "Under a month". */
export function spanWords(months: number): string {
    if (months < 1) return "Under a month";
    const y = Math.floor(months / 12);
    const mo = months % 12;
    const parts: string[] = [];
    if (y) parts.push(`${y} yr${y === 1 ? "" : "s"}`);
    if (mo) parts.push(`${mo} mo${mo === 1 ? "" : "s"}`);
    return parts.join(" ");
}

/** Time with the business: hire date to the end date (or today). Null when no hire date. */
export function tenure(hire: string | null, end: string | null, today = new Date()): { words: string; future: boolean } | null {
    const h = parseDay(hire);
    if (!h) return null;
    const stop = parseDay(end) ?? today;
    if (h > today) return { words: `Starts ${fmtDay(hire)}`, future: true };
    return { words: spanWords(monthsBetween(h, stop < h ? h : stop)), future: false };
}

/** Age in whole years (for the date of birth line). */
export function ageYears(dob: string | null, today = new Date()): number | null {
    const d = parseDay(dob);
    if (!d) return null;
    return Math.floor(monthsBetween(d, today) / 12);
}

// ─── Small parts ──────────────────────────────────────────────────────────────

/** A soft tint picked from the name, so the same person always gets the same colour. */
const TINTS = [
    "bg-brand-100 text-brand-800",
    "bg-amber-100 text-amber-800",
    "bg-success-100 text-success-800",
    "bg-info-100 text-info-800",
    "bg-pink-100 text-pink-800",
    "bg-accent-100 text-accent-800",
];
export function tintFor(name: string): string {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
    return TINTS[h % TINTS.length];
}
export function initialsOf(name: string): string {
    return name.split(/\s+/).map(s => s[0]).filter(Boolean).slice(0, 2).join("").toUpperCase() || "?";
}

export function Avatar({ name, url, size = "md" }: { name: string; url?: string | null; size?: "sm" | "md" | "xl" }) {
    const box = { sm: "w-10 h-10 text-sm", md: "w-12 h-12 text-base", xl: "w-20 h-20 md:w-24 md:h-24 text-2xl md:text-3xl" }[size];
    const [broken, setBroken] = useState(false);
    if (url && !broken) {
        return <img src={url} alt="" onError={() => setBroken(true)} className={clsx(box, "rounded-full object-cover shrink-0")} />;
    }
    return <div aria-hidden className={clsx(box, tintFor(name), "rounded-full flex items-center justify-center font-bold shrink-0 select-none")}>{initialsOf(name)}</div>;
}

/** The head of a file card: a tinted icon, the title, an optional line under it, actions on the right. */
export function CardHead({ icon, title, subtitle, actions, tone = "brand", titleId }: {
    icon: IconName; title: ReactNode; subtitle?: ReactNode; actions?: ReactNode;
    tone?: "brand" | "neutral" | "amber" | "success" | "info"; titleId?: string;
}) {
    const toneCls = { brand: "bg-brand-50 text-brand-700", neutral: "bg-surface-100 text-surface-600", amber: "bg-amber-50 text-amber-700", success: "bg-success-50 text-success-700", info: "bg-info-50 text-info-700" }[tone];
    return (
        <div className="card-header gap-3 flex-wrap">
            <div className="flex items-center gap-3 min-w-0 flex-1 basis-[14rem]">
                <span className={clsx("w-9 h-9 rounded-control flex items-center justify-center shrink-0", toneCls)}><Icon name={icon} className="w-[18px] h-[18px]" /></span>
                <div className="min-w-0">
                    <h3 id={titleId} className="font-semibold text-[15px] text-surface-900 leading-tight truncate">{title}</h3>
                    {subtitle && <p className="text-xs text-surface-500 mt-0.5 leading-snug">{subtitle}</p>}
                </div>
            </div>
            {actions && <div className="flex items-center gap-2 shrink-0 ml-auto">{actions}</div>}
        </div>
    );
}

/** A label over a value. Empty values say "Not set" softly and, where the viewer may, offer to add one. */
export function Field({ label, value, onAdd, emptyText = "Not set", className, mono }: {
    label: string; value: ReactNode; onAdd?: () => void; emptyText?: string; className?: string; mono?: boolean;
}) {
    const empty = value === null || value === undefined || value === "";
    return (
        <div className={clsx("min-w-0", className)}>
            <dt className="text-2xs font-semibold uppercase tracking-wider text-surface-500">{label}</dt>
            <dd className={clsx("mt-1 text-sm leading-snug", mono && "font-mono")}>
                {empty ? (
                    onAdd
                        ? <button type="button" onClick={onAdd} className="group inline-flex items-center gap-1 text-surface-400 hover:text-brand-700 focus-visible:text-brand-700 rounded">
                            <span>{emptyText}</span><span aria-hidden>·</span>
                            <span className="font-semibold text-brand-700 group-hover:underline">Add</span>
                            <span className="sr-only"> {label}</span>
                        </button>
                        : <span className="text-surface-400">{emptyText}</span>
                ) : <span className="text-surface-900 break-words">{value}</span>}
            </dd>
        </div>
    );
}

/** Copy a value; the icon turns into a tick for a moment. */
export function CopyButton({ value, label, className }: { value: string; label: string; className?: string }) {
    const toast = useToastStore();
    const [done, setDone] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout>>();
    useEffect(() => () => clearTimeout(timer.current), []);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setDone(true);
            clearTimeout(timer.current);
            timer.current = setTimeout(() => setDone(false), 1500);
        } catch { toast.error("Could not copy — select it and copy instead."); }
    };
    return (
        <button type="button" onClick={() => void copy()} aria-label={label} title={label}
            className={clsx("inline-flex items-center justify-center w-7 h-7 rounded-full text-surface-400 hover:text-surface-700 hover:bg-surface-100 transition-colors", className)}>
            <Icon name={done ? "check" : "copy"} className={clsx("w-3.5 h-3.5", done && "text-success")} strokeWidth={done ? 2.2 : 1.7} />
        </button>
    );
}

export function Chip({ children, icon, tone = "neutral", className }: { children: ReactNode; icon?: IconName; tone?: "neutral" | "brand" | "success" | "danger" | "amber" | "info"; className?: string }) {
    const toneCls = {
        neutral: "bg-surface-100 text-surface-700",
        brand: "bg-brand-50 text-brand-800",
        success: "bg-success-50 text-success-800",
        danger: "bg-danger-50 text-danger-700",
        amber: "bg-amber-50 text-amber-800",
        info: "bg-info-50 text-info-800",
    }[tone];
    return (
        <span className={clsx("inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap", toneCls, className)}>
            {icon && <Icon name={icon} className="w-3.5 h-3.5 opacity-80" />}
            {children}
        </span>
    );
}

/** The quiet empty line inside a card: a friendly sentence and, when there is one, the action. */
export function EmptyLine({ icon, text, action }: { icon: IconName; text: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center text-center gap-3 rounded-2xl border border-dashed border-surface-300 bg-surface-50/60 px-4 py-7">
            <span className="w-10 h-10 rounded-full bg-white text-surface-400 flex items-center justify-center shadow-inner-sm"><Icon name={icon} className="w-5 h-5" /></span>
            <p className="text-sm text-surface-500 max-w-sm">{text}</p>
            {action}
        </div>
    );
}

/** A thin used/remaining bar. */
export function Meter({ parts, total, label }: { parts: { value: number; className: string }[]; total: number; label: string }) {
    const safe = total > 0 ? total : 1;
    return (
        <div className="h-2 w-full rounded-full bg-surface-100 overflow-hidden flex" role="img" aria-label={label}>
            {parts.map((p, i) => <div key={i} className={clsx("h-full transition-[width] duration-500", p.className)} style={{ width: `${Math.max(0, Math.min(100, (p.value / safe) * 100))}%` }} />)}
        </div>
    );
}
