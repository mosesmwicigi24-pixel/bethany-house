import { useState } from "react";
import { useNavigate, useLocation, Navigate } from "react-router-dom";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { authApi } from "@/api/auth";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";
import type { ApiError } from "@/types";

// ─── Schemas ──────────────────────────────────────────────────────────────────

const loginSchema = z.object({
    email:       z.string().email("Enter a valid email address"),
    password:    z.string().min(1, "Password is required"),
    remember_me: z.boolean().optional(),
});

const tfaSchema = z.object({
    code: z.string().length(6, "Enter the 6-digit code from your authenticator"),
});

const forgotSchema = z.object({
    email: z.string().email("Enter a valid email address"),
});

const resetSchema = z
    .object({
        password:              z.string().min(8, "Password must be at least 8 characters"),
        password_confirmation: z.string(),
    })
    .refine((d) => d.password === d.password_confirmation, {
        message: "Passwords do not match",
        path:    ["password_confirmation"],
    });

type LoginForm  = z.infer<typeof loginSchema>;
type TfaForm    = z.infer<typeof tfaSchema>;
type ForgotForm = z.infer<typeof forgotSchema>;
type ResetForm  = z.infer<typeof resetSchema>;
type Step = "credentials" | "2fa" | "forgot" | "forgot-sent" | "reset";

// ─── Left panel ───────────────────────────────────────────────────────────────

function LeftPanel() {
    return (
        <div className="hidden lg:flex w-[45%] bg-surface-900 flex-col justify-between p-12 relative overflow-hidden">
            <div
                className="absolute inset-0 opacity-[0.03]"
                style={{
                    backgroundImage: "radial-gradient(circle at 1px 1px, white 1px, transparent 0)",
                    backgroundSize:  "32px 32px",
                }}
            />
            <div className="relative">
                <img src={`${import.meta.env.BASE_URL}images/logo-light.png`} alt="Bethany House" className="h-10 w-auto object-contain" />
            </div>
            <div className="relative">
                <blockquote className="text-2xl font-display font-semibold text-white leading-snug text-balance">
                    "Manage your business with clarity and confidence."
                </blockquote>
                <p className="mt-4 text-sm text-surface-400">
                    Procurement · Sales · Production — all in one place.
                </p>
            </div>
            <div className="relative flex items-center gap-4">
                <div className="flex gap-1">
                    {[1, 2, 3].map((i) => (
                        <div key={i} className={`h-1 rounded-full ${i === 1 ? "w-6 bg-brand-400" : "w-2 bg-surface-600"}`} />
                    ))}
                </div>
            </div>
        </div>
    );
}

// ─── Back button ──────────────────────────────────────────────────────────────

function BackButton({ onClick, label = "Back to sign in" }: { onClick: () => void; label?: string }) {
    return (
        <button onClick={onClick} className="flex items-center gap-1.5 text-sm text-surface-500 hover:text-surface-700 mb-4 transition-colors">
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {label}
        </button>
    );
}

// ─── Component ────────────────────────────────────────────────────────────────

/**
 * Eye toggle for a password field. Sits inside a `relative` wrapper; the input
 * needs `pr-10` so the text never runs under it.
 */
function PasswordToggle({ shown, onToggle }: { shown: boolean; onToggle: () => void }) {
    const label = shown ? "Hide password" : "Show password";
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-label={label}
            aria-pressed={shown}
            title={label}
            tabIndex={-1}
            className="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-700 transition-colors"
        >
            {shown ? (
                // Eye with a slash — password is visible, click to hide
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                </svg>
            ) : (
                // Eye — password is hidden, click to show
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            )}
        </button>
    );
}

