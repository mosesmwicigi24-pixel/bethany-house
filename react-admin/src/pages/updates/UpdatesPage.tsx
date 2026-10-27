import { useEffect, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { updatesApi, KIND_LABEL, type ComposeInput, type FeedPost, type PostDetail, type PostKind, type Rsvp } from "@/api/updates";
import { outletsApi } from "@/api/setup";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

/**
 * /updates — the feed (People C3): notices with a photo and a must-read
 * acknowledgement, shout-outs, the hub's birthday and anniversary posts,
 * events with an RSVP. /updates/:id opens one post (comments, and for the
 * author or a manager, who has read it). Anyone may post a recognition;
 * updates.post posts the rest.
 */
const KIND_ICON: Record<PostKind, string> = { update: "📣", recognition: "⭐", celebration: "🎉", event: "📅" };

function when(iso: string | null | undefined, withTime = true) {
    if (!iso) return "";
    const d = new Date(iso);
    return d.toLocaleDateString("en-GB", { day: "numeric", month: "short" }) + (withTime ? ` · ${d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}` : "");
}

export default function UpdatesPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [params] = useSearchParams();
    const [kind, setKind] = useState<PostKind | "">((params.get("kind") as PostKind | null) ?? "");
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState<FeedPost[]>([]);
    const [compose, setCompose] = useState<ComposeInput["kind"] | null>(null);
    const feed = useQuery({ queryKey: ["updates", kind, page], queryFn: () => updatesApi.feed(page, kind || undefined) });
    useEffect(() => { if (feed.data) setRows(r => (page === 1 ? feed.data.data : [...r.filter(x => !feed.data.data.some(y => y.id === x.id)), ...feed.data.data])); }, [feed.data, page]);
    const changeKind = (k: PostKind | "") => { setKind(k); setPage(1); };
    const refresh = () => { setPage(1); qc.invalidateQueries({ queryKey: ["updates"] }); };
    const pending = feed.data?.must_read_pending ?? 0;

    return (
        <div className="space-y-4 animate-fade-in max-w-2xl">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="page-title">Updates</h1>
                    <p className="page-subtitle">What is happening at Bethany House. {pending > 0 && <span className="text-danger font-semibold">{pending} must-read{pending > 1 ? "s" : ""} waiting for you.</span>}</p>
                </div>
                <div className="flex gap-2">
                    <button onClick={() => setCompose("recognition")} className="btn-secondary btn-sm">⭐ Recognise someone</button>
                    {feed.data?.can_post && <button onClick={() => setCompose("update")} className="btn-primary btn-sm">New post</button>}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs">
                {([["", "All"], ["update", "Updates"], ["recognition", "Recognitions"], ["event", "Events"], ["celebration", "Celebrations"]] as const).map(([k, l]) => (
                    <button key={k} onClick={() => changeKind(k)} className={clsx("px-3 py-1 rounded-full border", kind === k ? "bg-brand-600 text-white border-brand-600" : "bg-white text-surface-600 border-line")}>{l}</button>
                ))}
                {feed.data?.can_post && (feed.data.scheduled ?? 0) > 0 && <Link to="/updates/scheduled" className="ml-auto text-brand-600">{feed.data.scheduled} scheduled ›</Link>}
            </div>

            {feed.isLoading && rows.length === 0 ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : rows.length === 0 ? (
                <div className="card p-8 text-center text-sm text-surface-500">Nothing here yet.{feed.data?.can_post ? " Post the first update." : ""}</div>
            ) : (
                <div className="space-y-3">
                    {rows.map(p => <PostCard key={p.id} post={p} onOpen={() => navigate(`/updates/${p.id}`)} />)}
                    {feed.data?.has_more && <button onClick={() => setPage(p => p + 1)} className="btn-secondary btn-sm w-full">{feed.isFetching ? "…" : "Older posts"}</button>}
                </div>
            )}

            {id === "scheduled" ? <ScheduledModal onClose={() => navigate("/updates")} onChanged={refresh} /> : id ? <PostModal id={Number(id)} onClose={() => navigate("/updates")} onChanged={refresh} /> : null}
            {compose && <ComposeModal kind={compose} canPost={!!feed.data?.can_post} onClose={() => setCompose(null)} onPosted={() => { setCompose(null); refresh(); }} />}
        </div>
    );
}

