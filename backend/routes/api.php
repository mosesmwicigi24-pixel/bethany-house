<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    AuthController,
    ProductController,
    CategoryController,
    OrderController,
    CartController,
    CustomerController,
    InventoryController,
    OutletController,
    PosController,
    ProductionController,
    PurchaseOrderController,
    SupplierController,
    PaymentController,
    ShippingController,
    ReportController,
    UserController,
    SettingController,
    MaterialController,
    ProductReviewController,
    RoleController,
    PermissionController,
    AuditLogController,
    ShipmentController,
    ReturnController,
    ContentPageController,
    LanguageController,
    CurrencyController,
    CountryController,
    TaxRateController,
    PaymentMethodController,
    ProductStageController,
    ProfileController,
    DashboardController,
    ChannelController,
    CommentController,
    BomController,
    StockLevelsController,
    StockTransfersController,
    StockAdjustmentsController,
    PaymentApprovalController,
    NotificationController,
    PublicPaymentController,
    ExpenseController,
    EnhancedReportController,
    PushController,
    GlobalSearchController,
    IntelligenceController,
    DocumentPdfController,
    ReportPdfController,
    TimeClockController,
    TrashController,
    DatabaseManagementController
};

// ── Health check - unauthenticated, used by Docker healthcheck ───────────────
Route::get('/health', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));

