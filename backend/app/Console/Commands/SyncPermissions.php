<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Artisan;
use App\Services\PermissionDependencyService;

/**
 * php artisan permission:sync
 *
 * Idempotent - safe to run multiple times. Creates permissions that don't
 * exist yet and skips ones that do. Never deletes existing permissions.
 *
 * Run after each deployment that adds new permission slugs.
 */
class SyncPermissions extends Command
{
    protected $signature   = 'permission:sync';
    protected $description = 'Seed / sync all application permission slugs into the database.';

    /**
     * All permission slugs, grouped by module.
     * Format: 'slug' => ['display_name', 'description', 'group']
     *
     * Reflects every permission currently checked by the frontend Sidebar,
     * PermissionGate components, and backend route middleware.
     */
    const PERMISSIONS = [

        // ── Dashboard ────────────────────────────────────────────────────────
        'dashboard.view'           => ['View Dashboard',           'Access the main admin dashboard',            'Dashboard'],

        // ── Orders ──────────────────────────────────────────────────────────
        'orders.view'              => ['View Orders',              'List and view order details',                'Orders'],
        'orders.create'            => ['Create Orders',            'Place new orders (admin side)',              'Orders'],
        'orders.edit'              => ['Edit Orders',              'Edit order details and status',              'Orders'],
        'orders.cancel'            => ['Cancel Orders',            'Cancel unpaid/pending orders',               'Orders'],
        'orders.refund'            => ['Refund Orders',            'Process full or partial refunds',            'Orders'],
        'orders.set_shipping_fee'  => ['Set Shipping Fee',         'Manually set shipping fee before payment',   'Orders'],
        'orders.set_deposit'       => ['Set Deposit Terms',        'Set deposit amount and due date',            'Orders'],
        'orders.manage_returns'    => ['Manage Order Returns',      'Approve, reject and process customer return requests', 'Orders'],

        // ── Payments ────────────────────────────────────────────────────────
        'payments.view'                    => ['View Payments',                 'View payment records',                              'Payments'],
        'payments.record'                  => ['Record Payments',               'Record cash/manual payments',                       'Payments'],
        'payments.upload_proof'            => ['Upload Payment Proof',          'Upload proof-of-payment files',                     'Payments'],
        'payments.approve_international'   => ['Approve International Payments','Approve or reject international proof-of-payment',  'Payments'],
        'payments.transactions'            => ['View Payment Transactions',     'View the full payment transaction ledger and analytics', 'Payments'],
        'payments.void'                    => ['Void Payments',                 'Void a payment applied to the wrong order',              'Payments'],
        'payments.reassign'                => ['Reassign Payments',             'Move a payment from one order to another',               'Payments'],

        // ── Production ──────────────────────────────────────────────────────
        'production.view'                  => ['View Production',               'View production orders and schedule',                'Production'],
        'production.raise_order'           => ['Raise Production Order',        'Create new production orders (sales team)',          'Production'],
        'production.confirm_order'         => ['Confirm Production Order',      'Move order from draft to production queue',          'Production'],
        'production.manage_assignees'      => ['Manage Assignees',              'Add/remove workers and assign tasks on an order',    'Production'],
        'production.configure_auto_assignees' => ['Configure Production Settings', 'Manage auto-assignee rules and production stages in settings', 'Production'],
        'production.submit_qc'             => ['Submit QC Results',             'Submit quality control pass/fail',                   'Production'],
        'production.approve_qc'            => ['Approve QC',                    'Final sign-off on QC (manager/admin)',               'Production'],
        'production.worker'                => ['Production Worker Access',      'Access tailor/QC worker workspace (My Tasks)',       'Production'],

        // ── Shipments ───────────────────────────────────────────────────────
        'shipment.view'            => ['View Shipments',           'View shipment records and tracking',         'Shipments'],
        'shipment.create'          => ['Create Shipments',         'Create shipment records for orders',         'Shipments'],
        'shipment.edit'            => ['Edit Shipments',           'Edit carrier, tracking number, dates and notes on an existing shipment', 'Shipments'],
        'shipment.manage_tracking' => ['Manage Tracking Events',   'Add tracking events to shipments',           'Shipments'],

        // ── Customers ───────────────────────────────────────────────────────
        'customers.view'                   => ['View Customers',               'List and view customer profiles',            'Customers'],
        'customers.create'                 => ['Create Customers',             'Add new customer records',                   'Customers'],
        'customers.edit'                   => ['Edit Customers',               'Edit customer details',                      'Customers'],
        'customers.delete'                 => ['Delete Customers',             'Delete customer records',                    'Customers'],
        'customers.create_without_email'   => ['Create Walk-in Customers',     'Create customers without an email address',  'Customers'],
        'customers.invite'                 => ['Invite Customers to Portal',   'Send portal invitation emails to customers',  'Customers'],

        // ── Procurement ─────────────────────────────────────────────────────
        'procurement.view'     => ['View Procurement',     'View purchase orders, suppliers and GRN',    'Procurement'],
        'procurement.create'   => ['Create Purchase Orders','Create purchase orders',                    'Procurement'],
        'procurement.approve'  => ['Approve Purchase Orders','Approve or reject purchase orders',        'Procurement'],
        'procurement.receive'  => ['Receive Goods (GRN)',  'Record goods received notes',                'Procurement'],

        // ── Inventory ───────────────────────────────────────────────────────
        'inventory.view'       => ['View Inventory',       'View stock levels, adjustments and transfers', 'Inventory'],
        'inventory.adjust'     => ['Adjust Stock',         'Create manual stock adjustments',             'Inventory'],
        'inventory.transfer'   => ['Transfer Stock',       'Create stock transfers between outlets',      'Inventory'],
        'inventory.approve'    => ['Approve Stock Movements','Approve or reject pending stock adjustments and transfers', 'Inventory'],

        // ── Catalogue ───────────────────────────────────────────────────────
        'products.view'        => ['View Products',        'View product catalogue, categories and BOMs', 'Catalogue'],
        'products.create'      => ['Create Products',      'Add new products to the catalogue',           'Catalogue'],
        'products.edit'        => ['Edit Products',        'Edit product details, pricing and variants',  'Catalogue'],
        'products.delete'      => ['Delete Products',      'Delete products from the catalogue',          'Catalogue'],
        'products.import'      => ['Bulk Import Products',  'Import products in bulk from CSV/Excel files', 'Catalogue'],
        'products.export'      => ['Export Products',       'Export product catalogue to CSV',              'Catalogue'],

        // ── POS ─────────────────────────────────────────────────────────────
        'pos.access'           => ['POS Access',           'Use the point-of-sale terminal',              'POS'],
        'pos.discount'         => ['Apply Discounts',      'Apply manual discounts at POS',               'POS'],
        'pos.void'             => ['Void Transactions',    'Void completed POS transactions',             'POS'],
        'pos.open_register'    => ['Open Cash Register',   'Open a new cash register session',            'POS'],
        'pos.close_register'   => ['Close Cash Register',  'Close and reconcile a cash register',         'POS'],
        'pos.returns'          => ['Process Returns',      'Process item returns at POS',                 'POS'],
        'pos.cash_management'  => ['POS Cash Management',  'Perform cash deposits, withdrawals and adjustments on a register', 'POS'],

        // ── Reports & Analytics ─────────────────────────────────────────────
        'reports.view'         => ['View Reports',         'Access sales, inventory and financial reports', 'Reports'],
        'reports.export'       => ['Export Reports',       'Export reports to CSV/PDF',                    'Reports'],
        'reports.financial'    => ['View Financial Reports','Access revenue, profit and financial analytics', 'Reports'],

        // ── Expenses ────────────────────────────────────────────────────────
        'expenses.view'        => ['View Expenses',        'List and view expense records',                        'Expenses'],
        'expenses.create'      => ['Create Expenses',      'Create new expense records',                           'Expenses'],
        'expenses.edit'        => ['Edit Expenses',        'Edit draft or rejected expenses',                      'Expenses'],
        'expenses.delete'      => ['Delete Expenses',      'Delete draft, rejected or cancelled expenses',         'Expenses'],
        'expenses.approve'     => ['Approve Expenses',     'Approve or reject pending expenses',                   'Expenses'],
        'expenses.export'      => ['Export Expenses',      'Export expense data to CSV',                           'Expenses'],
        'expenses.budgets'     => ['Manage Expense Budgets','Create and manage expense category budgets',          'Expenses'],

        // ── Outlets ─────────────────────────────────────────────────────────
        'outlets.view'         => ['View Outlets',         'View outlet list, details and statistics',                'Outlets'],
        'outlets.create'       => ['Create Outlets',       'Add new outlets',                                         'Outlets'],
        'outlets.edit'         => ['Edit Outlets',         'Edit outlet details and settings',                        'Outlets'],
        'outlets.delete'       => ['Delete Outlets',       'Delete outlets',                                          'Outlets'],

        // ── Settings ────────────────────────────────────────────────────────
        'settings.view'             => ['View Settings',             'View system settings and configuration',                     'Settings'],
        'settings.edit'             => ['Edit Settings',              'Change business settings and configuration',                 'Settings'],
        'settings.manage_database'  => ['Manage Database',           'Backups, restores, transaction cleanup and full data wipe',  'Settings'],

        // ── Users & Roles ────────────────────────────────────────────────────
        'users.view'           => ['View Users',           'List and view system users',                  'Users & Roles'],
        'users.create'         => ['Create Users',         'Add new staff users',                         'Users & Roles'],
        'users.edit'           => ['Edit Users',           'Edit user details and role assignments',      'Users & Roles'],
        'users.delete'         => ['Delete Users',         'Delete user accounts',                        'Users & Roles'],
        'roles.view'           => ['View Roles',           'View roles and the permissions matrix',       'Users & Roles'],
        'roles.edit'           => ['Edit Roles',           'Create, edit and assign permissions to roles','Users & Roles'],

        // ── Activity / Audit Log ───────────────────────────────────────────
        // Viewing and exporting the audit log is covered by users.view (the
        // /admin/activity-logs route group's outer gate). This is separate
        // because it guards the one destructive action in that group -
        // permanently deleting log history - which shouldn't ride along
        // with a plain "look at the user list" permission.
        'activity_logs.manage' => ['Manage Activity Log',  'Permanently delete audit log entries older than a given date', 'Users & Roles'],

        // ── Profile ─────────────────────────────────────────────────────────
        'profile.view'         => ['View Own Profile',    'View own profile, sessions and activity log', 'Profile'],
        'profile.edit'         => ['Edit Own Profile',    'Update own profile details and password',     'Profile'],

        // ── Notifications ───────────────────────────────────────────────────
        'notifications.view'   => ['View Notifications',  'View own in-app notifications',               'Notifications'],

        // ── Attendance ──────────────────────────────────────────────────────
        'attendance.view_team' => ['View Team Attendance', 'View clock-in/out records for outlet or workshop staff', 'Attendance'],
        'attendance.manage'    => ['Manage Attendance',     'Correct time entries, resolve flags, and override geofence restrictions', 'Attendance'],
    ];