export default function LoginPage() {
    const navigate = useNavigate();
    const location = useLocation();
    const { isAuthenticated, login, verify2fa, isLoading } = useAuthStore();
    const toast = useToastStore();

    const [step,          setStep]          = useState<Step>("credentials");
    const [userId,        setUserId]        = useState<number | null>(null);
    const [resetToken,    setResetToken]    = useState("");
    const [resetEmail,    setResetEmail]    = useState("");
    const [forgotLoading, setForgotLoading] = useState(false);
    const [resetLoading,  setResetLoading]  = useState(false);
    const [showPassword,  setShowPassword]  = useState(false);
    const [showNewPw,     setShowNewPw]     = useState(false);
    const [showConfirmPw, setShowConfirmPw] = useState(false);

    // ── All hooks unconditional ───────────────────────────────────────────────
    const credForm = useForm<LoginForm>({
        resolver: zodResolver(loginSchema),
        defaultValues: { email: "", password: "", remember_me: false },
    });
    const tfaForm = useForm<TfaForm>({
        resolver: zodResolver(tfaSchema),
        defaultValues: { code: "" },
    });
    const forgotForm = useForm<ForgotForm>({
        resolver: zodResolver(forgotSchema),
        defaultValues: { email: "" },
    });
    const resetForm = useForm<ResetForm>({
        resolver: zodResolver(resetSchema),
        defaultValues: { password: "", password_confirmation: "" },
    });

    const from = (location.state as { from?: Location })?.from?.pathname ?? "/dashboard";

    // ── Handle reset link from email (?token=...&email=...) ──────────────────
    // Laravel's password reset email links to APP_URL — configure APP_URL to
    // point to the React admin URL so ?token= and ?email= land here.
    const urlParams = new URLSearchParams(location.search);
    const urlToken  = urlParams.get("token");
    const urlEmail  = urlParams.get("email");
    if (urlToken && urlEmail && step === "credentials" && !resetToken) {
        setResetToken(urlToken);
        setResetEmail(urlEmail);
        setStep("reset");
    }

    // Redirect already-authenticated users — after all hooks
    if (isAuthenticated) return <Navigate to={from} replace />;

    // ── Handlers ──────────────────────────────────────────────────────────────

    const onLoginSubmit = async (values: LoginForm) => {
        try {
            const result = await login(values);
            if (result.requires2fa && result.userId) {
                setUserId(result.userId);
                setStep("2fa");
                tfaForm.reset();
            } else {
                navigate(from, { replace: true });
            }
        } catch (err) {
            const apiErr = err as ApiError;
            if (apiErr.errors?.email) {
                credForm.setError("email", { message: apiErr.errors.email[0] });
            } else if (apiErr.errors?.password) {
                credForm.setError("password", { message: apiErr.errors.password[0] });
            } else {
                toast.error(apiErr.message ?? "Login failed. Please try again.");
            }
        }
    };

    const on2faSubmit = async (values: TfaForm) => {
        if (!userId) return;
        try {
            await verify2fa(userId, values.code);
            navigate(from, { replace: true });
        } catch (err) {
            const apiErr = err as ApiError;
            tfaForm.setError("code", {
                message: apiErr.message ?? "Invalid code. Please try again.",
            });
        }
    };

    const onForgotSubmit = async (values: ForgotForm) => {
        setForgotLoading(true);
        try {
            await authApi.forgotPassword(values.email);
            setResetEmail(values.email);
            setStep("forgot-sent");
        } catch (err) {
            const apiErr = err as ApiError;
            forgotForm.setError("email", {
                message: apiErr.errors?.email?.[0] ?? apiErr.message ?? "Something went wrong.",
            });
        } finally {
            setForgotLoading(false);
        }
    };

    const onResetSubmit = async (values: ResetForm) => {
        setResetLoading(true);
        try {
            await authApi.resetPassword({
                token:                 resetToken,
                email:                 resetEmail,
                password:              values.password,
                password_confirmation: values.password_confirmation,
            });
            toast.success("Password reset successfully. Please sign in.");
            setStep("credentials");
            credForm.setValue("email", resetEmail);
            resetForm.reset();
        } catch (err) {
            const apiErr = err as ApiError;
            if (apiErr.errors?.password) {
                resetForm.setError("password", { message: apiErr.errors.password[0] });
            } else {
                toast.error(apiErr.message ?? "Reset failed. The link may have expired.");
            }
        } finally {
            setResetLoading(false);
        }
    };

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <div className="min-h-screen flex bg-surface-50">
            <LeftPanel />

            <div className="flex-1 flex items-center justify-center p-6">
                <div className="w-full max-w-md">
                    {/* Mobile logo */}
                    <div className="lg:hidden mb-8 flex justify-center">
                        <img src={`${import.meta.env.BASE_URL}images/logo.png`} alt="Bethany House" className="h-10 w-auto object-contain" />
                    </div>

                    <div className="card p-8 shadow-card-lg">

                        {/* ── credentials ──────────────────────────────── */}
                        {step === "credentials" && (
                            <>
                                <div className="mb-7">
                                    <h1 className="font-display text-2xl font-bold text-surface-900">Welcome back</h1>
                                    <p className="mt-1 text-sm text-surface-500">Sign in to the admin dashboard</p>
                                </div>

                                <form onSubmit={credForm.handleSubmit(onLoginSubmit)} noValidate className="space-y-5">
                                    <div>
                                        <label className="label" htmlFor="email">Email address</label>
                                        <input
                                            id="email" type="email" autoComplete="email" autoFocus
                                            className={clsx("input", credForm.formState.errors.email && "input-error")}
                                            placeholder="you@example.com"
                                            {...credForm.register("email")}
                                        />
                                        {credForm.formState.errors.email && (
                                            <p className="field-error">{credForm.formState.errors.email.message}</p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="label" htmlFor="password">Password</label>
                                        <div className="relative">
                                            <input
                                                id="password"
                                                type={showPassword ? "text" : "password"}
                                                autoComplete="current-password"
                                                className={clsx("input pr-10", credForm.formState.errors.password && "input-error")}
                                                placeholder="••••••••"
                                                {...credForm.register("password")}
                                            />
                                            <PasswordToggle shown={showPassword} onToggle={() => setShowPassword((s) => !s)} />
                                        </div>
                                        {credForm.formState.errors.password && (
                                            <p className="field-error">{credForm.formState.errors.password.message}</p>
                                        )}
                                    </div>

                                    <div className="flex items-center justify-between">
                                        <label className="flex items-center gap-2 cursor-pointer select-none">
                                            <input
                                                type="checkbox"
                                                className="w-4 h-4 rounded border-surface-300 text-brand-500 accent-brand-500"
                                                {...credForm.register("remember_me")}
                                            />
                                            <span className="text-sm text-surface-600">Remember me</span>
                                        </label>
                                        {/* Forgot password — in-page, pre-fills email */}
                                        <button
                                            type="button"
                                            onClick={() => {
                                                forgotForm.setValue("email", credForm.getValues("email"));
                                                setStep("forgot");
                                            }}
                                            className="text-sm text-brand-600 hover:text-brand-700 transition-colors"
                                        >
                                            Forgot password?
                                        </button>
                                    </div>

                                    <button type="submit" disabled={isLoading} className="btn-primary w-full h-10 mt-2">
                                        {isLoading
                                            ? <Spinner size="sm" className="border-white/30 border-t-white" />
                                            : "Sign in"
                                        }
                                    </button>
                                </form>
                            </>
                        )}

                        {/* ── 2FA ──────────────────────────────────────── */}
                        {step === "2fa" && (
                            <>
                                <div className="mb-7">
                                    <BackButton onClick={() => { setStep("credentials"); tfaForm.reset(); }} label="Back" />
                                    <h1 className="font-display text-2xl font-bold text-surface-900">Two-factor auth</h1>
                                    <p className="mt-1 text-sm text-surface-500">
                                        Enter the 6-digit code from your authenticator app.
                                    </p>
                                </div>

                                <form onSubmit={tfaForm.handleSubmit(on2faSubmit)} noValidate className="space-y-5">
                                    <div>
                                        <label className="label" htmlFor="code">Verification code</label>
                                        <input
                                            id="code"
                                            type="text"
                                            inputMode="numeric"
                                            autoComplete="one-time-code"
                                            maxLength={6}
                                            autoFocus
                                            className={clsx(
                                                "input text-center font-mono text-xl tracking-[0.5em]",
                                                tfaForm.formState.errors.code && "input-error"
                                            )}
                                            placeholder="000000"
                                            {...tfaForm.register("code")}
                                        />
                                        {tfaForm.formState.errors.code && (
                                            <p className="field-error text-center">{tfaForm.formState.errors.code.message}</p>
                                        )}
                                    </div>

                                    <button type="submit" disabled={isLoading} className="btn-primary w-full h-10">
                                        {isLoading
                                            ? <Spinner size="sm" className="border-white/30 border-t-white" />
                                            : "Verify"
                                        }
                                    </button>
                                </form>
                            </>
                        )}

                        {/* ── forgot password ───────────────────────────── */}
                        {step === "forgot" && (
                            <>
                                <div className="mb-7">
                                    <BackButton onClick={() => setStep("credentials")} />
                                    <h1 className="font-display text-2xl font-bold text-surface-900">Reset your password</h1>
                                    <p className="mt-1 text-sm text-surface-500">
                                        Enter your email and we'll send you a reset link.
                                    </p>
                                </div>

                                <form onSubmit={forgotForm.handleSubmit(onForgotSubmit)} noValidate className="space-y-5">
                                    <div>
                                        <label className="label" htmlFor="forgot-email">Email address</label>
                                        <input
                                            id="forgot-email" type="email" autoComplete="email" autoFocus
                                            className={clsx("input", forgotForm.formState.errors.email && "input-error")}
                                            placeholder="you@example.com"
                                            {...forgotForm.register("email")}
                                        />
                                        {forgotForm.formState.errors.email && (
                                            <p className="field-error">{forgotForm.formState.errors.email.message}</p>
                                        )}
                                    </div>

                                    <button type="submit" disabled={forgotLoading} className="btn-primary w-full h-10">
                                        {forgotLoading
                                            ? <Spinner size="sm" className="border-white/30 border-t-white" />
                                            : "Send reset link"
                                        }
                                    </button>
                                </form>
                            </>
                        )}

                        {/* ── forgot-sent ───────────────────────────────── */}
                        {step === "forgot-sent" && (
                            <div className="flex flex-col items-center text-center py-4">
                                <div className="w-14 h-14 rounded-full bg-success-light flex items-center justify-center mb-4">
                                    <svg className="w-7 h-7 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                </div>
                                <h1 className="font-display text-2xl font-bold text-surface-900 mb-2">Check your email</h1>
                                <p className="text-sm text-surface-500 mb-1">We sent a password reset link to</p>
                                <p className="text-sm font-semibold text-surface-800 mb-6">{resetEmail}</p>
                                <p className="text-xs text-surface-400 mb-6">
                                    Didn't receive it? Check your spam folder or try again.
                                </p>
                                <div className="flex flex-col gap-2 w-full">
                                    <button
                                        onClick={() => { forgotForm.reset(); setStep("forgot"); }}
                                        className="btn-secondary w-full h-10"
                                    >
                                        Try a different email
                                    </button>
                                    <button
                                        onClick={() => setStep("credentials")}
                                        className="text-sm text-brand-600 hover:text-brand-700 transition-colors py-2"
                                    >
                                        Back to sign in
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* ── reset password ────────────────────────────── */}
                        {step === "reset" && (
                            <>
                                <div className="mb-7">
                                    <BackButton onClick={() => { resetForm.reset(); setStep("credentials"); }} />
                                    <h1 className="font-display text-2xl font-bold text-surface-900">Set a new password</h1>
                                    <p className="mt-1 text-sm text-surface-500">
                                        Choose a strong password for{" "}
                                        <span className="font-medium text-surface-700">{resetEmail}</span>.
                                    </p>
                                </div>

                                <form onSubmit={resetForm.handleSubmit(onResetSubmit)} noValidate className="space-y-5">
                                    <div>
                                        <label className="label" htmlFor="new-password">New password</label>
                                        <div className="relative">
                                            <input
                                                id="new-password"
                                                type={showNewPw ? "text" : "password"}
                                                autoComplete="new-password" autoFocus
                                                className={clsx("input pr-10", resetForm.formState.errors.password && "input-error")}
                                                placeholder="Min. 8 characters"
                                                {...resetForm.register("password")}
                                            />
                                            <PasswordToggle shown={showNewPw} onToggle={() => setShowNewPw((s) => !s)} />
                                        </div>
                                        {resetForm.formState.errors.password && (
                                            <p className="field-error">{resetForm.formState.errors.password.message}</p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="label" htmlFor="confirm-password">Confirm new password</label>
                                        <div className="relative">
                                            <input
                                                id="confirm-password"
                                                type={showConfirmPw ? "text" : "password"}
                                                autoComplete="new-password"
                                                className={clsx("input pr-10", resetForm.formState.errors.password_confirmation && "input-error")}
                                                placeholder="Re-enter password"
                                                {...resetForm.register("password_confirmation")}
                                            />
                                            <PasswordToggle shown={showConfirmPw} onToggle={() => setShowConfirmPw((s) => !s)} />
                                        </div>
                                        {resetForm.formState.errors.password_confirmation && (
                                            <p className="field-error">{resetForm.formState.errors.password_confirmation.message}</p>
                                        )}
                                    </div>

                                    <button type="submit" disabled={resetLoading} className="btn-primary w-full h-10">
                                        {resetLoading
                                            ? <Spinner size="sm" className="border-white/30 border-t-white" />
                                            : "Reset password"
                                        }
                                    </button>
                                </form>
                            </>
                        )}

                    </div>
                </div>
            </div>
        </div>
    );
}