function Avatar({ p, size = "w-9 h-9" }: { p: { name: string; avatar_url: string | null } | null; size?: string }) {
    const initials = (p?.name ?? "BH").split(" ").map(s => s[0]).slice(0, 2).join("").toUpperCase();
    return p?.avatar_url ? <img src={p.avatar_url} alt="" className={clsx(size, "rounded-full object-cover shrink-0")} /> : <span className={clsx(size, "rounded-full bg-brand-500/15 text-brand-700 text-xs font-bold flex items-center justify-center shrink-0")}>{initials}</span>;
}

function PostCard({ post: p, onOpen }: { post: FeedPost; onOpen: () => void }) {
    const unread = !p.me.read_at;
    const needsAck = p.must_read && !p.me.acknowledged_at && !p.is_author;
    return (
        <button type="button" onClick={onOpen} className={clsx("card w-full text-left p-4 space-y-2 hover:shadow-md transition", needsAck && "ring-2 ring-danger/40")}>
            <div className="flex items-center gap-3">
                <Avatar p={p.kind === "celebration" ? p.recipient : p.author} />
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold text-surface-900 truncate">{p.kind === "celebration" ? p.recipient?.name : p.author?.name ?? "Bethany House"}{p.kind === "recognition" && p.recipient ? <span className="font-normal text-surface-500"> recognised <b className="text-surface-800">{p.recipient.name}</b></span> : null}</p>
                    <p className="text-xs text-surface-400">{KIND_ICON[p.kind]} {KIND_LABEL[p.kind]} · {when(p.published_at, true)}{p.outlet ? ` · ${p.outlet.name}` : ""}</p>
                </div>
                {needsAck ? <span className="text-2xs font-bold uppercase tracking-wide text-white bg-danger rounded-full px-2 py-0.5">Must read</span> : unread && <span className="w-2.5 h-2.5 rounded-full bg-brand-600" aria-label="Unread" />}
            </div>
            {p.title && <h3 className="font-semibold text-surface-900">{p.title}</h3>}
            {p.body && <p className="text-sm text-surface-700 whitespace-pre-line line-clamp-4">{p.body}</p>}
            {p.points ? <p className="text-xs font-semibold text-amber-700">+{p.points} point{p.points === 1 ? "" : "s"}</p> : null}
            {p.kind === "event" && <p className="text-sm text-surface-700">📅 {when(p.event_at)}{p.event_place ? ` · ${p.event_place}` : ""}{p.me.rsvp ? ` · you said ${p.me.rsvp}` : ""}{p.rsvp_yes_count ? ` · ${p.rsvp_yes_count} going` : ""}</p>}
            <p className="text-xs text-surface-400">{p.has_photo ? "📷 photo · " : ""}{p.comments_count} comment{p.comments_count === 1 ? "" : "s"}{p.can_manage ? ` · ${p.reads_count} read` : ""}</p>
        </button>
    );
}

