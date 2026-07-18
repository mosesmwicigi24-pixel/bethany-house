<?php

use Illuminate\Support\Facades\Route;

// ── Auth Livewire pages ────────────────────────────────────────────────────
use App\Http\Livewire\Admin\Auth;
use App\Http\Livewire\Admin\Dashboard;
use App\Http\Livewire\Admin\Profile\TwoFactorSetup;

// ── Users & Roles Livewire pages ───────────────────────────────────────────
use App\Http\Livewire\Admin\Users\UserIndex;
use App\Http\Livewire\Admin\Users\UserCreate;
use App\Http\Livewire\Admin\Users\UserEdit;
use App\Http\Livewire\Admin\Roles\RolesIndex;
use App\Http\Livewire\Admin\Activity\ActivityLogs;

// ── Products Livewire pages ────────────────────────────────────────────────
use App\Http\Livewire\Admin\Products\ProductIndex;
use App\Http\Livewire\Admin\Products\ProductCreate;
use App\Http\Livewire\Admin\Products\ProductEdit;
use App\Http\Livewire\Admin\Products\CategoryIndex;
use App\Http\Livewire\Admin\Products\VariantIndex;
use App\Http\Livewire\Admin\Products\BulkImport;
use App\Http\Livewire\Admin\Products\SeoIndex;

// ── Inventory Livewire pages ───────────────────────────────────────────────
use App\Http\Livewire\Admin\Inventory\StockLevels;
use App\Http\Livewire\Admin\Inventory\StockAdjustments;
use App\Http\Livewire\Admin\Inventory\StockTransfers;
use App\Http\Livewire\Admin\Inventory\LowStockAlerts;
use App\Http\Livewire\Admin\Inventory\RawMaterials;
use App\Http\Livewire\Admin\Inventory\Outlets; 

// -- Orders Livewire pages ───────────────────────────────────────────────
use App\Http\Livewire\Admin\Orders\OrderList;
use App\Http\Livewire\Admin\Orders\ReturnsRefunds;
use App\Http\Livewire\Admin\Orders\AbandonedCarts;

// ── POS Livewire pages ───────────────────────────────────────────────
use App\Http\Livewire\Admin\Pos\Register;
use App\Http\Livewire\Admin\Pos\NewSale;
use App\Http\Livewire\Admin\Pos\SalesHistory;
use App\Http\Livewire\Admin\Pos\EndOfDay;
use App\Http\Livewire\Admin\Pos\PosReturns;

// ── Production Livewire pages ───────────────────────────────────────────────
use App\Http\Livewire\Admin\Production\ProductionOrders;
use App\Http\Livewire\Admin\Production\CreateOrder;
use App\Http\Livewire\Admin\Production\BillOfMaterials;
use App\Http\Livewire\Admin\Production\Tasks;
use App\Http\Livewire\Admin\Production\AssignTailors;
use App\Http\Livewire\Admin\Production\QualityControl;
use App\Http\Livewire\Admin\Production\WorkInProgress;

// -- Procurement Livewire pages ───────────────────────────────────────────────
use App\Http\Livewire\Admin\Procurement\Suppliers;
use App\Http\Livewire\Admin\Procurement\SupplierForm;
use App\Http\Livewire\Admin\Procurement\PurchaseOrders;
use App\Http\Livewire\Admin\Procurement\CreatePurchaseOrder;
use App\Http\Livewire\Admin\Procurement\GoodsReceipt;
use App\Http\Livewire\Admin\Procurement\PurchaseReturns;

// -- Customer Livewire pages -──────────────────────────────────────────────
use App\Http\Livewire\Admin\Customers\Customers;
use App\Http\Livewire\Admin\Customers\CustomerGroups;
use App\Http\Livewire\Admin\Customers\ReviewsRatings;

// -- Payments Livewire pages -──────────────────────────────────────────────
use App\Http\Livewire\Admin\Payments\Transactions;
use App\Http\Livewire\Admin\Payments\PaymentMethods;
use App\Http\Livewire\Admin\Payments\GatewaySetup;
use App\Http\Livewire\Admin\Payments\PaystackSetup;
use App\Http\Livewire\Admin\Payments\FlutterwaveSetup;
use App\Http\Livewire\Admin\Payments\MpesaSetup;
use App\Http\Livewire\Admin\Payments\Refunds;

// -- Marketing Livewire pages -──────────────────────────────────────────────
use App\Http\Livewire\Admin\Marketing\Promotions;
use App\Http\Livewire\Admin\Marketing\Discounts;
use App\Http\Livewire\Admin\Marketing\Banners;
use App\Http\Livewire\Admin\Marketing\EmailCampaigns;

// -- Shipping Livewire pages -──────────────────────────────────────────────
use App\Http\Livewire\Admin\Shipping\ShippingZones;
use App\Http\Livewire\Admin\Shipping\ShippingMethods;
use App\Http\Livewire\Admin\Shipping\ShippingRates;
use App\Http\Livewire\Admin\Shipping\TrackShipments;

// ── Products API controller (CSV template download) ────────────────────────
use App\Http\Controllers\Admin\AdminProductController;

