import { useEffect } from "react";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReactQueryDevtools } from "@tanstack/react-query-devtools";

import { AdminLayout } from "@/components/layout/AdminLayout";
import { RequireAuth } from "@/components/auth/RequireAuth";
import { ProtectedRoute } from "@/components/auth/ProtectedRoute";
import { ToastContainer } from "@/components/ui/Toast";
import { useAuthStore } from "@/store/auth.store";

// Pages
import LoginPage from "@/pages/auth/LoginPage";
import DashboardPage from "@/pages/dashboard/DashboardPage";

// Lazy-loaded module pages
import { lazy, Suspense } from "react";
import { Spinner } from "@/components/ui/Spinner";

// ── Setup module ────────────────────────────────────────────────────────────
const BusinessSettingsPage = lazy(
    () => import("@/pages/setup/business/BusinessSettingsPage"),
);
const CountriesPage = lazy(
    () => import("@/pages/setup/countries/CountriesPage"),
);
const CurrenciesPage = lazy(
    () => import("@/pages/setup/currencies/CurrenciesPage"),
);
const LanguagesPage = lazy(
    () => import("@/pages/setup/languages/LanguagesPage"),
);
const TaxRatesPage = lazy(() => import("@/pages/setup/taxes/TaxRatesPage"));
const SeasonsPage = lazy(() => import("@/pages/marketing/seasons/SeasonsPage"));
const CampaignsPage = lazy(() => import("@/pages/marketing/campaigns/CampaignsPage"));
const OutletsPage = lazy(() => import("@/pages/setup/outlets/OutletsPage"));
const AttendancePage = lazy(() => import("@/pages/setup/attendance/AttendancePage"));
const PaymentMethodsPage = lazy(
    () => import("@/pages/setup/payment-methods/PaymentMethodsPage"),
);
const RolesPage = lazy(() => import("@/pages/setup/roles/RolesPage"));
const UsersPage = lazy(() => import("@/pages/setup/users/UsersPage"));
const ActivityLogsPage = lazy(
    () => import("@/pages/setup/activity-logs/ActivityLogsPage"),
);
const TrashPage = lazy(
    () => import("@/pages/setup/trash/TrashPage"),
);
const ProfilePage = lazy(() => import("@/pages/profile/ProfilePage"));
const ShippingSettingsPage = lazy(
    () => import("@/pages/setup/shipping/ShippingSettingsPage"),
);
const DatabaseManagementPage = lazy(
    () => import("@/pages/setup/database/DatabaseManagementPage"),
);

// ---- Catalogue Module ─────────────────────────────────────────
const CategoriesPage = lazy(
    () => import("@/pages/catalogue/categories/CategoriesPage"),
);
const ProductsPage = lazy(
    () => import("@/pages/catalogue/products/ProductsListPage"),
);
const ProductFormPage = lazy(
    () => import("@/pages/catalogue/products/ProductFormPage"),
);

// --- Inventory -────────────────────────────────────────────────────────
const StockLevelsPage = lazy(() => import("@/pages/inventory/StockLevelsPage"));
const ProductSerialsPage = lazy(() => import("@/pages/inventory/ProductSerialsPage"));
const StockAdjustmentsPage = lazy(
    () => import("@/pages/inventory/StockAdjustmentsPage"),
);
const StockAdjustmentDetailPage = lazy(
    () => import("@/pages/inventory/StockAdjustmentDetailPage"),
);
const RawMaterialsPage = lazy(
    () => import("@/pages/inventory/RawMaterialsPage"),
);
const StockTransfersPage = lazy(
    () => import("@/pages/inventory/StockTransfersPage"),
);
const StockTransferDetailPage = lazy(
    () => import("@/pages/inventory/StockTransferDetailPage"),
);
const LowStockAlertsPage = lazy(
    () => import("@/pages/inventory/LowStockAlertsPage"),
);

