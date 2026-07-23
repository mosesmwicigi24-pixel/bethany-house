/**
 * PaymentModal.tsx - Configured-methods driven
 *
 * All payment method buttons are now rendered dynamically from the
 * `configuredMethods` prop (loaded from the payment_methods table),
 * not from a hardcoded list.
 *
 * Per-method flows:
 *  - cash           → cash-received + coin presets + change display
 *  - mpesa          → STK Push sub-flow (phone → push → poll) OR manual
 *                     code entry with "Verify via Daraja" option
 *  - paystack / card → email input → server initiates Paystack → redirect URL
 *                     shown (or auto-opens); cashier confirms when customer
 *                     completes payment
 *  - bank_transfer / other → reference input + proof-of-payment upload
 *  - any method     → deposit mode toggle + split payment mode
 *
 * Props:
 *  total             – order grand total
 *  currency          – currency code (e.g. "KES")
 *  orderId           – ID of the already-created POS order (needed for STK push
 *                      and Paystack initiation).  When undefined the modal falls
 *                      back to manual-only flows.
 *  configuredMethods – active payment methods from DB (pass [] to get a
 *                      "no methods configured" warning instead of crashing)
 *  onCharge          – called with the finalised payment list when the cashier
 *                      confirms a non-STK payment
 *  onStkComplete     – called after a successful STK push callback poll so the
 *                      parent can close the modal and show the receipt
 *  onClose
 *  isProcessing      – true while the parent is submitting the sale
 */

import { useState, useRef, useEffect, useCallback } from "react";
import { Link } from "react-router-dom";
import { clsx } from "clsx";
import { post, get } from "@/api/client";
import { useToastStore } from "@/store/toast.store";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface ConfiguredMethod {
    id: number;
    code: string;
    name: string;
    type: "mobile_money" | "card" | "cash" | "bank_transfer" | string;
    provider?: string | null;
    description?: string | null;
    is_default?: boolean;
    /** Effective approval policy from the backend. true → hold for admin review
     *  (proof required); false → settles instantly like cash (e.g. I&M Paybill). */
    requires_approval?: boolean;
    /** Currency codes this method supports. Empty / undefined = all currencies. */
    supported_currencies?: string[] | null;
}

export interface SplitPayment {
    id: string;
    method: string;
    amount: number;
    reference?: string;
    cashReceived?: number;
    proofFile?: File;
}

