/**
 * RegisterModal.tsx  (updated)
 *
 * Changes from original:
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. CLOSE MODE — EoD GUARD
 *    When mode="close" and eodSubmitted=false:
 *    - Cash amount input and the "Close Register" button are hidden
 *    - A warning banner explains EoD must be done first
 *    - A "Complete EoD Report First" button fires onRequestEod() so the
 *      parent can open the UserEodModal without dismissing this modal first
 *
 * 2. BACKEND ERROR HANDLING
 *    If the server still returns requires_eod:true (backend safety net),
 *    onError fires onRequestEod() automatically.
 *
 * 3. BLIND COUNT (Phase 4B)
 *    Closing is the first of two steps: the cashier counts the drawer and
 *    submits what is there. She is never shown what it should hold — the
 *    server does not send it (no expected cash, no variance, no sales totals)
 *    and nothing here pre-fills or compares. The till moves to "awaiting
 *    verification"; an outlet manager verifies and finalizes it on the Tills
 *    page. The opening float is typed blind too: no previous count is shown.
 */

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { posApi } from "@/api/pos";
import type { CashRegister, Outlet } from "@/api/pos";
import { useToastStore } from "@/store/toast.store";

interface Props {
    outlets: Outlet[];
    defaultOutletId: number | null;
    mode: "open" | "close";
    register?: CashRegister | null;
    /** Whether the user has already submitted their EoD report today.
     *  When false in close mode, the register cannot be closed. */
    eodSubmitted?: boolean;
    onClose: () => void;
    onSuccess: () => void;
    /** Called when the user clicks "Complete EoD Report First".
     *  The parent should open UserEodModal. */
    onRequestEod?: () => void;
}

