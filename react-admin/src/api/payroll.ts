import { get, post, del } from "./client";

/** Payroll (Bethany People PR 6). Every figure is KES; the server computed it from the approved timesheet and the pay structure. */

export type RunStatus = "draft" | "approved" | "paid";
export type StructureKind = "monthly" | "daily" | "hourly";
export type RateCode = "paye" | "nssf" | "shif" | "housing_levy";

export interface PayslipItem { label: string; amount: number; kind: "earning" | "deduction" }

export interface LineHours {
    regular_minutes: number; overtime_minutes: number; rest_day_minutes: number; holiday_minutes: number;
    paid_leave_days: number; unpaid_leave_days: number; days_worked: number;
}

export interface PayrollLine {
    id: number;
    user: { id: number; name?: string };
    hours: LineHours;
    items: PayslipItem[];
    structure: { kind: StructureKind | null; base_amount: number | null; hourly_equivalent: number | null; service_fraction: number | null };
    payment_method: "mpesa" | "bank" | "cash" | null;
    payment_reference: string | null;
    paid_at: string | null;
    notes: string | null;
    basic: number; overtime_pay: number; rest_day_pay: number; holiday_pay: number; allowances: number; unpaid_deduction: number;
    gross: number; taxable: number; paye: number; nssf_employee: number; nssf_employer: number; shif: number;
    housing_employee: number; housing_employer: number; other_deductions: number; net: number; employer_cost: number;
    run?: { id: number; reference: string; status: RunStatus; starts_on: string | null; ends_on: string | null; group: string | null };
}

export interface RunTotals {
    gross: number; taxable: number; paye: number; nssf_employee: number; nssf_employer: number; shif: number;
    housing_employee: number; housing_employer: number; other_deductions: number; net: number; employer_cost: number;
}

export interface RateUsed { id: number; code: RateCode; effective_from: string; is_confirmed: boolean; reduces_taxable_pay: boolean; config: Record<string, unknown> }

export interface PayrollRun {
    id: number;
    reference: string;
    kind: "regular" | "adjustment";
    status: RunStatus;
    people: number;
    period: { id: number; starts_on: string; ends_on: string; status: string } | null;
    group: { id: number; name: string } | null;
    totals: RunTotals;
    rates_used: RateUsed[] | null;
    unpayable: { id: number; name: string; why: string }[];
    expense: { id: number; reference?: string; status?: string; amount_kes?: number } | null;
    approved_by: string | null;
    approved_at: string | null;
    paid_at: string | null;
    created_at: string | null;
    notes: string | null;
    lines?: PayrollLine[];
    reconciles?: boolean;
    breaks?: string[];
}

export interface RunsIndex {
    year: number;
    runs: PayrollRun[];
    ready_periods: { id: number; group: string | null; starts_on: string; ends_on: string }[];
    rates_unconfirmed: RateCode[];
    can_run: boolean;
}

export interface RateTable {
    id: number;
    code: RateCode;
    effective_from: string;
    config: Record<string, unknown>;
    reduces_taxable_pay: boolean;
    source: string | null;
    is_confirmed: boolean;
    confirmed_at: string | null;
}

export interface Allowance { name: string; amount: number; taxable?: boolean }

export interface PayStructure {
    id: number;
    effective_from: string;
    kind: StructureKind;
    base_amount: number;
    currency_code: string;
    allowances: Allowance[];
    notes: string | null;
    created_at: string | null;
}

export const payrollApi = {
    runs: (year?: number) => get<RunsIndex>("/v1/admin/payroll/runs", { params: year ? { year: String(year) } : undefined }),
    draft: (periodId: number) => post<{ message: string; run: PayrollRun }>("/v1/admin/payroll/runs", { pay_period_id: periodId }),
    show: (id: number) => get<{ run: PayrollRun }>(`/v1/admin/payroll/runs/${id}`),
    recalculate: (id: number) => post<{ message: string; run: PayrollRun }>(`/v1/admin/payroll/runs/${id}/recalculate`),
    discard: (id: number) => del<{ message: string }>(`/v1/admin/payroll/runs/${id}`),
    /** The server asks for a step-up first. */
    approve: (id: number) => post<{ message: string; run: PayrollRun }>(`/v1/admin/payroll/runs/${id}/approve`),
    pay: (id: number, lines: { line_id: number; payment_method: "mpesa" | "bank" | "cash"; payment_reference?: string }[]) =>
        post<{ message: string; run: PayrollRun }>(`/v1/admin/payroll/runs/${id}/pay`, { lines }),
    payslipPdf: (runId: number, lineId: number) => get<Blob>(`/v1/admin/payroll/runs/${runId}/lines/${lineId}/payslip`, { responseType: "blob" }),

    rates: () => get<{ data: RateTable[] }>("/v1/admin/payroll/rates"),
    addRate: (data: { code: RateCode; effective_from: string; config: Record<string, unknown>; reduces_taxable_pay: boolean; source?: string }) =>
        post<{ message: string; rate: RateTable }>("/v1/admin/payroll/rates", data),
    confirmRate: (id: number) => post<{ message: string; rate: RateTable }>(`/v1/admin/payroll/rates/${id}/confirm`),

    // ── Employee file ──
    structures: (userId: number) => get<{ data: PayStructure[]; can_edit: boolean }>(`/v1/admin/people/${userId}/pay`),
    addStructure: (userId: number, data: { effective_from: string; kind: StructureKind; base_amount: number; allowances?: Allowance[]; notes?: string }) =>
        post<{ message: string; structure: PayStructure }>(`/v1/admin/people/${userId}/pay`, data),

    // ── Me ──
    myPayslips: () => get<{ data: PayrollLine[] }>("/v1/admin/me/payslips"),
    myPayslipPdf: (lineId: number) => get<Blob>(`/v1/admin/me/payslips/${lineId}/pdf`, { responseType: "blob" }),
};

