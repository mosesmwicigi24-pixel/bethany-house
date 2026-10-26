<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\PersonalAccessToken;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Auth\TerminalPin;
use App\Support\AttendanceRules;
use App\Support\SignedFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The clock-in kiosk (People C2): a tablet at the door of Bethany House.
 *
 * A manager with attendance.manage sets the tablet up from the Attendance
 * page (step-up): the hub mints a Sanctum token on the MANAGER's account
 * with the single ability `kiosk`, the console keeps it apart from the
 * manager's own session, signs the manager out of the tablet and opens
 * /kiosk. KioskTokensStayAtTheKiosk lets that token reach only the
 * /kiosk routes below. The token is named "kiosk:{outlet_id}:{label}".
 *
 * On the board a person taps their name, types their terminal PIN (the
 * same PIN as the till lock, App\Services\Auth\TerminalPin, with its
 * wrong-attempt lock-out) and the tablet takes a photo of the punch. The
 * entry is a normal time entry at the kiosk's outlet, clock_in_method
 * 'kiosk', no position, the C1 shift rules applied, the photo on the
 * private disk beside it and pruned with the trails.
 */
class KioskController extends Controller
{
    // ── Manager side (attendance.manage) ─────────────────────────────────

    /** GET /attendance/kiosks */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = PersonalAccessToken::where('name', 'like', 'kiosk:%')->orderBy('created_at')->get()
            ->filter(fn ($t) => in_array('kiosk', (array) $t->abilities, true))
            ->map(fn ($t) => $this->present($t))
            ->filter(fn ($k) => $user->can('outlets.all_access') || DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $k['outlet_id'])->exists())
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** POST /attendance/kiosks {outlet_id, name} — step-up. The answer carries the token once. */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate(['outlet_id' => 'required|integer|exists:outlets,id', 'name' => 'required|string|max:40']);
        $user = $request->user();
        $outlet = Outlet::findOrFail($v['outlet_id']);
        if (!$user->can('outlets.all_access') && !DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $outlet->id)->exists()) {
            abort(403, 'You do not manage this outlet.');
        }
        $label = trim(preg_replace('/[^\w \-]/u', '', $v['name'])) ?: 'Kiosk';
        $token = $user->createToken("kiosk:{$outlet->id}:{$label}", ['kiosk'], null);
        ActivityLogService::log('kiosk_set_up', null, ['outlet' => $outlet->name, 'name' => $label, 'token_id' => $token->accessToken->id],
            "{$user->first_name} set up the clock-in kiosk \"{$label}\" at {$outlet->name}");

        return response()->json(['message' => "This tablet is now the kiosk at {$outlet->name}.", 'token' => $token->plainTextToken, 'kiosk' => $this->present($token->accessToken)], 201);
    }

    /** DELETE /attendance/kiosks/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $t = PersonalAccessToken::where('name', 'like', 'kiosk:%')->findOrFail($id);
        $k = $this->present($t);
        if (!$user->can('outlets.all_access') && !DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $k['outlet_id'])->exists()) {
            abort(403, 'You do not manage this outlet.');
        }
        $t->delete();
        ActivityLogService::log('kiosk_removed', null, ['name' => $k['name'], 'outlet' => $k['outlet'], 'token_id' => $id], "{$user->first_name} removed the clock-in kiosk \"{$k['name']}\"");

        return response()->json(['message' => "Kiosk \"{$k['name']}\" removed. The tablet shows the sign-in screen next time."]);
    }

    // ── The tablet (token ability kiosk) ──────────────────────────────────

    /** GET /kiosk/board — the outlet's people and whether each is in. */
    public function board(Request $request): JsonResponse
    {
        $k = $this->kiosk($request);
        $outletId = $k['outlet_id'];
        $pins = app(TerminalPin::class);
        $active = TimeEntry::active()->whereIn('user_id', DB::table('outlet_user')->where('outlet_id', $outletId)->pluck('user_id'))->get()->keyBy('user_id');
        $people = User::whereIn('id', DB::table('outlet_user')->where('outlet_id', $outletId)->pluck('user_id'))
            ->where('status', 'active')->where('user_type', 'staff')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(function (User $u) use ($active, $pins, $outletId) {
                $e = $active->get($u->id);
                return [
                    'id'       => $u->id,
                    'name'     => trim("{$u->first_name} {$u->last_name}"),
                    'initials' => mb_strtoupper(mb_substr($u->first_name, 0, 1) . mb_substr((string) $u->last_name, 0, 1)),
                    'has_pin'  => $pins->isSet($u),
                    'state'    => $e ? ($e->hasOpenBreak() ? 'break' : 'in') : 'out',
                    'since'    => $e?->clock_in_at?->toIso8601String(),
                    'elsewhere'=> $e && $e->outlet_id !== $outletId ? $e->outlet?->name : null,
                ];
            })->values();

        return response()->json(['kiosk' => $k, 'people' => $people, 'rules' => ['clock_in_without_shift' => AttendanceRules::get('clock_in_without_shift')], 'server_time' => now()->toIso8601String()]);
    }

    /** POST /kiosk/punch {user_id, pin, action: in|out, photo?} */
    public function punch(Request $request): JsonResponse
    {
        $k = $this->kiosk($request);
        $v = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'pin'     => 'required|string|max:6',
            'action'  => 'required|in:in,out',
            'photo'   => 'nullable|string|max:600000', // a JPEG data URL, ~480 px
        ]);
        $person = User::findOrFail($v['user_id']);
        if ($person->status !== 'active' || !DB::table('outlet_user')->where('user_id', $person->id)->where('outlet_id', $k['outlet_id'])->exists()) {
            return response()->json(['message' => 'That person is not on this outlet\'s team.'], 422);
        }
        $pins = app(TerminalPin::class);
        if (!$pins->isSet($person)) {
            return response()->json(['message' => "{$person->first_name} has no PIN yet. Set one under Profile › Security on your own phone or computer."], 422);
        }
        if ($pins->lockedOut($person)) {
            return response()->json(['message' => 'Too many wrong PINs. Try again in a few minutes.'], 423);
        }
        if (!$pins->verify($person, $v['pin'])) {
            $left = $pins->attemptsLeft($person);
            return response()->json(['message' => 'Wrong PIN.' . ($left > 0 ? " {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.' : ' Locked for a few minutes.')], 422);
        }

        $now = now();
        $name = trim("{$person->first_name} {$person->last_name}");
        $active = TimeEntry::where('user_id', $person->id)->active()->first();

        if ($v['action'] === 'in') {
            if ($active) return response()->json(['message' => "{$name} is already clocked in" . ($active->outlet_id !== $k['outlet_id'] ? " at {$active->outlet?->name}" : '') . '.'], 422);
            $gate = AttendanceRules::shiftGate($person, $now);
            if ($gate['refusal']) return response()->json(['message' => $gate['refusal']], 422);
            $entry = TimeEntry::create([
                'user_id' => $person->id, 'outlet_id' => $k['outlet_id'], 'clock_in_at' => $now, 'clock_in_recorded_at' => $now,
                'clock_in_method' => 'kiosk', 'status' => 'active', 'flags' => $gate['flags'] ?: null,
                'device_info' => 'Kiosk: ' . $k['name'], 'app_version' => 'kiosk',
            ]);
            $photo = $this->storePhoto($v['photo'] ?? null, $entry->id, 'in');
            if ($photo) $entry->forceFill(['clock_in_photo_path' => $photo])->save();
            return response()->json(['message' => "{$person->first_name} clocked in at {$now->format('H:i')}.", 'entry_id' => $entry->id, 'at' => $now->toIso8601String()], 201);
        }

        if (!$active) return response()->json(['message' => "{$name} is not clocked in."], 422);
        $breaks = $active->breaks ?? [];
        foreach ($breaks as &$b) { if (!empty($b['started_at']) && empty($b['ended_at'])) $b['ended_at'] = $now->toIso8601String(); }
        unset($b);
        $active->breaks = $breaks;
        $breakMinutes = $active->calculateBreakMinutes();
        $flags = $active->flags ?? [];
        $worked = max(0, (int) $active->clock_in_at->diffInMinutes($now) - $breakMinutes);
        $photo = $this->storePhoto($v['photo'] ?? null, $active->id, 'out');
        $active->forceFill([
            'clock_out_at' => $now, 'clock_out_recorded_at' => $now, 'breaks' => $breaks, 'total_break_minutes' => $breakMinutes,
            'worked_minutes' => $worked, 'flags' => $flags ?: null, 'flagged_reason' => TimeEntry::describeFlags($flags),
            'status' => $flags ? 'flagged' : 'completed', 'clock_out_photo_path' => $photo ?: $active->clock_out_photo_path,
        ])->save();
        $h = intdiv($worked, 60); $m = $worked % 60;
        return response()->json(['message' => "{$person->first_name} clocked out at {$now->format('H:i')}. Worked " . ($h ? "{$h} h " : '') . "{$m} min.", 'entry_id' => $active->id, 'at' => $now->toIso8601String()]);
    }

    /** DELETE /kiosk/token — the tablet leaves kiosk mode; its token dies. */
    public function leave(Request $request): JsonResponse
    {
        $k = $this->kiosk($request);
        $request->user()->currentAccessToken()->delete();
        ActivityLogService::log('kiosk_left', null, ['name' => $k['name'], 'outlet' => $k['outlet']], "The clock-in kiosk \"{$k['name']}\" left kiosk mode");

        return response()->json(['message' => 'This tablet is no longer a kiosk.']);
    }

    // ── Photos (attendance.view_team) ──────────────────────────────────────

    /** GET /attendance/entries/{id}/photo/{which} — a short-lived signed link. */
    public function photoLink(Request $request, int $id, string $which): JsonResponse
    {
        $entry = TimeEntry::findOrFail($id);
        $user = $request->user();
        if (!$user->can('outlets.all_access') && !DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $entry->outlet_id)->exists()) {
            abort(403);
        }
        $path = $which === 'in' ? $entry->clock_in_photo_path : $entry->clock_out_photo_path;
        abort_unless($path, 404, 'No photo on this punch.');

        return SignedFiles::issue($request, 'files.attendance-photo', ['entry' => $entry->id, 'which' => $which], "punch-{$entry->id}-{$which}.jpg");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function kiosk(Request $request): array
    {
        $token = $request->user()?->currentAccessToken();
        abort_unless($token && in_array('kiosk', (array) $token->abilities, true), 403, 'Not a kiosk.');

        return $this->present($token);
    }

    private function present(PersonalAccessToken $t): array
    {
        [, $outletId, $label] = array_pad(explode(':', $t->name, 3), 3, null);
        $outlet = Outlet::find((int) $outletId);
        $by = $t->tokenable_id ? User::find($t->tokenable_id) : null;

        return [
            'id' => $t->id, 'name' => $label ?: 'Kiosk', 'outlet_id' => (int) $outletId, 'outlet' => $outlet?->name,
            'set_up_by' => $by ? trim("{$by->first_name} {$by->last_name}") : null, 'created_at' => $t->created_at?->toIso8601String(),
            'last_used_at' => $t->last_used_at?->toIso8601String(),
        ];
    }

    /** A JPEG data URL → private disk path, or null (no photo, or not a JPEG). */
    private function storePhoto(?string $dataUrl, int $entryId, string $which): ?string
    {
        if (!$dataUrl || !str_starts_with($dataUrl, 'data:image/jpeg;base64,')) return null;
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/jpeg;base64,')), true);
        if ($bytes === false || strlen($bytes) > 450_000) return null;
        $info = @getimagesizefromstring($bytes);
        if (!$info || ($info['mime'] ?? '') !== 'image/jpeg') return null;
        $path = "attendance/photos/{$entryId}-{$which}.jpg";
        Storage::disk('private')->put($path, $bytes);

        return $path;
    }
}