interface Props {
    total: number;
    currency: string;
    /** ID of the already-created POS order - always required for the two-step flow */
    orderId: number;
    configuredMethods: ConfiguredMethod[];
    /** Called when payment is confirmed (non-STK, non-Paystack flows) */
    onCharge: (payments: SplitPayment[], depositAmount?: number, proofFile?: File) => void;
    /** Called after STK push or Daraja verification confirms payment server-side */
    onStkComplete?: () => void;
    onClose: () => void;
    isProcessing: boolean;
    /** True while the pending order is being created server-side (step 1) */
    isCreatingOrder?: boolean;
    /** Whether product prices already include tax (from business settings) */
    taxInclusive?: boolean;
    /** Pre-calculated total tax amount across all cart lines */
    taxAmount?: number;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtAmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

const cashPresets = (amount: number) => {
    const rounded = Math.ceil(amount / 100) * 100;
    return [...new Set([rounded, rounded + 100, rounded + 500, 1000, 2000, 5000])]
        .filter((v) => v >= amount)
        .slice(0, 5);
};

// Derive a display icon based on method type / provider / code
function methodIcon(m: ConfiguredMethod, size = "w-5 h-5") {
    const s = { className: size, fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    if (m.code === "__other__")
        return <svg {...s}><circle cx="12" cy="12" r="10"/><path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>;
    if (m.code === "cash" || m.type === "cash")
        return <svg {...s}><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>;
    if (m.code === "mpesa" || m.provider === "safaricom" || m.type === "mobile_money")
        return <svg {...s}><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>;
    if (m.type === "card" || m.code.includes("paystack") || m.code.includes("card") || m.code.includes("flutterwave"))
        return <svg {...s}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>;
    if (m.type === "bank_transfer" || m.code === "bank_transfer")
        return <svg {...s}><path d="M3 22v-8m6 8V10m6 12V8m6 14V4"/><path d="M2 22h20"/></svg>;
    return <svg {...s}><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>;
}

function methodColour(m: ConfiguredMethod) {
    if (m.code === "__other__")                           return { active: "bg-purple-50 border-purple-300 text-purple-800",     dot: "bg-purple-500"   };
    if (m.code === "cash" || m.type === "cash")          return { active: "bg-success-light border-success text-success-dark",  dot: "bg-success"      };
    if (m.code === "mpesa" || m.type === "mobile_money") return { active: "bg-green-50 border-green-400 text-green-800",         dot: "bg-green-500"    };
    if (m.type === "card")                               return { active: "bg-info-light border-info text-info",                 dot: "bg-info"         };
    if (m.type === "bank_transfer")                      return { active: "bg-surface-100 border-surface-400 text-surface-700", dot: "bg-surface-500"  };
    return                                                      { active: "bg-brand-50 border-brand-400 text-brand-700",         dot: "bg-brand-500"    };
}

const isMpesa     = (m: ConfiguredMethod) => m.code === "mpesa" || m.provider === "safaricom";
const isPaystack  = (m: ConfiguredMethod) => m.code === "paystack" || m.provider === "paystack" || m.code === "card_paystack";
const isCard      = (m: ConfiguredMethod) => m.type === "card" || m.code.includes("card") || m.code.includes("flutterwave");
const isCash      = (m: ConfiguredMethod) => m.type === "cash" || m.code === "cash";
const isBank      = (m: ConfiguredMethod) => m.type === "bank_transfer" || m.code === "bank_transfer";
const isOther     = (m: ConfiguredMethod) => m.code === "__other__";
// Proof of payment is required only for methods that must be held for admin
// approval. The backend's effective `requires_approval` flag is the source of
// truth (so I&M Paybill, which settles instantly, needs no proof and is NOT
// held in "Processing"); fall back to the legacy type heuristic when the flag
// isn't present.
const needsProof  = (m: ConfiguredMethod) =>
    m.requires_approval ?? (isBank(m) || isOther(m) || (!isMpesa(m) && !isPaystack(m) && !isCash(m)));

/** Virtual "Other" method always appended after configured methods */
const OTHER_METHOD: ConfiguredMethod = {
    id: -1,
    code: "__other__",
    name: "Other",
    type: "bank_transfer",
    provider: null,
    description: "Any other payment method - add reference and proof of payment",
};

// ── Proof upload ──────────────────────────────────────────────────────────────

function ProofUploadZone({ file, onChange }: { file: File | null; onChange: (f: File | null) => void }) {
    const ref                             = useRef<HTMLInputElement>(null);
    const [compressing, setCompressing]   = useState(false);
    const [origSize,    setOrigSize]      = useState<number>(0);

    const handleSelect = async (f: File) => {
        setOrigSize(f.size);
        if (f.type.startsWith("image/")) {
            setCompressing(true);
            try {
                const { compressImage } = await import("@/utils/compressImage");
                onChange(await compressImage(f).catch(() => f));
            } finally {
                setCompressing(false);
            }
        } else {
            onChange(f);
        }
    };

    return (
        <div className={clsx("rounded-xl border-2 border-dashed transition-colors p-3 space-y-2",
            file ? "border-success bg-success-light/30" : "border-surface-200 bg-surface-50")}>
            <div className="flex items-center gap-3">
                <div className={clsx("w-8 h-8 rounded-lg flex items-center justify-center shrink-0",
                    compressing ? "bg-brand-100 text-brand-500" :
                    file ? "bg-success text-white" : "bg-surface-200 text-surface-400")}>
                    {compressing
                        ? <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        : file
                            ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>}
                </div>
                <div className="flex-1 min-w-0">
                    <p className="text-xs font-semibold text-surface-800">
                        Proof of Payment
                        <span className="ml-1 text-2xs font-normal text-surface-400">PDF or image, max 10 MB</span>
                    </p>
                    {compressing
                        ? <p className="text-2xs text-brand-600 font-medium mt-0.5">Compressing image…</p>
                        : file
                            ? <div className="flex items-center gap-2 mt-0.5">
                                <p className="text-2xs text-success-dark font-medium truncate">{file.name}</p>
                                {origSize > file.size && (
                                    <span className="text-2xs text-surface-400 shrink-0">
                                        {Math.round(origSize / 1024)}KB → {Math.round(file.size / 1024)}KB
                                    </span>
                                )}
                                <button onClick={() => { onChange(null); setOrigSize(0); }} className="text-2xs text-danger hover:underline shrink-0">Remove</button>
                              </div>
                            : <p className="text-2xs text-surface-400 mt-0.5">Attach bank receipt, wire confirmation, or screenshot.</p>}
                </div>
            </div>
            <input ref={ref} type="file" accept=".pdf,image/*" className="hidden"
                onChange={e => { const f = e.target.files?.[0]; if (f) handleSelect(f); e.target.value = ""; }} />
            {!file && !compressing && (
                <button onClick={() => ref.current?.click()} className="w-full btn-secondary btn-sm text-xs">
                    Choose File
                </button>
            )}
        </div>
    );
}

// ── M-Pesa panel - STK Push + manual code always shown together ───────────────

type StkStep = "idle" | "waiting" | "confirmed" | "failed";

function MpesaPanel({
    orderId,
    total,
    currency,
    customerPhone,
    onConfirmManual,
    onStkComplete,
}: {
    orderId: number;
    total: number;
    currency: string;
    customerPhone?: string;
    onConfirmManual: (ref: string) => void;
    onStkComplete?: () => void;
}) {
    const toast = useToastStore();

    // ── STK Push state ────────────────────────────────────────────────────────
    const [phone, setPhone]       = useState(customerPhone ?? "");
    const [stkStep, setStkStep]   = useState<StkStep>("idle");
    const [stkError, setStkError] = useState("");
    const [pushing, setPushing]   = useState(false);

    // ── Manual / Daraja state ─────────────────────────────────────────────────
    const [manualRef, setManualRef]   = useState("");
    const [verifying, setVerifying]   = useState(false);
    const [verifyMsg, setVerifyMsg]   = useState<{ ok: boolean; text: string } | null>(null);

    const pollRef   = useRef<ReturnType<typeof setInterval>>();
    const pollCount = useRef(0);
    const MAX_POLLS = 18; // ~3 min at 10 s intervals

    const stopPolling = () => { if (pollRef.current) clearInterval(pollRef.current); };
    useEffect(() => () => stopPolling(), []);

    // ── STK Push ──────────────────────────────────────────────────────────────
    const initiateStkPush = async () => {
        if (!orderId) return;
        setPushing(true);
        setStkError("");
        try {
            await post(`/v1/admin/orders/${orderId}/payment`, {
                payment_method: "mpesa",
                phone: phone.trim(),
            });
            setStkStep("waiting");
            pollCount.current = 0;
            pollRef.current = setInterval(async () => {
                pollCount.current += 1;
                if (pollCount.current >= MAX_POLLS) {
                    stopPolling();
                    setStkStep("failed");
                    setStkError("No confirmation received within 3 minutes. Use the transaction code below to complete manually.");
                    return;
                }
                try {
                    const res = await get<any>(`/v1/admin/orders/${orderId}`);
                    const status = res?.order?.payment_status ?? res?.payment_status;
                    if (status === "paid" || status === "partial") {
                        stopPolling();
                        setStkStep("confirmed");
                        toast.success("M-Pesa payment confirmed!");
                        setTimeout(() => onStkComplete?.(), 1200);
                    }
                } catch { /* ignore transient poll errors */ }
            }, 10_000);
        } catch (e: any) {
            setStkError(e.message ?? "Failed to send STK push. Check the phone number and try again.");
            setStkStep("idle");
        } finally {
            setPushing(false);
        }
    };

    const cancelStk = () => { stopPolling(); setStkStep("idle"); setStkError(""); };

    // ── Manual / Daraja verify ────────────────────────────────────────────────
    const verifyManual = async () => {
        if (!manualRef.trim()) return;
        setVerifying(true);
        setVerifyMsg(null);
        try {
            if (orderId) {
                // Attempt Daraja verification against any pending M-Pesa payment
                const orderRes = await get<any>(`/v1/admin/orders/${orderId}`);
                const pendingPayment = orderRes?.order?.payments?.find(
                    (p: any) => p.status === "pending" && p.payment_method === "mpesa"
                );
                if (pendingPayment) {
                    await post(
                        `/v1/admin/orders/${orderId}/payments/${pendingPayment.id}/verify-mpesa`,
                        { code: manualRef.trim() }
                    );
                    stopPolling(); // cancel any running STK poll
                    setStkStep("confirmed");
                    setVerifyMsg({ ok: true, text: "Verified with Daraja - payment confirmed!" });
                    toast.success("Payment verified via Daraja!");
                    setTimeout(() => onStkComplete?.(), 1200);
                    return;
                }
            }
            // No pending payment found or no orderId - fall back to manual record
            onConfirmManual(manualRef.trim());
        } catch {
            // Daraja verification failed - still allow cashier to manually confirm
            setVerifyMsg({ ok: false, text: "Daraja verification failed. Recording as manual confirmation." });
            onConfirmManual(manualRef.trim());
        } finally {
            setVerifying(false);
        }
    };

    const stkAvailable = !!orderId;

    return (
        <div className="space-y-0">

            {/* ── Section 1: STK Push ─────────────────────────────────────── */}
            {stkAvailable && (
                <div className="rounded-xl border border-green-200 bg-green-50/60 overflow-hidden">
                    {/* Section header */}
                    <div className="flex items-center gap-2.5 px-4 py-2.5 border-b border-green-200/70 bg-green-100/50">
                        <div className="w-6 h-6 rounded-lg bg-green-600 flex items-center justify-center shrink-0">
                            <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 8.25h3m-3 4.5h3"/>
                            </svg>
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-bold text-green-900">STK Push</p>
                            <p className="text-2xs text-green-700">Send a payment prompt directly to the customer's phone</p>
                        </div>
                        {stkStep === "waiting" && (
                            <span className="flex items-center gap-1 text-2xs text-green-700 font-semibold shrink-0">
                                <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
                                Waiting…
                            </span>
                        )}
                        {stkStep === "confirmed" && (
                            <span className="flex items-center gap-1 text-2xs text-success font-semibold shrink-0">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Confirmed
                            </span>
                        )}
                    </div>

                    <div className="px-4 py-3 space-y-3">
                        {/* Confirmed state */}
                        {stkStep === "confirmed" && (
                            <div className="flex items-center gap-3 py-2">
                                <div className="w-9 h-9 rounded-full bg-success flex items-center justify-center shrink-0">
                                    <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </div>
                                <div>
                                    <p className="text-sm font-bold text-success-dark">M-Pesa Payment Confirmed!</p>
                                    <p className="text-2xs text-success-dark/70">Processing receipt…</p>
                                </div>
                            </div>
                        )}

                        {/* Waiting state */}
                        {stkStep === "waiting" && (
                            <div className="space-y-2.5">
                                <div className="flex items-center gap-3 bg-green-100 rounded-xl px-3 py-2.5">
                                    <div className="w-7 h-7 border-[2.5px] border-green-500 border-t-transparent rounded-full animate-spin shrink-0" />
                                    <div className="flex-1 min-w-0">
                                        <p className="text-xs font-semibold text-green-800">Prompt sent to {phone}</p>
                                        <p className="text-2xs text-green-600">Ask the customer to enter their M-Pesa PIN now.</p>
                                    </div>
                                </div>
                                <button onClick={cancelStk}
                                    className="w-full text-xs text-surface-400 hover:text-danger transition-colors py-1 underline underline-offset-2">
                                    Cancel STK push
                                </button>
                            </div>
                        )}

                        {/* Idle / failed state - phone input + send button */}
                        {(stkStep === "idle" || stkStep === "failed") && (
                            <>
                                {stkError && (
                                    <div className="bg-danger-light rounded-lg px-3 py-2 text-2xs text-danger leading-snug">
                                        {stkError}
                                    </div>
                                )}
                                <div className="flex gap-2">
                                    <input
                                        type="tel"
                                        value={phone}
                                        onChange={e => setPhone(e.target.value)}
                                        placeholder="+254 700 000 000"
                                        className="input text-sm font-mono flex-1 min-w-0"
                                    />
                                    <button
                                        onClick={initiateStkPush}
                                        disabled={pushing || !phone.trim()}
                                        className="shrink-0 px-4 py-2 rounded-xl text-xs font-bold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5 whitespace-nowrap">
                                        {pushing
                                            ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                            : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                        }
                                        {pushing ? "Sending…" : "Send Push"}
                                    </button>
                                </div>
                                <p className="text-2xs text-green-700/70">
                                    Customer will receive a prompt on their phone to enter their PIN.
                                </p>
                            </>
                        )}
                    </div>
                </div>
            )}

            {/* ── Divider ─────────────────────────────────────────────────── */}
            <div className="relative flex items-center py-3">
                <div className="flex-1 border-t border-surface-150" />
                <span className="mx-3 text-2xs font-semibold text-surface-400 uppercase tracking-widest bg-white px-1">
                    {stkAvailable ? "or confirm with transaction code" : "Enter transaction code"}
                </span>
                <div className="flex-1 border-t border-surface-150" />
            </div>

            {/* ── Section 2: Manual code + Daraja verify ──────────────────── */}
            <div className="rounded-xl border border-surface-200 bg-white overflow-hidden">
                <div className="flex items-center gap-2.5 px-4 py-2.5 border-b border-surface-100 bg-surface-50">
                    <div className="w-6 h-6 rounded-lg bg-surface-700 flex items-center justify-center shrink-0">
                        <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-xs font-bold text-surface-900">Transaction Code</p>
                        <p className="text-2xs text-surface-500">
                            {orderId
                                ? "Enter code from customer's SMS - verified with Daraja before confirming"
                                : "Enter the M-Pesa confirmation code from the customer's SMS"}
                        </p>
                    </div>
                </div>

                <div className="px-4 py-3 space-y-3">
                    {verifyMsg && (
                        <div className={clsx("rounded-lg px-3 py-2 text-2xs leading-snug font-medium",
                            verifyMsg.ok ? "bg-success-light text-success-dark" : "bg-warning-light text-warning-dark")}>
                            {verifyMsg.text}
                        </div>
                    )}

                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={manualRef}
                            onChange={e => setManualRef(e.target.value.toUpperCase())}
                            placeholder="e.g. QHK7XXXXXYZ"
                            className="input font-mono tracking-widest text-sm flex-1 min-w-0"
                            disabled={stkStep === "confirmed"}
                        />
                        <button
                            onClick={verifyManual}
                            disabled={verifying || !manualRef.trim() || stkStep === "confirmed"}
                            className="shrink-0 px-4 py-2 rounded-xl text-xs font-bold text-white bg-surface-800 hover:bg-surface-900 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5 whitespace-nowrap">
                            {verifying
                                ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>}
                            {verifying ? "Checking…" : orderId ? "Verify & Confirm" : "Confirm"}
                        </button>
                    </div>

                    {orderId && stkStep !== "confirmed" && (
                        <p className="text-2xs text-surface-400">
                            Code will be validated with Safaricom Daraja before the payment is recorded.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Paystack panel ────────────────────────────────────────────────────────────

function PaystackPanel({
    orderId,
    total,
    currency,
    customerEmail,
    onConfirm,
    onStkComplete,
}: {
    orderId: number;
    total: number;
    currency: string;
    customerEmail?: string;
    onConfirm: (ref: string) => void;
    onStkComplete?: () => void;
}) {
    const toast = useToastStore();
    const [email, setEmail]           = useState(customerEmail ?? "");
    const [initiating, setInitiating] = useState(false);
    const [payUrl, setPayUrl]         = useState<string | null>(null);
    const [ref, setRef]               = useState("");
    const [error, setError]           = useState("");
    const [polling, setPolling]       = useState(false);
    const [confirmed, setConfirmed]   = useState(false);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => () => { if (pollRef.current) clearInterval(pollRef.current); }, []);

    const startPolling = () => {
        setPolling(true);
        let count = 0;
        pollRef.current = setInterval(async () => {
            count++;
            try {
                const res = await get<any>(`/v1/admin/orders/${orderId}`);
                const status = res?.order?.payment_status ?? res?.payment_status;
                if (status === "paid") {
                    clearInterval(pollRef.current!);
                    pollRef.current = null;
                    setPolling(false);
                    setConfirmed(true);
                    toast.success("Paystack payment confirmed ✓");
                    setTimeout(() => onStkComplete?.(), 1000);
                    return;
                }
            } catch { /* ignore */ }
            if (count >= 24) { // ~2 min
                clearInterval(pollRef.current!);
                pollRef.current = null;
                setPolling(false);
            }
        }, 5000);
    };

    const initiate = async () => {
        if (!email.trim()) return;
        setInitiating(true);
        setError("");
        try {
            const res = await post<{ authorization_url: string }>(`/v1/admin/orders/${orderId}/payment`, {
                payment_method: "card_paystack",
                email: email.trim(),
            });
            if (res.authorization_url) {
                setPayUrl(res.authorization_url);
                window.open(res.authorization_url, "_blank", "noopener");
                startPolling();
            }
        } catch (e: any) {
            setError(e.message ?? "Could not initiate Paystack payment");
        } finally {
            setInitiating(false);
        }
    };

    if (confirmed) {
        return (
            <div className="rounded-xl bg-success-light border border-success/30 px-4 py-5 text-center space-y-2">
                <div className="w-10 h-10 rounded-full bg-success flex items-center justify-center mx-auto">
                    <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                </div>
                <p className="text-sm font-bold text-success-dark">Payment Confirmed</p>
                <p className="text-xs text-success-dark/70">Paystack payment verified successfully.</p>
            </div>
        );
    }

    if (payUrl) {
        return (
            <div className="space-y-4">
                <div className="bg-info-light border border-info/30 rounded-xl px-4 py-3 text-xs text-info">
                    <div className="flex items-center gap-2 mb-1">
                        <p className="font-semibold">Paystack payment page opened</p>
                        {polling && (
                            <span className="flex items-center gap-1 text-2xs font-medium">
                                <span className="w-1.5 h-1.5 rounded-full bg-info animate-pulse"/>
                                Waiting for confirmation…
                            </span>
                        )}
                    </div>
                    <p className="opacity-80">
                        Ask the customer to complete payment on the Paystack page.
                        This will auto-confirm once payment is received, or enter the reference below.
                    </p>
                </div>
                <button onClick={() => window.open(payUrl, "_blank", "noopener")} className="btn-secondary w-full text-xs gap-2">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    Re-open Paystack page
                </button>
                <div>
                    <label className="label">Manual Reference <span className="text-surface-400 font-normal">(if auto-confirm doesn't trigger)</span></label>
                    <input type="text" value={ref} onChange={e => setRef(e.target.value)}
                        className="input font-mono" placeholder="e.g. T123456789" />
                    <p className="mt-1 text-2xs text-surface-400">
                        Copy the transaction reference from the Paystack confirmation page or SMS.
                    </p>
                </div>
                <button
                    onClick={() => onConfirm(ref)}
                    disabled={!ref.trim()}
                    className="btn-secondary w-full gap-2 disabled:opacity-40 disabled:cursor-not-allowed text-sm">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Confirm with Reference
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5 text-xs text-blue-700">
                <p className="font-semibold">Paystack card payment</p>
                <p className="mt-0.5 opacity-80">
                    Enter the customer's email to open a secure Paystack payment page.
                    Payment will be auto-confirmed once the customer completes it.
                </p>
            </div>
            <div>
                <label className="label">Customer Email <span className="text-danger">*</span></label>
                <input type="email" value={email} onChange={e => setEmail(e.target.value)}
                    placeholder="customer@example.com" className="input" autoFocus />
            </div>
            {error && <div className="bg-danger-light rounded-xl px-3 py-2 text-xs text-danger">{error}</div>}
            <button onClick={initiate} disabled={initiating || !email.trim()}
                className="btn-primary w-full gap-2 disabled:opacity-50">
                {initiating
                    ? <><div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />Opening Paystack…</>
                    : <><svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>Open Paystack Payment Page →</>}
            </button>
        </div>
    );
}

// ── Cash panel ────────────────────────────────────────────────────────────────

function CashPanel({ total, currency, onConfirm, allowPartial = false }: {
    total: number; currency: string; onConfirm: (cashReceived: number) => void; allowPartial?: boolean;
}) {
    const [cashReceived, setCashReceived] = useState(Math.ceil(total / 100) * 100 || total);

    const change  = Math.max(0, cashReceived - total);
    const isShort = cashReceived < total && cashReceived > 0;
    // With allowPartial, a short entry is accepted as a partial payment (the
    // balance stays owed) instead of being blocked; the amount actually
    // collected is then min(entered, total).
    const collected = allowPartial ? Math.min(cashReceived, total) : total;

    return (
        <div className="space-y-3">
            <div>
                <label className="label">Cash Received</label>
                <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-surface-400">{currency}</span>
                    <input type="number" min={allowPartial ? 0.01 : total} step={0.01} value={cashReceived}
                        onChange={e => setCashReceived(parseFloat(e.target.value) || 0)}
                        className="input pl-12 text-xl font-bold" autoFocus />
                </div>
            </div>
            <div className="flex gap-2 flex-wrap">
                {cashPresets(total).map(p => (
                    <button key={p} onClick={() => setCashReceived(p)}
                        className={clsx("px-3 py-1.5 rounded-lg border text-xs font-semibold transition-all",
                            cashReceived === p ? "bg-brand-500 text-white border-brand-500" : "bg-white border-surface-200 text-surface-700 hover:border-brand-300")}>
                        {p.toLocaleString("en-KE")}
                    </button>
                ))}
            </div>
            {cashReceived > 0 && (
                <div className={clsx("rounded-xl p-3 flex items-center justify-between",
                    change > 0 ? "bg-success-light" : isShort ? (allowPartial ? "bg-warning-light" : "bg-danger-light") : "bg-surface-50")}>
                    <span className={clsx("text-sm font-medium", change > 0 ? "text-success-dark" : isShort ? (allowPartial ? "text-warning-dark" : "text-danger") : "text-surface-600")}>
                        {isShort ? (allowPartial ? "Balance due" : "Short by") : "Change"}
                    </span>
                    <span className={clsx("text-xl font-bold", change > 0 ? "text-success-dark" : isShort ? (allowPartial ? "text-warning-dark" : "text-danger") : "text-surface-600")}>
                        {currency} {Math.abs(cashReceived - total).toLocaleString("en-KE", { minimumFractionDigits: 2 })}
                    </span>
                </div>
            )}
            <button
                onClick={() => onConfirm(cashReceived)}
                disabled={allowPartial ? cashReceived <= 0 : cashReceived < total}
                className="btn-primary w-full gap-2 disabled:opacity-40 disabled:cursor-not-allowed">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                Confirm - {currency} {fmtAmt(collected)}
            </button>
        </div>
    );
}

// ── Generic reference + proof panel ──────────────────────────────────────────

function ReferencePanel({ method, currency, total, onConfirm }: {
    method: ConfiguredMethod; currency: string; total: number;
    onConfirm: (ref: string, proof: File | null, customMethodName?: string) => void;
}) {
    const [ref, setRef]               = useState("");
    const [proof, setProof]           = useState<File | null>(null);
    const [customName, setCustomName] = useState("");
    const isOtherMethod = isOther(method);
    const isBankMethod  = method.type === "bank_transfer" || method.code === "bank_transfer";

    // Validation: for "Other", method name is required; reference always required
    const canConfirm = ref.trim().length > 0 && (!isOtherMethod || customName.trim().length > 0);

    return (
        <div className="space-y-3">
            {/* Admin approval notice - shown for all non-integrated methods */}
            <div className="flex items-start gap-2.5 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
                <svg className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                </svg>
                <div className="text-2xs text-amber-800 leading-snug">
                    <span className="font-bold">Requires admin approval.</span> This payment will be held as pending until an administrator reviews and approves it. The order cannot be processed until approval is granted.
                </div>
            </div>

            {/* Custom method name - required for "Other" */}
            {isOtherMethod && (
                <div>
                    <label className="label">
                        Payment Method Name <span className="text-danger">*</span>
                    </label>
                    <input
                        type="text"
                        value={customName}
                        onChange={e => setCustomName(e.target.value)}
                        placeholder="e.g. Cheque, Wire Transfer, PayPal, RTGS…"
                        className={clsx("input", !customName.trim() && "border-warning")}
                        autoFocus
                    />
                    <p className="mt-1 text-2xs text-surface-400">
                        Specify exactly how the customer is paying - this appears on the receipt and approval request.
                    </p>
                </div>
            )}

            {/* Reference - required for all */}
            <div>
                <label className="label">
                    {isBankMethod ? "Transfer Reference" : isOtherMethod ? "Payment Reference" : "Reference / Code"}
                    {" "}<span className="text-danger">*</span>
                </label>
                <input
                    type="text"
                    value={ref}
                    onChange={e => setRef(e.target.value)}
                    placeholder={
                        isBankMethod ? "Bank transfer / RTGS reference number" :
                        isOtherMethod ? "Transaction ID, cheque number, or confirmation code…" :
                        "Approval / reference code"
                    }
                    className={clsx("input", !ref.trim() && "border-warning")}
                    autoFocus={!isOtherMethod}
                />
                {!ref.trim() && (
                    <p className="mt-1 text-2xs text-warning-dark">A reference number is required to trace this payment.</p>
                )}
            </div>

            {/* Proof upload - always shown, optional (encouraged for bank/other) */}
            <div>
                <div className="flex items-center gap-1.5 mb-1.5">
                    <p className="text-2xs font-semibold text-surface-700">
                        Proof of Payment
                    </p>
                    {(isBankMethod || isOtherMethod) && (
                        <span className="text-2xs text-amber-700 font-medium">- strongly encouraged</span>
                    )}
                </div>
                <ProofUploadZone file={proof} onChange={setProof} />
                {(isBankMethod || isOtherMethod) && !proof && (
                    <p className="mt-1 text-2xs text-surface-400">
                        Attach a bank receipt, transfer confirmation, or screenshot. The admin can still approve without proof, but it helps speed up the process.
                    </p>
                )}
            </div>

            <button
                onClick={() => onConfirm(ref, proof, isOtherMethod ? customName : undefined)}
                disabled={!canConfirm}
                className="btn-primary w-full gap-2 disabled:opacity-40 disabled:cursor-not-allowed">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                Submit for Approval - {currency} {fmtAmt(total)}
            </button>
        </div>
    );
}

// ── Split-payment entry form ──────────────────────────────────────────────────

function SplitEntry({
    remaining,
    currency,
    methods,
    onAdd,
}: {
    remaining: number;
    currency: string;
    methods: ConfiguredMethod[];
    onAdd: (p: SplitPayment) => void;
}) {
    const [selectedCode, setSelectedCode] = useState(methods[0]?.code ?? "cash");
    const [amount, setAmount]     = useState(remaining);
    const [reference, setRef]     = useState("");
    const [cashRec, setCashRec]   = useState(Math.ceil(remaining / 100) * 100 || remaining);
    const [proof, setProof]       = useState<File | null>(null);

    const selectedMethod = methods.find(m => m.code === selectedCode) ?? methods[0];
    const isCashMethod   = isCash(selectedMethod);
    const isOtherMethod  = selectedMethod ? isOther(selectedMethod) : false;
    const isBankMethod   = selectedMethod ? isBank(selectedMethod) : false;
    const needsRef       = isOtherMethod || isBankMethod; // reference required
    const needsApproval  = needsRef; // these methods always need admin approval

    // canAdd: amount valid + cash tendered + reference filled when required
    const canAdd = amount > 0 && amount <= remaining
        && (!isCashMethod || cashRec >= amount)
        && (!needsRef || reference.trim().length > 0);

    useEffect(() => {
        setAmount(remaining);
        if (isCashMethod) setCashRec(Math.ceil(remaining / 100) * 100 || remaining);
    }, [remaining]);

    const handleAdd = () => {
        onAdd({
            id: Date.now().toString(),
            method: isOtherMethod ? "other" : selectedCode,
            amount,
            reference: reference || undefined,
            cashReceived: isCashMethod ? cashRec : undefined,
            proofFile: proof ?? undefined,
        });
        setRef(""); setProof(null);
    };

    return (
        <div className="space-y-3">
            {/* Method grid */}
            <div className={clsx("grid gap-2", methods.length <= 2 ? "grid-cols-2" : methods.length <= 4 ? "grid-cols-2 sm:grid-cols-4" : "grid-cols-3")}>
                {methods.map(m => {
                    const col = methodColour(m);
                    return (
                        <button key={m.code} onClick={() => { setSelectedCode(m.code); setRef(""); setProof(null); }}
                            className={clsx("flex flex-col items-center gap-1.5 p-2.5 rounded-xl border-2 transition-all",
                                selectedCode === m.code ? col.active : "border-surface-100 bg-white text-surface-400 hover:border-surface-300")}>
                            {methodIcon(m)}
                            <span className="text-2xs font-semibold truncate max-w-full px-1">{m.name}</span>
                        </button>
                    );
                })}
            </div>

            {/* Approval notice for bank/other */}
            {needsApproval && (
                <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
                    <svg className="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    <p className="text-2xs text-amber-800 leading-snug"><span className="font-bold">Requires admin approval</span> - order stays pending until reviewed.</p>
                </div>
            )}

            {/* Amount */}
            <div>
                <div className="flex items-center justify-between mb-1">
                    <label className="label mb-0">Amount</label>
                    <button onClick={() => { setAmount(remaining); setCashRec(Math.ceil(remaining / 100) * 100); }}
                        className="text-2xs text-brand-500 hover:underline">
                        Full remaining ({currency} {fmtAmt(remaining)})
                    </button>
                </div>
                <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-surface-400">{currency}</span>
                    <input type="number" min={0.01} max={remaining} step={0.01} value={amount}
                        onChange={e => { const v = Math.min(remaining, parseFloat(e.target.value) || 0); setAmount(v); if (isCashMethod) setCashRec(Math.ceil(v / 100) * 100 || v); }}
                        className="input pl-12 text-xl font-bold" />
                </div>
            </div>

            {/* Cash received */}
            {isCashMethod && (
                <div>
                    <label className="label">Cash Received</label>
                    <div className="relative">
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-surface-400">{currency}</span>
                        <input type="number" min={amount} step={0.01} value={cashRec}
                            onChange={e => setCashRec(parseFloat(e.target.value) || 0)}
                            className="input pl-12 text-lg font-bold" />
                    </div>
                    <div className="flex gap-2 flex-wrap mt-2">
                        {cashPresets(amount).map(p => (
                            <button key={p} onClick={() => setCashRec(p)}
                                className={clsx("px-3 py-1.5 rounded-lg border text-xs font-semibold transition-all",
                                    cashRec === p ? "bg-brand-500 text-white border-brand-500" : "bg-white border-surface-200 text-surface-700 hover:border-brand-300")}>
                                {p.toLocaleString("en-KE")}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* M-Pesa code */}
            {selectedMethod && isMpesa(selectedMethod) && (
                <div>
                    <label className="label">Transaction Code <span className="text-surface-400">(optional)</span></label>
                    <input type="text" value={reference} onChange={e => setRef(e.target.value.toUpperCase())}
                        placeholder="e.g. QHK7XXXXXYZ" className="input font-mono tracking-wider" />
                </div>
            )}

            {/* Reference for card/bank/other - required for bank and other */}
            {selectedMethod && (isCard(selectedMethod) || isBankMethod || isOtherMethod) && (
                <div>
                    <label className="label">
                        {isBankMethod ? "Transfer Reference" : isOtherMethod ? "Payment Reference" : "Approval Code"}
                        {needsRef && <span className="text-danger ml-1">*</span>}
                        {!needsRef && <span className="text-surface-400 ml-1">(optional)</span>}
                    </label>
                    <input type="text" value={reference} onChange={e => setRef(e.target.value)}
                        placeholder={isBankMethod ? "Bank transfer / RTGS reference" : isOtherMethod ? "Transaction ID, cheque number…" : "Terminal approval code"}
                        className={clsx("input", needsRef && !reference.trim() && "border-warning")} />
                    {needsRef && !reference.trim() && (
                        <p className="mt-1 text-2xs text-warning-dark">Required - needed for the approval request.</p>
                    )}
                </div>
            )}

            {/* Proof upload for bank/other */}
            {selectedMethod && needsProof(selectedMethod) && (
                <ProofUploadZone file={proof} onChange={setProof} />
            )}

            <button onClick={handleAdd} disabled={!canAdd}
                className="btn-primary w-full gap-2 disabled:opacity-40 disabled:cursor-not-allowed">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {needsApproval ? "Add (pending approval)" : `Add ${selectedMethod?.name}`} - {currency} {fmtAmt(amount)}
            </button>
        </div>
    );
}

// ── Main Modal ────────────────────────────────────────────────────────────────

export default function PaymentModal({
    total,
    currency,
    orderId,
    configuredMethods,
    onCharge,
    onStkComplete,
    onClose,
    isProcessing,
    isCreatingOrder = false,
    taxInclusive = false,
    taxAmount = 0,
}: Props) {
    const [splitMode, setSplitMode]     = useState(false);
    const [depositMode, setDepositMode] = useState(false);
    const [depositAmount, setDepositAmount] = useState(Math.round(total * 0.5 * 100) / 100);
    const depositPresets = [25, 30, 50].map(pct => ({ pct, val: Math.round(total * pct / 100 * 100) / 100 }));

    // Always append "Other" after configured methods
    const allMethods = [...configuredMethods, OTHER_METHOD];

    // Active method in single mode
    const defaultMethod = configuredMethods.find(m => m.is_default) ?? configuredMethods[0] ?? OTHER_METHOD;
    const [singleCode, setSingleCode] = useState(defaultMethod.code);
    const selectedMethod = allMethods.find(m => m.code === singleCode) ?? allMethods[0];

    // Split mode payments list
    const [payments, setPayments] = useState<SplitPayment[]>([]);
    const totalPaid   = payments.reduce((s, p) => s + p.amount, 0);
    const remaining   = Math.max(0, total - totalPaid);
    const isFullyPaid = totalPaid >= total;

    const removePayment = (id: string) => setPayments(p => p.filter(x => x.id !== id));
    const handleAddPayment = (p: SplitPayment) => setPayments(prev => [...prev, p]);

    // Single-mode confirm callback for non-STK, non-Paystack flows
    const handleSingleConfirm = useCallback((opts: {
        ref?: string;
        cashReceived?: number;
        proof?: File | null;
        depositAmount?: number;
        customMethodName?: string;
    }) => {
        const amount = opts.depositAmount ?? total;
        // When "Other" is selected, record it as "other" on the backend and
        // store the custom name in the reference field prefixed clearly.
        const effectiveMethod = singleCode === "__other__" ? "other" : singleCode;
        const effectiveRef = singleCode === "__other__" && opts.customMethodName
            ? `${opts.customMethodName}${opts.ref ? ` - ${opts.ref}` : ""}`
            : opts.ref;
        onCharge([{
            id: "1",
            method: effectiveMethod,
            amount,
            reference:    effectiveRef,
            cashReceived: opts.cashReceived,
            proofFile:    opts.proof ?? undefined,
        }], opts.depositAmount, opts.proof ?? undefined);
    }, [singleCode, total, onCharge]);

    // No methods configured
    if (configuredMethods.length === 0) {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                <div className="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-center space-y-4">
                    <div className="w-12 h-12 rounded-2xl bg-warning-light flex items-center justify-center mx-auto">
                        <svg className="w-6 h-6 text-warning-dark" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    </div>
                    <div>
                        <p className="font-bold text-surface-900">No payment methods configured</p>
                        <p className="text-xs text-surface-500 mt-1">
                            Go to <strong>Settings → Payment Methods</strong> to add and enable payment methods before processing sales.
                        </p>
                    </div>
                    <div className="flex gap-3">
                        <button onClick={onClose} className="btn-secondary flex-1">Close</button>
                        <Link to="/settings/payment-methods" className="btn-primary flex-1 text-center">Go to Settings</Link>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4">
            <div className="bg-white w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl shadow-2xl overflow-hidden animate-slide-up flex flex-col max-h-[92vh]">

                {/* Header */}
                <div className="px-5 pt-4 pb-3 border-b border-surface-100 shrink-0">
                    <div className="flex items-center justify-between mb-3">
                        <h2 className="font-bold text-lg text-surface-900">Payment</h2>
                        <button onClick={onClose} className="btn-ghost btn-icon btn-sm"
aria-label="Close">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    {/* Amount summary */}
                    <div className={clsx("rounded-xl p-3 grid gap-1", splitMode && payments.length > 0 ? "grid-cols-3" : "grid-cols-1 text-center")}>
                        <div className={splitMode && payments.length > 0 ? "text-center" : ""}>
                            <p className="text-2xs text-surface-400">Order Total</p>
                            <p className="text-2xl font-bold text-surface-900">{currency} {fmtAmt(total)}</p>
                            {taxAmount > 0 && (
                                <p className="text-2xs text-surface-400 mt-0.5">
                                    {taxInclusive
                                        ? <>Tax <span className="font-medium text-surface-500">{currency} {fmtAmt(taxAmount)}</span> incl. in price</>
                                        : <>+ Tax <span className="font-medium text-surface-500">{currency} {fmtAmt(taxAmount)}</span></>
                                    }
                                </p>
                            )}
                        </div>
                        {splitMode && payments.length > 0 && (
                            <>
                                <div className="text-center">
                                    <p className="text-2xs text-surface-400">Paid</p>
                                    <p className="text-xl font-bold text-success">{currency} {fmtAmt(totalPaid)}</p>
                                </div>
                                <div className="text-center">
                                    <p className="text-2xs text-surface-400">Remaining</p>
                                    <p className={clsx("text-xl font-bold", remaining > 0 ? "text-danger" : "text-success")}>{currency} {fmtAmt(remaining)}</p>
                                </div>
                            </>
                        )}
                    </div>

                    {/* Mode toggles */}
                    <div className="flex gap-2 mt-2.5">
                        <button onClick={() => { setDepositMode(v => !v); setSplitMode(false); setPayments([]); }}
                            className={clsx("flex-1 py-2 rounded-xl border text-xs font-medium transition-all flex items-center justify-center gap-1.5",
                                depositMode ? "bg-warning-light border-warning text-warning-dark" : "bg-white border-surface-200 text-surface-500 hover:border-warning")}>
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33"/></svg>
                            {depositMode ? "Deposit mode ON" : "Deposit only"}
                        </button>
                        {!depositMode && (
                            <button onClick={() => { setSplitMode(v => !v); setPayments([]); }}
                                className={clsx("flex-1 py-2 rounded-xl border text-xs font-medium transition-all flex items-center justify-center gap-1.5",
                                    splitMode ? "bg-brand-50 border-brand-300 text-brand-700" : "bg-white border-surface-200 text-surface-500 hover:border-brand-300")}>
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                                {splitMode ? "Split ON" : "Split payment"}
                            </button>
                        )}
                    </div>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto p-5 space-y-5">

                    {/* ── ORDER CREATION SPINNER (step 1 pending) ──────────────── */}
                    {isCreatingOrder && (
                        <div className="flex flex-col items-center justify-center py-10 gap-4">
                            <div className="w-12 h-12 rounded-full bg-brand-50 flex items-center justify-center">
                                <div className="w-7 h-7 border-[3px] border-brand-500 border-t-transparent rounded-full animate-spin" />
                            </div>
                            <div className="text-center">
                                <p className="text-sm font-semibold text-surface-900">Creating order…</p>
                                <p className="text-xs text-surface-400 mt-1">Stock is being reserved. Payment options will appear shortly.</p>
                            </div>
                        </div>
                    )}

                    {!isCreatingOrder && (<>

                    {/* ── DEPOSIT MODE ─────────────────────────────────────────── */}
                    {depositMode && (
                        <div className="space-y-4">
                            <div className="bg-warning-light border border-warning/40 rounded-xl px-4 py-3 text-xs text-warning-dark">
                                <p className="font-semibold">Collecting deposit only</p>
                                <p className="mt-0.5 opacity-80">The order will be saved as partially paid. The balance is settled later.</p>
                            </div>
                            <div>
                                <label className="label">Deposit Amount ({currency})</label>
                                <div className="relative">
                                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-surface-400">{currency}</span>
                                    <input type="number" min={0.01} max={total - 0.01} step={0.01} value={depositAmount}
                                        onChange={e => setDepositAmount(Math.min(total - 0.01, parseFloat(e.target.value) || 0))}
                                        className="input pl-12 text-xl font-bold" autoFocus />
                                </div>
                                <div className="flex gap-2 mt-2">
                                    {depositPresets.map(({ pct, val }) => (
                                        <button key={pct} onClick={() => setDepositAmount(val)}
                                            className={clsx("flex-1 py-1.5 text-2xs rounded-lg border font-semibold transition-all",
                                                depositAmount === val ? "bg-warning text-white border-warning" : "bg-white border-surface-200 text-surface-600 hover:border-warning")}>
                                            {pct}% ({fmtAmt(val)})
                                        </button>
                                    ))}
                                </div>
                                <div className="flex justify-between text-xs text-surface-500 mt-2 bg-surface-50 rounded-lg px-3 py-2">
                                    <span>Balance remaining after deposit</span>
                                    <span className="font-semibold text-danger">{currency} {fmtAmt(total - depositAmount)}</span>
                                </div>
                            </div>

                            {/* Method selector for deposit - includes Other */}
                            <div>
                                <label className="label">Pay deposit via</label>
                                <div className={clsx("grid gap-2", allMethods.length <= 4 ? "grid-cols-2 sm:grid-cols-4" : "grid-cols-3")}>
                                    {allMethods.map(m => {
                                        const col = methodColour(m);
                                        return (
                                            <button key={m.code} onClick={() => setSingleCode(m.code)}
                                                className={clsx("flex flex-col items-center gap-1.5 p-2.5 rounded-xl border-2 transition-all",
                                                    singleCode === m.code ? col.active : "border-surface-100 bg-white text-surface-400 hover:border-surface-300")}>
                                                {methodIcon(m)}
                                                <span className="text-2xs font-semibold truncate max-w-full px-1">{m.name}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Deposit method-specific detail */}
                            {selectedMethod && isCash(selectedMethod) && (
                                <CashPanel total={depositAmount} currency={currency}
                                    onConfirm={cashReceived => handleSingleConfirm({ cashReceived, depositAmount })} />
                            )}
                            {selectedMethod && isMpesa(selectedMethod) && (
                                <MpesaPanel orderId={orderId} total={depositAmount} currency={currency}
                                    onConfirmManual={ref => handleSingleConfirm({ ref, depositAmount })}
                                    onStkComplete={onStkComplete} />
                            )}
                            {selectedMethod && isPaystack(selectedMethod) && (
                                <PaystackPanel orderId={orderId} total={depositAmount} currency={currency}
                                    onConfirm={ref => handleSingleConfirm({ ref, depositAmount })}
                                    onStkComplete={onStkComplete} />
                            )}
                            {selectedMethod && !isCash(selectedMethod) && !isMpesa(selectedMethod) && !isPaystack(selectedMethod) && (
                                <ReferencePanel method={selectedMethod} currency={currency} total={depositAmount}
                                    onConfirm={(ref, proof, customMethodName) => handleSingleConfirm({ ref, proof, depositAmount, customMethodName })} />
                            )}
                        </div>
                    )}

                    {/* ── SPLIT MODE ────────────────────────────────────────────── */}
                    {splitMode && !depositMode && (
                        <>
                            {payments.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">Collected</p>
                                    {payments.map(p => {
                                        const m = allMethods.find(x => x.code === p.method);
                                        return (
                                            <div key={p.id} className="flex items-center gap-3 bg-surface-50 rounded-xl px-3 py-2.5">
                                                <span className="text-sm text-surface-500">{m ? methodIcon(m, "w-4 h-4") : null}</span>
                                                <div className="flex-1">
                                                    <p className="text-xs font-semibold text-surface-900">{m?.name ?? p.method}</p>
                                                    {p.reference && <p className="text-2xs text-surface-400 font-mono">{p.reference}</p>}
                                                    {p.proofFile && <p className="text-2xs text-success-dark">📎 {p.proofFile.name}</p>}
                                                </div>
                                                <span className="text-sm font-bold text-surface-900">{currency} {fmtAmt(p.amount)}</span>
                                                <button onClick={() => removePayment(p.id)}
                                                    className="w-5 h-5 rounded-full flex items-center justify-center text-surface-300 hover:text-danger hover:bg-danger-light transition-all"
                                                    aria-label="Close">
                                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                            {!isFullyPaid && (
                                <div className="space-y-3">
                                    {payments.length > 0 && (
                                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Next payment - {currency} {fmtAmt(remaining)} remaining
                                        </p>
                                    )}
                                    <SplitEntry remaining={remaining} currency={currency} methods={allMethods} onAdd={handleAddPayment} />
                                </div>
                            )}
                            {isFullyPaid && (
                                <div className="bg-success-light rounded-xl p-4 text-center">
                                    <div className="w-10 h-10 rounded-full bg-success flex items-center justify-center mx-auto mb-2">
                                        <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    </div>
                                    <p className="text-sm font-bold text-success-dark">Payment complete</p>
                                    {totalPaid > total && (
                                        <p className="text-xs text-success-dark mt-0.5">Change due: {currency} {fmtAmt(totalPaid - total)}</p>
                                    )}
                                </div>
                            )}
                        </>
                    )}

                    {/* ── SINGLE MODE ───────────────────────────────────────────── */}
                    {!splitMode && !depositMode && (
                        <div className="space-y-4">
                            {/* Method grid - configured methods + Other */}
                            <div className={clsx("grid gap-2",
                                allMethods.length === 1 ? "grid-cols-1" :
                                allMethods.length === 2 ? "grid-cols-2" :
                                allMethods.length <= 4 ? "grid-cols-2 sm:grid-cols-4" : "grid-cols-3")}>
                                {allMethods.map(m => {
                                    const col = methodColour(m);
                                    const isSelected = singleCode === m.code;
                                    return (
                                        <button key={m.code} onClick={() => setSingleCode(m.code)}
                                            className={clsx("flex flex-col items-center gap-1.5 p-2.5 rounded-xl border-2 transition-all",
                                                isSelected ? col.active : "border-surface-100 bg-white text-surface-400 hover:border-surface-300")}>
                                            {methodIcon(m)}
                                            <span className="text-2xs font-semibold truncate max-w-full px-1">{m.name}</span>
                                            {m.is_default && !isSelected && (
                                                <span className="text-2xs text-surface-300">Default</span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Per-method detail panel */}
                            {selectedMethod && isCash(selectedMethod) && (
                                <CashPanel total={total} currency={currency} allowPartial
                                    onConfirm={cashReceived => handleSingleConfirm({
                                        cashReceived,
                                        // Short cash = a partial payment of what was tendered; the
                                        // order keeps the balance owed (reuses the deposit path).
                                        depositAmount: cashReceived < total ? cashReceived : undefined,
                                    })} />
                            )}
                            {selectedMethod && isMpesa(selectedMethod) && (
                                <MpesaPanel orderId={orderId} total={total} currency={currency}
                                    onConfirmManual={ref => handleSingleConfirm({ ref })}
                                    onStkComplete={onStkComplete} />
                            )}
                            {selectedMethod && isPaystack(selectedMethod) && (
                                <PaystackPanel orderId={orderId} total={total} currency={currency}
                                    onConfirm={ref => handleSingleConfirm({ ref })}
                                    onStkComplete={onStkComplete} />
                            )}
                            {selectedMethod && !isCash(selectedMethod) && !isMpesa(selectedMethod) && !isPaystack(selectedMethod) && (
                                <ReferencePanel method={selectedMethod} currency={currency} total={total}
                                    onConfirm={(ref, proof, customMethodName) => handleSingleConfirm({ ref, proof, customMethodName })} />
                            )}
                        </div>
                    )}

                    </>) /* end !isCreatingOrder */}
                </div>

                {/* Footer - split-mode completion. Fully paid → Complete; part-paid
                    → Save with the balance owing (records the collected split
                    payments as a deposit so the order is saved partially paid and
                    reopens later for the balance). Not shown while creating order. */}
                {!isCreatingOrder && splitMode && !depositMode && (
                    <div className="p-5 pt-0 flex gap-3 shrink-0 border-t border-surface-100">
                        <button onClick={onClose} disabled={isProcessing} className="btn-secondary flex-1">Cancel</button>
                        {isFullyPaid ? (
                            <button onClick={() => onCharge(payments)} disabled={isProcessing || !orderId} className="btn-primary flex-1 gap-2">
                                {isProcessing
                                    ? <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                    : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>}
                                {isProcessing ? "Processing…" : `Complete - ${currency} ${fmtAmt(total)}`}
                            </button>
                        ) : (
                            <button
                                onClick={() => onCharge(payments, totalPaid)}
                                disabled={isProcessing || !orderId || payments.length === 0}
                                title={payments.length === 0 ? "Add at least one payment first" : `Saves ${currency} ${fmtAmt(totalPaid)} now; ${currency} ${fmtAmt(remaining)} owed`}
                                className="btn-primary flex-1 gap-2 !bg-warning-dark hover:!bg-warning-dark/90 disabled:opacity-40 disabled:cursor-not-allowed">
                                {isProcessing
                                    ? <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                    : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7M5 19h14" opacity="0"/><path strokeLinecap="round" strokeLinejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-8 0V5a2 2 0 012-2h4a2 2 0 012 2v2m-8 0h8"/></svg>}
                                {isProcessing ? "Processing…" : `Save · ${currency} ${fmtAmt(remaining)} balance owing`}
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}