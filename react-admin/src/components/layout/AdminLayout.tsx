import { Component, useState, useEffect, type ReactNode } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { PWAInstallBanner } from '@/components/pwa/PWAInstallBanner'
import { CommandPalette } from '@/components/ui/CommandPalette'

const COLLAPSE_KEY = 'bh_sidebar_collapsed'

// ─── Page error boundary ──────────────────────────────────────────────────────
// A render crash in any page used to unmount the ENTIRE tree — sidebar included —
// leaving a fully white screen with every network request green (the PO detail
// page did exactly this via a hooks-order bug). The boundary contains the blast
// to the page area, says what broke, and offers a reload. Keyed by pathname in
// the layout below so navigating away resets it.

class PageErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
    state = { error: null as Error | null }

    static getDerivedStateFromError(error: Error) {
        return { error }
    }

    render() {
        if (this.state.error) {
            return (
                <div className="max-w-lg mx-auto mt-16 bg-white border border-surface-200 rounded-2xl shadow-sm p-6 text-center">
                    <div className="w-12 h-12 mx-auto rounded-2xl bg-danger/10 text-danger flex items-center justify-center mb-3">
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <p className="text-sm font-bold text-surface-900">This page hit an error</p>
                    <p className="text-xs text-surface-500 mt-1 break-words">{this.state.error.message}</p>
                    <button onClick={() => window.location.reload()}
                        className="mt-4 px-4 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 transition-colors">
                        Reload page
                    </button>
                </div>
            )
        }
        return this.props.children
    }
}

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
            // visualViewport.height is the area actually visible RIGHT NOW — it
            // shrinks when the software keyboard opens. window.innerHeight does
            // not: on iOS it stays at full height with the keyboard up. This
            // effect always listened to visualViewport (below) but then read
            // innerHeight, so the keyboard listener wrote back an identical
            // value and did nothing. The shell stayed ~750px tall while only
            // ~420px was visible, so Safari scrolled the document to reveal the
            // focused field — which is why the message composer ended up
            // stranded at the top of the screen with dead space beneath it.
            //
            // Trade-off: visualViewport.height also shrinks on pinch-zoom, so a
            // pinch resizes the shell. That is the accepted cost of correct
            // keyboard behaviour in an app-shell layout, and typing is far more
            // common here than pinching.
            const h = window.visualViewport?.height ?? window.innerHeight
            document.documentElement.style.setProperty('--vh', `${h * 0.01}px`)
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
        // h-screen-safe, not h-screen: the --vh machinery below has always been
        // wired up, but the shell never consumed it. On iOS `100vh` is the LARGE
        // viewport — it measures as though the Safari toolbars were hidden — so
        // the shell ran ~110px taller than the visible area. Being overflow-hidden,
        // that bottom strip could not be scrolled to: the last row of every page
        // sat under the toolbar, untappable. That is the "unresponsive cards".
        <div className="flex h-screen-safe overflow-hidden bg-surface-50">

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
                    {/* key: a crash on one page must not follow you to the next */}
                    <PageErrorBoundary key={location.pathname}>
                        <Outlet />
                    </PageErrorBoundary>
                </main>
            </div>
        </div>
    )
}