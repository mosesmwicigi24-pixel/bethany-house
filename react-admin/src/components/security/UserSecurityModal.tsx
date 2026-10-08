import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { useAuthStore } from "@/store/auth.store";
import { userSecurityApi, tierOf } from "@/api/userSecurity";
import type { UserSetup } from "@/types/setup";
import type { ApiError } from "@/types";

export function lockLabel(u: Pick<UserSetup, "locked_at" | "locked_until">): string | null {
    if (u.locked_at) return "Locked";
    if (u.locked_until && new Date(u.locked_until).getTime() > Date.now()) {
        return `Locked until ${new Date(u.locked_until).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
    }
    return null;
}

/** Who may open this for whom — the same rules the server enforces (StaffAuthority). */
export function canAdministerSignIn(actorRoles: string[], actorId: number | undefined, target: UserSetup): boolean {
    if (!actorId || actorId === target.id) return false;
    if (actorRoles.includes("super_admin")) return true;
    if (actorRoles.includes("system_admin")) return tierOf(target.roles.map((r) => r.name)) >= 2;
    return false;
}

export function canResetClerkPin(actorRoles: string[], actorId: number | undefined, actorOutletId: number | undefined, target: UserSetup): boolean {
    if (!actorId || actorId === target.id || !target.roles.some((r) => r.name === "pos_clerk")) return false;
    if (actorRoles.includes("super_admin")) return true;
    // The server checks every shared outlet; the list shows the primary one.
    return actorRoles.includes("outlet_manager") && !!actorOutletId && actorOutletId === target.outlet?.id;
}

/**
 * Another person's sign-in (Phase 4C): unlock after failed sign-ins, reset
 * two-step sign-in (identity checked out of band; asks for step-up; ends their
 * sessions), see and end their sessions, clear a clerk's terminal PIN.
 */
export function UserSecurityModal({ user, onClose }: { user: UserSetup | null; onClose: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const me = useAuthStore((s) => s.user);
    const myRoles = (me?.roles ?? []).map((r) => r.name);
    const [reason, setReason] = useState("");

    const administer = !!user && canAdministerSignIn(myRoles, me?.id, user);
    const pinReset = !!user && canResetClerkPin(myRoles, me?.id, me?.outlet?.id, user);

    const sessions = useQuery({
        queryKey: ["user-sessions", user?.id],
        queryFn: () => userSecurityApi.sessions(user!.id),
        enabled: !!user && administer,
    });

    const done = (msg: string) => {
        toast.success(msg);
        qc.invalidateQueries({ queryKey: ["admin-users"] });
        qc.invalidateQueries({ queryKey: ["user-sessions", user?.id] });
    };
    const failed = (err: ApiError) => { if (err.reason !== "step_up_cancelled") toast.error(err.message); };

    const unlock = useMutation({ mutationFn: () => userSecurityApi.unlock(user!.id), onSuccess: (r) => done(r.message), onError: failed });
    const reset2fa = useMutation({
        mutationFn: () => userSecurityApi.resetTwoFactor(user!.id, reason.trim()),
        onSuccess: (r) => { setReason(""); done(r.message); },
        onError: failed,
    });
    const endOne = useMutation({ mutationFn: (id: string) => userSecurityApi.revokeSession(user!.id, id), onSuccess: (r) => done(r.message), onError: failed });
    const endAll = useMutation({ mutationFn: () => userSecurityApi.revokeAllSessions(user!.id), onSuccess: (r) => done(r.message), onError: failed });
    const resetPin = useMutation({ mutationFn: () => userSecurityApi.resetTerminalPin(user!.id), onSuccess: (r) => done(r.message), onError: failed });

    const lock = user ? lockLabel(user) : null;
    const rows = sessions.data?.data ?? [];

    return (
        <Modal open={!!user} onClose={onClose} title={user ? `Sign-in security — ${user.first_name} ${user.last_name}` : ""} size="lg">
            {user && (
                <div className="space-y-5 text-sm">
                    {administer && (
                        <section className="space-y-2">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-surface-500">Account lock</h4>
                            <div className="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
                                <p className={lock ? "text-danger font-medium" : "text-surface-600"}>
                                    {lock ?? "Not locked."}{lock && " Too many failed sign-ins."}
                                </p>
                                {lock && (
                                    <button onClick={() => unlock.mutate()} disabled={unlock.isPending} className="btn-primary btn-sm">
                                        {unlock.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Unlock
                                    </button>
                                )}
                            </div>
                        </section>
                    )}

                    {administer && (
                        <section className="space-y-2">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-surface-500">Two-step sign-in</h4>
                            <div className="rounded-lg border border-line p-3 space-y-2">
                                <p className="text-surface-600">
                                    {user.two_factor_enabled ? "On." : "Off."} Reset only after checking it is really them (in person or by a known phone number).
                                    Resetting ends every session they have; if their role requires it they set it up again at next sign-in.
                                </p>
                                {user.two_factor_enabled && (
                                    <div className="flex gap-2">
                                        <input value={reason} onChange={(e) => setReason(e.target.value)} className="input flex-1" placeholder="How you checked it was them" />
                                        <button onClick={() => reset2fa.mutate()} disabled={reset2fa.isPending || reason.trim().length < 5} className="btn-danger btn-sm shrink-0">
                                            {reset2fa.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Reset 2FA
                                        </button>
                                    </div>
                                )}
                            </div>
                        </section>
                    )}

                    {administer && (
                        <section className="space-y-2">
                            <div className="flex items-center justify-between">
                                <h4 className="text-xs font-semibold uppercase tracking-wider text-surface-500">Sessions</h4>
                                {rows.length > 0 && (
                                    <button onClick={() => endAll.mutate()} disabled={endAll.isPending} className="btn-secondary btn-sm text-danger">End all</button>
                                )}
                            </div>
                            {sessions.isLoading ? <Spinner size="sm" /> : rows.length === 0 ? (
                                <p className="text-surface-400">No active sessions.</p>
                            ) : (
                                <ul className="divide-y divide-line rounded-lg border border-line">
                                    {rows.map((s) => (
                                        <li key={s.id} className="flex items-center justify-between gap-3 p-3">
                                            <div className="min-w-0">
                                                <p className="font-medium text-surface-800 truncate">{s.agent}{s.locked && <span className="badge badge-neutral text-2xs ml-2">PIN-locked</span>}</p>
                                                <p className="text-xs text-surface-500">{s.ip} · signed in {new Date(s.signed_in).toLocaleString()} · last used {new Date(s.last_used).toLocaleString()}</p>
                                            </div>
                                            <button onClick={() => endOne.mutate(s.id)} disabled={endOne.isPending} className="btn-ghost btn-sm text-danger shrink-0">End</button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}

                    {pinReset && (
                        <section className="space-y-2">
                            <h4 className="text-xs font-semibold uppercase tracking-wider text-surface-500">Terminal PIN</h4>
                            <div className="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
                                <p className="text-surface-600">Clears the clerk’s PIN; they choose a new one from their profile. Nobody sees it.</p>
                                <button onClick={() => resetPin.mutate()} disabled={resetPin.isPending} className="btn-secondary btn-sm shrink-0">Clear PIN</button>
                            </div>
                        </section>
                    )}

                    {!administer && !pinReset && (
                        <p className="text-surface-500">You can’t change this person’s sign-in. A system administrator handles managers and operators; a super administrator handles everyone else.</p>
                    )}
                </div>
            )}
        </Modal>
    );
}
