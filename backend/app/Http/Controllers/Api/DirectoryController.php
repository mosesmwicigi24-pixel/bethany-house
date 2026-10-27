<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The staff directory (People C5): colleagues' names, jobs, departments and
 * outlets for everyone; a phone, an email and a birthday only where that
 * person lets them show. Each person sets their own switches here.
 */
class DirectoryController extends Controller
{
    /** GET /directory?q=&outlet_id= */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $v = $request->validate(['q' => 'nullable|string|max:80', 'outlet_id' => 'nullable|integer']);
        $q = User::where('status', 'active')->where('user_type', 'staff')->with(['employeeProfile', 'outlets:id,name'])->orderBy('first_name')->orderBy('last_name');
        if (!empty($v['q'])) {
            $s = '%' . strtolower(trim($v['q'])) . '%';
            $q->where(fn ($w) => $w->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$s])->orWhereHas('employeeProfile', fn ($p) => $p->whereRaw('LOWER(job_title) LIKE ?', [$s])->orWhereRaw('LOWER(department) LIKE ?', [$s])));
        }
        if (!empty($v['outlet_id'])) $q->whereIn('id', DB::table('outlet_user')->where('outlet_id', $v['outlet_id'])->pluck('user_id'));
        $rows = $q->get()->map(fn (User $u) => $this->card($u, $me))->values();
        $mine = EmployeeProfile::firstOrNew(['user_id' => $me->id]);
        return response()->json(['data' => $rows, 'me' => ['show_phone' => $mine->exists ? $mine->directory_show_phone : true, 'show_email' => $mine->exists ? $mine->directory_show_email : true, 'show_birthday' => $mine->exists ? $mine->directory_show_birthday : false],
            'departments' => $rows->pluck('department')->filter()->unique()->sort()->values()]);
    }

    /** PUT /me/directory {show_phone, show_email, show_birthday} */
    public function updateMine(Request $request): JsonResponse
    {
        $v = $request->validate(['show_phone' => 'sometimes|boolean', 'show_email' => 'sometimes|boolean', 'show_birthday' => 'sometimes|boolean']);
        $p = EmployeeProfile::firstOrNew(['user_id' => $request->user()->id]);
        if (!$p->exists) $p->created_by = $request->user()->id;
        foreach (['show_phone' => 'directory_show_phone', 'show_email' => 'directory_show_email', 'show_birthday' => 'directory_show_birthday'] as $in => $col) if (array_key_exists($in, $v)) $p->{$col} = (bool) $v[$in];
        $p->updated_by = $request->user()->id;
        $p->save();
        ActivityLogService::log('directory_visibility_changed', $p, array_intersect_key($v, array_flip(['show_phone', 'show_email', 'show_birthday'])), "{$request->user()->first_name} changed what the directory shows about them");
        return response()->json(['message' => 'Saved.', 'me' => ['show_phone' => $p->directory_show_phone, 'show_email' => $p->directory_show_email, 'show_birthday' => $p->directory_show_birthday]]);
    }

    private function card(User $u, User $viewer): array
    {
        $p = $u->employeeProfile;
        $self = $u->id === $viewer->id;
        $showPhone = $self || ($p ? $p->directory_show_phone : true);
        $showEmail = $self || ($p ? $p->directory_show_email : true);
        $showBday = $p && $p->directory_show_birthday && $p->date_of_birth;
        return [
            'id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'initials' => mb_strtoupper(mb_substr($u->first_name, 0, 1) . mb_substr((string) $u->last_name, 0, 1)), 'avatar_url' => $u->avatar_url,
            'job_title' => $p?->job_title, 'department' => $p?->department, 'outlets' => $u->outlets->map(fn ($o) => $o->name)->values(),
            'phone' => $showPhone ? $u->phone : null, 'email' => $showEmail ? $u->email : null,
            'birthday' => $showBday ? $p->date_of_birth->format('j M') : null, 'since' => $p?->hire_date?->format('M Y'), 'is_me' => $self,
        ];
    }
}
