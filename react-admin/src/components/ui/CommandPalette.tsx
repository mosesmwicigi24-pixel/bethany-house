/**
 * CommandPalette.tsx
 *
 * Global search & navigation command palette, triggered by:
 *   - Keyboard: ⌘K (Mac) or Ctrl+K (Windows/Linux)
 *   - Click: the search button in the Topbar
 *
 * Features:
 *   1. Navigation shortcuts - type a section name to jump directly
 *   2. Recent pages - shows last 5 visited pages
 *   3. Live search - searches products, orders, customers simultaneously
 *      via the API (debounced 300ms)
 *   4. Keyboard navigation - arrow keys, Enter, Escape
 *
 * Integration:
 *   1. Add <CommandPalette /> to AdminLayout (once, outside Sidebar/main)
 *   2. Add a search trigger button to Topbar that calls openCommandPalette()
 *   3. The keyboard shortcut is registered globally by the component itself.
 *
 * API endpoint expected:
 *   GET /v1/admin/search?q={query}&types[]=products&types[]=orders&types[]=customers
 *   Returns: { results: SearchResult[] }
 */

import { useState, useEffect, useRef, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { get } from "@/api/client";

// ─── Types ────────────────────────────────────────────────────────────────────

export type SearchResultType =
    | "product" | "order" | "customer" | "supplier"
    | "purchase_order" | "production_order" | "nav";

export interface SearchResult {
    id: string | number;
    type: SearchResultType;
    title: string;
    subtitle?: string;
    href: string;
    meta?: string;
}

interface NavShortcut {
    label: string;
    href: string;
    icon: string;
    keywords: string[];
}

// ─── Navigation shortcuts ─────────────────────────────────────────────────────
// These are always available and filterable by label/keyword

const NAV_SHORTCUTS: NavShortcut[] = [
    { label: "Dashboard",          href: "/dashboard",                      icon: "dashboard",  keywords: ["home", "overview"] },
    { label: "POS",                href: "/pos",                            icon: "pos",        keywords: ["point of sale", "register", "cashier"] },
    { label: "Products",           href: "/catalogue/products",             icon: "products",   keywords: ["catalog", "catalogue", "items"] },
    { label: "Orders",             href: "/sales/orders",                   icon: "orders",     keywords: ["sales", "customer orders"] },
    { label: "Customers",          href: "/sales/customers",                icon: "customers",  keywords: ["clients"] },
    { label: "Shipments",          href: "/sales/shipments",                icon: "shipments",  keywords: ["delivery", "dispatch", "tracking"] },
    { label: "Stock Levels",       href: "/inventory/stock-levels",         icon: "stock",      keywords: ["inventory", "warehouse"] },
    { label: "Low Stock Alerts",   href: "/inventory/low-stock",            icon: "alerts",     keywords: ["reorder", "out of stock"] },
    { label: "Purchase Orders",    href: "/procurement/purchase-orders",    icon: "purchase",   keywords: ["po", "procurement", "buying"] },
    { label: "Suppliers",          href: "/procurement/suppliers",          icon: "suppliers",  keywords: ["vendors"] },
    { label: "Production Orders",  href: "/production/orders",              icon: "production", keywords: ["manufacturing", "making"] },
    { label: "Work In Progress",   href: "/production/wip",                 icon: "wip",        keywords: ["wip", "in progress"] },
    { label: "Expenses",           href: "/expenses",                       icon: "expenses",   keywords: ["costs", "finance", "spending"] },
    { label: "Reports",            href: "/reports",                        icon: "reports",    keywords: ["analytics", "statistics"] },
    { label: "Approvals",          href: "/approvals",                      icon: "approvals",  keywords: ["pending", "review"] },
    { label: "Notifications",      href: "/notifications",                  icon: "notif",      keywords: ["alerts", "inbox"] },
    { label: "Users",              href: "/settings/users",                 icon: "users",      keywords: ["staff", "accounts", "team"] },
    { label: "Settings",           href: "/settings/business",              icon: "settings",   keywords: ["configuration", "setup"] },
];

// ─── Recent pages (localStorage) ─────────────────────────────────────────────

const RECENT_KEY = "bh_cmd_recent";
const MAX_RECENT = 6;

function getRecent(): string[] {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) ?? "[]"); }
    catch { return []; }
}

export function trackRecentPage(href: string) {
    const recent = getRecent().filter((h) => h !== href);
    recent.unshift(href);
    localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, MAX_RECENT)));
}

// ─── Global state ─────────────────────────────────────────────────────────────

let _open = false;
let _setOpen: ((v: boolean) => void) | null = null;

export function openCommandPalette() {
    _setOpen?.(true);
}

// ─── Icons ────────────────────────────────────────────────────────────────────