function PostModal({ id, onClose, onChanged }: { id: number; onClose: () => void; onChanged: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const me = useAuthStore(s => s.user);
    const q = useQuery({ queryKey: ["update", id], queryFn: () => updatesApi.show(id) });
    const post = q.data?.post;
    const photo = useQuery({ queryKey: ["update-photo", id], queryFn: () => updatesApi.photoLink(id), enabled: !!post?.has_photo, staleTime: 60_000 });
    const [comment, setComment] = useState("");
    const [showReaders, setShowReaders] = useState(false);
    useEffect(() => { if (post && !post.me.read_at) updatesApi.read(id).then(() => onChanged()).catch(() => undefined); }, [post, id, onChanged]);
    const invalidate = () => { qc.invalidateQueries({ queryKey: ["update", id] }); onChanged(); };
    const ack = useMutation({ mutationFn: () => updatesApi.acknowledge(id), onSuccess: r => { toast.success(r.message); invalidate(); }, onError: (e: ApiError) => toast.error(e.message) });
    const rsvp = useMutation({ mutationFn: (a: Rsvp) => updatesApi.rsvp(id, a), onSuccess: r => { toast.success(r.message); invalidate(); }, onError: (e: ApiError) => toast.error(e.message) });
    const send = useMutation({ mutationFn: () => updatesApi.comment(id, comment.trim()), onSuccess: () => { setComment(""); invalidate(); }, onError: (e: ApiError) => toast.error(e.message) });
    const removeComment = useMutation({ mutationFn: (cid: number) => updatesApi.removeComment(id, cid), onSuccess: invalidate });
    const remove = useMutation({ mutationFn: () => updatesApi.remove(id), onSuccess: r => { toast.success(r.message); onChanged(); onClose(); }, onError: (e: ApiError) => toast.error(e.message) });
    const title = post ? (post.kind === "recognition" ? `${post.author?.name ?? "Someone"} recognised ${post.recipient?.name ?? ""}` : post.title ?? KIND_LABEL[post.kind]) : "…";
    return (
        <Modal open onClose={onClose} size="lg" title={title}>
            {!post ? <div className="flex items-center justify-center h-40">{q.error ? <p className="text-sm text-danger">{(q.error as ApiError).message}</p> : <Spinner size="lg" />}</div> : (
                <div className="space-y-4 text-sm">
                    <div className="flex items-center gap-3">
                        <Avatar p={post.kind === "celebration" ? post.recipient : post.author} />
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold text-surface-900">{post.kind === "celebration" ? post.recipient?.name : post.author?.name ?? "Bethany House"}</p>
                            <p className="text-xs text-surface-400">{KIND_ICON[post.kind]} {KIND_LABEL[post.kind]} · {post.published_at ? when(post.published_at) : `scheduled ${when(post.publish_at)}`}{post.outlet ? ` · ${post.outlet.name}` : " · everyone"}</p>
                        </div>
                        {post.can_manage && <button onClick={() => { if (confirm("Remove this post?")) remove.mutate(); }} className="btn-ghost btn-sm text-xs text-danger">Remove</button>}
                    </div>
                    {post.has_photo && (photo.data ? <img src={photo.data.url} alt="" className="w-full rounded-xl bg-surface-100 max-h-96 object-contain" /> : <div className="h-40 rounded-xl bg-surface-100 flex items-center justify-center"><Spinner /></div>)}
                    {post.body && <p className="text-surface-800 whitespace-pre-line">{post.body}</p>}
                    {post.points ? <p className="font-semibold text-amber-700">+{post.points} point{post.points === 1 ? "" : "s"}</p> : null}
                    {post.kind === "event" && (
                        <div className="rounded-xl bg-surface-50 p-3 space-y-2">
                            <p className="font-medium text-surface-900">📅 {when(post.event_at)}{post.event_place ? ` · ${post.event_place}` : ""}</p>
                            <div className="flex flex-wrap gap-2">{(["yes", "maybe", "no"] as Rsvp[]).map(a => <button key={a} onClick={() => rsvp.mutate(a)} disabled={rsvp.isPending} className={clsx("btn-sm rounded-full border px-3", post.me.rsvp === a ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-700")}>{a === "yes" ? "I'll be there" : a === "maybe" ? "Maybe" : "Can't make it"}</button>)}</div>
                            {post.rsvp_yes_count > 0 && <p className="text-xs text-surface-500">{post.rsvp_yes_count} going</p>}
                        </div>
                    )}
                    {post.must_read && !post.is_author && (
                        post.me.acknowledged_at ? <p className="rounded-xl bg-success-light text-success px-3 py-2 text-xs font-medium">You acknowledged this on {when(post.me.acknowledged_at)}.</p>
                            : <button onClick={() => ack.mutate()} disabled={ack.isPending} className="btn-primary w-full">{ack.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}I have read this</button>
                    )}
                    {post.readers && (
                        <div className="rounded-xl border border-line p-3 text-xs space-y-2">
                            <button onClick={() => setShowReaders(s => !s)} className="w-full flex items-center justify-between font-medium text-surface-800">
                                <span>{post.must_read ? `${post.readers.acknowledged.length} of ${post.readers.audience} acknowledged` : `${post.readers.read.length} of ${post.readers.audience} read`}{post.kind === "event" ? ` · ${post.readers.rsvp.yes} going, ${post.readers.rsvp.maybe} maybe, ${post.readers.rsvp.no} not` : ""}</span><span>{showReaders ? "▴" : "▾"}</span>
                            </button>
                            {showReaders && (
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div><p className="font-semibold text-surface-500 mb-1">{post.must_read ? "Acknowledged" : "Read"}</p>{(post.must_read ? post.readers.acknowledged : post.readers.read).map(r => <p key={r.id}>{r.name}{r.rsvp ? ` · ${r.rsvp}` : ""}</p>)}{(post.must_read ? post.readers.acknowledged : post.readers.read).length === 0 && <p className="text-surface-400">Nobody yet</p>}</div>
                                    <div><p className="font-semibold text-surface-500 mb-1">{post.must_read ? "Not yet" : "Not opened"}</p>{(post.must_read ? [...post.readers.read.filter(r => !r.acknowledged_at), ...post.readers.pending] : post.readers.pending).map(r => <p key={r.id}>{r.name}{r.read_at && post.must_read ? " · opened" : ""}</p>)}</div>
                                </div>
                            )}
                        </div>
                    )}
                    <div className="space-y-2">
                        <p className="text-xs font-semibold text-surface-500">{post.comments.length} comment{post.comments.length === 1 ? "" : "s"}</p>
                        {post.comments.map(c => (
                            <div key={c.id} className="flex items-start gap-2">
                                <Avatar p={c.user} size="w-7 h-7" />
                                <div className="flex-1 min-w-0 rounded-xl bg-surface-50 px-3 py-2">
                                    <p className="text-xs text-surface-500"><b className="text-surface-800">{c.user?.name}</b> · {when(c.at)}{(c.mine || post.can_manage) && <button onClick={() => removeComment.mutate(c.id)} className="ml-2 text-surface-400 hover:text-danger">remove</button>}</p>
                                    <p className="text-surface-800 whitespace-pre-line">{c.body}</p>
                                </div>
                            </div>
                        ))}
                        {post.comments_enabled ? (
                            <form onSubmit={e => { e.preventDefault(); if (comment.trim()) send.mutate(); }} className="flex gap-2">
                                <input value={comment} onChange={e => setComment(e.target.value)} className="input flex-1" placeholder={`Comment as ${me?.first_name ?? "you"}…`} maxLength={2000} />
                                <button type="submit" disabled={!comment.trim() || send.isPending} className="btn-primary btn-sm">Post</button>
                            </form>
                        ) : <p className="text-xs text-surface-400">Comments are off on this post.</p>}
                    </div>
                </div>
            )}
        </Modal>
    );
}

function ScheduledModal({ onClose, onChanged }: { onClose: () => void; onChanged: () => void }) {
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["updates-scheduled"], queryFn: () => updatesApi.scheduled() });
    const qc = useQueryClient();
    const remove = useMutation({ mutationFn: (id: number) => updatesApi.remove(id), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["updates-scheduled"] }); onChanged(); } });
    const now = useMutation({ mutationFn: (id: number) => updatesApi.update(id, { publish_at: null }), onSuccess: () => { toast.success("Posted."); qc.invalidateQueries({ queryKey: ["updates-scheduled"] }); onChanged(); } });
    return (
        <Modal open onClose={onClose} title="Scheduled posts">
            {q.isLoading ? <Spinner /> : (q.data?.data ?? []).length === 0 ? <p className="text-sm text-surface-500">Nothing scheduled.</p> : (
                <ul className="divide-y divide-line text-sm">
                    {q.data!.data.map(p => (
                        <li key={p.id} className="py-2 flex items-center justify-between gap-3">
                            <span><b>{p.title ?? KIND_LABEL[p.kind]}</b><span className="block text-xs text-surface-500">Goes out {when(p.publish_at)}{p.outlet ? ` · ${p.outlet.name}` : ""}{p.must_read ? " · must read" : ""}</span></span>
                            <span className="flex gap-1"><button onClick={() => now.mutate(p.id)} className="btn-ghost btn-sm text-xs">Post now</button><button onClick={() => remove.mutate(p.id)} className="btn-ghost btn-sm text-xs text-danger">Remove</button></span>
                        </li>
                    ))}
                </ul>
            )}
        </Modal>
    );
}

