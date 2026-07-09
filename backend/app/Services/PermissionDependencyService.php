<?php

namespace App\Services;

/**
 * Central permission dependency map.
 *
 * Granting someone a permission like `orders.set_shipping_fee` is only
 * useful if they can also reach everything that action's UI/API flow
 * depends on. In this codebase almost every "action" permission
 * (create/edit/approve/etc.) sits on a route that is *nested inside* a
 * parent `permission:{module}.view` (or `.access`) route group - so a role
 * that has the action permission but not the module's view permission gets
 * a silent 403 the moment they try to use it. On top of that, several
 * workflows reach across modules entirely (e.g. building an order needs the
 * product catalogue and the customer picker).
 *
 * This service expands a requested permission set to include every
 * transitively-required prerequisite, so "grant X" always means "grant
 * everything X needs to actually function" - the same intent the
 * hand-written ROLE_PERMISSIONS lists in SyncPermissions already follow
 * manually today (and occasionally miss - e.g. pos_clerk is granted
 * `production.raise_order` without `production.view`, which currently
 * 403s, since /admin/production-orders and /admin/production/schedule both
 * sit inside the permission:production.view route group).
 *
 * Used by:
 *   - php artisan permission:sync (SyncPermissions), when seeding the
 *     built-in role defaults
 *   - RoleController, when an admin creates/edits/duplicates a role's
 *     permissions through the Roles & Permissions UI
 *
 * Keep this list read-access only: granting an action permission should
 * never silently hand out another WRITE permission, only the minimum
 * view/access a role needs for that action to be reachable at all.
 */
