<?php

/*
|--------------------------------------------------------------------------
| Audit trail
|--------------------------------------------------------------------------
|
| Three records, one purpose — every action on the hub can be attributed to
| a person, a time and a place, and none of it can be quietly removed:
|
|   activity_log   what changed: every write to a business record (before and
|                  after values), every login, every approval, every download.
|   request_logs   what was looked at: every staff API call, so a bulk read of
|                  customers is as visible as a download of them.
|   audit_seals    a daily SHA-256 chain over both, so a deleted or edited row
|                  is detectable even by someone with database access.
|
| Both log tables are append-only at the database (see the 2026_09_21
| migration): UPDATE, DELETE and TRUNCATE raise, except the retention prune of
| request_logs, which has to announce itself with `SET LOCAL audit.allow_prune`.
|
| Nothing here is editable from the admin UI, on purpose. A retention period or
| an owner address that a super admin can change is a way to erase or redirect
| the trail; these come from the server's environment only.
|
*/

return [

    // The account that owns the audit trail: the only approver of downloads
    // unless it delegates (Phase 2). Matched case-insensitively on users.email.
    'owner_account_email' => env('AUDIT_OWNER_ACCOUNT_EMAIL', 'mwicigi@icloud.com'),

    // Where the owner's email goes: silent copies of downloads, download
    // approval requests, imprest alerts and the daily digest. The owner asked
    // (2026-09-22) for everything to go to his hub account address.
    'owner_email' => env('AUDIT_OWNER_EMAIL', 'mwicigi@icloud.com'),

    // Links in those emails open the console here.
    'console_url' => env('AUDIT_CONSOLE_URL', rtrim((string) env('APP_URL', 'https://hub.bethanyhouse.co.ke'), '/') . '/admin'),

    'request_log' => [
        // Staff API calls. GETs repeat constantly (every screen polls), so the
        // same user reading the same URL is recorded once per window; every
        // write is recorded, always.
        'get_dedupe_seconds' => (int) env('AUDIT_GET_DEDUPE_SECONDS', 300),

        // Kept for a year, then pruned by logs:purge-old.
        'retention_days' => max(90, (int) env('AUDIT_REQUEST_LOG_RETENTION_DAYS', 365)),

        // Housekeeping endpoints that carry no user intent.
        // (Laravel's is() matches the full path: the websocket handshake is
        // served under the api/ prefix.)
        'skip_paths' => [
            'api/health',
            'up',
            'broadcasting/auth',
            'api/broadcasting/auth',
        ],

        // Query-string keys whose values must never be stored.
        'redact_query_keys' => ['token', 'signature', 'password', 'code', 'key', 'secret', 'otp'],
    ],

    // activity_log is never pruned. Business records are kept five years for
    // KRA (Tax Procedures Act s.23); the trail that explains them is kept as
    // long. logs:purge-old refuses to touch it.
    'activity_log_prunable' => false,

    // Models whose every create / update / delete is recorded automatically
    // with before-and-after values (App\Observers\AuditObserver). Ledgers that
    // are themselves histories (inventory_transactions, order_status_history,
    // channel messages) and customer-side churn (carts, push subscriptions,
    // analytics rollups) are deliberately absent — they would double-record
    // or drown the trail.
    'observed_models' => [
        // Sales & money
        \App\Models\Order::class,
        \App\Models\OrderItem::class,
        \App\Models\OrderReturn::class,
        \App\Models\ReturnItem::class,
        \App\Models\OrderShipment::class,
        \App\Models\Payment::class,
        \App\Models\PaymentTransaction::class,
        \App\Models\PaymentMethod::class,
        \App\Models\CashRegister::class,
        \App\Models\CashRegisterTransaction::class,
        \App\Models\Quotation::class,
        \App\Models\QuotationItem::class,
        \App\Models\SalesDocument::class,
        \App\Models\Coupon::class,
        \App\Models\Promotion::class,
        // People
        \App\Models\Customer::class,
        \App\Models\Address::class,
        \App\Models\User::class,
        \App\Models\UserAddress::class,
        \App\Models\Lead::class,
        \App\Models\Supplier::class,
        \App\Models\TimeEntry::class,
        // Catalogue & pricing
        \App\Models\Product::class,
        \App\Models\ProductVariant::class,
        \App\Models\ProductPrice::class,
        \App\Models\ProductImage::class,
        \App\Models\ProductSeo::class,
        \App\Models\ProductSerial::class,
        \App\Models\Category::class,
        \App\Models\Season::class,
        \App\Models\Banner::class,
        \App\Models\ContentPage::class,
        // Stock & procurement
        \App\Models\Inventory::class,
        \App\Models\InventoryItem::class,
        \App\Models\InventoryTransfer::class,
        \App\Models\InventoryTransferItem::class,
        \App\Models\PurchaseOrder::class,
        \App\Models\PurchaseOrderItem::class,
        \App\Models\GoodsReceivedNote::class,
        \App\Models\GrnItem::class,
        \App\Models\PurchaseReturn::class,
        \App\Models\PurchaseReturnItem::class,
        \App\Models\Material::class,
        \App\Models\MaterialInventory::class,
        \App\Models\BillOfMaterial::class,
        \App\Models\BomItem::class,
        // Production
        \App\Models\ProductionOrder::class,
        \App\Models\ProductionOrderApproval::class,
        \App\Models\ProductionOrderAssignee::class,
        \App\Models\ProductionTask::class,
        // Expenses
        \App\Models\Expense::class,
        \App\Models\ExpenseApproval::class,
        \App\Models\ExpenseBudget::class,
        \App\Models\ExpenseCategory::class,
        // Configuration. (Settings rows are written with the query builder, which
        // fires no model events — SettingController records those changes
        // itself via ActivityLogService::settingsSaved().)
        \App\Models\Outlet::class,
        \App\Models\Currency::class,
        \App\Models\TaxRate::class,
        \App\Models\ShippingMethod::class,
        \App\Models\ShippingZone::class,
        \App\Models\Channel::class,
        \App\Models\DatabaseBackup::class,
        // Imprest (petty-cash float): setup, top-ups, counts, every ledger row.
        \App\Models\ImprestAccount::class,
        \App\Models\ImprestTransaction::class,
        \App\Models\ImprestTopupRequest::class,
        \App\Models\ImprestCashCount::class,
        // Download approval: every decision and delegation, with before/after.
        \App\Models\DownloadRequest::class,
        \App\Models\DownloadApprover::class,
        // Spatie's own classes (config/permission.php) — App\Models\Role and
        // App\Models\Permission are unused legacy models; observing them would
        // record nothing. Grants to users/roles are pivot writes with no model
        // event; UserController / RoleController log those explicitly.
        \Spatie\Permission\Models\Role::class,
        \Spatie\Permission\Models\Permission::class,
    ],

    // Attribute names never written to the trail — the log records THAT they
    // changed, never the value. Matched as substrings, case-insensitively.
    'redacted_attributes' => [
        'password', 'remember_token', 'two_factor', 'secret', 'passkey',
        'api_key', 'access_token', 'refresh_token', 'private_key', 'credentials',
        'token_hash',
    ],

    // Whole columns redacted on specific models (they hold nested secrets).
    'redacted_columns' => [
        \App\Models\PaymentMethod::class => ['configuration'],
    ],

    // Attributes that change on their own and carry no intent.
    'ignored_attributes' => ['updated_at', 'last_login_at', 'last_login_ip', 'last_activity_at', 'last_seen_at'],

    /*
    |----------------------------------------------------------------------
    | Download approval (App\Http\Middleware\DownloadGate)
    |----------------------------------------------------------------------
    |
    | Owner decision 2026-09-21: every file that leaves the hub needs the
    | owner's approval (or a manager he delegates to), except invoices,
    | quotations and receipts. The owner's own downloads are approved by
    | being his.
    |
    | Classification is by controller action. Anything NOT listed that returns
    | a file — a PDF, a CSV, a spreadsheet, an archive — is gated too, so a
    | download endpoint added later fails closed instead of slipping past.
    |
    */
    'downloads' => [
        // Off: the gate records what it WOULD have held (shadow) and lets it
        // through. On: it holds. Flip in the server environment.
        'enforce' => (bool) env('DOWNLOAD_GUARD_ENABLED', false),

        'token_ttl_minutes'      => 30,     // an approved download link, once issued
        'request_ttl_hours'      => 24,     // pending/approved requests expire after this
        'archive_disk'           => 'local',
        'archive_dir'            => 'download-archive',
        'archive_retention_days' => 90,     // server copy; the owner's emailed copy is the long-term one
        'attach_max_bytes'       => 10 * 1024 * 1024,

        // Invoices, quotations, receipts — and a blank template that carries
        // no data. Recorded, never held; reported in the owner's daily digest.
        'exempt' => [
            'DocumentPdfController@invoice',
            'DocumentPdfController@quotation',
            'DocumentPdfController@receipt',
            'PosController@printReceipt',
            'PosController@emailReceipt',
            'ExpenseController@downloadReceipt',
            'ProductController@exportTemplate',
        ],

        // Files the hub shows INSIDE its own screens — a payment proof on the
        // approvals page, a customer's photo in a chat, a shipment attachment.
        // Viewing them is the work; they are in the request log like any read.
        'views' => [
            'ChannelController@serveAttachment',
            'PaymentApprovalController@serveProof',
            'ShipmentController@serveShipmentAttachment',
            'ShipmentController@serveTrackingAttachment',
            // The owner opening a file already recorded (and logged as such) —
            // not a new download, and not archived a second time.
            'DownloadRequestController@archive',
        ],

        // Held like any other, and the owner is told — but the file itself is
        // never emailed: a full database dump does not belong in a mailbox.
        'never_attach' => [
            'DatabaseManagementController@backupsDownload',
        ],

        // Held BEFORE the work runs (a response-time check would still catch
        // them; this spares building a file only to discard it). Includes the
        // JSON "exports" a browser turns into a file, which a response check
        // cannot see.
        'always_gated' => [
            'AuditLogController@export',
            'UserController@export',
            'SupplierController@export',
            'OrderController@exportCsv',
            'PaymentController@exportTransactions',
            'PurchaseOrderController@grnPDF',
            'ReportController@exportPDF',
            'ReportController@exportExcel',
            'DatabaseManagementController@backupsDownload',
            'DocumentPdfController@*',
            'ReportPdfController@*',
        ],
    ],
];
