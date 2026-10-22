import { Component, useState, useEffect, useRef, type ReactNode } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { BottomTabBar } from './BottomTabBar'
import { PWAInstallBanner } from '@/components/pwa/PWAInstallBanner'
import { CommandPalette } from '@/components/ui/CommandPalette'
import { useAuthStore } from '@/store/auth.store'
import { subscribeToUserChannel, getEcho } from '@/lib/echo'
import { primeAudio, messageAlert } from '@/lib/alertSound'
import { DownloadApprovalDialog } from '@/components/downloads/DownloadApprovalDialog'
import { useViewportFrame } from '@/lib/useViewportFrame'

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
    imprest: "Imprest",
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

const REPORT_PAGE_LABELS: Record<string, string> = {
    sales: 'Sales & Orders',
    customers: 'Customers & Neema',
    finance: 'Finance & Cash',
    production: 'Production & Fulfilment',
    inventory: 'Inventory',
    procurement: 'Procurement & Suppliers',
    signals: 'Signals',
    performance: 'Staff, Outlets & Performance',
    explorer: 'Business Explorer',
    'data-quality': 'Audit & Data Quality',
}

// A record id under these paths gets a word, not "#6": on a phone the last
// crumb IS the top-bar title, and a database id names nothing for the floor.
// (The page itself carries the order number.)
const ID_LABEL_BY_PARENT: Record<string, string> = {
    '/production/orders': 'Production Order',
}

function buildBreadcrumbs(pathname: string) {
    const segments = pathname.replace(/^\//, '').split('/')
    const crumbs: { label: string; href?: string }[] = []
    let path = ''

    for (const seg of segments) {
        const parent = path
        path += `/${seg}`
        if (/^\d+$/.test(seg)) {
            crumbs.push({ label: ID_LABEL_BY_PARENT[parent] ?? '#' + seg })
            continue
        }
        // Report pages are named for the question they answer (reports
        // consolidation, 2026-10-01) — "Sales & Orders", not the module word.
        const reportLabel = path.startsWith('/reports/') && path.split('/').length === 3
            ? REPORT_PAGE_LABELS[seg.toLowerCase()]
            : undefined
        const label =
            reportLabel ??
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
    const { user } = useAuthStore()
    const qc = useQueryClient()

    // ── Live message alerts ────────────────────────────────────────────────
    // The backend broadcasts notification.pushed on user.{id} for every
    // notification, chat messages included. While the app is open we ring
    // and vibrate here (the device's own mechanisms, not a silent badge) and
    // refresh the bell/sidebar counts without waiting for the 30s poll.
    // When the app is closed, the service worker's Web Push handler takes
    // over — the OS banner carries its own sound and vibration.
    useEffect(() => {
        primeAudio()
        if (!user?.id) return
        subscribeToUserChannel(user.id, data => {
            qc.invalidateQueries({ queryKey: ['notif-count'] })
            const d = (data as { data?: Record<string, unknown> }).data
            if (d && 'channel_message_id' in d) {
                messageAlert()
                qc.invalidateQueries({ queryKey: ['channels'] })
            }
        })
        return () => { getEcho().leave(`user.${user.id}`) }
    }, [user?.id])

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

    // ── The shell follows the keyboard, frame by frame ─────────────────────
    // Sets --vh / --vv-h / --kb-h from the visual viewport (the area actually
    // visible RIGHT NOW, which shrinks when the software keyboard opens while
    // window.innerHeight on iOS does not), pins the window at scroll 0, and
    // undoes the iOS keyboard pan the same frame (never a transform: WebKit
    // paints the caret at the untransformed spot, see the hook's docblock).
    // This used to be three timed re-measures (0 / 150 / 400 ms) and a window
    // scroll fight, which is exactly the slow slide, the header under the
    // clock and the dead band under the composer in the owner's recording of
    // 22 Oct 2026. See src/lib/useViewportFrame.ts for the full account.
    //
    // Trade-off kept: visualViewport.height also shrinks on pinch-zoom, so a
    // pinch resizes the shell. Typing is far more common here than pinching.
    const shellRef = useRef<HTMLDivElement>(null)
    useViewportFrame(shellRef)

    const breadcrumbs = buildBreadcrumbs(location.pathname)

    // Messages is an APP surface, not a document: it must fill the window and
    // scroll inside its own panes, with the composer pinned at the bottom.
    //
    // #218 moved the page gutter off <main> onto the height-less wrapper below.
    // Every other page is a document and did not care; Messages' `h-full` began
    // resolving against that wrapper's AUTO height, so the page grew as tall as
    // its whole history and <main> scrolled instead of the thread. It stayed
    // hidden because the thread used scrollIntoView, which scrolls every
    // ancestor — until #360 rightly stopped that (it panned the iOS layout off
    // screen), and the newest message was left sitting below the fold.
    const fillsWindow = /^\/comms(\/|$)/.test(location.pathname)

    return (
        // h-screen-safe, not h-screen: the --vh machinery below has always been
        // wired up, but the shell never consumed it. On iOS `100vh` is the LARGE
        // viewport — it measures as though the Safari toolbars were hidden — so
        // the shell ran ~110px taller than the visible area. Being overflow-hidden,
        // that bottom strip could not be scrolled to: the last row of every page
        // sat under the toolbar, untappable. That is the "unresponsive cards".
        <div ref={shellRef} className="flex h-screen-safe overflow-hidden bg-surface-canvas">

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

                {/* Page content.
                    The bottom tab bar is fixed, so on phones the scroll area
                    reserves its height (56px) plus the home-indicator inset —
                    otherwise the last row of every list sits underneath it. */}
                <main className="flex-1 overflow-y-auto">
                    {/* The shell FILLS the window. It briefly capped content at
                        1200px to match the Mukuru reference, and that was a
                        mistake: 1200px is a READING measure, correct for a
                        marketing page of prose and wrong for a console whose main
                        job is nine-column tables and a kanban board. On a 2560px
                        display it stranded ~1300px of empty canvas either side
                        while the orders table clipped columns inside it.

                        Width is not owned globally any more, because the right
                        measure depends on what a page contains:
                          • data pages (lists, tables, WIP board) take the window
                          • the 17 form and detail pages that need a reading
                            measure already set their own max-w-3xl..7xl, and are
                            unaffected by this — they were never relying on the
                            shell to constrain them.
                        Gutters still scale with the viewport so content never
                        touches the bezel on a large monitor, and phones and
                        tablets are untouched: the cap never bound below 1200px,
                        so nothing about their layout changes. */}
                    <div className={`w-full px-3 py-3 md:px-8 md:py-8 2xl:px-10${fillsWindow ? " h-full" : ""}`}>
                        {/* key: a crash on one page must not follow you to the next */}
                        <PageErrorBoundary key={location.pathname}>
                            <Outlet />
                            {/* Opens wherever a download is held for approval */}
                            <DownloadApprovalDialog />
                        </PageErrorBoundary>
                    </div>
                </main>

                {/* Phone-only app shell. A flex sibling of <main>, so the column
                    reserves its height instead of the bar floating over the last
                    row of every list. */}
                <BottomTabBar onOpenMenu={() => setMobileOpen(true)} />
            </div>
        </div>
    )
}