import { useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { get } from "@/api/client";
import { userSecurityApi } from "@/api/userSecurity";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

interface ClerkRow { id: number; first_name: string; last_name: string; outlet: { id: number; name: string } | null }

/**
 * An outlet manager clears a clerk's terminal PIN when the clerk has forgotten
 * it (Phase 4C); the clerk sets a new one from their profile. Shows the clerks
 * at the manager's outlet — the server checks the outlet is shared, whatever
 * the list shows. super_admin sees every clerk.
 */
export function ClerkPinsPanel() {
    const toast = useToastStore();
    const me = useAuthStore((s) => s.user);
    const roles = (me?.roles ?? []).map((r) => r.name);
    const isOwner = roles.includes("super_admin");
    const isManager = roles.includes("outlet_manager");
    const [open, setOpen] = useState(false);

    const clerks = useQuery({
        queryKey: ["clerks-for-pin"],
        queryFn: () => get<{ data: ClerkRow[] }>("/v1/admin/users/role/pos_clerk"),
        enabled: open && (isOwner || isManager),
    });

    const clear = useMutation({
        mutationFn: (id: number) => userSecurityApi.resetTerminalPin(id),
        onSuccess: (r) => toast.success(r.message),
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (!isOwner && !isManager) return null;

    const rows = (clerks.data?.data ?? []).filter((c) => isOwner || (me?.outlet && c.outlet?.id === me.outlet.id));

    return (
        <div className="card">
            <button type="button" onClick={() => setOpen((o) => !o)} className="card-header w-full flex items-center justify-between text-left">
                <span className="font-semibold text-sm text-surface-900">Clerk terminal PINs</span>
                <span className="text-xs text-surface-500">{open ? "Hide" : "Show"}</span>
            </button>
            {open && (
                <div className="card-body space-y-2">
                    <p className="text-xs text-surface-500">Clearing a PIN lets the clerk choose a new one. Nobody can see a PIN.</p>
                    {clerks.isLoading ? <p className="text-sm text-surface-400">Loading…</p> : rows.length === 0 ? (
                        <p className="text-sm text-surface-400">No clerks at your outlet.</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {rows.map((c) => (
                                <li key={c.id} className="flex items-center justify-between py-2 text-sm">
                                    <span>{c.first_name} {c.last_name}<span className="text-xs text-surface-400 ml-2">{c.outlet?.name ?? ""}</span></span>
                                    <button onClick={() => clear.mutate(c.id)} disabled={clear.isPending} className="btn-secondary btn-sm">Clear PIN</button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
