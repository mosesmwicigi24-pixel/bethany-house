import { useState, useEffect, useRef } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { useQuery } from "@tanstack/react-query";
import { get } from "@/api/client";
import { useAuthStore } from "@/store/auth.store";
import { usePermissions } from "@/hooks/usePermissions";
import type { NavGroup } from "@/types";

// ─── Role name formatter ───────────────────────────────────────────────────────
function formatRoleName(raw: string): string {
    if (!raw) return raw;
    // Convert snake_case / slug to Title Case words
    return raw
        .replace(/_/g, " ")
        .replace(/-/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

// PermissionGate is used by pages; Sidebar uses can() inline for nav filtering

// ─── Navigation definition ────────────────────────────────────────────────────
const NAV: NavGroup[] = [
    // ── Workspace ────────────────────────────────────────────────────────────
    // Personal / at-a-glance items. Dashboard is the true "overview"; Approvals
    // sits here because it spans procurement AND payment workflows and needs
    // visibility for all roles that action it daily.
    {
        label: "Workspace",
        items: [
            {
                label: "Dashboard",
                href: "/dashboard",
                icon: "dashboard",
            },
            {
                label: "Approvals",
                href: "/approvals",
                icon: "approvals",
                // Visible to anyone who can approve procurement OR international payments
                anyOfPermissions: ["procurement.view", "payments.approve_international"],
            },
            {
                label: "Notifications",
                href: "/notifications",
                icon: "notifications",
                permission: "notifications.view",
            },
            {
                label: "Messages",
                href: "/comms",
                icon: "messages",
            },
        ],
    },

    // ── Catalog ───────────────────────────────────────────────────────────────
    {
        label: "Catalog",
        items: [
            {
                label: "Products",
                href: "/catalogue/products",
                icon: "products",
                permission: "products.view",
            },
            {
                label: "Categories",
                href: "/catalogue/categories",
                icon: "categories",
                permission: "products.view",
            },
        ],
    },

    // ── Sales ─────────────────────────────────────────────────────────────────
    {
        label: "Sales",
        items: [
            {
                label: "POS",
                href: "/pos",
                icon: "pos",
                permission: "pos.access",
            },
            {
                label: "POS Orders",
                href: "/sales/pos-orders",
                icon: "orders",
                permission: "orders.view",
            },
            {
                label: "Online Orders",
                href: "/sales/online-orders",
                icon: "orders",
                permission: "orders.view",
            },
            {
                label: "WhatsApp Orders",
                href: "/sales/whatsapp-orders",
                icon: "orders",
                permission: "orders.view",
            },
            {
                label: "Quotations",
                href: "/sales/quotations",
                icon: "orders",
                permission: "quotations.view",
            },
            {
                label: "Invoices",
                href: "/sales/invoices",
                icon: "orders",
                permission: "orders.view",
            },
            {
                label: "Shipments",
                href: "/sales/shipments",
                icon: "shipments",
                permission: "shipment.view",
            },
            {
                label: "Customers",
                href: "/sales/customers",
                icon: "customers",
                permission: "customers.view",
            },
            {
                label: "Balances",
                href: "/pos/outstanding-balances",
                icon: "eod-reports",
                permission: "pos.access",
            },
            {
                label: "EoD Reports",
                href: "/pos/eod-reports",
                icon: "eod-reports",
                permission: "settings.view",
            },
            {
                label: "EoD Settings",
                href: "/pos/eod-settings",
                icon: "eod-settings",
                permission: "settings.edit",
            },
        ],
    },

    // ── Production ────────────────────────────────────────────────────────────
    // Ordered to mirror the production workflow:
    //   Bill of Materials → Orders → WIP → QC → My Tasks
    // Auto-Assignees and Production Stages are configuration items and have
    // been moved to Setup where they belong.
    {
        label: "Production",
        items: [
            {
                label: "My Tasks",
                href: "/production/my-tasks",
                icon: "tasks",
                permission: "production.worker",
            },
            {
                label: "Production Orders",
                href: "/production/orders",
                icon: "production",
                permission: "production.view",
            },
            {
                label: "Work In Progress",
                href: "/production/wip",
                icon: "wip",
                permission: "production.view",
            },
            {
                label: "Quality Control",
                href: "/production/qc",
                icon: "qc",
                permission: "production.view",
            },
            {
                label: "Calendar",
                href: "/production/calendar",
                icon: "calendar",
                // Visible to production team AND sales staff who raise orders
                anyOfPermissions: ["production.view", "production.raise_order"],
            },
            {
                label: "Bill of Materials",
                href: "/production/bom",
                icon: "bom",
                permission: "production.view",
            },
        ],
    },

    // ── Inventory ─────────────────────────────────────────────────────────────
    {
        label: "Inventory",
        items: [
            {
                label: "Stock Levels",
                href: "/inventory/stock-levels",
                icon: "stock",
                permission: "inventory.view",
            },
            {
                label: "Product Serials",
                href: "/inventory/serials",
                icon: "stock",
                permission: "inventory.view",
            },
            {
                label: "Stock Adjustments",
                href: "/inventory/adjustments",
                icon: "adjustments",
                permission: "inventory.view",
            },
            {
                label: "Stock Transfers",
                href: "/inventory/transfers",
                icon: "transfers",
                permission: "inventory.view",
            },
            {
                label: "Raw Materials",
                href: "/inventory/materials",
                icon: "materials",
                permission: "inventory.view",
            },
            {
                label: "Low Stock Alerts",
                href: "/inventory/low-stock",
                icon: "alerts",
                permission: "inventory.view",
            },
        ],
    },

    // ── Procurement ───────────────────────────────────────────────────────────
    // Approvals removed from here - promoted to Workspace so finance staff
    // who only have payments.approve_international can find it.
    {
        label: "Procurement",
        items: [
            {
                label: "Purchase Orders",
                href: "/procurement/purchase-orders",
                icon: "purchase-orders",
                permission: "procurement.view",
            },
            {
                label: "Suppliers",
                href: "/procurement/suppliers",
                icon: "suppliers",
                permission: "procurement.view",
            },
            {
                label: "Goods Receipt",
                href: "/procurement/goods-receipt",
                icon: "grn",
                permission: "procurement.receive",
            },
            {
                label: "Purchase Returns",
                href: "/procurement/returns",
                icon: "returns",
                permission: "procurement.view",
            },
        ],
    },

    // ── Expenses ──────────────────────────────────────────────────────────────
    // Renamed from "Finance" to match actual scope (expenses only).
    // "Categories & Budgets" is configuration and has been moved to Setup.
    {
        label: "Expenses",
        items: [
            {
                label: "Expenses",
                href: "/expenses",
                icon: "expenses",
                permission: "expenses.view",
            },
            {
                label: "Categories",
                href: "/expenses/settings",
                icon: "budget",
                permission: "expenses.budgets",
            },
            {
                label: "Analytics",
                href: "/expenses/analytics",
                icon: "reports",
                permission: "expenses.view",
            },
        ],
    },

    // ── Finance ───────────────────────────────────────────────────────────────
    {
        label: "Finance",
        items: [
            {
                label: "Payment Transactions",
                href: "/finance/transactions",
                icon: "transactions",
                permission: "payments.view",
            },
        ],
    },

    // ── Reports ───────────────────────────────────────────────────────────────
    // Sub-report order mirrors the operational section order above:
    //   Sales → Customers → Production → Inventory → Procurement → Finance
    {
        label: "Reports",
        items: [
            {
                label: "Overview",
                href: "/reports",
                icon: "reports",
                permission: "reports.view",
            },
            {
                label: "Sales",
                href: "/reports/sales",
                icon: "orders",
                permission: "reports.view",
            },
            {
                label: "Customers",
                href: "/reports/customers",
                icon: "customers",
                permission: "reports.view",
            },
            {
                label: "Production",
                href: "/reports/production",
                icon: "production",
                permission: "reports.view",
            },
            {
                label: "Inventory",
                href: "/reports/inventory",
                icon: "stock",
                permission: "reports.view",
            },
            {
                label: "Procurement",
                href: "/reports/procurement",
                icon: "purchase-orders",
                permission: "reports.view",
            },
            {
                label: "Finance",
                href: "/reports/financial",
                icon: "expenses",
                permission: "reports.view",
            },
        ],
    },

    // ── Intelligence ──────────────────────────────────────────────────────────
    {
        label: "Intelligence",
        items: [
            {
                label: "Signals",
                href: "/intelligence",
                icon: "intelligence",
                permission: "reports.view",
            },
        ],
    },

    // ── Setup ─────────────────────────────────────────────────────────────────
    // Ordered broad-to-specific, with logical clusters:
    //   Identity → Locations → Access control →
    //   Localisation → Transactional config → Production config → Audit
    {
        label: "Setup",
        items: [
            // Identity
            {
                label: "Business",
                href: "/settings/business",
                icon: "business",
                permission: "settings.view",
            },
            // Locations
            {
                label: "Outlets",
                href: "/settings/outlets",
                icon: "outlets",
                permission: "settings.view",
            },
            {
                label: "Attendance",
                href: "/settings/attendance",
                icon: "attendance",
                permission: "attendance.view_team",
            },
            // Access control (grouped together)
            {
                label: "Roles",
                href: "/settings/roles",
                icon: "roles",
                permission: "roles.view",
            },
            {
                label: "Users",
                href: "/settings/users",
                icon: "users",
                permission: "users.view",
            },
            // Localisation cluster
            {
                label: "Countries",
                href: "/settings/countries",
                icon: "countries",
                permission: "settings.view",
            },
            {
                label: "Currencies",
                href: "/settings/currencies",
                icon: "currencies",
                permission: "settings.view",
            },
            {
                label: "Languages",
                href: "/settings/languages",
                icon: "languages",
                permission: "settings.view",
            },
            // Transactional config cluster
            {
                label: "Tax Rates",
                href: "/settings/taxes",
                icon: "taxes",
                permission: "settings.view",
            },
            {
                label: "Payment Methods",
                href: "/settings/payment-methods",
                icon: "payments-setup",
                permission: "settings.view",
            },
            {
                label: "Shipping",
                href: "/settings/shipping",
                icon: "shipping",
                permission: "settings.view",
            },
            // Production configuration (moved from Production group)
            {
                label: "Production Stages",
                href: "/settings/production/stages",
                icon: "layers",
                permission: "production.configure_auto_assignees",
            },
            {
                label: "Auto-Assignees",
                href: "/settings/production/auto-assignees",
                icon: "auto-assign",
                permission: "production.configure_auto_assignees",
            },
            // Expense configuration (moved from Expenses group)
            // {
            //     label: "Expense Categories",
            //     href: "/expenses/settings",
            //     icon: "budget",
            //     permission: "expenses.budgets",
            // },
            // Audit (always last)
            {
                label: "Activity Logs",
                href: "/settings/activity-logs",
                icon: "activity-logs",
                permission: "settings.view",
            },
            {
                label: "Recycle Bin",
                href: "/settings/trash",
                icon: "trash",
                permission: "settings.manage",
            },
            {
                label: "Database Management",
                href: "/settings/database",
                icon: "database",
                permission: "settings.manage_database",
            },
        ],
    },
];

// ─── Icon map ─────────────────────────────────────────────────────────────────
const Icon = ({ name }: { name: string }) => {
    const paths: Record<string, React.ReactNode> = {
        business: (
            <>
                <path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </>
        ),
        countries: (
            <>
                <circle cx="12" cy="12" r="10" />
                <path d="M12 2a14.5 14.5 0 000 20M12 2a14.5 14.5 0 010 20M2 12h20M4.5 7h15M4.5 17h15" />
            </>
        ),
        currencies: (
            <>
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v8M9.5 10.5h4a1.5 1.5 0 010 3h-3a1.5 1.5 0 000 3h4" />
            </>
        ),
        languages: (
            <>
                <path d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
            </>
        ),
        taxes: (
            <>
                <path d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z" />
            </>
        ),
        "payments-setup": (
            <>
                <rect x="1" y="4" width="22" height="16" rx="2" />
                <line x1="1" y1="10" x2="23" y2="10" />
            </>
        ),
        dashboard: (
            <>
                <rect x="3" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="3" width="7" height="7" rx="1" />
                <rect x="3" y="14" width="7" height="7" rx="1" />
                <rect x="14" y="14" width="7" height="7" rx="1" />
            </>
        ),
        pos: (
            <>
                <path d="M3 5a2 2 0 012-2h14a2 2 0 012 2v4H3V5z" />
                <path d="M3 9h18v10a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                <path d="M8 14h8" />
            </>
        ),
        "eod-reports": (
            <>
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
                <rect x="9" y="3" width="6" height="4" rx="1" />
                <path d="M9 12h6M9 16h4" />
                <circle cx="16.5" cy="16.5" r="3" />
                <path d="M16.5 15v1.5l1 1" />
            </>
        ),
        "eod-settings": (
            <>
                <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <circle cx="12" cy="12" r="3" />
            </>
        ),
        orders: (
            <>
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
                <rect x="9" y="3" width="6" height="4" rx="1" />
                <path d="M9 12h6M9 16h4" />
            </>
        ),
        shipments: (
            <>
                <path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v9a2 2 0 01-2 2h-2" />
                <circle cx="9" cy="20" r="2" /><circle cx="16" cy="20" r="2" />
                <path d="M14 3v5h5" />
            </>
        ),
        customers: (
            <>
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" />
            </>
        ),
        "purchase-orders": (
            <>
                <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" />
                <line x1="3" y1="6" x2="21" y2="6" />
                <path d="M16 10a4 4 0 01-8 0" />
            </>
        ),
        suppliers: (
            <>
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                <polyline points="9 22 9 12 15 12 15 22" />
            </>
        ),
        grn: (
            <>
                <path d="M5 12l5 5L20 7" />
            </>
        ),
        returns: (
            <>
                <path d="M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6" />
            </>
        ),
        production: (
            <>
                <path d="M12 2L2 7l10 5 10-5-10-5z" />
                <path d="M2 17l10 5 10-5M2 12l10 5 10-5" />
            </>
        ),
        wip: (
            <>
                <circle cx="12" cy="12" r="10" />
                <polyline points="12 6 12 12 16 14" />
            </>
        ),
        bom: (
            <>
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="8" y1="13" x2="16" y2="13" />
                <line x1="8" y1="17" x2="16" y2="17" />
            </>
        ),
        qc: (
            <>
                <path d="M22 11.08V12a10 10 0 11-5.93-9.14" />
                <polyline points="22 4 12 14.01 9 11.01" />
            </>
        ),
        stock: (
            <>
                <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" />
            </>
        ),
        adjustments: (
            <>
                <path d="M12 4v16m-4-4l4 4 4-4M4 8h16" />
            </>
        ),
        transfers: (
            <>
                <path d="M5 12h14M12 5l7 7-7 7" />
            </>
        ),
        materials: (
            <>
                <path d="M12 2a10 10 0 100 20A10 10 0 0012 2z" />
                <path d="M12 8v8M8 12h8" />
            </>
        ),
        alerts: (
            <>
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </>
        ),
        products: (
            <>
                <rect x="2" y="3" width="20" height="14" rx="2" />
                <path d="M8 21h8M12 17v4" />
            </>
        ),
        categories: (
            <>
                <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z" />
            </>
        ),
        users: (
            <>
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" />
            </>
        ),
        roles: (
            <>
                <rect x="3" y="11" width="18" height="11" rx="2" />
                <path d="M7 11V7a5 5 0 0110 0v4" />
            </>
        ),
        outlets: (
            <>
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
            </>
        ),
        shipping: (
            <>
                <path d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
            </>
        ),
        logs: (
            <>
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                <line x1="8" y1="10" x2="16" y2="10" />
                <line x1="8" y1="14" x2="16" y2="14" />
                <line x1="8" y1="18" x2="12" y2="18" />
            </>
        ),
        // Phase 4 - tailor task workspace
        tasks: (
            <>
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
                <rect x="9" y="3" width="6" height="4" rx="1" />
                <path d="M9 12l2 2 4-4" />
            </>
        ),
        // Phase 6 - notifications bell
        notifications: (
            <>
                <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.73 21a2 2 0 01-3.46 0" />
            </>
        ),
        messages: (
            <>
                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
            </>
        ),
        // Approvals - checkmark shield
        approvals: (
            <>
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                <path d="M9 12l2 2 4-4" />
            </>
        ),
        // Activity logs - clock with list lines
        "activity-logs": (
            <>
                <circle cx="12" cy="12" r="10" />
                <polyline points="12 6 12 12 16 14" />
            </>
        ),
        // Attendance - clock face (time clock)
        attendance: (
            <>
                <circle cx="12" cy="12" r="10" />
                <polyline points="12 6 12 12 16 14" />
            </>
        ),
        // Auto-assign - person with plus badge
        "auto-assign": (
            <>
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                <circle cx="9" cy="7" r="4" />
                <line x1="19" y1="8" x2="19" y2="14" />
                <line x1="16" y1="11" x2="22" y2="11" />
            </>
        ),
        // Expenses - receipt with currency symbol
        expenses: (
            <>
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="9" y1="13" x2="15" y2="13" />
                <line x1="9" y1="17" x2="13" y2="17" />
                <path d="M11 10.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" />
            </>
        ),
        // Reports - bar chart with trend line
        reports: (
            <>
                <line x1="18" y1="20" x2="18" y2="10" />
                <line x1="12" y1="20" x2="12" y2="4" />
                <line x1="6" y1="20" x2="6" y2="14" />
                <path d="M2 20h20" />
            </>
        ),
        // Calendar - production calendar view
        calendar: (
            <>
                <rect x="3" y="4" width="18" height="18" rx="2" />
                <line x1="16" y1="2" x2="16" y2="6" />
                <line x1="8" y1="2" x2="8" y2="6" />
                <line x1="3" y1="10" x2="21" y2="10" />
                <rect x="7" y="14" width="3" height="3" rx="0.5" />
            </>
        ),
        // Layers - production stages
        layers: (
            <>
                <polygon points="12 2 2 7 12 12 22 7 12 2" />
                <polyline points="2 17 12 22 22 17" />
                <polyline points="2 12 12 17 22 12" />
            </>
        ),
        // Budget - calendar with coin
        budget: (
            <>
                <rect x="3" y="4" width="18" height="18" rx="2" />
                <line x1="16" y1="2" x2="16" y2="6" />
                <line x1="8" y1="2" x2="8" y2="6" />
                <line x1="3" y1="10" x2="21" y2="10" />
                <circle cx="12" cy="15" r="2" />
            </>
        ),
        // Transactions — two-way arrows (up/down)
        transactions: (
            <>
                <path d="M7 16V4m0 0L3 8m4-4l4 4" />
                <path d="M17 8v12m0 0l4-4m-4 4l-4-4" />
            </>
        ),
        // Intelligence - sparkle / lightbulb
        intelligence: (
            <>
                <path d="M9 18h6" />
                <path d="M10 22h4" />
                <path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0018 8 6 6 0 006 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 018.91 14" />
            </>
        ),
        // Database Management - stacked cylinder (DB) icon
        database: (
            <>
                <path d="M20 7c0 1.657-3.582 3-8 3S4 8.657 4 7m16 0c0-1.657-3.582-3-8-3S4 5.343 4 7m16 0v10c0 1.657-3.582 3-8 3s-8-1.343-8-3V7m16 5c0 1.657-3.582 3-8 3s-8-1.343-8-3" />
            </>
        ),
        // Trash / Recycle Bin
        trash: (
            <>
                <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </>
        ),
    };

    return (
        <svg
            className="w-[18px] h-[18px] shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            {paths[name] ?? <circle cx="12" cy="12" r="10" />}
        </svg>
    );
};

// ─── User footer with dropdown ───────────────────────────────────────────────

function UserFooter({ collapsed }: { collapsed: boolean }) {
    const [open, setOpen] = useState(false);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const navigate = useNavigate();
    const { user, logout } = useAuthStore();

    // Close on outside click
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener("mousedown", handler);
        return () => document.removeEventListener("mousedown", handler);
    }, []);

    const handleLogout = async () => {
        setIsLoggingOut(true);
        try {
            await logout();
        } catch {
            // Swallow — clearAuth always runs inside logout()
        }
        navigate("/login");
    };

    if (!user) return null;

    const fullName = user.first_name || user.last_name
        ? `${user.first_name ?? ""} ${user.last_name ?? ""}`.trim()
        : (user.name ?? "");
    const initials = `${user.first_name?.[0] ?? ""}${user.last_name?.[0] ?? ""}`.toUpperCase() || fullName[0]?.toUpperCase() || "?";
    const roleName = formatRoleName(
        user.roles?.[0]?.display_name ?? user.roles?.[0]?.name ?? user.user_type ?? ""
    );

    if (collapsed) {
        return (
            <div className="px-2 py-3 border-t border-white/10 shrink-0 flex justify-center">
                <button
                    onClick={() => navigate("/settings/profile")}
                    title={fullName}
                    className="w-8 h-8 rounded-full bg-brand-500/20 flex items-center justify-center hover:bg-brand-500/30 transition-colors"
                >
                    <span className="text-brand-300 text-xs font-semibold">{initials}</span>
                </button>
            </div>
        );
    }

    return (
        <div ref={ref} className="relative border-t border-white/10 shrink-0">
            {/* Dropdown menu - slides up above the footer */}
            {open && (
                <div className="absolute bottom-full left-2 right-2 mb-1 bg-surface-800 rounded-xl border border-white/10 shadow-xl overflow-hidden z-50">
                    <div className="px-4 py-3 border-b border-white/10">
                        <p className="text-xs font-semibold text-surface-100 truncate">{fullName}</p>
                        <p className="text-2xs text-surface-500 truncate mt-0.5">{user.email}</p>
                        {roleName && (
                            <span className="inline-block mt-1.5 px-2 py-0.5 rounded-full bg-brand-500/15 text-brand-400 text-2xs font-semibold">
                                {roleName}
                            </span>
                        )}
                    </div>
                    <ul className="py-1">
                        <li>
                            <button
                                onClick={() => { navigate("/settings/profile"); setOpen(false); }}
                                className="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-surface-300 hover:bg-white/5 hover:text-surface-100 transition-colors"
                            >
                                <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
                                </svg>
                                My Profile
                            </button>
                        </li>
                        <li className="border-t border-white/10 mt-1 pt-1">
                            <button
                                onClick={handleLogout}
                                disabled={isLoggingOut}
                                className="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-danger hover:bg-danger/10 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                {isLoggingOut ? (
                                    <div className="w-4 h-4 shrink-0 border-2 border-danger border-t-transparent rounded-full animate-spin" />
                                ) : (
                                    <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" />
                                    </svg>
                                )}
                                {isLoggingOut ? "Signing out…" : "Sign out"}
                            </button>
                        </li>
                    </ul>
                </div>
            )}

            {/* Footer trigger button */}
            <button
                onClick={() => setOpen((v) => !v)}
                className={clsx(
                    "w-full flex items-center gap-3 px-4 py-3 hover:bg-white/5 transition-colors group",
                    open && "bg-white/5"
                )}
            >
                <div className="w-8 h-8 rounded-full bg-brand-500/20 flex items-center justify-center shrink-0 group-hover:bg-brand-500/30 transition-colors">
                    <span className="text-brand-300 text-xs font-semibold">{initials}</span>
                </div>
                <div className="flex-1 min-w-0 text-left">
                    <p className="text-xs font-medium text-surface-200 truncate">{fullName}</p>
                    <p className="text-2xs text-surface-500 truncate">{user.email}</p>
                    {roleName && (
                        <p className="text-2xs text-brand-400/80 truncate mt-0.5">{roleName}</p>
                    )}
                </div>
                <svg
                    className={clsx("w-3.5 h-3.5 text-surface-500 shrink-0 transition-transform", open ? "rotate-180" : "")}
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
        </div>
    );
}

