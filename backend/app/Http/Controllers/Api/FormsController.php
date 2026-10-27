<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Outlet;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\Automations;
use App\Support\SignedFiles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Checklists and forms (People C4). Staff: what is due today at my outlet,
 * fill a checklist or a report (photos, a signature). forms.manage: the
 * templates, every submission in own outlets, and the day's overview —
 * done, late, missed, and what was skipped.
 */
class FormsController extends Controller
{
    // ── Staff ─────────────────────────────────────────────────────────────

    /** GET /forms/today?outlet_id=&date= — checklists due and reports available at my outlet(s). */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['outlet_id' => 'nullable|integer|exists:outlets,id', 'date' => 'nullable|date']);
        $date = !empty($v['date']) ? Carbon::parse($v['date'])->startOfDay() : today();
        $outletIds = $this->outletsOf($user, $v['outlet_id'] ?? null);
        $outlets = Outlet::whereIn('id', $outletIds)->orderBy('name')->get(['id', 'name']);
        $templates = FormTemplate::where('active', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds))->orderBy('kind')->orderBy('due_time')->get();
        $subs = FormSubmission::with('user:id,first_name,last_name')->whereIn('outlet_id', $outletIds)->where('for_date', $date->toDateString())->get();
        $rows = [];
        foreach ($outlets as $o) {
            foreach ($templates as $t) {
                if ($t->outlet_id && $t->outlet_id !== $o->id) continue;
                if ($t->kind === 'checklist' && !$t->dueOn($date)) continue;
                $done = $subs->first(fn ($s) => $s->form_template_id === $t->id && $s->outlet_id === $o->id && $s->status !== 'missed');
                $missed = $subs->first(fn ($s) => $s->form_template_id === $t->id && $s->outlet_id === $o->id && $s->status === 'missed');
                $rows[] = [
                    'template' => $this->template($t), 'outlet' => ['id' => $o->id, 'name' => $o->name],
                    'due_at' => $t->dueAt($date)?->toIso8601String(),
                    'state' => $t->kind === 'report' ? 'available' : ($done ? $done->status : ($missed ? 'missed' : ($t->dueAt($date) && now()->gt($t->dueAt($date)) ? 'overdue' : 'due'))),
                    'submission' => $done ? ['id' => $done->id, 'by' => $done->user ? trim("{$done->user->first_name} {$done->user->last_name}") : null, 'at' => $done->submitted_at?->toIso8601String(), 'skipped_required' => $done->skipped_required] : null,
                    'reports_today' => $t->kind === 'report' ? $subs->where('form_template_id', $t->id)->where('outlet_id', $o->id)->count() : null,
                ];
            }
        }
        return response()->json(['date' => $date->toDateString(), 'data' => $rows, 'can_manage' => $user->can('forms.manage')]);
    }

    /** GET /forms/templates/{id} — one form to fill. */
    public function showTemplate(Request $request, int $id): JsonResponse
    {
        $t = FormTemplate::findOrFail($id);
        $user = $request->user();
        if ($t->outlet_id && !in_array($t->outlet_id, $this->outletsOf($user), true)) abort(403);
        return response()->json(['template' => $this->template($t)]);
    }

    /** POST /forms/submissions — multipart: template_id, outlet_id, for_date?, answers (JSON), notes?, signature (data URL)?, photo_<key> files. */
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'template_id' => 'required|integer|exists:form_templates,id',
            'outlet_id'   => 'required|integer|exists:outlets,id',
            'for_date'    => 'nullable|date',
            'answers'     => 'required|string|max:20000',
            'notes'       => 'nullable|string|max:2000',
            'signature'   => 'nullable|string|max:300000',
        ]);
        $t = FormTemplate::findOrFail($v['template_id']);
        $outletId = (int) $v['outlet_id'];
        abort_unless($t->active, 422, 'This form is no longer in use.');
        abort_unless(in_array($outletId, $this->outletsOf($user), true), 403, 'You are not on that outlet\'s team.');
        if ($t->outlet_id && $t->outlet_id !== $outletId) abort(422, 'This form belongs to another outlet.');
        $date = !empty($v['for_date']) ? Carbon::parse($v['for_date'])->startOfDay() : today();
        abort_if($date->gt(today()), 422, 'That day has not come yet.');
        $answers = json_decode($v['answers'], true);
        abort_unless(is_array($answers), 422, 'The answers are not readable.');

        $existing = null;
        if ($t->kind === 'checklist') {
            $existing = FormSubmission::where('form_template_id', $t->id)->where('outlet_id', $outletId)->where('for_date', $date->toDateString())->first();
            if ($existing && $existing->status !== 'missed') {
                return response()->json(['message' => "Already done today" . ($existing->user ? " by {$existing->user->first_name}" : '') . '.'], 422);
            }
        }
        if ($t->requires_signature && empty($v['signature'])) {
            return response()->json(['message' => 'Sign at the bottom to send this.', 'errors' => ['signature' => ['A signature is needed.']]], 422);
        }

        $clean = []; $skipped = 0; $photos = [];
        foreach ($t->items as $item) {
            $k = $item['key']; $val = $answers[$k] ?? null;
            $has = match ($item['type']) {
                'check'  => $val === true || $val === 1 || $val === '1' || $val === 'true',
                'number' => $val !== null && $val !== '' && is_numeric($val),
                'photo'  => $request->hasFile("photo_{$k}"),
                default  => is_string($val) && trim($val) !== '',
            };
            if ($item['type'] === 'photo') {
                if ($has) {
                    $file = $request->file("photo_{$k}");
                    $request->validate(["photo_{$k}" => 'file|mimes:jpg,jpeg,png,webp|max:8192']);
                    $photos[$k] = $file;     // stored once the row has an id
                }
                $clean[$k] = $has;
            } else {
                $clean[$k] = $item['type'] === 'check' ? $has : ($item['type'] === 'number' ? ($has ? (float) $val : null) : ($has ? trim((string) $val) : null));
            }
            if (!empty($item['required']) && !$has) $skipped++;
        }
        $now = now();
        $dueAt = $t->kind === 'checklist' ? $t->dueAt($date) : null;
        $status = $dueAt && $now->gt($dueAt) ? 'late' : 'submitted';
        $data = ['form_template_id' => $t->id, 'outlet_id' => $outletId, 'user_id' => $user->id, 'for_date' => $date->toDateString(), 'status' => $status, 'due_at' => $dueAt,
            'answers' => $clean, 'notes' => $v['notes'] ?? null, 'skipped_required' => $skipped, 'flagged' => $skipped > 0 || $t->kind === 'report', 'submitted_at' => $now];
        $sub = $existing ? tap($existing)->update($data) : FormSubmission::create($data);
        $paths = [];
        foreach ($photos as $k => $file) {
            $p = "forms/{$sub->id}/{$k}." . strtolower($file->getClientOriginalExtension() ?: 'jpg');
            Storage::disk('private')->put($p, file_get_contents($file->getRealPath()));
            $paths[$k] = $p;
        }
        $sig = $this->storeSignature($v['signature'] ?? null, $sub->id);
        $sub->forceFill(['photos' => $paths ?: null, 'signature_path' => $sig])->save();
        ActivityLogService::log('form_submitted', $sub, ['template' => $t->name, 'kind' => $t->kind, 'outlet_id' => $outletId, 'status' => $status, 'skipped_required' => $skipped],
            "{$user->first_name} submitted {$t->name}" . ($status === 'late' ? ' (late)' : '') . ($skipped ? " with {$skipped} required item(s) skipped" : ''));
        // A report, a late checklist or skipped items: the outlet's managers hear now.
        if ($sub->flagged || $status === 'late') $this->tellManagers($sub, $t, $user);

        $msg = $t->kind === 'report' ? "{$t->name} sent. Your manager has been told." : ($status === 'late' ? "{$t->name} done — after the {$dueAt->format('H:i')} due time." : "{$t->name} done.") . ($skipped ? " {$skipped} required item" . ($skipped === 1 ? '' : 's') . " left blank; your manager will see that." : '');
        return response()->json(['message' => $msg, 'submission' => $this->submission($sub->fresh(['user', 'outlet', 'template']))], 201);
    }

    /** GET /forms/submissions/{id} — own, or forms.manage in the outlet. */
    public function show(Request $request, int $id): JsonResponse
    {
        $s = FormSubmission::with(['user:id,first_name,last_name', 'outlet:id,name', 'template'])->findOrFail($id);
        $this->mayRead($request->user(), $s);
        return response()->json(['submission' => $this->submission($s)]);
    }

    /** GET /forms/submissions/{id}/file/{key} — a signed link to a photo (item key) or the signature. */
    public function fileLink(Request $request, int $id, string $key): JsonResponse
    {
        $s = FormSubmission::findOrFail($id);
        $this->mayRead($request->user(), $s);
        $path = $key === 'signature' ? $s->signature_path : ($s->photos[$key] ?? null);
        abort_unless($path, 404, 'No such file on this form.');
        return SignedFiles::issue($request, 'files.form-file', ['submission' => $s->id, 'key' => $key], basename($path));
    }

    /** GET /forms/mine?limit= — what I have submitted lately. */
    public function mine(Request $request): JsonResponse
    {
        $rows = FormSubmission::with(['outlet:id,name', 'template'])->where('user_id', $request->user()->id)->orderByDesc('submitted_at')->limit(30)->get();
        return response()->json(['data' => $rows->map(fn ($s) => $this->submission($s, brief: true))->values()]);
    }

    // ── forms.manage ───────────────────────────────────────────────────────

    /** GET /forms/overview?date= — per outlet, each checklist's state, plus reports and flagged items. */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['date' => 'nullable|date']);
        $date = !empty($v['date']) ? Carbon::parse($v['date'])->startOfDay() : today();
        $outletIds = $this->outletsOf($user);
        $outlets = Outlet::whereIn('id', $outletIds)->orderBy('name')->get(['id', 'name']);
        $templates = FormTemplate::where('active', true)->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds))->orderBy('due_time')->get();
        $subs = FormSubmission::with(['user:id,first_name,last_name', 'template'])->whereIn('outlet_id', $outletIds)->where('for_date', $date->toDateString())->get();
        $out = [];
        foreach ($outlets as $o) {
            $checks = [];
            foreach ($templates->where('kind', 'checklist') as $t) {
                if ($t->outlet_id && $t->outlet_id !== $o->id) continue;
                if (!$t->dueOn($date)) continue;
                $s = $subs->first(fn ($x) => $x->form_template_id === $t->id && $x->outlet_id === $o->id);
                $state = $s ? $s->status : ($t->dueAt($date) && now()->gt($t->dueAt($date)) ? 'overdue' : 'due');
                $checks[] = ['template' => ['id' => $t->id, 'name' => $t->name, 'due_time' => $t->due_time ? substr((string) $t->due_time, 0, 5) : null], 'state' => $state,
                    'submission' => $s && $s->status !== 'missed' ? $this->submission($s, brief: true) : null];
            }
            $reports = $subs->filter(fn ($x) => $x->outlet_id === $o->id && $x->template?->kind === 'report')->map(fn ($x) => $this->submission($x, brief: true))->values();
            $out[] = ['outlet' => ['id' => $o->id, 'name' => $o->name], 'checklists' => $checks, 'reports' => $reports,
                'summary' => ['done' => count(array_filter($checks, fn ($c) => in_array($c['state'], ['submitted', 'late'], true))), 'late' => count(array_filter($checks, fn ($c) => $c['state'] === 'late')),
                    'missed' => count(array_filter($checks, fn ($c) => $c['state'] === 'missed')), 'pending' => count(array_filter($checks, fn ($c) => in_array($c['state'], ['due', 'overdue'], true))),
                    'skipped' => (int) collect($checks)->sum(fn ($c) => $c['submission']['skipped_required'] ?? 0), 'reports' => $reports->count()]];
        }
        return response()->json(['date' => $date->toDateString(), 'outlets' => $out]);
    }

    /** GET /forms/submissions?outlet_id=&from=&to=&status=&template_id= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['outlet_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date', 'status' => ['nullable', Rule::in(['submitted', 'late', 'missed', 'flagged'])], 'template_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1']);
        $outletIds = $this->outletsOf($user, $v['outlet_id'] ?? null);
        $q = FormSubmission::with(['user:id,first_name,last_name', 'outlet:id,name', 'template'])->whereIn('outlet_id', $outletIds)->orderByDesc('for_date')->orderByDesc('id');
        if (!empty($v['from'])) $q->where('for_date', '>=', $v['from']);
        if (!empty($v['to'])) $q->where('for_date', '<=', $v['to']);
        if (!empty($v['template_id'])) $q->where('form_template_id', $v['template_id']);
        if (($v['status'] ?? null) === 'flagged') $q->where('flagged', true); elseif (!empty($v['status'])) $q->where('status', $v['status']);
        $page = $q->paginate(30, ['*'], 'page', (int) ($v['page'] ?? 1));
        return response()->json(['data' => $page->getCollection()->map(fn ($s) => $this->submission($s, brief: true))->values(), 'has_more' => $page->hasMorePages(), 'page' => $page->currentPage()]);
    }

    /** GET /forms/templates */
    public function templates(Request $request): JsonResponse
    {
        $outletIds = $this->outletsOf($request->user());
        $rows = FormTemplate::with('outlet:id,name')->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds))->orderBy('kind')->orderBy('name')->get();
        return response()->json(['data' => $rows->map(fn ($t) => $this->template($t))->values()]);
    }

    /** POST /forms/templates */
    public function storeTemplate(Request $request): JsonResponse
    {
        $v = $this->validateTemplate($request);
        if (!empty($v['outlet_id']) && !in_array((int) $v['outlet_id'], $this->outletsOf($request->user()), true)) abort(403, 'You do not manage that outlet.');
        $t = FormTemplate::create($v + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        ActivityLogService::log('form_template_created', $t, ['kind' => $t->kind, 'items' => count($t->items)], "{$request->user()->first_name} created the form \"{$t->name}\"");
        return response()->json(['message' => 'Form saved.', 'template' => $this->template($t->fresh('outlet'))], 201);
    }

    /** PUT /forms/templates/{id} */
    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $t = FormTemplate::findOrFail($id);
        if ($t->outlet_id && !in_array($t->outlet_id, $this->outletsOf($request->user()), true)) abort(403);
        $v = $this->validateTemplate($request, partial: true);
        $t->fill($v + ['updated_by' => $request->user()->id])->save();
        ActivityLogService::log('form_template_updated', $t, ['changed' => array_keys($v)], "{$request->user()->first_name} edited the form \"{$t->name}\"");
        return response()->json(['message' => 'Form saved.', 'template' => $this->template($t->fresh('outlet'))]);
    }

    /** DELETE /forms/templates/{id} — retired; past submissions keep it. */
    public function destroyTemplate(Request $request, int $id): JsonResponse
    {
        $t = FormTemplate::findOrFail($id);
        if ($t->outlet_id && !in_array($t->outlet_id, $this->outletsOf($request->user()), true)) abort(403);
        $t->update(['active' => false, 'updated_by' => $request->user()->id]);
        $t->delete();
        ActivityLogService::log('form_template_retired', $t, [], "{$request->user()->first_name} retired the form \"{$t->name}\"");
        return response()->json(['message' => "\"{$t->name}\" retired. Past submissions are kept."]);
    }

    // ── Shared with forms:remind ───────────────────────────────────────────

    /** The outlet's managers hear about a flagged or late form, once. */
    public static function tellManagers(FormSubmission $s, FormTemplate $t, ?User $by): void
    {
        if ($s->managers_told_at) return;
        $s->forceFill(['managers_told_at' => now()])->save();
        $who = Automations::recipientsForOutlet('checklist_missed', $s->outlet_id)->reject(fn (User $u) => $by && $u->id === $by->id);
        if ($who->isEmpty()) return;
        $outlet = Outlet::find($s->outlet_id)?->name ?? 'the shop';
        $name = $by ? trim("{$by->first_name} {$by->last_name}") : null;
        [$title, $body] = match (true) {
            $s->status === 'missed'  => ["{$t->name} not done at {$outlet}", 'Nothing by ' . ($s->due_at?->format('H:i') ?? 'the due time') . " on {$s->for_date->format('D j M')}."],
            $t->kind === 'report'    => ["{$t->name} from {$name} at {$outlet}", Str::limit((string) collect($s->answers)->filter(fn ($v) => is_string($v))->first(), 140) ?: 'Open it to read the details.'],
            $s->skipped_required > 0 => ["{$t->name}: {$s->skipped_required} item" . ($s->skipped_required === 1 ? '' : 's') . " skipped at {$outlet}", "{$name} left required items blank" . ($s->status === 'late' ? ', and it was late' : '') . '.'],
            default                  => ["{$t->name} late at {$outlet}", "{$name} finished it at {$s->submitted_at?->format('H:i')}, due {$s->due_at?->format('H:i')}."],
        };
        NotificationService::deliver($who, new InAppNotification(title: $title, body: $body, actionUrl: "/people/forms?date={$s->for_date->toDateString()}", icon: 'clipboard', data: ['submission_id' => $s->id, 'automation' => 'checklist_missed']));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** The outlets $user belongs to (or all, with outlets.all_access), narrowed to $only when given. */
    private function outletsOf(User $user, ?int $only = null): array
    {
        $ids = $user->can('outlets.all_access') ? Outlet::pluck('id')->map(fn ($i) => (int) $i)->all() : DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id')->map(fn ($i) => (int) $i)->all();
        if ($only !== null) { abort_unless(in_array((int) $only, $ids, true), 403, 'Not your outlet.'); return [(int) $only]; }
        return $ids;
    }

    private function mayRead(User $user, FormSubmission $s): void
    {
        if ($s->user_id === $user->id) return;
        abort_unless($user->can('forms.manage') && in_array($s->outlet_id, $this->outletsOf($user), true), 403);
    }

    private function validateTemplate(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $v = $request->validate([
            'name' => "{$req}|string|max:80", 'kind' => [$req, Rule::in(FormTemplate::KINDS)], 'outlet_id' => 'nullable|integer|exists:outlets,id',
            'schedule' => ['sometimes', Rule::in(['daily', 'none'])], 'weekdays' => 'nullable|array|max:7', 'weekdays.*' => 'integer|between:1,7',
            'due_time' => 'nullable|date_format:H:i', 'requires_signature' => 'sometimes|boolean', 'active' => 'sometimes|boolean',
            'items' => "{$req}|array|min:1|max:40", 'items.*.key' => 'required|string|max:40|regex:/^[a-z0-9_]+$/', 'items.*.label' => 'required|string|max:140',
            'items.*.type' => ['required', Rule::in(FormTemplate::ITEM_TYPES)], 'items.*.required' => 'sometimes|boolean',
        ]);
        if (isset($v['items'])) {
            $keys = array_column($v['items'], 'key');
            if (count($keys) !== count(array_unique($keys))) abort(422, 'Each item needs its own key.');
            $v['items'] = array_map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'type' => $i['type'], 'required' => (bool) ($i['required'] ?? false)], $v['items']);
        }
        if (($v['kind'] ?? null) === 'report') { $v['schedule'] = 'none'; $v['due_time'] = null; }
        return $v;
    }

    private function storeSignature(?string $dataUrl, int $id): ?string
    {
        if (!$dataUrl || !str_starts_with($dataUrl, 'data:image/png;base64,')) return null;
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($bytes === false || strlen($bytes) > 220_000) return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info || ($info['mime'] ?? '') !== 'image/png') return null;
        $p = "forms/{$id}/signature.png";
        Storage::disk('private')->put($p, $bytes);
        return $p;
    }

    private function template(FormTemplate $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'outlet' => $t->outlet_id ? ['id' => $t->outlet_id, 'name' => $t->outlet?->name] : null, 'schedule' => $t->schedule,
            'weekdays' => $t->weekdays, 'due_time' => $t->due_time ? substr((string) $t->due_time, 0, 5) : null, 'items' => $t->items, 'requires_signature' => $t->requires_signature, 'active' => $t->active];
    }

    private function submission(FormSubmission $s, bool $brief = false): array
    {
        $row = ['id' => $s->id, 'template' => $s->template ? ['id' => $s->template->id, 'name' => $s->template->name, 'kind' => $s->template->kind] : null,
            'outlet' => $s->outlet ? ['id' => $s->outlet->id, 'name' => $s->outlet->name] : ['id' => $s->outlet_id, 'name' => null],
            'user' => $s->user ? ['id' => $s->user->id, 'name' => trim("{$s->user->first_name} {$s->user->last_name}")] : null,
            'for_date' => $s->for_date->toDateString(), 'status' => $s->status, 'due_at' => $s->due_at?->toIso8601String(), 'submitted_at' => $s->submitted_at?->toIso8601String(),
            'skipped_required' => $s->skipped_required, 'flagged' => $s->flagged, 'has_signature' => (bool) $s->signature_path, 'photo_keys' => array_keys($s->photos ?? [])];
        if (!$brief) { $row['answers'] = $s->answers; $row['notes'] = $s->notes; $row['items'] = $s->template?->items; }
        return $row;
    }
}
