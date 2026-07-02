<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * GET /api/v1/admin/activity-logs
     * Paginated activity log with filters.
     */
    public function index(Request $request)
    {
        $query = DB::table('activity_log')
            ->leftJoin('users', 'activity_log.causer_id', '=', 'users.id')
            ->select(
                'activity_log.*',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as user_name"),
                'users.email as user_email'
            );

        if ($request->filled('user_id')) {
            $query->where('activity_log.causer_id', $request->user_id);
        }

        if ($request->filled('action')) {
            $query->where('activity_log.action', $request->action);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('activity_log.created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('activity_log.created_at', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('activity_log.description', 'ILIKE', "%{$search}%")
                  ->orWhere('activity_log.action',    'ILIKE', "%{$search}%")
                  ->orWhere('users.email',             'ILIKE', "%{$search}%")
                  ->orWhere('users.first_name',        'ILIKE', "%{$search}%")
                  ->orWhere('users.last_name',         'ILIKE', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $logs    = $query->orderBy('activity_log.created_at', 'desc')->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * GET /api/v1/admin/activity-logs/{id}
     */
    public function show($id)
    {
        $log = DB::table('activity_log')
            ->leftJoin('users', 'activity_log.causer_id', '=', 'users.id')
            ->where('activity_log.id', $id)
            ->select(
                'activity_log.*',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as user_name"),
                'users.email as user_email'
            )
            ->first();

        if (!$log) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['log' => $log]);
    }

    /**
     * POST /api/v1/admin/activity-logs/clear
     * Delete logs older than N days.
     */
    public function clear(Request $request)
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:30',
        ]);

        try {
            $deleted = DB::table('activity_log')
                ->where('created_at', '<', now()->subDays($validated['days']))
                ->delete();

            // Log the clear action itself
            DB::table('activity_log')->insert([
                'user_id'     => $request->user()->id,
                'action'      => 'logs_cleared',
                'description' => "Cleared {$deleted} log entries older than {$validated['days']} days",
                'ip_address'  => $request->ip(),
                'created_at'  => now(),
            ]);

            return response()->json([
                'message'       => "Deleted {$deleted} log entries older than {$validated['days']} days.",
                'deleted_count' => $deleted,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to clear logs.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/admin/activity-logs/export
     * Export logs as JSON for CSV download on the frontend.
     */
    public function export(Request $request)
    {
        $query = DB::table('activity_log')
            ->leftJoin('users', 'activity_log.causer_id', '=', 'users.id')
            ->select(
                'activity_log.id',
                'activity_log.action',
                'activity_log.description',
                'activity_log.ip_address',
                'activity_log.created_at',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as user_name"),
                'users.email as user_email'
            );

        if ($request->filled('start_date')) $query->whereDate('activity_log.created_at', '>=', $request->start_date);
        if ($request->filled('end_date'))   $query->whereDate('activity_log.created_at', '<=', $request->end_date);
        if ($request->filled('action'))     $query->where('activity_log.action', $request->action);

        $logs = $query->orderBy('activity_log.created_at', 'desc')->limit(5000)->get();

        return response()->json([
            'data'  => $logs,
            'count' => $logs->count(),
        ]);
    }
}