function TypeIcon({ type }: { type: SearchResultType | string }) {
    const cls = "w-4 h-4 shrink-0";
    const s = { fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    switch (type) {
        case "product":
        case "products":
            return <svg className={cls} {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>;
        case "order":
        case "orders":
            return <svg className={cls} {...s}><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>;
        case "customer":
        case "customers":
            return <svg className={cls} {...s}><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>;
        case "supplier":
        case "suppliers":
            return <svg className={cls} {...s}><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/></svg>;
        case "purchase_order":
        case "purchase":
            return <svg className={cls} {...s}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>;
        case "production_order":
        case "production":
        case "wip":
            return <svg className={cls} {...s}><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M17.5 14v3m0 3v.01M14 17.5h3m3 0h.01"/></svg>;
        case "dashboard":
            return <svg className={cls} {...s}><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>;
        case "pos":
            return <svg className={cls} {...s}><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>;
        case "settings":
            return <svg className={cls} {...s}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>;
        default: // nav, generic
            return <svg className={cls} {...s}><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;
    }
}

// ─── Group header ─────────────────────────────────────────────────────────────

function GroupHeader({ label }: { label: string }) {
    return (
        <div className="px-4 py-1.5 text-2xs font-semibold text-surface-400 uppercase tracking-wider bg-surface-50 border-b border-surface-100 sticky top-0">
            {label}
        </div>
    );
}

// ─── Result item ─────────────────────────────────────────────────────────────

function ResultItem({
    result,
    isActive,
    onSelect,
}: {
    result: SearchResult | NavShortcut;
    isActive: boolean;
    onSelect: () => void;
}) {
    const isNav = "keywords" in result;
    return (
        <button
            onClick={onSelect}
            className={clsx(
                "w-full text-left flex items-center gap-3 px-4 py-2.5 transition-colors",
                isActive ? "bg-brand-50" : "hover:bg-surface-50",
            )}
        >
            <div className={clsx(
                "w-7 h-7 rounded-lg flex items-center justify-center shrink-0",
                isActive ? "bg-brand-100 text-brand-600" : "bg-surface-100 text-surface-500",
            )}>
                <TypeIcon type={isNav ? (result as NavShortcut).icon : (result as SearchResult).type} />
            </div>
            <div className="flex-1 min-w-0">
                <p className={clsx("text-sm font-medium truncate", isActive ? "text-brand-700" : "text-surface-800")}>
                    {isNav ? (result as NavShortcut).label : (result as SearchResult).title}
                </p>
                {"subtitle" in result && result.subtitle && (
                    <p className="text-xs text-surface-400 truncate mt-0.5">{result.subtitle}</p>
                )}
            </div>
            {"meta" in result && result.meta && (
                <span className="text-xs text-surface-400 shrink-0">{result.meta}</span>
            )}
            {isNav && (
                <svg className="w-3.5 h-3.5 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
            )}
        </button>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export function CommandPalette() {
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState("");
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    // Register global setter so openCommandPalette() works
    useEffect(() => {
        _setOpen = setOpen;
        return () => { _setOpen = null; };
    }, []);

    // ⌘K / Ctrl+K shortcut
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === "k") {
                e.preventDefault();
                setOpen((v) => !v);
            }
            if (e.key === "Escape") setOpen(false);
        };
        document.addEventListener("keydown", handler);
        return () => document.removeEventListener("keydown", handler);
    }, []);

    // Focus input when opened
    useEffect(() => {
        if (open) {
            setQuery("");
            setResults([]);
            setActiveIndex(0);
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }, [open]);

    // Debounced live search
    useEffect(() => {
        if (!query.trim() || query.length < 2) {
            setResults([]);
            return;
        }
        const t = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await get<{ results: SearchResult[] }>(
                    "/v1/admin/search",
                    { params: { q: query, "types[]": ["products", "orders", "customers", "suppliers", "purchase_orders"] } }
                );
                setResults(res.results ?? []);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 300);
        return () => clearTimeout(t);
    }, [query]);

    // Filtered nav shortcuts
    const filteredNav = query.trim()
        ? NAV_SHORTCUTS.filter((n) => {
            const q = query.toLowerCase();
            return n.label.toLowerCase().includes(q) || n.keywords.some((k) => k.includes(q));
          }).slice(0, 5)
        : [];

    // Recent pages (when no query)
    const recentHrefs = getRecent();
    const recentNav = !query.trim()
        ? NAV_SHORTCUTS.filter((n) => recentHrefs.includes(n.href))
              .sort((a, b) => recentHrefs.indexOf(a.href) - recentHrefs.indexOf(b.href))
              .slice(0, 5)
        : [];

    // All items in flat list for keyboard nav
    type AnyItem = SearchResult | NavShortcut;
    const allItems: { item: AnyItem; group: string }[] = [];

    if (!query.trim()) {
        // Show recents + popular nav
        recentNav.forEach((n) => allItems.push({ item: n, group: "recent" }));
        NAV_SHORTCUTS.slice(0, 6).forEach((n) => {
            if (!recentHrefs.includes(n.href)) allItems.push({ item: n, group: "jump" });
        });
    } else {
        filteredNav.forEach((n) => allItems.push({ item: n, group: "nav" }));
        results.forEach((r) => allItems.push({ item: r, group: "results" }));
    }

    // Keyboard navigation
    const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
        if (e.key === "ArrowDown") {
            e.preventDefault();
            setActiveIndex((i) => Math.min(i + 1, allItems.length - 1));
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setActiveIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === "Enter" && allItems[activeIndex]) {
            const item = allItems[activeIndex].item;
            const href = "href" in item ? item.href : "";
            if (href) { navigate(href); trackRecentPage(href); setOpen(false); }
        }
    }, [allItems, activeIndex, navigate]);

    const selectItem = (item: AnyItem) => {
        const href = "href" in item ? item.href : "";
        if (href) { navigate(href); trackRecentPage(href); setOpen(false); }
    };

    if (!open) return null;

    const hasContent = allItems.length > 0;
    const showEmptySearch = query.length >= 2 && !loading && results.length === 0 && filteredNav.length === 0;

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center pt-[15vh] px-4">
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-black/40 backdrop-blur-sm"
                onClick={() => setOpen(false)}
            />

            {/* Palette */}
            <div
                className="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden border border-surface-100 animate-scale-in"
                onKeyDown={handleKeyDown}
            >
                {/* Search input */}
                <div className="flex items-center gap-3 px-4 h-14 border-b border-surface-100">
                    {loading ? (
                        <svg className="w-4 h-4 text-surface-400 shrink-0 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth={4}/>
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                    ) : (
                        <svg className="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    )}
                    <input
                        ref={inputRef}
                        type="text"
                        value={query}
                        onChange={(e) => { setQuery(e.target.value); setActiveIndex(0); }}
                        placeholder="Search or jump to…"
                        className="flex-1 bg-transparent text-sm text-surface-900 placeholder:text-surface-400 outline-none focus:ring-0 focus:outline-none"
                    />
                    <button
                        onClick={() => setOpen(false)}
                        className="shrink-0 w-7 h-7 flex items-center justify-center rounded-lg text-surface-400 hover:text-surface-600 hover:bg-surface-100 transition-colors"
                        aria-label="Close"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {/* Results */}
                <div ref={listRef} className="max-h-[60vh] overflow-y-auto">
                    {!query.trim() && (
                        <>
                            {recentNav.length > 0 && (
                                <>
                                    <GroupHeader label="Recent" />
                                    {recentNav.map((n, i) => (
                                        <ResultItem key={n.href} result={n} isActive={i === activeIndex} onSelect={() => selectItem(n)} />
                                    ))}
                                </>
                            )}
                            <GroupHeader label="Jump to" />
                            {NAV_SHORTCUTS.filter((n) => !recentHrefs.includes(n.href)).slice(0, 6).map((n, i) => {
                                const idx = recentNav.length + i;
                                return <ResultItem key={n.href} result={n} isActive={idx === activeIndex} onSelect={() => selectItem(n)} />;
                            })}
                        </>
                    )}

                    {query.trim() && filteredNav.length > 0 && (
                        <>
                            <GroupHeader label="Navigation" />
                            {filteredNav.map((n, i) => (
                                <ResultItem key={n.href} result={n} isActive={i === activeIndex} onSelect={() => selectItem(n)} />
                            ))}
                        </>
                    )}

                    {query.trim() && results.length > 0 && (
                        <>
                            <GroupHeader label="Search results" />
                            {results.map((r, i) => {
                                const idx = filteredNav.length + i;
                                return <ResultItem key={`${r.type}-${r.id}`} result={r} isActive={idx === activeIndex} onSelect={() => selectItem(r)} />;
                            })}
                        </>
                    )}

                    {showEmptySearch && (
                        <div className="py-12 text-center">
                            <p className="text-sm text-surface-500">No results for <strong className="text-surface-700">"{query}"</strong></p>
                            <p className="text-xs text-surface-400 mt-1">Try a different search term</p>
                        </div>
                    )}

                    {!hasContent && !query.trim() && (
                        <div className="py-8 text-center text-sm text-surface-400">
                            Start typing to search…
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="border-t border-surface-100 px-4 py-2 flex items-center gap-4 text-2xs text-surface-400">
                    <span className="flex items-center gap-1">
                        <kbd className="px-1 py-0.5 rounded border border-surface-200 font-mono">↑↓</kbd> navigate
                    </span>
                    <span className="flex items-center gap-1">
                        <kbd className="px-1 py-0.5 rounded border border-surface-200 font-mono">↵</kbd> open
                    </span>
                    <span className="flex items-center gap-1">
                        <kbd className="px-1 py-0.5 rounded border border-surface-200 font-mono">esc</kbd> close
                    </span>
                </div>
            </div>
        </div>
    );
}

// ─── Topbar search button (add to Topbar right side) ─────────────────────────

export function CommandPaletteButton() {
    return (
        <button
            onClick={openCommandPalette}
            className="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg border border-surface-200 bg-surface-50 hover:bg-surface-100 transition-colors text-sm text-surface-500"
            aria-label="Open command palette"
        >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <span className="text-xs pe-12">Search…</span>
            <div className="hidden lg:flex items-center gap-0.5">
                <kbd className="px-1 py-0.5 rounded border border-surface-200 text-2xs font-mono bg-white">⌘</kbd>
                <kbd className="px-1 py-0.5 rounded border border-surface-200 text-2xs font-mono bg-white">K</kbd>
            </div>
        </button>
    );
}