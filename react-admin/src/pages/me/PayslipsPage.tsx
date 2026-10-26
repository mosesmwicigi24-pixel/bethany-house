import { useState } from "react";
import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { payrollApi, kes, saveBlob, RUN_TONE, type PayrollLine } from "@/api/payroll";
import { PayslipItems } from "@/components/people/PayslipItems";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** My payslips (People PR 6): every approved run I was on. The PDF asks for a step-up. Phone first. */
export default function PayslipsPage() {
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["my-payslips"], queryFn: () => payrollApi.myPayslips() });
    const [open, setOpen] = useState<number | null>(null);
    const fmtD = (iso: string | null) => (iso ? new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");
    const pdf = async (l: PayrollLine) => {
        try { saveBlob(await payrollApi.myPayslipPdf(l.id), `payslip-${l.run?.reference ?? l.id}.pdf`); } catch (e) { const err = e as ApiError; if (err?.message) toast.error(err.message); }
    };
    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4">
            <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">Bethany House</p>
                <h1 className="text-xl font-bold text-surface-900">My payslips</h1>
            </div>
            {data.data.length === 0 && <p className="rounded-2xl bg-white border border-line shadow-sm p-4 text-sm text-surface-500">No payslips yet. One appears here when a pay run you were on is approved.</p>}
            {data.data.map(l => (
                <div key={l.id} className="rounded-2xl bg-white border border-line shadow-sm overflow-hidden">
                    <button onClick={() => setOpen(open === l.id ? null : l.id)} className="w-full flex items-center justify-between gap-3 p-4 text-left">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-surface-900">{fmtD(l.run?.starts_on ?? null)} – {fmtD(l.run?.ends_on ?? null)}</p>
                            <p className="text-2xs text-surface-500">{l.run?.reference}{l.paid_at ? ` · paid ${new Date(l.paid_at).toLocaleDateString("en-GB", { day: "numeric", month: "short" })} by ${l.payment_method}` : " · not yet paid"}</p>
                        </div>
                        <div className="text-right shrink-0">
                            <p className="text-base font-bold text-surface-900 tabular-nums">{kes(l.net)}</p>
                            <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", RUN_TONE[l.paid_at ? "paid" : "approved"])}>{l.paid_at ? "Paid" : "Approved"}</span>
                        </div>
                    </button>
                    {open === l.id && (
                        <div className="border-t border-line p-4 space-y-3">
                            <PayslipItems line={l} />
                            <button onClick={() => void pdf(l)} className="btn-secondary w-full">Download PDF</button>
                        </div>
                    )}
                </div>
            ))}
            <Link to="/me/clock" className="block text-center text-xs text-brand-600 underline">Back to the clock</Link>
        </div>
    );
}
