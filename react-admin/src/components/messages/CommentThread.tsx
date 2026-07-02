/**
 * CommentThread - Phase 1 unified comment component.
 *
 * Drop into any detail page:
 *
 *   <CommentThread model="Order" id={order.id} />
 *   <CommentThread model="ProductionOrder" id={order.id} />
 *   <CommentThread model="PurchaseOrder" id={po.id} />
 *
 * Features:
 *   - Threaded comments with one level of replies
 *   - @mention autocomplete (type @ to trigger)
 *   - Note vs Comment type toggle (notes are visually distinct)
 *   - Edit window (10 min)
 *   - Soft delete
 *   - Polls for new comments every 30 s (Phase 2 upgrades to WebSocket)
 *   - Empty, loading, and error states
 */

import { useState, useRef, useEffect, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { commentApi, type Comment, type CommentModel, type MentionUser } from "@/api/comments";
import { useAuthStore } from "@/store/auth.store";

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fmtTime(iso: string) {
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now.getTime() - d.getTime();
    const diffMin = Math.floor(diffMs / 60_000);
    if (diffMin < 1) return "just now";
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return `${diffHr}h ago`;
    return d.toLocaleDateString("en-KE", { day: "2-digit", month: "short" });
}

/** Render body with @mentions highlighted. */
function RenderBody({ body }: { body: string }) {
    const parts = body.split(/(@\[[^\]]+\]\(user:\d+\))/g);
    return (
        <span>
            {parts.map((part, i) => {
                const match = part.match(/^@\[([^\]]+)\]\(user:(\d+)\)$/);
                if (match) {
                    return (
                        <span key={i} className="text-brand-600 font-semibold bg-brand-50 rounded px-0.5">
                            @{match[1]}
                        </span>
                    );
                }
                return <span key={i}>{part}</span>;
            })}
        </span>
    );
}

// ─── Mention autocomplete ─────────────────────────────────────────────────────

interface MentionDropdownProps {
    query: string;
    onSelect: (user: MentionUser) => void;
}

