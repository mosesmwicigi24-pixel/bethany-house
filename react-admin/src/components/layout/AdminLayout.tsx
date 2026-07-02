import { useState, useEffect } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { PWAInstallBanner } from '@/components/pwa/PWAInstallBanner'
import { CommandPalette } from '@/components/ui/CommandPalette'

const COLLAPSE_KEY = 'bh_sidebar_collapsed'

// ─── Route label overrides ────────────────────────────────────────────────────

const SEGMENT_LABELS: Record<string, string> = {
    dashboard: "Dashboard",
    catalogue: "Catalogue",
    products: "Products",
    categories: "Categories",
    sales: "Sales",
    orders: "Orders",
    shipments: "Shipments",
    customers: "Customers",
    production: "Production",
    wip: "Work In Progress",
    bom: "Bill of Materials",
    qc: "Quality Control",
    "my-tasks": "My Tasks",
    inventory: "Inventory",
    "stock-levels": "Stock Levels",
    adjustments: "Adjustments",
    transfers: "Transfers",
    materials: "Raw Materials",
    "low-stock": "Low Stock Alerts",
    procurement: "Procurement",
    "purchase-orders": "Purchase Orders",
    suppliers: "Suppliers",
    "goods-receipt": "Goods Receipt",
    returns: "Returns",
    approvals: "Approvals",
    expenses: "Expenses",
    analytics: "Analytics",
    finance: "Finance",
    reports: "Reports",
    notifications: "Notifications",
    comms: "Messages",
    settings: "Settings",
    business: "Business Settings",
    countries: "Countries",
    currencies: "Currencies",
    languages: "Languages",
    taxes: "Tax Rates",
    outlets: "Outlets",
    "payment-methods": "Payment Methods",
    roles: "Roles",
    users: "Users",
    "activity-logs": "Activity Logs",
    profile: "Profile",
    shipping: "Shipping",
    "auto-assignees": "Auto-Assignees",
    stages: "Production Stages",
    pos: "Point of Sale",
    new: "New",
}

function buildBreadcrumbs(pathname: string) {
    const segments = pathname.replace(/^\//, '').split('/')
    const crumbs: { label: string; href?: string }[] = []
    let path = ''

    for (const seg of segments) {
        path += `/${seg}`
        if (/^\d+$/.test(seg)) {
            crumbs.push({ label: '#' + seg })
            continue
        }
        const label =
            SEGMENT_LABELS[seg.toLowerCase()] ??
            seg
                .split('-')
                .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
                .join(' ')
        crumbs.push({ label, href: path })
    }

    if (crumbs.length > 0) delete crumbs[crumbs.length - 1].href

    return crumbs
}

// ─── Layout ───────────────────────────────────────────────────────────────────

export function AdminLayout() {
    const location = useLocation()

    // Desktop: collapsed state (persisted)
    const [collapsed, setCollapsed] = useState<boolean>(() => {
        try {
            return localStorage.getItem(COLLAPSE_KEY) === 'true'
        } catch {
            return false
        }
    })

    // Mobile: drawer open state (never persisted)
    const [mobileOpen, setMobileOpen] = useState(false)

    // Persist desktop collapse preference
    useEffect(() => {
        localStorage.setItem(COLLAPSE_KEY, String(collapsed))
    }, [collapsed])

    // Close mobile drawer on route change
    useEffect(() => {
        setMobileOpen(false)
    }, [location.pathname])

    // Prevent body scroll when mobile drawer is open
    useEffect(() => {
        document.body.style.overflow = mobileOpen ? 'hidden' : ''
        return () => { document.body.style.overflow = '' }
    }, [mobileOpen])

    // ── PWA: Fix iOS Safari 100vh bug ─────────────────────────────────────────
    // Sets --vh CSS custom property to the true inner window height.
    // Use h-screen-safe (calc(var(--vh, 1vh) * 100)) instead of h-screen
    // on any full-height container that breaks on iOS.
    // visualViewport fires when the software keyboard opens/closes, keeping
    // the layout correct when the POS search or message composer is focused.
    useEffect(() => {
        const setVh = () => {
            document.documentElement.style.setProperty(
                '--vh',
                `${window.innerHeight * 0.01}px`
            )
        }
        setVh()
        window.addEventListener('resize', setVh)
        window.visualViewport?.addEventListener('resize', setVh)
        return () => {
            window.removeEventListener('resize', setVh)
            window.visualViewport?.removeEventListener('resize', setVh)
        }
    }, [])

    const breadcrumbs = buildBreadcrumbs(location.pathname)

    return (
        <div className="flex h-screen overflow-hidden bg-surface-50">

            {/* ── PWA banners (install prompt, offline bar, update alert) ── */}
            <PWAInstallBanner />

            {/* ── Command palette — global search/nav (⌘K / Ctrl+K) ── */}
            <CommandPalette />

            {/* ── Mobile overlay backdrop ───────────────────────────── */}
            {mobileOpen && (
                <div
                    className="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 md:hidden"
                    onClick={() => setMobileOpen(false)}
                    aria-hidden="true"
                />
            )}

            {/*
             * ── Sidebar wrapper ───────────────────────────────────────
             *
             * Mobile  (<md): fixed off-canvas drawer; slides in when
             *   mobileOpen=true. Always full-width (w-64), not collapsible.
             *
             * Desktop (≥md): normal flex column in the layout row.
             *   h-full so the <aside h-full> inside resolves correctly.
             *   Width is owned by the <aside> itself (w-16 | w-64).
             */}
            <div
                className={[
                    'fixed inset-y-0 left-0 z-40 h-full',
                    'transition-transform duration-300 ease-in-out',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                    'md:hidden',
                ].join(' ')}
            >
                <Sidebar collapsed={false} />
            </div>

            {/* Desktop sidebar - in normal document flow */}
            <div className="hidden md:flex md:flex-col md:h-full md:shrink-0">
                <Sidebar collapsed={collapsed} />
            </div>

            {/* ── Main column ───────────────────────────────────────── */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
                <Topbar
                    collapsed={collapsed}
                    onToggleCollapse={() => setCollapsed((v) => !v)}
                    onMobileMenuToggle={() => setMobileOpen((v) => !v)}
                    breadcrumbs={breadcrumbs}
                />

                {/* Page content */}
                <main className="flex-1 overflow-y-auto p-4 md:p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    )
}