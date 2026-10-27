import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { signingApi, type SigningRequest } from "@/api/signing";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { SignaturePad } from "@/components/people/SignaturePad";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** /me/sign — what I have been asked to sign (People C4 part 2), and what I signed or declined. */
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");

export default function SignPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const q = useQuery({ queryKey: ["my-signing"], queryFn: () => signingApi.mine() });
    const rows = q.data?.data ?? [];
    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4 animate-fade-in">
            <div><h1 className="page-title">To sign</h1><p className="page-subtitle">{q.data ? (q.data.pending ? `${q.data.pending} waiting for your signature.` : "Nothing waiting.") : ""}</p></div>
            {q.isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : rows.length === 0 ? <p className="text-sm text-surface-500 py-8 text-center">Nobody has asked you to sign anything.</p> : (
                <ul className="rounded-2xl bg-white border border-line divide-y divide-line">
                    {rows.map(r => (
                        <li key={r.id}><button onClick={() => navigate(`/me/sign/${r.id}`)} className="w-full text-left flex items-center gap-3 px-4 py-3">
                            <span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-surface-900">{r.title}</span><span className="block text-xs text-surface-500">{r.requested_by ? `From ${r.requested_by}` : ""}{r.due_at && r.status === "pending" ? ` · by ${when(r.due_at)}` : ""}{r.signed_at ? ` · signed ${when(r.signed_at)}` : ""}</span></span>
                            <Status r={r} />
                        </button></li>
                    ))}
                </ul>
            )}
            {id && <SignModal id={Number(id)} onClose={() => navigate("/me/sign")} />}
        </div>
    );
}

function Status({ r }: { r: SigningRequest }) {
    const cls = r.status === "pending" ? "bg-danger text-white" : r.status === "signed" ? "bg-success-light text-success" : "bg-surface-100 text-surface-600";
    return <span className={clsx("text-2xs font-bold uppercase rounded-full px-2 py-0.5", cls)}>{r.status === "pending" ? "Sign" : r.status}</span>;
}

function SignModal({ id, onClose }: { id: number; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["my-signing", id], queryFn: () => signingApi.show(id) });
    const r = q.data?.request;
    const [signature, setSignature] = useState<string | null>(null);
    const [declining, setDeclining] = useState(false);
    const [note, setNote] = useState("");
    const done = (m: string) => { toast.success(m); qc.invalidateQueries({ queryKey: ["my-signing"] }); qc.invalidateQueries({ queryKey: ["my-file"] }); onClose(); };
    const sign = useMutation({ mutationFn: () => signingApi.sign(id, signature!), onSuccess: res => done(res.message), onError: (e: ApiError) => toast.error(e.message) });
    const decline = useMutation({ mutationFn: () => signingApi.decline(id, note.trim()), onSuccess: res => done(res.message), onError: (e: ApiError) => toast.error(e.message) });
    return (
        <Modal open onClose={onClose} size="lg" title={r?.title ?? "…"}>
            {!r ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : (
                <div className="space-y-3 text-sm">
                    <p className="text-xs text-surface-500">{r.requested_by ? `Asked by ${r.requested_by} on ${when(r.created_at)}` : ""}{r.due_at ? ` · by ${when(r.due_at)}` : ""}</p>
                    {r.kind === "document" ? (
                        r.document_link ? <a href={r.document_link.url} target="_blank" rel="noreferrer" className="block rounded-xl border border-line bg-surface-50 px-4 py-3 text-brand-600 font-medium">📄 Open the document to read it ›</a> : <p className="text-danger">The document is no longer on your file.</p>
                    ) : <div className="rounded-xl border border-line bg-surface-50 px-4 py-3 whitespace-pre-line text-surface-800 max-h-80 overflow-y-auto">{r.body}</div>}
                    {r.status === "pending" ? (
                        declining ? (
                            <div className="space-y-2">
                                <label className="block text-xs text-surface-500">Why are you not signing?<textarea rows={3} className="input mt-1 w-full" value={note} onChange={e => setNote(e.target.value)} maxLength={1000} /></label>
                                <div className="flex justify-end gap-2"><button onClick={() => setDeclining(false)} className="btn-secondary btn-sm">Back</button><button onClick={() => decline.mutate()} disabled={note.trim().length < 3 || decline.isPending} className="btn-primary btn-sm">Send</button></div>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <p className="font-medium text-surface-900">Your signature</p>
                                <SignaturePad onChange={setSignature} />
                                <p className="text-xs text-surface-500">By signing you confirm you have read and agree to the above. A signing record goes on your file.</p>
                                <div className="flex justify-between gap-2"><button onClick={() => setDeclining(true)} className="btn-ghost btn-sm text-xs text-danger">I can't sign this</button><button onClick={() => sign.mutate()} disabled={!signature || sign.isPending} className="btn-primary btn-sm">{sign.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Sign</button></div>
                            </div>
                        )
                    ) : <p className={clsx("rounded-xl px-3 py-2 text-xs font-medium", r.status === "signed" ? "bg-success-light text-success" : "bg-surface-100 text-surface-600")}>{r.status === "signed" ? `Signed on ${when(r.signed_at)}. The record is among your documents.` : r.status === "declined" ? `Declined: ${r.note}` : "Cancelled by HR."}</p>}
                </div>
            )}
        </Modal>
    );
}
