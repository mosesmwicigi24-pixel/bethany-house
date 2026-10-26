<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationPing;
use App\Models\Outlet;
use App\Models\Shift;
use App\Notifications\InAppNotification;
use App\Services\NotificationService;
use App\Support\AttendanceRules;
use App\Models\TimeEntry;
use App\Services\ActivityLogService;
use App\Services\Auth\Devices;
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

        // This week, Monday to now, Nairobi days (app timezone).
        $weekStart = now()->startOfWeek();
        $week = TimeEntry::where('user_id', $user->id)
            ->where('clock_in_at', '>=', $weekStart)
            ->get();
        $last = TimeEntry::with('outlet:id,name,outlet_type')
            ->where('user_id', $user->id)
            ->orderByDesc('clock_in_at')
            ->first();

        return response()->json([
            'active_entry'   => $active ? $this->transformEntry($active) : null,
            'today_entries'  => $today->map(fn ($e) => $this->transformEntry($e)),
            'today_worked_minutes' => $today->sum(fn ($e) => $e->status === 'active' ? $e->elapsedMinutes() : ($e->worked_minutes ?? 0)),
            'week_worked_minutes'  => $week->sum(fn ($e) => $e->status === 'active' ? $e->elapsedMinutes() : ($e->worked_minutes ?? 0)),
            'last_entry'     => $last ? $this->transformEntry($last) : null,
            'outlets'        => $this->availableOutletsFor($user),
            'rules'          => [
                'auto_clock_out_time' => AttendanceRules::get('auto_clock_out_time'),
                'late_sync_minutes'   => (int) config('attendance.late_sync_minutes'),
                // Location on shift (C1): the app runs its trail only while an entry is active and mode = trail.
                'location_mode'           => AttendanceRules::get('location_mode'),
                'trail_interval_minutes'  => (int) AttendanceRules::get('trail_interval_minutes'),
                'trail_distance_meters'   => (int) AttendanceRules::get('trail_distance_meters'),
                'leave_site_action'       => AttendanceRules::get('leave_site_action'),
                'leave_site_grace_minutes'=> (int) AttendanceRules::get('leave_site_grace_minutes'),
            ],
            'today_shift'    => ($sh = Shift::with('outlet:id,name')->where('user_id', $user->id)->where('status', 'published')->where('date', today()->toDateString())->orderBy('starts_at')->first())
                ? ['id' => $sh->id, 'starts_at' => Shift::hhmm($sh->starts_at), 'ends_at' => Shift::hhmm($sh->ends_at), 'outlet' => $sh->outlet?->name, 'label' => $sh->label] : null,
            'server_time'    => now()->toIso8601String(),
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
        // outlets.all_access (admin; super_admin by bypass) is not restricted —
        // they may float between sites. A permission since 4D, not role names.
        $query = Outlet::query()->active()
            ->select('id', 'name', 'outlet_type', 'latitude', 'longitude', 'geofence_radius_meters');

        if (!$user->can('outlets.all_access')) {
            $outletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
            $query->whereIn('id', $outletIds);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * POST /api/v1/admin/time-clock/clock-in
     * { outlet_id, latitude, longitude, accuracy?, recorded_at?, mock_location?, app_version?, force?, reason? }
     *
     * The phone sends its fix and how sure it is; the server decides.
     *   inside   distance <= radius                                  -> accepted
     *   edge     distance <= radius + min(accuracy, radius)          -> accepted, flagged "edge"
     *   outside  anything further                                    -> 422, override only for attendance.manage
     * A fix less precise than the radius is accepted and flagged "accuracy_low";
     * a punch sent late keeps the phone's time and is flagged "sent_late";
     * a phone that says its location is mocked is flagged "mock_location".
     * Flags never refuse an honest person at the gate; they put the punch in
     * front of the manager with the evidence.
     */
    public function clockIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id'     => 'required|exists:outlets,id',
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'accuracy'      => 'nullable|numeric|min:0|max:100000',
            'recorded_at'   => 'nullable|date',
            'mock_location' => 'sometimes|boolean',
            'app_version'   => 'nullable|string|max:40',
            'force'         => 'sometimes|boolean',
            'reason'        => 'required_if:force,true|nullable|string|max:255',
        ]);

        $user  = $request->user();
        $flags = [];
        $accuracy = isset($validated['accuracy']) ? (float) $validated['accuracy'] : null;
        $rules = AttendanceRules::all();

        if (TimeEntry::where('user_id', $user->id)->active()->exists()) {
            return response()->json([
                'message' => 'You are already clocked in. Clock out before starting a new shift.',
            ], 422);
        }

        $outlet = Outlet::findOrFail($validated['outlet_id']);
        $device = app(Devices::class)->knownTo($user, $request);

        // A fixed, location-free device (the shop computer, a kiosk tablet) may punch without a position (C1).
        $noPosition = !isset($validated['latitude']) || !isset($validated['longitude']);
        if ($noPosition && !($device && $device->location_free)) {
            return response()->json(['message' => 'Your position is needed to clock in. Allow location and try again.'], 422);
        }

        // Rostered shift rules (C1): without a shift → allow / flag / refuse; not too early before one.
        $shift = Shift::where('user_id', $user->id)->where('status', 'published')->where('date', today()->toDateString())->orderBy('starts_at')->first();
        if (!$shift) {
            if ($rules['clock_in_without_shift'] === 'refuse') {
                return response()->json(['message' => 'You have no shift on the roster today. Ask your manager to add one before clocking in.'], 422);
            }
            if ($rules['clock_in_without_shift'] === 'flag') $flags[] = 'no_shift';
        } elseif ((int) $rules['earliest_clock_in_minutes'] > 0) {
            $earliest = $shift->startsAtCarbon()->subMinutes((int) $rules['earliest_clock_in_minutes']);
            if (now()->lt($earliest)) {
                return response()->json(['message' => 'Your shift starts at ' . $shift->startsAtCarbon()->format('H:i') . '. You can clock in from ' . $earliest->format('H:i') . '.', 'earliest_at' => $earliest->toIso8601String()], 422);
            }
        }

        $distance = null;
        $method   = $noPosition ? 'device' : 'gps';
        $overriddenBy = null;

        if (!$noPosition && $outlet->latitude !== null && $outlet->longitude !== null) {
            $distance = TimeEntry::haversineMeters(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                (float) $outlet->latitude,
                (float) $outlet->longitude,
            );

            $radius = $outlet->geofence_radius_meters;

            if ($radius !== null && $accuracy !== null && $accuracy > $radius) {
                $flags[] = 'accuracy_low';
            }

            $edgeAllowance = $radius !== null ? min($accuracy ?? 0, $radius) : 0;

            if ($radius !== null && $distance > $radius && $distance <= $radius + $edgeAllowance) {
                $flags[] = 'edge';
            } elseif ($radius !== null && $distance > $radius) {
                $canOverride = $user->can('attendance.manage') || $user->can('outlets.all_access');

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

        if ($validated['mock_location'] ?? false) {
            $flags[] = 'mock_location';
        }

        [$punchAt, $recordedAt, $late] = $this->punchTime($validated['recorded_at'] ?? null);
        if ($late) {
            $flags[] = 'sent_late';
        }

        $entry = TimeEntry::create([
            'user_id'                 => $user->id,
            'outlet_id'               => $outlet->id,
            'clock_in_at'              => $punchAt,
            'clock_in_recorded_at'     => $recordedAt,
            'clock_in_latitude'        => $validated['latitude'] ?? null,
            'clock_in_longitude'       => $validated['longitude'] ?? null,
            'clock_in_distance_meters' => $distance !== null ? round($distance, 2) : null,
            'clock_in_accuracy_meters' => $accuracy !== null ? round($accuracy, 2) : null,
            'clock_in_method'          => $method,
            'status'                   => 'active',
            'flags'                    => $flags ?: null,
            'overridden_by'            => $overriddenBy,
            'notes'                    => $validated['reason'] ?? null,
            'device_info'              => substr((string) $request->userAgent(), 0, 250),
            'device_id'                => $device?->id,
            'app_version'              => $validated['app_version'] ?? null,
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
            'message' => "Clocked in at {$outlet->name}." . ($flags ? ' Your manager will see a note on this punch.' : ''),
            'entry'   => $this->transformEntry($entry),
        ], 201);
    }

    /**
     * POST /api/v1/admin/time-clock/trail  { points: [{latitude, longitude, accuracy?, recorded_at, mocked?}] }
     *
     * Location on shift (People C1). The app sends points only while an entry
     * is active; the server keeps them for the retention period and decides
     * about leaving the site: further than fence + leave_site_distance for
     * longer than the grace period → flag the entry, or clock out at the
     * moment they left (rule leave_site_action). Coming back inside before
     * the grace is up clears the timer. A phone that goes quiet is not
     * "leaving": only points prove it.
     */
    public function trail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'points'               => 'required|array|min:1|max:200',
            'points.*.latitude'    => 'required|numeric|between:-90,90',
            'points.*.longitude'   => 'required|numeric|between:-180,180',
            'points.*.accuracy'    => 'nullable|numeric|min:0|max:100000',
            'points.*.recorded_at' => 'required|date',
            'points.*.mocked'      => 'sometimes|boolean',
        ]);
        $user  = $request->user();
        $rules = AttendanceRules::all();
        if ($rules['location_mode'] !== 'trail') {
            return response()->json(['message' => 'Trail is off.', 'tracking' => false]);
        }
        $entry = TimeEntry::with('outlet')->where('user_id', $user->id)->active()->first();
        if (!$entry) {
            return response()->json(['message' => 'Not clocked in; nothing kept.', 'tracking' => false]);
        }
        $outlet = $entry->outlet;
        $radius = $outlet?->geofence_radius_meters;
        $hasFence = $outlet && $outlet->latitude !== null && $outlet->longitude !== null && $radius !== null;
        $leaveBeyond = $hasFence ? $radius + (int) $rules['leave_site_distance_meters'] : null;
        $points = collect($validated['points'])->sortBy('recorded_at')->values();
        $kept = 0; $mocked = false; $leftAt = $entry->left_site_at; $closed = false;

        foreach ($points as $p) {
            $at = Carbon::parse($p['recorded_at']);
            if ($at->lt($entry->clock_in_at) || $at->gt(now()->addMinutes(5))) continue;
            $distance = $hasFence ? TimeEntry::haversineMeters((float) $p['latitude'], (float) $p['longitude'], (float) $outlet->latitude, (float) $outlet->longitude) : null;
            LocationPing::create([
                'time_entry_id' => $entry->id, 'user_id' => $user->id,
                'latitude' => $p['latitude'], 'longitude' => $p['longitude'],
                'accuracy_meters' => isset($p['accuracy']) ? (int) round($p['accuracy']) : null,
                'distance_meters' => $distance !== null ? (int) round($distance) : null,
                'mocked' => (bool) ($p['mocked'] ?? false), 'recorded_at' => $at,
            ]);
            $kept++;
            if (!empty($p['mocked'])) $mocked = true;
            if ($leaveBeyond === null || $rules['leave_site_action'] === 'none' || $closed) continue;

            // Wide uncertainty is not evidence of leaving.
            $sure = !isset($p['accuracy']) || (float) $p['accuracy'] <= $leaveBeyond;
            if ($distance > $leaveBeyond && $sure) {
                if ($leftAt === null) {
                    $leftAt = $at;
                    NotificationService::deliver($user, new InAppNotification(
                        title: 'Still clocked in?',
                        body:  "You look to have left {$outlet->name}. Clock out if you have finished; otherwise you will be " . ($rules['leave_site_action'] === 'clock_out' ? 'clocked out' : 'flagged') . " in {$rules['leave_site_grace_minutes']} minutes.",
                        actionUrl: '/me/clock', icon: 'clock',
                    ));
                } elseif ($leftAt->diffInMinutes($at) >= (int) $rules['leave_site_grace_minutes']) {
                    $flags = array_values(array_unique(array_merge($entry->flags ?? [], ['left_site'])));
                    if ($rules['leave_site_action'] === 'clock_out') {
                        $this->closeEntryAt($entry, $leftAt, array_merge($flags, ['auto_clock_out']));
                        $closed = true;
                        NotificationService::deliver($user, new InAppNotification(title: 'Clocked out', body: "You were clocked out at {$leftAt->format('H:i')}, when you left {$outlet->name}. Tell your manager if that is wrong.", actionUrl: '/me/timesheet', icon: 'clock'));
                    } else {
                        $entry->flags = $flags; $entry->flagged_reason = TimeEntry::describeFlags($flags);
                    }
                }
            } elseif ($distance !== null && $distance <= ($radius ?? 0) && $leftAt !== null) {
                $leftAt = null; // back inside before the grace ran out
            }
        }
        if ($mocked) {
            $entry->flags = array_values(array_unique(array_merge($entry->flags ?? [], ['mock_location'])));
            $entry->flagged_reason = TimeEntry::describeFlags($entry->flags);
        }
        if (!$closed) {
            $entry->left_site_at = $leftAt;
            $entry->trail_points = (int) $entry->trail_points + $kept;
            $entry->save();
        }

        return response()->json(['message' => "Kept {$kept}.", 'tracking' => !$closed, 'left_site_at' => $leftAt?->toIso8601String(), 'closed' => $closed]);
    }

    /** Close an active entry at a given moment with flags (leave-site clock-out, limits). */
    public function closeEntryAt(TimeEntry $entry, Carbon $at, array $flags): void
    {
        $breaks = $entry->breaks ?? [];
        foreach ($breaks as &$b) { if (!empty($b['started_at']) && empty($b['ended_at'])) $b['ended_at'] = $at->toIso8601String(); }
        unset($b);
        $entry->breaks = $breaks;
        $breakMinutes = $entry->calculateBreakMinutes();
        $flags = array_values(array_unique($flags));
        $entry->forceFill([
            'clock_out_at' => $at, 'total_break_minutes' => $breakMinutes,
            'worked_minutes' => max(0, (int) $entry->clock_in_at->diffInMinutes($at) - $breakMinutes),
            'flags' => $flags, 'flagged_reason' => TimeEntry::describeFlags($flags), 'status' => 'flagged', 'auto_closed_at' => now(),
            'left_site_at' => $entry->left_site_at,
        ])->save();
    }

    /** GET /api/v1/admin/attendance/entries/{id}/trail — the points of one entry, for the map (attendance.view_team). */
    public function entryTrail(Request $request, int $id): JsonResponse
    {
        $entry = TimeEntry::with('outlet:id,name,latitude,longitude,geofence_radius_meters')->findOrFail($id);
        $user = $request->user();
        if (!$user->can('outlets.all_access') && !DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $entry->outlet_id)->exists()) {
            abort(403);
        }
        $points = LocationPing::where('time_entry_id', $entry->id)->orderBy('recorded_at')->get(['latitude', 'longitude', 'accuracy_meters', 'distance_meters', 'mocked', 'recorded_at']);
        return response()->json([
            'outlet' => $entry->outlet ? ['name' => $entry->outlet->name, 'latitude' => $entry->outlet->latitude !== null ? (float) $entry->outlet->latitude : null, 'longitude' => $entry->outlet->longitude !== null ? (float) $entry->outlet->longitude : null, 'radius' => $entry->outlet->geofence_radius_meters] : null,
            'clock_in' => $entry->clock_in_latitude !== null ? ['latitude' => (float) $entry->clock_in_latitude, 'longitude' => (float) $entry->clock_in_longitude, 'at' => $entry->clock_in_at?->toIso8601String()] : null,
            'clock_out' => $entry->clock_out_latitude !== null ? ['latitude' => (float) $entry->clock_out_latitude, 'longitude' => (float) $entry->clock_out_longitude, 'at' => $entry->clock_out_at?->toIso8601String()] : null,
            'left_site_at' => $entry->left_site_at?->toIso8601String(),
            'points' => $points->map(fn ($p) => ['latitude' => (float) $p->latitude, 'longitude' => (float) $p->longitude, 'accuracy' => $p->accuracy_meters, 'distance' => $p->distance_meters, 'mocked' => $p->mocked, 'at' => $p->recorded_at->toIso8601String()])->values(),
            'retention_days' => (int) AttendanceRules::get('trail_retention_days'),
        ]);
    }

    /**
     * POST /api/v1/admin/time-clock/clock-out
     * { latitude, longitude, accuracy?, recorded_at?, mock_location? }
     * Clocking out is never refused by distance: leaving is not a privilege.
     * The fix is stored for the record and the same flags apply.
     */
    public function clockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'accuracy'      => 'nullable|numeric|min:0|max:100000',
            'recorded_at'   => 'nullable|date',
            'mock_location' => 'sometimes|boolean',
        ]);

        $user  = $request->user();
        $entry = TimeEntry::where('user_id', $user->id)->active()->first();

        if (!$entry) {
            return response()->json(['message' => 'You are not currently clocked in.'], 404);
        }
        $noPosition = !isset($validated['latitude']) || !isset($validated['longitude']);
        if ($noPosition && !(app(Devices::class)->knownTo($user, $request)?->location_free)) {
            return response()->json(['message' => 'Your position is needed to clock out. Allow location and try again.'], 422);
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
        if (!$noPosition && $outlet && $outlet->latitude !== null && $outlet->longitude !== null) {
            $distance = TimeEntry::haversineMeters(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                (float) $outlet->latitude,
                (float) $outlet->longitude,
            );
        }

        [$clockOutAt, $recordedAt, $late] = $this->punchTime($validated['recorded_at'] ?? null, $entry->clock_in_at);
        $flags = $entry->flags ?? [];
        if ($late) $flags[] = 'sent_late';
        if ($validated['mock_location'] ?? false) $flags[] = 'mock_location';
        $radius = $outlet?->geofence_radius_meters;
        if ($radius !== null && isset($validated['accuracy']) && (float) $validated['accuracy'] > $radius) $flags[] = 'accuracy_low';
        $flags = array_values(array_unique($flags));

        $breakMinutes = 0;
        foreach ($breaks as $break) {
            if (!empty($break['started_at']) && !empty($break['ended_at'])) {
                $breakMinutes += (int) Carbon::parse($break['started_at'])->diffInMinutes(Carbon::parse($break['ended_at']));
            }
        }
        $workedMinutes = max(0, (int) $entry->clock_in_at->diffInMinutes($clockOutAt) - $breakMinutes);

        $entry->update([
            'clock_out_at'              => $clockOutAt,
            'clock_out_recorded_at'     => $recordedAt,
            'clock_out_latitude'        => $validated['latitude'] ?? null,
            'clock_out_longitude'       => $validated['longitude'] ?? null,
            'clock_out_distance_meters' => $distance !== null ? round($distance, 2) : null,
            'clock_out_accuracy_meters' => isset($validated['accuracy']) ? round((float) $validated['accuracy'], 2) : null,
            'breaks'                    => $breaks,
            'total_break_minutes'       => $breakMinutes,
            'worked_minutes'            => $workedMinutes,
            'flags'                     => $flags ?: null,
            'flagged_reason'            => TimeEntry::describeFlags($flags),
            'status'                    => $flags ? 'flagged' : 'completed',
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
        if (!$user->can('outlets.all_access')) {
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

        if (!$user->can('outlets.all_access')) {
            $managedOutletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
            $query->whereIn('outlet_id', $managedOutletIds);
        }

        $entries = $query->limit(50)->get();

        return response()->json(['data' => $entries->map(fn ($e) => $this->transformEntry($e, withUser: true))]);
    }

    /**
     * GET /api/v1/admin/attendance/live
     * Who is in right now, for the outlets the caller manages: every open
     * entry with how long, on break or not, and any flags on the punch.
     */
    public function live(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = TimeEntry::with(['user:id,first_name,last_name,avatar_url', 'outlet:id,name,outlet_type'])
            ->active()
            ->orderBy('clock_in_at');

        if (!$user->can('outlets.all_access')) {
            $managedOutletIds = DB::table('outlet_user')->where('user_id', $user->id)->pluck('outlet_id');
            $query->whereIn('outlet_id', $managedOutletIds);
        }

        $entries = $query->get();

        return response()->json([
            'data'        => $entries->map(fn ($e) => $this->transformEntry($e, withUser: true)),
            'count'       => $entries->count(),
            'on_break'    => $entries->filter(fn ($e) => $e->hasOpenBreak())->count(),
            'flagged'     => $entries->filter(fn ($e) => !empty($e->flags))->count(),
            'server_time' => now()->toIso8601String(),
        ]);
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
        if (isset($validated['status'])) {
            $entry->status = $validated['status'];
            if ($validated['status'] === 'completed') {
                // The manager has looked: the reasons stay in the audit trail
                // (activity_log keeps the before image) but no longer flag.
                $entry->flags = null;
                $entry->flagged_reason = null;
            }
        }
        if (array_key_exists('notes', $validated)) $entry->notes    = $validated['notes'];

        // Recompute worked time if either timestamp moved and we now have both ends.
        if ($entry->clock_in_at && $entry->clock_out_at) {
            $breakMinutes        = $entry->calculateBreakMinutes();
            $entry->total_break_minutes = $breakMinutes;
            $entry->worked_minutes      = max(0, (int) $entry->clock_in_at->diffInMinutes($entry->clock_out_at) - $breakMinutes);
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
        if ($user->can('outlets.all_access')) {
            return;
        }
        $managed = DB::table('outlet_user')->where('user_id', $user->id)->where('outlet_id', $outletId)->exists();
        abort_if(!$managed, 403, 'You do not manage this outlet.');
    }

    /**
     * The moment a punch counts from. A punch sent live counts from now. A
     * punch the phone made offline carries its own time; that time is used,
     * bounded to [now − late_sync_max_hours, now] (and never before the
     * clock-in it closes), and the entry is flagged "sent_late" when the two
     * clocks differ by more than late_sync_minutes.
     *
     * @return array{0: Carbon, 1: ?Carbon, 2: bool} [punch time, phone time as sent, late?]
     */
    private function punchTime(?string $recordedAt, ?Carbon $notBefore = null): array
    {
        $now = now();
        if ($recordedAt === null) {
            return [$now, null, false];
        }
        $sent  = Carbon::parse($recordedAt)->setTimezone(config('app.timezone'));
        $floor = $now->copy()->subHours((int) config('attendance.late_sync_max_hours'));
        if ($notBefore && $notBefore->gt($floor)) {
            $floor = $notBefore->copy();
        }
        $punch = $sent->lt($floor) ? $floor : ($sent->gt($now) ? $now->copy() : $sent->copy());
        $late  = abs($now->diffInMinutes($sent, false)) > (int) config('attendance.late_sync_minutes');

        return [$punch, $sent, $late];
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
            'flags'                   => $entry->flags ?? [],
            'flag_labels'             => array_values(array_filter(array_map(fn ($f) => TimeEntry::FLAG_LABELS[$f] ?? null, $entry->flags ?? []))),
            'clock_in_accuracy_meters'=> $entry->clock_in_accuracy_meters,
            'auto_closed_at'          => $entry->auto_closed_at?->toIso8601String(),
            'left_site_at'            => $entry->left_site_at?->toIso8601String(),
            'trail_points'            => (int) $entry->trail_points,
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
            $data['clock_out_accuracy_meters']   = $entry->clock_out_accuracy_meters;
            $data['clock_in_recorded_at']        = $entry->clock_in_recorded_at?->toIso8601String();
            $data['clock_out_recorded_at']       = $entry->clock_out_recorded_at?->toIso8601String();
            $data['device_info']                 = $entry->device_info;
            $data['app_version']                 = $entry->app_version;
            $data['overridden_by']               = $entry->overriddenBy ? trim("{$entry->overriddenBy->first_name} {$entry->overriddenBy->last_name}") : null;
            $data['corrected_by']                = $entry->correctedBy ? trim("{$entry->correctedBy->first_name} {$entry->correctedBy->last_name}") : null;
        }

        return $data;
    }
}
