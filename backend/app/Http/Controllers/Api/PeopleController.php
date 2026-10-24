<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\PublicHoliday;
use App\Models\StaffDocument;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\SignedFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * People (Bethany People PR 2): the employee file, staff documents and the
 * public holidays table.
 *
 * Scope: hr.view sees staff in the caller's outlets (outlet_user), every
 * outlet with outlets.all_access — the same rule as attendance. Identifiers
 * and pay details come back masked; the full values are a separate call
 * that needs hr.view_sensitive AND a fresh step-up, and every such read is
 * written to the activity log. A person always sees their own file.
 */
class PeopleController extends Controller
{
    private const PROFILE_RULES = [
        'job_title'                      => 'nullable|string|max:120',
        'department'                     => 'nullable|string|max:80',
        'employment_type'                => 'nullable|in:permanent,contract,casual,intern',
        'hire_date'                      => 'nullable|date',
        'end_date'                       => 'nullable|date|after_or_equal:hire_date',
        'date_of_birth'                  => 'nullable|date|before:today',
        'emergency_contact_name'         => 'nullable|string|max:120',
        'emergency_contact_phone'        => 'nullable|string|max:32',
        'emergency_contact_relationship' => 'nullable|string|max:60',
        'pay_method'                     => 'nullable|in:mpesa,bank,cash',
        'bank_name'                      => 'nullable|string|max:80',
        'bank_branch'                    => 'nullable|string|max:80',
        'bank_account_name'              => 'nullable|string|max:120',
        'notes'                          => 'nullable|string|max:2000',
    ];

    private const SENSITIVE_RULES = [
        'national_id_no'  => 'nullable|string|max:32',
        'kra_pin'         => 'nullable|string|max:16',
        'nssf_no'         => 'nullable|string|max:32',
        'shif_no'         => 'nullable|string|max:32',
        'mpesa_phone'     => 'nullable|string|max:32',
        'bank_account_no' => 'nullable|string|max:40',
    ];

    // ── Staff list and file ─────────────────────────────────────────────────

    /** GET /people?q=&department=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'          => 'nullable|string|max:80',
            'department' => 'nullable|string|max:80',
            'per_page'   => 'nullable|integer|min:5|max:100',
        ]);

        $query = User::query()
            ->where('user_type', 'staff')
            ->whereIn('id', $this->visibleUserIds($request->user()))
            ->with(['employeeProfile:id,user_id,job_title,department,employment_type,hire_date,end_date', 'outlets:id,name'])
            ->withCount(['staffDocuments as documents_count' => fn ($q) => $q->whereNull('removed_at')])
            ->withCount(['staffDocuments as expiring_count' => fn ($q) => $q->whereNull('removed_at')
                ->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(60)->toDateString())])
            ->orderBy('first_name')->orderBy('last_name');

        if (!empty($validated['q'])) {
            $needle = '%' . mb_strtolower($validated['q']) . '%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(email) LIKE ?', [$needle]));
        }
        if (!empty($validated['department'])) {
            $query->whereHas('employeeProfile', fn ($q) => $q->where('department', $validated['department']));
        }

        $page = $query->paginate($validated['per_page'] ?? 25);
        $page->getCollection()->transform(fn (User $u) => [
            'id'              => $u->id,
            'name'            => trim("{$u->first_name} {$u->last_name}"),
            'email'           => $u->email,
            'phone'           => $u->phone,
            'status'          => $u->status,
            'avatar_url'      => $u->avatar_url,
            'job_title'       => $u->employeeProfile?->job_title,
            'department'      => $u->employeeProfile?->department,
            'employment_type' => $u->employeeProfile?->employment_type,
            'hire_date'       => $u->employeeProfile?->hire_date?->toDateString(),
            'end_date'        => $u->employeeProfile?->end_date?->toDateString(),
            'outlets'         => $u->outlets->map(fn ($o) => $o->name)->values(),
            'documents_count' => (int) $u->documents_count,
            'expiring_count'  => (int) $u->expiring_count,
        ]);

        $departments = EmployeeProfile::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department');

        return response()->json($page)->setData(array_merge($page->toArray(), ['departments' => $departments]));
    }

    /** GET /people/{user} */
    public function show(Request $request, int $user): JsonResponse
    {
        $person = $this->findVisible($request->user(), $user);

        return response()->json(['person' => $this->file($person, $request->user())]);
    }

