import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { kiosksApi, kioskToken } from "@/api/people-c2";
import { outletsApi } from "@/api/setup";
import { usePermissions } from "@/hooks/usePermissions";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

/**
 * Attendance › Kiosks (People C2): the tablets at the door. A manager with
 * attendance.manage makes THIS device a kiosk (step-up): the hub mints a
 * kiosk-only token, the console keeps it under its own key, signs the
 * manager out of this device and opens /kiosk. Removing a kiosk here
 * revokes its token; the tablet shows the set-up screen next time.
 */
export function KiosksPanel() {
    const { can } = usePermissions();
    const qc = useQueryClient();
    const toast = useToastStore();
    const navigate = useNavigate();
    const logout = useAuthStore(s => s.logout);
    const [open, setOpen] = useState(false);
    const [outletId, setOutletId] = useState("");
    const [name, setName] = useState("Front door");
    const kiosks = useQuery({ queryKey: ["kiosks"], queryFn: () => kiosksApi.list(), enabled: can("attendance.manage") });
    const outlets = useQuery({ queryKey: ["outlets-for-kiosk"], queryFn: () => outletsApi.list(), enabled: open });
    const setUp = useMutation({
        mutationFn: () => kiosksApi.setUp(Number(outletId), name.trim()),
        onSuccess: async res => {
            kioskToken.set(res.token);
            toast.success(res.message);
            await logout();          // the manager's session leaves this tablet
            navigate("/kiosk", { replace: true });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const remove = useMutation({
        mutationFn: (id: number) => kiosksApi.remove(id),
        onSuccess: res => { toast.success(res.message); qc.invalidateQueries({ queryKey: ["kiosks"] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    if (!can("attendance.manage")) return null;
    const rows = kiosks.data?.data ?? [];
    return (
        <div className="card">
            <div className="card-header flex items-center justify-between">
                <div>
                    <h3 className="font-semibold text-sm text-surface-900">Kiosks</h3>
                    <p className="text-xs text-surface-500">A tablet at the door: tap your name, type your PIN, one photo. No phone or location needed.</p>
                </div>
                <button type="button" onClick={() => setOpen(o => !o)} className="btn-secondary btn-sm">{open ? "Cancel" : "Make this tablet a kiosk"}</button>
            </div>
            <div className="card-body space-y-3">
                {open && (
                    <form onSubmit={e => { e.preventDefault(); if (outletId && name.trim()) setUp.mutate(); }} className="rounded-xl bg-surface-50 p-3 space-y-2 text-sm">
                        <p className="text-surface-600">You will be signed out of <b>this device</b> and the kiosk screen opens. Staff need a terminal PIN (Profile › Security) to punch. You will be asked to confirm it is you.</p>
                        <div className="flex flex-wrap gap-2 items-end">
                            <label className="block">Outlet<br />
                                <select required value={outletId} onChange={e => setOutletId(e.target.value)} className="input px-2 py-1 text-sm">
                                    <option value="">Choose…</option>
                                    {(outlets.data?.data ?? []).map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
                                </select>
                            </label>
                            <label className="block">Name<br /><input required maxLength={40} value={name} onChange={e => setName(e.target.value)} className="input px-2 py-1 text-sm" /></label>
                            <button type="submit" disabled={setUp.isPending || !outletId} className="btn-primary btn-sm">{setUp.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Set up and sign me out</button>
                        </div>
                    </form>
                )}
                {kiosks.isLoading ? <Spinner size="sm" /> : rows.length === 0 ? (
                    <p className="text-sm text-surface-500">No kiosks yet.</p>
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {rows.map(k => (
                            <li key={k.id} className="flex items-center justify-between gap-3 py-2">
                                <span>
                                    <b>{k.name}</b> at {k.outlet ?? "—"}
                                    <span className="block text-xs text-surface-500">Set up by {k.set_up_by ?? "—"}{k.last_used_at ? ` · last used ${new Date(k.last_used_at).toLocaleString("en-KE", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}` : " · not used yet"}</span>
                                </span>
                                <button type="button" onClick={() => { if (confirm(`Remove the kiosk "${k.name}"? The tablet goes back to the set-up screen.`)) remove.mutate(k.id); }} disabled={remove.isPending} className="btn-ghost btn-sm text-xs text-danger">Remove</button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
