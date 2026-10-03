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
        // BOM reads sit inside the products.view route group, which the
        // dependency map hands to every till clerk (raise_order needs the
        // product picker). Without this gate a cashier could open any
        // product's bill of materials — unit costs, total cost, material
        // stock — from the till login. Named production.* so admin's
        // wildcard picks it up; granted below to the roles that genuinely
        // cost garments: managers and procurement.
        'production.view_bom'              => ['View Bill of Materials',        'View product BOMs including material unit costs',    'Production'],

        // Writing a bill of materials — create, edit, delete, activate. Was
        // products.edit, which made "edit the catalogue" and "decide what a
        // garment is made of and so what it costs" one key. The plan gives BOMs
        // to procurement (Role Hardening Plan §3.6); the catalogue stays with
        // admin. Deliberately NOT named products.* or production.*, so neither
        // of admin's wildcards picks it up.
        'bom.edit'                         => ['Edit Bills of Materials',       'Create, edit, delete and activate product BOMs',     'Production'],

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
        // Splits the customer DIRECTORY from the customer PICKER. customers.view
        // is what a till clerk needs to attach a walk-in to a sale: name, phone,
        // email. This permission is everything beyond that — addresses, credit
        // and balance figures, loyalty points, per-customer spend statistics and
        // order history. Admin inherits via customers.*.
        'customers.insights'               => ['View Customer Insights',       'Addresses, credit, spend statistics and order history on customer profiles', 'Customers'],

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
        // What a product and a garment COST us — cost_price on price rows,
        // material unit costs, BOM line costs and totals. Split from
        // products.view / production.view_bom, which outlet managers hold to
        // run the shop and the floor; the owner's field rule keeps cost to
        // admin, finance and procurement (super_admin via Gate::before).
        // Admin inherits it through products.*.
        'products.view_cost'   => ['View Product Cost',     'See cost prices, material unit costs and BOM costs', 'Catalogue'],

        // ── POS ─────────────────────────────────────────────────────────────
        'pos.access'           => ['POS Access',           'Use the point-of-sale terminal',              'POS'],
        'pos.discount'         => ['Apply Discounts',      'Apply manual discounts at POS, up to the configured ceiling', 'POS'],
        'pos.discount_override' => ['Discount Beyond the Ceiling', 'Apply a POS discount larger than the percentage ceiling that limits cashiers', 'POS'],
        'pos.discount_campaign' => ['Pass Through a Campaign Discount', 'Carry an owner-declared campaign discount into an order, bounded by the agent ceiling rather than the cashier one. For the sales agent\'s service account, not for people.', 'POS'],
        'pos.void'             => ['Void Transactions',    'Void completed POS transactions',             'POS'],
        'pos.open_register'    => ['Open Cash Register',   'Open a new cash register session',            'POS'],
        'pos.close_register'   => ['Close Cash Register',  'Close and reconcile a cash register',         'POS'],
        'pos.returns'          => ['Process Returns',      'Process item returns at POS',                 'POS'],
        'pos.cash_management'  => ['POS Cash Management',  'Perform cash deposits, withdrawals and adjustments on a register', 'POS'],
        // Reading every cashier's end-of-day report, acknowledging it and
        // answering in its thread. Was settings.view, which made reviewing
        // takings the same key as reading Setup — and handed Setup to every
        // outlet manager. Not a till permission: finance reviews takings and
        // has no till, so the review routes stand outside pos.access.
        'pos.eod_review'       => ['Review EoD Reports',   'Read, acknowledge and discuss cashiers\' end-of-day reports', 'POS'],
        // The till lifecycle (Phase 4B). Opening and the blind count are
        // pos.open_register / pos.close_register; these are the steps after it.
        'pos.till_verify'      => ['Verify & Finalize Tills', 'Verify a cashier\'s blind till count at your outlet and finalize it', 'POS'],
        'pos.reconcile'        => ['Reconcile Tills',      'Record the next-day check of a finalized till against the payments ledger', 'POS'],
        'pos.till_correction'  => ['Correct Finalized Tills', 'Open a linked correction against a finalized till (the original is never changed)', 'POS'],
        'pos.tills_view_all'   => ['View All Tills',       'Read every outlet\'s till sessions, counts and variances', 'POS'],

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
        // One permission per report page (Role Hardening Plan §6, Phase 3A).
        // The single front door 'reports.view' opened all ten non-financial
        // pages to whoever held it; it is retired (migration
        // 2026_10_03_300004) and no longer declared. Finance & Cash keeps
        // reports.financial. See App\Support\ReportPages for the page → slug
        // map and the export rule.
        'reports.executive'    => ['View Executive Overview',      'The Executive report page: headline figures, opportunities and their drill-downs', 'Reports'],
        'reports.sales'        => ['View Sales & Orders Report',   'Sales & Orders report page (and the dashboard revenue row)', 'Reports'],
        'reports.customers'    => ['View Customers & Neema Report','Customers & Neema report page, and Storefront Insights',  'Reports'],
        'reports.financial'    => ['View Financial Reports',       'Finance & Cash report page, and the financial figures on other report pages', 'Reports'],
        'reports.production'   => ['View Production & Fulfilment Report', 'Production & Fulfilment report page',            'Reports'],
        'reports.inventory'    => ['View Inventory Report',        'Inventory report page',                                 'Reports'],
        'reports.procurement'  => ['View Procurement & Suppliers Report', 'Procurement & Suppliers report page',            'Reports'],
        'reports.performance'  => ['View Staff, Outlets & Performance Report', 'Staff, Outlets & Performance report page',  'Reports'],
        'reports.signals'      => ['View Signals',                 'Signals report page: reorder, shortage, churn and budget warnings', 'Reports'],
        'reports.data_quality' => ['View Audit & Data Quality Report', 'Audit & Data Quality report page',                  'Reports'],
        'reports.explorer'     => ['View Business Explorer',       'Business Explorer report page',                         'Reports'],
        // A FILE out of a report page — CSV, PDF or a scheduled mailing. Needs
        // view of that page as well.
        'reports.export'       => ['Export Reports',               'Download CSV/PDF files from every report page the holder can view', 'Reports'],
        'reports.export_supply' => ['Export Supply Reports',       'Download CSV/PDF files from the Inventory and Procurement report pages only', 'Reports'],

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

        // The platform's technical reference data — countries, languages,
        // shipping zones and methods — read and edited. settings.view/edit also
        // open business settings, tax rates, currencies and payment methods,
        // which the platform head must never touch (plan §3.2). Not settings.*,
        // so admin's settings grants cannot carry it.
        'setup.technical'           => ['Technical Setup',           'Read and edit countries, languages, shipping zones and shipping methods', 'Settings'],

        // ── Users & Roles ────────────────────────────────────────────────────
        'users.view'           => ['View Users',           'List and view system users',                  'Users & Roles'],
        'users.create'         => ['Create Users',         'Add new staff users',                         'Users & Roles'],
        'users.edit'           => ['Edit Users',           'Edit user details and role assignments',      'Users & Roles'],
        'users.delete'         => ['Delete Users',         'Delete user accounts',                        'Users & Roles'],
        'roles.view'           => ['View Roles',           'View roles and the permissions matrix',       'Users & Roles'],
        'roles.edit'           => ['Edit Roles',           'Create, edit and assign permissions to roles','Users & Roles'],

        // ── Activity / Audit Log ───────────────────────────────────────────
        // No permission here, deliberately. The audit trail is super_admin
        // only (role gate on /admin/activity-logs) and append-only — nothing
        // can delete it — so activity_logs.manage ("permanently delete log
        // entries") governed nothing and was retired (2026_09_21 migration).

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

        // Stock, and buying it. The APPROVE keys are not in these bundles: the
        // person who moves stock or raises a PO is the maker, and approving is
        // the checker's job (plan §3, Phase 2). Roles that approve name it.
        'stock'           => ['inventory.view', 'inventory.adjust', 'inventory.transfer'],
        'buying'          => ['procurement.view', 'procurement.create', 'procurement.receive'],
    ];

    /**
     * Default permission sets for each system role, as bundles plus extras.
     *
     * super_admin uses '*' — it bypasses every check via Gate::before, so its
     * stored grants do nothing.
     */
    const ROLE_PERMISSIONS = [

        'super_admin' => '*',   // Wildcard - bypasses all permission checks

        // Operations Head (plan §3.3). Runs the business day to day and reads
        // all of it; does not hold the money, approval, register, Setup or
        // people-administration keys — those belong to finance, procurement,
        // the till and the owner. Wildcards are kept only for modules admin
        // holds IN FULL; every module it holds in part is written out, so a new
        // slug in that module never reaches admin by expansion.
        'admin' => [
            '@self', '@workspace',
            // orders.* except orders.refund — a refund is money leaving.
            'orders.view', 'orders.create', 'orders.edit', 'orders.edit_items',
            'orders.cancel', 'orders.set_shipping_fee', 'orders.reduce_shipping_fee',
            'orders.set_deposit', 'orders.authorize_dispatch', 'orders.manage_returns',
            'quotations.*',
            // Takes and evidences payments; voiding, moving and approving them
            // is finance's.
            'payments.view', 'payments.record', 'payments.upload_proof', 'payments.transactions',
            'production.*',
            'shipment.*',
            'customers.*',
            'procurement.view',
            'inventory.view',
            'products.*',
            'receivables.view',
            'marketing.*',
            'intelligence.*',
            // Sees the till and reviews its takings; does not operate it.
            // Reads every outlet's tills (Phase 4B); verifies, reconciles and
            // corrects none of them.
            'pos.access', 'pos.eod_review', 'pos.tills_view_all',
            // Every report page except Finance & Cash (plan §6: ADM "—"), and
            // files from them. Written out, not 'reports.*', so a future
            // report slug never reaches admin by expansion.
            'reports.executive', 'reports.sales', 'reports.customers', 'reports.production',
            'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
            'reports.data_quality', 'reports.explorer',
            'reports.export',
            'expenses.view',
            'outlets.view',
            'settings.view',
            'users.view', 'roles.view',
            'attendance.view_team',
        ],

        // Runs one shop: its till, its stock, its production, its people.
        'outlet_manager' => [
            '@self', '@workspace', '@till', '@sell', '@take_payment',
            '@walkin_customer', '@stock',
            // Quotes for the shop's customers and issues them — the second
            // person a clerk's draft quotation needs before it reaches a
            // customer (Phase 2; until now only admin could issue).
            'quotations.view', 'quotations.create', 'quotations.issue',
            // Beyond a cashier at the same till. discount_override makes this
            // role the escalation target when a cashier hits the 5% ceiling.
            'pos.cash_management', 'pos.discount_override',
            // Reviewing the shop's end-of-day reports (was settings.view).
            'pos.eod_review',
            // Verifies and finalizes the shop's till counts (Phase 4B) — at
            // their own outlets, and never a count they made themselves.
            'pos.till_verify',
            // Chasing what a customer still owes is a manager's job, not a
            // cashier's — the till key stopped carrying it.
            'receivables.view',
            'orders.edit', 'orders.manage_returns',
            'orders.set_shipping_fee', 'orders.set_deposit',
            'customers.edit',
            // The full customer profile — addresses, credit, spend statistics.
            // A manager chasing receivables needs the numbers a cashier doesn't.
            'customers.insights',
            // Production - manage orders and QC but not system configuration
            'production.view', 'production.raise_order', 'production.confirm_order',
            'production.manage_assignees', 'production.submit_qc', 'production.approve_qc',
            'production.view_bom',
            'shipment.view', 'shipment.create', 'shipment.manage_tracking',
            'products.view',
            // Report pages for running a shop (plan §6); no files. Outlet
            // scoping of these is Phase 4 — reports stay business-wide.
            'reports.sales', 'reports.production', 'reports.inventory', 'reports.performance',
            // Expenses - create and submit; approval is finance's. Deleting an
            // expense record is not a shop manager's call (Phase 2).
            'expenses.view', 'expenses.create', 'expenses.edit',
            // Sees the outlets; editing an outlet's details is the platform
            // head's (Phase 2). No inventory.approve either: @stock no longer
            // carries it, and a manager who adjusts stock must not approve it.
            'outlets.view',
            'attendance.view_team', 'attendance.manage',
        ],

        // Sells at the counter. open_register/close_register are in @till
        // because every cashier opens and closes their own drawer each shift —
        // both scope strictly to the current user's own register.
        'pos_clerk' => [
            // @workspace is dashboard.view: the route was ungated before, so
            // clerks already had the screen. The group revenue figure is
            // withheld separately by buildStats, which checks reports.sales.
            '@self', '@workspace', '@till', '@sell', '@take_payment', '@walkin_customer',
            // Front of the sales-documents flow. create covers raising and
            // editing a DRAFT; issuing one is quotations.issue and is
            // deliberately withheld, so a price reaches a customer over a
            // second person's decision. Without create, the scoped list she
            // was given in #297 could only ever be empty — she may see her own
            // quotations and had no way to have any.
            // The second person is the outlet manager or admin, who hold
            // quotations.issue (outlet_manager since Phase 2).
            'quotations.view', 'quotations.create',
            // Can raise a made-to-order job at the till
            'production.raise_order',
            // No expenses (owner decision O2, Phase 2): the till's running
            // costs are recorded by the outlet manager or the accountant.
        ],

        // Production worker workspace only.
        'tailor' => [
            '@self', '@workspace', '@shop_floor',
        ],

        // Raises POs, receives goods, moves stock. Approves none of it — that is
        // the procurement manager (Phase 2 closes the officer-approves-own-PO
        // gap). No payments or expenses: those are finance's records.
        'procurement_officer' => [
            '@self', '@workspace', '@buying', '@stock',
            // Catalogue - view to reference products when purchasing
            'products.view',
            // Costing materials against product BOMs is procurement's job
            'production.view_bom',
            'products.view_cost',
            // The floor and the stock it buys for (plan §6); no files.
            'reports.production', 'reports.inventory',
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

        // The procurement checker: approves POs, returns and stock movements
        // (maker≠checker per record still applies), and owns BOMs.
        'procurement_manager' => [
            '@self', '@workspace', '@buying', '@stock',
            'procurement.approve', 'inventory.approve',
            'products.view',
            'production.view_bom',
            'products.view_cost',
            'bom.edit',
            'payments.view',
            // Plan §6: production, stock and supplier pages; files from the
            // supply pages only (reports.export_supply, never reports.export).
            'reports.production', 'reports.inventory', 'reports.procurement',
            'reports.export_supply',
            'expenses.view',
        ],

        'finance_manager' => [
            '@self', '@workspace',
            // Payments - full approval authority + view transactions ledger
            'payments.view', 'payments.approve_international', 'payments.transactions',
            'payments.void', 'payments.reassign',
            // Expenses - the checker. Approves, budgets, exports; never creates,
            // edits or deletes the records it approves (plan §3.4).
            'expenses.view', 'expenses.approve', 'expenses.export', 'expenses.budgets',
            // Reports - every page except Customers & Neema (plan §6), Finance
            // & Cash included, and files from all of them.
            'reports.executive', 'reports.sales', 'reports.financial', 'reports.production',
            'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
            'reports.data_quality', 'reports.explorer',
            'reports.export',
            'receivables.view',
            // End-of-day reports: the takings finance reconciles against.
            'pos.eod_review',
            // Tills (Phase 4B): reads all, reconciles, and is the only role that
            // opens a correction against a finalized till.
            'pos.tills_view_all', 'pos.reconcile', 'pos.till_correction',
            // Orders - view only (for payment context)
            'orders.view',
            // Cost figures (margins, COGS context), and since Phase 2 the
            // screens they live on: catalogue, BOMs, stock, customers — read only.
            'products.view_cost',
            'products.view', 'production.view_bom', 'inventory.view', 'customers.view',
        ],

        // Platform Head (plan §3.2). Runs the platform — staff accounts,
        // outlets, attendance, technical reference data — and sees no business
        // data at all: no orders, money, customers, catalogue, stock or reports.
        // Was 17 legacy space-named permissions ("manage settings", …) that the
        // API never checks; the Phase 2 migration removes those.
        // users.delete is withheld: it is not a deactivation (users.edit's
        // status change is) but a soft delete that strips roles, outlets and
        // the customer profile. Role changes are super_admin's alone.
        'system_admin' => [
            '@self', '@workspace',
            'users.view', 'users.create', 'users.edit',
            'outlets.view', 'outlets.create', 'outlets.edit',
            'roles.view',
            'attendance.view_team', 'attendance.manage',
            'setup.technical',
        ],

        // Ledger Operator (plan §3.5) — the maker on the finance side. Records
        // and reads; approves nothing (finance_manager is the checker).
        'accountant' => [
            '@self', '@workspace',
            'orders.view',
            'payments.view', 'payments.transactions',
            'receivables.view',
            'pos.eod_review',
            // The next-day independent check of every finalized till (Phase 4B).
            'pos.tills_view_all', 'pos.reconcile',
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.export',
            'procurement.view',
            'inventory.view',
            'products.view', 'products.view_cost',
            // Reads six report pages, Finance & Cash among them (plan §6), and
            // exports none: its reconciliation files are expenses.export and
            // the payment ledger.
            'reports.sales', 'reports.financial', 'reports.production', 'reports.inventory',
            'reports.procurement', 'reports.performance',
        ],

    ];

    /**
     * Grants a role must never end up with, whatever its definition, a
     * wildcard or a dependency would otherwise add.
     *
     * Applied LAST in sync, after wildcard expansion and dependency
     * resolution, so it is the one place that guarantees a Phase 2 removal
     * stays removed. permission:sync runs on every container start and only
     * adds; without this a future slug or dependency entry could quietly hand
     * a removed key back. A denial that a granted permission depends on would
     * leave a broken role, so RoleCatalogueV2Test checks there is none.
     */
    const ROLE_DENIES = [
        'admin' => [
            'orders.refund',
            'payments.void', 'payments.reassign', 'payments.approve_international',
            'procurement.create', 'procurement.approve', 'procurement.receive',
            'inventory.adjust', 'inventory.transfer', 'inventory.approve',
            'pos.discount', 'pos.discount_override', 'pos.void', 'pos.open_register',
            'pos.close_register', 'pos.returns', 'pos.cash_management',
            'expenses.create', 'expenses.edit', 'expenses.delete', 'expenses.approve',
            'expenses.export', 'expenses.budgets',
            'outlets.create', 'outlets.edit', 'outlets.delete',
            'settings.edit',
            'users.create', 'users.edit', 'users.delete',
            'roles.edit',
            'attendance.manage',
            'bom.edit', 'setup.technical',
            // Phase 3A: Finance & Cash is not admin's (plan §6), and admin's
            // files come through reports.export, not the supply split.
            'reports.financial', 'reports.export_supply',
        ],
        'accountant' => [
            'expenses.approve', 'payments.void', 'payments.reassign',
            'payments.approve_international', 'inventory.approve', 'procurement.approve',
            // No report files (Phase 3A).
            'reports.export', 'reports.export_supply',
        ],
        'finance_manager'     => ['expenses.create', 'expenses.edit', 'expenses.delete'],
        'procurement_officer' => ['procurement.approve', 'inventory.approve', 'payments.view', 'expenses.view'],
        'outlet_manager'      => ['inventory.approve', 'expenses.delete', 'outlets.edit',
                                  // No report files (Phase 3A).
                                  'reports.export', 'reports.export_supply'],
        'pos_clerk'           => ['expenses.view', 'expenses.create'],
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
                // A SERVICE-ACCOUNT capability: it lets the sales agent carry
                // an owner-declared campaign discount past the cashier ceiling.
                // admin holds 'pos.*', so without this line the very next
                // container start hands a human role the agent's pass-through —
                // which is what happened the day it was added. admin already
                // has pos.discount_override, so it loses nothing; the point is
                // that this is not a thing people should hold by accident.
                'pos.discount_campaign',
                // Phase 2's split-out keys. No wildcard in use matches them
                // today; listed so a future 'bom.*' or 'setup.*' cannot either.
                'bom.edit',
                'setup.technical',
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

            // Phase 2 removals stay removed — see ROLE_DENIES.
            $withDependencies = array_values(array_diff($withDependencies, self::ROLE_DENIES[$roleName] ?? []));

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