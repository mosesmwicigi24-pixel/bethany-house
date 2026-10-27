import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { coursesApi, type CourseDetail, type CourseInput, type CourseSummary } from "@/api/courses";
import { outletsApi } from "@/api/setup";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * /courses — training (People C4 part 2): the list with my progress, a
 * course read lesson by lesson, the quiz at the end. updates.post writes
 * courses and sees who has done what.
 */
export default function CoursesPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const q = useQuery({ queryKey: ["courses"], queryFn: () => coursesApi.list() });
    const [editing, setEditing] = useState<CourseSummary | "new" | null>(null);
    const rows = q.data?.data ?? [];
    return (
        <div className="mx-auto w-full max-w-2xl px-4 py-4 sm:py-8 space-y-4 animate-fade-in">
            <div className="flex items-end justify-between gap-3">
                <div><h1 className="page-title">Training</h1><p className="page-subtitle">{q.data ? (q.data.to_do ? `${q.data.to_do} course${q.data.to_do > 1 ? "s" : ""} to complete.` : "Short courses with a quiz at the end.") : ""}</p></div>
                {q.data?.can_edit && <button onClick={() => setEditing("new")} className="btn-primary btn-sm">New course</button>}
            </div>
            {q.isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : rows.length === 0 ? <p className="text-sm text-surface-500 py-8 text-center">No courses yet.</p> : (
                <ul className="space-y-2">
                    {rows.map(c => (
                        <li key={c.id}><button onClick={() => navigate(`/courses/${c.id}`)} className={clsx("w-full text-left rounded-2xl border p-4 flex items-center gap-3", c.me.passed ? "bg-success-light/40 border-success/30" : c.required ? "bg-white border-danger/30" : "bg-white border-line")}>
                            <span className={clsx("w-10 h-10 rounded-full flex items-center justify-center text-lg shrink-0", c.me.passed ? "bg-success text-white" : "bg-surface-100")}>{c.me.passed ? "✓" : "🎓"}</span>
                            <span className="min-w-0 flex-1">
                                <span className="block font-semibold text-surface-900">{c.title}{!c.published_at && <span className="ml-2 text-2xs uppercase text-surface-400">draft</span>}</span>
                                <span className="block text-xs text-surface-500">{c.lessons_count} lesson{c.lessons_count === 1 ? "" : "s"} · {c.questions_count} question{c.questions_count === 1 ? "" : "s"} · pass {c.pass_mark}%{c.me.passed ? ` · passed ${c.me.best_score}%` : c.me.lessons_done ? ` · ${c.me.lessons_done}/${c.lessons_count} read` : ""}</span>
                            </span>
                            {c.required && !c.me.passed && <span className="text-2xs font-bold uppercase rounded-full px-2 py-0.5 bg-danger text-white">Required</span>}
                        </button></li>
                    ))}
                </ul>
            )}
            {id && <CourseModal id={Number(id)} canEdit={!!q.data?.can_edit} onClose={() => navigate("/courses")} onEdit={c => setEditing(c)} />}
            {editing && <EditModal c={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
        </div>
    );
}

function CourseModal({ id, canEdit, onClose, onEdit }: { id: number; canEdit: boolean; onClose: () => void; onEdit: (c: CourseSummary) => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["course", id], queryFn: () => coursesApi.show(id) });
    const c = q.data?.course;
    const [step, setStep] = useState(0);              // lesson index; lessons.length = the quiz
    const [answers, setAnswers] = useState<Record<number, number>>({});
    const [result, setResult] = useState<{ score: number; passed: boolean; wrong: number[]; message: string } | null>(null);
    const [showProgress, setShowProgress] = useState(false);
    const progress = useQuery({ queryKey: ["course-progress", id], queryFn: () => coursesApi.progress(id), enabled: canEdit && showProgress });
    const invalidate = () => { qc.invalidateQueries({ queryKey: ["course", id] }); qc.invalidateQueries({ queryKey: ["courses"] }); };
    const done = useMutation({ mutationFn: (lesson: number) => coursesApi.lessonDone(id, lesson), onSuccess: invalidate });
    const quiz = useMutation({ mutationFn: () => coursesApi.quiz(id, answers), onSuccess: r => { setResult(r); invalidate(); }, onError: (e: ApiError) => toast.error(e.message) });
    const remove = useMutation({ mutationFn: () => coursesApi.remove(id), onSuccess: r => { toast.success(r.message); invalidate(); onClose(); } });
    if (!c) return <Modal open onClose={onClose} title="…"><div className="flex items-center justify-center h-40">{q.error ? <p className="text-sm text-danger">{(q.error as ApiError).message}</p> : <Spinner size="lg" />}</div></Modal>;
    const lesson = c.lessons[step];
    const allRead = c.lessons.every(l => l.done);
    const atQuiz = step >= c.lessons.length;
    return (
        <Modal open onClose={onClose} size="lg" title={c.title}>
            <div className="space-y-3 text-sm">
                <div className="flex flex-wrap items-center gap-1 text-xs">
                    {c.lessons.map((l, i) => <button key={l.id} onClick={() => setStep(i)} className={clsx("px-2 py-0.5 rounded-full border", i === step && !atQuiz ? "bg-brand-600 text-white border-brand-600" : l.done ? "bg-success-light text-success border-success/30" : "bg-white border-line text-surface-600")}>{i + 1}</button>)}
                    {c.questions.length > 0 && <button onClick={() => setStep(c.lessons.length)} className={clsx("px-2 py-0.5 rounded-full border", atQuiz ? "bg-brand-600 text-white border-brand-600" : c.me.passed ? "bg-success-light text-success border-success/30" : "bg-white border-line text-surface-600")}>Quiz</button>}
                    {c.me.passed && <span className="ml-auto text-success font-semibold">Passed · {c.me.best_score}%</span>}
                </div>
                {!atQuiz && lesson && (
                    <div className="space-y-3">
                        <h3 className="font-semibold text-surface-900">{lesson.title}</h3>
                        <div className="whitespace-pre-line text-surface-800 leading-relaxed">{lesson.body}</div>
                        <div className="flex justify-end gap-2">
                            {!lesson.done && <button onClick={() => done.mutate(lesson.id)} disabled={done.isPending} className="btn-secondary btn-sm">Mark as read</button>}
                            <button onClick={() => { if (!lesson.done) done.mutate(lesson.id); setStep(s => s + 1); }} className="btn-primary btn-sm">{step + 1 < c.lessons.length ? "Next lesson" : c.questions.length ? "To the quiz" : "Finish"}</button>
                        </div>
                    </div>
                )}
                {atQuiz && c.questions.length > 0 && (
                    <div className="space-y-3">
                        {!allRead && <p className="rounded-xl bg-warning-light text-warning px-3 py-2 text-xs">Read every lesson first ({c.lessons.filter(l => l.done).length} of {c.lessons.length} read).</p>}
                        {c.me.passed ? <p className="rounded-xl bg-success-light text-success px-3 py-2 text-xs font-medium">You passed this course with {c.me.best_score}% on {c.me.completed_at ? new Date(c.me.completed_at).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : ""}.</p> : (
                            <>
                                {c.questions.map((qq, i) => (
                                    <div key={qq.id} className={clsx("rounded-xl border p-3", result?.wrong.includes(qq.id) ? "border-danger/40 bg-danger/5" : "border-line")}>
                                        <p className="font-medium text-surface-900 mb-2">{i + 1}. {qq.question}</p>
                                        <div className="space-y-1">{qq.options.map((o, oi) => <label key={oi} className="flex items-center gap-2"><input type="radio" name={`q${qq.id}`} checked={answers[qq.id] === oi} onChange={() => setAnswers(a => ({ ...a, [qq.id]: oi }))} /><span>{o}{canEdit && qq.answer === oi ? " ✓" : ""}</span></label>)}</div>
                                    </div>
                                ))}
                                {result && <p className={clsx("rounded-xl px-3 py-2 text-xs font-medium", result.passed ? "bg-success-light text-success" : "bg-danger/10 text-danger")}>{result.message}</p>}
                                <div className="flex justify-end"><button onClick={() => quiz.mutate()} disabled={!allRead || quiz.isPending || Object.keys(answers).length < c.questions.length} className="btn-primary btn-sm">{quiz.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Check my answers</button></div>
                            </>
                        )}
                    </div>
                )}
                {canEdit && (
                    <div className="pt-2 border-t border-line space-y-2">
                        <div className="flex justify-between gap-2">
                            <button onClick={() => setShowProgress(s => !s)} className="btn-ghost btn-sm text-xs">{showProgress ? "Hide" : "Who has done it"}</button>
                            <span className="flex gap-1"><button onClick={() => { if (confirm("Remove this course? Completions on files stay.")) remove.mutate(); }} className="btn-ghost btn-sm text-xs text-danger">Remove</button><button onClick={() => onEdit(c)} className="btn-secondary btn-sm">Edit</button></span>
                        </div>
                        {showProgress && (progress.isLoading ? <Spinner size="sm" /> : progress.data && (
                            <div className="text-xs"><p className="text-surface-500 mb-1">{progress.data.passed} of {progress.data.audience} passed</p>
                                <ul className="divide-y divide-line rounded-xl border border-line">{progress.data.data.map(r => <li key={r.id} className="flex items-center justify-between px-3 py-1.5"><span>{r.name}</span><span className={r.passed ? "text-success font-semibold" : "text-surface-500"}>{r.passed ? `passed ${r.best_score}%` : r.started_at ? `${r.lessons_done} read · ${r.attempts} attempt${r.attempts === 1 ? "" : "s"}` : "not started"}</span></li>)}</ul></div>
                        ))}
                    </div>
                )}
            </div>
        </Modal>
    );
}

function EditModal({ c, onClose }: { c: CourseSummary | null; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const outlets = useQuery({ queryKey: ["outlets-for-courses"], queryFn: () => outletsApi.list() });
    const full = useQuery({ queryKey: ["course", c?.id], queryFn: () => coursesApi.show(c!.id), enabled: !!c });
    const [d, setD] = useState<CourseInput | null>(c ? null : { title: "", description: "", outlet_id: null, required: false, pass_mark: 80, publish: true, lessons: [{ title: "", body: "" }], questions: [] });
    if (c && !d && full.data) {
        const cd: CourseDetail = full.data.course;
        setD({ title: cd.title, description: cd.description ?? "", outlet_id: cd.outlet?.id ?? null, required: cd.required, pass_mark: cd.pass_mark, publish: !!cd.published_at, lessons: cd.lessons.map(l => ({ title: l.title, body: l.body })), questions: cd.questions.map(qq => ({ question: qq.question, options: qq.options, answer: qq.answer ?? 0 })) });
    }
    const save = useMutation({ mutationFn: () => (c ? coursesApi.update(c.id, d!) : coursesApi.create(d!)), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["courses"] }); if (c) qc.invalidateQueries({ queryKey: ["course", c.id] }); onClose(); }, onError: (e: ApiError) => toast.error(e.message) });
    if (!d) return <Modal open onClose={onClose} title="…"><Spinner /></Modal>;
    const ok = d.title.trim() && d.lessons.length > 0 && d.lessons.every(l => l.title.trim() && l.body.trim()) && (d.questions ?? []).every(qq => qq.question.trim() && qq.options.length >= 2 && qq.options.every(o => o.trim()));
    return (
        <Modal open onClose={onClose} size="lg" title={c ? "Edit course" : "New course"} footer={<div className="flex justify-end gap-2"><button onClick={onClose} className="btn-secondary btn-sm">Cancel</button><button onClick={() => save.mutate()} disabled={!ok || save.isPending} className="btn-primary btn-sm">{d.publish ? "Publish" : "Save draft"}</button></div>}>
            <div className="space-y-3 text-sm">
                <label className="block">Title<input className="input w-full" value={d.title} onChange={e => setD({ ...d, title: e.target.value })} maxLength={140} /></label>
                <label className="block">What it is for<textarea rows={2} className="input w-full" value={d.description ?? ""} onChange={e => setD({ ...d, description: e.target.value })} maxLength={5000} /></label>
                <div className="grid grid-cols-2 gap-2">
                    <label className="block">For<select className="input w-full" value={d.outlet_id ?? ""} onChange={e => setD({ ...d, outlet_id: e.target.value ? Number(e.target.value) : null })}><option value="">Everyone</option>{(outlets.data?.data ?? []).map(o => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
                    <label className="block">Pass mark %<input type="number" min={1} max={100} className="input w-full" value={d.pass_mark ?? 80} onChange={e => setD({ ...d, pass_mark: Number(e.target.value) })} /></label>
                </div>
                <div className="flex flex-wrap gap-4 text-xs">
                    <label className="inline-flex items-center gap-2"><input type="checkbox" checked={!!d.required} onChange={e => setD({ ...d, required: e.target.checked })} />Required (waits on the Me page until passed)</label>
                    <label className="inline-flex items-center gap-2"><input type="checkbox" checked={!!d.publish} onChange={e => setD({ ...d, publish: e.target.checked })} />Published</label>
                </div>
                <div className="space-y-2">
                    <p className="font-medium text-surface-900">Lessons</p>
                    {d.lessons.map((l, i) => (
                        <div key={i} className="rounded-xl border border-line p-2 space-y-1">
                            <div className="flex gap-2"><input className="input flex-1 px-2 py-1" placeholder={`Lesson ${i + 1} title`} value={l.title} onChange={e => setD({ ...d, lessons: d.lessons.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)) })} maxLength={140} /><button type="button" onClick={() => setD({ ...d, lessons: d.lessons.filter((_, j) => j !== i) })} className="text-surface-400 hover:text-danger" aria-label="Remove lesson">✕</button></div>
                            <textarea rows={4} className="input w-full text-xs" placeholder="The lesson text" value={l.body} onChange={e => setD({ ...d, lessons: d.lessons.map((x, j) => (j === i ? { ...x, body: e.target.value } : x)) })} />
                        </div>
                    ))}
                    <button type="button" onClick={() => setD({ ...d, lessons: [...d.lessons, { title: "", body: "" }] })} className="btn-secondary btn-sm">+ Lesson</button>
                </div>
                <div className="space-y-2">
                    <p className="font-medium text-surface-900">Quiz</p>
                    {(d.questions ?? []).map((qq, i) => (
                        <div key={i} className="rounded-xl border border-line p-2 space-y-1">
                            <div className="flex gap-2"><input className="input flex-1 px-2 py-1" placeholder={`Question ${i + 1}`} value={qq.question} onChange={e => setD({ ...d, questions: d.questions!.map((x, j) => (j === i ? { ...x, question: e.target.value } : x)) })} maxLength={500} /><button type="button" onClick={() => setD({ ...d, questions: d.questions!.filter((_, j) => j !== i) })} className="text-surface-400 hover:text-danger" aria-label="Remove question">✕</button></div>
                            {qq.options.map((o, oi) => (
                                <div key={oi} className="flex items-center gap-2 text-xs"><input type="radio" name={`ans${i}`} checked={qq.answer === oi} onChange={() => setD({ ...d, questions: d.questions!.map((x, j) => (j === i ? { ...x, answer: oi } : x)) })} title="The right answer" /><input className="input flex-1 px-2 py-1" placeholder={`Option ${oi + 1}`} value={o} onChange={e => setD({ ...d, questions: d.questions!.map((x, j) => (j === i ? { ...x, options: x.options.map((y, k) => (k === oi ? e.target.value : y)) } : x)) })} maxLength={200} />{qq.options.length > 2 && <button type="button" onClick={() => setD({ ...d, questions: d.questions!.map((x, j) => (j === i ? { ...x, options: x.options.filter((_, k) => k !== oi), answer: Math.min(x.answer, x.options.length - 2) } : x)) })} className="text-surface-400 hover:text-danger">✕</button>}</div>
                            ))}
                            {qq.options.length < 6 && <button type="button" onClick={() => setD({ ...d, questions: d.questions!.map((x, j) => (j === i ? { ...x, options: [...x.options, ""] } : x)) })} className="text-xs text-brand-600">+ option</button>}
                        </div>
                    ))}
                    <button type="button" onClick={() => setD({ ...d, questions: [...(d.questions ?? []), { question: "", options: ["", ""], answer: 0 }] })} className="btn-secondary btn-sm">+ Question</button>
                    <p className="text-2xs text-surface-400">Tick the radio beside the right answer.</p>
                </div>
            </div>
        </Modal>
    );
}