export const kes = (v: number | null | undefined) => v === null || v === undefined ? "—" : new Intl.NumberFormat("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
export const RUN_TONE: Record<RunStatus, string> = { draft: "bg-surface-100 text-surface-600", approved: "bg-info-light text-info", paid: "bg-success-light text-success" };
export const RUN_LABEL: Record<RunStatus, string> = { draft: "Draft", approved: "Approved", paid: "Paid" };
export const RATE_LABEL: Record<RateCode, string> = { paye: "PAYE", nssf: "NSSF", shif: "SHIF", housing_levy: "Housing Levy" };
export const KIND_LABEL: Record<StructureKind, string> = { monthly: "Monthly salary", daily: "Daily rate", hourly: "Hourly rate" };

export function saveBlob(blob: Blob, name: string) {
    const url = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = url; a.download = name; a.click(); URL.revokeObjectURL(url);
}

// ── Advances, payouts and returns (People PR 7) ──
export type AdvanceStatus = "requested" | "approved" | "paid" | "settled" | "declined" | "cancelled";
export interface Advance {
    id: number; kind: "advance" | "loan"; status: AdvanceStatus; amount: number; instalment: number; balance: number; reason: string | null;
    requested_at: string | null; decided_at: string | null; decision_note: string | null; paid_at: string | null; payment_method: string | null; payment_reference: string | null; settled_at: string | null;
    repayments: { id: number; kind: "payroll" | "cash"; amount: number; on: string; reference: string | null }[];
    user?: { id: number; name?: string }; decided_by?: string | null;
}
export interface PayoutReadiness { mpesa: { ready: number; total: string; missing: string[] }; bank: { ready: number; total: string; missing: string[] }; cash: number; no_pay_method: string[] }
export type ReturnKind = "paye" | "nssf" | "shif" | "housing_levy";

export const advancesApi = {
    mine: () => get<{ data: Advance[]; open_balance: number }>("/v1/admin/me/advances"),
    request: (data: { kind: "advance" | "loan"; amount: number; instalment?: number; reason: string }) => post<{ message: string; advance: Advance }>("/v1/admin/me/advances", data),
    cancelMine: (id: number) => del<{ message: string }>(`/v1/admin/me/advances/${id}`),
    list: (status?: string) => get<{ data: Advance[]; open_balance: number; can_run: boolean }>("/v1/admin/payroll/advances", { params: status ? { status } : undefined }),
    create: (data: { user_id: number; kind: "advance" | "loan"; amount: number; instalment?: number; reason: string }) => post<{ message: string; advance: Advance }>("/v1/admin/payroll/advances", data),
    approve: (id: number, data?: { instalment?: number; note?: string }) => post<{ message: string; advance: Advance }>(`/v1/admin/payroll/advances/${id}/approve`, data ?? {}),
    decline: (id: number, note: string) => post<{ message: string; advance: Advance }>(`/v1/admin/payroll/advances/${id}/decline`, { note }),
    payout: (id: number, data: { payment_method: "mpesa" | "bank" | "cash"; payment_reference?: string }) => post<{ message: string; advance: Advance }>(`/v1/admin/payroll/advances/${id}/payout`, data),
    repay: (id: number, data: { amount: number; reference?: string }) => post<{ message: string; advance: Advance }>(`/v1/admin/payroll/advances/${id}/repay`, data),
    payoutReadiness: (runId: number) => get<PayoutReadiness>(`/v1/admin/payroll/runs/${runId}/payout-readiness`),
    payoutFile: (runId: number, method: "mpesa" | "bank") => get<Blob>(`/v1/admin/payroll/runs/${runId}/payout`, { params: { method }, responseType: "blob" }),
    returns: (month: string, kind: ReturnKind) => get<Blob>("/v1/admin/payroll/returns", { params: { month, kind }, responseType: "blob" }),
};
export const ADVANCE_TONE: Record<AdvanceStatus, string> = { requested: "bg-warning-light text-warning", approved: "bg-info-light text-info", paid: "bg-brand-100 text-brand-700", settled: "bg-success-light text-success", declined: "bg-danger-light text-danger", cancelled: "bg-surface-100 text-surface-500" };
export const ADVANCE_LABEL: Record<AdvanceStatus, string> = { requested: "Waiting", approved: "Approved", paid: "Paid out", settled: "Settled", declined: "Declined", cancelled: "Cancelled" };
export const RETURN_LABEL: Record<ReturnKind, string> = { paye: "PAYE (P10 working file)", nssf: "NSSF", shif: "SHIF", housing_levy: "Housing Levy" };
