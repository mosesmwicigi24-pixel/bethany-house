<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Serves the React admin dashboard endpoint.
 *
 * Route: GET /api/v1/admin/dashboard
 * Auth:  auth:sanctum + role:super_admin|admin
 *
 * Phase 7 additions:
 *   - Production queue stats (draft, pending, in_progress, qc_pending, overdue)
 *   - Pending payment approvals count (Phase 5)
 *   - Shipments in transit / pending dispatch (Phase 3)
 *   - Actionable alerts surfaced at the top of the dashboard
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'stats'           => $this->buildStats($request),
            'recent_activity' => $this->buildRecentActivity($request),
            'alerts'          => $this->buildAlerts($request),
        ]);
    }

    /**
     * GET /api/v1/admin/sidebar-badges
     *
     * Returns actionable counts for sidebar nav badges.
     * Each key maps to a nav item. Values are integers (0 = no badge shown).
     */
    public function sidebarBadges(Request $request): \Illuminate\Http\JsonResponse
    {
        $user   = $request->user();
        $badges = [];

        try { $badges['orders'] = Order::whereIn('status', ['pending', 'processing'])->count(); }
        catch (\Exception) { $badges['orders'] = 0; }

        try {
            $badges['production_orders'] = DB::table('production_orders')->where('status', 'pending')->count();
        } catch (\Exception) { $badges['production_orders'] = 0; }

        try {
            $badges['production_qc'] = DB::table('production_orders')->where('status', 'qc_pending')->count();
        } catch (\Exception) { $badges['production_qc'] = 0; }

        try {
            $badges['my_tasks'] = DB::table('production_tasks')
                ->where('assigned_to', $user->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->count();
        } catch (\Exception) { $badges['my_tasks'] = 0; }

        try {
            $badges['stock_adjustments'] = DB::table('inventory_transactions')
                ->where('type', 'adjustment')
                ->where('status', 'pending_approval')
                ->count();
        } catch (\Exception) { $badges['stock_adjustments'] = 0; }

        try {
            $badges['stock_transfers'] = DB::table('inventory_transfers')->where('status', 'pending')->count();
        } catch (\Exception) { $badges['stock_transfers'] = 0; }

        try {
            $badges['low_stock'] = DB::table('inventories')
                ->whereColumn('quantity', '<=', 'low_stock_threshold')
                ->where('low_stock_threshold', '>', 0)
                ->count();
        } catch (\Exception) { $badges['low_stock'] = 0; }

        try {
            $badges['purchase_orders'] = DB::table('purchase_orders')->where('status', 'pending_approval')->count();
        } catch (\Exception) { $badges['purchase_orders'] = 0; }

        try {
            $badges['purchase_returns'] = DB::table('purchase_returns')->where('status', 'pending')->count();
        } catch (\Exception) { $badges['purchase_returns'] = 0; }

        try {
            $badges['approvals'] = DB::table('payments')
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review')
                ->count();
        } catch (\Exception) { $badges['approvals'] = 0; }

        try {
            $badges['returns'] = DB::table('order_returns')->where('status', 'pending')->count();
        } catch (\Exception) { $badges['returns'] = 0; }

        return response()->json($badges);
    }

    private function buildStats(Request $request): array
    {
        $stats = [
            'total_users'  => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'staff_users'  => User::staffUsers()->count(),
            'customers'    => User::customers()->count(),
        ];

        try {
            $stats['total_orders']   = Order::count();
            $stats['pending_orders'] = Order::whereIn('status', ['pending', 'processing'])->count();
            $stats['today_orders']   = Order::whereDate('created_at', today())->count();
            $stats['today_sales']    = Order::whereDate('created_at', today())
                ->where('payment_status', 'paid')
                ->sum('total_amount');
            // Phase 5
            $stats['pending_payment_approvals'] = DB::table('payments')
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review')
                ->count();
        } catch (\Exception) {}

        try {
            $stats['total_products']     = Product::where('status', 'active')->count();
            $stats['low_stock_products'] = DB::table('inventories')
                ->whereColumn('quantity', '<=', 'low_stock_threshold')
                ->where('low_stock_threshold', '>', 0)
                ->count();
        } catch (\Exception) {}

        // Phase 3 - shipment tracking
        try {
            $stats['shipments_in_transit'] = DB::table('order_shipments')
                ->whereIn('status', ['picked_up', 'in_transit', 'out_for_delivery'])
                ->count();
            $stats['shipments_pending_dispatch'] = DB::table('order_shipments')
                ->whereIn('status', ['order_confirmed', 'processing', 'ready_to_ship'])
                ->count();
        } catch (\Exception) {}

        // Phase 4 - production queue
        try {
            $stats['production_draft']       = DB::table('production_orders')->where('status', 'draft')->count();
            $stats['production_queue']       = DB::table('production_orders')->where('status', 'pending')->count();
            $stats['production_in_progress'] = DB::table('production_orders')->where('status', 'in_progress')->count();
            $stats['production_qc_pending']  = DB::table('production_orders')->where('status', 'qc_pending')->count();
            $stats['production_overdue']     = DB::table('production_orders')
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled', 'draft'])
                ->count();
        } catch (\Exception) {}

        // Phase 6 - unread notifications
        try {
            $user = $request->user();
            $stats['unread_notifications'] = DB::table('notifications')
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->whereNull('read_at')
                ->count();
        } catch (\Exception) {}

        return $stats;
    }

    /**
     * Actionable alerts scoped to the authenticated user's role.
     *
     * - Payment approval alerts  → admins and outlet managers only
     * - Overdue production alerts → admins, managers, and tailors (tailors
     *   only see orders they are personally assigned to)
     * - Low stock alerts          → admins, managers, and procurement officers
     */
    private function buildAlerts(Request $request): array
    {
        $alerts = [];
        $user   = $request->user();
        $roles  = $this->getUserRoles($user->id);

        $isAdmin       = in_array('admin', $roles) || in_array('super_admin', $roles);
        $isTailor      = in_array('tailor', $roles);
        $isClerk       = in_array('pos_clerk', $roles);
        $isManager     = in_array('outlet_manager', $roles);
        $isProcurement = in_array('procurement_officer', $roles);

        // Payment approvals — admins and outlet managers only
        if ($isAdmin || $isManager) {
            try {
                $n = DB::table('payments')
                    ->where('requires_approval', true)
                    ->where('approval_status', 'pending_review')
                    ->count();
                if ($n > 0) $alerts[] = [
                    'type'         => 'warning',
                    'icon'         => 'payment',
                    'message'      => "{$n} international payment" . ($n !== 1 ? 's' : '') . " awaiting approval",
                    'action_url'   => '/approvals',
                    'action_label' => 'Review now',
                ];
            } catch (\Exception) {}
        }

        // Overdue production orders — admins, managers, and tailors
        if ($isAdmin || $isManager || $isTailor) {
            try {
                $overdueQuery = DB::table('production_orders')
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled', 'draft']);

                // Tailors only see orders they are assigned to
                if ($isTailor && !$isAdmin) {
                    $overdueQuery->whereExists(function ($q) use ($user) {
                        $q->select(DB::raw(1))
                          ->from('production_tasks')
                          ->whereColumn('production_tasks.production_order_id', 'production_orders.id')
                          ->where('production_tasks.assigned_to', $user->id);
                    });
                }

                $n = $overdueQuery->count();
                if ($n > 0) $alerts[] = [
                    'type'         => 'danger',
                    'icon'         => 'production',
                    'message'      => "{$n} production order" . ($n !== 1 ? 's are' : ' is') . " overdue",
                    'action_url'   => $isTailor ? '/production/my-tasks' : '/production',
                    'action_label' => 'View now',
                ];
            } catch (\Exception) {}
        }

        // Low stock — admins, managers, and procurement officers
        if ($isAdmin || $isManager || $isProcurement) {
            try {
                $n = DB::table('inventories')
                    ->whereColumn('quantity', '<=', 'low_stock_threshold')
                    ->where('low_stock_threshold', '>', 0)
                    ->count();
                if ($n > 0) $alerts[] = [
                    'type'         => 'warning',
                    'icon'         => 'stock',
                    'message'      => "{$n} product" . ($n !== 1 ? 's are' : ' is') . " running low on stock",
                    'action_url'   => '/inventory/low-stock',
                    'action_label' => 'View alerts',
                ];
            } catch (\Exception) {}
        }

        return $alerts;
    }

    /**
     * Recent activity feed.
     *
     * Super admins see all system activity.
     * Everyone else sees only their own actions (causer_id = current user).
     *
     * NOTE: queries the `activity_log` table written by ActivityLogService
     * (Spatie schema: causer_id, event, description) — not the AuditLog model.
     */
    private function buildRecentActivity(Request $request): array
    {
        try {
            $user        = $request->user();
            $roles       = $this->getUserRoles($user->id);
            $isSuperAdmin = in_array('super_admin', $roles);

            $query = DB::table('activity_log as al')
                ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
                ->select(
                    'al.event',
                    'al.description',
                    'al.created_at',
                    DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name")
                )
                ->orderBy('al.created_at', 'desc')
                ->limit(6);

            if (!$isSuperAdmin) {
                $query->where('al.causer_id', $user->id);
            }

            return $query->get()->map(fn ($log) => [
                'type'        => $log->event ?? 'activity',
                'description' => $log->description ?? ucfirst(str_replace('_', ' ', $log->event ?? 'Activity')),
                'user'        => $log->actor_name ?? 'System',
                'time'        => \Carbon\Carbon::parse($log->created_at)->diffForHumans(),
            ])->toArray();

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('DashboardController::buildRecentActivity failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve role names for a user directly from the pivot table.
     * Avoids Spatie's guard mismatch (sanctum vs web) which returns empty results.
     */
    private function getUserRoles(int $userId): array
    {
        try {
            return DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $userId)
                ->pluck('roles.name')
                ->toArray();
        } catch (\Exception) {
            return [];
        }
    }
}