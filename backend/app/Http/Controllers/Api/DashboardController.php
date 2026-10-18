<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Enums\DataScope;
use App\Services\DataScopeResolver;
use App\Support\ReportPages;
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
    use \App\Http\Controllers\Api\Concerns\ConstrainsRawQueriesToViewer;

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

        // Platform-only accounts get no business counts (4D).
        if (self::isPlatformOnly($user)) {
            return response()->json($badges);
        }

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
            $badges['low_stock'] = $this->lowStockCount($request->user());
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
        // today_sales used to be every paid order in the business, which is why
        // it was put behind reports.view — since Phase 3A the Sales & Orders
        // report (reports.sales), the page those takings come from. That is
        // still the right lock for a caller who would see the GROUP's
        // takings — a tailor, say.
        //
        // But Order now carries a viewer scope, so for a narrowed role the same
        // sum is their OWN takings, which is exactly what a cashier should see
        // on their own dashboard. Withholding it there left the card rendering
        // blank, which reads as a broken till rather than a deliberate boundary.
        //
        // So: show money to anyone who may read reports, OR to anyone whose
        // view is already narrower than the whole business.
        $user        = $request->user();
        $scope       = DataScopeResolver::for($user, 'orders.view');
        // None (no role grants orders.view) is not "a narrower view" — it is no view.
        $maySeeMoney = ($user?->can('reports.sales') ?? false) || in_array($scope, [DataScope::Own, DataScope::Outlet], true);

        $stats = [
            // What the figures below are counted over, so the UI can say "your
            // sales" rather than "total sales" without guessing.
            'scope' => $scope->value,
            'total_users'  => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'staff_users'  => User::staffUsers()->count(),
        ];

        // The system administrator runs the platform, not the business (4D):
        // accounts and their own notifications — no order, product, stock,
        // shipment, production, customer or money counts.
        if (self::isPlatformOnly($user)) {
            $stats['platform_only'] = true;
            $stats['unread_notifications'] = $this->unreadNotifications($user);
            return $stats;
        }

        $stats['platform_only'] = false;
        // The Customers list (customers table), not accounts with a customer
        // login: the tile read 1 beside a list of hundreds.
        $stats['customers']     = \App\Models\Customer::count();

        // The Home figures on the money rules (currency plan Step 4, owner
        // 2026-10-18), every one on the NAIROBI business date:
        //   today_orders   confirmed-or-paid orders made today (Order::recognised,
        //                  the Sales report's rule) — a cart is not an order yet;
        //   carts_waiting  unconfirmed, unpaid carts, any age — never a sale;
        //   today_sales    those orders' totals in KES at each order's own
        //                  reporting rate (sales truth, by order date);
        //   today_collected settled money that arrived today, net of refunds,
        //                  in KES (money truth) — so a balance paid today shows today;
        //   cash_today     the cash part of that, per currency, from the server.
        // Scope is the viewer's own (Order's viewer scope; payments through
        // their order), exactly as before.
        $tz       = config('app.timezone');
        $dayStart = now($tz)->startOfDay();
        $dayEnd   = now($tz)->endOfDay();
        $stats['business_date'] = $dayStart->toDateString();

        try {
            $todayOrders = fn () => Order::query()->recognised()->whereBetween('orders.created_at', [$dayStart, $dayEnd]);

            $stats['total_orders']       = Order::count();
            $stats['today_orders']       = $todayOrders()->count();
            $stats['carts_waiting']      = Order::query()->pipeline()->count();
            $stats['in_progress_orders'] = Order::whereIn('status', ['confirmed', 'processing'])->count();
            // Kept for readers of the old key: it meant "pending or processing"
            // and mixed carts with real work; it now means work in progress.
            $stats['pending_orders']     = $stats['in_progress_orders'];

            if ($maySeeMoney) {
                $kes = \App\Support\ReportingCurrency::kes('orders.total_amount', 'orders.currency_code');

                $stats['today_sales'] = round((float) $todayOrders()
                    ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('orders.currency_code'))
                    ->selectRaw("COALESCE(SUM({$kes}), 0) AS v")->value('v'), 2);
                $stats['today_sales_by_currency'] = $todayOrders()
                    ->groupByRaw('UPPER(orders.currency_code)')
                    ->selectRaw("UPPER(orders.currency_code) AS currency_code, COUNT(*) AS orders, SUM(orders.total_amount) AS amount, SUM({$kes}) AS amount_kes")
                    ->orderBy('currency_code')
                    ->get()
                    ->map(fn ($r) => [
                        'currency_code' => $r->currency_code,
                        'orders'        => (int) $r->orders,
                        'amount'        => round((float) $r->amount, 2),
                        'amount_kes'    => $r->amount_kes === null ? null : round((float) $r->amount_kes, 2),
                    ])->values()->all();
                $stats['today_sales_basis'] = 'Confirmed or paid orders by order date, in KES at the reporting rate';

                $settledToday = function () use ($user, $dayStart, $dayEnd) {
                    $q = DB::table('payments as p')
                        ->join('orders as o', 'o.id', '=', 'p.order_id')
                        ->tap(fn ($q) => \App\Support\SettledPayment::where($q, 'p'))
                        ->whereBetween(DB::raw('COALESCE(p.paid_at, p.created_at)'), [$dayStart, $dayEnd]);
                    $this->constrainToViewer($q, $user, 'o');

                    return $q;
                };
                $payKes = \App\Support\ReportingCurrency::kes('p.amount - COALESCE(p.refund_amount, 0)', 'p.currency_code');

                $stats['today_collected'] = round((float) $settledToday()
                    ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('p.currency_code'))
                    ->selectRaw("COALESCE(SUM({$payKes}), 0) AS v")->value('v'), 2);

                $cashCodes = DB::table('payment_methods')->where('type', 'cash')->pluck('code')
                    ->push('cash')->map(fn ($c) => strtolower($c))->unique()->values()->all();
                $cash = $settledToday()
                    ->whereIn(DB::raw('LOWER(p.payment_method)'), $cashCodes)
                    ->groupByRaw('UPPER(p.currency_code)')
                    ->selectRaw("UPPER(p.currency_code) AS currency_code, COUNT(*) AS payments, SUM(p.amount - COALESCE(p.refund_amount, 0)) AS amount, SUM({$payKes}) AS amount_kes")
                    ->orderBy('currency_code')
                    ->get();
                $stats['cash_today'] = [
                    'kes_total'   => round((float) $cash->sum(fn ($r) => (float) ($r->amount_kes ?? 0)), 2),
                    'unconverted' => $cash->filter(fn ($r) => $r->amount_kes === null)->pluck('currency_code')->values()->all(),
                    'by_currency' => $cash->map(fn ($r) => [
                        'currency_code' => $r->currency_code,
                        'payments'      => (int) $r->payments,
                        'amount'        => round((float) $r->amount, 2),
                        'amount_kes'    => $r->amount_kes === null ? null : round((float) $r->amount_kes, 2),
                    ])->values()->all(),
                ];

                // Phase 5
                $stats['pending_payment_approvals'] = $this->pendingApprovalCount($user);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('DashboardController::buildStats money failed: ' . $e->getMessage());
        }

        // Open purchase orders: the real count and KES value (the tile used to
        // read a key no endpoint returned, so it always showed "—").
        if ($user?->can('procurement.view')) {
            try {
                $row = DB::table('purchase_orders')
                    ->whereNotIn('status', ['received', 'completed', 'closed', 'cancelled'])
                    ->selectRaw('COUNT(*) AS n, COALESCE(SUM(' . \App\Support\ReportingCurrency::kes('total_amount', 'currency_code') . '), 0) AS kes')
                    ->first();
                $stats['open_pos'] = ['count' => (int) ($row->n ?? 0), 'kes_value' => round((float) ($row->kes ?? 0), 2)];
            } catch (\Exception) {}
        }

        try {
            $stats['total_products']     = Product::where('status', 'active')->count();
            $stats['low_stock_products'] = $this->lowStockCount($user);
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

        // Phase 4 - production queue. Counted over the orders this viewer can
        // SEE (ProductionOrder::visibleTo, the rule the Production Orders list
        // uses): the whole floor for those who run it, their outlets for an
        // outlet manager, their own work for a tailor. These were raw
        // shop-wide counts, so a tailor's Home read "3 active, 2 overdue"
        // beside a list with 2 and 1 (Tailor View Cycle 2, F5/B26).
        try {
            $user    = $request->user();
            $visible = fn () => \App\Models\ProductionOrder::query()->visibleTo($user);

            $stats['production_draft']       = $visible()->where('status', 'draft')->count();
            $stats['production_queue']       = $visible()->where('status', 'pending')->count();
            $stats['production_in_progress'] = $visible()->where('status', 'in_progress')->count();
            $stats['production_qc_pending']  = $visible()->where('status', 'qc_pending')->count();
            $stats['production_overdue']     = $visible()
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
     * A caller who holds no business-domain permission at all — the system
     * administrator in the Phase 2 catalogue (accounts, outlets, roles,
     * attendance, technical setup). Decided by permission, not role name, so
     * a role edited the same way gets the same platform-only dashboard.
     * Any report page counts as business (one permission per page since
     * Phase 3A — see ReportPages; reports.view is retired).
     */
    private const BUSINESS_PERMISSIONS = [
        'orders.view', 'products.view', 'inventory.view', 'production.view', 'shipment.view',
        'payments.view', 'customers.view', 'procurement.view', 'expenses.view', 'pos.access',
    ];

    private static function isPlatformOnly($user): bool
    {
        if (!$user) {
            return true;
        }
        foreach (self::BUSINESS_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return false;
            }
        }
        return !ReportPages::canViewAny($user, ReportPages::keys());
    }

    private function unreadNotifications($user): int
    {
        try {
            return DB::table('notifications')
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->whereNull('read_at')
                ->count();
        } catch (\Exception) {
            return 0;
        }
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
                $n = $this->pendingApprovalCount($user);
                if ($n > 0) $alerts[] = [
                    'type'         => 'warning',
                    'icon'         => 'payment',
                    'message'      => "{$n} international payment" . ($n !== 1 ? 's' : '') . " awaiting approval",
                    'action_url'   => '/approvals',
                    'action_label' => 'Review now',
                ];
            } catch (\Exception) {}
        }

        // Overdue production orders — admins, managers, tailors, and whoever
        // runs the floor (the key, not a role name: a production manager or
        // any custom role that assigns the work).
        if ($isAdmin || $isManager || $isTailor || $user->can('production.manage_assignees')) {
            try {
                // The same orders the Overdue tile counts (buildStats), so the
                // banner and the tile can never disagree.
                $overdueQuery = \App\Models\ProductionOrder::query()->visibleTo($user)
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled', 'draft']);

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
                $n = $this->lowStockCount($user);
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

    /**
     * Stock lines at or below their reorder point — the Low Stock page's own
     * rule (InventoryController::lowStock), within the viewer's outlets.
     *
     * These three counts read `inventories.quantity` and
     * `low_stock_threshold`, columns that table does not have: every call
     * failed inside its try/catch, so the tile, badge and alert were blank.
     */
    private function lowStockCount(?User $user): int
    {
        return \App\Models\InventoryItem::query()
            ->tap(fn ($q) => DataScopeResolver::boundToOutlets($q, $user, 'inventory.view', 'inventory_items.outlet_id'))
            ->lowStock()
            ->where('quantity_on_hand', '>', 0)
            ->count();
    }

    /**
     * Payments waiting for approval that THIS person can see — the same
     * boundary as the approvals page (PaymentApprovalController::
     * pendingApprovals), so an outlet manager's count is her outlet's, not
     * every outlet's (it was counted across the whole business).
     */
    private function pendingApprovalCount(?User $user): int
    {
        $query = DB::table('payments as p')
            ->join('orders as o', 'p.order_id', '=', 'o.id')
            ->where('p.requires_approval', true)
            ->where('p.approval_status', 'pending_review');
        $this->constrainToViewer($query, $user, 'o');
        if (! $user?->can('orders.view')) {
            $query->whereRaw('1 = 0');
        }

        return $query->count();
    }
}