function MentionDropdown({ query, onSelect }: MentionDropdownProps) {
    const { data, isLoading } = useQuery({
        queryKey: ["mention-users", query],
        queryFn: () => commentApi.searchUsers(query),
        enabled: query.length >= 0,
        staleTime: 10_000,
    });

    const users = data?.users ?? [];

    if (isLoading) {
        return (
            <div className="absolute bottom-full left-0 mb-1 w-56 bg-white rounded-xl border border-surface-200 shadow-lg p-2 z-50">
                <p className="text-xs text-surface-400 px-2 py-1">Searching…</p>
            </div>
        );
    }

    if (!users.length) {
        return (
            <div className="absolute bottom-full left-0 mb-1 w-56 bg-white rounded-xl border border-surface-200 shadow-lg p-2 z-50">
                <p className="text-xs text-surface-400 px-2 py-1">No users found</p>
            </div>
        );
    }

    return (
        <div className="absolute bottom-full left-0 mb-1 w-56 bg-white rounded-xl border border-surface-200 shadow-lg py-1 z-50">
            {users.map((u) => (
                <button
                    key={u.id}
                    onMouseDown={(e) => { e.preventDefault(); onSelect(u); }}
                    className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-surface-50 transition-colors text-left"
                >
                    <div className="w-6 h-6 rounded-full bg-brand-500/15 flex items-center justify-center shrink-0">
                        <span className="text-brand-600 text-2xs font-bold">{u.initials}</span>
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs font-medium text-surface-800 truncate">{u.name}</p>
                        <p className="text-2xs text-surface-400 truncate">{u.email}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ─── Composer ────────────────────────────────────────────────────────────────

interface ComposerProps {
    model:       CommentModel;
    id:          number;
    parentId?:   number | null;
    onPosted:    (comment: Comment) => void;
    onCancel?:   () => void;
    placeholder?: string;
    autoFocus?:  boolean;
    initialBody?: string;
    editMode?:   boolean;
    commentId?:  number;
}

function Composer({
    model, id, parentId, onPosted, onCancel,
    placeholder = "Write a comment… use @ to mention someone",
    autoFocus, initialBody = "", editMode, commentId,
}: ComposerProps) {
    const [body, setBody]           = useState(initialBody);
    const [type, setType]           = useState<"comment" | "note">("comment");
    const [mentionQuery, setMentionQuery] = useState<string | null>(null);
    const [mentionStart, setMentionStart] = useState(0);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const queryClient = useQueryClient();

    useEffect(() => {
        if (autoFocus) textareaRef.current?.focus();
    }, [autoFocus]);

    const postMutation = useMutation({
        mutationFn: () =>
            editMode && commentId
                ? commentApi.update(commentId, body)
                : commentApi.post({ model, id, body, type, parent_id: parentId ?? null }),
        onSuccess: (data) => {
            onPosted(data.comment);
            setBody("");
            queryClient.invalidateQueries({ queryKey: ["comments", model, id] });
        },
    });

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            if (body.trim()) postMutation.mutate();
        }
        if (e.key === "Escape") {
            setMentionQuery(null);
            onCancel?.();
        }
    };

    const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
        const val = e.target.value;
        setBody(val);

        // Detect @mention trigger
        const cursor = e.target.selectionStart;
        const textBefore = val.slice(0, cursor);
        const atMatch = textBefore.match(/@(\w*)$/);
        if (atMatch) {
            setMentionQuery(atMatch[1]);
            setMentionStart(cursor - atMatch[0].length);
        } else {
            setMentionQuery(null);
        }
    };

    const insertMention = (user: MentionUser) => {
        const before = body.slice(0, mentionStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? mentionStart + (mentionQuery?.length ?? 0) + 1);
        const tag    = `@[${user.name}](user:${user.id}) `;
        const newBody = before + tag + after;
        setBody(newBody);
        setMentionQuery(null);
        setTimeout(() => {
            if (textareaRef.current) {
                const pos = (before + tag).length;
                textareaRef.current.focus();
                textareaRef.current.setSelectionRange(pos, pos);
            }
        }, 0);
    };

    return (
        <div className="relative">
            {/* Type toggle - only for top-level comments, not edit mode */}
            {!editMode && !parentId && (
                <div className="flex gap-1 mb-2">
                    {(["comment", "note"] as const).map((t) => (
                        <button
                            key={t}
                            onClick={() => setType(t)}
                            className={clsx(
                                "px-3 py-1 rounded-full text-2xs font-semibold border transition-all",
                                type === t
                                    ? t === "note"
                                        ? "bg-amber-50 text-amber-700 border-amber-300"
                                        : "bg-brand-50 text-brand-700 border-brand-300"
                                    : "bg-white text-surface-500 border-surface-200 hover:border-surface-300"
                            )}
                        >
                            {t === "note" ? "📌 Internal Note" : "💬 Comment"}
                        </button>
                    ))}
                </div>
            )}

            <div className={clsx(
                "relative rounded-xl border transition-colors",
                "focus-within:border-brand-400 focus-within:ring-1 focus-within:ring-brand-200",
                type === "note" ? "border-amber-300 bg-amber-50/40" : "border-surface-200 bg-white"
            )}>
                {mentionQuery !== null && (
                    <MentionDropdown query={mentionQuery} onSelect={insertMention} />
                )}
                <textarea
                    ref={textareaRef}
                    value={body}
                    onChange={handleChange}
                    onKeyDown={handleKeyDown}
                    placeholder={type === "note" ? "Add an internal note… (not visible to customer)" : placeholder}
                    rows={3}
                    className="w-full px-3 py-2.5 text-sm text-surface-800 bg-transparent resize-none outline-none rounded-xl placeholder:text-surface-400"
                />
                <div className="flex items-center justify-between px-3 pb-2.5">
                    <span className="text-2xs text-surface-400">⌘↵ to submit</span>
                    <div className="flex gap-2">
                        {onCancel && (
                            <button
                                onClick={onCancel}
                                className="px-3 py-1.5 rounded-lg text-xs text-surface-500 hover:bg-surface-100 transition-colors"
                            >
                                Cancel
                            </button>
                        )}
                        <button
                            onClick={() => { if (body.trim()) postMutation.mutate(); }}
                            disabled={!body.trim() || postMutation.isPending}
                            className="px-4 py-1.5 rounded-lg text-xs font-semibold bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            {postMutation.isPending ? "Posting…" : editMode ? "Save" : "Post"}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Single comment row ───────────────────────────────────────────────────────

interface CommentRowProps {
    comment:    Comment;
    model:      CommentModel;
    id:         number;
    currentUserId?: number;
    depth?:     number;
    onDeleted:  (commentId: number) => void;
    onUpdated:  (comment: Comment) => void;
}

function CommentRow({ comment, model, id, currentUserId, depth = 0, onDeleted, onUpdated }: CommentRowProps) {
    const [replying, setReplying] = useState(false);
    const [editing,  setEditing]  = useState(false);
    const queryClient = useQueryClient();

    const isOwn = comment.user?.id === currentUserId;
    const canEdit = isOwn && comment.created_at
        ? new Date().getTime() - new Date(comment.created_at).getTime() < 10 * 60 * 1000
        : false;

    const deleteMutation = useMutation({
        mutationFn: () => commentApi.delete(comment.id),
        onSuccess: () => {
            onDeleted(comment.id);
            queryClient.invalidateQueries({ queryKey: ["comments", model, id] });
        },
    });

    const isNote = comment.type === "note";

    return (
        <div className={clsx("group", depth > 0 && "ml-8 border-l-2 border-surface-100 pl-4")}>
            <div className={clsx(
                "rounded-xl p-3 mb-1",
                isNote ? "bg-amber-50 border border-amber-200/60" : "bg-surface-50"
            )}>
                {/* Header */}
                <div className="flex items-start justify-between gap-2 mb-1.5">
                    <div className="flex items-center gap-2">
                        <div className="w-6 h-6 rounded-full bg-brand-500/15 flex items-center justify-center shrink-0">
                            <span className="text-brand-600 text-2xs font-bold">
                                {comment.user?.initials ?? "?"}
                            </span>
                        </div>
                        <span className="text-xs font-semibold text-surface-800">
                            {comment.user?.name ?? "System"}
                        </span>
                        {isNote && (
                            <span className="text-2xs px-1.5 py-0.5 rounded-full bg-amber-200 text-amber-800 font-semibold">
                                Internal Note
                            </span>
                        )}
                        <span className="text-2xs text-surface-400">{fmtTime(comment.created_at)}</span>
                        {comment.edited_at && (
                            <span className="text-2xs text-surface-400 italic">(edited)</span>
                        )}
                    </div>

                    {/* Actions - visible on hover */}
                    <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        {depth === 0 && (
                            <button
                                onClick={() => setReplying((v) => !v)}
                                className="text-2xs text-surface-400 hover:text-brand-600 px-1.5 py-0.5 rounded transition-colors"
                            >
                                Reply
                            </button>
                        )}
                        {canEdit && (
                            <button
                                onClick={() => setEditing((v) => !v)}
                                className="text-2xs text-surface-400 hover:text-brand-600 px-1.5 py-0.5 rounded transition-colors"
                            >
                                Edit
                            </button>
                        )}
                        {isOwn && (
                            <button
                                onClick={() => { if (confirm("Delete this comment?")) deleteMutation.mutate(); }}
                                className="text-2xs text-surface-400 hover:text-danger px-1.5 py-0.5 rounded transition-colors"
                            >
                                Delete
                            </button>
                        )}
                    </div>
                </div>

                {/* Body */}
                {editing ? (
                    <Composer
                        model={model}
                        id={id}
                        editMode
                        commentId={comment.id}
                        initialBody={comment.body}
                        autoFocus
                        onPosted={(updated) => { onUpdated(updated); setEditing(false); }}
                        onCancel={() => setEditing(false)}
                    />
                ) : (
                    <p className="text-sm text-surface-700 leading-relaxed whitespace-pre-wrap break-words">
                        <RenderBody body={comment.body} />
                    </p>
                )}
            </div>

            {/* Replies */}
            {(comment.replies ?? []).map((reply) => (
                <CommentRow
                    key={reply.id}
                    comment={reply}
                    model={model}
                    id={id}
                    currentUserId={currentUserId}
                    depth={depth + 1}
                    onDeleted={onDeleted}
                    onUpdated={onUpdated}
                />
            ))}

            {/* Reply composer */}
            {replying && (
                <div className="ml-8 mt-1 mb-2">
                    <Composer
                        model={model}
                        id={id}
                        parentId={comment.id}
                        autoFocus
                        placeholder={`Reply to ${comment.user?.name ?? "this comment"}…`}
                        onPosted={() => { setReplying(false); }}
                        onCancel={() => setReplying(false)}
                    />
                </div>
            )}
        </div>
    );
}

// ─── Main CommentThread ───────────────────────────────────────────────────────

interface CommentThreadProps {
    model:       CommentModel;
    id:          number;
    /** Collapsed mode - show count only, expand on click */
    collapsible?: boolean;
    className?:  string;
}

export function CommentThread({ model, id, collapsible, className }: CommentThreadProps) {
    const { user } = useAuthStore();
    const queryClient = useQueryClient();
    const [expanded, setExpanded] = useState(!collapsible);

    const { data, isLoading, isError } = useQuery({
        queryKey: ["comments", model, id],
        queryFn: () => commentApi.list(model, id),
        // Phase 2: replace with WebSocket subscription
        refetchInterval: 30_000,
        enabled: expanded,
        staleTime: 15_000,
    });

    const comments = data?.comments ?? [];

    const handleDeleted = useCallback((commentId: number) => {
        queryClient.setQueryData<{ comments: Comment[] }>(
            ["comments", model, id],
            (old) => old
                ? { comments: old.comments.filter((c) => c.id !== commentId) }
                : old
        );
    }, [queryClient, model, id]);

    const handleUpdated = useCallback((updated: Comment) => {
        queryClient.setQueryData<{ comments: Comment[] }>(
            ["comments", model, id],
            (old) => {
                if (!old) return old;
                return {
                    comments: old.comments.map((c) =>
                        c.id === updated.id ? { ...c, ...updated } : c
                    ),
                };
            }
        );
    }, [queryClient, model, id]);

    const totalCount = comments.reduce(
        (n, c) => n + 1 + (c.replies?.length ?? 0), 0
    );

    return (
        <div className={clsx("space-y-3", className)}>
            {/* Section header */}
            <button
                onClick={() => collapsible && setExpanded((v) => !v)}
                className={clsx(
                    "flex items-center gap-2 w-full text-left",
                    collapsible && "hover:opacity-80 transition-opacity"
                )}
            >
                <svg className="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round">
                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                </svg>
                <span className="text-xs font-semibold text-surface-500 uppercase tracking-widest">
                    Thread
                </span>
                {totalCount > 0 && (
                    <span className="px-1.5 py-0.5 rounded-full bg-surface-100 text-surface-500 text-2xs font-semibold">
                        {totalCount}
                    </span>
                )}
                {collapsible && (
                    <svg className={clsx("w-3 h-3 text-surface-400 ml-auto transition-transform", expanded && "rotate-180")}
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                )}
            </button>

            {expanded && (
                <>
                    {/* Comment list */}
                    {isLoading ? (
                        <div className="flex items-center gap-2 py-4 text-xs text-surface-400">
                            <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                            </svg>
                            Loading thread…
                        </div>
                    ) : isError ? (
                        <p className="text-xs text-danger py-2">Failed to load comments.</p>
                    ) : comments.length === 0 ? (
                        <p className="text-xs text-surface-400 py-2 text-center">
                            No comments yet. Be the first to add one.
                        </p>
                    ) : (
                        <div className="space-y-1">
                            {comments.map((c) => (
                                <CommentRow
                                    key={c.id}
                                    comment={c}
                                    model={model}
                                    id={id}
                                    currentUserId={user?.id}
                                    onDeleted={handleDeleted}
                                    onUpdated={handleUpdated}
                                />
                            ))}
                        </div>
                    )}

                    {/* New comment composer */}
                    <Composer
                        model={model}
                        id={id}
                        onPosted={() => queryClient.invalidateQueries({ queryKey: ["comments", model, id] })}
                    />
                </>
            )}
        </div>
    );
}