    /** PUT /people/{user} — the non-sensitive part of the file (hr.edit). */
    public function update(Request $request, int $user): JsonResponse
    {
        $person    = $this->findVisible($request->user(), $user);
        $validated = $request->validate(self::PROFILE_RULES);

        $profile = EmployeeProfile::firstOrNew(['user_id' => $person->id]);
        $profile->fill($validated);
        if (!$profile->exists) {
            $profile->created_by = $request->user()->id;
        }
        $profile->updated_by = $request->user()->id;
        $profile->save();

        return response()->json(['message' => 'Employee file saved.', 'person' => $this->file($person->fresh(), $request->user())]);
    }

    /**
     * GET /people/{user}/sensitive — identifiers and pay details in full.
     * hr.view_sensitive + step.up on the route. Every read is logged.
     */
    public function sensitive(Request $request, int $user): JsonResponse
    {
        $person  = $this->findVisible($request->user(), $user);
        $profile = $person->employeeProfile;

        ActivityLogService::log('employee_sensitive_viewed', $person, [
            'viewer' => $request->user()->only(['id', 'first_name', 'last_name']),
        ], "{$request->user()->first_name} viewed identifiers and pay details for {$person->first_name} {$person->last_name}");

        return response()->json(['sensitive' => $this->sensitiveOf($profile)]);
    }

    /** PUT /people/{user}/sensitive — hr.edit + hr.view_sensitive + step.up. */
    public function updateSensitive(Request $request, int $user): JsonResponse
    {
        $person    = $this->findVisible($request->user(), $user);
        $validated = $request->validate(self::SENSITIVE_RULES);

        $profile = EmployeeProfile::firstOrNew(['user_id' => $person->id]);
        foreach ($validated as $k => $v) {
            $profile->{$k} = $v === '' ? null : $v;
        }
        if (!$profile->exists) {
            $profile->created_by = $request->user()->id;
        }
        $profile->updated_by = $request->user()->id;
        $profile->save();

        ActivityLogService::log('employee_sensitive_updated', $person, [
            'fields' => array_keys($validated),
        ], "{$request->user()->first_name} updated identifiers or pay details for {$person->first_name} {$person->last_name}");

        return response()->json(['message' => 'Saved.', 'sensitive' => $this->sensitiveOf($profile->fresh())]);
    }

    // ── Documents ───────────────────────────────────────────────────────────

    /** POST /people/{user}/documents  multipart: kind, title, file, expires_at? (hr.edit) */
    public function storeDocument(Request $request, int $user): JsonResponse
    {
        $person    = $this->findVisible($request->user(), $user);
        $validated = $request->validate([
            'kind'       => ['required', Rule::in(StaffDocument::KINDS)],
            'title'      => 'required|string|max:120',
            'file'       => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif,pdf|max:10240',
            'expires_at' => 'nullable|date',
        ]);

        $file = $request->file('file');
        $path = $file->store("people/{$person->id}/documents", 'private');

        $doc = StaffDocument::create([
            'user_id'       => $person->id,
            'kind'          => $validated['kind'],
            'title'         => $validated['title'],
            'disk'          => 'private',
            'path'          => $path,
            'mime_type'     => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'original_name' => substr((string) $file->getClientOriginalName(), 0, 255),
            'expires_at'    => $validated['expires_at'] ?? null,
            'uploaded_by'   => $request->user()->id,
        ]);

        return response()->json(['message' => 'Document added.', 'document' => $this->document($doc)], 201);
    }

    /** GET /people/{user}/documents/{doc} — a short-lived signed link (hr.view). */
    public function documentLink(Request $request, int $user, int $doc): JsonResponse
    {
        $person   = $this->findVisible($request->user(), $user);
        $document = $person->staffDocuments()->current()->whereKey($doc)->firstOrFail();

        return SignedFiles::issue($request, 'files.staff-document', ['document' => $document->id], $document->original_name ?: basename($document->path));
    }

    /** DELETE /people/{user}/documents/{doc} — marked removed, file kept (hr.edit). */
    public function removeDocument(Request $request, int $user, int $doc): JsonResponse
    {
        $person   = $this->findVisible($request->user(), $user);
        $document = $person->staffDocuments()->current()->whereKey($doc)->firstOrFail();
        $document->update(['removed_at' => now(), 'removed_by' => $request->user()->id]);

        return response()->json(['message' => 'Document removed.']);
    }