function ComposeModal({ kind: initial, canPost, onClose, onPosted }: { kind: ComposeInput["kind"]; canPost: boolean; onClose: () => void; onPosted: () => void }) {
    const toast = useToastStore();
    const [d, setD] = useState<ComposeInput>({ kind: initial, title: "", body: "", must_read: false, comments_enabled: true, outlet_id: null, publish_at: null, event_at: null, event_place: "", recipient_id: null, points: null, photo: null });
    const people = useQuery({ queryKey: ["updates-people"], queryFn: () => updatesApi.people(), enabled: d.kind === "recognition" });
    const outlets = useQuery({ queryKey: ["outlets-for-updates"], queryFn: () => outletsApi.list(), enabled: canPost && d.kind !== "recognition" });
    const [schedule, setSchedule] = useState(false);
    const save = useMutation({ mutationFn: () => updatesApi.create({ ...d, publish_at: schedule ? d.publish_at : null }), onSuccess: r => { toast.success(r.message); onPosted(); }, onError: (e: ApiError) => toast.error(e.message) });
    const set = <K extends keyof ComposeInput>(k: K, v: ComposeInput[K]) => setD(x => ({ ...x, [k]: v }));
    const ok = d.kind === "recognition" ? !!d.recipient_id && !!d.body?.trim() : !!d.title?.trim() && (d.kind !== "event" || !!d.event_at);
    return (
        <Modal open onClose={onClose} title={d.kind === "recognition" ? "Recognise someone" : d.kind === "event" ? "New event" : "New update"}
            footer={<div className="flex justify-end gap-2"><button onClick={onClose} className="btn-secondary btn-sm">Cancel</button><button onClick={() => save.mutate()} disabled={!ok || save.isPending} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}{schedule && d.publish_at ? "Schedule" : "Post"}</button></div>}>
            <div className="space-y-3 text-sm">
                {canPost && (
                    <div className="flex gap-2 text-xs">{(["update", "event", "recognition"] as const).map(k => <button key={k} onClick={() => set("kind", k)} className={clsx("px-3 py-1 rounded-full border", d.kind === k ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-600")}>{KIND_ICON[k]} {KIND_LABEL[k]}</button>)}</div>
                )}
                {d.kind === "recognition" ? (
                    <>
                        <label className="block">Who<br /><select className="input w-full" value={d.recipient_id ?? ""} onChange={e => set("recipient_id", e.target.value ? Number(e.target.value) : null)}><option value="">Choose a colleague…</option>{(people.data?.data ?? []).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
                        <label className="block">What they did<br /><textarea className="input w-full" rows={3} value={d.body ?? ""} onChange={e => set("body", e.target.value)} placeholder="Stayed late to finish the wedding order…" maxLength={5000} /></label>
                        {canPost && <label className="block">Points <span className="text-xs text-surface-400">(optional; payroll turns them into a bonus only when a point has a value)</span><br /><input type="number" min={0} max={100} className="input w-28" value={d.points ?? ""} onChange={e => set("points", e.target.value ? Number(e.target.value) : null)} /></label>}
                    </>
                ) : (
                    <>
                        <label className="block">Title<br /><input className="input w-full" value={d.title ?? ""} onChange={e => set("title", e.target.value)} maxLength={140} autoFocus /></label>
                        <label className="block">Details<br /><textarea className="input w-full" rows={4} value={d.body ?? ""} onChange={e => set("body", e.target.value)} maxLength={5000} /></label>
                        {d.kind === "event" && <div className="grid grid-cols-2 gap-2"><label className="block">When<br /><input type="datetime-local" className="input w-full" value={d.event_at ?? ""} onChange={e => set("event_at", e.target.value || null)} /></label><label className="block">Where<br /><input className="input w-full" value={d.event_place ?? ""} onChange={e => set("event_place", e.target.value)} maxLength={120} /></label></div>}
                        <label className="block">Photo <span className="text-xs text-surface-400">(optional, up to 5 MB)</span><br /><input type="file" accept="image/jpeg,image/png,image/webp" onChange={e => set("photo", e.target.files?.[0] ?? null)} className="text-xs" /></label>
                        <div className="flex flex-wrap gap-x-4 gap-y-2 text-xs">
                            <label className="inline-flex items-center gap-2">For<select className="input px-2 py-1 text-xs" value={d.outlet_id ?? ""} onChange={e => set("outlet_id", e.target.value ? Number(e.target.value) : null)}><option value="">Everyone</option>{(outlets.data?.data ?? []).map(o => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
                            {d.kind === "update" && <label className="inline-flex items-center gap-2"><input type="checkbox" checked={!!d.must_read} onChange={e => set("must_read", e.target.checked)} />Must read (everyone presses "I have read this")</label>}
                            <label className="inline-flex items-center gap-2"><input type="checkbox" checked={d.comments_enabled !== false} onChange={e => set("comments_enabled", e.target.checked)} />Allow comments</label>
                            <label className="inline-flex items-center gap-2"><input type="checkbox" checked={schedule} onChange={e => setSchedule(e.target.checked)} />Schedule</label>
                            {schedule && <input type="datetime-local" className="input px-2 py-1 text-xs" value={d.publish_at ?? ""} onChange={e => set("publish_at", e.target.value || null)} />}
                        </div>
                    </>
                )}
            </div>
        </Modal>
    );
}