    /**
     * Default permission sets for each system role.
     *
     * Reflects the actual modules and actions each role performs in the system.
     * super_admin uses '*' - bypasses all checks in usePermissions().
     */
    const ROLE_PERMISSIONS = [

        'super_admin' => '*',   // Wildcard - bypasses all permission checks

        'admin' => [
            // Full access to everything except super_admin-only operations
            'dashboard.view',
            'orders.*', 'payments.*',
            'production.*',
            'shipment.*',
            'customers.*',
            'procurement.*',
            'inventory.*',
            'products.*',
            'pos.*',
            'reports.*',
            'expenses.*',
            'outlets.*',
            'settings.*',
            'users.*', 'roles.*',
            'activity_logs.manage',
            'attendance.*',
            'notifications.view',
        ],

        'outlet_manager' => [
            'dashboard.view',
            // Orders - full operational control
            'orders.view', 'orders.create', 'orders.edit', 'orders.manage_returns',
            'orders.set_shipping_fee', 'orders.set_deposit',
            // Payments - record and upload; international approval excluded
            'payments.view', 'payments.record', 'payments.upload_proof',
            // Production - manage orders and QC but not system configuration
            'production.view', 'production.raise_order', 'production.confirm_order',
            'production.manage_assignees', 'production.submit_qc', 'production.approve_qc',
            // Shipments
            'shipment.view', 'shipment.create', 'shipment.manage_tracking',
            // Customers
            'customers.view', 'customers.create', 'customers.edit',
            'customers.create_without_email',
            // Inventory
            'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.approve',
            // Catalogue - view only
            'products.view',
            // POS - full register access
            'pos.access', 'pos.discount', 'pos.void',
            'pos.open_register', 'pos.close_register', 'pos.returns', 'pos.cash_management',
            // Reports
            'reports.view',
            // Expenses - create and submit; approval handled by admin/finance
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
            // Outlets - view and edit own outlet details
            'outlets.view', 'outlets.edit',
            // Attendance - oversee their own outlet/workshop staff
            'attendance.view_team', 'attendance.manage',
            // Profile
            'profile.view', 'profile.edit',
            // Notifications
            'notifications.view',
        ],

        'pos_clerk' => [
            // POS terminal. open_register/close_register included because
            // both scope strictly to the current user's own register
            // (CashRegister::where('opened_by', $user->id) in both
            // PosController::openRegister/closeRegister) - every cashier
            // opens and closes their own drawer each shift, this was never
            // a manager-delegates-to-everyone action.
            'pos.access', 'pos.discount', 'pos.returns', 'pos.void',
            'pos.open_register', 'pos.close_register',
            // Orders - create and view own orders
            'orders.view', 'orders.create',
            // Payments - record, upload proof, and view transaction history
            'payments.view', 'payments.record', 'payments.upload_proof',
            // Customers - create walk-in and view
            'customers.view', 'customers.create', 'customers.create_without_email',
            // Production - can raise MTO orders at POS
            'production.raise_order',
            // Profile
            'profile.view', 'profile.edit',
            // Notifications
            'notifications.view',
        ],

        'tailor' => [
            // Production worker workspace only
            'production.view',
            'production.worker',
            'production.submit_qc',
            // Profile
            'profile.view', 'profile.edit',
            // Notifications
            'notifications.view',
        ],

        'procurement_officer' => [
            'dashboard.view',
            // Procurement - full cycle
            'procurement.view', 'procurement.create', 'procurement.receive', 'procurement.approve',
            // Inventory - view and adjust raw materials
            'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.approve',
            // Catalogue - view to reference products when purchasing
            'products.view',
            // Payments - view transaction history for PO-related payments
            'payments.view',
            // Reports - procurement officers need spend reports
            'reports.view',
            // Expenses - view expenses linked to purchase orders
            'expenses.view',
            // Profile
            'profile.view', 'profile.edit',
            // Notifications
            'notifications.view',
        ],
    ];

