<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Support\AttendanceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Setup › Attendance rules (People C1). Read with attendance.view_team; write with settings.edit. */
class AttendanceRulesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['rules' => AttendanceRules::all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'working_days'               => 'sometimes|array|min:1|max:7', 'working_days.*' => 'integer|between:1,7',
            'auto_clock_out_time'        => 'sometimes|date_format:H:i',
            'auto_clock_out_after_hours' => 'sometimes|integer|between:0,24',
            'daily_limit_hours'          => 'sometimes|integer|between:0,24',
            'over_scheduled_minutes'     => 'sometimes|integer|between:0,600',
            'under_scheduled_minutes'    => 'sometimes|integer|between:0,600',
            'clock_in_without_shift'     => 'sometimes|in:allow,flag,refuse',
            'earliest_clock_in_minutes'  => 'sometimes|integer|between:0,240',
            'remind_clock_in'            => 'sometimes|boolean',
            'remind_clock_out'           => 'sometimes|boolean',
            'location_mode'              => 'sometimes|in:off,punch,trail',
            'trail_interval_minutes'     => 'sometimes|integer|between:1,60',
            'trail_distance_meters'      => 'sometimes|integer|between:10,2000',
            'leave_site_action'          => 'sometimes|in:none,flag,clock_out',
            'leave_site_grace_minutes'   => 'sometimes|integer|between:1,120',
            'leave_site_distance_meters' => 'sometimes|integer|between:0,5000',
            'trail_retention_days'       => 'sometimes|integer|between:7,365',
        ]);
        $before = AttendanceRules::all();
        AttendanceRules::save($v);
        $after = AttendanceRules::all();
        $changed = array_filter($v, fn ($val, $k) => ($before[$k] ?? null) !== ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        ActivityLogService::log('attendance_rules_saved', null, ['changed' => array_keys($changed), 'before' => array_intersect_key($before, $changed), 'after' => array_intersect_key($after, $changed)],
            "{$request->user()->first_name} changed attendance rules: " . (implode(', ', array_keys($changed)) ?: 'nothing'));

        return response()->json(['message' => $changed ? 'Rules saved.' : 'Nothing changed.', 'rules' => $after]);
    }
}
