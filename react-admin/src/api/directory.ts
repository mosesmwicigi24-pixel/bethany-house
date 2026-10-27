import { get, put } from "./client";

/** The staff directory (People C5). */
export interface Colleague {
    id: number;
    name: string;
    initials: string;
    avatar_url: string | null;
    job_title: string | null;
    department: string | null;
    outlets: string[];
    phone: string | null;
    email: string | null;
    birthday: string | null;
    since: string | null;
    is_me: boolean;
}
export interface DirectoryMe { show_phone: boolean; show_email: boolean; show_birthday: boolean }

export const directoryApi = {
    list: (q?: string, outletId?: number) => get<{ data: Colleague[]; me: DirectoryMe; departments: string[] }>("/v1/admin/directory", { params: { q: q || undefined, outlet_id: outletId } }),
    saveMine: (d: Partial<DirectoryMe>) => put<{ message: string; me: DirectoryMe }>("/v1/admin/me/directory", d),
};
