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
    EnhancedReportController
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
                Route::get('/attachments/serve',                   [ChannelController::class, 'serveAttachment']);
                Route::post('/attachments',                        [ChannelController::class, 'uploadAttachment']);
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
            });
        });

        // ═══ ADMIN PANEL (super_admin | admin) ═══════════════════════════════

        Route::middleware(['role:super_admin|admin', 'throttle:admin-api'])->prefix('admin')->group(function () {

            Route::get('/dashboard',       [DashboardController::class, 'index']);

            // ── Profile (self-service for any authenticated admin) ───────────
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

            // ── Activity logs ───────────────────────────────────────────────
            Route::prefix('activity-logs')->group(function () {
                Route::get('/',        [AuditLogController::class, 'index']);
                Route::get('/export',  [AuditLogController::class, 'export']);
                Route::post('/clear',  [AuditLogController::class, 'clear']);
                Route::get('/{id}',    [AuditLogController::class, 'show']);
            });

            // ── Products ────────────────────────────────────────────────────
            Route::prefix('products')->group(function () {
                Route::get('/',                               [ProductController::class, 'adminIndex']);
                Route::post('/',                              [ProductController::class, 'store']);
                Route::get('/export-template',                [ProductController::class, 'exportTemplate']);
                Route::post('/bulk-import',                   [ProductController::class, 'bulkImport']);
                Route::get('/{id}',                           [ProductController::class, 'adminShow']);
                Route::put('/{id}',                           [ProductController::class, 'update']);
                Route::delete('/{id}',                        [ProductController::class, 'destroy']);
                Route::put('/{id}/publish',                   [ProductController::class, 'publish']);
                Route::put('/{id}/archive',                   [ProductController::class, 'archive']);
                Route::post('/{id}/images',                   [ProductController::class, 'uploadImages']);
                Route::put('/{id}/images/reorder',            [ProductController::class, 'reorderImages']);
                Route::put('/{id}/images/{imageId}/primary',  [ProductController::class, 'setPrimaryImage']);
                Route::delete('/{id}/images/{imageId}',       [ProductController::class, 'deleteImage']);
                Route::post('/{id}/variants',                 [ProductController::class, 'addVariant']);
                Route::put('/{productId}/variants/{variantId}',    [ProductController::class, 'updateVariant']);
                Route::delete('/{productId}/variants/{variantId}', [ProductController::class, 'deleteVariant']);

                // Phase 1 - per-product tax rate assignment
                Route::get('/{productId}/tax-rates',  [TaxRateController::class, 'productRates']);
                Route::post('/{productId}/tax-rates', [TaxRateController::class, 'syncProductRates']);

                // BOM routes - nested under products
                Route::prefix('/{productId}/bom')->group(function () {
                    Route::get('/',                  [BomController::class, 'index']);
                    Route::post('/',                 [BomController::class, 'store']);
                    Route::get('/{bomId}',           [BomController::class, 'show']);
                    Route::put('/{bomId}',           [BomController::class, 'update']);
                    Route::delete('/{bomId}',        [BomController::class, 'destroy']);
                    Route::put('/{bomId}/activate',  [BomController::class, 'activate']);
                    Route::get('/{bomId}/feasibility', [BomController::class, 'feasibility']);
                });
            });

            // ── Categories ──────────────────────────────────────────────────
            Route::prefix('categories')->group(function () {
                Route::get('/',                    [CategoryController::class, 'adminIndex']);
                Route::post('/',                   [CategoryController::class, 'store']);
                Route::put('/reorder',             [CategoryController::class, 'reorder']);
                Route::get('/{id}',                [CategoryController::class, 'adminShow']);
                Route::put('/{id}',                [CategoryController::class, 'update']);
                Route::delete('/{id}',             [CategoryController::class, 'destroy']);
                Route::put('/{id}/toggle',         [CategoryController::class, 'toggleStatus']);
                Route::post('/{id}/image',         [CategoryController::class, 'uploadImage']);
                Route::delete('/{id}/image',       [CategoryController::class, 'deleteImage']);
            });

            // ── Reviews ─────────────────────────────────────────────────────
            Route::prefix('reviews')->group(function () {
                Route::get('/',              [ProductReviewController::class, 'adminIndex']);
                Route::put('/{id}/approve',  [ProductReviewController::class, 'approve']);
                Route::put('/{id}/reject',   [ProductReviewController::class, 'reject']);
                Route::delete('/{id}/force', [ProductReviewController::class, 'forceDelete']);
            });

            // ── Stock Levels ─────────────────────────────────────────────────
            Route::prefix('inventory/stock-levels')->group(function () {
                Route::get('/',                       [StockLevelsController::class, 'index']);
                Route::post('/opening',               [StockLevelsController::class, 'setOpeningStock']);
                Route::get('/by-product/{productId}', [StockLevelsController::class, 'byProduct']);
                Route::get('/{id}',                   [StockLevelsController::class, 'show']);
                Route::put('/{id}',                   [StockLevelsController::class, 'update']);
                Route::get('/{id}/history',           [StockLevelsController::class, 'history']);
            });

            // ── Orders ──────────────────────────────────────────────────────
            Route::prefix('orders')->group(function () {
                Route::get('/',                          [OrderController::class, 'index']);
                Route::get('/{id}',                      [OrderController::class, 'show']);
                Route::put('/{id}/status',               [OrderController::class, 'updateStatus']);
                Route::post('/{id}/notes',               [OrderController::class, 'addNote']);
                Route::post('/{id}/attach-customer',     [OrderController::class, 'attachCustomer']);
                Route::get('/{id}/audit-log',           [OrderController::class, 'auditLog']);
                Route::post('/{id}/void',               [OrderController::class, 'voidOrder']);
                Route::post('/{id}/refund',              [OrderController::class, 'refund']);
                Route::post('/{id}/payments',            [OrderController::class, 'addPayment']);
                Route::post('/{id}/resend-confirmation', [OrderController::class, 'resendConfirmation']);
                Route::get('/{id}/invoice',              [OrderController::class, 'generateInvoice']);
                Route::get('/{id}/activity-log',         [OrderController::class, 'activityLog']);
                // Phase 1 - payment link generation
                Route::get('/{id}/payment-link',         [OrderController::class, 'paymentLink']);
                // Phase 2 - shipping fee override, deposit terms, currency enforcement
                Route::patch('/{id}/shipping-fee',       [OrderController::class, 'setShippingFee']);
                Route::post('/{id}/set-deposit',         [OrderController::class, 'setDeposit']);
                Route::post('/{id}/update-currency',     [OrderController::class, 'updateCurrency']);
                // Phase 3 - create shipment for this order
                Route::post('/{id}/shipments',           [ShipmentController::class, 'create']);
                // Integrated payment initiation (M-Pesa STK Push, Paystack, Flutterwave)
                // Used by PaymentModal and OrderDetailPage for admin-initiated gateway payments
                Route::post('/{id}/payment',             [PaymentController::class, 'initiatePayment']);
            });

            // ── Shipments ───────────────────────────────────────────────────
            Route::prefix('shipments')->group(function () {
                Route::get('/',                      [ShipmentController::class, 'index']);
                Route::get('/{id}',                  [ShipmentController::class, 'show']);
                Route::put('/{id}',                  [ShipmentController::class, 'update']);
                Route::post('/{id}/tracking',        [ShipmentController::class, 'addTracking']);
                Route::get('/{id}/tracking',         [ShipmentController::class, 'getTracking']);
                Route::post('/{id}/mark-delivered',  [ShipmentController::class, 'markDelivered']);
                Route::post('/{id}/cancel',          [ShipmentController::class, 'cancel']);
                Route::get('/{id}/audit-log',        [ShipmentController::class, 'auditLog']);
                Route::post('/{id}/upload-attachment',               [ShipmentController::class, 'uploadShipmentAttachment']);
                Route::get('/{id}/attachment',                       [ShipmentController::class, 'serveShipmentAttachment']);
                Route::post('/{id}/tracking/{trackingId}/upload-attachment', [ShipmentController::class, 'uploadTrackingAttachment']);
                Route::get('/{id}/tracking/{trackingId}/attachment',         [ShipmentController::class, 'serveTrackingAttachment']);
            });

            // ── Returns ─────────────────────────────────────────────────────
            Route::prefix('returns')->group(function () {
                Route::get('/',                     [ReturnController::class, 'index']);
                Route::get('/{id}',                 [ReturnController::class, 'show']);
                Route::put('/{id}/status',          [ReturnController::class, 'updateStatus']);
                Route::post('/{id}/approve',        [ReturnController::class, 'approve']);
                Route::post('/{id}/reject',         [ReturnController::class, 'reject']);
                Route::post('/{id}/process-refund', [ReturnController::class, 'processRefund']);
            });

            // ── Customers ───────────────────────────────────────────────────
            Route::prefix('customers')->group(function () {
                Route::get('/',                       [CustomerController::class, 'index']);
                Route::post('/',                      [CustomerController::class, 'store']);
                Route::post('/quick-create',          [CustomerController::class, 'quickCreate']);
                Route::get('/{id}',                   [CustomerController::class, 'show']);
                Route::put('/{id}',                   [CustomerController::class, 'update']);
                Route::delete('/{id}',                [CustomerController::class, 'destroy']);
                Route::get('/{id}/orders',            [CustomerController::class, 'customerOrders']);
                Route::put('/{id}/status',            [CustomerController::class, 'updateStatus']);
                Route::get('/{id}/statistics',        [CustomerController::class, 'statistics']);
                Route::post('/{id}/invite-to-portal', [CustomerController::class, 'inviteToPortal']);
            });

            // ── Users ────────────────────────────────────────────────────────
            Route::prefix('users')->group(function () {
                Route::get('/',                     [UserController::class, 'index']);
                Route::post('/',                    [UserController::class, 'store']);
                Route::get('/export',               [UserController::class, 'export']);
                Route::get('/role/{role}',          [UserController::class, 'byRole']);
                Route::post('/bulk-update-status',  [UserController::class, 'bulkUpdateStatus']);
                Route::get('/{id}',                 [UserController::class, 'show']);
                Route::put('/{id}',                 [UserController::class, 'update']);
                Route::delete('/{id}',              [UserController::class, 'destroy']);
                Route::put('/{id}/role',            [UserController::class, 'updateRole']);
                Route::put('/{id}/status',          [UserController::class, 'updateStatus']);
                Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
                Route::get('/{id}/activity',        [AuditLogController::class, 'userActivity']);
                Route::get('/{id}/permissions',     [UserController::class, 'permissions']);
            });

            // ── POS ──────────────────────────────────────────────────────────
            Route::prefix('pos')->group(function () {
                Route::get('outlets',                   [PosController::class, 'outlets']);
                Route::get('register/status',           [PosController::class, 'registerStatus']);
                Route::post('register/open',            [PosController::class, 'openRegister']);
                Route::post('register/close',           [PosController::class, 'closeRegister']);
                Route::get('register/history',          [PosController::class, 'registerHistory']);
                Route::get('products',                  [PosController::class, 'products']);
                Route::get('products/search',           [PosController::class, 'searchProducts']);
                Route::post('sales',                    [PosController::class, 'createSale']);
                Route::get('sales',                     [PosController::class, 'sales']);
                // Two-step checkout: create order first, then record payment
                Route::post('pending-order',            [PosController::class, 'createPendingOrder']);
                Route::patch('pending-order/{id}',      [PosController::class, 'updatePendingOrder']);
                Route::post('pending-order/{id}/pay',   [PosController::class, 'recordPosPay']);
                Route::get('pending-order/open',        [PosController::class, 'getOpenPendingOrder']);
                Route::get('sales/{id}',                [PosController::class, 'saleDetail']);
                Route::post('sales/{id}/void',          [PosController::class, 'voidSale']);
                Route::post('sales/{id}/email-receipt', [PosController::class, 'emailReceipt']);
                Route::post('returns',                  [PosController::class, 'processReturn']);
                Route::get('returns',                   [PosController::class, 'returns']);
                Route::get('reports/daily',             [PosController::class, 'dailySummary']);
                Route::get('reports/end-of-day',        [PosController::class, 'endOfDay']);
                Route::get('customers/search',          [PosController::class, 'searchCustomers']);
            });

            // ── Inventory ────────────────────────────────────────────────────
            Route::prefix('inventory')->group(function () {
                Route::get('/',                      [InventoryController::class, 'index']);
                Route::get('/low-stock',             [InventoryController::class, 'lowStock']);
                Route::get('/valuation',             [InventoryController::class, 'valuation']);
                Route::post('/adjust',               [InventoryController::class, 'adjust']);
                Route::post('/transfer',             [InventoryController::class, 'transfer']);
                Route::put('/thresholds',            [InventoryController::class, 'setThreshold']);
                Route::put('/thresholds/bulk',       [InventoryController::class, 'bulkSetThreshold']);
                Route::get('/{productId}/movements', [InventoryController::class, 'movements']);

                Route::get('/transfers',              [StockTransfersController::class, 'index']);
                Route::post('/transfers',             [StockTransfersController::class, 'store']);
                Route::get('/transfers/stats',        [StockTransfersController::class, 'index']);
                Route::get('/transfers/{id}',         [StockTransfersController::class, 'show']);
                Route::put('/transfers/{id}/approve', [StockTransfersController::class, 'approve']);
                Route::put('/transfers/{id}/dispatch', [StockTransfersController::class, 'dispatch']);
                Route::put('/transfers/{id}/receive', [StockTransfersController::class, 'receive']);
                Route::put('/transfers/{id}/cancel',  [StockTransfersController::class, 'cancel']);
                Route::get('/transfers/{id}/audit-log',[StockTransfersController::class, 'auditLog']);

                Route::prefix('adjustments')->group(function () {
                    Route::get('/',              [StockAdjustmentsController::class, 'index']);
                    Route::post('/',             [StockAdjustmentsController::class, 'store']);
                    Route::get('/pending',       [StockAdjustmentsController::class, 'pending']);
                    Route::get('/reason-codes',  [StockAdjustmentsController::class, 'reasonCodes']);
                    Route::get('/stats',         [StockAdjustmentsController::class, 'index']);
                    Route::get('/{id}',          [StockAdjustmentsController::class, 'show']);
                    Route::put('/{id}/approve',  [StockAdjustmentsController::class, 'approve']);
                    Route::put('/{id}/reject',   [StockAdjustmentsController::class, 'reject']);
                    Route::put('/{id}/reverse',  [StockAdjustmentsController::class, 'reverse']);
                    Route::get('/{id}/audit-log',[StockAdjustmentsController::class, 'auditLog']);
                });

                Route::prefix('materials')->group(function () {
                    Route::get('/',                [\App\Http\Controllers\Api\RawMaterialsController::class, 'index']);
                    Route::post('/',               [\App\Http\Controllers\Api\RawMaterialsController::class, 'store']);
                    Route::get('/low-stock',       [\App\Http\Controllers\Api\RawMaterialsController::class, 'lowStock']);
                    Route::get('/{id}',            [\App\Http\Controllers\Api\RawMaterialsController::class, 'show']);
                    Route::put('/{id}',            [\App\Http\Controllers\Api\RawMaterialsController::class, 'update']);
                    Route::delete('/{id}',         [\App\Http\Controllers\Api\RawMaterialsController::class, 'destroy']);
                    Route::post('/{id}/receive',   [\App\Http\Controllers\Api\RawMaterialsController::class, 'receive']);
                    Route::post('/{id}/adjust',    [\App\Http\Controllers\Api\RawMaterialsController::class, 'adjust']);
                    Route::get('/{id}/transactions', [\App\Http\Controllers\Api\RawMaterialsController::class, 'transactions']);
                });

                Route::get('/low-stock-alerts',                [\App\Http\Controllers\Api\LowStockAlertsController::class, 'index']);
                Route::put('/low-stock-alerts/{id}/threshold', [\App\Http\Controllers\Api\LowStockAlertsController::class, 'updateThreshold']);
            });

            // ── Outlets ──────────────────────────────────────────────────────
            Route::prefix('outlets')->group(function () {
                Route::get('/',                [OutletController::class, 'index']);
                Route::get('/{id}',            [OutletController::class, 'show']);
                Route::post('/',               [OutletController::class, 'store']);
                Route::put('/{id}',            [OutletController::class, 'update']);
                Route::delete('/{id}',         [OutletController::class, 'destroy']);
                Route::get('/{id}/inventory',  [OutletController::class, 'inventory']);
                Route::get('/{id}/statistics', [OutletController::class, 'statistics']);
            });

            // ── Suppliers ────────────────────────────────────────────────────
            Route::prefix('suppliers')->group(function () {
                Route::get('/',                     [SupplierController::class, 'index']);
                Route::get('/export',               [SupplierController::class, 'export']);
                Route::post('/',                    [SupplierController::class, 'store']);
                Route::get('/{id}',                 [SupplierController::class, 'show']);
                Route::put('/{id}',                 [SupplierController::class, 'update']);
                Route::delete('/{id}',              [SupplierController::class, 'destroy']);
                Route::get('/{id}/purchase-orders', [SupplierController::class, 'purchaseOrders']);
                Route::get('/{id}/performance',     [SupplierController::class, 'performance']);
                Route::put('/{id}/rating',          [SupplierController::class, 'updateRating']);
                Route::get('/{id}/contacts',        [SupplierController::class, 'contactHistory']);
                Route::post('/{id}/contacts',       [SupplierController::class, 'addContact']);
                Route::get('/{id}/documents',       [SupplierController::class, 'documents']);
                Route::post('/{id}/documents',      [SupplierController::class, 'uploadDocument']);
            });

            // ── Purchase Orders ──────────────────────────────────────────────
            Route::prefix('purchase-orders')->group(function () {
                Route::get('/',              [PurchaseOrderController::class, 'index']);
                Route::get('/statistics',    [PurchaseOrderController::class, 'statistics']);
                Route::post('/',             [PurchaseOrderController::class, 'store']);
                Route::get('/{id}',          [PurchaseOrderController::class, 'show']);
                Route::put('/{id}',          [PurchaseOrderController::class, 'update']);
                Route::delete('/{id}',       [PurchaseOrderController::class, 'destroy']);
                Route::post('/{id}/submit',  [PurchaseOrderController::class, 'submit']);
                Route::post('/{id}/approve', [PurchaseOrderController::class, 'approve']);
                Route::post('/{id}/reject',  [PurchaseOrderController::class, 'reject']);
                Route::post('/{id}/cancel',  [PurchaseOrderController::class, 'cancel']);
                Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive']);
                Route::post('/{id}/return',  [PurchaseOrderController::class, 'return']);
                Route::patch('/{id}/status', [PurchaseOrderController::class, 'updateStatus']);
                Route::get('/{id}/audit-log',[PurchaseOrderController::class, 'auditLog']);
            });

            Route::prefix('grn')->group(function () {
                Route::get('/',              [PurchaseOrderController::class, 'grnIndex']);
                Route::get('/{id}',          [PurchaseOrderController::class, 'grnShow']);
                Route::post('/{id}/print',   [PurchaseOrderController::class, 'printGRN']);
                Route::get('/{id}/pdf',      [PurchaseOrderController::class, 'grnPDF']);
                Route::get('/{id}/audit-log',[PurchaseOrderController::class, 'grnAuditLog']);
            });

            Route::prefix('purchase-returns')->group(function () {
                Route::get('/',               [PurchaseOrderController::class, 'purchaseReturns']);
                Route::get('/{id}',           [PurchaseOrderController::class, 'purchaseReturnDetails']);
                Route::post('/{id}/approve',  [PurchaseOrderController::class, 'approvePurchaseReturn']);
                Route::post('/{id}/reject',   [PurchaseOrderController::class, 'rejectPurchaseReturn']);
                Route::post('/{id}/complete', [PurchaseOrderController::class, 'completePurchaseReturn']);
                Route::get('/{id}/audit-log', [PurchaseOrderController::class, 'purchaseReturnAuditLog']);
            });

            // ── Production ───────────────────────────────────────────────────
            Route::prefix('production-orders')->group(function () {
                Route::get('/',                       [ProductionController::class, 'index']);
                Route::post('/',                      [ProductionController::class, 'store']);
                Route::get('/{id}',                   [ProductionController::class, 'show']);
                Route::put('/{id}',                   [ProductionController::class, 'update']);
                Route::delete('/{id}',                [ProductionController::class, 'destroy']);
                Route::post('/{id}/assign',           [ProductionController::class, 'assign']);
                Route::put('/{id}/stage',             [ProductionController::class, 'updateStage']);
                Route::post('/{id}/materials',        [ProductionController::class, 'issueMaterials']);
                Route::post('/{id}/qc',               [ProductionController::class, 'qualityCheck']);
                Route::post('/{id}/complete',         [ProductionController::class, 'complete']);
                Route::get('/{id}/timeline',          [ProductionController::class, 'timeline']);
                Route::post('/{id}/confirm',          [ProductionController::class, 'confirm']);
                Route::post('/{id}/cancel',           [ProductionController::class, 'destroy']);
                Route::post('/{id}/regenerate-tasks', [ProductionController::class, 'regenerateTasks']);
                Route::get('/{id}/messages',          [ProductionController::class, 'getMessages']);
                Route::post('/{id}/messages',         [ProductionController::class, 'postMessage']);
                Route::get('/{id}/assignees',         [ProductionController::class, 'assignees']);
                Route::post('/{id}/assignees',        [ProductionController::class, 'addAssignee']);
                Route::delete('/{orderId}/assignees/{userId}', [ProductionController::class, 'removeAssignee']);
                Route::get('/{id}/audit-log',         [ProductionController::class, 'auditLog']);
                Route::get('/{id}/approvals',         [ProductionController::class, 'approvalHistory']);
                Route::post('/{id}/approvals',        [ProductionController::class, 'recordApproval']);
            });

            Route::prefix('production')->group(function () {
                Route::get('/schedule',               [ProductionController::class, 'schedule']);
                Route::get('/auto-assignees',         [ProductionController::class, 'autoAssignees']);
                Route::post('/auto-assignees',        [ProductionController::class, 'createAutoAssignee']);
                Route::delete('/auto-assignees/{id}', [ProductionController::class, 'deleteAutoAssignee']);
            });

            Route::prefix('production-tasks')->group(function () {
                Route::get('/',              [ProductionController::class, 'allTasks']);
                Route::post('/',             [ProductionController::class, 'createTask']);
                Route::put('/{id}',          [ProductionController::class, 'updateTaskDetails']);
                Route::delete('/{id}',       [ProductionController::class, 'deleteTask']);
                Route::put('/{id}/reassign', [ProductionController::class, 'reassignTask']);
            });

            Route::prefix('material-allocations')->group(function () {
                Route::get('/',                                  [ProductionController::class, 'allocations']);
                Route::post('/production-orders/{id}/allocate', [ProductionController::class, 'allocateMaterials']);
                Route::put('/{id}',                             [ProductionController::class, 'updateAllocation']);
                Route::delete('/{id}',                          [ProductionController::class, 'deleteAllocation']);
            });

            Route::apiResource('product-stages', ProductStageController::class);
            Route::put('/product-stages/reorder', [ProductStageController::class, 'reorder']);

            // ── Shipping ─────────────────────────────────────────────────────
            Route::prefix('shipping')->group(function () {
                Route::get('/zones',                          [ShippingController::class, 'adminZones']);
                Route::post('/zones',                         [ShippingController::class, 'createZone']);
                Route::get('/zones/{id}',                     [ShippingController::class, 'showZone']);
                Route::put('/zones/{id}',                     [ShippingController::class, 'updateZone']);
                Route::delete('/zones/{id}',                  [ShippingController::class, 'deleteZone']);
                Route::post('/zones/{id}/countries',          [ShippingController::class, 'addCountries']);
                Route::delete('/zones/{id}/countries/{code}', [ShippingController::class, 'removeCountry']);
                Route::get('/zones/{id}/countries',           [ShippingController::class, 'zoneCountries']);
                Route::get('/methods',                        [ShippingController::class, 'adminMethods']);
                Route::post('/methods',                       [ShippingController::class, 'createMethod']);
                Route::put('/methods/{id}',                   [ShippingController::class, 'updateMethod']);
                Route::delete('/methods/{id}',                [ShippingController::class, 'deleteMethod']);
            });

            // ── Payment transactions ─────────────────────────────────────────
            Route::prefix('payment-transactions')->group(function () {
                Route::get('/',             [PaymentController::class, 'allTransactions']);
                Route::get('/export',       [PaymentController::class, 'exportTransactions']);
                Route::get('/{id}',         [PaymentController::class, 'transactionDetails']);
                Route::post('/{id}/refund', [PaymentController::class, 'refundTransaction']);
            });

            // ── Payment approvals ────────────────────────────────────────────
            Route::prefix('payments')->group(function () {
                Route::get('/pending-approval',   [PaymentApprovalController::class, 'pendingApprovals']);
                Route::post('/{id}/upload-proof', [PaymentApprovalController::class, 'uploadProof']);
                Route::post('/{id}/approve',      [PaymentApprovalController::class, 'approve']);
                Route::post('/{id}/reject',       [PaymentApprovalController::class, 'reject']);
                Route::get('/{id}/proof',         [PaymentApprovalController::class, 'serveProof']);
                // Phase 1 - offline M-Pesa transaction verification via Daraja
                Route::post('/{paymentId}/verify-mpesa', [PaymentController::class, 'verifyMpesa']);
            });

            // ── Tax rates ────────────────────────────────────────────────────
            Route::prefix('tax-rates')->group(function () {
                Route::get('/',            [TaxRateController::class, 'index']);
                Route::post('/',           [TaxRateController::class, 'store']);
                Route::get('/{id}',        [TaxRateController::class, 'show']);
                Route::put('/{id}',        [TaxRateController::class, 'update']);
                Route::delete('/{id}',     [TaxRateController::class, 'destroy']);
                Route::put('/{id}/toggle', [TaxRateController::class, 'toggleStatus']);
            });

            // ── Content pages ────────────────────────────────────────────────
            Route::prefix('pages')->group(function () {
                Route::post('/',               [ContentPageController::class, 'store']);
                Route::put('/{id}',            [ContentPageController::class, 'update']);
                Route::delete('/{id}',         [ContentPageController::class, 'destroy']);
                Route::post('/{id}/publish',   [ContentPageController::class, 'publish']);
                Route::post('/{id}/unpublish', [ContentPageController::class, 'unpublish']);
                Route::post('/{id}/duplicate', [ContentPageController::class, 'duplicate']);
            });

            // ── Reports ──────────────────────────────────────────────────────
            Route::prefix('reports')->group(function () {

                // ── Dashboard KPIs ───────────────────────────────────────────
                Route::get('/dashboard/kpis', [EnhancedReportController::class, 'dashboardKpis']);

                // ── Sales ────────────────────────────────────────────────────
                Route::prefix('sales')->group(function () {
                    Route::get('/summary',         [EnhancedReportController::class, 'salesSummary']);
                    Route::get('/by-product',      [EnhancedReportController::class, 'salesByProduct']);
                    Route::get('/by-category',     [EnhancedReportController::class, 'salesByCategory']);
                    Route::get('/by-customer',     [EnhancedReportController::class, 'salesByCustomer']);
                    Route::get('/by-outlet',       [EnhancedReportController::class, 'salesByOutlet']);
                    Route::get('/payment-methods', [EnhancedReportController::class, 'paymentMethodSummary']);
                });

                // ── Customers ────────────────────────────────────────────────
                Route::prefix('customers')->group(function () {
                    Route::get('/overview', [EnhancedReportController::class, 'customersOverview']);
                });

                // ── Inventory ────────────────────────────────────────────────
                Route::prefix('inventory')->group(function () {
                    Route::get('/stock-on-hand',  [EnhancedReportController::class, 'stockOnHand']);
                    Route::get('/stock-movement', [EnhancedReportController::class, 'stockMovement']);
                    Route::get('/valuation',      [EnhancedReportController::class, 'inventoryValuation']);
                });

                // ── Procurement ──────────────────────────────────────────────
                Route::prefix('procurement')->group(function () {
                    Route::get('/purchase-orders', [EnhancedReportController::class, 'purchaseOrderReport']);
                    Route::get('/by-supplier',     [EnhancedReportController::class, 'spendBySupplier']);
                });

                // ── Production ───────────────────────────────────────────────
                Route::prefix('production')->group(function () {
                    Route::get('/summary',             [EnhancedReportController::class, 'productionSummary']);
                    Route::get('/tailor-productivity', [EnhancedReportController::class, 'tailorProductivity']);
                });

                // ── Financial ────────────────────────────────────────────────
                Route::prefix('financial')->group(function () {
                    Route::get('/profit-loss', [EnhancedReportController::class, 'profitLoss']);
                    Route::get('/expenses',    [EnhancedReportController::class, 'expensesReport']);
                    Route::get('/tax-report',  [EnhancedReportController::class, 'taxReport']);
                    Route::get('/cash-flow',   [EnhancedReportController::class, 'cashFlow']);
                });
            });

            // ── Expenses ─────────────────────────────────────────────────────
            Route::prefix('expenses')->group(function () {

                // Categories & budgets
                Route::get('/categories',      [ExpenseController::class, 'categories']);
                Route::post('/categories',     [ExpenseController::class, 'storeCategory']);
                Route::put('/categories/{id}', [ExpenseController::class, 'updateCategory']);
                Route::get('/budgets',         [ExpenseController::class, 'budgets']);
                Route::post('/budgets',        [ExpenseController::class, 'storeBudget']);
                Route::put('/budgets/{id}',    [ExpenseController::class, 'updateBudget']);

                // Analytics
                Route::get('/summary',         [ExpenseController::class, 'summary']);

                // CRUD
                Route::get('/',                [ExpenseController::class, 'index']);
                Route::post('/',               [ExpenseController::class, 'store']);
                Route::get('/{id}',            [ExpenseController::class, 'show']);
                Route::put('/{id}',            [ExpenseController::class, 'update']);
                Route::delete('/{id}',         [ExpenseController::class, 'destroy']);

                // Workflow
                Route::post('/{id}/submit',    [ExpenseController::class, 'submit']);
                Route::post('/{id}/cancel',    [ExpenseController::class, 'cancel']);

                // Approval actions - requires expenses.approve permission
                Route::post('/{id}/approve',   [ExpenseController::class, 'approve'])
                    ->middleware('permission:expenses.approve,sanctum');
                Route::post('/{id}/reject',    [ExpenseController::class, 'reject'])
                    ->middleware('permission:expenses.approve,sanctum');
                Route::post('/{id}/mark-paid', [ExpenseController::class, 'markPaid'])
                    ->middleware('permission:expenses.approve,sanctum');

                // Receipt upload / download
                Route::post('/{id}/receipt',   [ExpenseController::class, 'uploadReceipt']);
                Route::get('/{id}/receipt',    [ExpenseController::class, 'downloadReceipt']);
            });

            // ── Settings ─────────────────────────────────────────────────────
            Route::prefix('settings')->group(function () {
                Route::get('/',                    [SettingController::class, 'index']);
                Route::put('/',                    [SettingController::class, 'update']);
                Route::post('/logo',               [SettingController::class, 'uploadLogo']);
                Route::get('/payment-providers',   [SettingController::class, 'paymentProviders']);
                Route::put('/payment-providers',   [SettingController::class, 'updatePaymentProviders']);
                Route::get('/email',               [SettingController::class, 'emailSettings']);
                Route::put('/email',               [SettingController::class, 'updateEmailSettings']);
                Route::post('/test-email',         [SettingController::class, 'testEmail']);
                Route::get('/tax',                 [SettingController::class, 'taxSettings']);
                Route::put('/languages',           [SettingController::class, 'updateLanguages']);
                Route::put('/currencies',          [SettingController::class, 'updateCurrencies']);
                Route::post('/cache/clear',        [SettingController::class, 'clearCache']);
                Route::get('/maintenance',         [SettingController::class, 'maintenanceMode']);
                Route::post('/maintenance/toggle', [SettingController::class, 'toggleMaintenanceMode']);
            });
        });

        // ═══ SUPER ADMIN ONLY ════════════════════════════════════════════════

        Route::middleware(['role:super_admin', 'throttle:admin-api'])->prefix('admin')->group(function () {

            Route::prefix('roles')->group(function () {
                Route::get('/',                  [RoleController::class, 'index']);
                Route::post('/',                 [RoleController::class, 'store']);
                Route::get('/{id}',              [RoleController::class, 'show']);
                Route::put('/{id}',              [RoleController::class, 'update']);
                Route::delete('/{id}',           [RoleController::class, 'destroy']);
                Route::get('/{id}/permissions',  [RoleController::class, 'permissions']);
                Route::post('/{id}/permissions', [RoleController::class, 'syncPermissions']);
                Route::post('/{id}/duplicate',   [RoleController::class, 'duplicate']);
            });

            Route::prefix('permissions')->group(function () {
                Route::get('/',        [PermissionController::class, 'index']);
                Route::post('/',       [PermissionController::class, 'store']);
                Route::put('/{id}',    [PermissionController::class, 'update']);
                Route::delete('/{id}', [PermissionController::class, 'destroy']);
                Route::post('/sync',   [PermissionController::class, 'syncAll']);
            });

            Route::prefix('languages')->group(function () {
                Route::get('/',                 [LanguageController::class, 'index']);
                Route::post('/',                [LanguageController::class, 'store']);
                Route::get('/{id}',             [LanguageController::class, 'show']);
                Route::put('/{id}',             [LanguageController::class, 'update']);
                Route::delete('/{id}',          [LanguageController::class, 'destroy']);
                Route::put('/{id}/toggle',      [LanguageController::class, 'toggleStatus']);
                Route::put('/{id}/set-default', [LanguageController::class, 'setDefault']);
            });

            Route::prefix('currencies-management')->group(function () {
                Route::get('/',                 [CurrencyController::class, 'index']);
                Route::post('/',                [CurrencyController::class, 'store']);
                Route::get('/{id}',             [CurrencyController::class, 'show']);
                Route::put('/{id}',             [CurrencyController::class, 'update']);
                Route::delete('/{id}',          [CurrencyController::class, 'destroy']);
                Route::put('/{id}/toggle',      [CurrencyController::class, 'toggleStatus']);
                Route::put('/{id}/set-default', [CurrencyController::class, 'setDefault']);
                Route::put('/{id}/rates',       [CurrencyController::class, 'updateRates']);
                Route::post('/sync-rates',      [CurrencyController::class, 'syncRates']);
            });

            Route::prefix('countries')->group(function () {
                Route::get('/',                         [CountryController::class, 'adminIndex']);
                Route::get('/regions',                  [CountryController::class, 'regions']);
                Route::post('/',                        [CountryController::class, 'store']);
                Route::put('/{code}',                   [CountryController::class, 'update']);
                Route::put('/{code}/shipping-settings', [CountryController::class, 'updateShippingSettings']);
                Route::put('/{code}/toggle',            [CountryController::class, 'toggleStatus']);
            });

            Route::prefix('payment-methods-management')->group(function () {
                Route::get('/',            [PaymentMethodController::class, 'index']);
                Route::post('/',           [PaymentMethodController::class, 'store']);
                Route::get('/{id}',        [PaymentMethodController::class, 'show']);
                Route::put('/{id}',        [PaymentMethodController::class, 'update']);
                Route::delete('/{id}',     [PaymentMethodController::class, 'destroy']);
                Route::put('/{id}/toggle', [PaymentMethodController::class, 'toggleStatus']);
                Route::put('/{id}/config', [PaymentMethodController::class, 'updateConfig']);
            });
        });

        // ═══ POS ROUTES (separate from admin prefix) ═════════════════════════

        Route::middleware(['role:pos_clerk|outlet_manager|admin|super_admin'])->prefix('pos')->group(function () {
            Route::get('/products',                 [PosController::class, 'products']);
            Route::get('/products/search',          [PosController::class, 'searchProducts']);
            Route::post('/sales',                   [PosController::class, 'createSale']);
            Route::get('/sales/today',              [PosController::class, 'todaySales']);
            Route::post('/sales/{id}/receipt',      [PosController::class, 'printReceipt']);
            Route::post('/sales/{id}/return',       [PosController::class, 'processReturn']);
            Route::post('/cash-register/open',          [PosController::class, 'openRegister']);
            Route::post('/cash-register/close',         [PosController::class, 'closeRegister']);
            Route::get('/cash-register/status',         [PosController::class, 'registerStatus']);
            Route::get('/cash-register/transactions',   [PosController::class, 'cashTransactions']);
            Route::get('/cash-register/summary',        [PosController::class, 'cashSummary']);
            Route::post('/cash-register/deposit',       [PosController::class, 'cashDeposit']);
            Route::post('/cash-register/withdrawal',    [PosController::class, 'cashWithdrawal']);
            Route::post('/cash-register/adjustment',    [PosController::class, 'cashAdjustment']);
            Route::get('/cash-register/reconciliation', [PosController::class, 'reconciliation']);
        });

        // ═══ TAILOR ROUTES ═══════════════════════════════════════════════════

        Route::middleware(['role:tailor|admin|super_admin'])->prefix('tailor')->group(function () {
            Route::get('/tasks',             [ProductionController::class, 'myTasks']);
            Route::get('/tasks/{id}',        [ProductionController::class, 'taskDetails']);
            Route::put('/tasks/{id}/status', [ProductionController::class, 'updateTaskStatus']);
            Route::post('/tasks/{id}/note',  [ProductionController::class, 'addTaskNote']);
        });
    });
});

// ═══ PUBLIC ORDER TRACKING (no auth) ════════════════════════════════════════

Route::get('v1/track/{token}', [\App\Http\Controllers\Api\ShipmentController::class, 'publicTrack'])
    ->middleware('throttle:60,1')
    ->name('order.track');

Route::post('v1/track/{token}/query', [\App\Http\Controllers\Api\ShipmentController::class, 'submitQuery'])
    ->middleware('throttle:10,1');

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