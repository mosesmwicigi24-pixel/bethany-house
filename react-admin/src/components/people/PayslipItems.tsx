import { clsx } from "clsx";
import { kes, type PayrollLine } from "@/api/payroll";
import { hm } from "@/api/timesheets";

/** One payslip's lines: earnings, deductions, net — the same list the PDF prints (People PR 6). */
export function PayslipItems({ line, compact = false }: { line: PayrollLine; compact?: boolean }) {
    const earnings = line.items.filter(i => i.kind === "earning");
    const deductions = line.items.filter(i => i.kind === "deduction");
    const Row = ({ label, amount, strong }: { label: string; amount: number; strong?: boolean }) => (
        <div className={clsx("flex items-baseline justify-between gap-3 py-1", strong && "font-semibold text-surface-900")}>
            <span className={clsx("text-sm", !strong && "text-surface-700")}>{label}</span>
            <span className={clsx("text-sm tabular-nums", amount < 0 && !strong && "text-surface-500")}>{amount < 0 ? `(${kes(-amount)})` : kes(amount)}</span>
        </div>
    );
    return (
        <div className="space-y-2">
            <div>
                {earnings.map((i, k) => <Row key={k} label={i.label} amount={i.amount} />)}
                <div className="border-t border-line"><Row label="Gross pay" amount={line.gross} strong /></div>
            </div>
            {deductions.length > 0 && (
                <div>
                    {deductions.map((i, k) => <Row key={k} label={i.label} amount={i.amount} />)}
                </div>
            )}
            <div className="border-t-2 border-surface-900"><Row label="Net pay" amount={line.net} strong /></div>
            {!compact && (
                <p className="text-2xs text-surface-500 leading-relaxed">
                    Hours: regular {hm(line.hours.regular_minutes)}, overtime {hm(line.hours.overtime_minutes)}, rest days {hm(line.hours.rest_day_minutes)}, holidays {hm(line.hours.holiday_minutes)}
                    {" · "}paid leave {line.hours.paid_leave_days} d, unpaid {line.hours.unpaid_leave_days} d, days worked {line.hours.days_worked}.
                    {" "}Taxable pay {kes(line.taxable)}. Employer NSSF {kes(line.nssf_employer)} and Housing Levy {kes(line.housing_employer)} are not deducted from you.
                </p>
            )}
        </div>
    );
}
