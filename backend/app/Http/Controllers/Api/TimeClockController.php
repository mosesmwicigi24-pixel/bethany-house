<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TimeClockController extends Controller
{
    // =========================================================================
    // SELF-SERVICE — every authenticated staff member, no permission gate.
    // Mirrors the /admin/profile routes: clocking in/out is a basic
    // self-service action, not something that should be permission-locked.
    // =========================================================================

    /**
     * GET /api/v1/admin/time-clock/status
     *
     * Returns the user's current open entry (if any), today's completed
     * entries, and the outlets/workshops they're allowed to clock into.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $active = TimeEntry::with('outlet:id,name,outlet_type')
            ->where('user_id', $user->id)
            ->active()
            ->first();

        $today = TimeEntry::with('outlet:id,name,outlet_type')
            ->where('user_id', $user->id)
            ->whereDate('clock_in_at', today())
            ->orderByDesc('clock_in_at')
            ->get();

        return response()->json([
            'active_entry'   => $active ? $this->transformEntry($active) : null,
            'today_entries'  => $today->map(fn ($e) => $this->transformEntry($e)),
            'today_worked_minutes' => $today->sum(fn ($e) => $e->status === 'active' ? $e->elapsedMinutes() : ($e->worked_minutes ?? 0)),
            'outlets'        => $this->availableOutletsFor($user),
        ]);
    }

    /**
     * GET /api/v1/admin/time-clock/outlets
     *
     * Lightweight list of outlets/workshops this user can clock into, with
     * coordinates + radius so the client can show a "you're inside/outside
     * the zone" indicator before they tap Clock In.
     */
    public function availableOutlets(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->availableOutletsFor($request->user())]);
    }

    private function availableOutletsFor($user)
    {
        // Staff are scoped to outlets they're assigned to via outlet_user.
        // Admins/super admins aren't restricted - they may float between sites.
        $query = Outlet::query()->active()
            ->select('id', 'name', 'outlet_type', 'latitude', 'longitude', 'geofence_radius_meters');

        if (!$user->hasAnyRole(['admin', 'super_admin'])) {
            $outletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
            $query->whereIn('id', $outletIds);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * POST /api/v1/admin/time-clock/clock-in
     * { outlet_id, latitude, longitude, force?, reason? }
     */
    public function clockIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'force'     => 'sometimes|boolean',
            'reason'    => 'required_if:force,true|nullable|string|max:255',
        ]);

        $user = $request->user();

        if (TimeEntry::where('user_id', $user->id)->active()->exists()) {
            return response()->json([
                'message' => 'You are already clocked in. Clock out before starting a new shift.',
            ], 422);
        }

        $outlet = Outlet::findOrFail($validated['outlet_id']);

        $distance = null;
        $method   = 'gps';
        $overriddenBy = null;

        if ($outlet->latitude !== null && $outlet->longitude !== null) {
            $distance = TimeEntry::haversineMeters(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                (float) $outlet->latitude,
                (float) $outlet->longitude,
            );

            $radius = $outlet->geofence_radius_meters;

            if ($radius !== null && $distance > $radius) {
                $canOverride = $user->can('attendance.manage') || $user->hasAnyRole(['admin', 'super_admin']);

                if (!($validated['force'] ?? false) || !$canOverride) {
                    return response()->json([
                        'message'  => "You're " . round($distance) . "m from {$outlet->name}, outside the {$radius}m clock-in zone.",
                        'distance_meters' => round($distance, 1),
                        'allowed_radius_meters' => $radius,
                        'requires_override' => true,
                    ], 422);
                }

                // Manager/admin force override - logged, not silent.
                $method       = 'override';
                $overriddenBy = $user->id;
            }
        }

        $entry = TimeEntry::create([
            'user_id'                 => $user->id,
            'outlet_id'               => $outlet->id,
            'clock_in_at'              => now(),
            'clock_in_latitude'        => $validated['latitude'],
            'clock_in_longitude'       => $validated['longitude'],
            'clock_in_distance_meters' => $distance !== null ? round($distance, 2) : null,
            'clock_in_method'          => $method,
            'status'                   => 'active',
            'overridden_by'            => $overriddenBy,
            'notes'                    => $validated['reason'] ?? null,
            'device_info'              => substr((string) $request->userAgent(), 0, 250),
        ]);

        if ($method === 'override') {
            ActivityLogService::log('attendance_geofence_override', $entry, [
                'user'     => $user->only(['id', 'first_name', 'last_name']),
                'outlet'   => $outlet->name,
                'distance' => round($distance, 1),
                'reason'   => $validated['reason'] ?? null,
            ], "{$user->first_name} clocked in outside the geofence at {$outlet->name} (override)");
        }

        return response()->json([
            'message' => "Clocked in at {$outlet->name}.",
            'entry'   => $this->transformEntry($entry),
        ], 201);
    }

    /**
     * POST /api/v1/admin/time-clock/clock-out
     * { latitude, longitude }
     */
    public function clockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user  = $request->user();
        $entry = TimeEntry::where('user_id', $user->id)->active()->first();

        if (!$entry) {
            return response()->json(['message' => 'You are not currently clocked in.'], 404);
        }

        // Auto-close a dangling open break rather than blocking clock-out.
        $breaks = $entry->breaks ?? [];
        if ($entry->hasOpenBreak()) {
            foreach ($breaks as &$break) {
                if (!empty($break['started_at']) && empty($break['ended_at'])) {
                    $break['ended_at'] = now()->toIso8601String();
                }
            }
            unset($break);
        }

        $outlet   = $entry->outlet;
        $distance = null;
        if ($outlet && $outlet->latitude !== null && $outlet->longitude !== null) {
            $distance = TimeEntry::haversineMeters(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                (float) $outlet->latitude,
                (float) $outlet->longitude,
            );
        }

        $clockOutAt   = now();
        $breakMinutes = 0;
        foreach ($breaks as $break) {
            if (!empty($break['started_at']) && !empty($break['ended_at'])) {
                $breakMinutes += Carbon::parse($break['started_at'])->diffInMinutes(Carbon::parse($break['ended_at']));
            }
        }
        $workedMinutes = max(0, $entry->clock_in_at->diffInMinutes($clockOutAt) - $breakMinutes);

        $entry->update([
            'clock_out_at'              => $clockOutAt,
            'clock_out_latitude'        => $validated['latitude'],
            'clock_out_longitude'       => $validated['longitude'],
            'clock_out_distance_meters' => $distance !== null ? round($distance, 2) : null,
            'breaks'                    => $breaks,
            'total_break_minutes'       => $breakMinutes,
            'worked_minutes'            => $workedMinutes,
            'status'                    => 'completed',
        ]);

        return response()->json([
            'message' => 'Clocked out. Worked ' . $this->formatMinutes($workedMinutes) . '.',
            'entry'   => $this->transformEntry($entry->fresh('outlet')),
        ]);
    }

    /**
     * POST /api/v1/admin/time-clock/break/start
     */
    public function startBreak(Request $request): JsonResponse
    {
        $entry = TimeEntry::where('user_id', $request->user()->id)->active()->first();

        if (!$entry) {
            return response()->json(['message' => 'You are not currently clocked in.'], 404);
        }
        if ($entry->hasOpenBreak()) {
            return response()->json(['message' => 'You are already on a break.'], 422);
        }

        $breaks   = $entry->breaks ?? [];
        $breaks[] = ['started_at' => now()->toIso8601String(), 'ended_at' => null];
        $entry->update(['breaks' => $breaks]);

        return response()->json(['message' => 'Break started.', 'entry' => $this->transformEntry($entry->fresh())]);
    }

    /**
     * POST /api/v1/admin/time-clock/break/end
     */
    public function endBreak(Request $request): JsonResponse
    {
        $entry = TimeEntry::where('user_id', $request->user()->id)->active()->first();

        if (!$entry || !$entry->hasOpenBreak()) {
            return response()->json(['message' => 'You are not currently on a break.'], 422);
        }

        $breaks = $entry->breaks;
        foreach ($breaks as &$break) {
            if (!empty($break['started_at']) && empty($break['ended_at'])) {
                $break['ended_at'] = now()->toIso8601String();
            }
        }
        unset($break);

        $entry->update(['breaks' => $breaks]);

        return response()->json(['message' => 'Break ended.', 'entry' => $this->transformEntry($entry->fresh())]);
    }

    /**
     * GET /api/v1/admin/time-clock/my-entries?from=&to=&per_page=
     */
    public function myEntries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $query = TimeEntry::with('outlet:id,name,outlet_type')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('clock_in_at');

        if (!empty($validated['from']) && !empty($validated['to'])) {
            $query->between($validated['from'], $validated['to']);
        }

        $entries = $query->paginate($validated['per_page'] ?? 20);
        $entries->getCollection()->transform(fn ($e) => $this->transformEntry($e));

        return response()->json($entries);
    }

    // =========================================================================
    // ADMIN / TEAM OVERSIGHT — gated by attendance.view_team / attendance.manage
    // =========================================================================

    /**
     * GET /api/v1/admin/attendance/entries
     * Filters: outlet_id, user_id, status, from, to
     */
    public function teamEntries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|exists:outlets,id',
            'user_id'   => 'nullable|exists:users,id',
            'status'    => 'nullable|in:active,completed,flagged',
            'from'      => 'nullable|date',
            'to'        => 'nullable|date',
            'per_page'  => 'nullable|integer|min:5|max:100',
        ]);

        $user  = $request->user();
        $query = TimeEntry::with(['user:id,first_name,last_name', 'outlet:id,name,outlet_type'])
            ->orderByDesc('clock_in_at');

        // Outlet managers (and anyone without the .* admin wildcard) only see
        // entries for outlets they're assigned to.
        if (!$user->hasAnyRole(['admin', 'super_admin'])) {
            $managedOutletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
            $query->whereIn('outlet_id', $managedOutletIds);
        }

        if (!empty($validated['outlet_id'])) $query->where('outlet_id', $validated['outlet_id']);
        if (!empty($validated['user_id']))   $query->where('user_id', $validated['user_id']);
        if (!empty($validated['status']))    $query->where('status', $validated['status']);
        if (!empty($validated['from']) && !empty($validated['to'])) {
            $query->between($validated['from'], $validated['to']);
        }

        $entries = $query->paginate($validated['per_page'] ?? 25);
        $entries->getCollection()->transform(fn ($e) => $this->transformEntry($e, withUser: true));

        return response()->json($entries);
    }

    /**
     * GET /api/v1/admin/attendance/entries/{id}
     */
    public function showEntry(Request $request, $id): JsonResponse
    {
        $entry = TimeEntry::with(['user:id,first_name,last_name,email', 'outlet', 'overriddenBy:id,first_name,last_name', 'correctedBy:id,first_name,last_name'])
            ->findOrFail($id);

        $this->authorizeOutletAccess($request->user(), $entry->outlet_id);

        return response()->json(['entry' => $this->transformEntry($entry, withUser: true, detailed: true)]);
    }

    /**
     * GET /api/v1/admin/attendance/flagged
     */
    public function flagged(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = TimeEntry::with(['user:id,first_name,last_name', 'outlet:id,name'])
            ->flagged()
            ->orderByDesc('clock_in_at');

        if (!$user->hasAnyRole(['admin', 'super_admin'])) {
            $managedOutletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
            $query->whereIn('outlet_id', $managedOutletIds);
        }

        $entries = $query->limit(50)->get();

        return response()->json(['data' => $entries->map(fn ($e) => $this->transformEntry($e, withUser: true))]);
    }

    /**
     * PUT /api/v1/admin/attendance/entries/{id}
     * Manager correction: adjust clock times, resolve a flag, add notes.
     */
    public function updateEntry(Request $request, $id): JsonResponse
    {
        $entry = TimeEntry::findOrFail($id);
        $this->authorizeOutletAccess($request->user(), $entry->outlet_id);

        $validated = $request->validate([
            'clock_in_at'  => 'sometimes|date',
            'clock_out_at' => 'nullable|date|after:clock_in_at',
            'status'       => 'sometimes|in:active,completed,flagged',
            'notes'        => 'nullable|string|max:1000',
        ]);

        $original = $entry->only(['clock_in_at', 'clock_out_at', 'status']);

        if (isset($validated['clock_in_at']))  $entry->clock_in_at  = $validated['clock_in_at'];
        if (array_key_exists('clock_out_at', $validated)) $entry->clock_out_at = $validated['clock_out_at'];
        if (isset($validated['status']))       $entry->status       = $validated['status'];
        if (array_key_exists('notes', $validated)) $entry->notes    = $validated['notes'];

        // Recompute worked time if either timestamp moved and we now have both ends.
        if ($entry->clock_in_at && $entry->clock_out_at) {
            $breakMinutes        = $entry->calculateBreakMinutes();
            $entry->total_break_minutes = $breakMinutes;
            $entry->worked_minutes      = max(0, $entry->clock_in_at->diffInMinutes($entry->clock_out_at) - $breakMinutes);
            if ($entry->status === 'active') {
                $entry->status = 'completed';
            }
        }

        $entry->corrected_by = $request->user()->id;
        $entry->save();

        ActivityLogService::log('attendance_entry_corrected', $entry, [
            'before' => $original,
            'after'  => $entry->only(['clock_in_at', 'clock_out_at', 'status']),
        ], "Time entry #{$entry->id} corrected by {$request->user()->first_name}");

        return response()->json([
            'message' => 'Time entry updated.',
            'entry'   => $this->transformEntry($entry->fresh(['user', 'outlet']), withUser: true, detailed: true),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function authorizeOutletAccess($user, $outletId): void
    {
        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            return;
        }
        $managed = DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $outletId)->exists();
        abort_if(!$managed, 403, 'You do not manage this outlet.');
    }

    private function formatMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }

    private function transformEntry(TimeEntry $entry, bool $withUser = false, bool $detailed = false): array
    {
        $data = [
            'id'                     => $entry->id,
            'outlet'                 => $entry->outlet ? ['id' => $entry->outlet->id, 'name' => $entry->outlet->name, 'outlet_type' => $entry->outlet->outlet_type] : null,
            'clock_in_at'             => $entry->clock_in_at?->toIso8601String(),
            'clock_out_at'            => $entry->clock_out_at?->toIso8601String(),
            'status'                  => $entry->status,
            'clock_in_method'         => $entry->clock_in_method,
            'clock_in_distance_meters'=> $entry->clock_in_distance_meters,
            'total_break_minutes'     => $entry->total_break_minutes,
            'worked_minutes'          => $entry->worked_minutes,
            'elapsed_minutes'         => $entry->elapsedMinutes(),
            'on_break'                => $entry->hasOpenBreak(),
            'flagged_reason'          => $entry->flagged_reason,
            'notes'                   => $entry->notes,
        ];

        if ($withUser && $entry->user) {
            $data['user'] = [
                'id'   => $entry->user->id,
                'name' => trim("{$entry->user->first_name} {$entry->user->last_name}"),
            ];
        }

        if ($detailed) {
            $data['breaks']                     = $entry->breaks;
            $data['clock_in_latitude']           = $entry->clock_in_latitude;
            $data['clock_in_longitude']          = $entry->clock_in_longitude;
            $data['clock_out_latitude']          = $entry->clock_out_latitude;
            $data['clock_out_longitude']         = $entry->clock_out_longitude;
            $data['clock_out_distance_meters']   = $entry->clock_out_distance_meters;
            $data['overridden_by']               = $entry->overriddenBy ? trim("{$entry->overriddenBy->first_name} {$entry->overriddenBy->last_name}") : null;
            $data['corrected_by']                = $entry->correctedBy ? trim("{$entry->correctedBy->first_name} {$entry->correctedBy->last_name}") : null;
        }

        return $data;
    }
}