Route::post('/broadcasting/auth', function (Request $request) {
    return \Illuminate\Support\Facades\Broadcast::auth($request);
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {

    // ═══ AUTHENTICATION (customer / public facing) ═══════════════════════════

    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('/register',         [AuthController::class, 'register']);
        Route::post('/login',            [AuthController::class, 'login']);
        Route::post('/forgot-password',  [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',   [AuthController::class, 'resetPassword']);

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
            Route::post('/logout',          [AuthController::class, 'logout']);
            Route::get('/user',             [AuthController::class, 'user']);
            Route::put('/profile',          [AuthController::class, 'updateProfile']);
            Route::post('/change-password', [AuthController::class, 'changePassword']);
            Route::post('/enable-2fa',      [AuthController::class, 'enable2FA']);
            Route::post('/verify-2fa',      [AuthController::class, 'verify2FA']);
            Route::post('/disable-2fa',     [AuthController::class, 'disable2FA']);
        });
    });

    // ═══ REACT ADMIN AUTHENTICATION ══════════════════════════════════════════

    Route::prefix('admin/auth')->middleware('throttle:auth')->group(function () {
        Route::post('/login',           [AuthController::class, 'adminLogin']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
        Route::post('/2fa/verify',      [AuthController::class, 'adminVerify2fa']);

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
            Route::post('/logout',      [AuthController::class, 'logout']);
            Route::get('/me',           [AuthController::class, 'adminMe']);
            Route::post('/2fa/enable',  [AuthController::class, 'enable2FA']);
            // Confirms a TOTP code and activates 2FA on the already-
            // authenticated account (profile security settings flow).
            // Distinct from the pre-auth '/2fa/verify' above, which is
            // adminVerify2fa - that one verifies the *login* challenge for a
            // user who doesn't have a session yet and expects a user_id in
            // the body instead of relying on the bearer token.
            Route::post('/2fa/confirm', [AuthController::class, 'verify2FA']);
            Route::post('/2fa/disable', [AuthController::class, 'disable2FA']);
        });
    });

    // ═══ PUBLIC PAYMENT LINK (no auth) ═══════════════════════════════════════
    // Phase 1 - customer-facing payment page served at /pay/{token}

    Route::prefix('pay')->middleware('throttle:60,1')->group(function () {
        Route::get('/{token}',                 [PublicPaymentController::class, 'show']);
        Route::post('/{token}/initiate',       [PublicPaymentController::class, 'initiate']);
        Route::get('/{token}/status',          [PublicPaymentController::class, 'status']);
        Route::post('/{token}/mpesa-callback', [PublicPaymentController::class, 'mpesaCallback']);
        Route::post('/{token}/upload-proof',   [PublicPaymentController::class, 'uploadProof']);
        Route::post('/{token}/mpesa-confirm',  [PublicPaymentController::class, 'confirmMpesa']);
        Route::post('/{token}/paystack-verify', [PublicPaymentController::class, 'verifyPaystack']);
    });

    // ═══ PUBLIC APIS ══════════════════════════════════════════════════════════

    Route::middleware(['throttle:public-api', 'api.key:optional'])->group(function () {

        Route::prefix('products')->group(function () {
            Route::get('/',             [ProductController::class, 'index']);
            Route::get('/featured',     [ProductController::class, 'featured']);
            Route::get('/new-arrivals', [ProductController::class, 'newArrivals']);
            Route::get('/{slug}',       [ProductController::class, 'show']);
            Route::get('/{id}/variants', [ProductController::class, 'variants']);
            Route::get('/search',       [ProductController::class, 'search'])
                ->middleware('throttle:search')->withoutMiddleware('throttle:public-api');
            Route::get('/{id}/reviews', [ProductReviewController::class, 'index']);
            Route::get('/reviews/{id}', [ProductReviewController::class, 'show']);
        });

        Route::prefix('categories')->group(function () {
            Route::get('/',              [CategoryController::class, 'index']);
            Route::get('/{slug}',        [CategoryController::class, 'show']);
            Route::get('/{id}/products', [CategoryController::class, 'products']);
        });

        Route::prefix('pages')->group(function () {
            Route::get('/',       [ContentPageController::class, 'index']);
            Route::get('/{slug}', [ContentPageController::class, 'show']);
        });

        Route::prefix('shipping')->group(function () {
            Route::post('/calculate',          [ShippingController::class, 'calculate']);
            Route::get('/zones',               [ShippingController::class, 'zones']);
            Route::get('/pickup-locations',    [ShippingController::class, 'pickupLocations']);
            Route::get('/available-countries', [ShippingController::class, 'availableCountries']);
        });

        Route::prefix('countries')->group(function () {
            Route::get('/',       [CountryController::class, 'index']);
            Route::get('/{code}', [CountryController::class, 'show']);
        });

        Route::get('/payment-methods', [PaymentMethodController::class, 'available']);

        Route::prefix('settings')->group(function () {
            Route::get('/languages', [SettingController::class, 'languages']);
            Route::get('/currencies', [SettingController::class, 'currencies']);
            Route::get('/app-info',  [SettingController::class, 'appInfo']);
        });
    });

    // ═══ AUTHENTICATED ROUTES ═════════════════════════════════════════════════

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        // ── Customer portal ─────────────────────────────────────────────────

        Route::prefix('customer')->group(function () {
            Route::get('/orders',                     [OrderController::class, 'customerOrders']);
            Route::get('/orders/{id}',                [OrderController::class, 'customerOrderDetails']);
            Route::post('/orders/{id}/cancel',        [OrderController::class, 'cancelOrder']);
            Route::get('/orders/{id}/shipment',       [OrderController::class, 'shipmentStatus']);
            Route::get('/orders/{id}/tracking',       [OrderController::class, 'trackingHistory']);
            Route::post('/orders/{id}/return',        [ReturnController::class, 'request']);
            Route::get('/returns',                    [ReturnController::class, 'customerReturns']);
            Route::get('/returns/{id}',               [ReturnController::class, 'customerReturnDetails']);
            Route::post('/returns/{id}/cancel',       [ReturnController::class, 'cancelRequest']);
            Route::get('/addresses',                  [CustomerController::class, 'addresses']);
            Route::post('/addresses',                 [CustomerController::class, 'storeAddress']);
            Route::put('/addresses/{id}',             [CustomerController::class, 'updateAddress']);
            Route::delete('/addresses/{id}',          [CustomerController::class, 'deleteAddress']);
            Route::put('/addresses/{id}/set-default', [CustomerController::class, 'setDefaultAddress']);
            Route::get('/wishlist',                   [CustomerController::class, 'wishlist']);
            Route::post('/wishlist/{productId}',      [CustomerController::class, 'addToWishlist']);
            Route::delete('/wishlist/{productId}',    [CustomerController::class, 'removeFromWishlist']);
            Route::post('/products/{id}/reviews',     [ProductReviewController::class, 'store']);
            Route::put('/reviews/{id}',               [ProductReviewController::class, 'update']);
            Route::delete('/reviews/{id}',            [ProductReviewController::class, 'destroy']);
        });

        // ── Cart ────────────────────────────────────────────────────────────

        Route::prefix('cart')->group(function () {
            Route::get('/',                 [CartController::class, 'index']);
            Route::post('/items',           [CartController::class, 'addItem']);
            Route::put('/items/{id}',       [CartController::class, 'updateItem']);
            Route::delete('/items/{id}',    [CartController::class, 'removeItem']);
            Route::delete('/clear',         [CartController::class, 'clear']);
            Route::post('/apply-coupon',    [CartController::class, 'applyCoupon']);
            Route::delete('/remove-coupon', [CartController::class, 'removeCoupon']);
        });

        Route::post('/checkout',            [OrderController::class, 'checkout']);
        Route::post('/orders/{id}/payment', [PaymentController::class, 'initiatePayment']);

        // ═══ COMMUNICATIONS - all authenticated staff ════════════════════
        // Comments, channels, notifications and the sidebar badge count are
        // accessible to every role (admin, tailor, pos_clerk, etc.) because
        // all staff members can receive messages and notifications.

        Route::prefix('admin')->middleware('throttle:admin-api')->group(function () {

            Route::get('/sidebar-badges', [DashboardController::class, 'sidebarBadges']);
            Route::get('/dashboard',      [DashboardController::class, 'index']);

            // ── In-App Notifications ─────────────────────────────────────────
            Route::prefix('notifications')->group(function () {
                Route::get('/',             [NotificationController::class, 'index']);
                Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
                Route::post('/read-all',    [NotificationController::class, 'markAllRead']);
                Route::post('/{id}/read',   [NotificationController::class, 'markRead']);
                Route::delete('/{id}',      [NotificationController::class, 'destroy']);
            });

            // ── Comments (Phase 1 - unified thread system) ───────────────────
            Route::prefix('comments')->group(function () {
                Route::get('/',        [CommentController::class, 'index']);
                Route::post('/',       [CommentController::class, 'store']);
                Route::patch('/{id}',  [CommentController::class, 'update']);
                Route::delete('/{id}', [CommentController::class, 'destroy']);
                Route::get('/users',   [CommentController::class, 'users']);
            });

            // ── Channels - DMs and Spaces (Phase 3) ──────────────────────────
            Route::prefix('channels')->group(function () {
                Route::get('/',                                    [ChannelController::class, 'index']);
                Route::post('/',                                   [ChannelController::class, 'store']);
                Route::post('/dm',                                 [ChannelController::class, 'openDm']);
                Route::post('/context',                            [ChannelController::class, 'findOrCreateContext']);
                Route::get('/attachments/serve',                   [ChannelController::class, 'serveAttachment']);
                Route::post('/attachments',                        [ChannelController::class, 'uploadAttachment']);
                // ── Entity search for # tagging in messages (Phase 6) ────
                Route::get('/entity-search',                       [ChannelController::class, 'entitySearch']);
                Route::get('/{id}',                                [ChannelController::class, 'show']);
                Route::patch('/{id}',                              [ChannelController::class, 'update']);
                Route::delete('/{id}',                             [ChannelController::class, 'destroy']);
                Route::post('/{id}/members',                       [ChannelController::class, 'addMember']);
                Route::delete('/{id}/members/{userId}',            [ChannelController::class, 'removeMember']);
                Route::get('/{id}/messages',                       [ChannelController::class, 'messages']);
                Route::post('/{id}/messages',                      [ChannelController::class, 'sendMessage']);
                Route::patch('/{id}/messages/{msgId}',             [ChannelController::class, 'editMessage']);
                Route::delete('/{id}/messages/{msgId}',            [ChannelController::class, 'deleteMessage']);
                Route::post('/{id}/messages/{msgId}/react',        [ChannelController::class, 'react']);
                Route::post('/{id}/read',                          [ChannelController::class, 'markRead']);
                // ── Per-user thread dismissal — order/production threads only ───
                Route::post('/{id}/dismiss',                       [ChannelController::class, 'dismiss']);
                Route::post('/{id}/undismiss',                     [ChannelController::class, 'undismiss']);
            });

            // ── Global search — powers CommandPalette (⌘K) ─────────────────────
            Route::get('/search', [GlobalSearchController::class, 'search']);

            // ── Push subscriptions (PWA Web Push + React Native Expo) ────────
            Route::prefix('push')->group(function () {
                // Web Push (VAPID) — PWA / browser ServiceWorker
                Route::get('/vapid-public-key', [PushController::class, 'vapidPublicKey']);
                Route::post('/subscribe',       [PushController::class, 'subscribe']);
                Route::delete('/unsubscribe',   [PushController::class, 'unsubscribe']);

                // Expo Push Service — React Native mobile app
                Route::post('/subscribe-expo',   [PushController::class, 'subscribeExpo']);
                Route::post('/unsubscribe-expo', [PushController::class, 'unsubscribeExpo']);
            });

        });

        // ── Intelligence — proactive signals (Features 1–6, 9, 10) ──────────
        // Each endpoint surfaces module-specific business data (inventory,
        // production, customer, financial), so it's gated by that module's
        // view permission rather than left open to every authenticated staff
        // member like comments/channels/notifications above. Previously this
        // whole block had no permission check at all, so e.g. a tailor or
        // pos_clerk could read customer churn-risk data and budget overruns,
        // and could trigger auto-reorder purchase orders.
        Route::middleware(['auth:sanctum', 'throttle:admin-api'])->prefix('admin/intelligence')->group(function () {
            Route::middleware('permission:inventory.view,sanctum')->group(function () {
                Route::get('/reorder-suggestions',           [IntelligenceController::class, 'reorderSuggestions']);
                Route::get('/material-shortages',            [IntelligenceController::class, 'materialShortages']);
                Route::post('/material-shortages/preflight', [IntelligenceController::class, 'materialShortagesPreflight']);
            });
            Route::post('/auto-reorder/{itemId}', [IntelligenceController::class, 'triggerAutoReorder'])
                ->middleware('permission:procurement.create,sanctum');
            Route::get('/tailor-workload', [IntelligenceController::class, 'tailorWorkload'])
                ->middleware('permission:production.view,sanctum');
            Route::get('/churn-risk', [IntelligenceController::class, 'churnRisk'])
                ->middleware('permission:customers.view,sanctum');
            Route::get('/budget-warnings', [IntelligenceController::class, 'budgetWarnings'])
                ->middleware('permission:expenses.view,sanctum');
            // smart-tasks and entity-previews stay open to all authenticated
            // staff: smart-tasks is a personal to-do aggregation scoped to the
            // current user, and entity-previews only returns data for
            // entities the caller explicitly references (e.g. while @-tagging
            // an order in a channel message) rather than a business-wide list.
            Route::get('/smart-tasks',          [IntelligenceController::class, 'smartTasks']);
            Route::post('/entity-previews',     [IntelligenceController::class, 'entityPreviews']);
        });

        // ═══ PERMISSION-BASED ROUTES (all authenticated staff) ══════════════
        // Every group below is guarded by permission: middleware, not role:.
        // This means any permission can be granted/revoked per user independently
        // of their role — full flexibility without code changes.

        Route::middleware(['auth:sanctum', 'throttle:admin-api'])->prefix('admin')->group(function () {

            // ── Profile — every authenticated staff member ───────────────────
            Route::prefix('profile')->group(function () {
                Route::get('/',                           [ProfileController::class, 'show']);
                Route::put('/',                           [ProfileController::class, 'update']);
                Route::post('/password',                  [ProfileController::class, 'changePassword']);
                Route::post('/avatar',                    [ProfileController::class, 'uploadAvatar']);
                Route::get('/sessions',                   [ProfileController::class, 'sessions']);
                Route::post('/sessions/revoke-all',       [ProfileController::class, 'revokeAllSessions']);
                Route::post('/sessions/{tokenId}/revoke', [ProfileController::class, 'revokeSession']);
                Route::get('/activity',                   [ProfileController::class, 'activity']);
            });

            // ── Users by role — all staff (assignee dropdowns) ───────────────
            Route::get('/users/role/{role}', [UserController::class, 'byRole']);

            // ── Time Clock — every authenticated staff member ────────────────
            // Geofenced clock in/out + breaks. No permission gate, same as
            // /profile above - this is a basic self-service action.
            Route::prefix('time-clock')->group(function () {
                Route::get('/status',       [TimeClockController::class, 'status']);
                Route::get('/outlets',      [TimeClockController::class, 'availableOutlets']);
                Route::post('/clock-in',    [TimeClockController::class, 'clockIn']);
                Route::post('/clock-out',   [TimeClockController::class, 'clockOut']);
                Route::post('/break/start', [TimeClockController::class, 'startBreak']);
                Route::post('/break/end',   [TimeClockController::class, 'endBreak']);
                Route::get('/my-entries',   [TimeClockController::class, 'myEntries']);
            });

            // ── Activity logs ────────────────────────────────────────────────
            Route::middleware('permission:users.view,sanctum')->prefix('activity-logs')->group(function () {
                Route::get('/',        [AuditLogController::class, 'index']);
                Route::get('/export',  [AuditLogController::class, 'export']);
                Route::post('/clear',  [AuditLogController::class, 'clear'])
                    ->middleware('permission:activity_logs.manage,sanctum');
                Route::get('/{id}',    [AuditLogController::class, 'show']);
            });

            // ── Trash / Recycle Bin (super_admin only) ────────────────────────
            // BUGFIX: this group previously had no role/permission middleware
            // at all - only auth:sanctum from the outer wrapper - despite the
            // comment above claiming "super_admin only". Any authenticated
            // staff member (pos_clerk, tailor, etc.) could restore or
            // permanently force-delete soft-deleted records across every
            // model in the system. Added the role check that was always
            // intended here.
            Route::middleware(['role:super_admin'])->prefix('trash')->group(function () {
                Route::get('/',                          [TrashController::class, 'summary']);
                Route::get('/{model}',                   [TrashController::class, 'index']);
                Route::post('/{model}/restore-all',      [TrashController::class, 'restoreAll']);
                Route::delete('/{model}/empty',          [TrashController::class, 'emptyModel']);
                Route::post('/{model}/{id}/restore',     [TrashController::class, 'restore']);
                Route::delete('/{model}/{id}',           [TrashController::class, 'forceDelete']);
            });

            // ── Products ─────────────────────────────────────────────────────
            Route::middleware('permission:products.view,sanctum')->prefix('products')->group(function () {
                Route::get('/',                               [ProductController::class, 'adminIndex']);
                Route::get('/export-template',                [ProductController::class, 'exportTemplate']);
                Route::get('/{id}',                           [ProductController::class, 'adminShow']);
                Route::get('/{productId}/tax-rates',          [TaxRateController::class, 'productRates']);
                Route::prefix('/{productId}/bom')->group(function () {
                    Route::get('/',                    [BomController::class, 'index']);
                    Route::get('/{bomId}',             [BomController::class, 'show']);
                    Route::get('/{bomId}/feasibility', [BomController::class, 'feasibility']);
                });

                // Write actions require create/edit/delete permissions
                Route::post('/',                              [ProductController::class, 'store'])
                    ->middleware('permission:products.create,sanctum');
                Route::post('/bulk-import',                   [ProductController::class, 'bulkImport'])
                    ->middleware('permission:products.import,sanctum');
                Route::put('/{id}',                           [ProductController::class, 'update'])
                    ->middleware('permission:products.edit,sanctum');
                Route::delete('/{id}',                        [ProductController::class, 'destroy'])
                    ->middleware('permission:products.delete,sanctum');
                Route::put('/{id}/publish',                   [ProductController::class, 'publish'])
                    ->middleware('permission:products.edit,sanctum');
                Route::put('/{id}/archive',                   [ProductController::class, 'archive'])
                    ->middleware('permission:products.edit,sanctum');
                Route::post('/{id}/images',                   [ProductController::class, 'uploadImages'])
                    ->middleware('permission:products.edit,sanctum');
                Route::put('/{id}/images/reorder',            [ProductController::class, 'reorderImages'])
                    ->middleware('permission:products.edit,sanctum');
                Route::put('/{id}/images/{imageId}/primary',  [ProductController::class, 'setPrimaryImage'])
                    ->middleware('permission:products.edit,sanctum');
                Route::delete('/{id}/images/{imageId}',       [ProductController::class, 'deleteImage'])
                    ->middleware('permission:products.edit,sanctum');
                Route::post('/{id}/variants',                 [ProductController::class, 'addVariant'])
                    ->middleware('permission:products.edit,sanctum');
                Route::put('/{productId}/variants/{variantId}',    [ProductController::class, 'updateVariant'])
                    ->middleware('permission:products.edit,sanctum');
                Route::delete('/{productId}/variants/{variantId}', [ProductController::class, 'deleteVariant'])
                    ->middleware('permission:products.edit,sanctum');
                Route::post('/{productId}/tax-rates',         [TaxRateController::class, 'syncProductRates'])
                    ->middleware('permission:products.edit,sanctum');
                Route::prefix('/{productId}/bom')->group(function () {
                    Route::post('/',                   [BomController::class, 'store'])
                        ->middleware('permission:products.edit,sanctum');
                    Route::put('/{bomId}',             [BomController::class, 'update'])
                        ->middleware('permission:products.edit,sanctum');
                    Route::delete('/{bomId}',          [BomController::class, 'destroy'])
                        ->middleware('permission:products.edit,sanctum');
                    Route::put('/{bomId}/activate',    [BomController::class, 'activate'])
                        ->middleware('permission:products.edit,sanctum');
                });
            });

            // ── Categories ───────────────────────────────────────────────────
            Route::middleware('permission:products.view,sanctum')->prefix('categories')->group(function () {
                Route::get('/',        [CategoryController::class, 'adminIndex']);
                Route::get('/{id}',    [CategoryController::class, 'adminShow']);

                Route::post('/',                   [CategoryController::class, 'store'])
                    ->middleware('permission:products.edit,sanctum');
                Route::put('/reorder',             [CategoryController::class, 'reorder'])
                    ->middleware('permission:products.edit,sanctum');
                Route::put('/{id}',                [CategoryController::class, 'update'])
                    ->middleware('permission:products.edit,sanctum');
                Route::delete('/{id}',             [CategoryController::class, 'destroy'])
                    ->middleware('permission:products.delete,sanctum');
                Route::put('/{id}/toggle',         [CategoryController::class, 'toggleStatus'])
                    ->middleware('permission:products.edit,sanctum');
                Route::post('/{id}/image',         [CategoryController::class, 'uploadImage'])
                    ->middleware('permission:products.edit,sanctum');
                Route::delete('/{id}/image',       [CategoryController::class, 'deleteImage'])
                    ->middleware('permission:products.edit,sanctum');
            });

            // ── Reviews ──────────────────────────────────────────────────────
            Route::middleware('permission:products.edit,sanctum')->prefix('reviews')->group(function () {
                Route::get('/',              [ProductReviewController::class, 'adminIndex']);
                Route::put('/{id}/approve',  [ProductReviewController::class, 'approve']);
                Route::put('/{id}/reject',   [ProductReviewController::class, 'reject']);
                Route::delete('/{id}/force', [ProductReviewController::class, 'forceDelete']);
            });

            // ── Orders ───────────────────────────────────────────────────────
            Route::middleware('permission:orders.view,sanctum')->prefix('orders')->group(function () {
                Route::get('/',                          [OrderController::class, 'index']);
                // Must come before GET /{id} - otherwise Laravel matches
                // "export" as the {id} parameter and routes to show() instead.
                Route::get('/export',                    [OrderController::class, 'exportCsv']);
                Route::get('/{id}',                      [OrderController::class, 'show']);
                Route::get('/{id}/audit-log',            [OrderController::class, 'auditLog']);
                Route::get('/{id}/invoice',              [OrderController::class, 'generateInvoice']);
                Route::get('/{id}/activity-log',         [OrderController::class, 'activityLog']);
                Route::get('/{id}/payment-link',         [OrderController::class, 'paymentLink']);

                Route::put('/{id}/status',               [OrderController::class, 'updateStatus'])
                    ->middleware('permission:orders.edit,sanctum');
                Route::post('/{id}/notes',               [OrderController::class, 'addNote'])
                    ->middleware('permission:orders.edit,sanctum');
                Route::post('/{id}/attach-customer',     [OrderController::class, 'attachCustomer'])
                    ->middleware('permission:orders.edit|orders.create,sanctum');
                Route::post('/{id}/void',                [OrderController::class, 'voidOrder'])
                    ->middleware('permission:orders.cancel,sanctum');
                Route::post('/{id}/refund',              [OrderController::class, 'refund'])
                    ->middleware('permission:orders.refund,sanctum');
                Route::post('/{id}/payments',            [OrderController::class, 'addPayment'])
                    ->middleware('permission:payments.record,sanctum');
                Route::post('/{id}/payments/{paymentId}/verify-mpesa',    [PaymentController::class, 'verifyMpesa'])
                    ->middleware('permission:payments.record,sanctum');
                Route::post('/{id}/payments/{paymentId}/verify-paystack', [PaymentController::class, 'verifyPaystack'])
                    ->middleware('permission:payments.record,sanctum');
                Route::post('/{id}/resend-confirmation', [OrderController::class, 'resendConfirmation'])
                    ->middleware('permission:orders.edit,sanctum');
                Route::patch('/{id}/shipping-fee',       [OrderController::class, 'setShippingFee'])
                    ->middleware('permission:orders.set_shipping_fee,sanctum');
                Route::patch('/{id}/items/{itemId}/price', [OrderController::class, 'adjustItemPrice'])
                    ->middleware('permission:orders.edit,sanctum');
                Route::post('/{id}/set-deposit',         [OrderController::class, 'setDeposit'])
                    ->middleware('permission:orders.set_deposit,sanctum');
                Route::post('/{id}/update-currency',     [OrderController::class, 'updateCurrency'])
                    ->middleware('permission:orders.edit,sanctum');
                Route::post('/{id}/shipments',           [ShipmentController::class, 'create'])
                    ->middleware('permission:shipment.create,sanctum');
                Route::post('/{id}/payment',             [PaymentController::class, 'initiatePayment'])
                    ->middleware('permission:payments.record,sanctum');
            });

            // ── Shipments ────────────────────────────────────────────────────
            Route::middleware('permission:shipment.view,sanctum')->prefix('shipments')->group(function () {
                Route::get('/',           [ShipmentController::class, 'index']);
                Route::get('/{id}',       [ShipmentController::class, 'show']);
                Route::get('/{id}/tracking',  [ShipmentController::class, 'getTracking']);
                Route::get('/{id}/audit-log', [ShipmentController::class, 'auditLog']);
                Route::get('/{id}/attachments/{attachmentId}',                       [ShipmentController::class, 'serveShipmentAttachment']);
                Route::get('/{id}/tracking/{trackingId}/attachments/{attachmentId}', [ShipmentController::class, 'serveTrackingAttachment']);

                // Editing shipment details is now gated by its own permission,
                // separate from adding tracking events.
                Route::put('/{id}',                  [ShipmentController::class, 'update'])
                    ->middleware('permission:shipment.edit,sanctum');
                Route::post('/{id}/tracking',        [ShipmentController::class, 'addTracking'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');
                Route::post('/{id}/mark-delivered',  [ShipmentController::class, 'markDelivered'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');
                Route::post('/{id}/cancel',          [ShipmentController::class, 'cancel'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');
                Route::post('/{id}/upload-attachment',               [ShipmentController::class, 'uploadShipmentAttachment'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');
                Route::post('/{id}/tracking/{trackingId}/upload-attachment', [ShipmentController::class, 'uploadTrackingAttachment'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');

                // Every ShipmentAttachment row carries shipment_id regardless of
                // whether it's attached to the shipment itself or one of its
                // tracking events (attachable_type/attachable_id), so delete and
                // visibility-toggle only ever need the flat {id}/attachments/{attachmentId}
                // shape - there's no separate tracking-nested variant of these two.
                Route::delete('/{id}/attachments/{attachmentId}', [ShipmentController::class, 'deleteAttachment'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');
                Route::patch('/{id}/attachments/{attachmentId}',  [ShipmentController::class, 'updateAttachmentVisibility'])
                    ->middleware('permission:shipment.manage_tracking,sanctum');
            });

            // ── Returns ──────────────────────────────────────────────────────
            Route::middleware('permission:orders.manage_returns,sanctum')->prefix('returns')->group(function () {
                Route::get('/',                     [ReturnController::class, 'index']);
                Route::get('/{id}',                 [ReturnController::class, 'show']);
                Route::put('/{id}/status',          [ReturnController::class, 'updateStatus']);
                Route::post('/{id}/approve',        [ReturnController::class, 'approve']);
                Route::post('/{id}/reject',         [ReturnController::class, 'reject']);
                Route::post('/{id}/process-refund', [ReturnController::class, 'processRefund']);
            });

            // ── Customers ────────────────────────────────────────────────────
            Route::middleware('permission:customers.view,sanctum')->prefix('customers')->group(function () {
                Route::get('/',              [CustomerController::class, 'index']);
                Route::get('/{id}',          [CustomerController::class, 'show']);
                Route::get('/{id}/orders',   [CustomerController::class, 'customerOrders']);
                Route::get('/{id}/statistics', [CustomerController::class, 'statistics']);

                Route::post('/',                      [CustomerController::class, 'store'])
                    ->middleware('permission:customers.create,sanctum');
                Route::post('/quick-create',          [CustomerController::class, 'quickCreate'])
                    ->middleware('permission:customers.create,sanctum');
                Route::put('/{id}',                   [CustomerController::class, 'update'])
                    ->middleware('permission:customers.edit,sanctum');
                Route::delete('/{id}',                [CustomerController::class, 'destroy'])
                    ->middleware('permission:customers.delete,sanctum');
                Route::put('/{id}/status',            [CustomerController::class, 'updateStatus'])
                    ->middleware('permission:customers.edit,sanctum');
                Route::post('/{id}/invite-to-portal', [CustomerController::class, 'inviteToPortal'])
                    ->middleware('permission:customers.invite,sanctum');
            });

            // ── Users ────────────────────────────────────────────────────────
            Route::middleware('permission:users.view,sanctum')->prefix('users')->group(function () {
                Route::get('/',         [UserController::class, 'index']);
                Route::get('/export',   [UserController::class, 'export']);
                Route::get('/{id}',     [UserController::class, 'show']);
                Route::get('/{id}/activity',    [AuditLogController::class, 'userActivity']);
                Route::get('/{id}/permissions', [UserController::class, 'permissions']);

                Route::post('/',                    [UserController::class, 'store'])
                    ->middleware('permission:users.create,sanctum');
                Route::post('/bulk-update-status',  [UserController::class, 'bulkUpdateStatus'])
                    ->middleware('permission:users.edit,sanctum');
                Route::put('/{id}',                 [UserController::class, 'update'])
                    ->middleware('permission:users.edit,sanctum');
                Route::delete('/{id}',              [UserController::class, 'destroy'])
                    ->middleware('permission:users.delete,sanctum');
                Route::put('/{id}/role',            [UserController::class, 'updateRole'])
                    ->middleware('permission:users.edit,sanctum');
                Route::put('/{id}/status',          [UserController::class, 'updateStatus'])
                    ->middleware('permission:users.edit,sanctum');
                Route::post('/{id}/reset-password', [UserController::class, 'resetPassword'])
                    ->middleware('permission:users.edit,sanctum');
                Route::post('/{id}/promote-to-staff', [UserController::class, 'promoteToStaff'])
                    ->middleware('permission:users.edit,sanctum');
            });

            // ── POS (admin-prefixed) ──────────────────────────────────────────
            Route::middleware('permission:pos.access,sanctum')->prefix('pos')->group(function () {
                Route::get('outlets',                   [PosController::class, 'outlets']);
                Route::get('register/status',           [PosController::class, 'registerStatus']);
                Route::get('register/history',          [PosController::class, 'registerHistory']);
                Route::get('products',                  [PosController::class, 'products']);
                Route::get('products/search',           [PosController::class, 'searchProducts']);
                Route::get('sales',                     [PosController::class, 'sales']);
                Route::get('sales/{id}',                [PosController::class, 'saleDetail']);
                Route::get('returns',                   [PosController::class, 'returns']);
                Route::get('reports/daily',             [PosController::class, 'dailySummary']);
                Route::get('reports/end-of-day',        [PosController::class, 'endOfDay']);
                Route::get('reports/user-eod',          [PosController::class, 'getUserEodReport']);
                Route::post('reports/user-eod',         [PosController::class, 'saveUserEodReport']);
                // Admin EoD report listing + delivery settings (settings.view / settings.edit gated)
                Route::get('reports/eod-admin',         [PosController::class, 'adminListEodReports'])
                    ->middleware('permission:settings.view,sanctum');
                Route::get('reports/eod-admin/{id}',    [PosController::class, 'adminGetEodReport'])
                    ->middleware('permission:settings.view,sanctum');
                Route::get('reports/eod-settings',      [PosController::class, 'getEodDeliverySettings'])
                    ->middleware('permission:settings.view,sanctum');
                Route::post('reports/eod-settings',     [PosController::class, 'saveEodDeliverySettings'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::post('reports/eod-settings/test', [PosController::class, 'testEodDelivery'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::get('customers/search',          [PosController::class, 'searchCustomers']);
                Route::get('pending-order/open',        [PosController::class, 'getOpenPendingOrder']);
                // Read-only, active-only shipping methods for the POS checkout picker.
                // Uses PosController (not ShippingController::adminMethods, which is
                // gated by settings.view — a back-office permission pos_clerk and
                // outlet_manager don't have). Same fix pattern as payment-methods:
                // checkout-facing reads must not depend on settings-management access.
                Route::get('shipping-methods',          [PosController::class, 'shippingMethods']);
                // Read-only checkout reference data: app_country (for
                // international-order detection) + tax_inclusive/default
                // rate/active rates. Reuses SettingController's cached
                // settings read, exposed through pos.access instead of
                // settings.view, which pos_clerk/outlet_manager don't have.
                // Same fix pattern as payment-methods and shipping-methods
                // above. NOTE: requires adding
                // SettingController::posCheckoutConfig() - see chat for the
                // method body, since SettingController.php itself wasn't
                // part of this edit's uploaded files.
                Route::get('checkout-config',           [SettingController::class, 'posCheckoutConfig']);

                Route::post('register/open',            [PosController::class, 'openRegister'])
                    ->middleware('permission:pos.open_register,sanctum');
                Route::post('register/close',           [PosController::class, 'closeRegister'])
                    ->middleware('permission:pos.close_register,sanctum');
                Route::post('sales',                    [PosController::class, 'createSale']);
                Route::post('pending-order',            [PosController::class, 'createPendingOrder']);
                Route::patch('pending-order/{id}',      [PosController::class, 'updatePendingOrder']);
                Route::post('pending-order/{id}/pay',   [PosController::class, 'recordPosPay']);
                Route::post('sales/{id}/void',          [PosController::class, 'voidSale'])
                    ->middleware('permission:pos.void,sanctum');
                Route::post('sales/{id}/email-receipt', [PosController::class, 'emailReceipt']);
                Route::post('returns',                  [PosController::class, 'processReturn'])
                    ->middleware('permission:pos.returns,sanctum');
            });

            // ── Inventory ────────────────────────────────────────────────────
            Route::middleware('permission:inventory.view,sanctum')->prefix('inventory')->group(function () {
                Route::get('/',                      [InventoryController::class, 'index']);
                Route::get('/low-stock',             [InventoryController::class, 'lowStock']);
                Route::get('/valuation',             [InventoryController::class, 'valuation']);
                Route::get('/{productId}/movements', [InventoryController::class, 'movements']);
                Route::get('/low-stock-alerts',      [\App\Http\Controllers\Api\LowStockAlertsController::class, 'index']);

                Route::post('/adjust',               [InventoryController::class, 'adjust'])
                    ->middleware('permission:inventory.adjust,sanctum');
                Route::post('/transfer',             [InventoryController::class, 'transfer'])
                    ->middleware('permission:inventory.transfer,sanctum');
                Route::put('/thresholds',            [InventoryController::class, 'setThreshold'])
                    ->middleware('permission:inventory.adjust,sanctum');
                Route::put('/thresholds/bulk',       [InventoryController::class, 'bulkSetThreshold'])
                    ->middleware('permission:inventory.adjust,sanctum');
                Route::put('/low-stock-alerts/{id}/threshold', [\App\Http\Controllers\Api\LowStockAlertsController::class, 'updateThreshold'])
                    ->middleware('permission:inventory.adjust,sanctum');

                // Stock transfers
                Route::get('/transfers',               [StockTransfersController::class, 'index']);
                Route::get('/transfers/stats',         [StockTransfersController::class, 'index']);
                Route::get('/transfers/{id}',          [StockTransfersController::class, 'show']);
                Route::get('/transfers/{id}/audit-log',[StockTransfersController::class, 'auditLog']);
                Route::post('/transfers',              [StockTransfersController::class, 'store'])
                    ->middleware('permission:inventory.transfer,sanctum');
                Route::put('/transfers/{id}/approve',  [StockTransfersController::class, 'approve'])
                    ->middleware('permission:inventory.approve,sanctum');
                Route::put('/transfers/{id}/dispatch', [StockTransfersController::class, 'dispatch'])
                    ->middleware('permission:inventory.transfer,sanctum');
                Route::put('/transfers/{id}/receive',  [StockTransfersController::class, 'receive'])
                    ->middleware('permission:inventory.transfer,sanctum');
                Route::put('/transfers/{id}/cancel',   [StockTransfersController::class, 'cancel'])
                    ->middleware('permission:inventory.transfer,sanctum');

                // Stock adjustments
                Route::prefix('adjustments')->group(function () {
                    Route::get('/',              [StockAdjustmentsController::class, 'index']);
                    Route::get('/pending',       [StockAdjustmentsController::class, 'pending']);
                    Route::get('/reason-codes',  [StockAdjustmentsController::class, 'reasonCodes']);
                    Route::get('/stats',         [StockAdjustmentsController::class, 'index']);
                    Route::get('/{id}',          [StockAdjustmentsController::class, 'show']);
                    Route::get('/{id}/audit-log',[StockAdjustmentsController::class, 'auditLog']);
                    Route::post('/',             [StockAdjustmentsController::class, 'store'])
                        ->middleware('permission:inventory.adjust,sanctum');
                    Route::put('/{id}/approve',  [StockAdjustmentsController::class, 'approve'])
                        ->middleware('permission:inventory.approve,sanctum');
                    Route::put('/{id}/reject',   [StockAdjustmentsController::class, 'reject'])
                        ->middleware('permission:inventory.approve,sanctum');
                    Route::put('/{id}/reverse',  [StockAdjustmentsController::class, 'reverse'])
                        ->middleware('permission:inventory.approve,sanctum');
                });

                // Raw materials
                Route::prefix('materials')->group(function () {
                    Route::get('/',                  [\App\Http\Controllers\Api\RawMaterialsController::class, 'index']);
                    Route::get('/low-stock',         [\App\Http\Controllers\Api\RawMaterialsController::class, 'lowStock']);
                    Route::get('/{id}',              [\App\Http\Controllers\Api\RawMaterialsController::class, 'show']);
                    Route::get('/{id}/transactions', [\App\Http\Controllers\Api\RawMaterialsController::class, 'transactions']);
                    Route::post('/',                 [\App\Http\Controllers\Api\RawMaterialsController::class, 'store'])
                        ->middleware('permission:inventory.adjust,sanctum');
                    Route::put('/{id}',              [\App\Http\Controllers\Api\RawMaterialsController::class, 'update'])
                        ->middleware('permission:inventory.adjust,sanctum');
                    Route::delete('/{id}',           [\App\Http\Controllers\Api\RawMaterialsController::class, 'destroy'])
                        ->middleware('permission:inventory.adjust,sanctum');
                    Route::post('/{id}/receive',     [\App\Http\Controllers\Api\RawMaterialsController::class, 'receive'])
                        ->middleware('permission:procurement.receive,sanctum');
                    Route::post('/{id}/adjust',      [\App\Http\Controllers\Api\RawMaterialsController::class, 'adjust'])
                        ->middleware('permission:inventory.adjust,sanctum');
                });
            });

            // ── Stock Levels ──────────────────────────────────────────────────
            Route::middleware('permission:inventory.view,sanctum')->prefix('inventory/stock-levels')->group(function () {
                Route::get('/',                       [StockLevelsController::class, 'index']);
                Route::get('/by-product/{productId}', [StockLevelsController::class, 'byProduct']);
                Route::get('/{id}',                   [StockLevelsController::class, 'show']);
                Route::get('/{id}/history',           [StockLevelsController::class, 'history']);
                Route::post('/opening',               [StockLevelsController::class, 'setOpeningStock'])
                    ->middleware('permission:inventory.adjust,sanctum');
                Route::put('/{id}',                   [StockLevelsController::class, 'update'])
                    ->middleware('permission:inventory.adjust,sanctum');
            });

            // ── Outlets ───────────────────────────────────────────────────────
            Route::middleware('permission:outlets.view,sanctum')->prefix('outlets')->group(function () {
                Route::get('/',                [OutletController::class, 'index']);
                Route::get('/{id}',            [OutletController::class, 'show']);
                Route::get('/{id}/inventory',  [OutletController::class, 'inventory']);
                Route::get('/{id}/statistics', [OutletController::class, 'statistics']);
                Route::post('/',               [OutletController::class, 'store'])
                    ->middleware('permission:outlets.create,sanctum');
                Route::put('/{id}',            [OutletController::class, 'update'])
                    ->middleware('permission:outlets.edit,sanctum');
                Route::delete('/{id}',         [OutletController::class, 'destroy'])
                    ->middleware('permission:outlets.delete,sanctum');
            });

            // ── Attendance (team time clock oversight) ──────────────────────────
            Route::middleware('permission:attendance.view_team,sanctum')->prefix('attendance')->group(function () {
                Route::get('/entries',      [TimeClockController::class, 'teamEntries']);
                Route::get('/entries/{id}', [TimeClockController::class, 'showEntry']);
                Route::get('/flagged',      [TimeClockController::class, 'flagged']);

                Route::put('/entries/{id}', [TimeClockController::class, 'updateEntry'])
                    ->middleware('permission:attendance.manage,sanctum');
            });

            // ── Procurement ───────────────────────────────────────────────────
            Route::middleware('permission:procurement.view,sanctum')->group(function () {
                Route::prefix('suppliers')->group(function () {
                    Route::get('/',                     [SupplierController::class, 'index']);
                    Route::get('/export',               [SupplierController::class, 'export']);
                    Route::get('/{id}',                 [SupplierController::class, 'show']);
                    Route::get('/{id}/purchase-orders', [SupplierController::class, 'purchaseOrders']);
                    Route::get('/{id}/performance',     [SupplierController::class, 'performance']);
                    Route::get('/{id}/contacts',        [SupplierController::class, 'contactHistory']);
                    Route::get('/{id}/documents',       [SupplierController::class, 'documents']);
                    Route::post('/',                    [SupplierController::class, 'store'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::put('/{id}',                 [SupplierController::class, 'update'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::delete('/{id}',              [SupplierController::class, 'destroy'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::put('/{id}/rating',          [SupplierController::class, 'updateRating'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::post('/{id}/contacts',       [SupplierController::class, 'addContact'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::post('/{id}/documents',      [SupplierController::class, 'uploadDocument'])
                        ->middleware('permission:procurement.create,sanctum');
                });

                Route::prefix('purchase-orders')->group(function () {
                    Route::get('/',              [PurchaseOrderController::class, 'index']);
                    Route::get('/statistics',    [PurchaseOrderController::class, 'statistics']);
                    Route::get('/{id}',          [PurchaseOrderController::class, 'show']);
                    Route::get('/{id}/audit-log',[PurchaseOrderController::class, 'auditLog']);
                    Route::post('/',             [PurchaseOrderController::class, 'store'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::put('/{id}',          [PurchaseOrderController::class, 'update'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::delete('/{id}',       [PurchaseOrderController::class, 'destroy'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::post('/{id}/submit',  [PurchaseOrderController::class, 'submit'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::post('/{id}/approve', [PurchaseOrderController::class, 'approve'])
                        ->middleware('permission:procurement.approve,sanctum');
                    Route::post('/{id}/reject',  [PurchaseOrderController::class, 'reject'])
                        ->middleware('permission:procurement.approve,sanctum');
                    Route::post('/{id}/cancel',  [PurchaseOrderController::class, 'cancel'])
                        ->middleware('permission:procurement.create,sanctum');
                    Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive'])
                        ->middleware('permission:procurement.receive,sanctum');
                    Route::post('/{id}/return',  [PurchaseOrderController::class, 'return'])
                        ->middleware('permission:procurement.receive,sanctum');
                    Route::patch('/{id}/status', [PurchaseOrderController::class, 'updateStatus'])
                        ->middleware('permission:procurement.approve,sanctum');
                });

                Route::prefix('grn')->group(function () {
                    Route::get('/',              [PurchaseOrderController::class, 'grnIndex']);
                    Route::get('/{id}',          [PurchaseOrderController::class, 'grnShow']);
                    Route::get('/{id}/pdf',      [PurchaseOrderController::class, 'grnPDF']);
                    Route::get('/{id}/audit-log',[PurchaseOrderController::class, 'grnAuditLog']);
                    Route::post('/{id}/print',   [PurchaseOrderController::class, 'printGRN'])
                        ->middleware('permission:procurement.receive,sanctum');
                });

                Route::prefix('purchase-returns')->group(function () {
                    Route::get('/',               [PurchaseOrderController::class, 'purchaseReturns']);
                    Route::get('/{id}',           [PurchaseOrderController::class, 'purchaseReturnDetails']);
                    Route::get('/{id}/audit-log', [PurchaseOrderController::class, 'purchaseReturnAuditLog']);
                    Route::post('/{id}/approve',  [PurchaseOrderController::class, 'approvePurchaseReturn'])
                        ->middleware('permission:procurement.approve,sanctum');
                    Route::post('/{id}/reject',   [PurchaseOrderController::class, 'rejectPurchaseReturn'])
                        ->middleware('permission:procurement.approve,sanctum');
                    Route::post('/{id}/complete', [PurchaseOrderController::class, 'completePurchaseReturn'])
                        ->middleware('permission:procurement.receive,sanctum');
                });
            });

            // ── Production ────────────────────────────────────────────────────
            Route::middleware('permission:production.view,sanctum')->group(function () {
                Route::prefix('production-orders')->group(function () {
                    Route::get('/',              [ProductionController::class, 'index']);
                    Route::get('/{id}',          [ProductionController::class, 'show']);
                    Route::get('/{id}/timeline', [ProductionController::class, 'timeline']);
                    Route::get('/{id}/messages', [ProductionController::class, 'getMessages']);
                    Route::get('/{id}/assignees',[ProductionController::class, 'assignees']);
                    Route::get('/{id}/audit-log',[ProductionController::class, 'auditLog']);
                    Route::get('/{id}/approvals',[ProductionController::class, 'approvalHistory']);

                    Route::post('/',                      [ProductionController::class, 'store'])
                        ->middleware('permission:production.raise_order,sanctum');
                    Route::put('/{id}',                   [ProductionController::class, 'update'])
                        ->middleware('permission:production.raise_order,sanctum');
                    Route::post('/{id}/confirm',          [ProductionController::class, 'confirm'])
                        ->middleware('permission:production.confirm_order,sanctum');
                    Route::post('/{id}/assign',           [ProductionController::class, 'assign'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::put('/{id}/stage',             [ProductionController::class, 'updateStage'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::post('/{id}/materials',        [ProductionController::class, 'issueMaterials'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::post('/{id}/qc',               [ProductionController::class, 'qualityCheck'])
                        ->middleware('permission:production.submit_qc,sanctum');
                    Route::post('/{id}/complete',         [ProductionController::class, 'complete'])
                        ->middleware('permission:production.approve_qc,sanctum');
                    Route::delete('/{id}',                [ProductionController::class, 'destroy'])
                        ->middleware('permission:production.confirm_order,sanctum');
                    Route::post('/{id}/cancel',           [ProductionController::class, 'destroy'])
                        ->middleware('permission:production.confirm_order,sanctum');
                    Route::post('/{id}/regenerate-tasks', [ProductionController::class, 'regenerateTasks'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::post('/{id}/messages',         [ProductionController::class, 'postMessage']);
                    Route::post('/{id}/assignees',        [ProductionController::class, 'addAssignee'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::delete('/{orderId}/assignees/{userId}', [ProductionController::class, 'removeAssignee'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::post('/{id}/approvals',        [ProductionController::class, 'recordApproval'])
                        ->middleware('permission:production.approve_qc,sanctum');
                });

                Route::prefix('production')->group(function () {
                    Route::get('/schedule',               [ProductionController::class, 'schedule']);
                    Route::get('/auto-assignees',         [ProductionController::class, 'autoAssignees']);
                    Route::post('/auto-assignees',        [ProductionController::class, 'createAutoAssignee'])
                        ->middleware('permission:production.configure_auto_assignees,sanctum');
                    Route::delete('/auto-assignees/{id}', [ProductionController::class, 'deleteAutoAssignee'])
                        ->middleware('permission:production.configure_auto_assignees,sanctum');
                });

                Route::prefix('production-tasks')->group(function () {
                    Route::get('/',              [ProductionController::class, 'allTasks']);
                    Route::post('/',             [ProductionController::class, 'createTask'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::put('/{id}',          [ProductionController::class, 'updateTaskDetails'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::delete('/{id}',       [ProductionController::class, 'deleteTask'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::put('/{id}/reassign', [ProductionController::class, 'reassignTask'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                });

                Route::prefix('material-allocations')->group(function () {
                    Route::get('/',                                  [ProductionController::class, 'allocations']);
                    Route::post('/production-orders/{id}/allocate', [ProductionController::class, 'allocateMaterials'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::put('/{id}',                             [ProductionController::class, 'updateAllocation'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                    Route::delete('/{id}',                          [ProductionController::class, 'deleteAllocation'])
                        ->middleware('permission:production.manage_assignees,sanctum');
                });

                // reorder must be declared before apiResource so Laravel does not
                // match the literal string "reorder" as the {product_stage} parameter.
                Route::put('/product-stages/reorder', [ProductStageController::class, 'reorder'])
                    ->middleware('permission:production.configure_auto_assignees,sanctum');
                // Was gated by nothing beyond the outer production.view - meaning
                // any role with mere read access to production orders (e.g.
                // tailor, whose intended scope is "production worker workspace
                // only") could create/edit/delete production stages, which are
                // pipeline-wide configuration shared by every production order
                // company-wide, not a per-order action. Reusing
                // production.configure_auto_assignees since it already gates
                // the other pipeline-configuration actions (auto-assignees,
                // stage reorder) right next to this.
                Route::apiResource('product-stages', ProductStageController::class)
                    ->except(['index', 'show'])
                    ->middleware('permission:production.configure_auto_assignees,sanctum');
                Route::get('/product-stages',         [ProductStageController::class, 'index']);
                Route::get('/product-stages/{id}',    [ProductStageController::class, 'show']);
            });

            // ── Payments ─────────────────────────────────────────────────────
            Route::middleware('permission:payments.view,sanctum')->group(function () {
                Route::prefix('payment-transactions')->group(function () {
                    Route::get('/',             [PaymentController::class, 'allTransactions']);
                    Route::get('/analytics',    [PaymentController::class, 'transactionAnalytics']);
                    Route::get('/export',       [PaymentController::class, 'exportTransactions']);
                    Route::get('/{id}',         [PaymentController::class, 'transactionDetails']);
                    Route::post('/{id}/refund',    [PaymentController::class, 'refundTransaction'])
                        ->middleware('permission:orders.refund,sanctum');
                    Route::post('/{id}/void',      [PaymentController::class, 'voidPayment'])
                        ->middleware('permission:payments.void,sanctum');
                    Route::post('/{id}/reassign',  [PaymentController::class, 'reassignPayment'])
                        ->middleware('permission:payments.reassign,sanctum');
                });

                Route::prefix('payments')->group(function () {
                    Route::get('/pending-approval',          [PaymentApprovalController::class, 'pendingApprovals']);
                    Route::get('/cash-report',               [PaymentApprovalController::class, 'cashReport']);
                    Route::get('/{id}/proof',                [PaymentApprovalController::class, 'serveProof']);
                    Route::post('/{id}/upload-proof',        [PaymentApprovalController::class, 'uploadProof'])
                        ->middleware('permission:payments.upload_proof,sanctum');
                    Route::post('/{id}/approve',             [PaymentApprovalController::class, 'approve'])
                        ->middleware('permission:payments.approve_international,sanctum');
                    Route::post('/{id}/reject',              [PaymentApprovalController::class, 'reject'])
                        ->middleware('permission:payments.approve_international,sanctum');
                    Route::post('/{paymentId}/verify-mpesa',    [PaymentController::class, 'verifyMpesa'])
                        ->middleware('permission:payments.approve_international,sanctum');
                    Route::post('/{paymentId}/verify-paystack', [PaymentController::class, 'verifyPaystack'])
                        ->middleware('permission:payments.record,sanctum');
                });
            });

            // ── Tax rates ─────────────────────────────────────────────────────
            Route::middleware('permission:settings.view,sanctum')->prefix('tax-rates')->group(function () {
                Route::get('/',            [TaxRateController::class, 'index']);
                Route::get('/{id}',        [TaxRateController::class, 'show']);
                Route::post('/',           [TaxRateController::class, 'store'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::put('/{id}',        [TaxRateController::class, 'update'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::delete('/{id}',     [TaxRateController::class, 'destroy'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::put('/{id}/toggle', [TaxRateController::class, 'toggleStatus'])
                    ->middleware('permission:settings.edit,sanctum');
            });

            // ── Shipping ──────────────────────────────────────────────────────
            Route::middleware('permission:settings.view,sanctum')->prefix('shipping')->group(function () {
                Route::get('/zones',                          [ShippingController::class, 'adminZones']);
                Route::get('/zones/{id}',                     [ShippingController::class, 'showZone']);
                Route::get('/zones/{id}/countries',           [ShippingController::class, 'zoneCountries']);
                Route::get('/methods',                        [ShippingController::class, 'adminMethods']);
                Route::post('/zones',                         [ShippingController::class, 'createZone'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::put('/zones/{id}',                     [ShippingController::class, 'updateZone'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::delete('/zones/{id}',                  [ShippingController::class, 'deleteZone'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::post('/zones/{id}/countries',          [ShippingController::class, 'addCountries'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::delete('/zones/{id}/countries/{code}', [ShippingController::class, 'removeCountry'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::post('/methods',                       [ShippingController::class, 'createMethod'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::put('/methods/{id}',                   [ShippingController::class, 'updateMethod'])
                    ->middleware('permission:settings.edit,sanctum');
                Route::delete('/methods/{id}',                [ShippingController::class, 'deleteMethod'])
                    ->middleware('permission:settings.edit,sanctum');
            });

            // ── Content pages ─────────────────────────────────────────────────
            Route::middleware('permission:settings.edit,sanctum')->prefix('pages')->group(function () {
                Route::post('/',               [ContentPageController::class, 'store']);
                Route::put('/{id}',            [ContentPageController::class, 'update']);
                Route::delete('/{id}',         [ContentPageController::class, 'destroy']);
                Route::post('/{id}/publish',   [ContentPageController::class, 'publish']);
                Route::post('/{id}/unpublish', [ContentPageController::class, 'unpublish']);
                Route::post('/{id}/duplicate', [ContentPageController::class, 'duplicate']);
            });

            // ── Expenses ─────────────────────────────────────────────────────
            Route::middleware('permission:expenses.view,sanctum')->prefix('expenses')->group(function () {
                Route::get('/categories',      [ExpenseController::class, 'categories']);
                Route::get('/budgets',         [ExpenseController::class, 'budgets']);
                Route::get('/summary',         [ExpenseController::class, 'summary']);
                Route::get('/',                [ExpenseController::class, 'index']);
                Route::get('/{id}',            [ExpenseController::class, 'show']);
                Route::get('/{id}/receipt',    [ExpenseController::class, 'downloadReceipt']);

                Route::post('/categories',     [ExpenseController::class, 'storeCategory'])
                    ->middleware('permission:expenses.budgets,sanctum');
                Route::put('/categories/{id}', [ExpenseController::class, 'updateCategory'])
                    ->middleware('permission:expenses.budgets,sanctum');
                Route::post('/budgets',        [ExpenseController::class, 'storeBudget'])
                    ->middleware('permission:expenses.budgets,sanctum');
                Route::put('/budgets/{id}',    [ExpenseController::class, 'updateBudget'])
                    ->middleware('permission:expenses.budgets,sanctum');
                Route::post('/',               [ExpenseController::class, 'store'])
                    ->middleware('permission:expenses.create,sanctum');
                Route::put('/{id}',            [ExpenseController::class, 'update'])
                    ->middleware('permission:expenses.edit,sanctum');
                Route::delete('/{id}',         [ExpenseController::class, 'destroy'])
                    ->middleware('permission:expenses.delete,sanctum');
                Route::post('/{id}/submit',    [ExpenseController::class, 'submit'])
                    ->middleware('permission:expenses.create,sanctum');
                Route::post('/{id}/cancel',    [ExpenseController::class, 'cancel'])
                    ->middleware('permission:expenses.edit,sanctum');
                Route::post('/{id}/approve',   [ExpenseController::class, 'approve'])
                    ->middleware('permission:expenses.approve,sanctum');
                Route::post('/{id}/reject',    [ExpenseController::class, 'reject'])
                    ->middleware('permission:expenses.approve,sanctum');
                Route::post('/{id}/mark-paid', [ExpenseController::class, 'markPaid'])
                    ->middleware('permission:expenses.approve,sanctum');
                Route::post('/{id}/receipt',   [ExpenseController::class, 'uploadReceipt'])
                    ->middleware('permission:expenses.create,sanctum');
            });

            // ── Reports ───────────────────────────────────────────────────────
            Route::middleware('permission:reports.view,sanctum')->prefix('reports')->group(function () {
                Route::get('/dashboard/kpis',  [ReportController::class, 'dashboardKPIs']);
                Route::get('/purchase-orders', [ReportController::class, 'purchaseOrderReport']);
                Route::get('/schedules',       [ReportController::class, 'listSchedules']);
                Route::post('/export/pdf',     [ReportController::class, 'exportPDF'])
                    ->middleware('permission:reports.export,sanctum');
                Route::post('/export/excel',   [ReportController::class, 'exportExcel'])
                    ->middleware('permission:reports.export,sanctum');
                Route::post('/schedules',         [ReportController::class, 'saveSchedule'])
                    ->middleware('permission:reports.export,sanctum');
                Route::delete('/schedules/{id}',  [ReportController::class, 'deleteSchedule'])
                    ->middleware('permission:reports.export,sanctum');
                Route::prefix('sales')->group(function () {
                    Route::get('/summary',           [ReportController::class, 'salesSummary']);
                    Route::get('/by-product',        [ReportController::class, 'salesByProduct']);
                    Route::get('/by-category',       [ReportController::class, 'salesByCategory']);
                    Route::get('/by-customer',       [ReportController::class, 'salesByCustomer']);
                    Route::get('/by-outlet',         [ReportController::class, 'salesByOutlet']);
                    Route::get('/by-payment-method', [ReportController::class, 'salesByPaymentMethod']);
                    Route::get('/returns',           [ReportController::class, 'salesReturns']);
                });
                Route::prefix('customers')->group(function () {
                    Route::get('/analytics',      [ReportController::class, 'customerAnalytics']);
                    Route::get('/summary',        [ReportController::class, 'customerSummary']);
                    Route::get('/aging',          [ReportController::class, 'customerAging']);
                    Route::get('/lifetime-value', [ReportController::class, 'customerLifetimeValue']);
                    Route::get('/retention',      [ReportController::class, 'customerRetention']);
                });
                Route::prefix('inventory')->group(function () {
                    Route::get('/stock-on-hand', [ReportController::class, 'stockOnHand']);
                    Route::get('/low-stock',     [ReportController::class, 'lowStockReport']);
                    Route::get('/valuation',     [EnhancedReportController::class, 'inventoryValuation']);
                    Route::get('/aging',         [ReportController::class, 'inventoryAging']);
                    Route::get('/movement',      [ReportController::class, 'inventoryMovement']);
                });
                // Financial reports (P&L, revenue, tax, cash-flow) require
                // reports.financial specifically, not just reports.view.
                // SyncPermissions deliberately withholds reports.financial
                // from outlet_manager and procurement_officer/manager - its
                // own comments say those roles get "spend reports", not
                // full financial statements - while finance_manager gets it
                // explicitly ("all reports including financial"). This
                // group previously only checked reports.view, so every role
                // with general report access could see full P&L/revenue/
                // tax/cash-flow regardless of that distinction.
                Route::middleware('permission:reports.financial,sanctum')->prefix('financial')->group(function () {
                    Route::get('/tax',         [EnhancedReportController::class, 'taxReport']);
                    Route::get('/cash-flow',  [EnhancedReportController::class, 'cashFlow']);
                    Route::get('/profit-loss', [ReportController::class, 'profitLoss']);
                    Route::get('/revenue',     [ReportController::class, 'revenue']);
                    Route::get('/expenses',    [ReportController::class, 'expenses']);
                });
                Route::prefix('production')->group(function () {
                    Route::get('/summary',             [ReportController::class, 'productionSummary']);
                    Route::get('/efficiency',          [ReportController::class, 'productionEfficiency']);
                    Route::get('/tailor-productivity', [ReportController::class, 'tailorProductivity']);
                    Route::get('/costing-summary',     [ReportController::class, 'productionCostingSummary']);
                    Route::get('/costing/{id}',        [ReportController::class, 'productCostingReport']);
                });
            });

            // ── Settings ────────────────────────────────────────────────────
            // Was hardcoded to role:super_admin|admin, which meant granting
            // settings.view / settings.edit to any other role (e.g. via a
            // custom role built in the Roles UI) silently did nothing here -
            // the nav item would show (Sidebar gates on settings.view) but
            // every call would 403. Now genuinely permission-gated, matching
            // every other module and matching what settings.edit already
            // implies (settings.view) via PermissionDependencyService.
            Route::middleware('permission:settings.view,sanctum')->prefix('settings')->group(function () {
                Route::get('/',                    [SettingController::class, 'index']);
                Route::get('/payment-providers',   [SettingController::class, 'paymentProviders']);
                Route::get('/email',               [SettingController::class, 'emailSettings']);
                Route::get('/tax',                 [SettingController::class, 'taxSettings']);
                Route::get('/maintenance',         [SettingController::class, 'maintenanceMode']);

                Route::middleware('permission:settings.edit,sanctum')->group(function () {
                    Route::put('/',                    [SettingController::class, 'update']);
                    Route::post('/logo',               [SettingController::class, 'uploadLogo']);
                    Route::put('/payment-providers',   [SettingController::class, 'updatePaymentProviders']);
                    Route::put('/email',               [SettingController::class, 'updateEmailSettings']);
                    Route::post('/test-email',         [SettingController::class, 'testEmail']);
                    Route::put('/languages',           [SettingController::class, 'updateLanguages']);
                    Route::put('/currencies',          [SettingController::class, 'updateCurrencies']);
                    Route::post('/cache/clear',        [SettingController::class, 'clearCache']);
                    Route::post('/maintenance/toggle', [SettingController::class, 'toggleMaintenanceMode']);
                });
            });

            // ── PDF Document Generation ───────────────────────────────────────
            // GET /api/v1/admin/pdf/{type}/{id}  → streams application/pdf
            Route::prefix('pdf')->name('pdf.')->group(function () {

                // Procurement
                Route::get('/purchase-orders/{id}',  [DocumentPdfController::class, 'purchaseOrder'])
                    ->middleware('permission:procurement.view,sanctum')
                    ->name('purchase-order');
                Route::get('/grn/{id}',              [DocumentPdfController::class, 'grn'])
                    ->middleware('permission:procurement.view,sanctum')
                    ->name('grn');
                Route::get('/purchase-returns/{id}', [DocumentPdfController::class, 'purchaseReturn'])
                    ->middleware('permission:procurement.view,sanctum')
                    ->name('purchase-return');

                // Orders & Sales
                Route::get('/orders/{id}',           [DocumentPdfController::class, 'order'])
                    ->middleware('permission:orders.view,sanctum')
                    ->name('order');
                Route::get('/orders/{id}/invoice',   [DocumentPdfController::class, 'invoice'])
                    ->middleware('permission:orders.view,sanctum')
                    ->name('invoice');
                Route::get('/returns/{id}',          [DocumentPdfController::class, 'orderReturn'])
                    ->middleware('permission:orders.manage_returns,sanctum')
                    ->name('order-return');

                // Logistics
                Route::get('/shipments/{id}',        [DocumentPdfController::class, 'shipment'])
                    ->middleware('permission:shipment.view,sanctum')
                    ->name('shipment');

                // Production
                Route::get('/production-orders/{id}',[DocumentPdfController::class, 'productionOrder'])
                    ->middleware('permission:production.view,sanctum')
                    ->name('production-order');

                // Inventory
                Route::get('/stock-transfers/{id}',  [DocumentPdfController::class, 'stockTransfer'])
                    ->middleware('permission:inventory.view,sanctum')
                    ->name('stock-transfer');
                Route::get('/stock-adjustments/{id}',[DocumentPdfController::class, 'stockAdjustment'])
                    ->middleware('permission:inventory.view,sanctum')
                    ->name('stock-adjustment');

                // Expenses
                Route::get('/expenses/{id}',         [DocumentPdfController::class, 'expense'])
                    ->middleware('permission:expenses.view,sanctum')
                    ->name('expense');
            });

            // ── Report PDFs ───────────────────────────────────────────────────
            // GET /api/v1/admin/reports/pdf/{type}?start_date=&end_date=
            // Runs queries server-side and streams a formatted PDF binary.
            Route::middleware('permission:reports.view,sanctum')
                ->prefix('reports/pdf')
                ->name('reports.pdf.')
                ->group(function () {
                    Route::get('/sales',        [ReportPdfController::class, 'sales'])       ->name('sales');
                    Route::get('/financial',    [ReportPdfController::class, 'financial'])
                        ->middleware('permission:reports.financial,sanctum')
                        ->name('financial');
                    Route::get('/inventory',    [ReportPdfController::class, 'inventory'])   ->name('inventory');
                    Route::get('/procurement',  [ReportPdfController::class, 'procurement']) ->name('procurement');
                    Route::get('/production',   [ReportPdfController::class, 'production'])  ->name('production');
                    Route::get('/customers',    [ReportPdfController::class, 'customers'])   ->name('customers');
                });

        });

        // ═══ ROLES, PERMISSIONS & LOCALISATION SETUP ════════════════════════
        // Previously this entire block was hardcoded to role:super_admin,
        // which meant the roles.view / roles.edit / settings.* permissions
        // defined in SyncPermissions were dead weight for every role except
        // literal super_admin - including 'admin', which is explicitly
        // granted 'roles.*' and 'settings.*' via wildcard in ROLE_PERMISSIONS
        // but could never actually reach these endpoints. Now genuinely
        // permission-gated so any role (built-in or custom, created via the
        // Roles UI) that holds the matching permission can use it, the same
        // as every other module in the app. Database management stays its
        // own gate on settings.manage_database, which SyncPermissions has
        // always deliberately kept out of wildcard expansion - it must be
        // assigned to a role explicitly, which is exactly what this
        // permission check now enforces (rather than a second, redundant
        // role:super_admin check that made the permission unassignable to
        // anyone else even on purpose).
        Route::middleware(['throttle:admin-api'])->prefix('admin')->group(function () {

            Route::middleware('permission:roles.view,sanctum')->prefix('roles')->group(function () {
                Route::get('/',                  [RoleController::class, 'index']);
                Route::get('/{id}',              [RoleController::class, 'show']);
                Route::get('/{id}/permissions',  [RoleController::class, 'permissions']);

                Route::middleware('permission:roles.edit,sanctum')->group(function () {
                    Route::post('/',                 [RoleController::class, 'store']);
                    Route::put('/{id}',              [RoleController::class, 'update']);
                    Route::delete('/{id}',           [RoleController::class, 'destroy']);
                    Route::post('/{id}/permissions', [RoleController::class, 'syncPermissions']);
                    Route::post('/{id}/duplicate',   [RoleController::class, 'duplicate']);
                });
            });

            Route::middleware('permission:roles.view,sanctum')->prefix('permissions')->group(function () {
                Route::get('/',        [PermissionController::class, 'index']);

                Route::middleware('permission:roles.edit,sanctum')->group(function () {
                    Route::post('/',       [PermissionController::class, 'store']);
                    Route::put('/{id}',    [PermissionController::class, 'update']);
                    Route::delete('/{id}', [PermissionController::class, 'destroy']);
                    Route::post('/sync',   [PermissionController::class, 'syncAll']);
                });
            });

            Route::middleware('permission:settings.view,sanctum')->prefix('languages')->group(function () {
                Route::get('/',                 [LanguageController::class, 'index']);
                Route::get('/{id}',             [LanguageController::class, 'show']);

                Route::middleware('permission:settings.edit,sanctum')->group(function () {
                    Route::post('/',                [LanguageController::class, 'store']);
                    Route::put('/{id}',             [LanguageController::class, 'update']);
                    Route::delete('/{id}',          [LanguageController::class, 'destroy']);
                    Route::put('/{id}/toggle',      [LanguageController::class, 'toggleStatus']);
                    Route::put('/{id}/set-default', [LanguageController::class, 'setDefault']);
                });
            });

            Route::middleware('permission:settings.view,sanctum')->prefix('currencies-management')->group(function () {
                Route::get('/',                 [CurrencyController::class, 'index']);
                Route::get('/{id}',             [CurrencyController::class, 'show']);

                Route::middleware('permission:settings.edit,sanctum')->group(function () {
                    Route::post('/',                [CurrencyController::class, 'store']);
                    Route::put('/{id}',             [CurrencyController::class, 'update']);
                    Route::delete('/{id}',          [CurrencyController::class, 'destroy']);
                    Route::put('/{id}/toggle',      [CurrencyController::class, 'toggleStatus']);
                    Route::put('/{id}/set-default', [CurrencyController::class, 'setDefault']);
                    Route::put('/{id}/rates',       [CurrencyController::class, 'updateRates']);
                    Route::post('/sync-rates',      [CurrencyController::class, 'syncRates']);
                });
            });

            Route::middleware('permission:settings.view,sanctum')->prefix('countries')->group(function () {
                Route::get('/',                         [CountryController::class, 'adminIndex']);
                Route::get('/regions',                  [CountryController::class, 'regions']);

                Route::middleware('permission:settings.edit,sanctum')->group(function () {
                    Route::post('/',                        [CountryController::class, 'store']);
                    Route::put('/{code}',                   [CountryController::class, 'update']);
                    Route::put('/{code}/shipping-settings', [CountryController::class, 'updateShippingSettings']);
                    Route::put('/{code}/toggle',            [CountryController::class, 'toggleStatus']);
                });
            });

            Route::middleware('permission:settings.view,sanctum')->prefix('payment-methods-management')->group(function () {
                Route::get('/',            [PaymentMethodController::class, 'index']);
                Route::get('/{id}',        [PaymentMethodController::class, 'show']);

                Route::middleware('permission:settings.edit,sanctum')->group(function () {
                    Route::post('/',           [PaymentMethodController::class, 'store']);
                    Route::put('/{id}',        [PaymentMethodController::class, 'update']);
                    Route::delete('/{id}',     [PaymentMethodController::class, 'destroy']);
                    Route::put('/{id}/toggle', [PaymentMethodController::class, 'toggleStatus']);
                    Route::put('/{id}/config', [PaymentMethodController::class, 'updateConfig']);
                });
            });

            // ── Database Management (backups, restore, transaction cleanup, full wipe) ──
            // Gated on BOTH role:super_admin and settings.manage_database.
            // DatabaseManagementController's own docblocks assert this
            // module is "super_admin only" and call wipe() "the most
            // dangerous endpoint in the system" - a documented invariant,
            // not just an implementation detail. A permission-only gate
            // would let settings.manage_database (which SyncPermissions
            // deliberately allows assigning to a non-super-admin role) grant
            // full backup/restore/wipe access on its own; backupsRestore()
            // in particular has no password re-check the way wipe() does,
            // so it would rely entirely on this route gate. Keeping both
            // checks: settings.manage_database still has to be explicitly
            // granted (matching SyncPermissions' intent), but only ever
            // takes effect for an account that is also super_admin.
            Route::middleware(['role:super_admin', 'permission:settings.manage_database,sanctum'])->prefix('database')->group(function () {
                Route::get('/stats',              [DatabaseManagementController::class, 'stats']);

                // Transaction clear-by-date
                Route::get('/clearable-tables',   [DatabaseManagementController::class, 'clearableTables']);
                Route::post('/clear-preview',     [DatabaseManagementController::class, 'clearPreview']);
                Route::post('/clear',             [DatabaseManagementController::class, 'clear']);

                // Backups
                Route::get('/backups',                  [DatabaseManagementController::class, 'backupsIndex']);
                Route::post('/backups',                 [DatabaseManagementController::class, 'backupsStore']);
                Route::get('/backups/{id}/download',    [DatabaseManagementController::class, 'backupsDownload']);
                Route::post('/backups/{id}/restore',    [DatabaseManagementController::class, 'backupsRestore']);
                Route::delete('/backups/{id}',          [DatabaseManagementController::class, 'backupsDestroy']);

                // Scheduled backup config
                Route::get('/schedule',           [DatabaseManagementController::class, 'scheduleShow']);
                Route::put('/schedule',           [DatabaseManagementController::class, 'scheduleUpdate']);

                // Backup storage destination config (local / S3-compatible)
                Route::get('/storage-settings',         [DatabaseManagementController::class, 'storageSettingsShow']);
                Route::put('/storage-settings',         [DatabaseManagementController::class, 'storageSettingsUpdate']);
                Route::post('/storage-settings/test',   [DatabaseManagementController::class, 'storageSettingsTest']);

                // Full factory-reset wipe — extremely destructive, guarded in the
                // controller by confirm phrase + password re-auth + mandatory pre-wipe backup.
                Route::post('/wipe',              [DatabaseManagementController::class, 'wipe']);
            });
        });

        // ═══ POS ROUTES (separate from admin prefix - PWA/mobile terminal) ══
        // Previously only role-gated (role:pos_clerk|outlet_manager|admin|
        // super_admin), with none of the granular pos.* permission checks
        // that the equivalent /admin/pos routes above enforce. That meant a
        // pos_clerk - whose default permission set deliberately excludes
        // pos.open_register, pos.close_register, pos.void and
        // pos.cash_management - could still hit every one of those actions
        // through this alternate route set, since only the role name was
        // ever checked. Mirrored to the same per-action gates as /admin/pos.
        Route::middleware(['role:pos_clerk|outlet_manager|admin|super_admin', 'permission:pos.access,sanctum'])->prefix('pos')->group(function () {
            Route::get('/products',                 [PosController::class, 'products']);
            Route::get('/products/search',          [PosController::class, 'searchProducts']);
            Route::post('/sales',                   [PosController::class, 'createSale']);
            Route::get('/sales/today',              [PosController::class, 'todaySales']);
            Route::post('/sales/{id}/receipt',      [PosController::class, 'printReceipt']);
            Route::post('/sales/{id}/return',       [PosController::class, 'processReturn'])
                ->middleware('permission:pos.returns,sanctum');
            Route::post('/cash-register/open',          [PosController::class, 'openRegister'])
                ->middleware('permission:pos.open_register,sanctum');
            Route::post('/cash-register/close',         [PosController::class, 'closeRegister'])
                ->middleware('permission:pos.close_register,sanctum');
            Route::get('/cash-register/status',         [PosController::class, 'registerStatus']);
            Route::get('/cash-register/transactions',   [PosController::class, 'cashTransactions']);
            Route::get('/cash-register/summary',        [PosController::class, 'cashSummary']);
            Route::post('/cash-register/deposit',       [PosController::class, 'cashDeposit'])
                ->middleware('permission:pos.cash_management,sanctum');
            Route::post('/cash-register/withdrawal',    [PosController::class, 'cashWithdrawal'])
                ->middleware('permission:pos.cash_management,sanctum');
            Route::post('/cash-register/adjustment',    [PosController::class, 'cashAdjustment'])
                ->middleware('permission:pos.cash_management,sanctum');
            Route::get('/cash-register/reconciliation', [PosController::class, 'reconciliation']);
        });

        // ═══ TAILOR ROUTES ═══════════════════════════════════════════════════

        Route::middleware(['role:tailor|admin|super_admin'])->prefix('tailor')->group(function () {
            Route::get('/tasks',                  [ProductionController::class, 'myTasks']);
            Route::get('/tasks/{id}',             [ProductionController::class, 'taskDetails']);
            Route::get('/tasks/{id}/history',     [ProductionController::class, 'taskHistory']);
            Route::put('/tasks/{id}/status',      [ProductionController::class, 'updateTaskStatus']);
            Route::post('/tasks/{id}/note',       [ProductionController::class, 'addTaskNote']);
        });
    });
});

// ═══ PUBLIC ORDER TRACKING (no auth) ════════════════════════════════════════

Route::get('v1/track/{token}', [\App\Http\Controllers\Api\ShipmentController::class, 'publicTrack'])
    ->middleware('throttle:60,1')
    ->name('order.track');

Route::post('v1/track/{token}/query', [\App\Http\Controllers\Api\ShipmentController::class, 'submitQuery'])
    ->middleware('throttle:10,1');

// Serves a single attachment for the public tracking page (image thumbnails,
// lightbox preview, and the "Download" button). Strictly gated to
// is_public=true inside the controller method - see
// ShipmentController::publicServeAttachment(). Sits under the same
// throttle as the other public tracking routes above, since it's reachable
// by anyone with the tracking link, same as they are.
Route::get('v1/track/{token}/attachments/{attachmentId}', [\App\Http\Controllers\Api\ShipmentController::class, 'publicServeAttachment'])
    ->middleware('throttle:60,1');

Route::get('/track/{token}', function () {
    return response()->file(public_path('index.html'));
})->where('token', '[a-zA-Z0-9\-]+');

// ═══ SPA FALLBACK - serves index.html for /pay/* so React Router handles token
Route::get('/pay/{token}', function () {
    return response()->file(public_path('index.html'));
})->where('token', '[a-zA-Z0-9]+')->middleware('throttle:60,1');

// ═══ PAYMENT WEBHOOKS (no auth) ══════════════════════════════════════════════

Route::prefix('webhooks')->middleware('throttle:webhooks')->group(function () {
    Route::post('/mpesa/callback',      [PaymentController::class, 'mpesaCallback']);
    Route::post('/mpesa/validation',    [PaymentController::class, 'mpesaValidation']);
    Route::post('/paystack/webhook',    [PaymentController::class, 'paystackWebhook']);
    Route::post('/flutterwave/webhook', [PaymentController::class, 'flutterwaveWebhook']);
});