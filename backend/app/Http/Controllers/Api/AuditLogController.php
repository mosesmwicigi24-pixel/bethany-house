<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ActivityLogService;
use App\Services\Audit\AuditSealer;

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
     *
     * Used to delete entries older than N days. The audit trail is append-only
     * now — the database refuses the DELETE — so this answers 403 and records
     * that someone asked, which is itself worth knowing.
     */
    public function clear(Request $request)
    {
        ActivityLogService::log('audit_clear_refused', null, [
            'requested_days' => $request->input('days'),
        ], 'Refused a request to clear the audit trail (it is append-only)', $request->user());

        return response()->json([
            'message' => 'The activity log is a permanent record and cannot be cleared.',
        ], 403);
    }

    /**
     * GET /api/v1/admin/activity-logs/requests
     * Staff API calls (request_logs): who looked at what, when, from where.
     * Filters: user_id, method, status, path (contains), start_date, end_date,
     * min_rows (list responses carrying at least N records).
     */
    public function requests(Request $request)
    {
        $q = DB::table('request_logs')
            ->leftJoin('users', 'request_logs.user_id', '=', 'users.id')
            ->select('request_logs.*',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as user_name"),
                'users.email as user_email');

        if ($request->filled('user_id'))    $q->where('request_logs.user_id', (int) $request->input('user_id'));
        if ($request->filled('method'))     $q->where('request_logs.method', strtoupper((string) $request->input('method')));
        if ($request->filled('status'))     $q->where('request_logs.status', (int) $request->input('status'));
        if ($request->filled('path'))       $q->where('request_logs.path', 'ILIKE', '%' . $request->input('path') . '%');
        if ($request->filled('start_date')) $q->whereDate('request_logs.occurred_at', '>=', $request->input('start_date'));
        if ($request->filled('end_date'))   $q->whereDate('request_logs.occurred_at', '<=', $request->end_date);
        if ($request->filled('min_rows'))   $q->where('request_logs.rows_returned', '>=', (int) $request->min_rows);

        $perPage = min((int) $request->get('per_page', 50), 100);

        return response()->json($q->orderByDesc('request_logs.occurred_at')->paginate($perPage));
    }

    /**
     * GET /api/v1/admin/activity-logs/record/{type}/{id}
     * The full history of one record — every change with before and after.
     * {type} is a short model name from config('audit.observed_models')
     * ("order", "product", "customer", "product-price" …); anything else 404s,
     * so the endpoint cannot be pointed at arbitrary classes.
     */
    public function record(Request $request, string $type, int $id)
    {
        $class = $this->observedTypes()[strtolower($type)] ?? null;
        if (!$class) {
            return response()->json(['message' => 'Unknown record type.'], 404);
        }

        $logs = DB::table('activity_log')
            ->leftJoin('users', 'activity_log.causer_id', '=', 'users.id')
            ->where('activity_log.subject_type', $class)
            ->where('activity_log.subject_id', $id)
            ->select('activity_log.*',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as user_name"),
                'users.email as user_email')
            ->orderByDesc('activity_log.created_at')
            ->orderByDesc('activity_log.id')
            ->paginate(min((int) $request->get('per_page', 50), 100));

        return response()->json($logs);
    }

    /**
     * GET /api/v1/admin/activity-logs/integrity
     * The newest seal per table, how many rows await the next seal, and the
     * result of the last verification run.
     */
    public function integrity(AuditSealer $sealer)
    {
        $tables = [];
        foreach (array_keys(AuditSealer::TABLES) as $table) {
            $seal = $sealer->lastSeal($table);
            $tables[$table] = [
                'last_seal' => $seal ? [
                    'last_id'   => (int) $seal->last_id,
                    'row_count' => (int) $seal->row_count,
                    'hash'      => $seal->hash,
                    'sealed_at' => $seal->sealed_at,
                ] : null,
                'unsealed_rows' => DB::table($table)->where('id', '>', (int) ($seal->last_id ?? 0))->count(),
            ];
        }

        $lastCheck = DB::table('activity_log')
            ->whereIn('event', ['audit_verified', 'audit_verification_failed'])
            ->orderByDesc('id')->first(['event', 'created_at', 'properties']);

        return response()->json([
            'tables'     => $tables,
            'last_check' => $lastCheck ? [
                'ok'         => $lastCheck->event === 'audit_verified',
                'checked_at' => $lastCheck->created_at,
                'details'    => json_decode($lastCheck->properties ?? 'null', true),
            ] : null,
        ]);
    }

    /** "order" / "product-price" / "productprice" => FQCN, from the observed list only. */
    private function observedTypes(): array
    {
        $map = [];
        foreach ((array) config('audit.observed_models', []) as $class) {
            $base = class_basename($class);
            $map[strtolower($base)] = $class;
            $map[\Illuminate\Support\Str::kebab($base)] = $class;
        }
        return $map;
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