/*
|--------------------------------------------------------------------------
| Root Route - Smart redirect
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    if (auth()->check() && auth()->user()->canAccessAdmin()) {
        return redirect()->route('admin.dashboard');
    }
    return redirect()->route('admin.login');
});

/*
|--------------------------------------------------------------------------
| /admin - Smart redirect
|--------------------------------------------------------------------------
*/
Route::get('/admin', function () {
    if (auth()->check() && auth()->user()->canAccessAdmin()) {
        return redirect()->route('admin.dashboard');
    }
    return redirect()->route('admin.login');
});

/*
|--------------------------------------------------------------------------
| Admin Authentication Routes  (Guest only)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/login',                          Auth\Login::class         )->name('login');
        Route::get('/two-factor',                     Auth\TwoFactorVerify::class)->name('two-factor.verify');
        Route::get('/forgot-password',                Auth\ForgotPassword::class )->name('password.request');
        Route::get('/reset-password/{token}',         Auth\ResetPassword::class  )->name('password.reset');
    });

/*
|--------------------------------------------------------------------------
| Admin Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'ensure.2fa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // ── Dashboard ──────────────────────────────────────────────────────
        Route::get('/dashboard', Dashboard::class)->name('dashboard');

        // ── Logout ─────────────────────────────────────────────────────────
        Route::post('/logout', function () {
            activity()
                ->causedBy(auth()->user())
                ->log('Admin logout');

            auth()->logout();
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('admin.login');
        })->name('logout');

        // ══════════════════════════════════════════════════════════════════
        // Users & Roles  (super_admin | admin)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin'])
            ->group(function () {

                // ── All Users ──────────────────────────────────────────────
                Route::get('/users',                  UserIndex::class  )->name('users.index');
                Route::get('/users/create',           UserCreate::class )->name('users.create');

                // NOTE: /users/activity must be declared BEFORE /users/{userId}/edit
                // so Laravel does not interpret "activity" as a userId parameter.
                Route::get('/users/activity',         ActivityLogs::class)->name('users.activity');

                Route::get('/users/{userId}/edit',    UserEdit::class   )->name('users.edit');

                // ── Roles & Permissions ────────────────────────────────────
                Route::get('/roles',                  RolesIndex::class )->name('roles.index');
            });

        // ══════════════════════════════════════════════════════════════════
        // Products & Categories  (super_admin | admin)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin'])
            ->group(function () {

                // ── All Products ───────────────────────────────────────────
                Route::get('/products',               ProductIndex::class )->name('products.index');
                Route::get('/products/create',        ProductCreate::class)->name('products.create');
                Route::get('/products/{product}/edit', ProductEdit::class)->name('products.edit');

                // NOTE: static segments (/variants, /import, /seo) MUST be
                // declared BEFORE any future /products/{id}/edit route so
                // Laravel never treats "variants", "import", or "seo" as IDs.

                // ── Product Variants ───────────────────────────────────────
                Route::get('/products/variants',      VariantIndex::class )->name('products.variants.index');

                // ── Bulk Import ────────────────────────────────────────────
                // CSV template download goes through the controller;
                // the upload/import UI is the Livewire component.
                // Route::get('/products/import/template', [AdminProductController::class, 'importTemplate'])->name('products.import.template');
                Route::get('/products/import',        BulkImport::class   )->name('products.import');

                // ── SEO & Meta ─────────────────────────────────────────────
                Route::get('/products/seo',           SeoIndex::class     )->name('products.seo.index');

                // ── Categories ─────────────────────────────────────────────
                Route::get('/categories',             CategoryIndex::class)->name('categories.index');
            });
        
        // ══════════════════════════════════════════════════════════════════
        // Inventory Management  (inventory_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,inventory_manager'])
            ->group(function () {
                Route::get('/inventory/stock', StockLevels::class)->name('inventory.stock');
                Route::get('/inventory/low-stock', LowStockAlerts::class)->name('inventory.low-stock');
                Route::get('/inventory/transfers', StockTransfers::class)->name('inventory.transfers');
                Route::get('/inventory/materials', RawMaterials::class)->name('inventory.materials');
                Route::get('/inventory/adjustments', StockAdjustments::class)->name('inventory.adjustments');
                Route::get('/inventory/outlets', Outlets::class)->name('inventory.outlets');
            });

        // ══════════════════════════════════════════════════════════════════
        // Orders & Returns  (order_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,inventory_manager,can:manage orders'])
            ->group(function () {
                Route::get('/orders', OrderList::class)->name('orders.index');
                Route::get('/orders/returns', ReturnsRefunds::class)->name('orders.returns');
                Route::get('/orders/abandoned-carts', AbandonedCarts::class)->name('orders.abandoned');
            });
            

        // ══════════════════════════════════════════════════════════════════
        // POS  (pos_clerk | outlet_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,can:access pos'])
            ->group(function () {
                Route::get('/pos/register', Register::class)->name('pos.register');
                Route::get('/pos/sale', NewSale::class)->name('pos.sale');
                Route::get('/pos/history', SalesHistory::class)->name('pos.history');
                Route::get('/pos/end-of-day', EndOfDay::class)->name('pos.end-of-day');
                Route::get('/pos/returns', PosReturns::class)->name('pos.returns');
            });

        // ══════════════════════════════════════════════════════════════════
        // Production
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,can:view production'])
            ->group(function () {
                Route::get('/production/orders', ProductionOrders::class)->name('production.orders');
                Route::get('/production/orders/create', CreateOrder::class)->name('production.orders.create');
                Route::get('/production/bom', BillOfMaterials::class)->name('production.bom');
                Route::get('/production/tasks', Tasks::class)->name('production.tasks');
                Route::get('/production/assign', AssignTailors::class)->name('production.assign');
                Route::get('/production/quality-control', QualityControl::class)->name('production.quality-control');
                Route::get('/production/wip', WorkInProgress::class)->name('production.wip');
            });

        // ══════════════════════════════════════════════════════════════════
        // Procurement Livewire pages
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,can:manage procurement'])
            ->group(function () {
                Route::get('/procurement/suppliers', Suppliers::class)->name('procurement.suppliers');
                Route::get('/procurement/suppliers/create', SupplierForm::class)->name('procurement.suppliers.create');
                Route::get('/procurement/purchase-orders', PurchaseOrders::class)->name('procurement.purchase-orders');
                Route::get('/procurement/purchase-orders/create', CreatePurchaseOrder::class)->name('procurement.purchase-orders.create');
                Route::get('/procurement/goods-receipt', GoodsReceipt::class)->name('procurement.goods-receipt');
                Route::get('/procurement/purchase-returns', PurchaseReturns::class)->name('procurement.purchase-returns');
            });

        // ══════════════════════════════════════════════════════════════════
        // Customer Management  (customer_service)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,customer_service'])
            ->group(function () {
                Route::get('/customers', Customers::class)->name('customers.index');
                Route::get('/customers/groups', CustomerGroups::class)->name('customers.groups');
                Route::get('/customers/reviews', ReviewsRatings::class)->name('customers.reviews');
            });

        // ══════════════════════════════════════════════════════════════════
        // Payments  (finance_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,finance_manager'])
            ->group(function () {
                Route::get('/payments/transactions', Transactions::class)->name('payments.transactions');
                Route::get('/payments/methods', PaymentMethods::class)->name('payments.methods');
                Route::get('/payments/gateway-setup', GatewaySetup::class)->name('payments.gateway-setup');
                Route::get('/payments/paystack', PaystackSetup::class)->name('payments.gateway-setup.paystack');
                Route::get('/payments/flutterwave', FlutterwaveSetup::class)->name('payments.gateway-setup.flutterwave');
                Route::get('/payments/mpesa', MpesaSetup::class)->name('payments.gateway-setup.mpesa');
                Route::get('/payments/refunds', Refunds::class)->name('payments.refunds');
            });

        // ══════════════════════════════════════════════════════════════════
        // Marketing  (marketing_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,marketing_manager'])
            ->group(function () {
                Route::get('/marketing/promotions', Promotions::class)->name('marketing.promotions');
                Route::get('/marketing/discounts', Discounts::class)->name('marketing.discounts');
                Route::get('/marketing/banners', Banners::class)->name('marketing.banners');
                Route::get('/marketing/email-campaigns', EmailCampaigns::class)->name('marketing.email-campaigns');
            });

        // ══════════════════════════════════════════════════════════════════
        // Shipping  (shipping_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:super_admin,admin,shipping_manager'])
            ->group(function () {
                Route::get('/shipping/zones', ShippingZones::class)->name('shipping.zones');
                Route::get('/shipping/methods', ShippingMethods::class)->name('shipping.methods');
                Route::get('/shipping/rates', ShippingRates::class)->name('shipping.rates');
                Route::get('/shipping/track-shipments', TrackShipments::class)->name('shipping.track-shipments');
            });

        // ══════════════════════════════════════════════════════════════════
        // Outlet Dashboard  (outlet_manager)
        // ══════════════════════════════════════════════════════════════════
        Route::middleware(['role:outlet_manager'])
            ->group(function () {
                Route::get('/outlet/dashboard', function () {
                    return 'Outlet Dashboard - Coming Soon';
                })->name('outlet.dashboard');
            });
    });

/*
|--------------------------------------------------------------------------
| 2FA Setup  - auth only, no ensure.2fa so users can reach it pre-setup
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/profile/2fa/setup', TwoFactorSetup::class)->name('profile.2fa.setup');
    });
/*
|--------------------------------------------------------------------------
| Public SPA pages — /pay/{token} and /track/{token}
|--------------------------------------------------------------------------
| In production Nginx rewrites these to the react-admin bundle; this
| fallback covers nginx-less deploys (single-container / local sandbox)
| where the built frontend is copied into public/. Guarded so nothing
| changes when no bundle is present.
*/
foreach (['/pay/{token}', '/track/{token}'] as $spaRoute) {
    Route::get($spaRoute, function () {
        $index = public_path('index.html');
        abort_unless(file_exists($index), 404);
        return response()->file($index);
    })->where('token', '[a-zA-Z0-9\-_]+')->middleware('throttle:60,1');
}
