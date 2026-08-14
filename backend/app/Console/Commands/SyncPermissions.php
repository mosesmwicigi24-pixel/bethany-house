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
        'orders.edit_items'        => ['Edit Order Line Items',    'Add, change or remove the products on an existing order — including one that has already been paid (administrator only)', 'Orders'],
        'orders.cancel'            => ['Cancel Orders',            'Cancel unpaid/pending orders',               'Orders'],
        'orders.refund'            => ['Refund Orders',            'Process full or partial refunds',            'Orders'],
        'orders.set_shipping_fee'  => ['Set Shipping Fee',         'Add or raise the shipping charge on an order (allowed after payment)', 'Orders'],
        'orders.reduce_shipping_fee' => ['Reduce Shipping Fee',    'Reduce or remove the shipping charge on a receipt that has been paid (admin only)', 'Orders'],
        'orders.set_deposit'       => ['Set Deposit Terms',        'Set deposit amount and due date',            'Orders'],
        'orders.authorize_dispatch' => ['Authorize Dispatch',      'Confirm a paid order against the physical goods and release it for hand-over (the one or two people allowed to let goods leave)', 'Orders'],
        'orders.manage_returns'    => ['Manage Order Returns',      'Approve, reject and process customer return requests', 'Orders'],

        // ── Quotations (sales-documents flow) ───────────────────────────────
        'quotations.view'          => ['View Quotations',          'List and view customer quotations',          'Quotations'],
        'quotations.create'        => ['Create Quotations',        'Build and edit draft quotations',            'Quotations'],
        'quotations.issue'         => ['Issue Quotations',         'Issue a quotation and convert an accepted one to an invoice', 'Quotations'],
        'quotations.delete'        => ['Delete Quotations',        'Delete a draft quotation',                   'Quotations'],

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
        // Enforced at routes/api.php and ProductionController::destroy, and
        // never declared — so permission:sync never created it and NOBODY could
        // hold it. The feature was super-admin-only by accident rather than by
        // decision. Declared here so it can be granted deliberately.
        'production.delete_order'          => ['Delete Production Order',       'Permanently delete a production order that is past draft', 'Production'],

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
        'pos.discount'         => ['Apply Discounts',      'Apply manual discounts at POS, up to the configured ceiling', 'POS'],
        'pos.discount_override' => ['Discount Beyond the Ceiling', 'Apply a POS discount larger than the percentage ceiling that limits cashiers', 'POS'],
        'pos.void'             => ['Void Transactions',    'Void completed POS transactions',             'POS'],
        'pos.open_register'    => ['Open Cash Register',   'Open a new cash register session',            'POS'],
        'pos.close_register'   => ['Close Cash Register',  'Close and reconcile a cash register',         'POS'],
        'pos.returns'          => ['Process Returns',      'Process item returns at POS',                 'POS'],
        'pos.cash_management'  => ['POS Cash Management',  'Perform cash deposits, withdrawals and adjustments on a register', 'POS'],

        // ── Marketing & storefront ──────────────────────────────────────────
        // Split out of products.view, which is a READ permission on the
        // catalogue and was also opening Seasons, Campaigns and — because the
        // home-front pages are built on marketing banners — editing the public
        // storefront's home and product pages.
        'marketing.view'       => ['View Marketing',       'View liturgical seasons, campaigns and storefront banners', 'Marketing'],
        'marketing.manage'     => ['Manage Marketing',     'Create and edit seasons, campaigns and the storefront home/product pages', 'Marketing'],

        // ── Intelligence ────────────────────────────────────────────────────
        // Split out of customers.view. Reading a customer record to serve them
        // is not the same capability as reading where the customer base lives
        // and which channels it buys through.
        'intelligence.view'    => ['View Customer Intelligence', 'Customer geography, channel engagement and churn risk', 'Intelligence'],

        // ── Receivables ─────────────────────────────────────────────────────
        // Split out of pos.access. That permission exists to open the till; it
        // was also opening Outstanding Balances — every part-paid order in the
        // group with the customer's name, phone and what they owe.
        'receivables.view'     => ['View Outstanding Balances', 'Part-paid orders and what customers still owe', 'Receivables'],

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
        // Checked by the Sidebar's Recycle Bin entry. It was never declared, so
        // it resolved false for everyone and the item showed only to
        // super_admin, whose bypass ignores permissions — which happens to
        // match the backend, where /admin/trash is role:super_admin. Declaring
        // it makes that agreement deliberate instead of accidental.
        'settings.manage'           => ['Manage Recycle Bin',        'View and restore soft-deleted records',                      'Settings'],

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
    /**
     * How wide a view each role gets of the records it may reach.
     *
     * Anything not listed stays at 'all', which is the column default and the
     * behaviour every role had before scoping existed. Narrowing a role is a
     * line here plus a migration — the migration matters, because deploys run
     * `migrate` and never `permission:sync`.
     *
     * pos_clerk at 'own' is the change that closes the order-book leak: five
     * cashiers stop seeing each other's sales, and the invoices, exports and
     * searches built on the same query narrow with it.
     *
     * @see \App\Enums\DataScope
     */
    const ROLE_SCOPES = [
        'pos_clerk' => 'own',
        // Nine people, the largest group in the hub. Their work is ASSIGNED to
        // them, so 'own' resolves through assigned_to on a task and through
        // either relationship on a production order.
        'tailor'    => 'own',
    ];

    /**
     * Named sets of permissions that go together because a JOB needs them
     * together.
     *
     * A role used to be thirty-odd hand-maintained strings, which nobody could
     * read at a glance and which drifted whenever one list was updated and its
     * near-twin was not. A bundle is the unit of review instead: "a POS clerk
     * is @self + @till + @sell + @take_payment + @walkin_customer" is a
     * sentence somebody can check against how the shop actually works.
     *
     * A role's definition below may mix bundle references (@name) with plain
     * permission strings and wildcards, so a role that is "the usual, plus two"
     * says exactly that.
     *
     * These bundles were factored out of the existing role definitions and
     * change nothing: expandBundles() reproduces the previous lists exactly.
     */
    const BUNDLES = [

        // Everyone who signs in gets these.
        'self'            => ['profile.view', 'profile.edit', 'notifications.view'],
        'workspace'       => ['dashboard.view'],

        // Operating a till. cash_management is deliberately NOT here — a
        // cashier opens and closes their own drawer, but moving money in and
        // out of it is a supervisor's action.
        'till'            => ['pos.access', 'pos.discount', 'pos.void',
                              'pos.open_register', 'pos.close_register', 'pos.returns'],

        // Making a sale, and taking the money for it.
        'sell'            => ['orders.view', 'orders.create'],
        'take_payment'    => ['payments.view', 'payments.record', 'payments.upload_proof'],
        'walkin_customer' => ['customers.view', 'customers.create', 'customers.create_without_email'],

        // The shop floor: a worker's own tasks and the QC they submit on them.
        'shop_floor'      => ['production.view', 'production.worker', 'production.submit_qc'],

        // Stock, and buying it.
        'stock'           => ['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.approve'],
        'buying'          => ['procurement.view', 'procurement.create',
                              'procurement.receive', 'procurement.approve'],
    ];

    /**
     * Default permission sets for each system role, as bundles plus extras.
     *
     * super_admin uses '*' — it bypasses every check via Gate::before, so its
     * stored grants do nothing.
     */
    const ROLE_PERMISSIONS = [

        'super_admin' => '*',   // Wildcard - bypasses all permission checks

        'admin' => [
            // Full access to everything except super_admin-only operations.
            // Not @self: admin has never been granted profile.*, and this
            // refactor does not change who holds what.
            'dashboard.view',
            'orders.*', 'quotations.*', 'payments.*',
            'production.*',
            'shipment.*',
            'customers.*',
            'procurement.*',
            'inventory.*',
            'products.*',
            'receivables.*',
            'marketing.*',
            'intelligence.*',
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

        // Runs one shop: its till, its stock, its production, its people.
        'outlet_manager' => [
            '@self', '@workspace', '@till', '@sell', '@take_payment',
            '@walkin_customer', '@stock',
            // Beyond a cashier at the same till. discount_override makes this
            // role the escalation target when a cashier hits the 5% ceiling.
            'pos.cash_management', 'pos.discount_override',
            // Chasing what a customer still owes is a manager's job, not a
            // cashier's — the till key stopped carrying it.
            'receivables.view',
            'orders.edit', 'orders.manage_returns',
            'orders.set_shipping_fee', 'orders.set_deposit',
            'customers.edit',
            // Production - manage orders and QC but not system configuration
            'production.view', 'production.raise_order', 'production.confirm_order',
            'production.manage_assignees', 'production.submit_qc', 'production.approve_qc',
            'shipment.view', 'shipment.create', 'shipment.manage_tracking',
            'products.view',
            'reports.view',
            // Expenses - create and submit; approval handled by admin/finance
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete',
            'outlets.view', 'outlets.edit',
            'attendance.view_team', 'attendance.manage',
        ],

        // Sells at the counter. open_register/close_register are in @till
        // because every cashier opens and closes their own drawer each shift —
        // both scope strictly to the current user's own register.
        'pos_clerk' => [
            // @workspace is dashboard.view: the route was ungated before, so
            // clerks already had the screen. The group revenue figure is
            // withheld separately by buildStats, which checks reports.view.
            '@self', '@workspace', '@till', '@sell', '@take_payment', '@walkin_customer',
            // Front of the sales-documents flow
            'quotations.view',
            // Can raise a made-to-order job at the till
            'production.raise_order',
        ],

        // Production worker workspace only.
        'tailor' => [
            '@self', '@workspace', '@shop_floor',
        ],

        'procurement_officer' => [
            '@self', '@workspace', '@buying', '@stock',
            // Catalogue - view to reference products when purchasing
            'products.view',
            // Payments - view transaction history for PO-related payments
            'payments.view',
            // Reports - procurement officers need spend reports
            'reports.view',
            // Expenses - view expenses linked to purchase orders
            'expenses.view',
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

        // NOTE: this differs from procurement_officer by reports.export ALONE.
        // Both hold procurement.approve, so an officer can approve the purchase
        // orders they raised themselves. That is a segregation-of-duties gap,
        // not a refactor artefact — it is preserved here deliberately, because
        // this change is not allowed to move a single grant. Fixing it is a
        // policy decision.
        'procurement_manager' => [
            '@self', '@workspace', '@buying', '@stock',
            'products.view',
            'payments.view',
            'reports.view', 'reports.export',
            'expenses.view',
        ],

        'finance_manager' => [
            '@self', '@workspace',
            // Payments - full approval authority + view transactions ledger
            'payments.view', 'payments.approve_international', 'payments.transactions',
            'payments.void', 'payments.reassign',
            // Expenses - full control including approval and budgets
            'expenses.view', 'expenses.create', 'expenses.edit',
            'expenses.delete', 'expenses.approve', 'expenses.export', 'expenses.budgets',
            // Reports - all reports including financial
            'reports.view', 'reports.export', 'reports.financial',
            'receivables.view',
            // Orders - view only (for payment context)
            'orders.view',
        ],

    ];

    /**
     * Resolve a role definition into a flat permission list, replacing every
     * "@bundle" reference with the bundle's contents.
     *
     * @param  list<string>  $spec
     * @return list<string>
     */
    public static function expandBundles(array $spec): array
    {
        $out = [];

        foreach ($spec as $entry) {
            if (str_starts_with($entry, '@')) {
                $name = substr($entry, 1);
                if (!isset(self::BUNDLES[$name])) {
                    throw new \InvalidArgumentException("Unknown permission bundle: @{$name}");
                }
                foreach (self::BUNDLES[$name] as $p) {
                    $out[] = $p;
                }
                continue;
            }
            $out[] = $entry;
        }

        return array_values(array_unique($out));
    }

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

            // Data scope. Written for every role, not only the narrowed ones,
            // so a role that is widened back to 'all' in ROLE_SCOPES actually
            // widens rather than keeping whatever it had.
            $scope = self::ROLE_SCOPES[$roleName] ?? 'all';
            \Illuminate\Support\Facades\DB::table('roles')
                ->where('id', $role->id)
                ->update(['data_scope' => $scope]);
            if ($scope !== 'all') {
                $this->info("  {$roleName}: data scope {$scope}");
            }

            if ($perms === '*') {
                $this->info("  {$roleName}: super admin - no explicit permissions needed (wildcard bypass)");
                continue;
            }

            // Permissions that must NEVER be granted via wildcard expansion,
            // regardless of role - only assignable explicitly (or via the
            // super_admin wildcard bypass above, which continues before
            // reaching this code for that role).
            // Never granted by a wildcard, only ever explicitly. These are
            // destructive or system-owner capabilities, and `admin` holds
            // 'settings.*' and 'production.*' — without this, declaring them at
            // all would silently hand them to admin, which is the opposite of
            // the intent. super_admin still reaches them via its Gate::before
            // bypass, exactly as it did while they were undeclared.
            $wildcardExcluded = [
                'settings.manage_database',
                'settings.manage',
                'production.delete_order',
            ];

            // Resolve "@bundle" references first, so wildcard expansion and
            // dependency resolution below see a flat list exactly as they did
            // when roles were written out longhand.
            $perms = self::expandBundles($perms);

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