import { get, post, patch, del } from "./client";

/** To-dos (People C5). */
export interface Todo {
    id: number;
    title: string;
    details: string | null;
    status: "open" | "done" | "cancelled";
    due_at: string | null;
    overdue: boolean;
    done_at: string | null;
    done_note: string | null;
    created_at: string | null;
    user: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
    outlet: { id: number; name: string } | null;
    mine: boolean;
    gave: boolean;
}
export type TodoScope = "mine" | "given" | "team";

export const todosApi = {
    list: (scope: TodoScope = "mine", status: "open" | "done" | "all" = "open") => get<{ data: Todo[]; open: number; overdue: number; can_give: boolean }>("/v1/admin/todos", { params: { scope, status } }),
    people: () => get<{ data: { id: number; name: string }[] }>("/v1/admin/todos/people"),
    create: (d: { title: string; details?: string | null; due_at?: string | null; user_id?: number | null }) => post<{ message: string; todo: Todo }>("/v1/admin/todos", d),
    update: (id: number, d: { status?: "done" | "open" | "cancelled"; done_note?: string | null; title?: string; details?: string | null; due_at?: string | null }) => patch<{ message: string; todo: Todo }>(`/v1/admin/todos/${id}`, d),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/todos/${id}`),
};