// ─── Sidebar ─────────────────────────────────────────────────────────────────

interface SidebarProps {
    collapsed: boolean;
}

export function Sidebar({ collapsed }: SidebarProps) {
    const { can, isSuperAdmin } = usePermissions();
    const { user } = useAuthStore();
    const location = useLocation();

    // ── Live badge counts ─────────────────────────────────────────────────────
    const { data: notifCountData } = useQuery({
        queryKey: ["notif-count"],
        queryFn: () => get<{ count: number }>("/v1/admin/notifications/unread-count"),
        refetchInterval: 30_000,
        staleTime: 30_000,
    });
    const notifCount = notifCountData?.count ?? 0;

    const { data: channelsData } = useQuery({
        queryKey: ["channels"],
        queryFn: () => get<{ channels: { unread_count?: number }[] }>("/v1/admin/channels"),
        refetchInterval: 30_000,
        staleTime: 30_000,
    });
    const messagesCount = (channelsData?.channels ?? []).reduce(
        (sum, c) => sum + (c.unread_count ?? 0), 0
    );

    // All groups collapsed by default; the active group auto-expands on mount
    const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>(
        Object.fromEntries(NAV.map((g) => [g.label, false]))
    );

    useEffect(() => {
        const activeGroup = NAV.find((g) =>
            g.items.some((item) =>
                location.pathname === item.href ||
                location.pathname.startsWith(item.href + "/")
            )
        );
        if (activeGroup) {
            setExpandedGroups((prev) => ({ ...prev, [activeGroup.label]: true }));
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const toggleGroup = (label: string) =>
        setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }));

    return (
        <aside
            className={clsx(
                "flex flex-col h-full bg-surface-900 text-surface-200 transition-all duration-300 ease-spring",
                collapsed ? "w-16" : "w-64",
            )}
        >
            {/* Logo */}
            <div
                className={clsx(
                    "h-[60px] flex items-center border-b border-white/10 shrink-0 transition-all duration-300",
                    collapsed ? "px-4 justify-center" : "px-5",
                )}
            >
                {collapsed ? (
                    /* Collapsed: show favicon icon only */
                    <img
                        src={`${import.meta.env.BASE_URL}images/favicon-light.png`}
                        alt="Bethany House"
                        className="w-8 h-8 object-contain"
                    />
                ) : (
                    /* Expanded: show full logo */
                    <img
                        src={`${import.meta.env.BASE_URL}images/logo-light.png`}
                        alt="Bethany House"
                        className="h-8 w-auto object-contain"
                    />
                )}
            </div>

            {/* Nav */}
            <nav className="flex-1 overflow-y-auto py-3 no-scrollbar">
                {NAV.map((group) => {
                    // Filter items by permission
                    const visibleItems = group.items.filter((item) => {
                        if (isSuperAdmin) return true;
                        if (item.anyOfPermissions?.length) {
                            return item.anyOfPermissions.some((p) => can(p));
                        }
                        return !item.permission || can(item.permission);
                    });
                    if (!visibleItems.length) return null;

                    const isExpanded = expandedGroups[group.label];

                    return (
                        <div key={group.label} className="mb-1">
                            {/* Group label */}
                            {!collapsed && (
                                <button
                                    onClick={() => toggleGroup(group.label)}
                                    className="w-full flex items-center justify-between px-4 py-1.5 text-xs font-semibold tracking-widest uppercase text-surface-500 hover:text-surface-400 transition-colors"
                                >
                                    {group.label}
                                    <svg
                                        className={clsx(
                                            "w-3 h-3 transition-transform",
                                            isExpanded ? "" : "-rotate-90",
                                        )}
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={2.5}
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M19 9l-7 7-7-7"
                                        />
                                    </svg>
                                </button>
                            )}

                            {/* Items */}
                            {(collapsed || isExpanded) && (
                                <ul className="mt-0.5">
                                    {visibleItems.map((item) => {
                                        const isActive =
                                            location.pathname === item.href ||
                                            location.pathname.startsWith(item.href + "/");

                                        return (
                                            <li key={item.href}>
                                                <Link
                                                    to={item.href}
                                                    title={
                                                        collapsed
                                                            ? item.label
                                                            : undefined
                                                    }
                                                    className={clsx(
                                                        "flex items-center gap-3 transition-colors duration-100 rounded-lg mx-2 my-0.5",
                                                        collapsed
                                                            ? "justify-center px-2 py-2.5"
                                                            : "px-3 py-2",
                                                        isActive
                                                            ? "bg-brand-500/20 text-brand-300"
                                                            : "text-surface-400 hover:bg-white/5 hover:text-surface-200",
                                                    )}
                                                >
                                                    <div className="relative shrink-0">
                                                        <Icon name={item.icon} />
                                                        {/* Collapsed-mode dot badge */}
                                                        {collapsed && (() => {
                                                            const liveCount =
                                                                item.icon === "notifications" ? notifCount :
                                                                item.icon === "messages" ? messagesCount : 0;
                                                            return liveCount > 0 ? (
                                                                <span className="absolute -top-1 -right-1 min-w-[14px] h-[14px] px-0.5 bg-danger text-white text-[9px] font-bold rounded-full flex items-center justify-center leading-none">
                                                                    {liveCount > 99 ? "99+" : liveCount}
                                                                </span>
                                                            ) : null;
                                                        })()}
                                                    </div>
                                                    {!collapsed && (
                                                        <span className="text-sm font-medium flex-1">
                                                            {item.label}
                                                        </span>
                                                    )}
                                                    {!collapsed && (() => {
                                                        const liveCount =
                                                            item.icon === "notifications" ? notifCount :
                                                            item.icon === "messages" ? messagesCount : 0;
                                                        const staticBadge = item.badge;
                                                        if (liveCount > 0) {
                                                            return (
                                                                <span className="ml-auto min-w-[20px] h-5 px-1.5 bg-danger text-white text-2xs font-bold rounded-full flex items-center justify-center leading-none">
                                                                    {liveCount > 99 ? "99+" : liveCount}
                                                                </span>
                                                            );
                                                        }
                                                        if (staticBadge) {
                                                            return (
                                                                <span className="ml-auto badge badge-warning text-2xs">
                                                                    {staticBadge}
                                                                </span>
                                                            );
                                                        }
                                                        return null;
                                                    })()}
                                                </Link>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                    );
                })}
            </nav>

            {/* User footer */}
            <UserFooter collapsed={collapsed} />
        </aside>
    );
}