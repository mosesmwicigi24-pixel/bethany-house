import { get, post, put, del, api } from "./client";

/** Checklists and forms (People C4). */

export type ItemType = "check" | "text" | "number" | "photo";
export interface FormItem { key: string; label: string; type: ItemType; required: boolean }

export interface FormTemplate {
    id: number;
    name: string;
    kind: "checklist" | "report";
    outlet: { id: number; name: string | null } | null;
    schedule: "daily" | "none";
    weekdays: number[] | null;
    due_time: string | null;
    items: FormItem[];
    requires_signature: boolean;
    active: boolean;
}

export type SubmissionState = "due" | "overdue" | "submitted" | "late" | "missed" | "available";

export interface TodayRow {
    template: FormTemplate;
    outlet: { id: number; name: string };
    due_at: string | null;
    state: SubmissionState;
    submission: { id: number; by: string | null; at: string | null; skipped_required: number } | null;
    reports_today: number | null;
}

export interface SubmissionBrief {
    id: number;
    template: { id: number; name: string; kind: "checklist" | "report" } | null;
    outlet: { id: number; name: string | null };
    user: { id: number; name: string } | null;
    for_date: string;
    status: "submitted" | "late" | "missed";
    due_at: string | null;
    submitted_at: string | null;
    skipped_required: number;
    flagged: boolean;
    has_signature: boolean;
    photo_keys: string[];
}

export interface Submission extends SubmissionBrief {
    answers: Record<string, unknown> | null;
    notes: string | null;
    items: FormItem[] | null;
}

export interface OutletOverview {
    outlet: { id: number; name: string };
    checklists: { template: { id: number; name: string; due_time: string | null }; state: SubmissionState; submission: SubmissionBrief | null }[];
    reports: SubmissionBrief[];
    summary: { done: number; late: number; missed: number; pending: number; skipped: number; reports: number };
}

export interface TemplateInput {
    name: string;
    kind: "checklist" | "report";
    outlet_id?: number | null;
    schedule?: "daily" | "none";
    weekdays?: number[] | null;
    due_time?: string | null;
    requires_signature?: boolean;
    active?: boolean;
    items: FormItem[];
}

export const formsApi = {
    today: (date?: string, outletId?: number) => get<{ date: string; data: TodayRow[]; can_manage: boolean }>("/v1/admin/forms/today", { params: { date, outlet_id: outletId } }),
    mine: () => get<{ data: SubmissionBrief[] }>("/v1/admin/forms/mine"),
    template: (id: number) => get<{ template: FormTemplate }>(`/v1/admin/forms/templates/${id}`),
    submit: async (input: { template_id: number; outlet_id: number; for_date?: string; answers: Record<string, unknown>; notes?: string; signature?: string | null; photos: Record<string, File> }) => {
        const fd = new FormData();
        fd.append("template_id", String(input.template_id));
        fd.append("outlet_id", String(input.outlet_id));
        if (input.for_date) fd.append("for_date", input.for_date);
        fd.append("answers", JSON.stringify(input.answers));
        if (input.notes) fd.append("notes", input.notes);
        if (input.signature) fd.append("signature", input.signature);
        for (const [k, f] of Object.entries(input.photos)) fd.append(`photo_${k}`, f);
        const r = await api.post<{ message: string; submission: Submission }>("/v1/admin/forms/submissions", fd);
        return r.data;
    },
    show: (id: number) => get<{ submission: Submission }>(`/v1/admin/forms/submissions/${id}`),
    fileLink: (id: number, key: string) => get<{ url: string }>(`/v1/admin/forms/submissions/${id}/file/${key}`),
    overview: (date?: string) => get<{ date: string; outlets: OutletOverview[] }>("/v1/admin/forms/overview", { params: { date } }),
    list: (params: { outlet_id?: number; from?: string; to?: string; status?: string; template_id?: number; page?: number }) => get<{ data: SubmissionBrief[]; has_more: boolean; page: number }>("/v1/admin/forms/submissions", { params }),
    templates: () => get<{ data: FormTemplate[] }>("/v1/admin/forms/templates"),
    createTemplate: (d: TemplateInput) => post<{ message: string; template: FormTemplate }>("/v1/admin/forms/templates", d),
    updateTemplate: (id: number, d: Partial<TemplateInput>) => put<{ message: string; template: FormTemplate }>(`/v1/admin/forms/templates/${id}`, d),
    retireTemplate: (id: number) => del<{ message: string }>(`/v1/admin/forms/templates/${id}`),
};

export const STATE_LABEL: Record<SubmissionState, string> = { due: "Due", overdue: "Overdue", submitted: "Done", late: "Done late", missed: "Missed", available: "" };
