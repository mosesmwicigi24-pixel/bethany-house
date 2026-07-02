{{-- Admin Sidebar Component --}}
<div x-show="$store.sidebar.open && !$store.sidebar.isDesktop" 
     class="fixed inset-0 z-40 bg-black bg-opacity-50 md:hidden"
     @click="$store.sidebar.open = false" 
     x-transition.opacity>
</div>

<aside x-data="{ hovered: false }" 
       @mouseenter="hovered = true" 
       @mouseleave="hovered = false"
       :class="{
           'fixed z-50 top-0 left-0 transform transition-transform duration-300 ease-in-out md:transform-none': true,
           'translate-x-0': $store.sidebar.open || $store.sidebar.isDesktop,
           '-translate-x-full': !$store.sidebar.open && !$store.sidebar.isDesktop,
           'md:static md:translate-x-0': true,
           'w-72': hovered || !$store.sidebar.collapsed,
           'w-20': !hovered && $store.sidebar.collapsed
       }"
       class="min-h-screen bg-primary dark:bg-slate-800 border-r dark:border-slate-700 overflow-hidden flex flex-col">
    
    {{-- Close Button for Mobile --}}
    <button @click="$store.sidebar.open = false"
            class="absolute top-3 right-3 text-2xl text-slate-500 dark:text-slate-300 md:hidden z-50"
            x-show="!$store.sidebar.isDesktop" 
            x-transition 
            aria-label="Close sidebar">
        &times;
    </button>
    
    {{-- Logo --}}
    <div class="h-15 flex items-center justify-center py-3 border-b border-gray-100 dark:border-slate-700 shrink-0">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center">
            <template x-if="!hovered && $store.sidebar.collapsed">
                <img src="{{ asset('images/favicon-light.svg') }}" class="h-10" alt="Mini Logo" />
            </template>
            <template x-if="hovered || !$store.sidebar.collapsed">
                <img :src="theme === 'dark' ? '{{ asset('images/logo-light.svg') }}' : '{{ asset('/images/logo-light.svg') }}'"
                     class="h-10" alt="Bethany House" />
            </template>
        </a>
    </div>

    {{-- Scrollable Navigation --}}
    <div class="overflow-y-auto flex-1 pt-2 pb-20 scrollbar-thin scrollbar-thumb-slate-300 dark:scrollbar-thumb-slate-600">
        <nav class="flex flex-col space-y-1 pt-4">
            
            {{-- Dashboard --}}
            <x-admin.sidebar.menu-item 
                icon="bi bi-speedometer2" 
                title="Dashboard" 
                url="/admin/dashboard" />
            
            {{-- ==========================================
                 USER MANAGEMENT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Users & Roles" 
                icon="bi bi-people" 
                slug="users" 
                :links="[
                    ['label' => 'All Users', 'url' => '/admin/users'],
                    ['label' => 'Create User', 'url' => '/admin/users/create'],
                    ['label' => 'Roles & Permissions', 'url' => '/admin/roles'],
                    ['label' => 'Activity Logs', 'url' => '/admin/users/activity'],
                ]" />
            
            {{-- ==========================================
                 PRODUCT MANAGEMENT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Products" 
                icon="bi bi-box-seam" 
                slug="products" 
                :links="[
                    ['label' => 'All Products', 'url' => '/admin/products'],
                    ['label' => 'Add Product', 'url' => '/admin/products/create'],
                    ['label' => 'Categories', 'url' => '/admin/categories'],
                    ['label' => 'Product Variants', 'url' => '/admin/products/variants'],
                    ['label' => 'Bulk Import', 'url' => '/admin/products/import'],
                    ['label' => 'SEO & Meta', 'url' => '/admin/products/seo'],
                ]" />
            
            {{-- ==========================================
                 INVENTORY MANAGEMENT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Inventory" 
                icon="bi bi-boxes" 
                slug="inventory" 
                :links="[
                    ['label' => 'Stock Levels', 'url' => '/admin/inventory/stock'],
                    ['label' => 'Stock Adjustments', 'url' => '/admin/inventory/adjustments'],
                    ['label' => 'Stock Transfers', 'url' => '/admin/inventory/transfers'],
                    ['label' => 'Low Stock Alerts', 'url' => '/admin/inventory/low-stock'],
                    ['label' => 'Raw Materials', 'url' => '/admin/inventory/materials'],
                    ['label' => 'Outlets', 'url' => '/admin/inventory/outlets'],
                ]" />
            
            {{-- ==========================================
                 ORDER MANAGEMENT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Orders" 
                icon="bi bi-cart-check" 
                slug="orders" 
                :links="[
                    ['label' => 'All Orders', 'url' => '/admin/orders'],
                    ['label' => 'Pending Orders', 'url' => '/admin/orders?status=pending'],
                    ['label' => 'Processing', 'url' => '/admin/orders?status=processing'],
                    ['label' => 'Shipped Orders', 'url' => '/admin/orders?status=shipped'],
                    ['label' => 'Completed', 'url' => '/admin/orders?status=completed'],
                    ['label' => 'Returns & Refunds', 'url' => '/admin/orders/returns'],
                    ['label' => 'Abandoned Carts', 'url' => '/admin/orders/abandoned-carts'],
                ]" />
            
            {{-- ==========================================
                 POINT OF SALE (POS)
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Point of Sale" 
                icon="bi bi-calculator" 
                slug="pos" 
                :links="[
                    ['label' => 'New Sale', 'url' => '/admin/pos/sale'],
                    ['label' => 'Sales History', 'url' => '/admin/pos/history'],
                    ['label' => 'Cash Register', 'url' => '/admin/pos/register'],
                    ['label' => 'POS Returns', 'url' => '/admin/pos/returns'],
                    ['label' => 'End of Day', 'url' => '/admin/pos/end-of-day'],
                ]" />
            
            {{-- ==========================================
                 PRODUCTION MANAGEMENT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Production" 
                icon="bi bi-scissors" 
                slug="production" 
                :links="[
                    ['label' => 'Production Orders', 'url' => '/admin/production/orders'],
                    ['label' => 'Create Order', 'url' => '/admin/production/orders/create'],
                    ['label' => 'Tasks', 'url' => '/admin/production/tasks'],
                    ['label' => 'Bill of Materials', 'url' => '/admin/production/bom'],
                    ['label' => 'Assign Tailors', 'url' => '/admin/production/assign'],
                    ['label' => 'Quality Control', 'url' => '/admin/production/quality-control'],
                    ['label' => 'Work in Progress', 'url' => '/admin/production/wip'],
                ]" />
            
            {{-- ==========================================
                 PROCUREMENT & SUPPLIERS
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Procurement" 
                icon="bi bi-truck" 
                slug="procurement" 
                :links="[
                    ['label' => 'Suppliers', 'url' => '/admin/procurement/suppliers'],
                    ['label' => 'Add Supplier', 'url' => '/admin/procurement/suppliers/create'],
                    ['label' => 'Purchase Orders', 'url' => '/admin/procurement/purchase-orders'],
                    ['label' => 'Create PO', 'url' => '/admin/procurement/purchase-orders/create'],
                    ['label' => 'Goods Receipt', 'url' => '/admin/procurement/goods-receipt'],
                    ['label' => 'Purchase Returns', 'url' => '/admin/procurement/purchase-returns'],
                ]" />
            
            {{-- ==========================================
                 CUSTOMERS
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Customers" 
                icon="bi bi-person-circle" 
                slug="customers" 
                :links="[
                    ['label' => 'All Customers', 'url' => '/admin/customers'],
                    ['label' => 'Customer Groups', 'url' => '/admin/customers/groups'],
                    ['label' => 'Reviews & Ratings', 'url' => '/admin/customers/reviews'],
                ]" />
            
            {{-- ==========================================
                 PAYMENTS & FINANCE
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Payments" 
                icon="bi bi-credit-card" 
                slug="payments" 
                :links="[
                    ['label' => 'Transactions', 'url' => '/admin/payments/transactions'],
                    ['label' => 'Payment Methods', 'url' => '/admin/payments/methods'],
                    ['label' => 'M-PESA Setup', 'url' => '/admin/payments/mpesa'],
                    ['label' => 'Paystack Setup', 'url' => '/admin/payments/paystack'],
                    ['label' => 'Flutterwave Setup', 'url' => '/admin/payments/flutterwave'],
                    ['label' => 'Refunds', 'url' => '/admin/payments/refunds'],
                ]" />
            
            {{-- ==========================================
                 MARKETING & PROMOTIONS
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Marketing" 
                icon="bi bi-megaphone" 
                slug="marketing" 
                :links="[
                    ['label' => 'Discounts & Coupons', 'url' => '/admin/marketing/discounts'],
                    ['label' => 'Create Coupon', 'url' => '/admin/marketing/coupons/create'],
                    ['label' => 'Promotions', 'url' => '/admin/marketing/promotions'],
                    ['label' => 'Email Campaigns', 'url' => '/admin/marketing/email-campaigns'],
                    ['label' => 'Banners & Sliders', 'url' => '/admin/marketing/banners'],
                ]" />
            
            {{-- ==========================================
                 REPORTS & ANALYTICS
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Reports & Analytics" 
                icon="bi bi-graph-up" 
                slug="reports" 
                :links="[
                    ['label' => 'Dashboard Overview', 'url' => '/admin/reports/dashboard'],
                    ['label' => 'Sales Reports', 'url' => '/admin/reports/sales'],
                    ['label' => 'Inventory Reports', 'url' => '/admin/reports/inventory'],
                    ['label' => 'Financial Reports', 'url' => '/admin/reports/financial'],
                    ['label' => 'Production Reports', 'url' => '/admin/reports/production'],
                    ['label' => 'Customer Reports', 'url' => '/admin/reports/customers'],
                    ['label' => 'Tax Reports', 'url' => '/admin/reports/tax'],
                    ['label' => 'Report Scheduler', 'url' => '/admin/reports/schedule'],
                ]" />
            
            {{-- ==========================================
                 SHIPPING & DELIVERY
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Shipping" 
                icon="bi bi-box" 
                slug="shipping" 
                :links="[
                    ['label' => 'Shipping Methods', 'url' => '/admin/shipping/methods'],
                    ['label' => 'Shipping Zones', 'url' => '/admin/shipping/zones'],
                    ['label' => 'Shipping Rates', 'url' => '/admin/shipping/rates'],
                    ['label' => 'Track Shipments', 'url' => '/admin/shipping/track-shipments'],
                ]" />
            
            {{-- ==========================================
                 CONTENT MANAGEMENT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Content" 
                icon="bi bi-file-text" 
                slug="content" 
                :links="[
                    ['label' => 'Pages', 'url' => '/admin/content/pages'],
                    ['label' => 'Blog Posts', 'url' => '/admin/content/blog'],
                    ['label' => 'Media Library', 'url' => '/admin/content/media'],
                    ['label' => 'Menus', 'url' => '/admin/content/menus'],
                ]" />
            
            {{-- ==========================================
                 LOCALIZATION
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Localization" 
                icon="bi bi-globe" 
                slug="localization" 
                :links="[
                    ['label' => 'Languages', 'url' => '/admin/localization/languages'],
                    ['label' => 'Translations', 'url' => '/admin/localization/translations'],
                    ['label' => 'Currencies', 'url' => '/admin/localization/currencies'],
                    ['label' => 'Tax Settings', 'url' => '/admin/localization/tax'],
                ]" />
            
            {{-- ==========================================
                 SYSTEM SETTINGS
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Settings" 
                icon="bi bi-gear" 
                slug="settings" 
                :links="[
                    ['label' => 'General Settings', 'url' => '/admin/settings/general'],
                    ['label' => 'Company Info', 'url' => '/admin/settings/company'],
                    ['label' => 'Email Settings', 'url' => '/admin/settings/email'],
                    ['label' => 'Notification Settings', 'url' => '/admin/settings/notifications'],
                    ['label' => 'Payment Gateways', 'url' => '/admin/settings/payment-gateways'],
                    ['label' => 'Security', 'url' => '/admin/settings/security'],
                    ['label' => 'Backup & Restore', 'url' => '/admin/settings/backup'],
                    ['label' => 'System Health', 'url' => '/admin/settings/system-health'],
                ]" />
            
            {{-- ==========================================
                 AUDIT & LOGS
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Audit & Logs" 
                icon="bi bi-journal-text" 
                slug="audit" 
                :links="[
                    ['label' => 'Audit Logs', 'url' => '/admin/audit-logs'],
                    ['label' => 'User Activity', 'url' => '/admin/audit/user-activity'],
                    ['label' => 'System Logs', 'url' => '/admin/audit/system-logs'],
                    ['label' => 'Error Logs', 'url' => '/admin/audit/error-logs'],
                ]" />
            
            {{-- ==========================================
                 HELP & SUPPORT
                 ========================================== --}}
            <x-admin.sidebar.menu-group 
                title="Help & Support" 
                icon="bi bi-question-circle" 
                slug="support" 
                :links="[
                    ['label' => 'Notifications', 'url' => '/admin/notifications'],
                    ['label' => 'Documentation', 'url' => '/admin/help/documentation'],
                    ['label' => 'System Info', 'url' => '/admin/help/system-info'],
                ]" />
            
            {{-- ==========================================
                 PROFILE & LOGOUT
                 ========================================== --}}
            <div class="border-t border-gray-200 dark:border-slate-700 mt-4 pt-4">
                <x-admin.sidebar.menu-item 
                    icon="bi bi-person-gear" 
                    title="My Profile" 
                    url="/admin/profile" />
                
                <x-admin.sidebar.menu-item 
                    icon="bi bi-shield-lock" 
                    title="Two-Factor Auth" 
                    url="/admin/profile/2fa" />
                
                <form method="POST" action="{{ route('admin.logout') }}" x-data>
                    @csrf
                    <button type="submit"
                            class="flex items-center gap-3 px-6 py-3 text-secondary-200 hover:bg-secondary hover:text-primary dark:text-slate-200 dark:hover:bg-slate-700 w-full text-start cursor-pointer">
                        <i class="bi bi-box-arrow-left text-lg"></i>
                        <span x-show="hovered || !$store.sidebar.collapsed" class="text-sm">Logout</span>
                    </button>
                </form>
            </div>
        </nav>
    </div>
</aside>