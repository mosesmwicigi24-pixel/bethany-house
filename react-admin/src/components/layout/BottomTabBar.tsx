import { Link, useLocation } from 'react-router-dom'
import { clsx } from 'clsx'
import { usePermissions } from '@/hooks/usePermissions'

/*
 * Mobile app shell: a five-slot bottom tab bar, phone-only (md:hidden).
 *
 * The hub is installed to phones as a PWA, so on a handset it should behave
 * like an app rather than a desktop admin squeezed into 390px. The four
 * destinations below are the ones actually opened every day; everything else
 * stays reachable through "More", which opens the existing drawer rather than
 * duplicating the navigation tree — Sidebar's NAV array remains the single
 * source of truth for navigation and permissions.
 *
 * Tabs the user cannot access are dropped rather than shown-and-refused, and
 * the bar keeps its shape by filling the freed slots (a 3-tab bar looks
 * deliberate; a bar with gaps looks broken).
 *
 * Sits above the iPhone home indicator via env(safe-area-inset-bottom);
 * index.html already sets viewport-fit=cover, which that inset requires.
 *
 * Deliberately NOT position:fixed. It is a flex sibling of <main> inside the
 * content column, so the flex layout reserves its height and the shell's
 * h-screen-safe workaround keeps working — a fixed bar would occlude the last
 * row of every list, which is the exact bug the comment block at the top of
 * AdminLayout documents having been bitten by once already.
 */

interface Tab {
    label: string
    href: string
    /** Route prefixes that should light this tab up. */
    match: string[]
    permission?: string
    icon: JSX.Element
}

const TABS: Tab[] = [
    {
        label: 'Home',
        href: '/dashboard',
        match: ['/dashboard'],
        icon: (
            <>
                <path d="M3 10.5 12 3l9 7.5" />
                <path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5" />
            </>
        ),
    },
    {
        label: 'Messages',
        href: '/comms',
        match: ['/comms'],
        icon: (
            <>
                <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
            </>
        ),
    },
    {
        label: 'Production',
        href: '/production/orders',
        match: ['/production'],
        permission: 'production.view',
        icon: (
            <>
                <path d="M3 21h18" />
                <path d="M4 21V9l5 3.5V9l5 3.5V9l5 3.5V21" />
                <path d="M9 21v-3.5h3V21" />
            </>
        ),
    },
    {
        label: 'Orders',
        href: '/sales/online-orders',
        match: ['/sales'],
        permission: 'orders.view',
        icon: (
            <>
                <path d="M6 2 4 6v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4z" />
                <path d="M4 6h16" />
                <path d="M16 10a4 4 0 0 1-8 0" />
            </>
        ),
    },
]

const MORE_ICON = (
    <>
        <path d="M4 7h16M4 12h16M4 17h16" />
    </>
)

export function BottomTabBar({ onOpenMenu }: { onOpenMenu: () => void }) {
    const { pathname } = useLocation()
    const { can } = usePermissions()

    const visible = TABS.filter((t) => !t.permission || can(t.permission))

    return (
        <nav
            aria-label="Primary"
            className="md:hidden shrink-0 bg-white border-t border-line
                       pb-[env(safe-area-inset-bottom)] shadow-tabbar"
        >
            <ul className="flex items-stretch">
                {visible.map((tab) => {
                    const active = tab.match.some(
                        (m) => pathname === m || pathname.startsWith(`${m}/`),
                    )
                    return (
                        <li key={tab.href} className="flex-1">
                            <Link
                                to={tab.href}
                                aria-current={active ? 'page' : undefined}
                                className={clsx(
                                    // 56px of tappable height before the safe-area inset —
                                    // comfortably past the 44px minimum target.
                                    'flex flex-col items-center justify-center gap-1 h-14 px-1',
                                    'transition-colors',
                                    active ? 'text-brand-700' : 'text-surface-500',
                                )}
                            >
                                <svg
                                    className={clsx(
                                        'w-[22px] h-[22px] transition-colors',
                                        // brand-700 on a brand-50 pill = 5.20:1, and keeps the
                                        // #d98a2a family visibly present in the active state.
                                        active && 'rounded-full bg-brand-50 px-3 py-0.5 w-11 box-content',
                                    )}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={active ? 2.2 : 1.8}
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    {tab.icon}
                                </svg>
                                <span
                                    className={clsx(
                                        'text-[10px] leading-none tracking-tight',
                                        active ? 'font-bold' : 'font-medium',
                                    )}
                                >
                                    {tab.label}
                                </span>
                            </Link>
                        </li>
                    )
                })}

                {/* "More" opens the existing drawer — one navigation tree, not two. */}
                <li className="flex-1">
                    <button
                        type="button"
                        onClick={onOpenMenu}
                        aria-haspopup="menu"
                        className="w-full flex flex-col items-center justify-center gap-1 h-14 px-1
                                   text-surface-500 transition-colors"
                    >
                        <svg
                            className="w-[22px] h-[22px]"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.8}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            aria-hidden="true"
                        >
                            {MORE_ICON}
                        </svg>
                        <span className="text-[10px] leading-none tracking-tight font-medium">More</span>
                    </button>
                </li>
            </ul>
        </nav>
    )
}