class PermissionDependencyService
{
    /**
     * permission => [ prerequisite permissions ]
     *
     * Every entry is traceable to either (a) a route nested inside another
     * permission's middleware group in routes/api.php, or (b) a specific
     * frontend API call that a permission's UI flow makes into another
     * module.
     */
    const DEPENDENCIES = [

        // ── Orders ──────────────────────────────────────────────────────────
        // create/edit/cancel/refund/set_shipping_fee/set_deposit all live
        // inside the `permission:orders.view` route group - unreachable
        // without it. create/edit also need the catalogue (product picker)
        // and the customer picker to build an order line-by-line.
        'orders.create'           => ['orders.view', 'products.view', 'customers.view'],
        'orders.edit'             => ['orders.view', 'products.view', 'customers.view'],
        'orders.cancel'           => ['orders.view'],
        'orders.set_deposit'      => ['orders.view'],
        // The shipping-fee modal loads GET /admin/shipping/methods, gated
        // by settings.view - without it the method picker 403s.
        'orders.set_shipping_fee' => ['orders.view', 'settings.view'],
        'orders.authorize_dispatch' => ['orders.view'],
        // Reducing a paid receipt's shipping is a superset of setting it.
        'orders.reduce_shipping_fee' => ['orders.set_shipping_fee', 'orders.view', 'settings.view'],
        // Refunds are also reachable via the payment-transactions ledger
        // (nested under permission:payments.view) and need the underlying
        // payment record either way.
        'orders.refund'           => ['orders.view', 'payments.view'],

        // ── Payments ─────────────────────────────────────────────────────── 
        // upload_proof/approve_international/void/reassign/transactions all
        // live inside the permission:payments.view route group.
        'payments.upload_proof'          => ['payments.view'],
        'payments.approve_international' => ['payments.view'],
        'payments.void'                  => ['payments.view'],
        'payments.reassign'              => ['payments.view'],
        'payments.transactions'          => ['payments.view'],
        // Recording a payment happens at POST /orders/{id}/payments, nested
        // inside permission:orders.view, not the payments module itself.
        'payments.record' => ['payments.view', 'orders.view'],

        // ── Production ───────────────────────────────────────────────────── 
        // Every production action lives inside permission:production.view
        // (production-orders, /production/schedule, production-tasks,
        // material-allocations and product-stages all share that gate).
        'production.confirm_order'            => ['production.view'],
        'production.manage_assignees'         => ['production.view'],
        'production.submit_qc'                => ['production.view'],
        'production.approve_qc'               => ['production.view'],
        'production.configure_auto_assignees' => ['production.view'],
        // Raising a made-to-order production order means picking a product.
        'production.raise_order' => ['production.view', 'products.view'],

        // ── Shipments ────────────────────────────────────────────────────── 
        'shipment.edit'            => ['shipment.view'],
        'shipment.manage_tracking' => ['shipment.view'],
        // Creating a shipment happens at POST /orders/{id}/shipments, nested
        // inside permission:orders.view, not the shipments module itself.
        'shipment.create' => ['shipment.view', 'orders.view'],

        // ── Customers ────────────────────────────────────────────────────── 
        'customers.create'               => ['customers.view'],
        'customers.edit'                 => ['customers.view'],
        'customers.delete'               => ['customers.view'],
        'customers.create_without_email' => ['customers.view'],
        'customers.invite'               => ['customers.view'],

        // ── Procurement ──────────────────────────────────────────────────── 
        'procurement.approve' => ['procurement.view'],
        'procurement.receive' => ['procurement.view'],
        // Building a PO means picking products to order.
        'procurement.create' => ['procurement.view', 'products.view'],

        // ── Inventory ────────────────────────────────────────────────────── 
        'inventory.adjust'   => ['inventory.view'],
        'inventory.transfer' => ['inventory.view'],
        'inventory.approve'  => ['inventory.view'],

        // ── Catalogue ────────────────────────────────────────────────────── 
        'products.create' => ['products.view'],
        'products.edit'   => ['products.view'],
        'products.delete' => ['products.view'],
        'products.import' => ['products.view', 'products.create'],

        // ── POS ──────────────────────────────────────────────────────────── 
        // All of these sit inside the permission:pos.access admin/pos
        // route group.
        'pos.discount'        => ['pos.access'],
        'pos.void'            => ['pos.access'],
        'pos.open_register'   => ['pos.access'],
        'pos.close_register'  => ['pos.access'],
        'pos.returns'         => ['pos.access'],
        'pos.cash_management' => ['pos.access'],

        // ── Reports ──────────────────────────────────────────────────────── 
        'reports.export'    => ['reports.view'],
        'reports.financial' => ['reports.view'],

        // ── Expenses ─────────────────────────────────────────────────────── 
        // Every expenses.* action lives inside permission:expenses.view.
        'expenses.create'  => ['expenses.view'],
        'expenses.edit'    => ['expenses.view'],
        'expenses.delete'  => ['expenses.view'],
        'expenses.approve' => ['expenses.view'],
        'expenses.export'  => ['expenses.view'],
        'expenses.budgets' => ['expenses.view'],

        // ── Outlets ──────────────────────────────────────────────────────── 
        'outlets.create' => ['outlets.view'],
        'outlets.edit'   => ['outlets.view'],
        'outlets.delete' => ['outlets.view'],

        // ── Settings ─────────────────────────────────────────────────────── 
        // Applies once the settings/roles/permissions/database route groups
        // are permission-gated instead of hardcoded to role:super_admin
        // (see the routes/api.php change made in this same review).
        'settings.edit'            => ['settings.view'],
        'settings.manage_database' => ['settings.view'],

        // ── Users & Roles ────────────────────────────────────────────────── 
        'users.create' => ['users.view'],
        'users.edit'   => ['users.view'],
        'users.delete' => ['users.view'],
        'roles.edit'   => ['roles.view'],

        // activity-logs/clear lives inside the permission:users.view route
        // group (same as read-only log viewing/export) - unreachable
        // without it.
        'activity_logs.manage' => ['users.view'],

        // ── Attendance ───────────────────────────────────────────────────── 
        'attendance.manage' => ['attendance.view_team'],
    ];

    /**
     * Expand a list of permission names to include every transitively
     * required dependency. Idempotent and safe to call on an
     * already-expanded list, or a list containing unknown permission names
     * (they're just passed through untouched).
     *
     * @param  string[]  $permissionNames
     * @return string[]
     */
    public static function resolve(array $permissionNames): array
    {
        $resolved = array_fill_keys($permissionNames, true);
        $queue = $permissionNames;

        while ($queue) {
            $permission = array_pop($queue);
            foreach (self::DEPENDENCIES[$permission] ?? [] as $dependency) {
                if (!isset($resolved[$dependency])) {
                    $resolved[$dependency] = true;
                    $queue[] = $dependency;
                }
            }
        }

        return array_keys($resolved);
    }
}