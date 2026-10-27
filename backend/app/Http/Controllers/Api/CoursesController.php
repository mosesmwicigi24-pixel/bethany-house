<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseProgress;
use App\Models\CourseQuestion;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Courses (People C4 part 2): short training paths — lessons in order and
 * a quiz at the end. Staff read and take the quiz; updates.post writes.
 * Completion sits on the employee file; a required course waits on the
 * Me page until it is passed.
 */
class CoursesController extends Controller
{
    /** GET /courses — published and visible, with my progress (writers also see drafts). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Course::visibleTo($user)->withCount(['lessons', 'questions'])->orderByDesc('required')->orderBy('title');
        if (!$user->can('updates.post')) $q->published();
        $rows = $q->get();
        $prog = CourseProgress::where('user_id', $user->id)->whereIn('course_id', $rows->pluck('id'))->get()->keyBy('course_id');
        return response()->json(['data' => $rows->map(fn (Course $c) => $this->summary($c, $prog->get($c->id)))->values(), 'can_edit' => $user->can('updates.post'),
            'to_do' => $rows->filter(fn (Course $c) => $c->required && $c->published_at && !($prog->get($c->id)?->passed))->count()]);
    }

    /** GET /courses/{id} — lessons, the questions (answers withheld), my progress. */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $c = Course::visibleTo($user)->with(['lessons', 'questions'])->findOrFail($id);
        if (!$c->published_at && !$user->can('updates.post')) abort(404);
        $p = CourseProgress::firstOrCreate(['course_id' => $c->id, 'user_id' => $user->id], ['started_at' => now(), 'lessons_done' => []]);
        $out = $this->summary($c, $p) + [
            'description' => $c->description,
            'lessons' => $c->lessons->map(fn (CourseLesson $l) => ['id' => $l->id, 'position' => $l->position, 'title' => $l->title, 'body' => $l->body, 'done' => in_array($l->id, $p->lessons_done ?? [], true)])->values(),
            'questions' => $c->questions->map(fn (CourseQuestion $q) => ['id' => $q->id, 'position' => $q->position, 'question' => $q->question, 'options' => $q->options] + ($user->can('updates.post') ? ['answer' => $q->answer] : []))->values(),
        ];
        return response()->json(['course' => $out]);
    }

    /** POST /courses/{id}/lessons/{lesson}/done */
    public function lessonDone(Request $request, int $id, int $lesson): JsonResponse
    {
        $user = $request->user();
        $c = Course::visibleTo($user)->published()->findOrFail($id);
        $l = CourseLesson::where('course_id', $c->id)->findOrFail($lesson);
        $p = CourseProgress::firstOrCreate(['course_id' => $c->id, 'user_id' => $user->id], ['started_at' => now(), 'lessons_done' => []]);
        $done = array_values(array_unique(array_merge($p->lessons_done ?? [], [$l->id])));
        $p->update(['lessons_done' => $done]);
        return response()->json(['lessons_done' => $done, 'all_read' => count($done) >= $c->lessons()->count()]);
    }

    /** POST /courses/{id}/quiz {answers: {question_id: option index}} */
    public function quiz(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $c = Course::visibleTo($user)->published()->with(['lessons', 'questions'])->findOrFail($id);
        $v = $request->validate(['answers' => 'required|array', 'answers.*' => 'integer|min:0|max:9']);
        $p = CourseProgress::firstOrCreate(['course_id' => $c->id, 'user_id' => $user->id], ['started_at' => now(), 'lessons_done' => []]);
        if ($p->passed) return response()->json(['message' => 'You have already passed this course.'], 422);
        $unread = $c->lessons->pluck('id')->diff($p->lessons_done ?? []);
        if ($unread->isNotEmpty()) return response()->json(['message' => 'Read every lesson before the quiz.', 'unread' => $unread->values()], 422);
        if ($c->questions->isEmpty()) return response()->json(['message' => 'This course has no quiz yet.'], 422);
        $right = 0; $wrong = [];
        foreach ($c->questions as $q) {
            if ((int) ($v['answers'][$q->id] ?? -1) === $q->answer) $right++; else $wrong[] = $q->id;
        }
        $score = (int) round($right / $c->questions->count() * 100);
        $passed = $score >= (int) $c->pass_mark;
        $p->update(['attempts' => $p->attempts + 1, 'best_score' => max((int) $p->best_score, $score), 'passed' => $passed, 'completed_at' => $passed ? now() : null]);
        if ($passed) {
            ActivityLogService::log('course_completed', $c, ['user_id' => $user->id, 'score' => $score, 'attempts' => $p->attempts], "{$user->first_name} completed the course \"{$c->title}\" ({$score}%)");
        }
        return response()->json(['message' => $passed ? "Passed with {$score}%. Well done." : "{$score}% — you need {$c->pass_mark}%. Read it again and have another go.", 'score' => $score, 'passed' => $passed, 'wrong' => $wrong, 'attempts' => $p->attempts]);
    }

    // ── Authoring (updates.post) ──────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $v = $this->validateCourse($request);
        $c = Course::create(array_intersect_key($v, array_flip(['title', 'description', 'outlet_id', 'required', 'pass_mark'])) + ['published_at' => !empty($v['publish']) ? now() : null, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        $this->syncContent($c, $v);
        $this->announce($c);
        ActivityLogService::log('course_saved', $c, ['published' => (bool) $c->published_at, 'lessons' => count($v['lessons'] ?? []), 'questions' => count($v['questions'] ?? [])], "{$request->user()->first_name} wrote the course \"{$c->title}\"");
        return response()->json(['message' => $c->published_at ? 'Course published.' : 'Course saved as a draft.', 'course' => $this->summary($c->fresh()->loadCount(['lessons', 'questions']), null)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = Course::findOrFail($id);
        $v = $this->validateCourse($request, partial: true);
        if (array_key_exists('publish', $v)) { if ($v['publish'] && !$c->published_at) $v['published_at'] = now(); elseif (!$v['publish']) $v['published_at'] = null; }
        $c->fill(array_intersect_key($v, array_flip(['title', 'description', 'outlet_id', 'required', 'pass_mark', 'published_at'])) + ['updated_by' => $request->user()->id])->save();
        if (isset($v['lessons']) || isset($v['questions'])) $this->syncContent($c, $v);
        $this->announce($c);
        ActivityLogService::log('course_saved', $c, ['changed' => array_keys($v)], "{$request->user()->first_name} edited the course \"{$c->title}\"");
        return response()->json(['message' => 'Saved.', 'course' => $this->summary($c->fresh()->loadCount(['lessons', 'questions']), null)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $c = Course::findOrFail($id);
        $c->delete();
        ActivityLogService::log('course_removed', $c, [], "{$request->user()->first_name} removed the course \"{$c->title}\"");
        return response()->json(['message' => 'Removed. Completions already on people\'s files stay there.']);
    }

    /** GET /courses/{id}/progress (updates.post) — who has done what. */
    public function progress(Request $request, int $id): JsonResponse
    {
        $c = Course::findOrFail($id);
        $audience = $c->audience();
        $prog = CourseProgress::where('course_id', $c->id)->get()->keyBy('user_id');
        $rows = $audience->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'started_at' => $prog->get($u->id)?->started_at?->toIso8601String(),
            'lessons_done' => count($prog->get($u->id)?->lessons_done ?? []), 'attempts' => (int) ($prog->get($u->id)?->attempts ?? 0), 'best_score' => $prog->get($u->id)?->best_score,
            'passed' => (bool) ($prog->get($u->id)?->passed), 'completed_at' => $prog->get($u->id)?->completed_at?->toIso8601String()])->sortBy('name')->values();
        return response()->json(['data' => $rows, 'passed' => $rows->where('passed', true)->count(), 'audience' => $rows->count()]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function validateCourse(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $v = $request->validate([
            'title' => "{$req}|string|max:140", 'description' => 'nullable|string|max:5000', 'outlet_id' => 'nullable|integer|exists:outlets,id', 'required' => 'sometimes|boolean',
            'pass_mark' => 'sometimes|integer|between:1,100', 'publish' => 'sometimes|boolean',
            'lessons' => "{$req}|array|min:1|max:30", 'lessons.*.title' => 'required|string|max:140', 'lessons.*.body' => 'required|string|max:20000',
            'questions' => 'sometimes|array|max:40', 'questions.*.question' => 'required|string|max:500', 'questions.*.options' => 'required|array|min:2|max:6', 'questions.*.options.*' => 'required|string|max:200',
            'questions.*.answer' => 'required|integer|min:0',
        ]);
        foreach ($v['questions'] ?? [] as $i => $q) {
            if ($q['answer'] >= count($q['options'])) abort(422, 'Question ' . ($i + 1) . ': the answer must be one of its options.');
        }
        return $v;
    }

    /** Lessons and questions are replaced as a whole; progress rows keep lesson ids that still exist. */
    private function syncContent(Course $c, array $v): void
    {
        if (isset($v['lessons'])) {
            $keep = [];
            $existing = $c->lessons()->get()->keyBy('position');
            foreach (array_values($v['lessons']) as $i => $l) {
                $row = $existing->get($i + 1);
                if ($row) { $row->update(['title' => $l['title'], 'body' => $l['body']]); }
                else { $row = CourseLesson::create(['course_id' => $c->id, 'position' => $i + 1, 'title' => $l['title'], 'body' => $l['body']]); }
                $keep[] = $row->id;
            }
            $c->lessons()->whereNotIn('id', $keep)->delete();
        }
        if (isset($v['questions'])) {
            $c->questions()->delete();
            foreach (array_values($v['questions']) as $i => $q) CourseQuestion::create(['course_id' => $c->id, 'position' => $i + 1, 'question' => $q['question'], 'options' => array_values($q['options']), 'answer' => (int) $q['answer']]);
        }
    }

    /** A published course is announced once; a required one says so. */
    private function announce(Course $c): void
    {
        if (!$c->published_at || $c->announced_at) return;
        $c->forceFill(['announced_at' => now()])->save();
        $who = $c->audience()->reject(fn (User $u) => $u->id === $c->created_by);   // the author knows
        if ($who->isEmpty()) return;
        NotificationService::deliver($who, new InAppNotification(title: ($c->required ? 'Training to complete: ' : 'New training: ') . $c->title, body: $c->description ? \Illuminate\Support\Str::limit($c->description, 140) : 'Open it under Me › Training.', actionUrl: "/courses/{$c->id}", icon: 'star', data: ['course_id' => $c->id, 'required' => $c->required]));
    }

    private function summary(Course $c, ?CourseProgress $p): array
    {
        return ['id' => $c->id, 'title' => $c->title, 'description' => $c->description, 'outlet' => $c->outlet_id ? ['id' => $c->outlet_id, 'name' => $c->outlet?->name] : null, 'required' => $c->required, 'pass_mark' => $c->pass_mark,
            'published_at' => $c->published_at?->toIso8601String(), 'lessons_count' => (int) ($c->lessons_count ?? $c->lessons()->count()), 'questions_count' => (int) ($c->questions_count ?? $c->questions()->count()),
            'me' => ['started_at' => $p?->started_at?->toIso8601String(), 'lessons_done' => count($p?->lessons_done ?? []), 'attempts' => (int) ($p?->attempts ?? 0), 'best_score' => $p?->best_score, 'passed' => (bool) ($p?->passed), 'completed_at' => $p?->completed_at?->toIso8601String()]];
    }
}
