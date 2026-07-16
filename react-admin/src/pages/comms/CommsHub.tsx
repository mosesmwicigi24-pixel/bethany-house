/**
 * CommsHub - Phase 3 DMs, Spaces, User Directory.
 *
 * New in this version:
 *   - User directory panel - browse all staff, click to open DM
 *   - New Space modal - private/public toggle + member picker at creation
 *   - Space settings panel - rename, toggle private, add/remove members, archive
 *   - @mention autocomplete (type @)
 *   - Private spaces show lock icon; public spaces show #
 *   - Channel header shows member count + settings cog for space admins
 */

import { useState, useEffect, useRef, useCallback } from "react";
import { useVisualViewport } from "@/lib/useVisualViewport";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get } from "@/api/client";
import { channelApi, type Channel, type ChannelMessage, type ChannelUser, type ChannelAttachment, type LinkedEntity, type EntitySearchResult, type EntityPreview } from "@/api/channels";
import { EntityChipWithPreview } from "@/components/comms/EntityChipWithPreview";
import { commentApi, type MentionUser } from "@/api/comments";
import { useAuthStore } from "@/store/auth.store";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import { subscribeToChannel, subscribeToReaction, joinPresenceChannel, whisperTyping, getEcho } from "@/lib/echo";

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Strip markdown syntax for plain-text previews (sidebar, reply snippets)
function stripMarkdown(body: string): string {
    return body
        .replace(/!\[([^\]]*)\]\([^)]+\)/g, "$1 📎")   // images  → "filename 📎"
        .replace(/\[📎 ([^\]]+)\]\([^)]+\)/g, "$1 📎")  // files   → "filename 📎"
        .replace(/@\[([^\]]+)\]\(user:\d+\)/g, "@$1")   // mentions → "@Name"
        .replace(/#\[([^\]]+)\]\(entity:[^)]+\)/g, "$1") // entity tags → "#ORD-1234"
        .replace(/\s+/g, " ")
        .trim();
}

function fmtTime(iso: string) {
    const d = new Date(iso);
    const now = new Date();
    const diff = now.getTime() - d.getTime();
    if (diff < 60_000) return "just now";
    if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m`;
    if (diff < 86_400_000) return d.toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" });
    return d.toLocaleDateString("en-KE", { day: "2-digit", month: "short" });
}

/** Exact wall-clock time — used where `fmtTime`'s relative wording ("5m") would be ambiguous. */
function fmtClock(iso: string) {
    return new Date(iso).toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" });
}

/** "Today" / "Yesterday" beat a date every time for the two days people actually read. */
function dateLabel(iso: string) {
    const d     = new Date(iso);
    const today = new Date();
    const start = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const days  = Math.round((start(today) - start(d)) / 86_400_000);
    if (days === 0) return "Today";
    if (days === 1) return "Yesterday";
    if (days < 7)   return d.toLocaleDateString("en-KE", { weekday: "long" });
    return d.toLocaleDateString("en-KE", { weekday: "long", day: "2-digit", month: "long" });
}

function groupByDate(messages: ChannelMessage[]) {
    const groups: Record<string, ChannelMessage[]> = {};
    for (const m of messages) {
        const date = dateLabel(m.created_at);
        if (!groups[date]) groups[date] = [];
        groups[date].push(m);
    }
    return Object.entries(groups).map(([date, messages]) => ({ date, messages }));
}

/**
 * Consecutive messages from one author within this window collapse into a single
 * avatar + name header. Five minutes is the usual chat convention: long enough to
 * absorb a burst of typing, short enough that a genuine pause re-introduces the
 * header and re-anchors who is speaking.
 */
const GROUP_WINDOW_MS = 5 * 60_000;

function isContinuation(msg: ChannelMessage, prev?: ChannelMessage) {
    if (!prev) return false;
    if (msg.type === "system" || prev.type === "system") return false;
    if (!msg.user || !prev.user || msg.user.id !== prev.user.id) return false;
    if (msg.reply_to) return false; // a reply opens a new thought — always show its header
    return new Date(msg.created_at).getTime() - new Date(prev.created_at).getTime() < GROUP_WINDOW_MS;
}

/**
 * Solid tile colours seeded by the name, so one person keeps one colour in every
 * session — colour is a faster handle than initials when scanning a busy channel.
 * Every entry is a token from this app's own theme (tailwind.config.js): the brand
 * amber leads, backed by the slate neutrals and the semantic hues. Nothing here is
 * an off-palette Tailwind default.
 */
const AVATAR_COLORS = [
    "bg-brand-500",   // #d98a2a — the brand amber, first so it dominates
    "bg-surface-700", // #364152 — slate
    "bg-info",        // #2563eb
    "bg-success",     // #16a34a
    "bg-brand-700",   // #9f561c — deep rust
    "bg-surface-500", // #697586
];

function avatarColor(name?: string) {
    if (!name) return AVATAR_COLORS[0];
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = (Math.imul(hash, 31) + name.charCodeAt(i)) >>> 0;
    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

function Avatar({ name, initials, size = "sm" }: { name?: string; initials: string; size?: "xs" | "sm" | "md" }) {
    const sz = size === "xs" ? "w-5 h-5 text-2xs" : size === "md" ? "w-9 h-9 text-sm" : "w-7 h-7 text-2xs";
    return (
        <div className={clsx(
            // Solid fill + white text — a 15% tint reads as washed-out at this size.
            "rounded-full flex items-center justify-center shrink-0 font-bold tracking-tight select-none text-white",
            avatarColor(name), sz,
        )} title={name}>
            {initials}
        </div>
    );
}

// ─── Rich text helpers ────────────────────────────────────────────────────────

// Convert simple markdown subset to JSX: **bold**, *italic*, `code`, bullet lists
function renderInlineMarkdown(text: string, isOwn: boolean, keyPrefix: string): React.ReactNode[] {
    // Process line by line so we can handle bullet lists
    const lines = text.split("\n");
    const result: React.ReactNode[] = [];
    let listItems: string[] = [];

    const flushList = (k: string) => {
        if (!listItems.length) return;
        result.push(
            <ul key={`${k}-ul`} className="list-disc list-inside space-y-0.5 my-1">
                {listItems.map((li, j) => (
                    <li key={j} className="text-sm leading-relaxed">{renderInlineSpan(li, isOwn, `${k}-li${j}`)}</li>
                ))}
            </ul>
        );
        listItems = [];
    };

    lines.forEach((line, li) => {
        const bullet = line.match(/^(\s*[-*•]|\s*\d+\.)\s+(.*)$/);
        if (bullet) {
            listItems.push(bullet[2]);
            return;
        }
        flushList(`${keyPrefix}-${li}`);
        // Blank line → spacer
        if (line.trim() === "") {
            if (li > 0 && li < lines.length - 1) result.push(<br key={`${keyPrefix}-br${li}`} />);
            return;
        }
        // Heading: ## or #
        const heading = line.match(/^(#{1,3})\s+(.+)$/);
        if (heading) {
            const Tag = heading[1].length === 1 ? "p" : "p";
            const cls = heading[1].length === 1
                ? "font-bold text-base mt-1"
                : heading[1].length === 2 ? "font-bold text-sm mt-1" : "font-semibold text-xs mt-0.5";
            result.push(<p key={`${keyPrefix}-h${li}`} className={cls}>{renderInlineSpan(heading[2], isOwn, `${keyPrefix}-h${li}`)}</p>);
            return;
        }
        // Blockquote: > text
        const bq = line.match(/^>\s*(.*)$/);
        if (bq) {
            result.push(
                <div key={`${keyPrefix}-bq${li}`}
                    className={clsx("border-l-2 pl-2 my-0.5 italic text-sm",
                        isOwn ? "border-white/40 text-white/80" : "border-surface-300 text-surface-500")}>
                    {bq[1]}
                </div>
            );
            return;
        }
        // Normal line
        result.push(
            <span key={`${keyPrefix}-t${li}`}>
                {renderInlineSpan(line, isOwn, `${keyPrefix}-t${li}`)}
                {li < lines.length - 1 && <br />}
            </span>
        );
    });
    flushList(`${keyPrefix}-end`);
    return result;
}

function renderInlineSpan(text: string, isOwn: boolean, keyPrefix: string): React.ReactNode {
    // Split on **bold**, *italic*, `code`
    const parts = text.split(/(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/g);
    return (
        <>
            {parts.map((p, i) => {
                if (p.startsWith("**") && p.endsWith("**"))
                    return <strong key={`${keyPrefix}-b${i}`}>{p.slice(2, -2)}</strong>;
                if (p.startsWith("*") && p.endsWith("*"))
                    return <em key={`${keyPrefix}-em${i}`}>{p.slice(1, -1)}</em>;
                if (p.startsWith("`") && p.endsWith("`"))
                    return <code key={`${keyPrefix}-c${i}`}
                        className={clsx("px-1 py-0.5 rounded text-xs font-mono",
                            isOwn ? "bg-white/20" : "bg-surface-100 text-surface-700")}>{p.slice(1, -1)}</code>;
                return <span key={`${keyPrefix}-s${i}`}>{p}</span>;
            })}
        </>
    );
}

// Parse a message body into { textParts, attachments } so we can render them separately
type ParsedBody = {
    textContent: string;    // body stripped of attachment tokens
    images: Array<{ name: string; url: string }>;
    files:  Array<{ name: string; url: string }>;
};

function parseBody(body: string): ParsedBody {
    const images: ParsedBody["images"] = [];
    const files:  ParsedBody["files"]  = [];
    let text = body;

    // Extract image tokens
    text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (_, name, url) => {
        images.push({ name, url });
        return "";
    });
    // Extract file tokens (with or without 📎 prefix)
    text = text.replace(/\[📎\s*([^\]]+)\]\(([^)]+)\)/g, (_, name, url) => {
        files.push({ name, url });
        return "";
    });
    text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, name, url) => {
        // Don't strip @mention-style links - those are handled separately
        if (!url.startsWith("http")) return `[${name}](${url})`;
        files.push({ name, url });
        return "";
    });

    // Strip entity tag tokens - #[#ORD-1234](entity:order:1234)
    // These are rendered as EntityChip components via linkedEntities prop,
    // so the raw token must be removed from the text content of the bubble.
    text = text.replace(/#\[([^\]]+)\]\(entity:[^)]+\)/g, "");

    return { textContent: text.trim(), images, files };
}



// ─── File type helpers ────────────────────────────────────────────────────────

type FileKind = "image" | "video" | "audio" | "pdf" | "text" | "csv" | "word" | "excel" | "other";

function getFileKind(name: string, mime?: string): FileKind {
    const ext = (name.split(".").pop() ?? "").toLowerCase();
    if (/^(png|jpe?g|gif|webp|svg|bmp|ico|avif)$/.test(ext)) return "image";
    if (/^(mp4|webm|ogg|mov|avi|mkv)$/.test(ext)) return "video";
    if (/^(mp3|wav|ogg|aac|flac|m4a)$/.test(ext)) return "audio";
    if (ext === "pdf") return "pdf";
    if (/^(txt|md|log|json|xml|yaml|yml|ts|tsx|js|jsx|css|html|sh|py|php|sql)$/.test(ext)) return "text";
    if (/^(csv|tsv)$/.test(ext)) return "csv";
    if (/^(doc|docx|odt|rtf)$/.test(ext)) return "word";
    if (/^(xls|xlsx|ods)$/.test(ext)) return "excel";
    if (mime?.startsWith("image/")) return "image";
    if (mime?.startsWith("video/")) return "video";
    if (mime?.startsWith("audio/")) return "audio";
    return "other";
}

// Colour + icon per kind, used in file cards and composer chips
const KIND_META: Record<FileKind, { label: string; bg: string; text: string; icon: React.ReactNode }> = {
    image:  { label: "Image",       bg: "bg-violet-50",  text: "text-violet-600", icon: <path strokeLinecap="round" strokeLinejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/> },
    video:  { label: "Video",       bg: "bg-pink-50",    text: "text-pink-600",   icon: <path strokeLinecap="round" strokeLinejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/> },
    audio:  { label: "Audio",       bg: "bg-sky-50",     text: "text-sky-600",    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"/> },
    pdf:    { label: "PDF",         bg: "bg-red-50",     text: "text-red-600",    icon: <><path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></> },
    text:   { label: "Text",        bg: "bg-slate-50",   text: "text-slate-600",  icon: <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/> },
    csv:    { label: "CSV",         bg: "bg-emerald-50", text: "text-emerald-600",icon: <path strokeLinecap="round" strokeLinejoin="round" d="M3 10h18M3 14h18M10 4v16M6 4v16M14 4v16M18 4v16M3 8a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/> },
    word:   { label: "Word",        bg: "bg-blue-50",    text: "text-blue-600",   icon: <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/> },
    excel:  { label: "Spreadsheet", bg: "bg-green-50",   text: "text-green-600",  icon: <path strokeLinecap="round" strokeLinejoin="round" d="M3 10h18M3 14h18M10 4v16M6 4v16M14 4v16M18 4v16M3 8a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/> },
    other:  { label: "File",        bg: "bg-surface-50", text: "text-surface-500",icon: <path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/> },
};

function FileKindIcon({ kind, className = "w-5 h-5" }: { kind: FileKind; className?: string }) {
    return (
        <svg className={clsx(className, KIND_META[kind].text)} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            {KIND_META[kind].icon}
        </svg>
    );
}

// ─── Unified preview modal ────────────────────────────────────────────────────

type PreviewModalProps = { src: string; name: string; kind: FileKind; rawUrl: string; onClose: () => void };

function PreviewModal({ src, name, kind, rawUrl, onClose }: PreviewModalProps) {
    const [textContent, setTextContent] = useState<string | null>(null);

    useEffect(() => {
        const handler = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
        document.addEventListener("keydown", handler);
        return () => document.removeEventListener("keydown", handler);
    }, [onClose]);

    // Load text content for text/csv files
    useEffect(() => {
        if (kind !== "text" && kind !== "csv") return;
        fetch(src).then(r => r.text()).then(setTextContent).catch(() => setTextContent("(Could not load file content)"));
    }, [src, kind]);

    const toolbar = (
        <div className="flex items-center justify-between w-full gap-4 shrink-0">
            <div className="flex items-center gap-2 min-w-0">
                <FileKindIcon kind={kind} className="w-4 h-4 shrink-0" />
                <span className="text-white text-sm font-medium truncate">{name}</span>
                <span className="text-white/50 text-xs shrink-0">{KIND_META[kind].label}</span>
            </div>
            <div className="flex items-center gap-2 shrink-0">
                <a href={src} download={name}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white text-xs font-medium transition-colors">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Download
                </a>
                <button onClick={onClose}
                    className="w-8 h-8 flex items-center justify-center rounded-lg bg-white/10 hover:bg-white/20 text-white transition-colors"
                    aria-label="Close">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    );

    const renderPreview = () => {
        if (kind === "image") return (
            <img src={src} alt={name} className="max-w-full max-h-[80vh] rounded-xl object-contain shadow-2xl" />
        );
        if (kind === "video") return (
            <video src={src} controls autoPlay className="max-w-full max-h-[78vh] rounded-xl shadow-2xl bg-black" />
        );
        if (kind === "audio") return (
            <div className="bg-white/10 rounded-2xl p-8 flex flex-col items-center gap-5 w-80">
                <div className="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center">
                    <FileKindIcon kind="audio" className="w-9 h-9" />
                </div>
                <p className="text-white text-sm font-medium text-center truncate w-full">{name}</p>
                <audio src={src} controls className="w-full" />
            </div>
        );
        if (kind === "pdf") return (
            <iframe src={src} title={name}
                className="w-[min(860px,90vw)] h-[80vh] rounded-xl shadow-2xl bg-white border-0" />
        );
        if (kind === "text" || kind === "csv") return (
            <div className="w-[min(860px,90vw)] h-[75vh] bg-white rounded-xl shadow-2xl overflow-auto p-5">
                {textContent === null
                    ? <div className="flex items-center justify-center h-full text-surface-400 text-sm">Loading…</div>
                    : <pre className="text-xs text-surface-700 font-mono whitespace-pre-wrap break-all leading-relaxed">{textContent}</pre>
                }
            </div>
        );
        // Word / Excel / other - Google Docs viewer (works for docx, xlsx, etc.)
        const docsUrl = `https://docs.google.com/viewer?url=${encodeURIComponent(rawUrl)}&embedded=true`;
        return (
            <div className="w-[min(860px,90vw)] h-[80vh] flex flex-col gap-2">
                <iframe src={docsUrl} title={name}
                    className="flex-1 rounded-xl shadow-2xl bg-white border-0" />
                <p className="text-white/40 text-xs text-center">
                    Powered by Google Docs Viewer · <a href={src} download={name} className="underline hover:text-white/70">Download instead</a>
                </p>
            </div>
        );
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/85 p-4"
            onClick={onClose}>
            <div className="flex flex-col items-center gap-3 max-w-[95vw] max-h-[95vh]"
                onClick={e => e.stopPropagation()}>
                {toolbar}
                {renderPreview()}
            </div>
        </div>
    );
}

