import { get, post, put, del } from "./client";
import { fetchSignedFile } from "@/api/signedFiles";
import type { PaginatedResponse } from "@/types";

/** People (Bethany People PR 2): employee files, staff documents, public holidays. */

export type EmploymentType = "permanent" | "contract" | "casual" | "intern";
export type PayMethod = "mpesa" | "bank" | "cash";
export type DocumentKind = "national_id" | "kra_pin" | "nssf" | "shif" | "contract" | "certificate" | "passport_photo" | "medical" | "signed" | "other";

export const DOCUMENT_KINDS: { value: DocumentKind; label: string }[] = [
    { value: "national_id", label: "National ID" },
    { value: "kra_pin", label: "KRA PIN certificate" },
    { value: "nssf", label: "NSSF card" },
    { value: "shif", label: "SHIF card" },
    { value: "contract", label: "Contract" },
    { value: "certificate", label: "Certificate" },
    { value: "passport_photo", label: "Passport photo" },
    { value: "medical", label: "Medical" },
    { value: "signed", label: "Signed record" },
    { value: "other", label: "Other" },
];

export interface PersonRow {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    status: string;
    avatar_url: string | null;
    job_title: string | null;
    department: string | null;
    employment_type: EmploymentType | null;
    hire_date: string | null;
    end_date: string | null;
    outlets: string[];
    documents_count: number;
    expiring_count: number;
}

export interface StaffDocument {
    id: number;
    kind: DocumentKind;
    title: string;
    original_name: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    expires_at: string | null;
    expired: boolean;
    uploaded_at: string | null;
    user?: { id: number; name: string };
}

export interface EmployeeProfileFields {
    job_title: string | null;
    department: string | null;
    employment_type: EmploymentType;
    hire_date: string | null;
    end_date: string | null;
    date_of_birth: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    /** The second contact — who to try when the first does not answer. */
    emergency_contact_2_name: string | null;
    emergency_contact_2_phone: string | null;
    emergency_contact_2_relationship: string | null;
    pay_method: PayMethod | null;
    pay_group_id: number | null;
    bank_name: string | null;
    bank_branch: string | null;
    bank_account_name: string | null;
    notes: string | null;
    updated_at: string | null;
}

export type EmergencyContactKey =
    | "emergency_contact_name" | "emergency_contact_phone" | "emergency_contact_relationship"
    | "emergency_contact_2_name" | "emergency_contact_2_phone" | "emergency_contact_2_relationship";

/** Country dialling codes for phone entry (the public reference list the storefront reads). */
export interface DialCountry { code: string; name: string; phone_code: string | null; flag: string | null }

export type SensitiveKey = "national_id_no" | "kra_pin" | "nssf_no" | "shif_no" | "mpesa_phone" | "bank_account_no";
export type Sensitive = Record<SensitiveKey, string | null>;

export interface PersonFile {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    email: string | null;
    phone: string | null;
    status: string;
    avatar_url: string | null;
    outlets: { id: number; name: string }[];
    reports_to: { id: number; name: string } | null;
    roles: string[];
    profile: EmployeeProfileFields;
    masked: Sensitive;
    /** edit_contact: the file keeper, or the person themselves (saved through My file). */
    can: { edit: boolean; view_sensitive: boolean; edit_sensitive: boolean; edit_contact?: boolean };
    documents: StaffDocument[];
    recognitions: Recognition[];
    recognition_points: number;
    courses: FileCourse[];
    signing: FileSigning[];
}

/** Training on the file (People C4 part 2). */
export interface FileCourse { id: number; title: string; required: boolean; passed: boolean; best_score: number | null; attempts: number; completed_at: string | null }
export interface FileSigning { id: number; kind: "document" | "article" | "text"; title: string; status: "pending" | "signed" | "declined" | "cancelled"; due_at: string | null; signed_at: string | null; note: string | null; requested_by: string | null; signed_document_id: number | null }

/** A shout-out on the Updates feed, as shown on the file (People C3). */
export interface Recognition { id: number; body: string | null; points: number | null; at: string | null; by: string | null }

export interface Holiday {
    id: number;
    date: string;
    name: string;
    country: string;
    is_confirmed: boolean;
    notes: string | null;
}

export const peopleApi = {
    list: (params?: { q?: string; department?: string; page?: string; per_page?: string }) =>
        get<PaginatedResponse<PersonRow> & { departments: string[] }>("/v1/admin/people", { params }),

    show: (id: number) => get<{ person: PersonFile }>(`/v1/admin/people/${id}`),

    update: (id: number, data: Partial<Omit<EmployeeProfileFields, "updated_at">>) =>
        put<{ message: string; person: PersonFile }>(`/v1/admin/people/${id}`, data),

    /** Full identifiers and pay details; the server asks for a step-up first. */
    sensitive: (id: number) => get<{ sensitive: Sensitive }>(`/v1/admin/people/${id}/sensitive`),

    updateSensitive: (id: number, data: Partial<Sensitive>) =>
        put<{ message: string; sensitive: Sensitive }>(`/v1/admin/people/${id}/sensitive`, data),

    addDocument: (id: number, file: File, kind: DocumentKind, title: string, expiresAt?: string) => {
        const form = new FormData();
        form.append("kind", kind);
        form.append("title", title);
        if (expiresAt) form.append("expires_at", expiresAt);
        form.append("file", file, file.name);
        return post<{ message: string; document: StaffDocument }>(`/v1/admin/people/${id}/documents`, form);
    },

    removeDocument: (id: number, docId: number) => del<{ message: string }>(`/v1/admin/people/${id}/documents/${docId}`),

    fetchDocumentBlob: async (id: number, docId: number) => {
        const file = await fetchSignedFile(`/api/v1/admin/people/${id}/documents/${docId}`);
        return { url: URL.createObjectURL(file.blob), mimeType: file.contentType };
    },

    expiring: (days = 60) => get<{ data: StaffDocument[]; days: number }>("/v1/admin/people/documents/expiring", { params: { days: String(days) } }),

    // ── My own file ──
    myFile: () => get<{ person: PersonFile }>("/v1/admin/me/file"),
    updateMyFile: (data: Partial<Pick<EmployeeProfileFields, EmergencyContactKey | "date_of_birth">>) =>
        put<{ message: string; person: PersonFile }>("/v1/admin/me/file", data),
    mySensitive: () => get<{ sensitive: Sensitive }>("/v1/admin/me/file/sensitive"),
    fetchMyDocumentBlob: async (docId: number) => {
        const file = await fetchSignedFile(`/api/v1/admin/me/file/documents/${docId}`);
        return { url: URL.createObjectURL(file.blob), mimeType: file.contentType };
    },

    dialCountries: () => get<{ data: DialCountry[] }>("/v1/countries"),

    // ── Public holidays ──
    holidays: (year: number) => get<{ data: Holiday[]; year: number }>("/v1/admin/holidays", { params: { year: String(year) } }),
    addHoliday: (data: { date: string; name: string; is_confirmed?: boolean; notes?: string | null }) =>
        post<{ message: string; holiday: Holiday }>("/v1/admin/holidays", data),
    updateHoliday: (id: number, data: Partial<{ date: string; name: string; is_confirmed: boolean; notes: string | null }>) =>
        put<{ message: string; holiday: Holiday }>(`/v1/admin/holidays/${id}`, data),
    removeHoliday: (id: number) => del<{ message: string }>(`/v1/admin/holidays/${id}`),
};