    /**
     * Additional system roles referenced in backend controllers but not listed
     * above. Defined here so they are created and seeded on permission:sync.
     *
     * NOTE: procurement_manager is referenced by hasAnyRole() checks in
     * PurchaseOrderController (approve/reject PO) and PurchaseReturnController.
     */
    const EXTRA_ROLES = [

        'procurement_manager' => [
            'dashboard.view',
            // Procurement - full cycle including approval authority
            'procurement.view', 'procurement.create', 'procurement.approve', 'procurement.receive',
            // Inventory - stock visibility and approval
            'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.approve',
            // Catalogue - view to reference products
            'products.view',
            // Payments - view transaction history for PO-related payments
            'payments.view',
            // Reports - spend and procurement analytics
            'reports.view', 'reports.export',
            // Expenses - view expenses linked to POs
            'expenses.view',
            // Profile
            'profile.view', 'profile.edit',
            // Notifications
            'notifications.view',
        ],

        'finance_manager' => [
            'dashboard.view',
            // Payments - full approval authority + view transactions ledger
            'payments.view', 'payments.approve_international', 'payments.transactions', 'payments.void', 'payments.reassign',
            // Expenses - full control including approval and budgets
            'expenses.view', 'expenses.create', 'expenses.edit',
            'expenses.delete', 'expenses.approve', 'expenses.export', 'expenses.budgets',
            // Reports - all reports including financial
            'reports.view', 'reports.export', 'reports.financial',
            // Orders - view only (for payment context)
            'orders.view',
            // Profile
            'profile.view', 'profile.edit',
            // Notifications
            'notifications.view',
        ],

    ];