// --- POS -────────────────────────────────────────────────────────
const PosPage = lazy(() => import("@/pages/pos/PosPage"));
const EodReportsPage = lazy(() => import("@/pages/pos/EodReportsPage"));
const EodReportSettingsPage = lazy(() => import("@/pages/pos/EodReportSettingsPage"));
const OutstandingBalancesPage = lazy(() => import("@/pages/pos/OutstandingBalancesPage"));

const OrdersPage = lazy(() => import("@/pages/sales/orders/OrdersListPage"));
const QuotationsPage = lazy(() => import("@/pages/sales/quotations/QuotationsPage"));
const InvoicesPage = lazy(() => import("@/pages/sales/invoices/InvoicesPage"));
const OrderDetailPage = lazy(
    () => import("@/pages/sales/orders/OrderDetailPage"),
);
const CustomersPage = lazy(
    () => import("@/pages/sales/customers/CustomersPage"),
);
const CustomerDetailPage = lazy(
    () => import("@/pages/sales/customers/CustomerDetailPage"),
);

// --- Procurement -────────────────────────────────────────────────────────
const SuppliersPage = lazy(() => import("@/pages/procurement/SuppliersPage"));
const PurchaseOrdersPage = lazy(
    () => import("@/pages/procurement/PurchaseOrdersPage"),
);
const PurchaseOrderDetailPage = lazy(
    () => import("@/pages/procurement/PurchaseOrderDetailPage"),
);
const GoodsReceiptPage = lazy(
    () => import("@/pages/procurement/GoodsReceiptPage"),
);
const GoodsReceiptDetailPage = lazy(
    () => import("@/pages/procurement/GoodsReceiptDetailPage"),
);
const PurchaseReturnsPage = lazy(
    () => import("@/pages/procurement/PurchaseReturnsPage"),
);
const PurchaseReturnDetailPage = lazy(
    () => import("@/pages/procurement/PurchaseReturnDetailPage"),
);

// --- Shipments -────────────────────────────────────────────────────────
const ShipmentsListPage = lazy(
    () => import("@/pages/sales/shipments/ShipmentsListPage"),
);
const ShipmentDetailPage = lazy(
    () => import("@/pages/sales/shipments/ShipmentDetailPage"),
);

const ApprovalsPage = lazy(() => import("@/pages/approvals/ApprovalsPage"));

//--Production -────────────────────────────────────────────────────────
const ProductionCalendarPage = lazy(() => import("@/pages/production/ProductionCalendarPage"));
const ProductionOrdersPage = lazy(() => import("@/pages/production/ProductionPage"));
const ProductionWIPPage     = lazy(() => import("@/pages/production/ProductionPage").then(m => ({ default: m.ProductionWIPPage })));
const ProductionBOMPage     = lazy(() => import("@/pages/production/ProductionPage").then(m => ({ default: m.ProductionBOMPage })));
const ProductionQCPage      = lazy(() => import("@/pages/production/ProductionPage").then(m => ({ default: m.ProductionQCPage })));
const ProductionOrderDetailPage = lazy(() => import("@/pages/production/ProductionOrderDetailPage"));
const TailorWorkspacePage = lazy(
    () => import("@/pages/production/TailorWorkspacePage"),
);
const TrackingPage = lazy(() => import("@/pages/tracking/TrackingPage"));
const PaymentLinkPage = lazy(() => import("@/pages/PaymentLinkPage"));
const PublicQuotationPage = lazy(() => import("@/pages/PublicQuotationPage"));
const NotificationsPage = lazy(
    () => import("@/pages/notifications/NotificationsPage"),
);
const CommsHub = lazy(() => import("@/pages/comms/CommsHub"));
const ProductionAutoAssigneesPage = lazy(
    () => import("@/pages/setup/production/ProductionAutoAssigneesPage"),
);
const ProductionStagesPage = lazy(
    () => import("@/pages/setup/production/ProductionStagesPage"),
);