export default function RegisterModal({
    outlets,
    defaultOutletId,
    mode,
    register,
    eodSubmitted = false,
    onClose,
    onSuccess,
    onRequestEod,
}: Props) {
    const toast = useToastStore();
    const [outletId, setOutletId] = useState(defaultOutletId ?? outlets[0]?.id);
    // Never pre-filled: a count typed over a suggested figure is not a count.
    const [amount, setAmount] = useState<number>(0);
    const [notes, setNotes] = useState("");
    // Optional physical denomination count (KES note/coin value → quantity).
    const [denoms, setDenoms] = useState<Record<number, number>>({});

    const openMutation = useMutation({
        mutationFn: () =>
            posApi.openRegister({ outlet_id: outletId, opening_cash: amount, notes }),
        onSuccess: () => {
            toast.success("Register opened!");
            onSuccess();
        },
        onError: (err: { message: string }) => toast.error(err.message),
    });

    const closeMutation = useMutation({
        mutationFn: () =>
            posApi.closeRegister({
                outlet_id: outletId,
                closing_cash: amount,
                notes,
                denomination_count: anyDenoms ? denoms : undefined,
            }),
        onSuccess: (res) => {
            // The reply carries no variance by design — the count is blind.
            toast.success(res.message || "Count submitted. Your outlet manager will verify it.");
            onSuccess();
        },
        onError: (err: { message: string; requires_eod?: boolean }) => {
            // Backend safety net: if EoD wasn't done, open the EoD modal
            if (err.requires_eod && onRequestEod) {
                toast.error("Please complete your End of Day report before closing.");
                onClose();
                onRequestEod();
            } else {
                toast.error(err.message);
            }
        },
    });

    const isPending = openMutation.isPending || closeMutation.isPending;

    const DENOMS = [1000, 500, 200, 100, 50, 40, 20, 10, 5, 1];
    const anyDenoms = Object.values(denoms).some((q) => q > 0);
    const setDenom = (d: number, qty: number) => {
        const next = { ...denoms, [d]: Math.max(0, Math.floor(qty || 0)) };
        setDenoms(next);
        setAmount(DENOMS.reduce((sum, x) => sum + x * (next[x] || 0), 0));
    };

    // In close mode, block if EoD not done
    const closeBlocked = mode === "close" && !eodSubmitted;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-slide-up">

                {/* ── Header ── */}
                <div className="p-5 border-b border-line">
                    <h2 className="font-bold text-lg text-surface-900">
                        {mode === "open" ? "Open Cash Register" : "Close Cash Register"}
                    </h2>
                    <p className="text-sm text-surface-500 mt-0.5">
                        {mode === "open"
                            ? "Count the cash in the drawer and enter it as your opening float."
                            : "Count the drawer and enter exactly what is there. Your outlet manager verifies the count."}
                    </p>
                </div>

                {/* ── Body ── */}
                <div className="p-5 space-y-4">

                    {/* ── EoD guard — close mode only ── */}
                    {closeBlocked && (
                        <div className="rounded-xl border border-warning/60 bg-warning/8 p-3 space-y-3">
                            <div className="flex items-start gap-2.5">
                                <svg
                                    className="w-4 h-4 text-warning shrink-0 mt-0.5"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={2}
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                                <div>
                                    <p className="text-xs font-semibold text-warning-dark">
                                        End of Day Report Required
                                    </p>
                                    <p className="text-2xs text-surface-600 mt-0.5 leading-relaxed">
                                        You must complete and submit your End of Day report before
                                        closing the register. This records your daily sales summary
                                        and any observations.
                                    </p>
                                </div>
                            </div>
                            {onRequestEod && (
                                <button
                                    onClick={() => {
                                        onClose();
                                        onRequestEod();
                                    }}
                                    className="w-full btn btn-sm btn-primary gap-2"
                                >
                                    <svg
                                        className="w-3.5 h-3.5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={2}
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                                    </svg>
                                    Complete EoD Report First
                                </button>
                            )}
                        </div>
                    )}

                    {/* Outlet selector — open mode only, multiple outlets */}
                    {outlets.length > 1 && mode === "open" && (
                        <div>
                            <label className="label">Outlet</label>
                            <select
                                value={outletId}
                                onChange={(e) => setOutletId(Number(e.target.value))}
                                className="input"
                            >
                                {outlets.map((o) => (
                                    <option key={o.id} value={o.id}>{o.name}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    {/* Register info — close mode. Blind: float and transaction
                        count only; the expected figure is never shown here. */}
                    {mode === "close" && register && (
                        <div className="bg-surface-50 rounded-xl p-3 space-y-1.5 text-xs text-surface-600">
                            <div className="flex justify-between">
                                <span>Opening float</span>
                                <span>KES {register.opening_cash.toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                            </div>
                            <div className="flex justify-between text-surface-500">
                                <span>Transactions</span>
                                <span>{register.transaction_count}</span>
                            </div>
                            <p className="text-2xs text-surface-500 pt-1.5 border-t border-surface-200">
                                This is a blind count. Once you submit, the till takes no more sales and
                                waits for your outlet manager to verify and finalize it.
                            </p>
                        </div>
                    )}

                    {/* Cash amount input — hidden while close is blocked */}
                    {!closeBlocked && (
                        <>
                            <div>
                                <label className="label">
                                    {mode === "open" ? "Opening Cash Float (counted)" : "Cash Counted in Drawer"}
                                </label>
                                <div className="relative">
                                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-surface-500">
                                        KES
                                    </span>
                                    <input
                                        type="number"
                                        min={0}
                                        step={0.01}
                                        value={amount}
                                        onChange={(e) => setAmount(parseFloat(e.target.value) || 0)}
                                        className="input pl-12 text-lg font-bold"
                                        autoFocus
                                    />
                                </div>
                            </div>

                            {/* Optional denomination count — close mode */}
                            {mode === "close" && (
                                <div className="rounded-xl border border-line p-3">
                                    <div className="flex items-center justify-between mb-2">
                                        <label className="label mb-0">
                                            Count by denomination <span className="text-surface-400 font-normal">(optional)</span>
                                        </label>
                                        {anyDenoms && (
                                            <span className="text-xs text-surface-500">
                                                Total: <strong>KES {amount.toLocaleString("en-KE")}</strong>
                                            </span>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-2 sm:grid-cols-5 gap-2">
                                        {DENOMS.map((d) => (
                                            <div key={d} className="flex items-center gap-1.5">
                                                <span className="text-2xs text-surface-500 w-10 text-right shrink-0">
                                                    {d.toLocaleString("en-KE")}
                                                </span>
                                                <input
                                                    type="number"
                                                    min={0}
                                                    step={1}
                                                    value={denoms[d] || ""}
                                                    onChange={(e) => setDenom(d, parseInt(e.target.value, 10))}
                                                    placeholder="0"
                                                    className="input py-1 text-sm w-full"
                                                />
                                            </div>
                                        ))}
                                    </div>
                                    <p className="text-2xs text-surface-400 mt-2">
                                        Entering counts fills the cash total automatically.
                                    </p>
                                </div>
                            )}

                            {/* Notes */}
                            <div>
                                <label className="label">
                                    Notes <span className="text-surface-400">(optional)</span>
                                </label>
                                <textarea
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    rows={2}
                                    placeholder="Any notes about this register session…"
                                    className="input resize-none"
                                />
                            </div>
                        </>
                    )}
                </div>

                {/* ── Footer ── */}
                <div className="p-5 pt-0 flex gap-3">
                    <button
                        onClick={onClose}
                        className="btn-secondary flex-1"
                        disabled={isPending}
                    >
                        Cancel
                    </button>

                    {/* Only show the action button when not blocked */}
                    {!closeBlocked && (
                        <button
                            onClick={() =>
                                mode === "open"
                                    ? openMutation.mutate()
                                    : closeMutation.mutate()
                            }
                            disabled={isPending || amount < 0}
                            className={clsx(
                                "flex-1 btn gap-2",
                                mode === "open" ? "btn-primary" : "btn-danger",
                            )}
                        >
                            {isPending && (
                                <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                            )}
                            {mode === "open" ? "Open Register" : "Submit Count"}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}