    public function handle(): void
    {
        $this->info('Syncing permissions…');
        $created = 0;
        $skipped = 0;

        foreach (self::PERMISSIONS as $slug => [$displayName, $description, $group]) {
            $permission = Permission::firstOrCreate(
                ['name' => $slug, 'guard_name' => 'sanctum'],
            );
            // Always update metadata in case display_name/description/group changed
            $permission->update([
                'display_name' => $displayName,
                'description'  => $description,
                'group'        => $group,
            ]);
            if ($permission->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->info("  {$created} created, {$skipped} already existed (metadata updated).");

        // ── Assign permissions to roles ──────────────────────────────────────
        $this->info('Assigning permissions to system roles…');
        $allPermissions = Permission::where('guard_name', 'sanctum')->pluck('name')->toArray();

        // Merge standard role permissions with extra roles (procurement_manager, finance_manager, etc.)
        $allRolePermissions = array_merge(self::ROLE_PERMISSIONS, self::EXTRA_ROLES);

        foreach ($allRolePermissions as $roleName => $perms) {
            // Ensure the role exists - create it if missing (idempotent)
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'sanctum'],
                ['display_name' => ucwords(str_replace('_', ' ', $roleName)), 'guard_name' => 'sanctum'],
            );
            if ($role->wasRecentlyCreated) {
                $this->info("  Created new role: {$roleName}");
            }

            if ($perms === '*') {
                $this->info("  {$roleName}: super admin - no explicit permissions needed (wildcard bypass)");
                continue;
            }

            // Permissions that must NEVER be granted via wildcard expansion,
            // regardless of role - only assignable explicitly (or via the
            // super_admin wildcard bypass above, which continues before
            // reaching this code for that role).
            $wildcardExcluded = ['settings.manage_database'];

            // Expand wildcards like 'orders.*'
            $expanded = [];
            foreach ($perms as $pattern) {
                if (str_ends_with($pattern, '.*')) {
                    $prefix  = substr($pattern, 0, -2);
                    $matched = array_filter(
                        $allPermissions,
                        fn ($p) => str_starts_with($p, $prefix . '.') && !in_array($p, $wildcardExcluded, true)
                    );
                    $expanded = array_merge($expanded, array_values($matched));
                } else {
                    $expanded[] = $pattern;
                }
            }

            // Auto-assign prerequisite permissions. A permission like
            // 'orders.set_shipping_fee' is worthless without 'orders.view'
            // (its route lives inside that group) and 'settings.view' (the
            // shipping-methods picker it depends on) - see
            // PermissionDependencyService for the full map and the
            // reasoning behind each entry. This is what lets
            // ROLE_PERMISSIONS above list only the "headline" permission
            // for a role and still get a fully working feature.
            $withDependencies = PermissionDependencyService::resolve($expanded);

            // Only assign permissions that actually exist in the DB
            $toAssign = array_values(array_unique(array_intersect($withDependencies, $allPermissions)));

            $impliedCount = count($toAssign) - count(array_intersect($expanded, $allPermissions));

            // Give permissions without detaching any manually added ones
            $role->givePermissionTo($toAssign);
            $this->info("  {$roleName}: " . count($toAssign) . ' permissions assigned'
                . ($impliedCount > 0 ? " ({$impliedCount} auto-added as dependencies)" : ''));
        }

        Artisan::call('permission:cache-reset');
        $this->info('Permission cache cleared.');
        $this->info('Done ✓');
    }
}