// ── Finance module ───────────────────────────────────────────────────────────
const ExpensesPage = lazy(() => import("@/pages/expenses/ExpensesPage"));
const ExpenseDetailPage = lazy(
    () => import("@/pages/expenses/ExpenseDetailPage"),
);
const ExpenseCategoriesPage = lazy(
    () => import("@/pages/expenses/ExpenseCategoriesPage"),
);
const ExpenseSummaryPage = lazy(
    () => import("@/pages/expenses/ExpenseSummaryPage"),
);

// ── Finance module ───────────────────────────────────────────────────────────
const PaymentTransactionsPage = lazy(
    () => import("@/pages/finance/PaymentTransactionsPage"),
);

// ── Reports module ───────────────────────────────────────────────────────────
const ReportsPage        = lazy(() => import("@/pages/reports/ReportsPage"));
const SalesReportPage       = lazy(() => import("@/pages/reports/SalesReportPage"));
const CustomersReportPage   = lazy(() => import("@/pages/reports/CustomersReportPage"));
const InventoryReportPage   = lazy(() => import("@/pages/reports/InventoryReportPage"));
const ProductionReportPage  = lazy(() => import("@/pages/reports/ProductionReportPage"));
const ProcurementReportPage = lazy(() => import("@/pages/reports/ProcurementReportPage"));
const FinancialReportPage   = lazy(() => import("@/pages/reports/FinancialReportPage"));
const ProductCostingReportPage = lazy(() => import("@/pages/reports/ProductCostingReportPage"));
const IntelligenceDashboard = lazy(() => import("@/pages/intelligence/IntelligenceDashboard"));

const ModulePlaceholder = lazy(
    (): Promise<{ default: React.ComponentType }> =>
        Promise.resolve({
            default: function Placeholder() {
                return (
                    <div className="flex flex-col items-center justify-center h-64 text-surface-400 gap-3">
                        <svg
                            className="w-12 h-12 opacity-30"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.5}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"
                            />
                        </svg>
                        <div className="text-center">
                            <p className="text-sm font-medium text-surface-500">
                                Coming soon
                            </p>
                            <p className="text-xs text-surface-400 mt-1">
                                This module is under construction.
                            </p>
                        </div>
                    </div>
                );
            },
        }),
);

// ─── Query client config ──────────────────────────────────────────────────────

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000, // 30 s before refetch
            gcTime: 5 * 60_000, // 5 min cache
            refetchOnWindowFocus: true,
            retry: (failureCount, error) => {
                // Don't retry on 401/403/422
                const status = (error as { response?: { status: number } })
                    ?.response?.status;
                if (status && [401, 403, 422].includes(status)) return false;
                return failureCount < 2;
            },
        },
        mutations: {
            retry: false,
        },
    },
});

// ─── App ──────────────────────────────────────────────────────────────────────

function GlobalEventHandlers() {
    const clearAuth = useAuthStore((s) => s.clearAuth);

    useEffect(() => {
        const handler = () => {
            clearAuth();
            queryClient.clear();
        };
        window.addEventListener("auth:expired", handler);
        return () => window.removeEventListener("auth:expired", handler);
    }, [clearAuth]);

    return null;
}

function PageLoader() {
    return (
        <div className="flex items-center justify-center h-64">
            <Spinner size="lg" />
        </div>
    );
}

