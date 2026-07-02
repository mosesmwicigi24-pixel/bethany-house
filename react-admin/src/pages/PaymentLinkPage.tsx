/**
 * PaymentLinkPage.tsx
 *
 * Public (unauthenticated) page at /pay/:token
 *
 * Supports:
 *   - M-Pesa STK Push → phone input → push → auto-poll until paid
 *   - Paystack (card)  → email input → server initiates → redirect → return → poll
 *   - Bank transfer    → server records intent → file upload (proof) → pending admin approval
 *
 * "Other" method type is intentionally excluded - staff-only.
 * Cash is excluded - customers pay remotely, not in person.
 *
 * DEPLOY TO: src/pages/PaymentLinkPage.tsx
 * ROUTE:     /pay/:token  (App.tsx, outside RequireAuth)
 */

import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { clsx } from "clsx";

// ── Types ─────────────────────────────────────────────────────────────────────

interface PaymentMethod {
    id: number;
    code: string;  // "mpesa" | "card_paystack" | "bank_transfer"
    name: string;
    type: string;  // "mobile_money" | "card" | "bank_transfer"
    provider: string | null;
}

interface OrderInfo {
    order_number:       string;
    total_amount:       number;
    amount_due:         number;   // remaining balance (may be less than total)
    tax_amount:         number;
    prices_include_tax: boolean;
    currency_code:      string;
    payment_status:     string;
    available_methods:  PaymentMethod[];
    business_name:      string;
    business_logo:      string | null;
    business_tagline:   string | null;
    customer_first_name?: string | null;
    is_international:   boolean;
    expires_at?:        string | null;
}

interface StatusResponse {
    payment_status:  string;
    order_status:    string;
    latest_payment?: {
        status:           string;
        method:           string;
        amount:           number;
        requires_approval: boolean;
        approval_status:  string | null;
    } | null;
}

type Stage =
    | "loading"
    | "error"
    | "expired"
    | "select_method"
    | "mpesa"             // phone input + push waiting
    | "paystack_email"    // collecting email before redirect
    | "paystack_redirect" // redirecting to Paystack
    | "paystack_return"   // returned from Paystack, polling
    | "bank_transfer"     // instructions + file upload
    | "bank_pending"      // proof submitted, awaiting admin approval
    | "paid";

// ── API ───────────────────────────────────────────────────────────────────────

const BASE = import.meta.env.VITE_API_URL ?? "/api";

