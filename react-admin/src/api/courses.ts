import { get, post, put, del } from "./client";

/** Courses (People C4 part 2). */
export interface CourseProgressMe { started_at: string | null; lessons_done: number; attempts: number; best_score: number | null; passed: boolean; completed_at: string | null }
export interface CourseSummary {
    id: number;
    title: string;
    description: string | null;
    outlet: { id: number; name: string | null } | null;
    required: boolean;
    pass_mark: number;
    published_at: string | null;
    lessons_count: number;
    questions_count: number;
    me: CourseProgressMe;
}
export interface CourseLesson { id: number; position: number; title: string; body: string; done: boolean }
export interface CourseQuestion { id: number; position: number; question: string; options: string[]; answer?: number }
export interface CourseDetail extends CourseSummary { lessons: CourseLesson[]; questions: CourseQuestion[] }
export interface CourseInput {
    title: string;
    description?: string | null;
    outlet_id?: number | null;
    required?: boolean;
    pass_mark?: number;
    publish?: boolean;
    lessons: { title: string; body: string }[];
    questions?: { question: string; options: string[]; answer: number }[];
}
export interface ProgressRow { id: number; name: string; started_at: string | null; lessons_done: number; attempts: number; best_score: number | null; passed: boolean; completed_at: string | null }

export const coursesApi = {
    list: () => get<{ data: CourseSummary[]; can_edit: boolean; to_do: number }>("/v1/admin/courses"),
    show: (id: number) => get<{ course: CourseDetail }>(`/v1/admin/courses/${id}`),
    lessonDone: (id: number, lesson: number) => post<{ lessons_done: number[]; all_read: boolean }>(`/v1/admin/courses/${id}/lessons/${lesson}/done`, {}),
    quiz: (id: number, answers: Record<number, number>) => post<{ message: string; score: number; passed: boolean; wrong: number[]; attempts: number }>(`/v1/admin/courses/${id}/quiz`, { answers }),
    create: (d: CourseInput) => post<{ message: string; course: CourseSummary }>("/v1/admin/courses", d),
    update: (id: number, d: Partial<CourseInput>) => put<{ message: string; course: CourseSummary }>(`/v1/admin/courses/${id}`, d),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/courses/${id}`),
    progress: (id: number) => get<{ data: ProgressRow[]; passed: number; audience: number }>(`/v1/admin/courses/${id}/progress`),
};