export default function App() {
    // Detect public pages before React Router initialises so we can render
    // a minimal router (basename="/") instead of the full admin app.
    // Matches both /pay/... and /admin/pay/... (Nginx rewrite path).
    const isPublicPage = /^\/(admin\/)?(pay|track)\//.test(window.location.pathname);

    return (
        <QueryClientProvider client={queryClient}>
            {isPublicPage ? (
                /* ── Public pages: /pay/:token  and  /track/:token ─────────────────
                   basename="/" works whether the URL arrives as /pay/TOKEN (direct)
                   or /admin/pay/TOKEN (after the Nginx internal rewrite).           */
                <BrowserRouter basename="/">
                    <Routes>
                        <Route
                            path="/pay/:token"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <PaymentLinkPage />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/quote/:token"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <PublicQuotationPage />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/track/:token"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <TrackingPage />
                                </Suspense>
                            }
                        />
                        {/* Nginx rewrite variants — /admin/pay/... and /admin/track/... */}
                        <Route
                            path="/admin/pay/:token"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <PaymentLinkPage />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/admin/quote/:token"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <PublicQuotationPage />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/admin/track/:token"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <TrackingPage />
                                </Suspense>
                            }
                        />
                    </Routes>
                </BrowserRouter>
            ) : (
                /* ── Admin app — all routes under /admin basename ─────────────── */
                <BrowserRouter basename={import.meta.env.BASE_URL}>
                    <GlobalEventHandlers />

                    <Routes>
                        {/* Public */}
                        <Route path="/login" element={<LoginPage />} />
                        <Route
                            path="/"
                            element={<Navigate to="/dashboard" replace />}
                        />

                        {/* Protected - all wrapped in AdminLayout */}
                        <Route
                            element={
                                <RequireAuth>
                                    <AdminLayout />
                                </RequireAuth>
                            }
                        >
                        <Route
                            path="/dashboard"
                            element={
                                <ProtectedRoute permission="dashboard.view">
                                    <DashboardPage />
                                </ProtectedRoute>
                            }
                        />
                        {/* ── Procurement ──────────────────────────────────── */}
                        <Route
                            path="/procurement/suppliers"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <SuppliersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement/purchase-orders"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <PurchaseOrdersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement/purchase-orders/:id"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <PurchaseOrderDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement/goods-receipt"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <GoodsReceiptPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement/goods-receipt/:id"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <GoodsReceiptDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement/returns"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <PurchaseReturnsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement/returns/:id"
                            element={
                                <ProtectedRoute permission="procurement.view">
                                <Suspense fallback={<PageLoader />}>
                                    <PurchaseReturnDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/procurement"
                            element={
                                <Navigate
                                    to="/procurement/purchase-orders"
                                    replace
                                />
                            }
                        />
                        <Route
                            path="/procurement/*"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <ModulePlaceholder />
                                </Suspense>
                            }
                        />
                        {/* ── Sales / Orders ────────────────────────────────── */}
                        <Route
                            path="/sales/orders"
                            element={
                                <ProtectedRoute permission="orders.view">
                                <Suspense fallback={<PageLoader />}>
                                    <OrdersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        {/* Channel-scoped order views (Sales nav). Distinct paths so
                            they don't collide with /sales/orders/:id. */}
                        <Route
                            path="/sales/pos-orders"
                            element={
                                <ProtectedRoute permission="orders.view">
                                <Suspense fallback={<PageLoader />}>
                                    <OrdersPage channel="pos" />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/online-orders"
                            element={
                                <ProtectedRoute permission="orders.view">
                                <Suspense fallback={<PageLoader />}>
                                    <OrdersPage channel="online" />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/whatsapp-orders"
                            element={
                                <ProtectedRoute permission="orders.view">
                                <Suspense fallback={<PageLoader />}>
                                    <OrdersPage channel="whatsapp" />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/orders/:id"
                            element={
                                <ProtectedRoute permission="orders.view">
                                <Suspense fallback={<PageLoader />}>
                                    <OrderDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/customers"
                            element={
                                <ProtectedRoute permission="customers.view">
                                <Suspense fallback={<PageLoader />}>
                                    <CustomersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/customers/:id"
                            element={
                                <ProtectedRoute permission="customers.view">
                                <Suspense fallback={<PageLoader />}>
                                    <CustomerDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/quotations"
                            element={
                                <ProtectedRoute permission="quotations.view">
                                <Suspense fallback={<PageLoader />}>
                                    <QuotationsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/invoices"
                            element={
                                <ProtectedRoute permission="orders.view">
                                <Suspense fallback={<PageLoader />}>
                                    <InvoicesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/shipments"
                            element={
                                <ProtectedRoute permission="shipment.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ShipmentsListPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/shipments/:id"
                            element={
                                <ProtectedRoute permission="shipment.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ShipmentDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/sales/*"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <ModulePlaceholder />
                                </Suspense>
                            }
                        />
                        {/* ── POS ───────────────────────────────────────────── */}
                        <Route
                            path="/pos"
                            element={
                                <ProtectedRoute permission="pos.access">
                                <Suspense fallback={<PageLoader />}>
                                    <PosPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/pos/eod-reports"
                            element={
                                <ProtectedRoute permission="pos.access">
                                <Suspense fallback={<PageLoader />}>
                                    <EodReportsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/pos/outstanding-balances"
                            element={
                                <ProtectedRoute permission="pos.access">
                                <Suspense fallback={<PageLoader />}>
                                    <OutstandingBalancesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/pos/eod-settings"
                            element={
                                <ProtectedRoute permission="pos.access">
                                <Suspense fallback={<PageLoader />}>
                                    <EodReportSettingsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/pos/*"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <ModulePlaceholder />
                                </Suspense>
                            }
                        />
                        {/* ── Production ────────────────────────────────────── */}
                        <Route
                            path="/production"
                            element={<Navigate to="/production/orders" replace />}
                        />
                        <Route
                            path="/production/orders"
                            element={
                                <ProtectedRoute permission="production.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionOrdersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/orders/:id"
                            element={
                                <ProtectedRoute permission="production.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionOrderDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/wip"
                            element={
                                <ProtectedRoute permission="production.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionWIPPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/bom"
                            element={
                                <ProtectedRoute permission="production.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionBOMPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/qc"
                            element={
                                <ProtectedRoute permission="production.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionQCPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/my-tasks"
                            element={
                                <ProtectedRoute permission="production.worker">
                                <Suspense fallback={<PageLoader />}>
                                    <TailorWorkspacePage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/calendar"
                            element={
                                <ProtectedRoute permission="production.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionCalendarPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/production/*"
                            element={<Navigate to="/production/orders" replace />}
                        />
                        {/* ── Inventory ─────────────────────────────────────── */}
                        <Route
                            path="/inventory/stock-levels"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <StockLevelsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/serials"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductSerialsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/adjustments"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <StockAdjustmentsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/adjustments/:id"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <StockAdjustmentDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/materials"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <RawMaterialsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/transfers"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <StockTransfersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/transfers/:id"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <StockTransferDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/low-stock"
                            element={
                                <ProtectedRoute permission="inventory.view">
                                <Suspense fallback={<PageLoader />}>
                                    <LowStockAlertsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/inventory/*"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <ModulePlaceholder />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/approvals"
                            element={
                                <ProtectedRoute anyOf={["procurement.approve", "inventory.approve"]}>
                                <Suspense fallback={<PageLoader />}>
                                    <ApprovalsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/notifications"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <NotificationsPage />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/comms"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <CommsHub />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/comms/:channelId"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <CommsHub />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/settings/production/auto-assignees"
                            element={
                                <ProtectedRoute permission="production.configure_auto_assignees">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionAutoAssigneesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/production/stages"
                            element={
                                <ProtectedRoute permission="production.configure_auto_assignees">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionStagesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        {/* ── Finance / Expenses ────────────────────────────── */}
                        <Route
                            path="/expenses"
                            element={
                                <ProtectedRoute permission="expenses.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ExpensesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/expenses/analytics"
                            element={
                                <ProtectedRoute permission="expenses.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ExpenseSummaryPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/expenses/settings"
                            element={
                                <ProtectedRoute permission="expenses.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ExpenseCategoriesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/expenses/:id"
                            element={
                                <ProtectedRoute permission="expenses.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ExpenseDetailPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        {/* ── Finance ──────────────────────────────────────── */}
                        <Route
                            path="/finance/transactions"
                            element={
                                <ProtectedRoute permission="payments.view">
                                <Suspense fallback={<PageLoader />}>
                                    <PaymentTransactionsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        {/* ── Reports & Analytics ───────────────────────────── */}
                        <Route
                            path="/reports"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ReportsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/sales"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <SalesReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/customers"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <CustomersReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/inventory"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <InventoryReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/production"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductionReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/procurement"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProcurementReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/financial"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <FinancialReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/reports/production/costing/:id"
                            element={
                                <ProtectedRoute permission="reports.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductCostingReportPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        {/* ── Intelligence ──────────────────────────────────── */}
                        <Route
                            path="/intelligence"
                            element={
                                <ProtectedRoute anyOf={["inventory.view", "production.view", "customers.view", "expenses.view"]}>
                                <Suspense fallback={<PageLoader />}>
                                    <IntelligenceDashboard />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />

                        {/* ── Catalogue ─────────────────────────────────────── */}
                        <Route
                            path="/catalogue/products"
                            element={
                                <ProtectedRoute permission="products.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/catalogue/products/new"
                            element={
                                <ProtectedRoute permission="products.create">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductFormPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/catalogue/products/:id"
                            element={
                                <ProtectedRoute permission="products.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ProductFormPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/catalogue/categories"
                            element={
                                <ProtectedRoute permission="products.view">
                                <Suspense fallback={<PageLoader />}>
                                    <CategoriesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/catalogue/*"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <ModulePlaceholder />
                                </Suspense>
                            }
                        />
                        {/* ── Setup / Settings ──────────────────────────────── */}
                        <Route
                            path="/settings/business"
                            element={
                                <ProtectedRoute anyOfRoles={["super_admin", "admin"]}>
                                <Suspense fallback={<PageLoader />}>
                                    <BusinessSettingsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/countries"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <CountriesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/currencies"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <CurrenciesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/languages"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <LanguagesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/taxes"
                            element={
                                <ProtectedRoute permission="settings.view">
                                <Suspense fallback={<PageLoader />}>
                                    <TaxRatesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/outlets"
                            element={
                                <ProtectedRoute permission="outlets.view">
                                <Suspense fallback={<PageLoader />}>
                                    <OutletsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/attendance"
                            element={
                                <ProtectedRoute permission="attendance.view_team">
                                <Suspense fallback={<PageLoader />}>
                                    <AttendancePage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/payment-methods"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <PaymentMethodsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/roles"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <RolesPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/users"
                            element={
                                <ProtectedRoute permission="users.view">
                                <Suspense fallback={<PageLoader />}>
                                    <UsersPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/activity-logs"
                            element={
                                <ProtectedRoute permission="users.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ActivityLogsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/trash"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <TrashPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/database"
                            element={
                                <ProtectedRoute role="super_admin">
                                <Suspense fallback={<PageLoader />}>
                                    <DatabaseManagementPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/profile"
                            element={
                                <Suspense fallback={<PageLoader />}>
                                    <ProfilePage />
                                </Suspense>
                            }
                        />
                        <Route
                            path="/settings/shipping"
                            element={
                                <ProtectedRoute permission="settings.view">
                                <Suspense fallback={<PageLoader />}>
                                    <ShippingSettingsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/marketing/seasons"
                            element={
                                <ProtectedRoute permission="products.view">
                                <Suspense fallback={<PageLoader />}>
                                    <SeasonsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/marketing/campaigns"
                            element={
                                <ProtectedRoute permission="products.view">
                                <Suspense fallback={<PageLoader />}>
                                    <CampaignsPage />
                                </Suspense>
                                </ProtectedRoute>
                            }
                        />
                        <Route
                            path="/settings/*"
                            element={
                                <Navigate to="/settings/business" replace />
                            }
                        />
                        {/* Catch-all → dashboard */}
                        <Route
                            path="*"
                            element={<Navigate to="/dashboard" replace />}
                        />
                    </Route>
                </Routes>

                <ToastContainer />
                </BrowserRouter>
            )}

            {import.meta.env.DEV && (
                <ReactQueryDevtools buttonPosition="bottom-left" />
            )}
        </QueryClientProvider>
    );
}