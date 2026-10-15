/**
 * MorningCheckGate.tsx
 *
 * The morning check (owner, 2026-10-15): "The point of sale should restart
 * every morning. If she did not close yesterday, she gets a pop-up that
 * requires her to write the report for yesterday so that today she can sell."
 *
 * Shown in place of the POS whenever the server reports a till of hers still
 * open from an earlier day (register/status → stale_till). It cannot be
 * dismissed: the server refuses every sale and payment meanwhile
 * (TILL_FROM_EARLIER_DAY), so there is nothing behind it to go back to.
 *
 *   1. Write that day's End-of-Day report (pinned to the till's trading day).
 *   2. Count the drawer — the same blind count as an evening close; the
 *      outlet manager verifies it, and sees that it was closed late.
 *   3. Open today's till (the ordinary open-register gate takes over).
 *
 * Nothing is closed on her behalf: a drawer count cannot be invented.
 */

import { useState } from "react";
import { clsx } from "clsx";
import type { Outlet, StaleTill } from "@/api/pos";
import RegisterModal from "./RegisterModal";
import UserEodModal from "./UserEodModal";

interface Props {
    till: StaleTill;
    outlets: Outlet[];
    /** Re-read register status after each step. */
    onProgress: () => void;
}

const dayLabel = (ymd: string | null): string => {
    if (!ymd) return "an earlier day";
    const [y, m, d] = ymd.split("-").map(Number);
    // Noon UTC is the same calendar day in Nairobi — no edge to fall off.
    return new Date(Date.UTC(y, m - 1, d, 12)).toLocaleDateString("en-KE", {
        timeZone: "Africa/Nairobi", weekday: "long", day: "numeric", month: "long",
    });
};

export default function MorningCheckGate({ till, outlets, onProgress }: Props) {
    const [step, setStep] = useState<"none" | "report" | "count">("none");
    const day = dayLabel(till.trading_day);
    const reportDone = till.eod_submitted;

    const Step = ({ n, title, body, done, active }: { n: number; title: string; body: string; done: boolean; active: boolean }) => (
        <li className={clsx("flex gap-3 rounded-xl border p-3", active ? "border-brand-300 bg-brand-50" : "border-line")}>
            <span className={clsx(
                "w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold shrink-0",
                done ? "bg-success text-white" : active ? "bg-brand-600 text-white" : "bg-surface-100 text-surface-500",
            )}>
                {done ? "✓" : n}
            </span>
            <div className="min-w-0">
                <p className={clsx("text-sm font-semibold", done ? "text-surface-500 line-through" : "text-surface-900")}>{title}</p>
                <p className="text-xs text-surface-500 mt-0.5">{body}</p>
            </div>
        </li>
    );

    return (
        <div className="flex items-center justify-center min-h-full p-4">
            <div className="bg-white rounded-2xl shadow-card border border-line w-full max-w-md p-5 space-y-4" role="alertdialog" aria-labelledby="morning-check-title">
                <div>
                    <h2 id="morning-check-title" className="font-bold text-lg text-surface-900">
                        Close your till from {day}
                    </h2>
                    <p className="text-sm text-surface-500 mt-1">
                        Your till{till.outlet_name ? ` at ${till.outlet_name}` : ""} was not closed. Finish that day
                        before you sell today, so each day's money stays in its own till.
                    </p>
                </div>

                <ol className="space-y-2">
                    <Step n={1} title={`Write the report for ${day}`} body="Notes on that day's orders and how the day went."
                        done={reportDone} active={!reportDone} />
                    <Step n={2} title="Count the drawer" body="Enter exactly what is in it. Your outlet manager verifies the count."
                        done={false} active={reportDone} />
                    <Step n={3} title="Open today's till" body="Count the morning float and start selling."
                        done={false} active={false} />
                </ol>

                <button
                    className="btn-primary w-full"
                    onClick={() => setStep(reportDone ? "count" : "report")}
                >
                    {reportDone ? "Count the drawer" : `Write the report for ${day}`}
                </button>
            </div>

            {step === "report" && (
                <UserEodModal
                    outletId={till.outlet_id}
                    outletName={till.outlet_name ?? ""}
                    registerId={till.id}
                    fixedDate={till.trading_day ?? undefined}
                    onClose={() => setStep("none")}
                    onSubmitSuccess={() => {
                        setStep("none");
                        onProgress();
                    }}
                />
            )}

            {step === "count" && (
                <RegisterModal
                    outlets={outlets}
                    defaultOutletId={till.outlet_id}
                    mode="close"
                    eodSubmitted
                    onClose={() => setStep("none")}
                    onSuccess={() => {
                        setStep("none");
                        onProgress();
                    }}
                    onRequestEod={() => setStep("report")}
                />
            )}
        </div>
    );
}