async function api<T>(path: string, init?: RequestInit): Promise<T> {
    const res  = await fetch(`${BASE}${path}`, {
        headers: { "Content-Type": "application/json", Accept: "application/json", ...init?.headers },
        ...init,
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((body as any)?.message ?? `Error ${res.status}`);
    return body as T;
}

async function apiForm<T>(path: string, form: FormData): Promise<T> {
    const res  = await fetch(`${BASE}${path}`, { method: "POST", body: form });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((body as any)?.message ?? `Error ${res.status}`);
    return body as T;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmt = (n: number, cc = "KES") =>
    `${cc} ${n.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const isMpesa   = (m: PaymentMethod) => m.code === "mpesa"   || m.type === "mobile_money";
const isPaystack = (m: PaymentMethod) => m.code === "card_paystack" || m.provider === "paystack" || m.code === "paystack";
const isBank     = (m: PaymentMethod) => m.code === "bank_transfer" || m.type === "bank_transfer";

// ── UI primitives ─────────────────────────────────────────────────────────────

function Spinner({ size = "md" }: { size?: "sm" | "md" | "lg" }) {
    const s = { sm: "w-4 h-4 border-2", md: "w-6 h-6 border-2", lg: "w-10 h-10 border-[3px]" }[size];
    return <div className={clsx("rounded-full animate-spin border-gray-200 border-t-blue-600", s)} />;
}

function Card({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <div className={clsx("bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden", className)}>
            {children}
        </div>
    );
}

function Btn({
    children, onClick, disabled, variant = "primary", className,
}: {
    children: React.ReactNode;
    onClick?: () => void;
    disabled?: boolean;
    variant?: "primary" | "secondary" | "ghost";
    className?: string;
}) {
    const base = "py-3 px-5 rounded-xl text-sm font-semibold flex items-center justify-center gap-2 transition-all disabled:opacity-40 disabled:cursor-not-allowed";
    const v = {
        primary:   "bg-blue-600 text-white hover:bg-blue-700 active:bg-blue-800",
        secondary: "bg-gray-100 text-gray-700 hover:bg-gray-200",
        ghost:     "text-gray-500 hover:text-gray-700 hover:bg-gray-100",
    }[variant];
    return (
        <button onClick={onClick} disabled={disabled} className={clsx(base, v, className)}>
            {children}
        </button>
    );
}

function Field({
    label, type = "text", value, onChange, placeholder, autoFocus, hint,
}: {
    label: string;
    type?: string;
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    autoFocus?: boolean;
    hint?: string;
}) {
    return (
        <div className="space-y-1.5">
            <label className="block text-xs font-semibold text-gray-700">{label}</label>
            <input
                type={type}
                value={value}
                onChange={e => onChange(e.target.value)}
                placeholder={placeholder}
                autoFocus={autoFocus}
                className="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            />
            {hint && <p className="text-xs text-gray-400">{hint}</p>}
        </div>
    );
}

function ErrMsg({ msg }: { msg: string }) {
    return msg ? (
        <p className="text-xs text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{msg}</p>
    ) : null;
}

// ── Full-screen states ─────────────────────────────────────────────────────────

function LoadingScreen() {
    return (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center">
            <Spinner size="lg" />
        </div>
    );
}

function ErrorScreen({ message, onRetry }: { message: string; onRetry?: () => void }) {
    return (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
            <Card className="max-w-sm w-full p-8 text-center">
                <div className="w-14 h-14 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-4">
                    <svg className="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                    </svg>
                </div>
                <h2 className="text-lg font-semibold text-gray-900 mb-2">Payment Unavailable</h2>
                <p className="text-sm text-gray-500 mb-6">{message}</p>
                {onRetry && <Btn onClick={onRetry} className="w-full">Try Again</Btn>}
            </Card>
        </div>
    );
}

function PaidScreen({ orderNumber, businessName }: { orderNumber: string; businessName: string }) {
    return (
        <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
            <Card className="max-w-sm w-full p-8 text-center">
                <div className="w-16 h-16 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-4">
                    <svg className="w-8 h-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h2 className="text-xl font-bold text-gray-900 mb-1">Payment Confirmed!</h2>
                <p className="text-sm text-gray-500 mb-2">
                    Order <span className="font-mono font-semibold text-gray-800">{orderNumber}</span> is paid.
                </p>
                <p className="text-xs text-gray-400">{businessName} will be in touch shortly. Thank you!</p>
            </Card>
        </div>
    );
}

// ── Method selector ────────────────────────────────────────────────────────────

const METHOD_META: Record<string, { label: string; sub: string; icon: React.ReactNode; accent: string }> = {
    mpesa: {
        label: "M-Pesa",
        sub:   "Instant STK push to your phone",
        icon: (
            <div className="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center shrink-0">
                <svg className="w-5 h-5 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <rect x="5" y="2" width="14" height="20" rx="2"/>
                    <line x1="12" y1="18" x2="12.01" y2="18"/>
                </svg>
            </div>
        ),
        accent: "hover:border-green-200 hover:bg-green-50/40",
    },
    card_paystack: {
        label: "Card (Visa / Mastercard)",
        sub:   "Secure payment via Paystack",
        icon: (
            <div className="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                <svg className="w-5 h-5 text-blue-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <rect x="1" y="4" width="22" height="16" rx="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
            </div>
        ),
        accent: "hover:border-blue-200 hover:bg-blue-50/40",
    },
    bank_transfer: {
        label: "Bank Transfer",
        sub:   "Transfer then upload proof for verification",
        icon: (
            <div className="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center shrink-0">
                <svg className="w-5 h-5 text-purple-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path d="M3 22v-8m6 8V10m6 12V8m6 14V4"/><path d="M2 22h20"/>
                </svg>
            </div>
        ),
        accent: "hover:border-purple-200 hover:bg-purple-50/40",
    },
};

function MethodList({
    methods,
    onSelect,
}: {
    methods: PaymentMethod[];
    onSelect: (m: PaymentMethod) => void;
}) {
    if (methods.length === 0) {
        return (
            <p className="text-sm text-gray-400 py-6 text-center">
                No payment methods available. Please contact the business.
            </p>
        );
    }
    return (
        <div className="space-y-2">
            {methods.map(m => {
                const meta = METHOD_META[m.code] ?? {
                    label: m.name,
                    sub: "",
                    icon: (
                        <div className="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center shrink-0">
                            <svg className="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>
                            </svg>
                        </div>
                    ),
                    accent: "hover:border-gray-200 hover:bg-gray-50",
                };
                return (
                    <button
                        key={m.id}
                        onClick={() => onSelect(m)}
                        className={clsx(
                            "w-full flex items-center gap-4 p-4 rounded-2xl border border-gray-100 transition-all text-left group",
                            meta.accent,
                        )}
                    >
                        {meta.icon}
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-semibold text-gray-800">{meta.label}</p>
                            {meta.sub && <p className="text-xs text-gray-400 mt-0.5">{meta.sub}</p>}
                        </div>
                        <svg className="w-4 h-4 text-gray-300 group-hover:text-gray-500 transition-colors shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                );
            })}
        </div>
    );
}

// ── M-Pesa panel ──────────────────────────────────────────────────────────────

const MAX_POLLS = 24; // 24 × 5 s = 2 min

type StkStep = "idle" | "waiting" | "confirmed" | "failed";
type ConfirmStep = "idle" | "verifying" | "confirmed" | "pending_approval";

function MpesaPanel({
    token, amountDue, currency, onSuccess, onBack,
}: {
    token: string; amountDue: number; currency: string;
    onSuccess: () => void; onBack: () => void;
}) {
    // ── STK Push state ────────────────────────────────────────────────────────
    const [phone,     setPhone]    = useState("");
    const [stkStep,   setStkStep]  = useState<StkStep>("idle");
    const [pushing,   setPushing]  = useState(false);
    const [stkErr,    setStkErr]   = useState("");
    const [pollsLeft, setPollsLeft]= useState(MAX_POLLS);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // ── Transaction code state ────────────────────────────────────────────────
    const [code,         setCode]        = useState("");
    const [confirmStep,  setConfirmStep] = useState<ConfirmStep>("idle");
    const [confirmErr,   setConfirmErr]  = useState("");
    const [confirmMsg,   setConfirmMsg]  = useState("");

    const stopPoll = () => { if (timerRef.current) { clearInterval(timerRef.current); timerRef.current = null; } };
    useEffect(() => () => stopPoll(), []);

    // ── STK Push ──────────────────────────────────────────────────────────────
    const startPolling = () => {
        setPollsLeft(MAX_POLLS);
        timerRef.current = setInterval(async () => {
            setPollsLeft(p => {
                if (p <= 1) {
                    stopPoll();
                    setStkStep("failed");
                    setStkErr("No confirmation received. If you completed the payment, use the transaction code below.");
                    return 0;
                }
                return p - 1;
            });
            try {
                const res = await api<StatusResponse>(`/v1/pay/${token}/status`);
                if (res.payment_status === "paid") {
                    stopPoll();
                    setStkStep("confirmed");
                    setTimeout(onSuccess, 1200);
                }
            } catch {}
        }, 5000);
    };

    const sendPush = async () => {
        if (!phone.trim()) { setStkErr("Please enter your M-Pesa phone number."); return; }
        setPushing(true); setStkErr("");
        try {
            await api(`/v1/pay/${token}/initiate`, {
                method: "POST",
                body: JSON.stringify({ method: "mpesa", phone: phone.trim() }),
            });
            setStkStep("waiting");
            startPolling();
        } catch (e: any) {
            setStkErr(e.message ?? "Failed to send push. Check the phone number and try again.");
            setStkStep("idle");
        } finally {
            setPushing(false);
        }
    };

    const cancelStk = () => { stopPoll(); setStkStep("idle"); setStkErr(""); };

    // ── Transaction code confirm ───────────────────────────────────────────────
    const submitCode = async () => {
        if (!code.trim()) { setConfirmErr("Please enter the M-Pesa transaction code."); return; }
        setConfirmStep("verifying"); setConfirmErr("");
        try {
            const res = await api<{ confirmed: boolean; message: string; payment_status: string }>(
                `/v1/pay/${token}/mpesa-confirm`,
                { method: "POST", body: JSON.stringify({ transaction_code: code.trim().toUpperCase() }) },
            );
            if (res.confirmed) {
                stopPoll(); // cancel any running STK poll
                setStkStep("confirmed"); // also show STK as confirmed
                setConfirmStep("confirmed");
                setConfirmMsg(res.message);
                setTimeout(onSuccess, 1200);
            } else {
                setConfirmStep("pending_approval");
                setConfirmMsg(res.message);
            }
        } catch (e: any) {
            setConfirmErr(e.message ?? "Could not verify the code. Please try again.");
            setConfirmStep("idle");
        }
    };

    return (
        <div className="space-y-0">

            {/* ── Section 1: STK Push ────────────────────────────────────────── */}
            <div className="rounded-xl border border-green-200 bg-green-50/60 overflow-hidden">
                <div className="flex items-center gap-2.5 px-4 py-2.5 border-b border-green-200/70 bg-green-100/50">
                    <div className="w-6 h-6 rounded-lg bg-green-600 flex items-center justify-center shrink-0">
                        <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <rect x="5" y="2" width="14" height="20" rx="2"/>
                            <line x1="12" y1="18" x2="12.01" y2="18"/>
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-xs font-bold text-green-900">STK Push</p>
                        <p className="text-2xs text-green-700">Get a payment prompt sent directly to your phone</p>
                    </div>
                    {stkStep === "waiting" && (
                        <span className="flex items-center gap-1 text-2xs text-green-700 font-semibold shrink-0">
                            <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
                            Waiting…
                        </span>
                    )}
                    {stkStep === "confirmed" && (
                        <span className="flex items-center gap-1 text-2xs text-green-700 font-semibold shrink-0">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                            Confirmed
                        </span>
                    )}
                </div>

                <div className="px-4 py-3 space-y-3">
                    {stkStep === "confirmed" && (
                        <div className="flex items-center gap-3 py-2">
                            <div className="w-9 h-9 rounded-full bg-green-600 flex items-center justify-center shrink-0">
                                <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            </div>
                            <div>
                                <p className="text-sm font-bold text-green-800">M-Pesa Confirmed!</p>
                                <p className="text-2xs text-green-600">Processing your payment…</p>
                            </div>
                        </div>
                    )}

                    {stkStep === "waiting" && (
                        <div className="space-y-2.5">
                            <div className="flex items-center gap-3 bg-green-100 rounded-xl px-3 py-2.5">
                                <div className="w-6 h-6 border-2 border-green-500 border-t-transparent rounded-full animate-spin shrink-0" />
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-semibold text-green-800">Prompt sent to {phone}</p>
                                    <p className="text-2xs text-green-600">Enter your M-Pesa PIN to confirm {fmt(amountDue, currency)}.</p>
                                </div>
                            </div>
                            <p className="text-2xs text-green-600/70 text-center">Checking status… ({pollsLeft} attempts remaining)</p>
                            <button onClick={cancelStk}
                                className="w-full text-2xs text-gray-400 hover:text-gray-600 underline underline-offset-2 py-1">
                                Cancel / try again
                            </button>
                        </div>
                    )}

                    {(stkStep === "idle" || stkStep === "failed") && (
                        <div className="space-y-2">
                            {stkErr && <ErrMsg msg={stkErr} />}
                            <div className="flex gap-2">
                                <input
                                    type="tel"
                                    value={phone}
                                    onChange={e => setPhone(e.target.value)}
                                    onKeyDown={e => e.key === "Enter" && sendPush()}
                                    placeholder="e.g. 0712 345 678"
                                    className="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                    autoFocus
                                />
                                <button
                                    onClick={sendPush}
                                    disabled={pushing || !phone.trim()}
                                    className="shrink-0 px-4 py-2 rounded-xl text-xs font-bold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40 transition-colors flex items-center gap-1.5 whitespace-nowrap"
                                >
                                    {pushing
                                        ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                        : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                    }
                                    {pushing ? "Sending…" : "Send Push"}
                                </button>
                            </div>
                            <p className="text-2xs text-green-700/70">You'll receive a prompt on your phone. Enter your PIN to confirm.</p>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Divider ──────────────────────────────────────────────────────── */}
            <div className="relative flex items-center py-3">
                <div className="flex-1 border-t border-gray-100" />
                <span className="mx-3 text-2xs font-semibold text-gray-400 uppercase tracking-widest bg-white px-1">
                    or confirm with transaction code
                </span>
                <div className="flex-1 border-t border-gray-100" />
            </div>

            {/* ── Section 2: Already paid - transaction code ───────────────────── */}
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div className="flex items-center gap-2.5 px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                    <div className="w-6 h-6 rounded-lg bg-gray-700 flex items-center justify-center shrink-0">
                        <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-xs font-bold text-gray-800">Already Paid via M-Pesa?</p>
                        <p className="text-2xs text-gray-500">Enter the confirmation code from your M-Pesa SMS</p>
                    </div>
                    {confirmStep === "confirmed" && (
                        <span className="flex items-center gap-1 text-2xs text-green-700 font-semibold shrink-0">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                            Confirmed
                        </span>
                    )}
                </div>

                <div className="px-4 py-3 space-y-3">
                    {confirmStep === "confirmed" && (
                        <div className="bg-green-50 border border-green-100 rounded-lg px-3 py-2 text-xs font-medium text-green-800">
                            ✓ {confirmMsg}
                        </div>
                    )}

                    {confirmStep === "pending_approval" && (
                        <div className="bg-blue-50 border border-blue-100 rounded-lg px-3 py-2 text-xs text-blue-800 leading-relaxed">
                            <p className="font-semibold mb-0.5">Payment recorded</p>
                            <p>{confirmMsg}</p>
                        </div>
                    )}

                    {(confirmStep === "idle" || confirmStep === "verifying") && (
                        <div className="space-y-2">
                            {confirmErr && <ErrMsg msg={confirmErr} />}
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={code}
                                    onChange={e => setCode(e.target.value.toUpperCase())}
                                    onKeyDown={e => e.key === "Enter" && submitCode()}
                                    placeholder="e.g. QHK7ABC1XY"
                                    className="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-transparent uppercase"
                                />
                                <button
                                    onClick={submitCode}
                                    disabled={confirmStep === "verifying" || !code.trim()}
                                    className="shrink-0 px-4 py-2 rounded-xl text-xs font-bold text-white bg-gray-700 hover:bg-gray-800 disabled:opacity-40 transition-colors flex items-center gap-1.5 whitespace-nowrap"
                                >
                                    {confirmStep === "verifying"
                                        ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                        : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    }
                                    {confirmStep === "verifying" ? "Checking…" : "Confirm"}
                                </button>
                            </div>
                            <p className="text-2xs text-gray-400">
                                Use this if you paid via paybill or till number and have the SMS confirmation code.
                            </p>
                        </div>
                    )}
                </div>
            </div>

            {/* Back button */}
            <div className="pt-2">
                <Btn variant="ghost" onClick={onBack} className="w-full text-xs text-gray-400">
                    ← Back to payment methods
                </Btn>
            </div>
        </div>
    );
}

// ── Paystack panel ────────────────────────────────────────────────────────────

function PaystackEmailPanel({
    token, amountDue, currency, onBack,
}: {
    token: string; amountDue: number; currency: string; onBack: () => void;
}) {
    const [email,   setEmail]   = useState("");
    const [loading, setLoading] = useState(false);
    const [err,     setErr]     = useState("");

    const proceed = async () => {
        if (!email.trim() || !email.includes("@")) { setErr("Please enter a valid email address."); return; }
        setLoading(true); setErr("");
        try {
            const res = await api<{ authorization_url: string }>(
                `/v1/pay/${token}/initiate`,
                { method: "POST", body: JSON.stringify({ method: "card_paystack", email: email.trim() }) },
            );
            if (res.authorization_url) window.location.href = res.authorization_url;
        } catch (e: any) {
            setErr(e.message ?? "Could not initiate card payment. Please try again.");
            setLoading(false);
        }
    };

    return (
        <div className="space-y-4">
            <div className="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 text-xs text-blue-700">
                You'll be redirected to Paystack to complete payment of{" "}
                <strong>{fmt(amountDue, currency)}</strong> securely.
            </div>
            <Field
                label="Your Email Address"
                type="email"
                value={email}
                onChange={setEmail}
                placeholder="you@example.com"
                autoFocus
                hint="Used by Paystack to send your payment receipt."
            />
            {err && <ErrMsg msg={err} />}
            <div className="flex gap-3">
                <Btn variant="secondary" onClick={onBack} className="shrink-0 w-20">Back</Btn>
                <Btn onClick={proceed} disabled={loading || !email.trim()} className="flex-1">
                    {loading && <Spinner size="sm" />}
                    {loading ? "Redirecting…" : "Pay with Card →"}
                </Btn>
            </div>
        </div>
    );
}

function PaystackReturnPanel({
    token, onSuccess,
}: {
    token: string; onSuccess: () => void;
}) {
    const [err, setErr]         = useState("");
    const [checking, setChecking] = useState(false);
    const pollsRef = useRef(0);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // Try to verify immediately using the reference Paystack appended to the URL
    const verifyWithReference = async (ref: string) => {
        try {
            await api(`/v1/pay/${token}/paystack-verify`, {
                method: "POST",
                body: JSON.stringify({ reference: ref }),
            });
            clearInterval(timerRef.current!);
            onSuccess();
            return true;
        } catch {
            return false; // fall through to polling
        }
    };

    const manualCheck = async () => {
        setChecking(true); setErr("");
        try {
            const res = await api<StatusResponse>(`/v1/pay/${token}/status`);
            if (res.payment_status === "paid") { onSuccess(); return; }
            setErr("Payment not yet confirmed. If you completed payment, please wait a moment and try again.");
        } catch {
            setErr("Could not check status. Please try again.");
        } finally {
            setChecking(false);
        }
    };

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const ref = params.get("reference") || params.get("trxref");

        // Immediately try to verify using the reference from Paystack redirect
        if (ref) {
            verifyWithReference(ref).then(verified => {
                if (verified) return; // done
                startPolling(); // fallback to polling if verify fails
            });
        } else {
            startPolling();
        }

        return () => { if (timerRef.current) clearInterval(timerRef.current); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token]);

    const startPolling = () => {
        pollsRef.current = 0;
        timerRef.current = setInterval(async () => {
            pollsRef.current += 1;
            if (pollsRef.current > 24) { // 24 × 5s = 2 min
                clearInterval(timerRef.current!);
                setErr("We couldn't auto-confirm your payment. If you completed payment, tap 'Check Again' below.");
                return;
            }
            try {
                const res = await api<StatusResponse>(`/v1/pay/${token}/status`);
                if (res.payment_status === "paid") {
                    clearInterval(timerRef.current!);
                    onSuccess();
                }
            } catch {}
        }, 5000);
    };

    return (
        <div className="text-center py-8 space-y-4">
            {err ? (
                <>
                    <div className="w-12 h-12 rounded-full bg-yellow-50 flex items-center justify-center mx-auto">
                        <svg className="w-6 h-6 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                    </div>
                    <p className="text-sm text-gray-600">{err}</p>
                    <Btn onClick={manualCheck} disabled={checking} className="w-full">
                        {checking && <Spinner size="sm" />}
                        {checking ? "Checking…" : "Check Again"}
                    </Btn>
                </>
            ) : (
                <>
                    <Spinner size="lg" />
                    <div>
                        <p className="text-sm font-semibold text-gray-800">Confirming your payment…</p>
                        <p className="text-xs text-gray-400 mt-1">This usually takes just a moment.</p>
                    </div>
                </>
            )}
        </div>
    );
}

// ── Bank transfer panel ───────────────────────────────────────────────────────

function BankTransferPanel({
    token, amountDue, currency, businessName, onDone, onBack,
}: {
    token: string; amountDue: number; currency: string;
    businessName: string; onDone: () => void; onBack: () => void;
}) {
    const [step,        setStep]        = useState<"instructions" | "upload">("instructions");
    const [paymentId,   setPaymentId]   = useState<number | null>(null);
    const [file,        setFile]        = useState<File | null>(null);
    const [origSize,    setOrigSize]    = useState<number>(0);
    const [compressing, setCompressing] = useState(false);
    const [loading,     setLoading]     = useState(false);
    const [err,         setErr]         = useState("");

    const handleFileSelect = async (f: File) => {
        setOrigSize(f.size);
        if (f.type.startsWith("image/")) {
            setCompressing(true);
            try {
                const { compressImage } = await import("@/utils/compressImage");
                setFile(await compressImage(f).catch(() => f));
            } finally {
                setCompressing(false);
            }
        } else {
            setFile(f);
        }
    };

    // Step 1: Record payment intent
    const confirmTransfer = async () => {
        setLoading(true); setErr("");
        try {
            const res = await api<{ payment_id: number }>(
                `/v1/pay/${token}/initiate`,
                { method: "POST", body: JSON.stringify({ method: "bank_transfer" }) },
            );
            setPaymentId(res.payment_id);
            setStep("upload");
        } catch (e: any) {
            setErr(e.message ?? "Could not record transfer. Please try again.");
        } finally {
            setLoading(false);
        }
    };

    // Step 2: Upload proof
    const uploadProof = async () => {
        if (!file || !paymentId) return;
        setLoading(true); setErr("");
        try {
            const form = new FormData();
            form.append("proof", file);
            form.append("payment_id", String(paymentId));
            await apiForm(`/v1/pay/${token}/upload-proof`, form);
            onDone();
        } catch (e: any) {
            setErr(e.message ?? "Upload failed. Please try again.");
        } finally {
            setLoading(false);
        }
    };

    if (step === "instructions") {
        return (
            <div className="space-y-4">
                <div className="bg-purple-50 border border-purple-100 rounded-xl p-4 space-y-2 text-sm text-purple-800">
                    <p className="font-semibold">Bank Transfer Instructions</p>
                    <p>
                        Transfer <strong>{fmt(amountDue, currency)}</strong> to{" "}
                        <strong>{businessName}</strong>.
                    </p>
                    <p className="text-xs text-purple-600">
                        Use your order number as the payment reference. Once you've made
                        the transfer, click below and upload your receipt so our team can verify it.
                    </p>
                </div>
                {err && <ErrMsg msg={err} />}
                <div className="flex gap-3">
                    <Btn variant="secondary" onClick={onBack} className="shrink-0 w-20">Back</Btn>
                    <Btn onClick={confirmTransfer} disabled={loading} className="flex-1">
                        {loading && <Spinner size="sm" />}
                        {loading ? "Recording…" : "I've Made the Transfer"}
                    </Btn>
                </div>
            </div>
        );
    }

    // Upload step
    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-600">
                Upload a screenshot or PDF of your bank transfer confirmation.
            </p>
            <label className="block cursor-pointer">
                <div className={clsx(
                    "border-2 border-dashed rounded-xl p-6 text-center transition-colors",
                    file ? "border-purple-400 bg-purple-50" : "border-gray-200 hover:border-purple-300",
                )}>
                    <input
                        type="file"
                        className="hidden"
                        accept="image/*,application/pdf"
                        onChange={e => { const f = e.target.files?.[0]; if (f) handleFileSelect(f); }}
                    />
                    {compressing ? (
                        <div className="flex items-center justify-center gap-2 py-1">
                            <svg className="w-3.5 h-3.5 animate-spin text-purple-500" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            <p className="text-xs text-purple-600">Compressing image…</p>
                        </div>
                    ) : file ? (
                        <div className="space-y-0.5">
                            <p className="text-sm text-purple-700 font-medium truncate">{file.name}</p>
                            {origSize > file.size && (
                                <p className="text-xs text-gray-400">
                                    Compressed: {Math.round(origSize / 1024)}KB → {Math.round(file.size / 1024)}KB
                                </p>
                            )}
                        </div>
                    ) : (
                        <>
                            <svg className="w-8 h-8 text-gray-300 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                            </svg>
                            <p className="text-xs text-gray-400">Tap to choose image or PDF</p>
                        </>
                    )}
                </div>
            </label>
            {err && <ErrMsg msg={err} />}
            <Btn onClick={uploadProof} disabled={!file || loading || compressing} className="w-full">
                {loading && <Spinner size="sm" />}
                {compressing ? "Compressing…" : loading ? "Uploading…" : "Submit Proof of Payment"}
            </Btn>
        </div>
    );
}

// ── Order summary card ────────────────────────────────────────────────────────

function OrderCard({ order }: { order: OrderInfo }) {
    const statusMap: Record<string, { label: string; cls: string }> = {
        paid:             { label: "Paid",              cls: "bg-green-100  text-green-800  border-green-200"  },
        partial:          { label: "Partially Paid",    cls: "bg-yellow-100 text-yellow-800 border-yellow-200" },
        deposit:          { label: "Deposit Received",  cls: "bg-blue-100   text-blue-800   border-blue-200"   },
        pending:          { label: "Awaiting Payment",  cls: "bg-orange-100 text-orange-800 border-orange-200" },
        pending_approval: { label: "Proof Under Review",cls: "bg-purple-100 text-purple-800 border-purple-200" },
        failed:           { label: "Payment Failed",    cls: "bg-red-100    text-red-800    border-red-200"    },
    };
    const badge = statusMap[order.payment_status] ?? { label: order.payment_status, cls: "bg-gray-100 text-gray-700 border-gray-200" };
    const showBalance = ["partial", "deposit"].includes(order.payment_status) && order.amount_due < order.total_amount;

    return (
        <Card>
            <div className="p-5 space-y-4">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <p className="text-2xs text-gray-400 uppercase tracking-wide font-medium">Order</p>
                        <p className="text-xl font-bold font-mono text-gray-900">{order.order_number}</p>
                        {order.customer_first_name && (
                            <p className="text-xs text-gray-500 mt-0.5">Hi, {order.customer_first_name} 👋</p>
                        )}
                    </div>
                    <span className={clsx("inline-flex items-center gap-1.5 text-2xs font-semibold px-2.5 py-1.5 rounded-full border whitespace-nowrap", badge.cls)}>
                        {badge.label}
                    </span>
                </div>

                <div className="border-t border-gray-50 pt-3 space-y-1">
                    {showBalance ? (
                        <>
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm text-gray-500">Total</span>
                                <span className="text-sm font-semibold text-gray-400 line-through">{fmt(order.total_amount, order.currency_code)}</span>
                            </div>
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm text-gray-800 font-medium">Amount Due</span>
                                <span className="text-3xl font-bold tabular-nums text-gray-900">{fmt(order.amount_due, order.currency_code)}</span>
                            </div>
                        </>
                    ) : (
                        <div className="flex items-baseline justify-between">
                            <span className="text-sm text-gray-500">Amount Due</span>
                            <span className="text-3xl font-bold tabular-nums text-gray-900">{fmt(order.amount_due, order.currency_code)}</span>
                        </div>
                    )}
                    {order.tax_amount > 0 && (
                        <p className="text-xs text-gray-400 text-right">
                            {order.prices_include_tax
                                ? `Includes tax of ${fmt(order.tax_amount, order.currency_code)}`
                                : `+ tax ${fmt(order.tax_amount, order.currency_code)}`}
                        </p>
                    )}
                    {order.is_international && (
                        <p className="text-xs text-blue-500 font-medium text-right mt-0.5">🌐 International order</p>
                    )}
                </div>
            </div>
        </Card>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function PaymentLinkPage() {
    const { token }         = useParams<{ token: string }>();
    const [searchParams]    = useSearchParams();
    const [stage, setStage] = useState<Stage>("loading");
    const [order, setOrder] = useState<OrderInfo | null>(null);
    const [error, setError] = useState("");

    // Track whether the initial stage decision has already been made.
    // The background poll must NEVER overwrite stage after this point -
    // doing so would reset whichever payment flow the user has navigated into.
    const initialised = useRef(false);
    const pollRef     = useRef<ReturnType<typeof setInterval> | null>(null);
    // Keep a live ref to the current stage so the polling callback can read it
    // without needing it as a dependency (avoids stale closure resets).
    const stageRef    = useRef<Stage>("loading");

    const clearOuterPoll = () => {
        if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    };

    const updateStage = (s: Stage) => { stageRef.current = s; setStage(s); };

    // ── Initial fetch - runs once on mount ───────────────────────────────────
    const initialLoad = useCallback(async () => {
        if (!token) { setError("Invalid payment link."); updateStage("error"); return; }
        try {
            const data = await api<OrderInfo>(`/v1/pay/${token}`);
            setOrder(data);
            initialised.current = true;

            if (data.payment_status === "paid") {
                updateStage("paid");
            } else if (data.expires_at && new Date(data.expires_at) < new Date()) {
                updateStage("expired");
            } else {
                // Decide starting stage once - never overwritten by background poll
                const fromPaystack = searchParams.get("status") === "returned";
                updateStage(fromPaystack ? "paystack_return" : "select_method");
            }
        } catch (e: any) {
            setError((e as Error).message ?? "Could not load payment details.");
            updateStage("error");
        }
    // searchParams is read once at mount - intentionally not in deps so the
    // callback is stable and the effect runs exactly once.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [token]);

    // ── Background refresh - only updates order data, never resets stage ─────
    // Runs every 15 s. Detects paid/expired terminal states only; never touches
    // stage while the user is in an active payment flow.
    const backgroundRefresh = useCallback(async () => {
        if (!token || !initialised.current) return;
        // Never interrupt an active payment sub-flow that has its own polling
        const activeFlows: Stage[] = ["mpesa", "paystack_redirect", "paystack_return"];
        if (activeFlows.includes(stageRef.current)) return;

        try {
            const data = await api<OrderInfo>(`/v1/pay/${token}`);
            setOrder(data); // update order details (e.g. amount_due) silently
            if (data.payment_status === "paid") {
                clearOuterPoll();
                updateStage("paid");
            } else if (data.expires_at && new Date(data.expires_at) < new Date()) {
                updateStage("expired");
            }
            // All other cases: leave stage alone - user is interacting
        } catch {
            // Silently ignore transient poll errors - don't show error to user
        }
    }, [token]);

    useEffect(() => {
        initialLoad();
        pollRef.current = setInterval(backgroundRefresh, 15_000);
        return clearOuterPoll;
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (stage === "loading") return <LoadingScreen />;
    if (stage === "error")   return <ErrorScreen message={error} onRetry={initialLoad} />;
    if (stage === "expired") return <ErrorScreen message="This payment link has expired. Please contact the business for a new link." />;
    if (stage === "paid")    return <PaidScreen orderNumber={order?.order_number ?? ""} businessName={order?.business_name ?? ""} />;
    if (!order) return <LoadingScreen />;

    const handleMethodSelect = (m: PaymentMethod) => {
        if (isMpesa(m))    return updateStage("mpesa");
        if (isPaystack(m)) return updateStage("paystack_email");
        if (isBank(m))     return updateStage("bank_transfer");
    };

    const isPanelStage = !["select_method", "bank_pending"].includes(stage);
    const panelTitle: Record<Stage, string> = {
        mpesa:            "M-Pesa Payment",
        paystack_email:   "Card Payment",
        paystack_redirect:"Redirecting…",
        paystack_return:  "Confirming Payment",
        bank_transfer:    "Bank Transfer",
        bank_pending:     "",
        loading: "", error: "", expired: "", select_method: "", paid: "",
    };

    return (
        <div className="min-h-screen bg-gray-50 flex flex-col items-center justify-start py-4 px-4 sm:py-8">
            <div className="w-full max-w-md space-y-4">

                {/* Business header */}
                <div className="text-center mb-2">
                    {order.business_logo ? (
                        <img src={order.business_logo} alt={order.business_name}
                            className="h-10 mx-auto mb-2 object-contain" />
                    ) : (
                        <h1 className="text-xl font-bold text-gray-900">{order.business_name}</h1>
                    )}
                    {order.business_tagline && (
                        <p className="text-xs text-gray-400">{order.business_tagline}</p>
                    )}
                </div>

                {/* Order summary */}
                <OrderCard order={order} />

                {/* Payment panel */}
                <Card>
                    <div className="p-5">

                        {/* Back button + title when inside a method flow */}
                        {/* mpesa excluded - MpesaPanel renders its own back button */}
                        {isPanelStage && stage !== "paystack_return" && stage !== "mpesa" && (
                            <div className="flex items-center gap-2 mb-4">
                                <button
                                    onClick={() => updateStage("select_method")}
                                    className="p-1 rounded-lg hover:bg-gray-100 transition-colors"
                                >
                                    <svg className="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                                    </svg>
                                </button>
                                <h2 className="text-sm font-semibold text-gray-800">{panelTitle[stage]}</h2>
                            </div>
                        )}

                        {/* Select method */}
                        {stage === "select_method" && (
                            <>
                                <h2 className="text-sm font-semibold text-gray-800 mb-3">Choose payment method</h2>
                                <MethodList methods={order.available_methods} onSelect={handleMethodSelect} />
                            </>
                        )}

                        {/* M-Pesa */}
                        {stage === "mpesa" && (
                            <MpesaPanel
                                token={token!}
                                amountDue={order.amount_due}
                                currency={order.currency_code}
                                onSuccess={() => { clearOuterPoll(); updateStage("paid"); }}
                                onBack={() => updateStage("select_method")}
                            />
                        )}

                        {/* Paystack - email collection */}
                        {stage === "paystack_email" && (
                            <PaystackEmailPanel
                                token={token!}
                                amountDue={order.amount_due}
                                currency={order.currency_code}
                                onBack={() => updateStage("select_method")}
                            />
                        )}

                        {/* Paystack - returned from gateway, confirming */}
                        {stage === "paystack_return" && (
                            <PaystackReturnPanel
                                token={token!}
                                onSuccess={() => { clearOuterPoll(); updateStage("paid"); }}
                            />
                        )}

                        {/* Bank transfer */}
                        {stage === "bank_transfer" && (
                            <BankTransferPanel
                                token={token!}
                                amountDue={order.amount_due}
                                currency={order.currency_code}
                                businessName={order.business_name}
                                onDone={() => updateStage("bank_pending")}
                                onBack={() => updateStage("select_method")}
                            />
                        )}

                        {/* Bank transfer - proof submitted */}
                        {stage === "bank_pending" && (
                            <div className="text-center py-8 space-y-3">
                                <div className="w-12 h-12 rounded-full bg-purple-50 flex items-center justify-center mx-auto">
                                    <svg className="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <p className="text-sm font-semibold text-gray-800">Proof submitted!</p>
                                <p className="text-xs text-gray-500 max-w-xs mx-auto">
                                    {order.business_name} will review your transfer receipt and confirm your order.
                                    You'll be notified once approved.
                                </p>
                            </div>
                        )}
                    </div>
                </Card>

                {/* Security footer */}
                <p className="text-center text-2xs text-gray-300 flex items-center justify-center gap-1.5">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                    </svg>
                    Secured by {order.business_name}
                </p>
            </div>
        </div>
    );
}