// ─── Auth-fetched file card & image thumb ─────────────────────────────────────

// Central hook: fetch protected file → blob URL, cache in state
function useAuthBlob(url: string, autoFetch = false) {
    const [blobUrl, setBlobUrl] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [error,   setError]   = useState(false);

    const fetch_ = async () => {
        if (blobUrl || loading) return blobUrl;
        setLoading(true);
        try {
            const { tokenStorage } = await import("@/api/client");
            const token = tokenStorage.get();
            const r = await fetch(url, { headers: token ? { Authorization: `Bearer ${token}` } : {} });
            if (!r.ok) throw new Error(String(r.status));
            const blob = await r.blob();
            const obj  = URL.createObjectURL(blob);
            setBlobUrl(obj);
            setLoading(false);
            return obj;
        } catch {
            setError(true);
            setLoading(false);
            return null;
        }
    };

    // Auto-fetch on mount (for images - we want them to load immediately)
    useEffect(() => {
        if (autoFetch) fetch_();
        return () => { if (blobUrl) URL.revokeObjectURL(blobUrl); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    return { blobUrl, loading, error, fetch: fetch_ };
}

// Image attachment - auto-fetches, shows thumbnail, opens full preview on click
function AuthImage({ url, alt, isOwn }: { url: string; alt: string; isOwn: boolean }) {
    const { blobUrl, loading, error, fetch: fetchBlob } = useAuthBlob(url, true);
    const [modal, setModal] = useState(false);

    if (error) return <AuthFileLink url={url} name={alt || "image"} isOwn={isOwn} />;
    if (loading || !blobUrl) return (
        <span className="inline-block w-48 h-32 rounded-xl bg-surface-100 animate-pulse" />
    );

    return (
        <>
            <button type="button" onClick={() => setModal(true)}
                className="block rounded-xl overflow-hidden hover:opacity-90 transition-opacity cursor-zoom-in max-w-[260px]">
                <img src={blobUrl} alt={alt} className="max-w-full max-h-52 object-cover" />
            </button>
            {modal && (
                <PreviewModal src={blobUrl} name={alt || "image"} kind="image"
                    rawUrl={url} onClose={() => setModal(false)} />
            )}
        </>
    );
}

// File attachment card - shows kind-coloured card with icon, name, size hint; click to preview/download
function AuthFileLink({ url, name, isOwn }: { url: string; name: string; isOwn: boolean }) {
    const kind = getFileKind(name);
    const meta = KIND_META[kind];
    const { blobUrl, loading, fetch: fetchBlob } = useAuthBlob(url);
    const [modal, setModal] = useState(false);
    // Previewable in-browser without Google Docs
    const canPreviewDirect = (["image","video","audio","pdf","text","csv"] as FileKind[]).includes(kind);

    const handleClick = async (e: React.MouseEvent) => {
        e.preventDefault();
        if (loading) return;
        const obj = await fetchBlob();
        if (!obj) { window.open(url, "_blank"); return; }
        if (canPreviewDirect || kind === "word" || kind === "excel") {
            setModal(true);
        } else {
            // Trigger download for truly unsupported types
            const a = document.createElement("a");
            a.href = obj; a.download = name; a.click();
        }
    };

    return (
        <>
            <button type="button" onClick={handleClick}
                className="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors text-left w-56 sm:w-64 bg-surface-50 hover:bg-surface-100 border border-surface-200">
                {/* Icon tile */}
                <div className={clsx("w-10 h-10 rounded-lg flex items-center justify-center shrink-0", meta.bg)}>
                    {loading
                        ? <svg className={clsx("w-5 h-5 animate-spin", meta.text)} viewBox="0 0 24 24" fill="none"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        : <FileKindIcon kind={kind} className="w-5 h-5" />
                    }
                </div>
                {/* Name + type label */}
                <div className="flex-1 min-w-0">
                    <p className="text-xs font-semibold truncate text-surface-800">{name}</p>
                    <p className="text-2xs mt-0.5 text-surface-400">{meta.label}</p>
                </div>
                {/* Open hint */}
                <svg className="w-4 h-4 shrink-0 text-surface-300"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
            {modal && blobUrl && (
                <PreviewModal src={blobUrl} name={name} kind={kind}
                    rawUrl={url} onClose={() => setModal(false)} />
            )}
        </>
    );
}

function LockIcon() {
    return (
        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
    );
}

// ─── Hooks ────────────────────────────────────────────────────────────────────

function useStaffSearch(query: string) {
    return useQuery({
        queryKey: ["staff-search", query],
        queryFn: () => commentApi.searchUsers(query).then(r => r.users),
        staleTime: 15_000,
        placeholderData: [],
    });
}

function useAllStaff() {
    return useQuery({
        queryKey: ["all-staff"],
        queryFn: () => commentApi.searchUsers("").then(r => r.users),
        staleTime: 60_000,
    });
}

// ─── Entity search hook ──────────────────────────────────────────────────────

function useEntitySearch(query: string, enabled: boolean) {
    return useQuery({
        queryKey: ["entity-search", query],
        queryFn:  () => channelApi.entitySearch(query),
        staleTime: 10_000,
        placeholderData: { results: [] },
        enabled: enabled && query.length >= 1,
    });
}

// Status colour map shared between entity popup and entity chip
const ENTITY_STATUS_COLOURS: Record<string, string> = {
    pending:       "bg-surface-100 text-surface-500",
    processing:    "bg-brand-50 text-brand-700",
    completed:     "bg-success-light text-success-dark",
    shipped:       "bg-info-light text-info-dark",
    delivered:     "bg-success-light text-success-dark",
    cancelled:     "bg-danger-light text-danger",
    draft:         "bg-surface-100 text-surface-500",
    in_progress:   "bg-brand-50 text-brand-700",
    pending_approval: "bg-warning-light text-warning-dark",
    approved:      "bg-success-light text-success-dark",
    urgent:        "bg-danger-light text-danger",
};

function entityStatusCls(status: string): string {
    return ENTITY_STATUS_COLOURS[status] ?? "bg-surface-100 text-surface-500";
}

// ─── Entity picker popup ──────────────────────────────────────────────────────

function EntityPickerPopup({
    query,
    onSelect,
    onDismiss,
}: {
    query: string;
    onSelect: (entity: EntitySearchResult) => void;
    onDismiss: () => void;
}) {
    const { data } = useEntitySearch(query, true);
    const results  = data?.results ?? [];

    if (!results.length) return (
        <div className="absolute bottom-full left-0 mb-2 w-72 bg-white rounded-xl border border-surface-200 shadow-xl py-2 z-50">
            <div className="flex items-center justify-between px-3 py-1">
                <p className="text-xs text-surface-400">
                    {query.length < 1 ? "Type to search orders…" : "No results found"}
                </p>
                <button
                    onMouseDown={e => { e.preventDefault(); onDismiss(); }}
                    className="text-surface-300 hover:text-surface-500 transition-colors p-0.5 rounded"
                    aria-label="Close"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    );

    return (
        <div className="absolute bottom-full left-0 mb-2 w-80 bg-white rounded-xl border border-surface-200 shadow-xl py-1 z-50 max-h-64 overflow-y-auto">
            <div className="flex items-center justify-between px-3 pt-1.5 pb-1">
                <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">
                    Tag an order or production order
                </p>
                <button
                    onMouseDown={e => { e.preventDefault(); onDismiss(); }}
                    className="text-surface-300 hover:text-surface-500 transition-colors p-0.5 rounded"
                    aria-label="Close picker"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            {results.map(r => (
                <button
                    key={`${r.type}:${r.id}`}
                    onMouseDown={e => { e.preventDefault(); onSelect(r); }}
                    className="w-full flex items-start gap-2.5 px-3 py-2 hover:bg-surface-50 text-left transition-colors"
                >
                    {/* Type icon */}
                    <div className={`mt-0.5 w-6 h-6 rounded-md flex items-center justify-center shrink-0 ${
                        r.type === "order" ? "bg-brand-50" : "bg-purple-50"
                    }`}>
                        {r.type === "order" ? (
                            <svg className="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        ) : (
                            <svg className="w-3.5 h-3.5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                            </svg>
                        )}
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-surface-900 font-mono">{r.label}</span>
                            <span className={`text-2xs px-1.5 py-0.5 rounded-full font-medium ${entityStatusCls(r.status)}`}>
                                {r.status.replace(/_/g, " ")}
                            </span>
                        </div>
                        <p className="text-2xs text-surface-500 truncate mt-0.5">{r.subtitle}</p>
                        <p className="text-2xs text-surface-400">{r.meta}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ─── Mention popup ────────────────────────────────────────────────────────────

function MentionPopup({ query, onSelect }: { query: string; onSelect: (u: MentionUser) => void }) {
    const { data: users = [] } = useStaffSearch(query);
    if (!users.length) return null;
    return (
        <div className="absolute bottom-full left-0 mb-2 w-64 bg-white rounded-xl border border-surface-200 shadow-xl py-1 z-50 max-h-36 sm:max-h-48 overflow-y-auto">
            {users.map(u => (
                <button key={u.id} onMouseDown={e => { e.preventDefault(); onSelect(u); }}
                    className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-surface-50 text-left">
                    <Avatar initials={u.initials} name={u.name} />
                    <div className="min-w-0">
                        <p className="text-xs font-semibold text-surface-800 truncate">{u.name}</p>
                        <p className="text-2xs text-surface-400 truncate">{u.email}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ─── Rich composer: parse body tokens into visual pill nodes ─────────────────
//
// Converts raw @[Name](user:id) and #[Label](entity:type:id) tokens into
// styled inline pill spans. Used by both the transparent-overlay composer
// in CommsHub and the inline thread composers on detail pages.

// ─── Short composer tokens ───────────────────────────────────────────────────
//
// The wire format is `#[Label](entity:order:12)` / `@[Name](user:3)`. Putting
// that in the textarea is what caused the caret to drift: the mirror can only
// keep the caret honest if it occupies EXACTLY the textarea's width, so a long
// raw token forced either a displaced caret or a stretch of dead space.
//
// So the textarea holds only what you actually want to see — `#POS-260715-IDQLG`
// — and we expand to the wire format at send. The mirror then styles those same
// characters with colour alone: identical metrics, tight highlight, no gap.

export type TokenTarget =
    | { kind: "user"; id: number }
    | { kind: "entity"; type: string; id: number };

/** `#POS-1` → `#[POS-1](entity:order:9)` for every label we inserted. */
export function expandTokens(text: string, map: Record<string, TokenTarget>): string {
    // Longest labels first so "POS-1" can't clobber the start of "POS-12".
    const labels = Object.keys(map).sort((a, b) => b.length - a.length);
    let out = text;
    for (const label of labels) {
        const t = map[label];
        const needle = (t.kind === "user" ? "@" : "#") + label;
        const token =
            t.kind === "user"
                ? `@[${label}](user:${t.id})`
                : `#[${label}](entity:${t.type}:${t.id})`;
        out = out.split(needle).join(token);
    }
    return out;
}

/**
 * Mirror nodes for the SHORT-token composer. Highlights the labels we inserted
 * using colour only — no padding, margin, font-size or weight, since any of
 * those change the metrics and would drift the caret again.
 */
export function parseComposerNodes(
    body: string,
    map: Record<string, TokenTarget>,
): React.ReactNode[] {
    const labels = Object.keys(map).sort((a, b) => b.length - a.length);
    if (!labels.length) return [<span key="t0">{body}</span>];

    const nodes: React.ReactNode[] = [];
    let i = 0;
    let buf = "";
    const flush = () => {
        if (buf) { nodes.push(<span key={"t" + i}>{buf}</span>); buf = ""; }
    };

    outer: while (i < body.length) {
        const ch = body[i];
        if (ch === "#" || ch === "@") {
            for (const label of labels) {
                const t = map[label];
                if ((t.kind === "user") !== (ch === "@")) continue;
                const needle = ch + label;
                if (body.startsWith(needle, i)) {
                    flush();
                    nodes.push(
                        <span key={"k" + i}
                            className={"rounded " + (t.kind === "user"
                                ? "bg-brand-100 text-brand-700"
                                : "bg-brand-50 text-brand-700")}>
                            {needle}
                        </span>,
                    );
                    i += needle.length;
                    continue outer;
                }
            }
        }
        buf += ch;
        i++;
    }
    flush();
    return nodes;
}

export function parseBodyToNodes(body: string): React.ReactNode[] {
    const nodes: React.ReactNode[] = [];
    const re = /(@\[([^\]]+)\]\(user:\d+\)|#\[([^\]]+)\]\(entity:[^)]+\))/g;
    let last = 0;
    let m: RegExpExecArray | null;

    // CARET ALIGNMENT — read before changing anything here.
    //
    // This mirror sits under a transparent <textarea> that owns the caret. The
    // browser positions that caret from the textarea's RAW value, so every token
    // we draw must occupy EXACTLY the raw text's width. Previously the raw
    // `#[POS-260626-V7YAR](entity:order:123)` (~37 chars) was drawn as a short
    // pill (icon + 16-char label at text-xs), so the caret sat ~160px right of
    // what you saw.
    //
    // Fix: render every raw character — the markup around the label is made
    // transparent, not removed — and style with COLOUR ONLY. Padding, margin,
    // font-size, weight and letter-spacing all change the metrics and would
    // reintroduce the drift, so none are used. Wrapping also stays identical
    // because the character run is unchanged.
    const hidden = (s: string, key: string) => (
        <span key={key} className="opacity-0">{s}</span>
    );

    while ((m = re.exec(body)) !== null) {
        if (m.index > last) nodes.push(<span key={"t" + last}>{body.slice(last, m.index)}</span>);
        const raw = m[0];
        const i   = m.index;

        if (raw.startsWith("@")) {
            const name  = m[2];
            const close = raw.slice(raw.indexOf("]("));   // "](user:12)"
            nodes.push(
                <span key={"m" + i} className="rounded bg-brand-100 text-brand-700">
                    {hidden("@[", "mo" + i)}
                    {name}
                    {hidden(close, "mc" + i)}
                </span>
            );
        } else {
            const label   = m[3];
            const close   = raw.slice(raw.indexOf("]("));  // "](entity:order:12)"
            const isOrder = raw.includes("entity:order:");
            nodes.push(
                <span key={"e" + i}
                    className={"rounded " + (isOrder
                        ? "bg-brand-50 text-brand-700"
                        : "bg-purple-50 text-purple-700")}>
                    {hidden("#[", "eo" + i)}
                    {label}
                    {hidden(close, "ec" + i)}
                </span>
            );
        }
        last = i + raw.length;
    }
    if (last < body.length) nodes.push(<span key={"t" + last}>{body.slice(last)}</span>);
    return nodes;
}

// ─── Member picker ────────────────────────────────────────────────────────────

function MemberPicker({ selected, onToggle, excludeIds = [], label = "Add members" }: {
    selected: MentionUser[]; onToggle: (u: MentionUser) => void;
    excludeIds?: number[]; label?: string;
}) {
    const [q, setQ] = useState("");
    const { data: results = [] } = useStaffSearch(q);
    const filtered = results.filter(u => !excludeIds.includes(u.id));

    return (
        <div>
            <label className="label text-xs">{label}</label>
            {selected.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mb-2">
                    {selected.map(u => (
                        <span key={u.id} className="flex items-center gap-1 px-2 py-0.5 rounded-full bg-brand-50 border border-brand-200 text-brand-700 text-2xs font-semibold">
                            {u.name}
                            <button onClick={() => onToggle(u)} className="hover:text-danger ml-0.5">×</button>
                        </span>
                    ))}
                </div>
            )}
            <input className="input text-sm" value={q} onChange={e => setQ(e.target.value)} placeholder="Search by name or email…" />
            {filtered.length > 0 && (
                <div className="mt-1 border border-surface-200 rounded-xl overflow-hidden max-h-40 overflow-y-auto">
                    {filtered.map(u => {
                        const isSelected = selected.some(s => s.id === u.id);
                        return (
                            <button key={u.id} onClick={() => onToggle(u)}
                                className={clsx("w-full flex items-center gap-2.5 px-3 py-2 text-left text-xs transition-colors", isSelected ? "bg-brand-50" : "hover:bg-surface-50")}>
                                <Avatar initials={u.initials} name={u.name} />
                                <div className="flex-1 min-w-0">
                                    <p className="font-semibold text-surface-800 truncate">{u.name}</p>
                                    <p className="text-surface-400 truncate">{u.email}</p>
                                </div>
                                {isSelected && (
                                    <svg className="w-4 h-4 text-brand-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                )}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

// ─── Non-member @mention prompt ──────────────────────────────────────────────
//
// Shown when a user @-mentions someone who is not yet a member of the channel.
// For spaces, the user can add them inline; for DMs (impossible to have non-members)
// this should never appear.

function NonMemberPrompt({
    user,
    channelId,
    channelName,
    isDm,
    onAddAndInsert,
    onInsertAnyway,
    onCancel,
}: {
    user: MentionUser;
    channelId: number;
    channelName: string;
    isDm: boolean;
    onAddAndInsert: () => void;
    onInsertAnyway: () => void;
    onCancel: () => void;
}) {
    const [adding, setAdding] = useState(false);
    const qc = useQueryClient();

    const handleAdd = async () => {
        setAdding(true);
        try {
            await channelApi.addMember(channelId, user.id);
            qc.invalidateQueries({ queryKey: ["channel-detail", channelId] });
            qc.invalidateQueries({ queryKey: ["channels"] });
            onAddAndInsert();
        } catch {
            // If add fails (e.g. no admin rights), still let them mention
            onInsertAnyway();
        } finally {
            setAdding(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[70] flex items-end sm:items-center justify-center bg-black/40 p-4"
            onMouseDown={e => { if (e.target === e.currentTarget) onCancel(); }}>
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-5 flex flex-col gap-4">
                <div className="flex items-start gap-3">
                    <div className="w-9 h-9 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                        <svg className="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 className="text-sm font-bold text-surface-900">
                            {user.name} isn't a member
                        </h3>
                        <p className="text-xs text-surface-500 mt-1">
                            <span className="font-semibold text-surface-700">{user.name}</span> is not in{" "}
                            <span className="font-semibold text-surface-700">{channelName}</span>.
                            They won't be notified and won't see this message unless added.
                        </p>
                    </div>
                </div>

                <div className="flex flex-col gap-2">
                    <button
                        onClick={handleAdd}
                        disabled={adding}
                        className="w-full py-2.5 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50 transition-colors flex items-center justify-center gap-2"
                    >
                        {adding ? (
                            <>
                                <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Adding…
                            </>
                        ) : (
                            <>
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                                </svg>
                                Add {user.name} and mention
                            </>
                        )}
                    </button>
                    <button
                        onClick={onInsertAnyway}
                        className="w-full py-2 rounded-xl border border-surface-200 text-surface-600 text-sm hover:bg-surface-50 transition-colors"
                    >
                        Mention anyway (they won't see it)
                    </button>
                    <button
                        onClick={onCancel}
                        className="w-full py-2 rounded-xl text-surface-400 text-sm hover:text-surface-600 transition-colors"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    );
}

// Convert pasted HTML to markdown-ish plain text so formatting is preserved in the bubble renderer
function htmlToMarkdown(html: string): string {
    const div = document.createElement("div");
    div.innerHTML = html;

    const walk = (node: Node): string => {
        if (node.nodeType === Node.TEXT_NODE) return node.textContent ?? "";
        if (node.nodeType !== Node.ELEMENT_NODE) return "";
        const el = node as HTMLElement;
        const tag = el.tagName.toLowerCase();
        const inner = Array.from(el.childNodes).map(walk).join("");

        switch (tag) {
            case "b": case "strong": return `**${inner}**`;
            case "i": case "em":    return `*${inner}*`;
            case "code":            return `\`${inner}\``;
            case "pre":             return `\`${inner.trim()}\``;
            case "a":               return inner; // strip links, keep text
            case "br":              return "\n";
            case "p":               return inner + "\n";
            case "div":             return inner + "\n";
            case "li": {
                const parentTag = (el.parentElement?.tagName ?? "").toLowerCase();
                if (parentTag === "ol") {
                    const idx = Array.from(el.parentElement!.children).indexOf(el) + 1;
                    return `${idx}. ${inner}\n`;
                }
                return `- ${inner}\n`;
            }
            case "ul": case "ol":   return inner;
            case "h1":              return `# ${inner}\n`;
            case "h2":              return `## ${inner}\n`;
            case "h3":              return `### ${inner}\n`;
            case "blockquote":      return inner.split("\n").map(l => `> ${l}`).join("\n") + "\n";
            default:                return inner;
        }
    };

    return walk(div)
        .replace(/\n{3,}/g, "\n\n") // collapse excessive blank lines
        .trim();
}


function Composer({ channelId, channelName, channelType, channelMemberIds, replyTo, onClearReply, onSent, bottomRef }: {
    channelId: number;
    channelName?: string;
    channelType?: string;
    /** Set of user IDs who are already members — used to guard @mentions */
    channelMemberIds?: Set<number>;
    replyTo?: ChannelMessage | null;
    onClearReply?: () => void; onSent: (msg: ChannelMessage) => void;
    bottomRef?: React.RefObject<HTMLDivElement>;
}) {
    const [body, setBody]               = useState("");
    const [mentionQ, setMentionQ]       = useState<string | null>(null);
    const [mentionStart, setStart]      = useState(0);
    const [entityQ, setEntityQ]         = useState<string | null>(null);
    const [entityStart, setEntityStart] = useState(0);
    // The composer holds SHORT, human tokens (`#POS-260715-IDQLG`, `@Jane Doe`)
    // — never the long wire form — so the mirror can draw them at exactly the
    // textarea's width: caret stays aligned, no dead space, tight highlight.
    // This maps a token's label back to its entity so we can expand to the wire
    // format (`#[Label](entity:order:12)`) on send. Nothing else changes.
    const [tokenMap, setTokenMap] = useState<Record<string, TokenTarget>>({});
    // Enter sends on a physical keyboard; on touch the Return key makes a newline
    // and Send is an explicit tap. That is the platform convention everywhere else
    // on a phone, it stops Return firing off half-typed messages, and it retires
    // the ↵ toggle on touch — handing its width back to the text, which is the
    // narrowest thing in the row.
    const isCoarsePointer = typeof window !== "undefined"
        && window.matchMedia?.("(pointer: coarse)").matches === true;
    const [enterToSend, setEnterToSend] = useState(!isCoarsePointer);
    // Pending non-member mention — shows the add-member prompt before inserting
    const [pendingMention, setPendingMention] = useState<MentionUser | null>(null);
    const [attachments, setAttachments] = useState<ChannelAttachment[]>([]);
    const [previews, setPreviews]       = useState<string[]>([]);  // local blob URLs for composer preview
    const [uploading, setUploading]     = useState(false);
    const textareaRef                   = useRef<HTMLTextAreaElement>(null);
    const fileInputRef                  = useRef<HTMLInputElement>(null);
    const { user }                      = useAuthStore();

    // ── Phase 5: keyboard handling ────────────────────────────────────────────
    // visualViewport tracks the true visible area - shrinks when keyboard opens.
    // We use it to pad the composer so it sits above the keyboard on iOS/Android.
    // keyboardHeight is deliberately not consumed any more — the shell is sized to
    // visualViewport.height, so it already ends at the keyboard. keyboardOpen is
    // still used, to scroll the thread down once the keyboard has settled.
    const { keyboardOpen } = useVisualViewport();

    // Auto-scroll to bottom when keyboard opens so the latest message is visible
    const scrollRef = useRef<HTMLElement | null>(null);
    useEffect(() => {
        if (bottomRef?.current) scrollRef.current = bottomRef.current;
    }, [bottomRef]);

    useEffect(() => {
        if (keyboardOpen) {
            // Small delay so the keyboard animation finishes first
            setTimeout(() => scrollRef.current?.scrollIntoView({ behavior: "smooth" }), 100);
        }
    }, [keyboardOpen]);

    // Focus textarea when channel changes (desktop barcode scanner / keyboard)
    useEffect(() => {
        if (window.innerWidth >= 640) {
            setTimeout(() => textareaRef.current?.focus(), 50);
        }
    }, [channelId]);

    // Revoke blob preview URLs when composer unmounts to avoid memory leaks
    const previewsRef = useRef<string[]>([]);
    useEffect(() => () => { previewsRef.current.forEach(URL.revokeObjectURL); }, []);

    const sendMutation = useMutation({
        mutationFn: () => {
            const attachmentMarkdown = attachments.map(a =>
                a.is_image
                    ? `![${a.name}](${a.url})`
                    : `[📎 ${a.name}](${a.url})`
            ).join("\n");
            // Expand the short composer tokens to the wire format the backend
            // parses into linked_entities. A label the user has since edited
            // simply won't match and goes out as plain text.
            const wireBody = expandTokens(body.trim(), tokenMap);
            const fullBody = [wireBody, attachmentMarkdown].filter(Boolean).join("\n") || " ";
            return channelApi.send(channelId, {
                body: fullBody,
                reply_to_id: replyTo?.id ?? null,
            });
        },
        onSuccess: data => {
            setBody("");
            setTokenMap({});
            setAttachments([]);
            setPreviews([]);
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = "20px";
            }
            onSent(data.message);
            onClearReply?.();
        },
    });

    const canSend = (body.trim().length > 0 || attachments.length > 0) && !uploading;

    const handleFiles = async (files: FileList | null) => {
        if (!files || files.length === 0) return;
        setUploading(true);
        const uploaded: ChannelAttachment[] = [];
        const newPreviews: string[] = [];
        for (const file of Array.from(files).slice(0, 5)) {
            // Create local preview immediately from the File object (no auth needed)
            const previewUrl = URL.createObjectURL(file);
            previewsRef.current.push(previewUrl);
            newPreviews.push(previewUrl);
            try {
                const att = await channelApi.uploadAttachment(file);
                uploaded.push(att);
            } catch (e) {
                console.error("Upload failed for", file.name, e);
                newPreviews.pop(); // remove preview for failed upload
                URL.revokeObjectURL(previewUrl);
                previewsRef.current = previewsRef.current.filter(u => u !== previewUrl);
            }
        }
        setAttachments(prev => [...prev, ...uploaded].slice(0, 10));
        setPreviews(prev => [...prev, ...newPreviews].slice(0, 10));
        setUploading(false);
    };

    const removeAttachment = (idx: number) => {
        const previewUrl = previews[idx];
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewsRef.current = previewsRef.current.filter(u => u !== previewUrl);
        }
        setAttachments(prev => prev.filter((_, i) => i !== idx));
        setPreviews(prev => prev.filter((_, i) => i !== idx));
    };

    const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
        const val    = e.target.value;
        setBody(val);
        const cursor = e.target.selectionStart;
        const before = val.slice(0, cursor);

        // @mention detection
        const at = before.match(/@([^@\n]*)$/);
        if (at) { setMentionQ(at[1]); setStart(cursor - at[0].length); setEntityQ(null); }
        else setMentionQ(null);

        // # entity tag detection - only when @ popup is not open.
        // Must be a FRESH tag being typed: a `#` at the start or after
        // whitespace, with no spaces since (order codes never contain one).
        // The old /#([^#\n]*)$/ matched everything after the last `#`, so once a
        // tag was in the box every further keystroke re-opened the picker and
        // searched "#POS-260715-IDQLG Have you seen the Code?" → "No results found".
        if (!at) {
            const hash = before.match(/(?:^|\s)#([^\s#]*)$/);
            if (hash) { setEntityQ(hash[1]); setEntityStart(cursor - hash[1].length - 1); }
            else setEntityQ(null);
        }

        if (user) whisperTyping(channelId, { id: user.id, name: user.first_name ?? "" });
    };

    const doInsertMention = (u: MentionUser) => {
        // Short token in the box; expanded to the wire format on send.
        const token  = `@${u.name}`;
        setTokenMap(m => ({ ...m, [u.name]: { kind: "user", id: u.id } }));
        const before = body.slice(0, mentionStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? mentionStart);
        // Always leave a trailing space — it separates the tag from whatever is
        // typed next, which also stops the picker re-triggering on the tag.
        const pad    = after.startsWith(" ") ? "" : " ";
        setBody(before + token + pad + after);
        setMentionQ(null);
        // Put the caret right after what we inserted — otherwise it jumps to the
        // end of the message and typing continues in the wrong place.
        const caret = before.length + token.length + pad.length;
        setTimeout(() => {
            const ta = textareaRef.current;
            if (!ta) return;
            ta.focus();
            ta.setSelectionRange(caret, caret);
            ta.style.height = "auto";
            ta.style.height = ta.scrollHeight + "px";
        }, 0);
    };

    const insertMention = (u: MentionUser) => {
        // For DMs membership is implicit; also skip guard if we have no member data yet.
        const isDm = channelType === "dm";
        if (!isDm && channelMemberIds && channelMemberIds.size > 0 && !channelMemberIds.has(u.id)) {
            setMentionQ(null);
            setPendingMention(u);
            return;
        }
        doInsertMention(u);
    };

    const insertEntity = (entity: EntitySearchResult) => {
        // Short token in the box; expanded to the wire format on send.
        const token  = `#${entity.label}`;
        setTokenMap(m => ({
            ...m,
            [entity.label]: { kind: "entity", type: entity.type, id: entity.id },
        }));
        const before = body.slice(0, entityStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? entityStart);
        // Always leave a trailing space — it separates the tag from whatever is
        // typed next, which also stops the picker re-triggering on the tag.
        const pad    = after.startsWith(" ") ? "" : " ";
        setBody(before + token + pad + after);
        setEntityQ(null);
        // Put the caret right after the inserted tag (see doInsertMention).
        const caret = before.length + token.length + pad.length;
        setTimeout(() => {
            const ta = textareaRef.current;
            if (!ta) return;
            ta.focus();
            ta.setSelectionRange(caret, caret);
            ta.style.height = "auto";
            ta.style.height = ta.scrollHeight + "px";
        }, 0);
    };

    return (
        <>
        <div
            className="px-2 sm:px-4 py-3 border-t border-surface-200 shrink-0 bg-white"
            style={{
                // Only the home-indicator inset. This used to add keyboardHeight to
                // lift the composer over the keyboard, which was right while the app
                // shell ran the full window height and therefore extended UNDER the
                // keyboard. The shell is now sized to visualViewport.height, so it
                // already stops at the top of the keyboard — adding keyboardHeight on
                // top of that double-compensates, padding the composer by ~330px
                // inside a ~420px shell and swallowing the thread.
                paddingBottom: "env(safe-area-inset-bottom, 0px)",
            }}
        >
            {/* Reply context */}
            {replyTo && (
                <div className="flex items-center gap-2 mb-2 px-3 py-1.5 rounded-lg bg-surface-50 border border-surface-200 text-xs">
                    <span className="text-surface-400">Replying to</span>
                    <span className="font-semibold text-surface-700 truncate">{replyTo.user?.name}</span>
                    <span className="text-surface-400 truncate flex-1">: {stripMarkdown(replyTo.body).slice(0, 60)}</span>
                    <button onClick={onClearReply} className="text-surface-400 hover:text-danger ml-auto shrink-0"
aria-label="Delete">✕</button>
                </div>
            )}

            {/* Attachment previews */}
            {attachments.length > 0 && (
                <div className="flex flex-wrap gap-2 mb-2">
                    {attachments.map((att, idx) => {
                        const kind = getFileKind(att.name);
                        const meta = KIND_META[kind];
                        return (
                            <div key={idx} className="relative group/att">
                                {kind === "image" ? (
                                    <div className="w-16 h-16 rounded-xl overflow-hidden border border-surface-200">
                                        <img src={previews[idx] ?? att.url} alt={att.name}
                                            className="w-full h-full object-cover" />
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-2 border border-surface-200 rounded-xl px-2.5 py-2 max-w-[160px]">
                                        <div className={clsx("w-7 h-7 rounded-lg flex items-center justify-center shrink-0", meta.bg)}>
                                            <FileKindIcon kind={kind} className="w-4 h-4" />
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-2xs text-surface-700 font-medium truncate">{att.name}</p>
                                            <p className={clsx("text-2xs", meta.text)}>{meta.label}</p>
                                        </div>
                                    </div>
                                )}
                                <button onClick={() => removeAttachment(idx)}
                                    className="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-danger text-white text-2xs flex items-center justify-center opacity-0 group-hover/att:opacity-100 transition-opacity leading-none">
                                    ✕
                                </button>
                            </div>
                        );
                    })}
                    {uploading && (
                        <div className="w-16 h-16 rounded-xl border border-surface-200 bg-surface-50 flex items-center justify-center">
                            <svg className="w-5 h-5 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                        </div>
                    )}
                </div>
            )}

            {/* Main input */}
            <div className="relative">
                {mentionQ !== null && <MentionPopup query={mentionQ} onSelect={insertMention} />}
                {entityQ  !== null && <EntityPickerPopup query={entityQ} onSelect={insertEntity} onDismiss={() => setEntityQ(null)} />}
                {/* Tighter gutters on phone: the text is the narrowest thing here and
                    every px of chrome comes straight out of it. Desktop keeps its
                    roomier px-3/gap-1.5. */}
                <div className="flex items-center gap-1 sm:gap-1.5 bg-white rounded-xl border border-surface-200 focus-within:border-brand-400 focus-within:ring-1 focus-within:ring-brand-200 px-1.5 sm:px-3 py-2">
                    {/* Attach */}
                    <button onClick={() => fileInputRef.current?.click()} title="Attach file or image"
                        disabled={uploading}
                        className="shrink-0 w-9 h-9 sm:w-7 sm:h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-brand-600 hover:bg-surface-100 disabled:opacity-40 transition-colors">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                        </svg>
                    </button>
                    <input ref={fileInputRef} type="file" multiple
                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                        className="hidden"
                        onChange={e => { handleFiles(e.target.files); e.target.value = ""; }} />

                    {/* Rich composer: transparent textarea + visual mirror overlay */}
                    <div className="relative flex-1 self-center min-w-0 max-h-40 overflow-y-auto">
                        {/* Mirror drives the wrapper height — pills render here visually.
                            The textarea is absolute on top, transparent, and handles input.
                            This avoids caret displacement: wrapper height = mirror height. */}
                        <div aria-hidden="true"
                            className="composer-mirror pointer-events-none text-sm leading-5 whitespace-pre-wrap break-words select-none w-full"
                            style={{ wordBreak: "break-word", minHeight: "20px" }}>
                            {body
                                ? parseComposerNodes(body, tokenMap)
                                : <span className="text-surface-400">
                                    {window.innerWidth < 640
                                        ? "Message… (@ mention · # tag order)"
                                        : enterToSend
                                            ? "Message… (Enter to send · @ to mention · # tag order)"
                                            : "Message… (Ctrl+Enter to send · @ to mention · # tag order)"
                                    }
                                  </span>
                            }
                            {/* Zero-width space keeps the last line's height correct */}
                            <span className="select-none">{"​"}</span>
                        </div>

                        {/* Textarea sits exactly over the mirror — same font/line-height, transparent text */}
                        <textarea ref={textareaRef} value={body} onChange={handleChange}
                            rows={1}
                            className="absolute inset-0 w-full text-sm leading-5 bg-transparent resize-none outline-none focus:outline-none focus:ring-0 border-0 shadow-none overflow-hidden"
                            style={{ color: "transparent", caretColor: "rgb(15 23 42)", height: "100%" }}
                            onInput={e => {
                                // Keep textarea height matching the mirror (driven by wrapper).
                                // We do NOT auto-size the textarea itself — the mirror does that.
                                // But we still need scrollHeight sync for correct caret position.
                                const t = e.currentTarget;
                                t.style.height = t.parentElement ? t.parentElement.offsetHeight + "px" : "auto";
                            }}
                            onPaste={e => {
                                const html = e.clipboardData.getData("text/html");
                                if (!html) return;
                                e.preventDefault();
                                const md = htmlToMarkdown(html);
                                const ta = textareaRef.current!;
                                const start = ta.selectionStart;
                                const end   = ta.selectionEnd;
                                const next  = body.slice(0, start) + md + body.slice(end);
                                setBody(next);
                                requestAnimationFrame(() => {
                                    ta.selectionStart = ta.selectionEnd = start + md.length;
                                    ta.style.height = "auto";
                                    ta.style.height = ta.scrollHeight + "px";
                                });
                            }}
                            onKeyDown={e => {
                                if (e.key === "Enter" && mentionQ === null) {
                                    const isMobile = window.matchMedia("(hover: none) and (pointer: coarse)").matches;
                                    if (!isMobile) {
                                        if (enterToSend && !e.shiftKey) { e.preventDefault(); if (canSend && !sendMutation.isPending) sendMutation.mutate(); }
                                        else if (!enterToSend && (e.ctrlKey || e.metaKey)) { e.preventDefault(); if (canSend && !sendMutation.isPending) sendMutation.mutate(); }
                                    }
                                }
                                if (e.key === "Escape") { setMentionQ(null); setEntityQ(null); }
                            }}
                        />
                    </div>

                    {/* Enter mode toggle — physical keyboards only. On touch there is
                        nothing to toggle: Return makes a newline, Send sends. */}
                    {!isCoarsePointer && (
                        <button onClick={() => setEnterToSend(v => !v)}
                            title={enterToSend ? "Enter sends - click to switch" : "Enter = newline - click to switch"}
                            className={clsx(
                                "shrink-0 w-7 h-7 flex items-center justify-center rounded-lg transition-colors text-xs font-bold",
                                enterToSend ? "bg-brand-50 text-brand-600" : "bg-surface-100 text-surface-500"
                            )}>
                            ↵
                        </button>
                    )}

                    {/* Send */}
                    <button onClick={() => { if (canSend) sendMutation.mutate(); }}
                        disabled={!canSend || sendMutation.isPending}
                        className="shrink-0 w-10 h-10 sm:w-8 sm:h-8 rounded-xl bg-brand-600 text-white flex items-center justify-center hover:bg-brand-700 active:bg-brand-800 disabled:opacity-40 transition-colors tap-target">
                        <svg className="w-4 h-4 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </div>
                {/* Shortcut hints - visible when composer is empty */}
                {!body && !attachments.length && (
                    <div className="flex items-center gap-3 px-1 pt-1.5 pb-0.5">
                        <span className="flex items-center gap-1 text-2xs text-surface-300">
                            <kbd className="px-1 py-0.5 rounded bg-surface-100 text-surface-400 font-mono text-2xs border border-surface-200 leading-none">@</kbd>
                            mention people
                        </span>
                        <span className="text-surface-200 text-2xs select-none">·</span>
                        <span className="flex items-center gap-1 text-2xs text-surface-300">
                            <kbd className="px-1 py-0.5 rounded bg-surface-100 text-surface-400 font-mono text-2xs border border-surface-200 leading-none">#</kbd>
                            tag an order
                        </span>
                    </div>
                )}
            </div>
        </div>

        {/* Non-member @mention prompt */}
        {pendingMention && (
            <NonMemberPrompt
                user={pendingMention}
                channelId={channelId}
                channelName={channelName ?? "this space"}
                isDm={channelType === "dm"}
                onAddAndInsert={() => { doInsertMention(pendingMention); setPendingMention(null); }}
                onInsertAnyway={() => { doInsertMention(pendingMention); setPendingMention(null); }}
                onCancel={() => setPendingMention(null)}
            />
        )}
        </>
    );
}

// ─── Entity chip - tappable linked entity in a message ───────────────────────

function EntityChip({ entity, isOwn }: { entity: LinkedEntity; isOwn: boolean }) {
    const navigate = useNavigate();
    const label    = entity.label;
    const isOrder  = entity.type === "order";

    return (
        <button
            onClick={() => navigate(isOrder ? `/sales/orders/${entity.id}` : `/production/orders/${entity.id}`)}
            className={[
                "inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-xs font-bold whitespace-nowrap",
                "border transition-colors cursor-pointer",
                isOwn
                    ? "bg-white/15 border-white/30 text-white hover:bg-white/25"
                    : isOrder
                        ? "bg-brand-50 border-brand-200 text-brand-700 hover:bg-brand-100"
                        : "bg-purple-50 border-purple-200 text-purple-700 hover:bg-purple-100",
            ].join(" ")}
            title={`Open ${isOrder ? "order" : "production order"} ${label}`}
        >
            {/* Type icon */}
            {isOrder ? (
                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            ) : (
                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                </svg>
            )}
            {label}
            {/* External link arrow */}
            <svg className="w-2.5 h-2.5 shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
        </button>
    );
}

// ─── Message content - splits text bubble from bare attachments ───────────────

function MessageContent({ body, isOwn, linkedEntities, entityPreviews, timeLabel }: {
    body: string;
    isOwn: boolean;
    linkedEntities?: LinkedEntity[];
    entityPreviews?: Record<string, EntityPreview>;
    /** Clock time rendered bottom-right inside the bubble, as Nuru does it. */
    timeLabel?: string;
}) {
    const { textContent, images, files } = parseBody(body);
    const hasText        = textContent.length > 0;
    const hasAttachments = images.length > 0 || files.length > 0;
    const chips          = linkedEntities ?? [];

    return (
        <div className="flex flex-col gap-1">
            {/* Coloured bubble - only rendered when there is text */}
            {hasText && (
                <div className={clsx(
                    "rounded-2xl px-3.5 py-2 text-sm leading-relaxed break-words",
                    // Nuru's card colours: own = the dark neutral (their navy → our
                    // surface-900), everyone else = a white card on the tinted canvas.
                    // The brand amber stays an ACCENT (send, chips, reply rule) and is
                    // never a bubble fill — a page of amber blocks is what went wrong.
                    isOwn
                        ? "bg-surface-900 text-white"
                        : "bg-white border border-surface-200 text-surface-800",
                    // Tail on the BOTTOM corner, pointing at the speaker's side.
                    isOwn ? "rounded-br-[5px]" : "rounded-bl-[5px]",
                )}>
                    <RenderTextOnly body={textContent} isOwn={isOwn} />
                    {/* Entity chips inside the bubble */}
                    {chips.length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-1.5 overflow-x-auto">
                            {chips.map((e, i) => <EntityChipWithPreview key={i} entity={e} isOwn={isOwn} />)}
                        </div>
                    )}
                    {timeLabel && (
                        <div className={clsx(
                            "flex items-center justify-end mt-1 text-2xs tabular-nums",
                            isOwn ? "text-white/60" : "text-surface-400",
                        )}>{timeLabel}</div>
                    )}
                </div>
            )}

            {/* Entity chips when message is attachment-only (no text bubble) */}
            {!hasText && chips.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {chips.map((e, i) => <EntityChipWithPreview key={i} entity={e} isOwn={isOwn} />)}
                </div>
            )}

            {/* Attachments - no bubble background, rendered as plain thumbnails / file chips */}
            {hasAttachments && (
                <div className="flex flex-col gap-1">
                    {images.map((img, i) => (
                        <AuthImage key={`img${i}`} url={img.url} alt={img.name} isOwn={isOwn} />
                    ))}
                    {files.map((f, i) => (
                        <AuthFileLink key={`file${i}`} url={f.url} name={f.name} isOwn={isOwn} />
                    ))}
                </div>
            )}
        </div>
    );
}

// Text-only renderer (no attachment parsing - already stripped by parseBody)
function RenderTextOnly({ body, isOwn }: { body: string; isOwn: boolean }) {
    // Belt-and-suspenders: strip any entity tokens not already removed by parseBody
    const cleanBody = body.replace(/#\[([^\]]+)\]\(entity:[^)]+\)/g, "");
    const parts = cleanBody.split(/(@\[[^\]]+\]\(user:\d+\))/g);
    const nodes: React.ReactNode[] = [];
    parts.forEach((part, i) => {
        const mention = part.match(/^@\[([^\]]+)\]\(user:(\d+)\)$/);
        if (mention) {
            nodes.push(
                <span key={`m${i}`} className={clsx(
                    "font-semibold px-1 py-0.5 rounded text-xs",
                    isOwn ? "bg-white/20 text-white" : "bg-brand-100 text-brand-700"
                )}>@{mention[1]}</span>
            );
        } else {
            nodes.push(...renderInlineMarkdown(part, isOwn, `t${i}`));
        }
    });
    return <>{nodes}</>;
}

// ─── Message bubble ───────────────────────────────────────────────────────────

function MessageBubble({ message, channelId, currentUserId, onReply, onDeleted, onReaction, continued = false }: {
    message: ChannelMessage; channelId: number; currentUserId?: number;
    onReply: (m: ChannelMessage) => void; onDeleted: (id: number) => void;
    /** Called with the server-confirmed reactions map after a successful toggle. */
    onReaction: (messageId: number, reactions: Record<string, number[]>) => void;
    /** Same author, moments after the previous message: drop the avatar and header. */
    continued?: boolean;
}) {
    const [hovering, setHovering]           = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const isOwn    = message.user?.id === currentUserId;
    const isSystem = message.type === "system";
    // Backend (ChannelController::deleteMessage) allows the author OR
    // admin/super_admin to delete any message - the "other people's
    // messages" toolbar below previously had no delete option at all, so
    // admins had no way to moderate messages through the UI even though
    // the API already supports it.
    const { isAdmin } = usePermissions();

    const deleteMutation = useMutation({
        mutationFn: () => channelApi.deleteMessage(channelId, message.id),
        onSuccess: () => onDeleted(message.id),
    });
    const reactMutation = useMutation({
        mutationFn: (emoji: string) => channelApi.react(channelId, message.id, emoji),
        // Optimistic update: apply the toggle locally before the server responds
        // so the reactor gets instant feedback even if WebSockets are slow.
        onMutate: (emoji: string) => {
            const prev = message.reactions ?? {};
            const userId = currentUserId ?? -1;
            const users  = prev[emoji] ? [...prev[emoji]] : [];
            const idx    = users.indexOf(userId);
            const next: Record<string, number[]> = { ...prev };
            if (idx !== -1) {
                const filtered = users.filter(id => id !== userId);
                if (filtered.length === 0) delete next[emoji];
                else next[emoji] = filtered;
            } else {
                next[emoji] = [...users, userId];
            }
            onReaction(message.id, next);
            return { previousReactions: prev }; // snapshot for rollback
        },
        onSuccess: (data) => {
            // Replace optimistic state with the authoritative server value.
            // This also handles the case where Reverb is down — the reactor's
            // own UI is always in sync regardless of WebSocket availability.
            onReaction(message.id, data.reactions);
        },
        onError: (_err, _emoji, ctx) => {
            // Roll back to pre-mutation state on network/server error.
            if (ctx?.previousReactions !== undefined) {
                onReaction(message.id, ctx.previousReactions);
            }
        },
    });

    if (isSystem) {
        return (
            <div className="flex justify-center py-2">
                <span className="text-2xs text-surface-500 bg-white px-3 py-1 rounded-full border border-surface-200">{message.body}</span>
            </div>
        );
    }

    return (
        <div className={clsx(
                // Nuru's outline: the row is an end-aligned flex, and the side is
                // chosen by justify — not by reversing the row. Your own messages
                // carry no avatar at all; the position already says they're yours.
                "group relative flex items-end gap-2 px-3 sm:px-4",
                continued ? "py-0.5" : "pt-3 pb-0.5",
                isOwn ? "justify-end" : "justify-start",
            )}
            onMouseEnter={() => setHovering(true)} onMouseLeave={() => setHovering(false)}>
            {!isOwn && (
                continued
                    // Spacer keeps a run's bubbles in one column under the avatar.
                    ? <div className="w-7 shrink-0" aria-hidden />
                    : <Avatar initials={message.user?.initials ?? "?"} name={message.user?.name} />
            )}
            <div className={clsx("relative min-w-0 max-w-[78%]", isOwn && "flex flex-col items-end")}>
                {!isOwn && !continued && (
                    <div className="flex items-baseline gap-2 mb-1 ml-0.5">
                        <span className="text-xs font-bold text-surface-800 tracking-tight">{message.user?.name ?? "Unknown"}</span>
                        <span className="text-2xs text-surface-400 tabular-nums">{fmtTime(message.created_at)}</span>
                        {message.edited_at && <span className="text-2xs text-surface-400 italic">(edited)</span>}
                    </div>
                )}
                {(isOwn || continued) && message.edited_at && (
                    <span className={clsx("text-2xs text-surface-400 italic mb-0.5", isOwn && "self-end")}>(edited)</span>
                )}
                {message.reply_to && (
                    <div className={clsx(
                        // Gold left rule is Nuru's marker for a quote; the brand amber
                        // earns its place as an accent here rather than as a fill.
                        "mb-1 pl-2.5 pr-3 py-1 rounded-lg bg-white border border-surface-200 border-l-2 border-l-brand-500 text-xs text-surface-500 max-w-xs",
                        isOwn && "self-end",
                    )}>
                        <span className="font-bold text-surface-700">{message.reply_to.user_name}</span>
                        {/* One line only: the quote is a pointer back, not a re-read. */}
                        <span className="block truncate">{stripMarkdown(message.reply_to.body)}</span>
                    </div>
                )}
                {/* Bubble + hover toolbar */}
                <div className="flex items-end gap-1.5 relative">
                    {/* Toolbar. ABSOLUTE, deliberately: it used to be an in-flow sibling
                        of the bubble in this row, and at ~197px wide with shrink-0 it ate
                        most of the 78% the bubble was allowed — leaving ~120px, so long
                        messages wrapped one word per line. opacity-0 hides a thing; it
                        does not un-reserve its space. Out of flow, the bubble gets the
                        full width and still hugs short text.
                        Also sm:-gated: it is hover-only, and a touch screen has no hover,
                        so on a phone it was stealing that width for something that could
                        never be shown. */}
                    {isOwn && (
                        <div className={clsx(
                            // Anchored ABOVE the bubble, not beside it: beside would run
                            // ~197px past the row and re-open horizontal scroll on a phone.
                            // right-0 grows leftwards into empty canvas, so it can't overflow.
                            "absolute bottom-full mb-1 right-0 z-10",
                            "flex items-center gap-0.5 bg-white border border-surface-200 rounded-xl shadow-lg px-1.5 py-1 transition-opacity duration-100",
                            hovering ? "opacity-100" : "opacity-0 pointer-events-none"
                        )}>
                            {["👍","❤️","😄","🎉"].map(e => (
                                <button key={e} onClick={() => reactMutation.mutate(e)}
                                    className="text-sm w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-100 hover:scale-125 transition-all">{e}</button>
                            ))}
                            <div className="w-px h-4 bg-surface-200 mx-0.5" />
                            <button onClick={() => onReply(message)} title="Reply"
                                className="w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-brand-600 hover:bg-surface-100 transition-colors">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6"/>
                                </svg>
                            </button>
                            <button onClick={() => setConfirmDelete(true)} title="Delete"
                                className="w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-danger hover:bg-danger/10 transition-colors"
                                aria-label="Delete">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>
                                </svg>
                            </button>
                        </div>
                    )}
                    <MessageContent body={message.body} isOwn={isOwn} timeLabel={fmtClock(message.created_at)}
                        linkedEntities={message.linked_entities} entityPreviews={message.entity_previews} />
                    {/* Same as above, mirrored to the right of the bubble. */}
                    {!isOwn && (
                        <div className={clsx(
                            // Mirrored: grows rightwards from the row's left edge, which
                            // starts after the avatar — so it also stays on-screen.
                            "absolute bottom-full mb-1 left-0 z-10",
                            "flex items-center gap-0.5 bg-white border border-surface-200 rounded-xl shadow-lg px-1.5 py-1 transition-opacity duration-100",
                            hovering ? "opacity-100" : "opacity-0 pointer-events-none"
                        )}>
                            {["👍","❤️","😄","🎉"].map(e => (
                                <button key={e} onClick={() => reactMutation.mutate(e)}
                                    className="text-sm w-7 h-7 flex items-center justify-center rounded-lg hover:bg-surface-100 hover:scale-125 transition-all">{e}</button>
                            ))}
                            <div className="w-px h-4 bg-surface-200 mx-0.5" />
                            <button onClick={() => onReply(message)} title="Reply"
                                className="w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-brand-600 hover:bg-surface-100 transition-colors">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6"/>
                                </svg>
                            </button>
                            {isAdmin && (
                                <button onClick={() => setConfirmDelete(true)} title="Delete (admin)"
                                    className="w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-danger hover:bg-danger/10 transition-colors"
                                    aria-label="Delete">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>
                                    </svg>
                                </button>
                            )}
                        </div>
                    )}
                </div>
                {Object.keys(message.reactions ?? {}).length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-1">
                        {Object.entries(message.reactions).map(([emoji, users]) => (
                            <button key={emoji} onClick={() => reactMutation.mutate(emoji)}
                                className={clsx("flex items-center gap-1 px-2 py-0.5 rounded-full text-xs border shadow-sm transition-all active:scale-95",
                                    users.includes(currentUserId ?? -1)
                                        ? "bg-brand-50 border-brand-300 text-brand-700 ring-1 ring-brand-200"
                                        : "bg-white border-surface-200 text-surface-600 hover:border-brand-300 hover:bg-brand-50/40")}>
                                {emoji} <span className="font-semibold tabular-nums">{users.length}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
            {confirmDelete && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
                    onMouseDown={e => { if (e.target === e.currentTarget) setConfirmDelete(false); }}>
                    <div className="bg-white rounded-2xl shadow-2xl p-5 w-72 flex flex-col gap-4">
                        <p className="text-sm font-semibold text-surface-900">Delete this message?</p>
                        <p className="text-xs text-surface-500">This action cannot be undone.</p>
                        <div className="flex gap-2 mt-1">
                            <button onClick={() => setConfirmDelete(false)}
                                className="btn-secondary flex-1 text-xs">Cancel</button>
                            <button onClick={() => { setConfirmDelete(false); deleteMutation.mutate(); }}
                                disabled={deleteMutation.isPending}
                                className="flex-1 text-xs py-2 rounded-lg bg-danger text-white font-semibold hover:bg-danger/90 disabled:opacity-50 transition-colors"
                                aria-label="Delete">
                                {deleteMutation.isPending ? "Deleting…" : "Delete"}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Live order context for context-scoped channels ─────────────────────────
//
// Fetches real-time order data for the channel header and sidebar so context
// is always fresh regardless of when the channel was first created.

function useOrderContext(channel: Channel) {
    const isProd  = channel.context_type === "production_order";
    const isOrder = channel.context_type === "order";
    const id      = channel.context_id;

    const { data: prodData } = useQuery({
        queryKey: ["comms-prod-context", id],
        queryFn: () => get<{ order: any }>(`/v1/admin/production-orders/${id}`),
        enabled: isProd && !!id,
        staleTime: 60_000,
    });

    const { data: orderData } = useQuery({
        queryKey: ["comms-order-context", id],
        queryFn: () => get<{ order: any }>(`/v1/admin/orders/${id}`),
        enabled: isOrder && !!id,
        staleTime: 60_000,
    });

    if (isProd && prodData) {
        const o = (prodData as any).order;
        return {
            type:        "production_order" as const,
            orderNumber: o.order_number,
            title:       o.product_name,
            status:      o.status,
            priority:    o.priority,
            dueDate:     o.due_date,
            customer:    o.customer ? `${o.customer.first_name} ${o.customer.last_name}` : null,
            isCustomer:  !!o.customer_order_id,
            completion:  o.completion_percentage,
            currentStage: o.current_stage,
        };
    }

    if (isOrder && orderData) {
        const o = (orderData as any).order ?? (orderData as any);
        // OrderController::show returns customer_name as a pre-joined string,
        // not customer_first_name / customer_last_name separately.
        const customerName = o.customer_name ?? (
            o.customer_first_name
                ? `${o.customer_first_name} ${o.customer_last_name ?? ""}`.trim()
                : null
        );
        return {
            type:        "order" as const,
            orderNumber: o.order_number,
            title:       customerName,
            status:      o.status,
            priority:    null,
            dueDate:     null,
            customer:    customerName,
            isCustomer:  false,
            completion:  null,
            currentStage: o.payment_status ?? null,
        };
    }

    return null;
}

// Status colour map (shared for production orders in the header)
const PROD_STATUS_COLOURS: Record<string, { text: string; bg: string }> = {
    draft:       { text: "text-surface-500",  bg: "bg-surface-100" },
    pending:     { text: "text-amber-700",    bg: "bg-amber-50"    },
    in_progress: { text: "text-brand-700",    bg: "bg-brand-50"    },
    on_hold:     { text: "text-orange-700",   bg: "bg-orange-50"   },
    qc_pending:  { text: "text-purple-700",   bg: "bg-purple-50"   },
    qc_passed:   { text: "text-emerald-700",  bg: "bg-emerald-50"  },
    qc_failed:   { text: "text-red-700",      bg: "bg-red-50"      },
    completed:   { text: "text-emerald-700",  bg: "bg-emerald-100" },
    cancelled:   { text: "text-surface-400",  bg: "bg-surface-100" },
    processing:  { text: "text-brand-700",    bg: "bg-brand-50"    },
    shipped:     { text: "text-blue-700",     bg: "bg-blue-50"     },
    delivered:   { text: "text-emerald-700",  bg: "bg-emerald-50"  },
};

const PRIORITY_COLOURS: Record<string, string> = {
    low:    "text-surface-400",
    normal: "text-brand-600",
    high:   "text-warning-dark",
    urgent: "text-danger",
};

function fmtDueDate(iso: string): { label: string; cls: string } {
    const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
    if (days < 0)  return { label: `${Math.abs(days)}d overdue`, cls: "text-danger font-semibold" };
    if (days === 0) return { label: "Due today",                  cls: "text-warning-dark font-semibold" };
    if (days <= 3)  return { label: `Due in ${days}d`,            cls: "text-warning-dark" };
    return { label: new Date(iso).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" }), cls: "text-surface-500" };
}

// ─── Space settings panel ─────────────────────────────────────────────────────

function SpaceSettings({ channel, currentUserId, onClose }: {
    channel: Channel; currentUserId?: number; onClose: () => void;
}) {
    const qc = useQueryClient();
    const [name, setName]         = useState(channel.name);
    const [desc, setDesc]         = useState(channel.description ?? "");
    const [isPrivate, setIsPrivate] = useState(channel.is_private);
    const [addOpen, setAddOpen]   = useState(false);
    const [newMembers, setNewMembers] = useState<MentionUser[]>([]);
    const [confirmArchive, setConfirmArchive] = useState(false);
    const [confirmRemove, setConfirmRemove]   = useState<ChannelUser | null>(null);

    const members = Array.isArray(channel.members) ? (channel.members as ChannelUser[]) : [];

    const updateMutation = useMutation({
        mutationFn: () => channelApi.update(channel.id, { name, description: desc }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["channels"] }),
    });

    const addMembersMutation = useMutation({
        mutationFn: async () => {
            for (const u of newMembers) await channelApi.addMember(channel.id, u.id);
        },
        onSuccess: () => {
            setNewMembers([]); setAddOpen(false);
            qc.invalidateQueries({ queryKey: ["channels"] });
            qc.invalidateQueries({ queryKey: ["channel-detail", channel.id] });
        },
    });

    const removeMutation = useMutation({
        mutationFn: (uid: number) => channelApi.removeMember(channel.id, uid),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["channel-detail", channel.id] }),
    });

    const archiveMutation = useMutation({
        mutationFn: () => channelApi.delete(channel.id),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ["channels"] }); onClose(); },
    });

    const existingIds = members.map(m => m.id);

    return (
        <div className="w-72 shrink-0 border-l border-surface-100 bg-white flex flex-col overflow-y-auto">
            <div className="flex items-center justify-between px-4 py-3.5 border-b border-surface-100 shrink-0">
                <h3 className="text-sm font-bold text-surface-900">Space Settings</h3>
                <button onClick={onClose} className="text-surface-400 hover:text-surface-700 p-1 rounded transition-colors"
aria-label="Close">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div className="p-4 space-y-5 flex-1 overflow-y-auto">
                {/* Name & description */}
                <div className="space-y-3">
                    <div>
                        <label className="label text-xs">Space name</label>
                        <input className="input" value={name} onChange={e => setName(e.target.value)} maxLength={80} />
                    </div>
                    <div>
                        <label className="label text-xs">Description</label>
                        <textarea className="input resize-none" rows={2} value={desc} onChange={e => setDesc(e.target.value)} maxLength={500} />
                    </div>
                    <button onClick={() => updateMutation.mutate()} disabled={updateMutation.isPending || !name.trim()}
                        className="btn-primary w-full text-xs">
                        {updateMutation.isPending ? "Saving…" : "Save changes"}
                    </button>
                </div>

                {/* Privacy */}
                <div className={clsx("flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors",
                    isPrivate ? "border-amber-300 bg-amber-50" : "border-surface-200 bg-surface-50")}
                    onClick={() => {
                        const next = !isPrivate;
                        setIsPrivate(next);
                        channelApi.update(channel.id, { is_private: next })
                            .then(() => qc.invalidateQueries({ queryKey: ["channels"] }))
                            .catch(() => setIsPrivate(isPrivate)); // rollback on error
                    }}>
                    <div className={clsx("w-8 h-8 rounded-lg flex items-center justify-center shrink-0",
                        isPrivate ? "bg-amber-100 text-amber-600" : "bg-white text-surface-400")}>
                        {isPrivate
                            ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg>
                        }
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-xs font-semibold text-surface-800">{isPrivate ? "Private - invite only" : "Public - visible to all staff"}</p>
                        <p className="text-2xs text-surface-400 mt-0.5">
                            {isPrivate ? "Only members you invite can see this space." : "Any staff member can find and join this space."}
                        </p>
                    </div>
                    <div className={clsx("relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors mt-0.5",
                        isPrivate ? "bg-amber-400" : "bg-surface-200")}>
                        <span className={clsx("inline-block h-4 w-4 rounded-full bg-white shadow transition-transform", isPrivate ? "translate-x-4" : "translate-x-0")} />
                    </div>
                </div>

                {/* Members */}
                <div>
                    <div className="flex items-center justify-between mb-2">
                        <p className="text-xs font-semibold text-surface-700">Members ({members.length})</p>
                        <button onClick={() => setAddOpen(v => !v)} className="text-2xs text-brand-600 hover:underline font-semibold">
                            + Add members
                        </button>
                    </div>

                    {addOpen && (
                        <div className="mb-3 p-3 bg-surface-50 rounded-xl border border-surface-200">
                            <MemberPicker
                                selected={newMembers}
                                onToggle={u => setNewMembers(prev => prev.some(p => p.id === u.id) ? prev.filter(p => p.id !== u.id) : [...prev, u])}
                                excludeIds={existingIds}
                                label="Select members"
                            />
                            <div className="flex gap-2 mt-3">
                                <button onClick={() => { setAddOpen(false); setNewMembers([]); }} className="btn-secondary flex-1 text-xs">Cancel</button>
                                <button onClick={() => addMembersMutation.mutate()} disabled={!newMembers.length || addMembersMutation.isPending}
                                    className="btn-primary flex-1 text-xs">
                                    {addMembersMutation.isPending ? "Adding…" : `Add ${newMembers.length || ""}`}
                                </button>
                            </div>
                        </div>
                    )}

                    <div className="space-y-0.5">
                        {members.map(m => (
                            <div key={m.id} className="flex items-center gap-2.5 px-2 py-1.5 rounded-lg hover:bg-surface-50 group/member">
                                <Avatar initials={m.initials} name={m.name} size="xs" />
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-medium text-surface-800 truncate">{m.name}</p>
                                    {m.role === "admin" && <span className="text-2xs text-brand-600 font-semibold">admin</span>}
                                </div>
                                {m.id !== currentUserId && (
                                    <button onClick={() => setConfirmRemove(m)}
                                        className="opacity-0 group-hover/member:opacity-100 text-surface-400 hover:text-danger transition-all p-1 rounded"
                                        aria-label="Remove member">
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                {/* Archive */}
                <div className="border border-danger/20 rounded-xl p-3">
                    <p className="text-xs font-semibold text-danger mb-1">Danger zone</p>
                    <p className="text-2xs text-surface-400 mb-2">Archiving hides this space from all members. Messages are preserved.</p>
                    <button onClick={() => setConfirmArchive(true)}
                        disabled={archiveMutation.isPending}
                        className="w-full text-xs py-1.5 rounded-lg border border-danger/30 text-danger hover:bg-danger/5 transition-colors">
                        {archiveMutation.isPending ? "Archiving…" : "Archive space"}
                    </button>
                </div>
            </div>

            {/* ── Archive confirmation modal ── */}
            {confirmArchive && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onMouseDown={e => { if (e.target === e.currentTarget) setConfirmArchive(false); }}>
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col gap-4">
                        <div className="flex items-start gap-3">
                            <div className="w-10 h-10 rounded-full bg-danger/10 flex items-center justify-center shrink-0">
                                <svg className="w-5 h-5 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4M14 12v4"/>
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-surface-900">Archive this space?</h3>
                                <p className="text-xs text-surface-500 mt-1">
                                    <span className="font-semibold text-surface-700">{channel.name}</span> will be hidden from all members.
                                    All messages are preserved and can be restored by an admin.
                                </p>
                            </div>
                        </div>
                        <div className="flex gap-2 pt-1">
                            <button onClick={() => setConfirmArchive(false)}
                                className="btn-secondary flex-1 text-sm">
                                Cancel
                            </button>
                            <button
                                onClick={() => { setConfirmArchive(false); archiveMutation.mutate(); }}
                                disabled={archiveMutation.isPending}
                                className="flex-1 text-sm py-2 rounded-xl bg-danger text-white font-semibold hover:bg-danger/90 disabled:opacity-50 transition-colors">
                                {archiveMutation.isPending ? "Archiving…" : "Archive space"}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ── Remove member confirmation modal ── */}
            {confirmRemove && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onMouseDown={e => { if (e.target === e.currentTarget) setConfirmRemove(null); }}>
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col gap-4">
                        <div className="flex items-start gap-3">
                            <div className="w-10 h-10 rounded-full bg-danger/10 flex items-center justify-center shrink-0">
                                <svg className="w-5 h-5 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"/>
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-surface-900">Remove member?</h3>
                                <p className="text-xs text-surface-500 mt-1">
                                    <span className="font-semibold text-surface-700">{confirmRemove.name}</span> will lose access to{" "}
                                    <span className="font-semibold text-surface-700">{channel.name}</span> and won't receive new messages.
                                </p>
                            </div>
                        </div>
                        <div className="flex gap-2 pt-1">
                            <button onClick={() => setConfirmRemove(null)}
                                className="btn-secondary flex-1 text-sm">
                                Cancel
                            </button>
                            <button
                                onClick={() => { const id = confirmRemove.id; setConfirmRemove(null); removeMutation.mutate(id); }}
                                disabled={removeMutation.isPending}
                                className="flex-1 text-sm py-2 rounded-xl bg-danger text-white font-semibold hover:bg-danger/90 disabled:opacity-50 transition-colors">
                                {removeMutation.isPending ? "Removing…" : "Remove member"}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Channel view ─────────────────────────────────────────────────────────────

function ChannelView({ channel, onOpenSidebar }: { channel: Channel; onOpenSidebar: () => void }) {
    const { user }   = useAuthStore();
    const qc         = useQueryClient();
    const navigate   = useNavigate();
    const [messages, setMessages]       = useState<ChannelMessage[]>([]);
    const [hasMore, setHasMore]         = useState(false);
    const [replyTo, setReplyTo]         = useState<ChannelMessage | null>(null);
    const [typing, setTyping]           = useState<string[]>([]);
    const [onlineIds, setOnlineIds]     = useState<number[]>([]);
    const [showSettings, setShowSettings] = useState(false);
    const bottomRef  = useRef<HTMLDivElement>(null);
    const scrollAreaRef = useRef<HTMLDivElement>(null);
    const timers     = useRef<Record<string, ReturnType<typeof setTimeout>>>({});
    // Live order data for context channels — always fresh, never stale from creation time
    const orderCtx   = useOrderContext(channel);

    // Load detail for settings panel AND for @mention membership guard.
    // Always enabled so channelMemberIds is populated before the user types @.
    const { data: detail } = useQuery({
        queryKey: ["channel-detail", channel.id],
        queryFn: () => channelApi.get(channel.id),
        staleTime: 60_000,
    });
    const channelFull = detail?.channel ?? channel;

    // Build a Set of member IDs for O(1) lookup in the mention guard.
    const channelMemberIds = new Set(
        Array.isArray(channelFull.members)
            ? (channelFull.members as ChannelUser[]).map(m => m.id)
            : []
    );

    const { isLoading } = useQuery({
        queryKey: ["channel-messages", channel.id],
        queryFn: async () => {
            const data = await channelApi.messages(channel.id);
            setMessages(data.messages);
            setHasMore(data.has_more);
            return data;
        },
        staleTime: 0,
    });

    useEffect(() => {
        subscribeToChannel(channel.id, msg => {
            setMessages(prev => { const m2 = msg as unknown as ChannelMessage; return prev.some(m => String(m.id) === String(m2.id)) ? prev : [...prev, m2]; });
        });

        // Reaction updates: patch only the reactions field of the affected message.
        // This fires for every channel member, including the reactor themselves
        // (server-confirmed state), so it naturally syncs all open tabs/windows.
        subscribeToReaction(channel.id, ({ message_id, reactions }) => {
            setMessages(prev =>
                prev.map(m => m.id === message_id ? { ...m, reactions } : m)
            );
        });

        joinPresenceChannel(channel.id, {
            onHere:    ms  => setOnlineIds((ms as any[]).map(m => m.id)),
            onJoining: m   => setOnlineIds(prev => [...prev, (m as any).id]),
            onLeaving: m   => setOnlineIds(prev => prev.filter(id => id !== (m as any).id)),
            onTyping:  m   => {
                const name = (m as any).name;
                setTyping(prev => prev.includes(name) ? prev : [...prev, name]);
                clearTimeout(timers.current[name]);
                timers.current[name] = setTimeout(() => setTyping(prev => prev.filter(n => n !== name)), 2500);
            },
        });
        channelApi.markRead(channel.id).catch(() => {});
        qc.invalidateQueries({ queryKey: ["channels"] });
        return () => { getEcho().leave(`channel.${channel.id}`); getEcho().leave(`presence.channel.${channel.id}`); };
    }, [channel.id]);

    useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages.length]);

    const isSpace      = channel.type === "space";
    const memberCount  = typeof channel.members === "number" ? channel.members : (channel.members as ChannelUser[]).length;
    const grouped      = groupByDate(messages);

    return (
        <div className="flex h-full overflow-hidden">
            <div className="flex flex-col flex-1 min-w-0">
                {/* Header */}
                <div className="flex items-center gap-2 px-3 sm:px-5 py-3.5 border-b border-surface-200 bg-white shrink-0">
                    {/* Back button - mobile only */}
                    <button onClick={onOpenSidebar}
                        className="sm:hidden w-8 h-8 flex items-center justify-center rounded-lg text-surface-400 hover:text-surface-700 hover:bg-surface-100 transition-colors shrink-0 -ml-1">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <div className={clsx("w-8 h-8 rounded-lg flex items-center justify-center text-xs font-bold shrink-0",
                        channel.is_private ? "bg-amber-50 text-amber-700" : "bg-brand-50 text-brand-700")}>
                        {channel.type === "dm"
                            ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            : channel.is_private ? <LockIcon /> : "#"
                        }
                    </div>
                    <div className="flex-1 min-w-0">
                        {orderCtx ? (
                            /* ── Context channel: live order data ── */
                            <div className="flex flex-col gap-0.5 min-w-0">
                                {/* Row 1: type pill + order number + priority */}
                                <div className="flex items-center gap-1.5 flex-wrap">
                                    <span className={clsx(
                                        "text-2xs font-bold uppercase tracking-wide px-1.5 py-0.5 rounded shrink-0",
                                        orderCtx.type === "production_order"
                                            ? "bg-purple-50 text-purple-600"
                                            : "bg-brand-50 text-brand-600"
                                    )}>
                                        {orderCtx.type === "production_order" ? "Production" : "Order"}
                                    </span>
                                    <span className="text-sm font-bold text-surface-900 font-mono">
                                        {orderCtx.orderNumber}
                                    </span>
                                    {orderCtx.status && (() => {
                                        const sc = PROD_STATUS_COLOURS[orderCtx.status] ?? { text: "text-surface-500", bg: "bg-surface-100" };
                                        return (
                                            <span className={clsx("text-2xs font-semibold px-1.5 py-0.5 rounded-full shrink-0", sc.text, sc.bg)}>
                                                {orderCtx.status.replace(/_/g, " ")}
                                            </span>
                                        );
                                    })()}
                                    {orderCtx.priority && orderCtx.priority !== "normal" && (
                                        <span className={clsx("text-2xs font-bold uppercase shrink-0", PRIORITY_COLOURS[orderCtx.priority] ?? "text-surface-400")}>
                                            {orderCtx.priority}
                                        </span>
                                    )}
                                </div>
                                {/* Row 2: product/customer · stage · due date · members */}
                                <div className="flex items-center gap-2 flex-wrap text-2xs text-surface-500">
                                    {orderCtx.title && (
                                        <span className="font-medium text-surface-700 truncate max-w-[160px]">{orderCtx.title}</span>
                                    )}
                                    {orderCtx.currentStage && (
                                        <>
                                            <span className="text-surface-300">·</span>
                                            <span>{orderCtx.currentStage}</span>
                                        </>
                                    )}
                                    {orderCtx.dueDate && (() => {
                                        const { label, cls } = fmtDueDate(orderCtx.dueDate);
                                        return (
                                            <>
                                                <span className="text-surface-300">·</span>
                                                <span className={cls}>{label}</span>
                                            </>
                                        );
                                    })()}
                                    {orderCtx.completion != null && (
                                        <>
                                            <span className="text-surface-300">·</span>
                                            <span>{orderCtx.completion}% done</span>
                                        </>
                                    )}
                                    <span className="text-surface-300">·</span>
                                    <span>{memberCount} member{memberCount !== 1 ? "s" : ""}{onlineIds.length > 0 ? `, ${onlineIds.length} online` : ""}</span>
                                </div>
                            </div>
                        ) : (
                            /* ── DM or manual space ── */
                            <>
                                <p className="text-sm font-semibold text-surface-900 truncate">{channel.name}</p>
                                <p className="text-2xs text-surface-400">
                                    {channel.type === "dm"
                                        ? onlineIds.length > 0
                                            ? <span className="text-emerald-600 flex items-center gap-1"><span className="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block" /> Online</span>
                                            : "Offline"
                                        : `${memberCount} member${memberCount !== 1 ? "s" : ""}${onlineIds.length > 0 ? ` · ${onlineIds.length} online` : ""}`
                                    }
                                </p>
                            </>
                        )}
                    </div>
                    {/* Settings cog — only for manually created spaces, not system order threads */}
                    {isSpace && !channel.context_type && (
                        <button onClick={() => setShowSettings(v => !v)} title="Space settings"
                            className={clsx("p-2 rounded-lg transition-colors", showSettings ? "bg-surface-100 text-surface-900" : "text-surface-400 hover:bg-surface-100 hover:text-surface-700")}>
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
                            </svg>
                        </button>
                    )}
                    {/* For context channels: link directly to the order instead */}
                    {isSpace && channel.context_type && channel.context_id && (
                        <button
                            onClick={() => navigate(
                                channel.context_type === "production_order"
                                    ? `/production/orders/${channel.context_id}`
                                    : `/sales/orders/${channel.context_id}`
                            )}
                            title="Open order"
                            className="p-2 rounded-lg text-surface-400 hover:bg-surface-100 hover:text-brand-600 transition-colors flex items-center"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                        </button>
                    )}
                </div>

                {/* Messages */}
                {/* Tinted canvas, as Nuru does it (their --background #f6f4ee). White
                    bubbles were previously invisible against a white thread — this is
                    what made the page read flat. brand-50 (#fdf8f0) is this app's own
                    warm cream, so the bubbles now sit ON something. */}
                <div className="flex-1 overflow-y-auto py-4 scroll-touch overscroll-contain bg-brand-50/60">
                    {hasMore && (
                        <div className="flex justify-center pb-2">
                            <button onClick={async () => {
                                if (!messages.length) return;
                                const data = await channelApi.messages(channel.id, messages[0].id);
                                setMessages(prev => [...data.messages, ...prev]);
                                setHasMore(data.has_more);
                            }} className="text-xs text-brand-600 hover:underline px-4 py-1.5 rounded-full bg-brand-50 border border-brand-100">
                                Load earlier messages
                            </button>
                        </div>
                    )}
                    {isLoading ? (
                        <div className="flex items-center justify-center h-full text-sm text-surface-400">Loading…</div>
                    ) : grouped.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-full gap-3 px-6 text-center">
                            <div className="w-14 h-14 rounded-2xl bg-white border border-surface-200 flex items-center justify-center text-brand-500 shadow-sm">
                                <svg className="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-surface-700">No messages yet</p>
                                <p className="text-xs text-surface-400 mt-0.5">
                                    Say hello — or type <span className="font-mono text-2xs bg-surface-100 text-surface-600 px-1 py-0.5 rounded">#</span> to link an order.
                                </p>
                            </div>
                        </div>
                    ) : grouped.map(({ date, messages: msgs }) => (
                        <div key={date}>
                            {/* Sticky pill: scroll back through a long day and you never
                                lose track of which day you're reading. */}
                            <div className="sticky top-0 z-10 flex justify-center py-2 pointer-events-none">
                                <span className="text-2xs font-bold text-surface-500 bg-white/90 backdrop-blur-sm px-3 py-1 rounded-full border border-surface-200 shadow-sm">
                                    {date}
                                </span>
                            </div>
                            {msgs.map((msg, i) => (
                                <MessageBubble key={msg.id} message={msg} channelId={channel.id}
                                    continued={isContinuation(msg, msgs[i - 1])}
                                    currentUserId={user?.id} onReply={setReplyTo}
                                    onDeleted={id => setMessages(prev => prev.filter(m => m.id !== id))}
                                    onReaction={(msgId, reactions) =>
                                        setMessages(prev => prev.map(m => m.id === msgId ? { ...m, reactions } : m))
                                    } />
                            ))}
                        </div>
                    ))}
                    {typing.filter(n => n !== (user?.first_name ?? "")).length > 0 && (
                        <div className="flex items-center gap-2 px-4 py-2">
                            {/* Three breathing dots read as "someone is there" far faster
                                than the word "typing" ever does. */}
                            <span className="flex items-center gap-1 bg-white border border-surface-100 rounded-full px-2.5 py-1.5 shadow-sm">
                                {[0, 150, 300].map(delay => (
                                    <span key={delay} className="w-1.5 h-1.5 rounded-full bg-surface-300 animate-bounce"
                                        style={{ animationDelay: `${delay}ms`, animationDuration: "1s" }} />
                                ))}
                            </span>
                            <span className="text-xs text-surface-400">
                                {typing.join(", ")} {typing.length === 1 ? "is" : "are"} typing…
                            </span>
                        </div>
                    )}
                    <div ref={bottomRef} />
                </div>

                <Composer
                    channelId={channel.id}
                    channelName={channel.name}
                    channelType={channel.type}
                    channelMemberIds={channelMemberIds}
                    replyTo={replyTo}
                    onClearReply={() => setReplyTo(null)}
                    onSent={msg => { setMessages(prev => prev.some(m => String(m.id) === String(msg.id)) ? prev : [...prev, msg]); setTimeout(() => bottomRef.current?.scrollIntoView({ behavior: "smooth" }), 50); }}
                    bottomRef={bottomRef} />
            </div>

            {showSettings && isSpace && (
                <SpaceSettings channel={channelFull} currentUserId={user?.id} onClose={() => setShowSettings(false)} />
            )}
        </div>
    );
}

// ─── New Space modal ──────────────────────────────────────────────────────────

function NewSpaceModal({ onClose, onCreated, currentUserId }: {
    onClose: () => void; onCreated: (c: Channel) => void; currentUserId?: number;
}) {
    const [name, setName]         = useState("");
    const [desc, setDesc]         = useState("");
    const [isPrivate, setIsPrivate] = useState(false);
    const [members, setMembers]   = useState<MentionUser[]>([]);

    const mutation = useMutation({
        mutationFn: () => channelApi.create({ name, description: desc || undefined, is_private: isPrivate, member_ids: members.map(m => m.id) }),
        onSuccess: data => { onCreated(data.channel); onClose(); },
    });

    const toggleMember = (u: MentionUser) =>
        setMembers(prev => prev.some(p => p.id === u.id) ? prev.filter(p => p.id !== u.id) : [...prev, u]);

    return (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
                <h2 className="text-base font-bold text-surface-900 mb-4">New Space</h2>
                <div className="space-y-4">
                    <div>
                        <label className="label text-xs">Name <span className="text-danger">*</span></label>
                        <input className="input" value={name} onChange={e => setName(e.target.value)} placeholder="e.g. production-team" maxLength={80} />
                    </div>
                    <div>
                        <label className="label text-xs">Description</label>
                        <input className="input" value={desc} onChange={e => setDesc(e.target.value)} placeholder="What's this space for?" maxLength={500} />
                    </div>

                    {/* Privacy toggle */}
                    <div className={clsx("flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors",
                        isPrivate ? "border-amber-300 bg-amber-50" : "border-surface-200 bg-surface-50")}
                        onClick={() => setIsPrivate(v => !v)}>
                        <div className={clsx("w-8 h-8 rounded-lg flex items-center justify-center shrink-0",
                            isPrivate ? "bg-amber-100 text-amber-600" : "bg-white text-surface-400")}>
                            {isPrivate
                                ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                                : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg>
                            }
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-surface-800">{isPrivate ? "Private - invite only" : "Public - visible to all staff"}</p>
                            <p className="text-2xs text-surface-400 mt-0.5">
                                {isPrivate ? "Only members you add can see and join this space." : "Any staff member can find and join this space."}
                            </p>
                        </div>
                        <div className={clsx("relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors mt-0.5",
                            isPrivate ? "bg-amber-400" : "bg-surface-200")}>
                            <span className={clsx("inline-block h-4 w-4 rounded-full bg-white shadow transition-transform", isPrivate ? "translate-x-4" : "translate-x-0")} />
                        </div>
                    </div>

                    <MemberPicker selected={members} onToggle={toggleMember}
                        excludeIds={currentUserId ? [currentUserId] : []} label="Add members (optional)" />
                </div>
                <div className="flex gap-3 mt-5">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={!name.trim() || mutation.isPending} className="btn-primary flex-1">
                        {mutation.isPending ? "Creating…" : "Create Space"}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ─── User directory ───────────────────────────────────────────────────────────

function UserDirectory({ onOpenDm, onClose }: { onOpenDm: (id: number) => void; onClose: () => void }) {
    const [q, setQ]        = useState("");
    const { user }         = useAuthStore();
    const qc               = useQueryClient();
    const { data: all = [], isLoading } = useAllStaff();
    const { data: searched = [] } = useStaffSearch(q);
    const list = q.trim() ? searched : all;

    const dmMutation = useMutation({
        mutationFn: (userId: number) => channelApi.openDm(userId),
        onSuccess: data => { qc.invalidateQueries({ queryKey: ["channels"] }); onOpenDm(data.channel.id); onClose(); },
    });

    return (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm max-h-[70vh] flex flex-col">
                <div className="flex items-center justify-between px-4 py-3.5 border-b border-surface-100 shrink-0">
                    <h3 className="text-sm font-bold text-surface-900">New Message</h3>
                    <button onClick={onClose} className="text-surface-400 hover:text-surface-700 p-1 rounded"
aria-label="Close">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div className="px-4 py-2 border-b border-surface-100 shrink-0">
                    <input className="input" value={q} onChange={e => setQ(e.target.value)} placeholder="Search by name or email…" autoFocus />
                </div>
                <div className="flex-1 overflow-y-auto py-2">
                    {isLoading ? (
                        <div className="flex items-center justify-center py-8 text-sm text-surface-400">Loading…</div>
                    ) : list.filter(u => u.id !== user?.id).length === 0 ? (
                        <div className="flex items-center justify-center py-8 text-sm text-surface-400">No users found</div>
                    ) : list.filter(u => u.id !== user?.id).map(u => (
                        <button key={u.id} onClick={() => dmMutation.mutate(u.id)} disabled={dmMutation.isPending}
                            className="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 transition-colors text-left">
                            <div className="w-9 h-9 rounded-full bg-brand-500/15 flex items-center justify-center shrink-0">
                                <span className="text-brand-600 text-sm font-bold">{u.initials}</span>
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-semibold text-surface-800 truncate">{u.name}</p>
                                <p className="text-xs text-surface-400 truncate">{u.email}</p>
                            </div>
                            <svg className="w-4 h-4 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}

// ─── Sidebar section ──────────────────────────────────────────────────────────

function SidebarSection({ label, onAdd, addTitle, children, collapsed, onToggleCollapse, count }: {
    label: string; onAdd?: () => void; addTitle?: string; children: React.ReactNode;
    collapsed?: boolean; onToggleCollapse?: () => void; count?: number;
}) {
    return (
        <div className="px-3">
            <div className="flex items-center justify-between px-1 py-1.5">
                <button
                    onClick={onToggleCollapse}
                    className="flex items-center gap-1.5 min-w-0 text-left group"
                    disabled={!onToggleCollapse}
                >
                    {onToggleCollapse && (
                        <svg className={clsx("w-3 h-3 text-surface-400 shrink-0 transition-transform", !collapsed && "rotate-90")}
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    )}
                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">{label}</p>
                    {count !== undefined && count > 0 && (
                        <span className="text-2xs text-surface-400 font-normal">({count})</span>
                    )}
                </button>
                {onAdd && (
                    <button onClick={onAdd} title={addTitle}
                        className="w-5 h-5 rounded flex items-center justify-center text-surface-400 hover:text-brand-600 hover:bg-brand-50 transition-colors">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16M4 12h16"/>
                        </svg>
                    </button>
                )}
            </div>
            {!collapsed && children}
        </div>
    );
}

// ─── Order thread item ────────────────────────────────────────────────────────
// Specialised sidebar item for context-scoped channels (production orders, orders).
// Shows a richer layout: type icon, order number, product/customer subtitle, and
// last message — giving enough context to identify the order without opening it.

function OrderThreadItem({ channel, active, onClick, onDismiss }: {
    channel: Channel; active: boolean; onClick: () => void; onDismiss: () => void;
}) {
    const unread = channel.unread_count ?? 0;

    // Parse display info from context fields and name.
    // Name format from backend: "PRD · PO-260527-RF3FM" or "Order · ORD-1234"
    const isProd    = channel.context_type === "production_order";
    const nameParts = channel.name.split("·").map(s => s.trim());
    const orderNum  = nameParts[1] ?? nameParts[0] ?? channel.name;
    // description is set by the backend on create/refresh with product·status·due
    // For older channels that were created before this was added, it will be null —
    // fall back gracefully to just the last message preview in that case.
    const subtitle  = channel.description ?? null;

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={onClick}
            onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") onClick(); }}
            className={clsx(
                "w-full text-left px-2 py-2 rounded-lg transition-colors mb-0.5 group relative cursor-pointer",
                active ? "bg-brand-500/10" : "hover:bg-surface-100"
            )}
        >
            {/* Dismiss button — hover/focus only, stops propagation so it
                doesn't also select the thread. Order/production threads only;
                see backend dismiss() guard. */}
            <button
                type="button"
                onClick={(e) => { e.stopPropagation(); onDismiss(); }}
                title="Dismiss thread (reappears on new message)"
                className="absolute top-1.5 right-1.5 w-5 h-5 rounded-md flex items-center justify-center text-surface-300 hover:text-danger hover:bg-danger/10 opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity z-10"
            >
                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>

            <div className="flex items-start gap-2">
                {/* Type icon */}
                <div className={clsx(
                    "w-7 h-7 rounded-lg flex items-center justify-center shrink-0 mt-0.5",
                    isProd
                        ? active ? "bg-purple-100 text-purple-700" : "bg-purple-50 text-purple-600"
                        : active ? "bg-brand-100 text-brand-700"  : "bg-brand-50 text-brand-600"
                )}>
                    {isProd ? (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                        </svg>
                    ) : (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    )}
                </div>

                <div className="flex-1 min-w-0">
                    {/* Type label + order number */}
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <span className={clsx(
                            "text-2xs font-bold uppercase tracking-wide shrink-0",
                            active ? (isProd ? "text-purple-600" : "text-brand-600") : "text-surface-400"
                        )}>
                            {isProd ? "Prod" : "Order"}
                        </span>
                        <span className={clsx(
                            "text-xs font-bold font-mono truncate",
                            active ? "text-brand-700" : "text-surface-800"
                        )}>
                            {orderNum}
                        </span>
                        {unread > 0 && (
                            <span className="shrink-0 min-w-[16px] h-4 px-1 rounded-full bg-brand-600 text-white text-2xs font-bold flex items-center justify-center ml-auto">
                                {unread > 99 ? "99+" : unread}
                            </span>
                        )}
                    </div>

                    {/* Subtitle: product/customer·status·due (from description) */}
                    {subtitle && (
                        <p className="text-2xs text-surface-500 truncate mt-0.5 leading-tight">{subtitle}</p>
                    )}

                    {/* Last message preview */}
                    {channel.last_message ? (
                        <p className="text-2xs text-surface-400 truncate mt-0.5">
                            {channel.last_message.user_name
                                ? <span className="font-medium text-surface-500">{channel.last_message.user_name.split(" ")[0]}: </span>
                                : null
                            }
                            {stripMarkdown(channel.last_message.body)}
                        </p>
                    ) : (
                        <p className="text-2xs text-surface-300 truncate mt-0.5 italic">No messages yet</p>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Order threads section ────────────────────────────────────────────────────
// Manages the collapsible, searchable list of context-scoped order channels.
// Shows only channels with recent activity by default; "Show all" reveals the rest.

const ORDER_THREADS_RECENT_DAYS = 14; // channels active within this window shown by default

function OrderThreadsSection({ channels, activeId, onSelect, onDismiss, recentlyDismissed, onUndismiss }: {
    channels: Channel[]; activeId: number | null; onSelect: (id: number) => void; onDismiss: (channel: Channel) => void;
    recentlyDismissed: Channel[]; onUndismiss: (id: number) => void;
}) {
    const [collapsed, setCollapsed]   = useState(false);
    const [search, setSearch]         = useState("");
    const [showAll, setShowAll]       = useState(false);

    const now        = Date.now();
    const cutoff     = ORDER_THREADS_RECENT_DAYS * 86_400_000;

    // Sort by last activity desc
    const sorted = [...channels].sort((a, b) => {
        const ta = a.last_activity_at ? new Date(a.last_activity_at).getTime() : 0;
        const tb = b.last_activity_at ? new Date(b.last_activity_at).getTime() : 0;
        return tb - ta;
    });

    // Apply search filter
    const searched = search.trim()
        ? sorted.filter(c => c.name.toLowerCase().includes(search.toLowerCase()) ||
            (c.description ?? "").toLowerCase().includes(search.toLowerCase()))
        : sorted;

    // Split into recent vs older
    const recent = searched.filter(c =>
        c.id === activeId ||
        (c.last_activity_at && now - new Date(c.last_activity_at).getTime() < cutoff) ||
        (c.unread_count ?? 0) > 0
    );
    const older  = searched.filter(c => !recent.includes(c));
    const visible = (showAll || search.trim()) ? searched : recent;

    const totalUnread = channels.reduce((n, c) => n + (c.unread_count ?? 0), 0);

    return (
        <div className="px-3">
            {/* Section header */}
            <div className="flex items-center justify-between px-1 py-1.5">
                <button
                    onClick={() => setCollapsed(v => !v)}
                    className="flex items-center gap-1.5 min-w-0 text-left"
                >
                    <svg className={clsx("w-3 h-3 text-surface-400 shrink-0 transition-transform", !collapsed && "rotate-90")}
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Order Threads</p>
                    {totalUnread > 0 && (
                        <span className="px-1 rounded-full bg-danger text-white text-2xs font-bold leading-tight">
                            {totalUnread > 99 ? "99+" : totalUnread}
                        </span>
                    )}
                    {!collapsed && channels.length > 0 && (
                        <span className="text-2xs text-surface-400 font-normal">({channels.length})</span>
                    )}
                </button>
            </div>

            {!collapsed && (
                <>
                    {/* Search input — only shown when there are enough channels to warrant it */}
                    {channels.length >= 5 && (
                        <div className="relative mb-1.5">
                            <svg className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3 h-3 text-surface-300"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                            </svg>
                            <input
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Find order…"
                                className="w-full text-2xs pl-7 pr-2.5 py-1.5 rounded-lg bg-surface-100 border border-transparent focus:border-brand-300 focus:outline-none focus:bg-white placeholder:text-surface-300 transition-colors"
                            />
                            {search && (
                                <button onClick={() => setSearch("")}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 text-surface-300 hover:text-surface-500">
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            )}
                        </div>
                    )}

                    {channels.length === 0 ? (
                        <p className="text-2xs text-surface-400 px-2 py-1">No order threads yet</p>
                    ) : visible.length === 0 ? (
                        <p className="text-2xs text-surface-400 px-2 py-1">No results for "{search}"</p>
                    ) : (
                        <>
                            {visible.map(c => (
                                <OrderThreadItem key={c.id} channel={c} active={c.id === activeId} onClick={() => onSelect(c.id)} onDismiss={() => onDismiss(c)} />
                            ))}

                            {/* Show all / show recent toggle */}
                            {!search.trim() && older.length > 0 && (
                                <button
                                    onClick={() => setShowAll(v => !v)}
                                    className="w-full text-left px-2 py-1.5 text-2xs text-brand-500 hover:text-brand-700 font-medium flex items-center gap-1 transition-colors"
                                >
                                    {showAll ? (
                                        <>
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>
                                            </svg>
                                            Show recent only
                                        </>
                                    ) : (
                                        <>
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                            </svg>
                                            {older.length} older thread{older.length !== 1 ? "s" : ""}
                                        </>
                                    )}
                                </button>
                            )}
                        </>
                    )}

                    {/* Recently dismissed — session-local undo affordance.
                        index() never returns dismissed channels, so this list
                        only ever reflects what THIS browser tab dismissed
                        since it loaded; a page reload clears it, which is
                        fine — the dismissal itself is still in effect on the
                        server either way. */}
                    {recentlyDismissed.length > 0 && (
                        <div className="mt-2 pt-2 border-t border-surface-100">
                            <p className="text-2xs font-semibold text-surface-400 px-2 py-1">Recently dismissed</p>
                            {recentlyDismissed.map(c => (
                                <div key={c.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-surface-100 transition-colors">
                                    <span className="flex-1 truncate text-2xs text-surface-400">{c.name}</span>
                                    <button
                                        onClick={() => onUndismiss(c.id)}
                                        className="text-2xs font-medium text-brand-500 hover:text-brand-700 shrink-0"
                                    >
                                        Restore
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

// ─── Channel list item ────────────────────────────────────────────────────────

function ChannelItem({ channel, active, onClick }: { channel: Channel; active: boolean; onClick: () => void }) {
    const unread = channel.unread_count ?? 0;
    return (
        <button onClick={onClick} className={clsx(
            "w-full flex items-center gap-2.5 px-2 py-2.5 sm:py-2 rounded-lg text-left transition-colors mb-0.5",
            active ? "bg-brand-500/10 text-brand-700" : "text-surface-600 hover:bg-surface-100 hover:text-surface-900"
        )}>
            <div className={clsx("w-7 h-7 rounded-lg flex items-center justify-center shrink-0 text-2xs font-bold",
                active ? "bg-brand-500/20 text-brand-700" : "bg-surface-200 text-surface-600")}>
                {channel.type === "dm"
                    ? (channel.name[0] ?? "D").toUpperCase()
                    : channel.is_private ? <LockIcon /> : "#"
                }
            </div>
            <div className="flex-1 min-w-0">
                <p className="text-xs font-semibold truncate">{channel.name}</p>
                {channel.last_message && (
                    <p className="text-2xs text-surface-400 truncate">
                        {channel.last_message.user_name ? `${channel.last_message.user_name}: ` : ""}{stripMarkdown(channel.last_message.body)}
                    </p>
                )}
            </div>
            {unread > 0 && (
                <span className="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-brand-600 text-white text-2xs font-bold flex items-center justify-center">
                    {unread > 99 ? "99+" : unread}
                </span>
            )}
        </button>
    );
}

// ─── CommsHub main ────────────────────────────────────────────────────────────

export default function CommsHub() {
    const { channelId } = useParams<{ channelId?: string }>();
    const navigate      = useNavigate();
    const { user }      = useAuthStore();

    // Phase 5: Sets --visual-viewport-height and --keyboard-height CSS vars
    // so the composer and mention popup can position above the software keyboard.
    useVisualViewport();
    const [showNewSpace, setShowNewSpace]   = useState(false);
    const [showDirectory, setShowDirectory] = useState(false);
    const [activeId, setActiveId]           = useState<number | null>(channelId ? parseInt(channelId) : null);
    const [mobileSidebarOpen, setMobileSidebarOpen] = useState(!channelId); // open by default if no channel selected

    const { data, isLoading, refetch } = useQuery({
        queryKey: ["channels"],
        queryFn: () => channelApi.list(),
        staleTime: 30_000,
        refetchInterval: 30_000,
    });

    const qc    = useQueryClient();
    const toast = useToastStore();

    // Channels the user has dismissed THIS session, kept client-side purely
    // for the "Recently dismissed" undo affordance below. index() excludes
    // dismissed channels entirely (the server has no reason to ever return
    // them to a normal channel-list call), so this is the only place that
    // knowledge exists on the frontend — it intentionally does NOT persist
    // across a page reload; a refreshed page just shows the clean list,
    // which is fine since the dismissal itself is still in effect server-side.
    const [recentlyDismissed, setRecentlyDismissed] = useState<Channel[]>([]);

    const dismissMutation = useMutation({
        mutationFn: (id: number) => channelApi.dismiss(id),
        // Optimistic: remove the channel from the cached list immediately so
        // the sidebar updates without waiting on the round-trip. Reconciles
        // with the server on the next 30s refetch (or sooner, on any
        // invalidation elsewhere) — if a message somehow lands in the same
        // instant the dismiss is sent, the channel simply reappears then.
        onMutate: async (id) => {
            const prev = qc.getQueryData<{ channels: Channel[] }>(["channels"]);
            qc.setQueryData<{ channels: Channel[] } | undefined>(["channels"], (old) =>
                old ? { channels: old.channels.filter(c => c.id !== id) } : old
            );
            return { prev };
        },
        onError: (_err, _id, ctx) => {
            if (ctx?.prev) qc.setQueryData(["channels"], ctx.prev);
            toast.error("Couldn't dismiss thread — please try again.");
        },
    });

    const undismissMutation = useMutation({
        mutationFn: (id: number) => channelApi.undismiss(id),
        onSuccess: (_data, id) => {
            setRecentlyDismissed(prev => prev.filter(c => c.id !== id));
            qc.invalidateQueries({ queryKey: ["channels"] });
        },
        onError: () => toast.error("Couldn't restore thread — please try again."),
    });

    const handleDismissThread = (channel: Channel) => {
        // If the dismissed thread happens to be the one currently open,
        // back out to the empty state rather than leaving a phantom active
        // channel that no longer appears in the sidebar.
        if (channel.id === activeId) {
            setActiveId(null);
            navigate("/comms", { replace: true });
        }
        dismissMutation.mutate(channel.id);
        setRecentlyDismissed(prev => [channel, ...prev.filter(c => c.id !== channel.id)]);
        toast.info(`Dismissed "${channel.name}" — it'll reappear if there's a new message, or restore it below.`);
    };

    const handleUndismissThread = (id: number) => {
        undismissMutation.mutate(id);
    };

    const channels      = data?.channels ?? [];
    const dms           = channels.filter(c => c.type === "dm");
    // Context channels (auto-created for orders/production orders) go into their
    // own "Order Threads" section — keeps the Spaces list clean regardless of
    // how many orders have active conversations.
    const orderThreads  = channels.filter(c => c.type !== "dm" && c.context_type != null);
    const manualSpaces  = channels.filter(c => c.type !== "dm" && c.context_type == null);
    const activeChannel = channels.find(c => c.id === activeId) ?? null;
    const totalUnread   = channels.reduce((n, c) => n + (c.unread_count ?? 0), 0);

    const selectChannel = (id: number) => {
        setActiveId(id);
        setMobileSidebarOpen(false);
        navigate(`/comms/${id}`, { replace: true });
    };

    return (
        <div className="flex h-full overflow-hidden rounded-xl border border-surface-200 bg-white shadow-sm relative">

            {/* Mobile sidebar backdrop */}
            {mobileSidebarOpen && (
                <div className="fixed inset-0 bg-black/30 z-20 sm:hidden"
                    onClick={() => setMobileSidebarOpen(false)} />
            )}

            {/* Sidebar - always visible on sm+, slide-over on mobile */}
            <div className={clsx(
                "flex flex-col bg-surface-50 border-r border-surface-100 shrink-0 transition-transform duration-200 z-30",
                // Desktop: static in flow
                "sm:relative sm:translate-x-0 sm:w-64",
                // Mobile: fixed overlay, slide in/out
                "max-sm:fixed max-sm:inset-y-0 max-sm:left-0 max-sm:w-72 max-sm:shadow-2xl",
                mobileSidebarOpen ? "max-sm:translate-x-0" : "max-sm:-translate-x-full"
            )}>
                <div className="px-4 py-3.5 border-b border-surface-100 shrink-0">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-bold text-surface-900 flex items-center gap-2">
                            Messages
                            {totalUnread > 0 && (
                                <span className="px-1.5 py-0.5 rounded-full bg-danger text-white text-2xs font-bold">
                                    {totalUnread > 99 ? "99+" : totalUnread}
                                </span>
                            )}
                        </h2>
                        <div className="flex items-center gap-1">
                            <button onClick={() => setShowDirectory(true)} title="New direct message"
                                className="w-6 h-6 rounded flex items-center justify-center text-surface-400 hover:text-brand-600 hover:bg-brand-50 transition-colors">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                            {/* Close button - mobile only */}
                            <button onClick={() => setMobileSidebarOpen(false)}
                                className="sm:hidden w-6 h-6 rounded flex items-center justify-center text-surface-400 hover:text-surface-700 transition-colors"
                                aria-label="Close">
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto py-2 space-y-1">
                    <SidebarSection label="Direct Messages" onAdd={() => setShowDirectory(true)} addTitle="New message">
                        {dms.length === 0
                            ? <p className="text-2xs text-surface-400 px-2 py-1">No direct messages yet</p>
                            : dms.map(c => <ChannelItem key={c.id} channel={c} active={c.id === activeId} onClick={() => selectChannel(c.id)} />)
                        }
                    </SidebarSection>

                    {/* Order Threads — context channels auto-created for production/sales orders.
                        Kept separate from manually-created Spaces to prevent list bloat. */}
                    <OrderThreadsSection
                        channels={orderThreads}
                        activeId={activeId}
                        onSelect={selectChannel}
                        onDismiss={handleDismissThread}
                        recentlyDismissed={recentlyDismissed}
                        onUndismiss={handleUndismissThread}
                    />

                    <SidebarSection label="Spaces" onAdd={() => setShowNewSpace(true)} addTitle="New space">
                        {manualSpaces.length === 0
                            ? <p className="text-2xs text-surface-400 px-2 py-1">No spaces yet</p>
                            : manualSpaces.map(c => <ChannelItem key={c.id} channel={c} active={c.id === activeId} onClick={() => selectChannel(c.id)} />)
                        }
                    </SidebarSection>
                </div>
            </div>

            {/* Main */}
            <div className="flex-1 min-w-0 flex flex-col">
                {activeChannel ? (
                    <ChannelView channel={activeChannel} onOpenSidebar={() => setMobileSidebarOpen(true)} />
                ) : (
                    <div className="flex flex-col items-center justify-center h-full gap-4 text-surface-400 p-8">
                        <svg className="w-14 h-14 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.25}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <p className="text-sm text-center">Select a conversation or start a new one</p>
                        <div className="flex gap-2">
                            <button onClick={() => { setShowDirectory(true); setMobileSidebarOpen(false); }} className="btn-secondary text-xs">💬 New Message</button>
                            <button onClick={() => { setShowNewSpace(true); setMobileSidebarOpen(false); }} className="btn-primary text-xs"># New Space</button>
                        </div>
                        {/* Mobile: open sidebar button when no channel selected */}
                        <button onClick={() => setMobileSidebarOpen(true)}
                            className="sm:hidden mt-2 text-xs text-brand-600 underline underline-offset-2">
                            Browse conversations
                        </button>
                    </div>
                )}
            </div>

            {showNewSpace && <NewSpaceModal currentUserId={user?.id} onClose={() => setShowNewSpace(false)} onCreated={c => { refetch(); selectChannel(c.id); }} />}
            {showDirectory && <UserDirectory onClose={() => setShowDirectory(false)} onOpenDm={id => { refetch(); selectChannel(id); }} />}
        </div>
    );
}