    /** GET /people/documents/expiring?days=60 (hr.view) */
    public function expiring(Request $request): JsonResponse
    {
        $days = (int) $request->validate(['days' => 'nullable|integer|min:1|max:365'])['days'] ?? 60;
        $days = $days ?: 60;

        $docs = StaffDocument::with('user:id,first_name,last_name')
            ->current()
            ->whereIn('user_id', $this->visibleUserIds($request->user()))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expires_at')
            ->get();

        return response()->json(['data' => $docs->map(fn ($d) => $this->document($d, withUser: true)), 'days' => $days]);
    }

    // ── My own file ─────────────────────────────────────────────────────────

    /** GET /me/file — the signed-in person's own file, identifiers masked. */
    public function myFile(Request $request): JsonResponse
    {
        return response()->json(['person' => $this->file($request->user(), $request->user(), self: true)]);
    }

    /** PUT /me/file — only the fields a person keeps about themselves. */
    public function updateMyFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emergency_contact_name'         => 'nullable|string|max:120',
            'emergency_contact_phone'        => 'nullable|string|max:32',
            'emergency_contact_relationship' => 'nullable|string|max:60',
            'date_of_birth'                  => 'nullable|date|before:today',
        ]);
        $profile = EmployeeProfile::firstOrNew(['user_id' => $request->user()->id]);
        $profile->fill($validated);
        if (!$profile->exists) {
            $profile->created_by = $request->user()->id;
        }
        $profile->updated_by = $request->user()->id;
        $profile->save();

        return response()->json(['message' => 'Saved.', 'person' => $this->file($request->user()->fresh(), $request->user(), self: true)]);
    }

    /** GET /me/file/sensitive — own identifiers and pay details in full (step.up). */
    public function mySensitive(Request $request): JsonResponse
    {
        return response()->json(['sensitive' => $this->sensitiveOf($request->user()->employeeProfile)]);
    }

    /** GET /me/file/documents/{doc} — a signed link to one of my own documents. */
    public function myDocumentLink(Request $request, int $doc): JsonResponse
    {
        $document = $request->user()->staffDocuments()->current()->whereKey($doc)->firstOrFail();

        return SignedFiles::issue($request, 'files.staff-document', ['document' => $document->id], $document->original_name ?: basename($document->path));
    }

    // ── Public holidays ─────────────────────────────────────────────────────

    /** GET /holidays?year= — every staff member (the roster and leave read it). */
    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => 'nullable|integer|min:2020|max:2100'])['year'] ?? now()->year);
        $year = $year ?: now()->year;

        $rows = PublicHoliday::whereYear('date', $year)->orderBy('date')->get()
            ->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'country' => $h->country,
                'is_confirmed' => $h->is_confirmed, 'notes' => $h->notes]);

        return response()->json(['data' => $rows, 'year' => $year]);
    }

    /** POST /holidays (hr.edit) */
    public function storeHoliday(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'         => 'required|date',
            'name'         => 'required|string|max:120',
            'is_confirmed' => 'sometimes|boolean',
            'notes'        => 'nullable|string|max:255',
        ]);
        $exists = PublicHoliday::where('date', $validated['date'])->where('country', 'KE')->exists();
        if ($exists) {
            return response()->json(['message' => 'There is already a holiday on that date.'], 422);
        }
        $h = PublicHoliday::create($validated + ['country' => 'KE', 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Holiday added.', 'holiday' => $h], 201);
    }

    /** PUT /holidays/{id} (hr.edit) */
    public function updateHoliday(Request $request, int $id): JsonResponse
    {
        $h = PublicHoliday::findOrFail($id);
        $validated = $request->validate([
            'date'         => 'sometimes|date',
            'name'         => 'sometimes|string|max:120',
            'is_confirmed' => 'sometimes|boolean',
            'notes'        => 'nullable|string|max:255',
        ]);
        if (isset($validated['date']) && PublicHoliday::where('date', $validated['date'])->where('country', $h->country)->whereKeyNot($h->id)->exists()) {
            return response()->json(['message' => 'There is already a holiday on that date.'], 422);
        }
        $h->update($validated);

        return response()->json(['message' => 'Holiday saved.', 'holiday' => $h->fresh()]);
    }

    /** DELETE /holidays/{id} (hr.edit) */
    public function destroyHoliday(int $id): JsonResponse
    {
        PublicHoliday::findOrFail($id)->delete();

        return response()->json(['message' => 'Holiday removed.']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Staff this caller may see: own outlets, or everyone with outlets.all_access; always themselves. */
    private function visibleUserIds(User $viewer)
    {
        if ($viewer->can('outlets.all_access')) {
            return User::where('user_type', 'staff')->pluck('id');
        }
        $outletIds = DB::table('outlet_user')->where('user_id', $viewer->id)->pluck('outlet_id');
        $ids = DB::table('outlet_user')->whereIn('outlet_id', $outletIds)->pluck('user_id')->push($viewer->id)->unique()->values();

        return $ids;
    }

    private function findVisible(User $viewer, int $userId): User
    {
        $person = User::where('user_type', 'staff')->findOrFail($userId);
        abort_if(!$this->visibleUserIds($viewer)->contains($person->id), 403, 'This person is not in your outlets.');

        return $person;
    }

    private function file(User $person, User $viewer, bool $self = false): array
    {
        $p = $person->employeeProfile;
        $docs = $person->staffDocuments()->current()->orderByDesc('created_at')->get();

        return [
            'id'         => $person->id,
            'name'       => trim("{$person->first_name} {$person->last_name}"),
            'first_name' => $person->first_name,
            'last_name'  => $person->last_name,
            'email'      => $person->email,
            'phone'      => $person->phone,
            'status'     => $person->status,
            'avatar_url' => $person->avatar_url,
            'outlets'    => $person->outlets()->get(['outlets.id', 'outlets.name'])->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'reports_to' => $person->reportsTo ? ['id' => $person->reportsTo->id, 'name' => trim("{$person->reportsTo->first_name} {$person->reportsTo->last_name}")] : null,
            'roles'      => $person->getRoleNames()->values(),
            'profile'    => [
                'job_title'                      => $p?->job_title,
                'department'                     => $p?->department,
                'employment_type'                => $p?->employment_type ?? 'permanent',
                'hire_date'                      => $p?->hire_date?->toDateString(),
                'end_date'                       => $p?->end_date?->toDateString(),
                'date_of_birth'                  => $p?->date_of_birth?->toDateString(),
                'emergency_contact_name'         => $p?->emergency_contact_name,
                'emergency_contact_phone'        => $p?->emergency_contact_phone,
                'emergency_contact_relationship' => $p?->emergency_contact_relationship,
                'pay_method'                     => $p?->pay_method,
                'bank_name'                      => $p?->bank_name,
                'bank_branch'                    => $p?->bank_branch,
                'bank_account_name'              => $p?->bank_account_name,
                'notes'                          => $p?->notes,
                'updated_at'                     => $p?->updated_at?->toIso8601String(),
            ],
            // Masked always; the full values are /sensitive behind step-up.
            'masked'     => collect(EmployeeProfile::SENSITIVE)->mapWithKeys(fn ($k) => [$k => EmployeeProfile::mask($p?->{$k})])->all(),
            'can'        => [
                'edit'           => !$self && $viewer->can('hr.edit'),
                'view_sensitive' => $self || $viewer->can('hr.view_sensitive'),
                'edit_sensitive' => !$self && $viewer->can('hr.edit') && $viewer->can('hr.view_sensitive'),
            ],
            'documents'  => $docs->map(fn ($d) => $this->document($d))->values(),
        ];
    }

    private function sensitiveOf(?EmployeeProfile $p): array
    {
        return collect(EmployeeProfile::SENSITIVE)->mapWithKeys(fn ($k) => [$k => $p?->{$k}])->all();
    }

    private function document(StaffDocument $d, bool $withUser = false): array
    {
        $out = [
            'id'            => $d->id,
            'kind'          => $d->kind,
            'title'         => $d->title,
            'original_name' => $d->original_name,
            'mime_type'     => $d->mime_type,
            'size_bytes'    => $d->size_bytes,
            'expires_at'    => $d->expires_at?->toDateString(),
            'expired'       => $d->expires_at !== null && $d->expires_at->lt(now()->startOfDay()),
            'uploaded_at'   => $d->created_at?->toIso8601String(),
        ];
        if ($withUser && $d->user) {
            $out['user'] = ['id' => $d->user->id, 'name' => trim("{$d->user->first_name} {$d->user->last_name}")];
        }
        return $out;
    }
}
