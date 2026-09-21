import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { DOWNLOAD_HELD_EVENT, downloadsApi, type HeldDownload } from "@/api/downloads";

/**
 * Opens anywhere in the console when the server holds a download for approval
 * (api/client.ts and heldFromFetch() announce it). The person adds a reason;
 * the server turns its own record of that exact attempt into a request, so an
 * approver sees what was really asked for.
 */
export function DownloadApprovalDialog() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const navigate = useNavigate();
    const [held, setHeld] = useState<HeldDownload | null>(null);
    const [reason, setReason] = useState("");
    const [sending, setSending] = useState(false);

    useEffect(() => {
        const onHeld = (e: Event) => {
            setHeld((e as CustomEvent<HeldDownload>).detail);
            setReason("");
        };
        window.addEventListener(DOWNLOAD_HELD_EVENT, onHeld);
        return () => window.removeEventListener(DOWNLOAD_HELD_EVENT, onHeld);
    }, []);

    const filters = held
        ? Object.entries(held.download.payload ?? {})
              .filter(([, v]) => v !== "" && v !== null && v !== undefined)
              .map(([k, v]) => `${k}: ${typeof v === "object" ? JSON.stringify(v) : String(v)}`)
        : [];

    const send = async () => {
        if (!held || reason.trim().length < 5) return;
        setSending(true);
        try {
            await downloadsApi.ask(held.held, reason.trim());
            qc.invalidateQueries({ queryKey: ["my-downloads"] });
            toast.success("Sent for approval. You'll be notified — it will be waiting under My downloads.");
            setHeld(null);
        } catch (err: any) {
            toast.error(err?.message ?? "Could not send the request.");
        } finally {
            setSending(false);
        }
    };

    return (
        <Modal
            open={!!held}
            onClose={() => setHeld(null)}
            title="This download needs approval"
            size="sm"
            footer={
                <>
                    <button onClick={() => setHeld(null)} className="btn-secondary btn-sm">Not now</button>
                    <button onClick={send} disabled={sending || reason.trim().length < 5} className="btn-primary btn-sm">
                        {sending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        Ask for approval
                    </button>
                </>
            }
        >
            {held && (
                <div className="space-y-4 text-sm">
                    <div className="rounded-lg bg-surface-50 p-3">
                        <p className="font-medium text-surface-800">{held.download.label}</p>
                        {filters.length > 0 && (
                            <p className="mt-1 font-mono text-[11px] text-surface-500 break-all">{filters.join(" · ")}</p>
                        )}
                    </div>
                    <p className="text-surface-600">
                        Files taken out of Bethany Hub are approved first. Say what you need it for — the approver sees
                        exactly this download and your reason.
                    </p>
                    <div>
                        <label className="label">Reason</label>
                        <textarea
                            className="input mt-1 min-h-[88px]"
                            placeholder="e.g. Month-end reconciliation with the bank statement"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            autoFocus
                        />
                    </div>
                    <button
                        type="button"
                        onClick={() => { setHeld(null); navigate("/settings/my-downloads"); }}
                        className="text-xs text-brand-600 hover:underline"
                    >
                        See my download requests
                    </button>
                </div>
            )}
        </Modal>
    );
}
