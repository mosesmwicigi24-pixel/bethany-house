/**
 * PosPage.tsx - Redesigned
 *
 * Key improvements over the original:
 *
 * 1. OUTLET SELECTION  - rich visual cards at the gate and in-session, showing
 *    outlet name, city, address, and a live "Open / Closed" status badge.
 *    No more naked <select> dropdowns.
 *
 * 2. CUSTOMER SEARCH & ATTACH  - the customer panel is a proper search
 *    typeahead. Type a name, phone, or email and matching customers from the
 *    system appear as a dropdown. Selecting a known customer attaches their
 *    full profile (id, name, phone, email) to the order so history is linked.
 *    "Walk-in" remains the frictionless default.
 *
 * 3. PRODUCTION ORDER FLOW  - when a product has zero stock but has production
 *    capability (variants flagged `is_made_to_order` or `allow_backorder`), an
 *    "Order to Make" chip appears instead of "Out of Stock". Adding such an
 *    item marks it as a production line item in the cart (purple badge). At
 *    checkout the sale payload includes `production_items: [...]`; the backend
 *    (PosController / ProductionController) creates draft production orders for
 *    each one, linked to the sale order. A post-sale "Production Orders Raised"
 *    banner shows the created order numbers.
 *
 *    For in-stock ready-made items the flow is unchanged.
 */

import { useState, useEffect, useRef, useCallback, useMemo } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { posApi } from "@/api/pos";
import { get } from "@/api/client";
import { paymentMethodsApi } from "@/api/setup";
import type {
    CartItem,
    PosProduct,
    PosVariant,
    PosOutlet,
    PosMeasurementField,
    PosSale,
    PosShippingMethod,
} from "@/api/pos";
import type { SplitPayment as ModalSplitPayment, ConfiguredMethod } from "./components/PaymentModal";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import RegisterModal from "./components/RegisterModal";
import PaymentModal from "./components/PaymentModal";
import ReceiptModal from "./components/ReceiptModal";
import SalesHistoryDrawer from "./components/SalesHistoryDrawer";
import UserEodModal from "./components/UserEodModal";
import PosReturnsModal from "./components/PosReturnsModal";

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

interface CustomerHit {
    id: number;
    name: string;
    phone?: string | null;
    email?: string | null;
}

interface AttachedCustomer {
    id: number;          // 0 = walk-in not yet saved, -1 = new to create
    first_name: string;
    last_name: string;
    phone?: string | null;
    email?: string | null;
    /** Present when id === -1 - full form data to create a new customer record */
    new_customer_data?: {
        first_name: string;
        last_name: string;
        phone: string;
        email?: string;
    };
}

// Cart item extended with production flag and order-specific notes
interface ExtCartItem extends CartItem {
    is_production?: boolean;   // true → raise draft production order at checkout
    product_id?: number;
    /** Human-readable tax category name(s) for display on receipt e.g. "VAT", "VAT + Tourism Levy" */
    tax_name?: string | null;
    /** Preconfigured measurement fields from the product */
    measurement_fields?: PosMeasurementField[];
    /** Filled-in measurement values keyed by field name */
    measurement_values?: Record<string, string>;
    /** Freetext notes for when no measurement fields are configured */
    production_notes?: string;
    /** Catalogue price before any manual override — stored for audit/reporting */
    original_price?: number;
    /** True when the cashier manually overrode the unit price above catalogue */
    price_adjusted?: boolean;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cart math
// ─────────────────────────────────────────────────────────────────────────────

function calcItemSubtotal(item: ExtCartItem): number {
    const base = item.price * item.quantity;
    if (item.discount_type === "flat")
        return Math.max(0, base - item.discount_value);
    if (item.discount_type === "percent")
        return Math.max(0, base * (1 - item.discount_value / 100));
    return base;
}

function calcLineTax(item: ExtCartItem, taxInclusive: boolean): number {
    const lineSubtotal = calcItemSubtotal(item);
    const rate = item.tax_rate ?? 0;
    if (rate === 0) return 0;
    if (taxInclusive) {
        // Extract tax that is already embedded in the price
        return Math.round((lineSubtotal - lineSubtotal / (1 + rate / 100)) * 100) / 100;
    }
    // Add tax on top
    return Math.round(lineSubtotal * rate / 100 * 100) / 100;
}

function calcTotals(
    items: ExtCartItem[],
    discType: "none" | "flat" | "percent",
    discVal: number,
    shippingAmount: number,
    taxInclusive: boolean = false,
) {
    const subtotal = items.reduce((s, i) => s + calcItemSubtotal(i), 0);
    const discountAmount =
        discType === "flat"
            ? Math.min(discVal, subtotal)
            : discType === "percent"
              ? (subtotal * discVal) / 100
              : 0;
    const afterDisc = subtotal - discountAmount;

    // Apply cart-level discount proportionally across lines for tax calculation.
    // Each line's share = its subtotal / total subtotal.
    const discountRatio = subtotal > 0 ? discountAmount / subtotal : 0;
    const taxAmount = Math.round(
        items.reduce((sum, item) => {
            const lineSub = calcItemSubtotal(item) * (1 - discountRatio);
            const rate = item.tax_rate ?? 0;
            if (rate === 0) return sum;
            const lineTax = taxInclusive
                ? lineSub - lineSub / (1 + rate / 100)
                : lineSub * rate / 100;
            return sum + lineTax;
        }, 0) * 100
    ) / 100;

    const total = taxInclusive
        ? afterDisc + shippingAmount
        : afterDisc + taxAmount + shippingAmount;

    return {
        subtotal,
        discountAmount,
        taxAmount,
        taxInclusive,
        shippingAmount,
        total,
    };
}

const fmt = (n: number) =>
    n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

// ─────────────────────────────────────────────────────────────────────────────
// Cart draft persistence
//
// The active cart session is serialised to sessionStorage so the cashier can
// navigate away (e.g. check stock levels, look up a customer) and return to
// exactly the same cart state.  sessionStorage is intentionally used instead
// of localStorage — it is scoped to the browser tab and is automatically
// wiped when the tab closes, which is the right behaviour for a shared POS
// terminal.  The draft is cleared explicitly when clearCart() runs or a sale
// completes.
// ─────────────────────────────────────────────────────────────────────────────

const CART_DRAFT_KEY = "pos_cart_draft";

// Key for storing the ID of a pending order that was intentionally saved-and-cleared
// by the cashier. The auto-resume effect must NOT silently re-attach this order —
// the cashier already knows it exists and can restore it manually via the history drawer.
//
// IMPORTANT: this stores a SET of dismissed IDs, not a single value. A cashier
// can save multiple orders in one session (#100, then #101, then #102…) and
// EVERY one of them must stay dismissed - not just the most recently saved one.
// A single-value store would let an earlier dismissed order "come back" the
// moment a second order is saved and overwrites it.
const DISMISSED_PENDING_KEY = "pos_dismissed_pending_ids";

function getDismissedPendingOrderIds(): Set<number> {
    try {
        const raw = sessionStorage.getItem(DISMISSED_PENDING_KEY);
        if (!raw) return new Set();
        const arr = JSON.parse(raw);
        return new Set(Array.isArray(arr) ? arr.map(Number) : []);
    } catch { return new Set(); }
}

function isPendingOrderDismissed(id: number): boolean {
    return getDismissedPendingOrderIds().has(id);
}

function addDismissedPendingOrderId(id: number): void {
    try {
        const ids = getDismissedPendingOrderIds();
        ids.add(id);
        sessionStorage.setItem(DISMISSED_PENDING_KEY, JSON.stringify([...ids]));
    } catch { /* ignore */ }
}

function removeDismissedPendingOrderId(id: number): void {
    try {
        const ids = getDismissedPendingOrderIds();
        ids.delete(id);
        sessionStorage.setItem(DISMISSED_PENDING_KEY, JSON.stringify([...ids]));
    } catch { /* ignore */ }
}

interface CartDraft {
    outletId:             number | null;
    cart:                 ExtCartItem[];
    cartDiscType:         "none" | "flat" | "percent";
    cartDiscVal:          number;
    attachedCustomer:     AttachedCustomer | null;
    customerCountryCode:  string;
    shippingAmount:       number;
    selectedShippingId:   number | null;
    shippingAddress:      string;
    pendingOrderId:       number | null;
    pendingOrderData:     any | null;
    pendingOrderCartSig:  string;
    isResumedOrder:       boolean;
    savedAt:              number; // epoch ms — used to discard stale drafts
}

/** Maximum age (ms) after which a draft is silently discarded — 8 hours */
const CART_DRAFT_MAX_AGE_MS = 8 * 60 * 60 * 1000;

function loadCartDraft(): CartDraft | null {
    try {
        const raw = sessionStorage.getItem(CART_DRAFT_KEY);
        if (!raw) return null;
        const draft: CartDraft = JSON.parse(raw);
        if (Date.now() - (draft.savedAt ?? 0) > CART_DRAFT_MAX_AGE_MS) {
            sessionStorage.removeItem(CART_DRAFT_KEY);
            return null;
        }
        return draft;
    } catch {
        return null;
    }
}

function saveCartDraft(draft: Omit<CartDraft, "savedAt">): void {
    try {
        sessionStorage.setItem(
            CART_DRAFT_KEY,
            JSON.stringify({ ...draft, savedAt: Date.now() }),
        );
    } catch {
        // sessionStorage full or unavailable — silently ignore
    }
}

function clearCartDraft(): void {
    try {
        sessionStorage.removeItem(CART_DRAFT_KEY);
    } catch {
        // ignore
    }
}

function cartSignature(items: ExtCartItem[]): string {
    return items
        .map(i => `${i.variant_id}:${i.quantity}:${i.price}:${i.discount_type}:${i.discount_value}`)
        .join("|");
}

function parseMeasurementsFromNotes(notes: string): Record<string, string> {
    const measPart = notes.includes(" | ") ? notes.split(" | ")[0] : notes;
    if (!measPart.trim()) return {};
    const parsed: Record<string, string> = {};
    measPart.split(", ").forEach(segment => {
        const colonIdx = segment.indexOf(": ");
        if (colonIdx === -1) return;
        const key = segment.slice(0, colonIdx).trim();
        const val = segment.slice(colonIdx + 2).trim();
        if (key) parsed[key] = val;
    });
    return parsed;
}

// ─────────────────────────────────────────────────────────────────────────────
// OutletCard
// ─────────────────────────────────────────────────────────────────────────────

function OutletCard({
    outlet,
    selected,
    onSelect,
}: {
    outlet: PosOutlet;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            onClick={onSelect}
            className={clsx(
                "relative text-left rounded-2xl border-2 p-4 transition-all duration-150 w-full",
                "focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-2",
                selected
                    ? "border-brand-500 bg-brand-50 shadow-md shadow-brand-100"
                    : "border-surface-200 bg-white hover:border-brand-300 hover:shadow-sm",
            )}
        >
            {/* Selected check */}
            {selected && (
                <span className="absolute top-3 right-3 w-5 h-5 rounded-full bg-brand-500 flex items-center justify-center shadow">
                    <svg
                        className="w-3 h-3 text-white"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={3}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M4.5 12.75l6 6 9-13.5"
                        />
                    </svg>
                </span>
            )}

            {/* Store icon */}
            <div
                className={clsx(
                    "w-10 h-10 rounded-xl flex items-center justify-center mb-3",
                    selected
                        ? "bg-brand-500 text-white"
                        : "bg-surface-100 text-surface-500",
                )}
            >
                <svg
                    className="w-5 h-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={1.8}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"
                    />
                </svg>
            </div>

            <p
                className={clsx(
                    "font-bold text-sm",
                    selected ? "text-brand-700" : "text-surface-900",
                )}
            >
                {outlet.name}
            </p>
            {outlet.city && (
                <p className="text-2xs text-surface-400 mt-0.5 truncate">
                    {outlet.city}
                </p>
            )}
            {outlet.address && (
                <p className="text-2xs text-surface-400 truncate">
                    {outlet.address}
                </p>
            )}
        </button>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// CountryPickerPanel - compact inline picker for customer's country
// Shown in the cart panel; drives currency for international POS orders.
// ─────────────────────────────────────────────────────────────────────────────

function CountryPickerPanel({
    value,
    onChange,
    countries,
    activeCurrencies,
    homeCountry,
}: {
    value: string;
    onChange: (code: string) => void;
    countries: Array<{ code: string; name: string; flag: string | null; default_currency_code: string | null }>;
    activeCurrencies: string[];
    homeCountry: string;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState("");
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener("mousedown", h);
        return () => document.removeEventListener("mousedown", h);
    }, []);

    const filtered = search.trim()
        ? countries.filter(c =>
            c.name.toLowerCase().includes(search.toLowerCase()) ||
            c.code.toLowerCase().includes(search.toLowerCase())
          )
        : countries;

    const selected = countries.find(c => c.code === value);
    const isInternational = value !== "" && value !== homeCountry;
    const previewCurrency = selected?.default_currency_code ?? null;

    if (!open && !value) {
        return (
            <button
                onClick={() => setOpen(true)}
                className="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg border border-dashed border-surface-200 text-2xs text-surface-400 hover:border-brand-300 hover:text-brand-600 transition-colors"
            >
                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918"/>
                </svg>
                International customer? Set country
            </button>
        );
    }

    return (
        <div ref={ref}>
            {/* Compact chip when a country is selected and picker is closed */}
            {!open && value && (
                <div className={clsx(
                    "flex items-center gap-2 px-3 py-1.5 rounded-lg border text-2xs",
                    isInternational
                        ? "border-blue-200 bg-blue-50 text-blue-800"
                        : "border-surface-200 bg-surface-50 text-surface-600"
                )}>
                    {selected?.flag && <span className="text-sm shrink-0">{selected.flag}</span>}
                    <span className="flex-1 font-semibold truncate">{selected?.name ?? value}</span>
                    {isInternational && (
                        <span className="shrink-0 text-2xs font-bold text-blue-600 flex items-center gap-0.5">
                            🌐 {previewCurrency}
                        </span>
                    )}
                    <button
                        onClick={() => setOpen(true)}
                        className="text-surface-400 hover:text-brand-600 shrink-0 ml-1"
                        title="Change country"
                    >
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/>
                        </svg>
                    </button>
                    <button
                        onClick={() => { onChange(""); setSearch(""); }}
                        className="text-surface-300 hover:text-danger shrink-0"
                        aria-label="Close"
                        title="Clear country"
                    >
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            )}

            {/* Expanded picker */}
            {open && (
                <div className="border border-brand-200 rounded-xl bg-white shadow-md overflow-hidden">
                    <div className="flex items-center gap-2 px-3 py-2 border-b border-surface-100">
                        <svg className="w-3.5 h-3.5 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                        </svg>
                        <input
                            autoFocus
                            type="search"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder="Search country…"
                            className="flex-1 text-xs outline-none bg-transparent placeholder-surface-300"
                        />
                        <button onClick={() => { setOpen(false); setSearch(""); }} className="text-surface-300 hover:text-surface-600 shrink-0"
aria-label="Close">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {/* Clear option */}
                    {value && (
                        <button
                            onClick={() => { onChange(""); setSearch(""); setOpen(false); }}
                            className="w-full flex items-center gap-2 px-3 py-1.5 text-2xs text-surface-400 hover:bg-surface-50 border-b border-surface-100 transition-colors"
                        >
                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            Local / home country (clear)
                        </button>
                    )}

                    <div className="max-h-44 overflow-y-auto">
                        {filtered.length === 0 ? (
                            <p className="text-2xs text-surface-400 italic px-3 py-3 text-center">No results.</p>
                        ) : filtered.map(c => {
                            const isHome = c.code === homeCountry;
                            const currOk = !c.default_currency_code ||
                                activeCurrencies.length === 0 ||
                                activeCurrencies.includes(c.default_currency_code);
                            return (
                                <button
                                    key={c.code}
                                    onClick={() => { onChange(c.code); setSearch(""); setOpen(false); }}
                                    className={clsx(
                                        "w-full flex items-center gap-2 px-3 py-1.5 text-left text-2xs transition-colors",
                                        value === c.code
                                            ? "bg-brand-50 text-brand-700 font-semibold"
                                            : "text-surface-700 hover:bg-surface-50"
                                    )}
                                >
                                    <span className="text-sm shrink-0 w-5 text-center">{c.flag ?? "🏳"}</span>
                                    <span className="flex-1 truncate">{c.name}</span>
                                    {isHome && <span className="text-2xs text-surface-400 shrink-0">Home</span>}
                                    {c.default_currency_code && (
                                        <span className={clsx("text-2xs font-semibold shrink-0", currOk ? "text-surface-500" : "text-warning-dark")}>
                                            {c.default_currency_code}
                                            {!currOk && " ⚠"}
                                        </span>
                                    )}
                                    {value === c.code && (
                                        <svg className="w-3 h-3 text-brand-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    {activeCurrencies.length > 0 && (
                        <p className="px-3 py-1.5 text-2xs text-surface-300 border-t border-surface-100">
                            Active currencies: {activeCurrencies.join(", ")}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// CustomerSearchPanel - typeahead to attach a known customer to the order
// ─────────────────────────────────────────────────────────────────────────────

function CustomerSearchPanel({
    attached,
    onAttach,
    onClear,
}: {
    attached: AttachedCustomer | null;
    onAttach: (c: AttachedCustomer) => void;
    onClear: () => void;
}) {
    const [q, setQ] = useState("");
    const [open, setOpen] = useState(false);
    const [manualMode, setManualMode] = useState(false);
    const [manualName, setManualName] = useState("");
    const [manualPhone, setManualPhone] = useState("");
    const [manualEmail, setManualEmail] = useState("");
    const containerRef = useRef<HTMLDivElement>(null);
    const searchTimer = useRef<ReturnType<typeof setTimeout>>();

    const { data, isFetching } = useQuery({
        queryKey: ["pos-customer-search", q],
        queryFn: () => posApi.searchCustomers(q),
        enabled: q.trim().length >= 2,
        staleTime: 10_000,
    });

    const hits: CustomerHit[] = data?.data ?? [];

    // Debounce search
    const handleInput = (val: string) => {
        setQ(val);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => {
            if (val.trim().length >= 2) setOpen(true);
        }, 250);
    };

    // Close dropdown on outside click
    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (
                containerRef.current &&
                !containerRef.current.contains(e.target as Node)
            )
                setOpen(false);
        };
        document.addEventListener("mousedown", h);
        return () => document.removeEventListener("mousedown", h);
    }, []);

    // If a customer is already attached, show a compact chip
    if (attached) {
        return (
            <div className="flex items-center gap-2 px-3 py-2 bg-brand-50 border border-brand-200 rounded-xl">
                <div className="w-7 h-7 rounded-full bg-brand-500 text-white flex items-center justify-center text-xs font-bold shrink-0">
                    {attached.first_name[0]}{attached.last_name[0]}
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5">
                        <p className="text-xs font-semibold text-brand-800 truncate">
                            {attached.first_name} {attached.last_name}
                        </p>
                        {attached.id === -1 && (
                            <span className="shrink-0 text-2xs bg-brand-100 text-brand-700 font-bold px-1.5 py-0.5 rounded-md">New</span>
                        )}
                    </div>
                    <p className="text-2xs text-brand-500 truncate">
                        {attached.phone ?? attached.email ?? "Walk-in"}
                    </p>
                </div>
                <button onClick={onClear} className="text-brand-400 hover:text-danger transition-colors shrink-0"
aria-label="Close" title="Remove customer">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        );
    }

    if (manualMode) {
        const nameParts = manualName.trim().split(" ");
        const firstName = nameParts[0] ?? "";
        const lastName  = nameParts.slice(1).join(" ");
        const canSave   = firstName.trim().length > 0 && manualPhone.trim().length > 0;
        return (
            <div className="space-y-2 px-3 py-2.5 border border-brand-200 rounded-xl bg-brand-50/60">
                <div className="flex items-center justify-between">
                    <p className="text-2xs font-bold text-brand-700 uppercase tracking-wide flex items-center gap-1">
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        New Customer
                    </p>
                    <button onClick={() => { setManualMode(false); setManualName(""); setManualPhone(""); setManualEmail(""); }}
                        className="text-2xs text-surface-400 hover:text-danger">Cancel</button>
                </div>
                <div className="grid grid-cols-2 gap-1.5">
                    <input type="text" placeholder="First name *"
                        value={firstName}
                        onChange={e => setManualName(`${e.target.value} ${lastName}`.trim())}
                        className="input text-xs py-1.5" autoFocus />
                    <input type="text" placeholder="Last name"
                        value={lastName}
                        onChange={e => setManualName(`${firstName} ${e.target.value}`.trim())}
                        className="input text-xs py-1.5" />
                </div>
                <input type="tel" placeholder="Phone number *"
                    value={manualPhone} onChange={e => setManualPhone(e.target.value)}
                    className="input text-xs py-1.5 w-full" />
                <input type="email" placeholder="Email address (optional)"
                    value={manualEmail} onChange={e => setManualEmail(e.target.value)}
                    className="input text-xs py-1.5 w-full" />
                <button
                    onClick={() => {
                        onAttach({
                            id: -1,  // -1 = create new customer record at checkout
                            first_name: firstName,
                            last_name:  lastName || "",
                            phone: manualPhone || null,
                            email: manualEmail || null,
                            new_customer_data: {
                                first_name: firstName,
                                last_name:  lastName || "",
                                phone: manualPhone,
                                email: manualEmail || undefined,
                            },
                        });
                        setManualMode(false);
                    }}
                    disabled={!canSave}
                    className="w-full btn-primary btn-sm text-xs disabled:opacity-40"
                >
                    Add to Order
                </button>
                <p className="text-2xs text-brand-500/70">Customer record will be created on checkout.</p>
            </div>
        );
    }

    return (
        <div ref={containerRef} className="relative">
            <div className="relative">
                <div className="absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 pointer-events-none">
                    {isFetching ? (
                        <div className="w-3.5 h-3.5 border-2 border-brand-400 border-t-transparent rounded-full animate-spin" />
                    ) : (
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                            />
                        </svg>
                    )}
                </div>
                <input
                    type="search"
                    placeholder="Search customer by name, phone, email…"
                    value={q}
                    onChange={(e) => handleInput(e.target.value)}
                    onFocus={() => q.trim().length >= 2 && setOpen(true)}
                    className="input text-xs py-2 pl-9 pr-20 w-full"
                />
                <div className="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                    {q && (
                        <button
                            onClick={() => {
                                setQ("");
                                setOpen(false);
                            }}
                            className="text-surface-300 hover:text-surface-600"
                            aria-label="Close"
                        >
                            <svg
                                className="w-3.5 h-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    )}
                    <button
                        onClick={() => setManualMode(true)}
                        className="text-2xs text-surface-400 hover:text-brand-600 font-medium whitespace-nowrap px-1 flex items-center gap-0.5"
                        title="Add new customer"
                    >
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        New
                    </button>
                </div>
            </div>

            {/* Results dropdown */}
            {open && (
                <div className="absolute left-0 right-0 top-full mt-1 z-50 bg-white border border-surface-200 rounded-xl shadow-xl overflow-hidden">
                    {hits.length === 0 &&
                    !isFetching &&
                    q.trim().length >= 2 ? (
                        <div className="px-4 py-3 text-xs text-surface-500 flex items-center justify-between">
                            <span>No customer found for "{q}"</span>
                            <button
                                onClick={() => {
                                    setOpen(false);
                                    setManualMode(true);
                                    setManualName(q);
                                }}
                                className="text-brand-600 hover:underline font-medium"
                            >
                                + Create new →
                            </button>
                        </div>
                    ) : (
                        hits.slice(0, 6).map((hit) => (
                            <button
                                key={hit.id}
                                onClick={() => {
                                    const parts = hit.name.trim().split(" ");
                                    onAttach({
                                        id: hit.id,
                                        first_name: parts[0] ?? hit.name,
                                        last_name:
                                            parts.slice(1).join(" ") || "",
                                        phone: hit.phone,
                                        email: hit.email,
                                    });
                                    setQ("");
                                    setOpen(false);
                                }}
                                className="w-full text-left px-4 py-2.5 hover:bg-brand-50 transition-colors flex items-center gap-3 border-b border-surface-50 last:border-0"
                            >
                                <div className="w-7 h-7 rounded-full bg-surface-100 text-surface-600 flex items-center justify-center text-xs font-bold shrink-0">
                                    {hit.name[0]?.toUpperCase()}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-semibold text-surface-900 truncate">
                                        {hit.name}
                                    </p>
                                    <p className="text-2xs text-surface-400 truncate">
                                        {hit.phone ?? hit.email ?? ""}
                                    </p>
                                </div>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// VariantPicker (unchanged logic, slightly tightened)
// ─────────────────────────────────────────────────────────────────────────────

function VariantPicker({
    product,
    onSelect,
    onClose,
    currency = "KES",
}: {
    product: PosProduct;
    onSelect: (v: PosVariant) => void;
    onClose: () => void;
    currency?: string;
}) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4"
            onClick={onClose}
        >
            <div
                className="bg-white w-full sm:max-w-sm sm:rounded-2xl rounded-t-2xl shadow-2xl overflow-hidden animate-slide-up"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex justify-center pt-3 pb-1 sm:hidden">
                    <div className="w-10 h-1 rounded-full bg-surface-200" />
                </div>
                <div className="px-5 pt-3 pb-2 border-b border-surface-100 flex items-center justify-between">
                    <div>
                        <p className="font-semibold text-surface-900 text-sm">
                            {product.name}
                        </p>
                        <p className="text-2xs text-surface-400 mt-0.5">
                            Select a variant
                        </p>
                    </div>
                    <button
                        onClick={onClose}
                        className="w-7 h-7 rounded-full flex items-center justify-center bg-surface-100 text-surface-500 hover:bg-surface-200 transition-colors"
                        aria-label="Close"
                    >
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2.5}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>
                <div className="p-3 grid grid-cols-1 gap-2 max-h-72 overflow-y-auto">
                    {product.variants.map((v) => {
                        const oos = v.stock === 0;
                        const mto =
                            (v as any).is_made_to_order ||
                            (v as any).allow_backorder;
                        return (
                            <button
                                key={v.id}
                                onClick={() => {
                                    onSelect(v);
                                    onClose();
                                }}
                                className={clsx(
                                    "flex items-center justify-between p-3 rounded-xl border text-left transition-all active:scale-[0.98]",
                                    oos && !mto
                                        ? "border-surface-100 bg-surface-50 opacity-50 cursor-not-allowed"
                                        : mto && oos
                                          ? "border-purple-200 bg-purple-50 cursor-pointer hover:border-purple-400"
                                          : "border-surface-200 hover:border-brand-400 hover:bg-brand-50 cursor-pointer",
                                )}
                                disabled={oos && !mto}
                            >
                                <div>
                                    <p className="text-sm font-medium text-surface-900">
                                        {v.variant_name}
                                    </p>
                                    {Object.keys(v.attributes).length > 0 && (
                                        <p className="text-2xs text-surface-400 mt-0.5">
                                            {Object.entries(v.attributes)
                                                .map(
                                                    ([k, val]) =>
                                                        `${k}: ${val}`,
                                                )
                                                .join(" · ")}
                                        </p>
                                    )}
                                </div>
                                <div className="text-right shrink-0 ml-3">
                                    <p className="text-sm font-bold text-brand-600">
                                        {currency} {fmt(v.price)}
                                    </p>
                                    <p
                                        className={clsx(
                                            "text-2xs mt-0.5",
                                            oos && mto
                                                ? "text-purple-600"
                                                : oos
                                                  ? "text-danger"
                                                  : v.stock <= 5
                                                    ? "text-warning"
                                                    : "text-surface-400",
                                        )}
                                    >
                                        {oos && mto
                                            ? "Made to order"
                                            : oos
                                              ? "Out of stock"
                                              : v.stock <= 5
                                                ? `Only ${v.stock} left`
                                                : `${v.stock} in stock`}
                                    </p>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ProductCard - production-aware
// ─────────────────────────────────────────────────────────────────────────────

function ProductCard({
    product,
    onAdd,
    currency = "KES",
}: {
    product: PosProduct;
    onAdd: (p: PosProduct, v: PosVariant) => void;
    currency?: string;
}) {
    const defaultVariant =
        product.variants.find((v) => v.is_default) ?? product.variants[0];
    if (!defaultVariant) return null;

    const oos = defaultVariant.stock === 0;
    const mto =
        (defaultVariant as any).is_made_to_order ||
        (defaultVariant as any).allow_backorder;
    const lowStock = !oos && defaultVariant.stock <= 5;
    const blocked = oos && !mto;

    return (
        <button
            onClick={() => { if (!blocked) { navigator.vibrate?.(30); onAdd(product, defaultVariant); } }}
            disabled={blocked}
            className={clsx(
                "group relative flex flex-col rounded-2xl overflow-hidden text-left transition-all duration-150 border bg-white active:scale-[0.97] active:brightness-[0.97]",
                blocked
                    ? "border-surface-100 opacity-60 cursor-not-allowed"
                    : mto && oos
                      ? "border-purple-200 cursor-pointer hover:border-purple-400 hover:shadow-lg active:scale-[0.97]"
                      : "border-surface-200 cursor-pointer hover:border-brand-400 hover:shadow-lg active:scale-[0.97]",
            )}
        >
            {/* Image */}
            <div className="relative aspect-square bg-gradient-to-br from-surface-50 to-surface-100 overflow-hidden">
                {product.image_url ? (
                    <img
                        src={product.image_url}
                        alt={product.name}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center opacity-30">
                        <svg
                            className="w-10 h-10 text-surface-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                            />
                        </svg>
                    </div>
                )}

                {/* Status overlays */}
                {blocked && (
                    <div className="absolute inset-0 bg-white/70 flex items-center justify-center">
                        <span className="bg-surface-800 text-white text-2xs font-bold px-2 py-1 rounded-full uppercase tracking-wider">
                            Out of Stock
                        </span>
                    </div>
                )}
                {oos && mto && (
                    <div className="absolute inset-0 bg-purple-900/10 flex items-end justify-center pb-2">
                        <span className="bg-purple-600 text-white text-2xs font-bold px-2 py-1 rounded-full tracking-wide">
                            Made to Order
                        </span>
                    </div>
                )}
                {lowStock && (
                    <div className="absolute top-2 right-2 bg-warning text-white text-2xs font-bold px-2 py-0.5 rounded-full shadow-sm">
                        {defaultVariant.stock} left
                    </div>
                )}
                {product.variants.length > 1 && !blocked && (
                    <div className="absolute bottom-2 left-2 bg-black/60 text-white text-2xs px-2 py-0.5 rounded-full backdrop-blur-sm">
                        {product.variants.length} variants
                    </div>
                )}
                {!blocked && (
                    <div
                        className={clsx(
                            "absolute top-2 left-2 w-6 h-6 rounded-full text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all scale-75 group-hover:scale-100 shadow-md",
                            mto && oos ? "bg-purple-500" : "bg-brand-500",
                        )}
                    >
                        <svg
                            className="w-3 h-3"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={3}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M12 4.5v15m7.5-7.5h-15"
                            />
                        </svg>
                    </div>
                )}
            </div>

            {/* Info */}
            <div className="p-2.5">
                <p className="text-xs font-semibold text-surface-900 line-clamp-2 leading-snug">
                    {product.name}
                </p>
                <p
                    className={clsx(
                        "mt-1.5 text-sm font-bold",
                        mto && oos ? "text-purple-600" : "text-brand-600",
                    )}
                >
                    {currency} {fmt(defaultVariant.price)}
                </p>
            </div>
        </button>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// CartRow - production badge
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
// MeasurementSheet - slide-up modal for MTO measurements (keeps cart rows compact)
// ─────────────────────────────────────────────────────────────────────────────

function MeasurementSheet({
    item,
    index,
    onProduction,
    onMeasurement,
    onClose,
}: {
    item: ExtCartItem;
    index: number;
    onProduction: (i: number, val: boolean, notes?: string) => void;
    onMeasurement: (i: number, field: string, value: string) => void;
    onClose: () => void;
}) {
    const hasMeasurementFields = (item.measurement_fields?.length ?? 0) > 0;
    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden">
                {/* Sheet header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-surface-100">
                    <div>
                        <h3 className="font-bold text-surface-900 text-sm">{item.product_name}</h3>
                        <p className="text-2xs text-purple-600 font-medium mt-0.5">Made-to-Order details</p>
                    </div>
                    <button onClick={onClose} className="w-8 h-8 rounded-full bg-surface-100 flex items-center justify-center text-surface-500 hover:bg-surface-200 transition-colors"
aria-label="Close">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div className="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    {hasMeasurementFields ? (
                        <>
                            <p className="text-xs text-surface-500">Fill in the customer measurements for this item.</p>
                            <div className="grid grid-cols-2 gap-3">
                                {item.measurement_fields!.map((field) => (
                                    <div key={field.name}>
                                        <label className="block text-xs font-medium text-surface-700 mb-1">
                                            {field.name}
                                            {field.unit && <span className="text-surface-400 font-normal ml-1">({field.unit})</span>}
                                            {field.required && <span className="text-danger ml-0.5">*</span>}
                                        </label>
                                        <input
                                            type="text"
                                            className="input text-sm"
                                            placeholder={field.unit ? `e.g. 32 ${field.unit}` : "Enter value"}
                                            value={item.measurement_values?.[field.name] ?? ""}
                                            onChange={(e) => onMeasurement(index, field.name, e.target.value)}
                                        />
                                    </div>
                                ))}
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-surface-700 mb-1">Additional notes</label>
                                <textarea
                                    rows={2}
                                    className="input text-sm resize-none"
                                    placeholder="Colour, fabric preference, special instructions…"
                                    value={item.production_notes ?? ""}
                                    onChange={e => onProduction(index, true, e.target.value)}
                                />
                            </div>
                        </>
                    ) : (
                        <div>
                            <label className="block text-xs font-medium text-surface-700 mb-1">Production notes</label>
                            <textarea
                                rows={4}
                                className="input text-sm resize-none"
                                placeholder="Measurements, colours, preferences… e.g. waist 32, length 28, navy blue"
                                value={item.production_notes ?? ""}
                                onChange={e => onProduction(index, true, e.target.value)}
                                autoFocus
                            />
                            <p className="text-2xs text-surface-400 mt-1.5">These notes will be attached to the production order raised after checkout.</p>
                        </div>
                    )}
                    <button onClick={onClose} className="btn-primary w-full">Done</button>
                </div>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// CartRow - compact, single-height rows that never expand inline
// ─────────────────────────────────────────────────────────────────────────────

function CartRow({
    item,
    index,
    onQty,
    onRemove,
    onDiscount,
    onProduction,
    onMeasurement,
    onOpenMeasurements,
    onPriceOverride,
    currency = "KES",
}: {
    item: ExtCartItem;
    index: number;
    onQty: (i: number, q: number) => void;
    onRemove: (i: number) => void;
    onDiscount: (i: number, t: "none" | "flat" | "percent", v: number) => void;
    onProduction: (i: number, val: boolean, notes?: string) => void;
    onMeasurement: (i: number, field: string, value: string) => void;
    onOpenMeasurements: (i: number) => void;
    onPriceOverride: (i: number, price: number) => void;
    currency?: string;
}) {
    const [showDisc, setShowDisc] = useState(false);
    const [showPriceEdit, setShowPriceEdit] = useState(false);
    const [priceInput, setPriceInput] = useState<string>("");
    const sub = calcItemSubtotal(item);
    const hasMto = item.is_production;
    const cataloguePrice = item.original_price ?? item.price;

    // ── Swipe-to-delete ───────────────────────────────────────────────────────
    const cardRef     = useRef<HTMLDivElement>(null);
    const swipeStartX = useRef(0);
    const dragging    = useRef(false);
    const [swiped, setSwiped] = useState(false);

    const onSwipePointerDown = (e: React.PointerEvent) => {
        // Swipe-to-delete is touch/pen only — never fire on mouse (desktop PC)
        if (e.pointerType === "mouse") return;
        // Don't initiate swipe from interactive elements — their own pointer
        // events must reach them uninterrupted (select, input, button).
        if ((e.target as HTMLElement).closest("select, input, button, textarea")) return;
        swipeStartX.current = e.clientX;
        dragging.current    = false;
        cardRef.current?.setPointerCapture(e.pointerId);
    };
    const onSwipePointerMove = (e: React.PointerEvent) => {
        if (e.pointerType === "mouse") return;
        const delta = e.clientX - swipeStartX.current;
        if (Math.abs(delta) > 6) dragging.current = true;
        if (!dragging.current || !cardRef.current) return;
        const clamped = Math.max(-72, Math.min(0, delta));
        cardRef.current.style.transform  = `translateX(${clamped}px)`;
        cardRef.current.style.transition = "none";
        if (clamped < -50 && !swiped)  { setSwiped(true);  navigator.vibrate?.(30); }
        if (clamped > -50 && swiped)   { setSwiped(false); }
    };
    const onSwipePointerUp = (e: React.PointerEvent) => {
        if (e.pointerType === "mouse") return;
        if (!cardRef.current) return;
        const delta = e.clientX - swipeStartX.current;
        if (delta < -60 && dragging.current) {
            cardRef.current.style.transition = "transform 200ms ease, opacity 200ms ease";
            cardRef.current.style.transform  = "translateX(-100%)";
            cardRef.current.style.opacity    = "0";
            setTimeout(() => onRemove(index), 200);
            navigator.vibrate?.([30, 20, 80]);
        } else {
            cardRef.current.style.transition = "transform 250ms cubic-bezier(0.32,0.72,0,1)";
            cardRef.current.style.transform  = "translateX(0)";
            setSwiped(false);
        }
        dragging.current = false;
    };
    const hasMeasurementFields = (item.measurement_fields?.length ?? 0) > 0;
    const measurementsFilled = hasMto && hasMeasurementFields &&
        item.measurement_fields!.filter(f => f.required).every(f => item.measurement_values?.[f.name]?.trim());
    const hasNotes = hasMto && item.production_notes?.trim();

    return (
        <div className="relative overflow-hidden border-b border-surface-50 last:border-0">
            {/* Delete hint - visible when swiped (pointer-events-none so it never blocks clicks) */}
            <div
                className="absolute inset-y-0 right-0 w-16 flex items-center justify-center bg-danger transition-opacity duration-150 pointer-events-none"
                style={{ opacity: swiped ? 1 : 0 }}
            >
                <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </div>
            <div
                ref={cardRef}
                onPointerDown={onSwipePointerDown}
                onPointerMove={onSwipePointerMove}
                onPointerUp={onSwipePointerUp}
                className={clsx(
                    "px-3 py-2 bg-white transition-colors",
                    hasMto ? "bg-purple-50/40" : "",
                )}
                style={{ touchAction: "pan-y" } as React.CSSProperties}
            >
            {/* Row 1: name + qty controls + subtotal */}
            <div className="flex items-center gap-2">
                {/* Qty stepper */}
                <div className="flex items-center border border-surface-200 rounded-lg overflow-hidden bg-white shrink-0">
                    <button
                        onClick={() => item.quantity > 1 && onQty(index, item.quantity - 1)}
                        className="w-8 h-8 sm:w-6 sm:h-6 flex items-center justify-center text-surface-400 hover:bg-surface-100 active:bg-surface-200 transition-colors"
                    >
                        <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 12h-15"/></svg>
                    </button>
                    <span className="w-7 text-center text-xs font-bold text-surface-900 select-none tabular-nums">{item.quantity}</span>
                    <button
                        onClick={() => onQty(index, item.quantity + 1)}
                        className="w-8 h-8 sm:w-6 sm:h-6 flex items-center justify-center text-surface-400 hover:bg-surface-100 active:bg-surface-200 transition-colors"
                        aria-label="Add"
                    >
                        <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    </button>
                </div>

                {/* Product name */}
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1">
                        <p className="text-xs font-semibold text-surface-900 truncate leading-tight">{item.product_name}</p>
                        {hasMto && (
                            <span className="shrink-0 text-2xs font-bold text-purple-600 bg-purple-100 px-1 py-0.5 rounded leading-none">MTO</span>
                        )}
                    </div>
                    <div className="flex items-center gap-1 min-w-0">
                        <p className="text-2xs text-surface-400 truncate leading-tight">
                            {item.variant_name} · @ {fmt(item.price)}
                        </p>
                        {item.price_adjusted && (
                            <span className="shrink-0 text-2xs font-bold text-orange-600 bg-orange-100 px-1 py-0.5 rounded leading-none" title={`Original: ${fmt(cataloguePrice)}`}>
                                ✎
                            </span>
                        )}
                    </div>
                </div>

                {/* Subtotal + desktop delete */}
                <div className="flex items-center gap-1 shrink-0">
                    <span className="text-xs font-bold text-surface-900 tabular-nums">{fmt(sub)}</span>
                    {/* Desktop-only remove button — hidden on touch devices (swipe handles it) */}
                    <button
                        onClick={() => onRemove(index)}
                        title="Remove item"
                        className="hidden sm:flex w-6 h-6 items-center justify-center rounded text-surface-300 hover:text-danger hover:bg-danger-light transition-colors ml-0.5"
                    >
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            {/* Row 2: action pills - only shown on hover via group, always visible when active */}
            <div className="flex items-center gap-1 mt-1 pl-[52px]">
                {/* Discount toggle */}
                <button
                    onClick={() => setShowDisc(v => !v)}
                    className={clsx(
                        "text-2xs px-1.5 py-0.5 rounded font-medium transition-colors leading-none",
                        item.discount_type !== "none"
                            ? "bg-warning-light text-warning-dark"
                            : "text-surface-300 hover:text-surface-600 hover:bg-surface-100",
                    )}
                >
                    {item.discount_type === "percent" ? `-${item.discount_value}%` :
                     item.discount_type === "flat"    ? `-${fmt(item.discount_value)}` : "% disc"}
                </button>

                {/* Price override */}
                <button
                    onClick={() => { setShowPriceEdit(v => !v); setPriceInput(String(item.price)); }}
                    title="Adjust unit price (upward only)"
                    className={clsx(
                        "flex items-center gap-0.5 text-2xs px-1.5 py-0.5 rounded font-medium transition-colors leading-none",
                        item.price_adjusted
                            ? "bg-orange-100 text-orange-700"
                            : "text-surface-300 hover:text-orange-600 hover:bg-orange-50",
                    )}
                >
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
                    </svg>
                    {item.price_adjusted ? fmt(item.price) : "price"}
                </button>

                {/* MTO toggle */}
                <button
                    onClick={() => onProduction(index, !hasMto)}
                    title={hasMto ? "Remove MTO flag" : "Mark as Made-to-Order"}
                    className={clsx(
                        "flex items-center gap-0.5 text-2xs px-1.5 py-0.5 rounded font-medium transition-colors leading-none",
                        hasMto
                            ? "bg-purple-100 text-purple-700"
                            : "text-surface-300 hover:text-purple-600 hover:bg-purple-50",
                    )}
                >
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                    MTO
                </button>

                {/* Measurements/notes button - only when MTO */}
                {hasMto && (
                    <button
                        onClick={() => onOpenMeasurements(index)}
                        className={clsx(
                            "flex items-center gap-0.5 text-2xs px-1.5 py-0.5 rounded font-medium transition-colors leading-none",
                            (measurementsFilled || hasNotes)
                                ? "bg-purple-100 text-purple-700"
                                : "bg-purple-50 text-purple-500 border border-purple-200",
                        )}
                    >
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/></svg>
                        {(measurementsFilled || hasNotes) ? "edited" : "add details"}
                    </button>
                )}

            </div>

            {/* Inline discount editor */}
            {showDisc && (
                <div className="flex items-center gap-1.5 mt-1 pl-[52px]">
                    <select
                        value={item.discount_type}
                        onChange={(e) => onDiscount(index, e.target.value as "none"|"flat"|"percent", item.discount_value)}
                        className="text-2xs border border-surface-200 rounded px-1.5 py-1 bg-white focus:outline-none focus:border-brand-400"
                    >
                        <option value="none">None</option>
                        <option value="flat">Flat {currency}</option>
                        <option value="percent">% Off</option>
                    </select>
                    {item.discount_type !== "none" && (
                        <input
                            type="number" min={0}
                            value={item.discount_value}
                            onChange={(e) => onDiscount(index, item.discount_type, parseFloat(e.target.value) || 0)}
                            className="w-16 text-2xs border border-surface-200 rounded px-2 py-1 focus:outline-none focus:border-brand-400"
                        />
                    )}
                    <button onClick={() => { onDiscount(index, "none", 0); setShowDisc(false); }} className="text-2xs text-danger ml-auto">Clear</button>
                </div>
            )}
            </div>

            {/* Inline price override editor */}
            {showPriceEdit && (
                <div className="flex items-center gap-1.5 mt-1 pl-[52px]">
                    <span className="text-2xs text-surface-400 shrink-0">Price ({currency})</span>
                    <input
                        type="number"
                        min={cataloguePrice}
                        step="1"
                        value={priceInput}
                        onChange={(e) => setPriceInput(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === "Enter") {
                                const p = parseFloat(priceInput);
                                if (!isNaN(p) && p >= cataloguePrice) { onPriceOverride(index, p); setShowPriceEdit(false); }
                            }
                            if (e.key === "Escape") setShowPriceEdit(false);
                        }}
                        autoFocus
                        className="w-20 text-2xs border border-orange-300 rounded px-2 py-1 focus:outline-none focus:border-orange-500 bg-orange-50"
                    />
                    <button
                        onClick={() => {
                            const p = parseFloat(priceInput);
                            if (!isNaN(p) && p >= cataloguePrice) { onPriceOverride(index, p); setShowPriceEdit(false); }
                        }}
                        className="text-2xs font-semibold text-orange-700 hover:text-orange-900"
                    >Set</button>
                    {item.price_adjusted && (
                        <button onClick={() => { onPriceOverride(index, cataloguePrice); setShowPriceEdit(false); }} className="text-2xs text-danger ml-auto">Reset</button>
                    )}
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Register Gate - now with visual outlet cards
// ─────────────────────────────────────────────────────────────────────────────

function RegisterGate({
    outlets,
    selectedOutletId,
    onSelectOutlet,
    onOpenRegister,
}: {
    outlets: PosOutlet[];
    selectedOutletId: number | null;
    onSelectOutlet: (id: number) => void;
    onOpenRegister: () => void;
}) {
    const selected = outlets.find((o) => o.id === selectedOutletId);

    return (
        <div className="flex flex-col items-center justify-center h-full gap-8 px-4 animate-fade-in max-w-xl mx-auto">
            {/* Icon */}
            <div className="relative">
                <div className="w-20 h-20 rounded-3xl bg-gradient-to-br from-brand-50 to-brand-100 flex items-center justify-center shadow-inner">
                    <svg
                        className="w-10 h-10 text-brand-500"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.5}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75"
                        />
                    </svg>
                </div>
                <div className="absolute -bottom-1 -right-1 w-6 h-6 rounded-full bg-danger flex items-center justify-center shadow-md">
                    <svg
                        className="w-3 h-3 text-white"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={3}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </div>
            </div>

            <div className="text-center">
                <h2 className="text-xl font-bold text-surface-900">
                    Register Closed
                </h2>
                <p className="text-sm text-surface-500 mt-1">
                    {outlets.length > 1
                        ? "Select your outlet and open the register to start."
                        : `Open the register at ${selected?.name ?? "this outlet"} to start.`}
                </p>
            </div>

            {/* Outlet cards */}
            {outlets.length > 1 && (
                <div
                    className={clsx(
                        "grid gap-3 w-full",
                        outlets.length === 2
                            ? "grid-cols-2"
                            : "grid-cols-2 sm:grid-cols-3",
                    )}
                >
                    {outlets.map((o) => (
                        <OutletCard
                            key={o.id}
                            outlet={o}
                            selected={o.id === selectedOutletId}
                            onSelect={() => onSelectOutlet(o.id)}
                        />
                    ))}
                </div>
            )}

            <button
                onClick={onOpenRegister}
                disabled={!selectedOutletId}
                className="btn-primary btn-lg gap-3 px-8 shadow-lg shadow-brand-200 hover:shadow-brand-300 transition-shadow disabled:opacity-50"
            >
                <svg
                    className="w-5 h-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15m-3 0l-3-3m0 0l3-3m-3 3H15"
                    />
                </svg>
                Open Register
            </button>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// OutletSwitcher - compact, shown in the active POS top-bar
// ─────────────────────────────────────────────────────────────────────────────

function OutletSwitcher({
    outlets,
    selectedId,
    onChange,
}: {
    outlets: PosOutlet[];
    selectedId: number | null;
    onChange: (id: number) => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const current = outlets.find((o) => o.id === selectedId);

    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node))
                setOpen(false);
        };
        document.addEventListener("mousedown", h);
        return () => document.removeEventListener("mousedown", h);
    }, []);

    if (outlets.length <= 1) {
        return (
            <span className="flex items-center gap-2">
                <svg
                    className="w-4 h-4 text-surface-400 shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={1.8}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"
                    />
                </svg>
                <span className="font-semibold text-surface-900 text-sm">
                    {current?.name}
                </span>
            </span>
        );
    }

    return (
        <div ref={ref} className="relative">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex items-center gap-2 px-3 py-1.5 rounded-xl border border-surface-200 bg-white hover:border-brand-300 transition-all text-sm font-semibold text-surface-900 shadow-sm"
            >
                <svg
                    className="w-4 h-4 text-surface-400 shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={1.8}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"
                    />
                </svg>
                <span className="max-w-[140px] truncate">
                    {current?.name ?? "Select outlet"}
                </span>
                <svg
                    className={clsx(
                        "w-3.5 h-3.5 text-surface-400 transition-transform",
                        open && "rotate-180",
                    )}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2.5}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M19.5 8.25l-7.5 7.5-7.5-7.5"
                    />
                </svg>
            </button>

            {open && (
                <div className="absolute left-0 top-full mt-1.5 z-50 bg-white border border-surface-200 rounded-2xl shadow-xl overflow-hidden min-w-[260px]">
                    <p className="px-3 pt-3 pb-1.5 text-2xs font-semibold text-surface-400 uppercase tracking-wide">
                        Switch Outlet
                    </p>
                    <div className="p-2 space-y-1">
                        {outlets.map((o) => (
                            <button
                                key={o.id}
                                onClick={() => {
                                    onChange(o.id);
                                    setOpen(false);
                                }}
                                className={clsx(
                                    "w-full text-left flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all",
                                    o.id === selectedId
                                        ? "bg-brand-50 text-brand-700"
                                        : "hover:bg-surface-50 text-surface-700",
                                )}
                            >
                                <div
                                    className={clsx(
                                        "w-7 h-7 rounded-lg flex items-center justify-center shrink-0",
                                        o.id === selectedId
                                            ? "bg-brand-500 text-white"
                                            : "bg-surface-100 text-surface-500",
                                    )}
                                >
                                    <svg
                                        className="w-4 h-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={1.8}
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"
                                        />
                                    </svg>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="font-semibold text-sm truncate">
                                        {o.name}
                                    </p>
                                    {o.city && (
                                        <p className="text-2xs text-surface-400 truncate">
                                            {o.city}
                                        </p>
                                    )}
                                </div>
                                {o.id === selectedId && (
                                    <svg
                                        className="w-4 h-4 text-brand-500 shrink-0"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={2.5}
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M4.5 12.75l6 6 9-13.5"
                                        />
                                    </svg>
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Main POS Page
// ─────────────────────────────────────────────────────────────────────────────

export default function PosPage() {
    const toast = useToastStore();
    const qc = useQueryClient();

    // ── State ──────────────────────────────────────────────────────────────────
    // Hydrate from sessionStorage on the very first render so the cashier's
    // cart survives navigation away from the POS page.
    const _draft = loadCartDraft();

    const [selectedOutletId, setSelectedOutletId] = useState<number | null>(
        _draft?.outletId ?? null,
    );
    const [searchInput, setSearchInput] = useState("");
    const [searchQuery, setSearchQuery] = useState("");
    const [categoryFilter, setCategoryFilter] = useState<number | null>(null);
    const [cart, setCart] = useState<ExtCartItem[]>(_draft?.cart ?? []);
    const [cartDiscType, setCartDiscType] = useState<"none" | "flat" | "percent">(_draft?.cartDiscType ?? "none");
    const [cartDiscVal, setCartDiscVal] = useState(_draft?.cartDiscVal ?? 0);
    // ── Checkout config: tax settings + app_country ─────────────────────────────
    // Uses the POS-scoped /pos/checkout-config endpoint (pos.access
    // permission), NOT settingsApi.get() -> /v1/admin/settings, which
    // requires role:super_admin|admin. pos_clerk / outlet_manager got 403s
    // here and silently fell back to taxInclusive = false / HOME_COUNTRY =
    // "KE" (hardcoded default) on every load.
    const { data: checkoutConfigData } = useQuery({
        queryKey: ["pos-checkout-config"],
        queryFn: () => get<{
            app_country: string;
            tax_inclusive: boolean;
            default_tax_rate: any;
            tax_rates: any[];
        }>("/v1/admin/pos/checkout-config"),
        staleTime: 300_000,
    });
    const taxInclusive: boolean = checkoutConfigData?.tax_inclusive ?? false;

    // ── Active currencies (from project settings) ───────────────────────────────
    // Uses the public /v1/settings/currencies endpoint (no auth/permission
    // required), NOT /v1/admin/currencies-management, which requires
    // role:super_admin. Also already pre-filtered to is_active server-side.
    const { data: currenciesData } = useQuery({
        queryKey: ["currencies-active"],
        queryFn: () =>
            get<{ data: Array<{ code: string; name: string; is_active?: boolean }> }>(
                "/v1/settings/currencies",
            ),
        staleTime: 300_000,
    });
    const activeCurrencies: string[] = (currenciesData?.data ?? []).map((c: any) => c.code);

    // ── Countries list for the country picker ───────────────────────────────────
    // Uses the public /v1/countries endpoint (no auth/permission required),
    // NOT /v1/admin/countries, which requires role:super_admin.
    const { data: countriesData } = useQuery({
        queryKey: ["countries-all"],
        queryFn: () =>
            get<{ data: Array<{ code: string; name: string; flag: string | null; default_currency_code: string | null; is_active: boolean }> }>(
                "/v1/countries",
            ),
        staleTime: 300_000,
    });
    const allCountries = (countriesData?.data ?? []).filter((c: any) => c.is_active);

    // Customer country code - drives currency for international POS orders.
    // Declared here (before earlyCountryObj) to avoid temporal dead zone.
    const [customerCountryCode, setCustomerCountryCode] = useState<string>(_draft?.customerCountryCode ?? "");

    // ── Early currency derivation for product queries ─────────────────────────
    // Needed before selectedOutlet is declared so it can go into React Query keys.
    // Simple rule: if a customer country is set and its default currency is active,
    // use it; otherwise send "" so the backend falls back to the outlet's currency.
    const earlyCountryObj  = allCountries.find((c: any) => c.code === customerCountryCode);
    const earlyCountryCurr = earlyCountryObj?.default_currency_code ?? "";
    const currencyForQuery =
        earlyCountryCurr && (activeCurrencies.length === 0 || activeCurrencies.includes(earlyCountryCurr))
            ? earlyCountryCurr
            : "";

    const [shippingAmount, setShippingAmount] = useState(_draft?.shippingAmount ?? 0);
    const [showShippingInput, setShowShippingInput] = useState(false);

    // Customer
    const [attachedCustomer, setAttachedCustomer] =
        useState<AttachedCustomer | null>(_draft?.attachedCustomer ?? null);



    // Mobile panel
    const [mobilePanel, setMobilePanel] = useState<"products" | "cart">(
        "products",
    );

    // Modals
    const [variantPicker, setVariantPicker] = useState<PosProduct | null>(null);
    const [showRegisterModal, setShowRegisterModal] = useState(false);
    const [showPaymentModal, setShowPaymentModal] = useState(false);

    // Banner dismiss flags — reset on page refresh (intentionally not persisted)
    const [resumeBannerDismissed, setResumeBannerDismissed]   = useState(false);
    const [mismatchBannerDismissed, setMismatchBannerDismissed] = useState(false);
    const [receiptSale, setReceiptSale] = useState<PosSale | null>(null);
    const [receiptNeedsApproval, setReceiptNeedsApproval] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [showEod, setShowEod] = useState(false);
    const [eodSubmitted, setEodSubmitted] = useState(false);
    const [showReturns, setShowReturns] = useState(false);
    // Production orders raised after checkout
    const [raisedProdOrders, setRaisedProdOrders] = useState<string[]>([]);
    // Index of cart item whose measurement sheet is open (-1 = none)
    const [measurementSheetIdx, setMeasurementSheetIdx] = useState<number>(-1);
    // Stores the last completed sale's order number so the POS page can show a
    // subtle "Sale complete" banner after the cart is cleared (Paystack / STK).
    const [lastCompletedSale, setLastCompletedSale] = useState<string | null>(null);

    const searchRef = useRef<HTMLInputElement>(null);
    const searchTimer = useRef<ReturnType<typeof setTimeout>>();

    // ── Data ───────────────────────────────────────────────────────────────────
    const { data: outletsData, isLoading: loadingOutlets } = useQuery({
        queryKey: ["pos-outlets"],
        queryFn: () => posApi.outlets(),
    });
    const outlets: PosOutlet[] = outletsData?.data ?? [];

    // ── Configured payment methods (driven by settings, not hardcoded) ─────────
    // NOTE: uses the public /v1/payment-methods endpoint (availableForSale),
    // NOT the admin-only paymentMethodsApi.list(). The admin endpoint is
    // gated by role:super_admin, so pos_clerk / outlet_manager / admin users
    // would get a 403 and see an empty list, breaking checkout.
    const { data: payMethodsData } = useQuery({
        queryKey: ["payment-methods"],
        queryFn:  () => paymentMethodsApi.availableForSale(),
        staleTime: 5 * 60_000,
    });
    const configuredMethods: ConfiguredMethod[] = (payMethodsData?.data ?? []).filter(
        (m: any) => m.is_active
    );

    useEffect(() => {
        if (outlets.length > 0 && !selectedOutletId)
            setSelectedOutletId(outlets[0].id);
    }, [outlets, selectedOutletId]);

    // ── Auto-bypass: if THIS USER already has an open register on any outlet, go in ─
    const [autoBypassDone, setAutoBypassDone] = useState(false);
    useEffect(() => {
        if (autoBypassDone || outlets.length === 0) return;
        Promise.all(outlets.map((o) => posApi.registerStatus(o.id).then((r) => ({ outletId: o.id, open: r.has_open_register }))))
            .then((results) => {
                const openOutlet = results.find((r) => r.open);
                if (openOutlet) setSelectedOutletId(openOutlet.outletId);
                setAutoBypassDone(true);
            })
            .catch(() => setAutoBypassDone(true));
    }, [outlets, autoBypassDone]);

    const { data: registerData, refetch: refetchRegister } = useQuery({
        queryKey: ["pos-register", selectedOutletId],
        queryFn: () => posApi.registerStatus(selectedOutletId!),
        enabled: !!selectedOutletId,
        refetchInterval: 60_000,
    });
    const registerOpen = registerData?.has_open_register ?? false;
    const register = registerData?.register ?? null;

    // Sync eod_submitted from the server — true when the user has submitted
    // their EoD report today (required before closeRegister is allowed)
    useEffect(() => {
        if (registerData?.eod_submitted !== undefined) {
            setEodSubmitted(registerData.eod_submitted);
        }
    }, [registerData?.eod_submitted]);

    // ── Resume pending order ───────────────────────────────────────────────────
    // When the cashier selects an outlet (or returns to the POS after a refresh),
    // check if there is an unfinished pending POS order from this session.
    // If so, preload it into state so "Charge" resumes the same order instead
    // of creating a duplicate.
    //
    // staleTime is intentionally NOT 0: with staleTime:0 React Query treats the
    // result as stale immediately, refetching on every window-focus / remount and
    // producing a new object reference each time — re-firing the effect and
    // re-showing the toast even though the underlying order hasn't changed.
    const { data: openPendingData } = useQuery({
        queryKey: ["pos-pending-order", selectedOutletId],
        queryFn:  () => posApi.getPendingOrder(selectedOutletId!),
        enabled:  !!selectedOutletId && registerOpen,
        staleTime: 30_000,
        retry: false,
    });

    // Track which order ID has already been auto-resumed so a refetch of the
    // same order never re-fires the toast or overwrites in-progress state.
    // A ref (not state) is intentional — it must not cause a re-render.
    const autoResumedOrderRef = useRef<number | null>(null);

    useEffect(() => {
        if (!openPendingData?.order_id) return;

        const incomingId = openPendingData.order_id;

        // Already handled this exact order in a previous run of this effect.
        // Prevents re-toasting when React Query refetches and produces a new
        // object reference for the same underlying order.
        if (autoResumedOrderRef.current === incomingId) return;

        // A pending order is already attached to this cart session (from the
        // sessionStorage draft or a previous auto-resume). Don't clobber it.
        if (pendingOrderId) {
            autoResumedOrderRef.current = incomingId;
            return;
        }

        // Never auto-resume an order that was deliberately dismissed — either
        // by the cashier saving and walking away, or by clearing the cart after
        // a restore without saving changes.
        if (isPendingOrderDismissed(incomingId)) {
            autoResumedOrderRef.current = incomingId;
            return;
        }

        // All guards passed — attach the order and notify exactly once.
        autoResumedOrderRef.current = incomingId;
        setPendingOrderId(incomingId);
        setPendingOrderData(openPendingData);
        setPendingOrderCartSig(cartSignature(cart));
        toast.info(`Resuming order ${openPendingData.order_number} — press Charge to continue.`);
    }, [openPendingData]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Shipping methods ───────────────────────────────────────────────────────
    const [selectedShippingId, setSelectedShippingId] = useState<number | null>(_draft?.selectedShippingId ?? null);
    const [shippingAddress, setShippingAddress]       = useState(_draft?.shippingAddress ?? "");
    const [showShippingPanel, setShowShippingPanel]   = useState(false);

    const { data: shippingMethodsData } = useQuery({
        queryKey: ["pos-shipping-methods"],
        queryFn: () => posApi.shippingMethods(),
        staleTime: 5 * 60_000,
    });
    const shippingMethods: PosShippingMethod[] = shippingMethodsData?.data ?? [];
    const selectedShippingMethod = shippingMethods.find((m) => m.id === selectedShippingId) ?? null;
    // Derive the numeric fee from the selected method; override the old freeform shippingAmount
    const shippingFeeFromMethod = selectedShippingMethod
        ? (selectedShippingMethod.cost_type === "free" ? 0 : selectedShippingMethod.flat_rate)
        : 0;

    const { data: productsData, isLoading: loadingProducts } = useQuery({
        queryKey: ["pos-products", selectedOutletId, categoryFilter, currencyForQuery],
        queryFn: () =>
            get<{ data: PosProduct[]; meta: { total: number; current_page: number; last_page: number } }>(
                "/v1/admin/pos/products",
                { params: {
                    outlet_id:    String(selectedOutletId!),
                    ...(categoryFilter ? { category_id: String(categoryFilter) } : {}),
                    ...(currencyForQuery ? { currency: currencyForQuery } : {}),
                }},
            ),
        enabled: !!selectedOutletId && registerOpen && !searchQuery,
        staleTime: 30_000,
    });

    const { data: searchData, isFetching: searching } = useQuery({
        queryKey: ["pos-search", searchQuery, selectedOutletId, currencyForQuery],
        queryFn: () =>
            get<{ data: PosProduct[] }>(
                "/v1/admin/pos/products/search",
                { params: {
                    q:         searchQuery,
                    outlet_id: String(selectedOutletId!),
                    ...(currencyForQuery ? { currency: currencyForQuery } : {}),
                }},
            ),
        enabled: !!selectedOutletId && registerOpen && searchQuery.length >= 2,
    });

    const products: PosProduct[] = searchQuery
        ? (searchData?.data ?? [])
        : (productsData?.data ?? []);

    const categories = useMemo(() => {
        const all = productsData?.data ?? [];
        const map = new Map<number, string>();
        all.forEach((p) => {
            if (p.category) map.set(p.category.id, p.category.name);
        });
        return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
    }, [productsData]);

    // ── Search debounce ────────────────────────────────────────────────────────
    const handleSearch = useCallback((val: string) => {
        setSearchInput(val);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => {
            setSearchQuery(val.trim());
            if (val.trim()) setCategoryFilter(null);
        }, 280);
    }, []);

    // ── Cart ops ───────────────────────────────────────────────────────────────
    const addToCart = useCallback(
        (product: PosProduct, variant: PosVariant) => {
            const isProduction =
                (variant as any).is_made_to_order ||
                (variant as any).allow_backorder;
            const variantId = Number(variant.id);
            const productId = Number(product.id);
            setCart((prev) => {
                const idx = prev.findIndex(
                    (i) => Number(i.variant_id) === variantId && Number(i.product_id) === productId,
                );
                if (idx >= 0) {
                    const u = [...prev];
                    u[idx] = { ...u[idx], quantity: u[idx].quantity + 1 };
                    return u;
                }
                return [
                    ...prev,
                    {
                        variant_id: variantId,
                        product_name: product.name,
                        variant_name: variant.variant_name,
                        sku: variant.sku,
                        price: variant.price,
                        quantity: 1,
                        discount_type: "none",
                        discount_value: 0,
                        image_url: product.image_url,
                        tax_rate: variant.tax_rate ?? 0,
                        tax_name: variant.tax_name ?? null,
                        is_production: isProduction && variant.stock === 0,
                        product_id: productId,
                        measurement_fields: product.is_producible && product.measurements?.length
                            ? product.measurements
                            : undefined,
                        measurement_values: {},
                    },
                ];
            });
            setMobilePanel("cart");
            setLastCompletedSale(null); // clear previous sale banner when new order begins
        },
        [],
    );

    const handleProductClick = useCallback(
        (product: PosProduct, variant: PosVariant) => {
            if (product.variants.length > 1) {
                setVariantPicker(product);
            } else {
                addToCart(product, variant);
            }
        },
        [addToCart],
    );

    const updateQty = useCallback(
        (i: number, q: number) =>
            setCart((p) => {
                const u = [...p];
                u[i] = { ...u[i], quantity: q };
                return u;
            }),
        [],
    );
    const removeItem = useCallback(
        (i: number) => setCart((p) => p.filter((_, x) => x !== i)),
        [],
    );
    const updateDiscount = useCallback(
        (i: number, t: "none" | "flat" | "percent", v: number) =>
            setCart((p) => {
                const u = [...p];
                u[i] = { ...u[i], discount_type: t, discount_value: v };
                return u;
            }),
        [],
    );

    const updatePriceOverride = useCallback(
        (i: number, price: number) =>
            setCart((p) => {
                const u = [...p];
                const item = u[i];
                const original = item.original_price ?? item.price;
                const isReset  = Math.abs(price - original) < 0.001;
                u[i] = {
                    ...item,
                    price:          isReset ? original : price,
                    original_price: isReset ? undefined : original,
                    price_adjusted: isReset ? false : price > original,
                };
                return u;
            }),
        [],
    );

    const updateProduction = useCallback(
        (i: number, val: boolean, notes?: string) => {
            setCart((p) => {
                const u = [...p];
                u[i] = {
                    ...u[i],
                    is_production: val,
                    production_notes: notes !== undefined ? notes : u[i].production_notes,
                };
                return u;
            });
            // Close the measurement sheet whenever MTO is toggled off so the
            // sheet's onChange can't silently re-enable is_production.
            if (!val) setMeasurementSheetIdx(-1);
        },
        [],
    );

    const updateMeasurement = useCallback(
        (i: number, field: string, value: string) =>
            setCart((p) => {
                const u = [...p];
                u[i] = {
                    ...u[i],
                    measurement_values: { ...(u[i].measurement_values ?? {}), [field]: value },
                };
                return u;
            }),
        [],
    );

    const openMeasurementSheet = useCallback((i: number) => setMeasurementSheetIdx(i), []);
    const closeMeasurementSheet = useCallback(() => setMeasurementSheetIdx(-1), []);

    // Must be declared before clearCart and the cart-change useEffect
    // which both reference pendingOrderId.
    const [pendingOrderId,   setPendingOrderId]   = useState<number | null>(_draft?.pendingOrderId ?? null);
    const [pendingOrderData, setPendingOrderData] = useState<any | null>(_draft?.pendingOrderData ?? null);
    // Fingerprint of the cart at the moment the pending order was created.
    // Used to detect genuine cart changes (items added/removed/qty changed)
    // rather than comparing server vs client totals which can differ by rounding.
    const [pendingOrderCartSig, setPendingOrderCartSig] = useState<string>(_draft?.pendingOrderCartSig ?? "");
    // When true the current pendingOrderId was restored from sales history.
    // Changes to a resumed order UPDATE it in place rather than void+recreate.
    const [isResumedOrder, setIsResumedOrder] = useState(_draft?.isResumedOrder ?? false);

    // ── Persist cart draft to sessionStorage ───────────────────────────────────
    // Runs after every render where any of the tracked values change, so the
    // cashier can navigate away and return to an identical cart session.
    useEffect(() => {
        // Don't persist an empty cart — let the draft be absent so the page
        // starts fresh when there is genuinely nothing to restore.
        if (cart.length === 0 && !pendingOrderId) {
            clearCartDraft();
            return;
        }
        saveCartDraft({
            outletId:            selectedOutletId,
            cart,
            cartDiscType,
            cartDiscVal,
            attachedCustomer,
            customerCountryCode,
            shippingAmount,
            selectedShippingId,
            shippingAddress,
            pendingOrderId,
            pendingOrderData,
            pendingOrderCartSig,
            isResumedOrder,
        });
    }, [ // eslint-disable-line react-hooks/exhaustive-deps
        cart, cartDiscType, cartDiscVal, attachedCustomer, customerCountryCode,
        shippingAmount, selectedShippingId, shippingAddress,
        pendingOrderId, pendingOrderData, pendingOrderCartSig, isResumedOrder,
        selectedOutletId,
    ]);

    const clearCart = useCallback(() => {
        // Release the pending order from this cart session - the order stays
        // intact in the system. The cashier can find it in the sales history
        // drawer if needed. We never auto-void orders in the POS.
        //
        // If a restored order was cleared without saving changes, dismiss it
        // so the auto-resume effect never re-attaches it (and re-toasts) when
        // React Query next refetches the open pending order.
        if (pendingOrderId) {
            addDismissedPendingOrderId(pendingOrderId);
        }
        setCart([]);
        setCartDiscType("none");
        setCartDiscVal(0);
        setShippingAmount(0);
        setShowShippingInput(false);
        setSelectedShippingId(null);
        setShippingAddress("");
        setShowShippingPanel(false);
        setAttachedCustomer(null);
        setCustomerCountryCode("");
        setMobilePanel("products");
        setRaisedProdOrders([]);
        setPendingOrderId(null);
        setPendingOrderData(null);
        setPendingOrderCartSig("");
        setIsResumedOrder(false);
        clearCartDraft();
    }, [pendingOrderId]);

    // ── Refresh cart prices when currency changes ──────────────────────────────
    // When the cashier picks a different customer country the effective currency
    // changes. React Query will refetch new product data automatically (the query
    // key includes currencyForQuery), but items already in the cart were added
    // with the old price. This effect detects the currency change and updates
    // existing cart items' prices from the freshly-fetched product data.
    const prevCurrencyRef = useRef<string>("");
    useEffect(() => {
        // Nothing to do on first render or if currency hasn't actually changed
        if (currencyForQuery === prevCurrencyRef.current) return;
        prevCurrencyRef.current = currencyForQuery;

        if (cart.length === 0 || !selectedOutletId) return;

        const target = currencyForQuery || "KES";
        get<{ data: PosProduct[] }>(
            "/v1/admin/pos/products/search",
            { params: {
                q:         cart.map(i => i.sku).filter(Boolean).join(" ") || "_",
                outlet_id: String(selectedOutletId),
                currency:  target,
            }},
        ).then((res) => {
            const fresh: PosProduct[] = res.data ?? [];
            setCart(prev => prev.map(item => {
                for (const p of fresh) {
                    const v = p.variants.find(vv => Number(vv.id) === Number(item.variant_id));
                    if (v) return { ...item, price: v.price };
                }
                return item;
            }));
        }).catch(() => { /* silently ignore - stale price stays until item re-added */ });
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [currencyForQuery]);

    const totals = useMemo(
        () => calcTotals(cart, cartDiscType, cartDiscVal, shippingFeeFromMethod || shippingAmount, taxInclusive),
        [cart, cartDiscType, cartDiscVal, shippingFeeFromMethod, shippingAmount, taxInclusive],
    );
    const cartQty = cart.reduce((s, i) => s + i.quantity, 0);
    const hasMto  = cart.some((i) => i.is_production);

    // ── Two-step POS checkout ──────────────────────────────────────────────────
    //
    // Step 1: "Charge" → build cart payload → POST /admin/pos/pending-order
    //         (creates order + deducts stock, no payment yet)
    //         → open PaymentModal with the real orderId
    //
    // Step 2: PaymentModal fires onCharge/onStkComplete
    //         → POST /admin/pos/pending-order/{id}/pay  (records payment + proof)
    //         → show receipt
    //
    // The old single-shot createSale is kept for cash-only fast-path below.

    // Build the cart-only portion of the payload (no payment fields)
    const buildCartPayload = useCallback(() => {
        const productionItems = cart
            .filter((i) => i.is_production)
            .map((i) => {
                let notes = i.production_notes ?? "";
                if (i.measurement_fields?.length && i.measurement_values) {
                    const measStr = i.measurement_fields
                        .map((f) => `${f.name}: ${i.measurement_values![f.name] ?? "-"}${f.unit ? " " + f.unit : ""}`)
                        .join(", ");
                    notes = measStr + (notes ? " | " + notes : "");
                }
                return {
                    variant_id:         i.variant_id || null,
                    product_id:         i.product_id,
                    quantity:           i.quantity,
                    unit_price:         i.price,
                            original_price:     i.original_price ?? null,
                            price_adjusted:     i.price_adjusted ?? false,
                    production_notes:   notes || undefined,
                    measurement_values: (i.measurement_values && Object.keys(i.measurement_values).length > 0)
                        ? i.measurement_values
                        : undefined,
                };
            });

        return {
            outlet_id:            selectedOutletId!,
            customer_first_name:  attachedCustomer?.first_name ?? undefined,
            customer_last_name:   attachedCustomer?.last_name  ?? undefined,
            customer_phone:       attachedCustomer?.phone      ?? undefined,
            customer_email:       attachedCustomer?.email      ?? undefined,
            ...(attachedCustomer && attachedCustomer.id > 0 ? { customer_id: attachedCustomer.id } : {}),
            ...(attachedCustomer?.id === -1 && attachedCustomer.new_customer_data ? { new_customer: attachedCustomer.new_customer_data } : {}),
            // Country drives currency resolution on the backend
            ...(customerCountryCode ? { customer_country_code: customerCountryCode } : {}),
            // MTO items must NOT appear in items[] — the backend stock check
            // would reject them immediately (they have zero stock by definition).
            // They are represented solely via production_items[]; the backend
            // creates the order line from that array without touching inventory.
            items: cart
                .filter((i) => !i.is_production)
                .map((i) => ({
                    variant_id: i.variant_id || null,
                    ...(i.product_id ? { product_id: i.product_id } : {}),
                    quantity: i.quantity, unit_price: i.price, original_price: i.original_price ?? null, price_adjusted: i.price_adjusted ?? false,
                    discount_type: i.discount_type, discount_value: i.discount_value,
                })),
            cart_discount_type:  cartDiscType,
            cart_discount_value: cartDiscVal,
            shipping_amount:     (shippingFeeFromMethod || shippingAmount) > 0 ? (shippingFeeFromMethod || shippingAmount) : undefined,
            ...(selectedShippingId ? { shipping_method_id: selectedShippingId } : {}),
            ...(productionItems.length > 0 ? { production_items: productionItems } : {}),
        };
    }, [cart, selectedOutletId, attachedCustomer, customerCountryCode, cartDiscType, cartDiscVal, shippingAmount, shippingFeeFromMethod, selectedShippingId]);

    // Step 1 mutation - create pending order
    const pendingOrderMutation = useMutation({
        mutationFn: (payload: any) => posApi.createPendingOrder(payload),
        onSuccess: (res) => {
            setPendingOrderId(res.order_id);
            setPendingOrderData(res);
            setIsResumedOrder(false);
            // Snapshot the cart fingerprint at order-creation time.
            // The mismatch warning compares against this - not against server totals -
            // so rounding differences never trigger a false positive.
            setPendingOrderCartSig(cartSignature(cart));
            // Reset banner dismiss flags so banners appear for the new order.
            setResumeBannerDismissed(false);
            setMismatchBannerDismissed(false);
            const raised = res.production_orders;
            if (raised && raised.length > 0) setRaisedProdOrders(raised);
        },
        onError: (err: { message: string }) => {
            toast.error(err.message);
            setShowPaymentModal(false);
        },
    });

    // Step 1b mutation - update an existing resumed pending order in place
    // (used instead of void+recreate when the cart changes after a restore)
    const updateOrderMutation = useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: any }) =>
            posApi.updatePendingOrder(id, payload),
        onSuccess: (res) => {
            setPendingOrderData(res);
            setPendingOrderCartSig(cartSignature(cart));
            // Keep isResumedOrder true - still a resumed order
        },
        onError: (err: { message: string }) => {
            toast.error(err.message);
            setShowPaymentModal(false);
        },
    });

    // "Save Order" mutation — creates a pending order without opening the payment
    // modal. The cashier can leave the POS, and return later via the history
    // drawer to restore and pay. The cart is cleared once the order is saved.
    //
    // NOTE: success handling (toast + clearCart) lives ONLY in the per-call
    // onSuccess passed to .mutate() inside handleSaveOrder below — NOT here.
    // React Query calls both a mutation-level onSuccess and a per-call onSuccess
    // if both are defined; defining the toast in both fired it twice on every save.
    const saveOrderMutation = useMutation({
        mutationFn: (payload: any) => posApi.createPendingOrder(payload),
        onError: (err: { message: string }) => toast.error(err.message),
    });

    const handleSaveOrder = useCallback(() => {
        if (!selectedOutletId || cart.length === 0) return;
        // If there's already a pending order with no cart changes, just clear
        // locally — no need to create a duplicate.
        if (pendingOrderId && pendingOrderCartSig !== "" &&
            cartSignature(cart) === pendingOrderCartSig) {
            const orderNum = pendingOrderData?.order_number ?? pendingOrderData?.order?.order_number ?? "";
            toast.success(`Order ${orderNum} is already saved — find it in Sales History.`);
            // Dismiss so auto-resume never re-attaches this order on return
            addDismissedPendingOrderId(pendingOrderId);
            clearCart();
            return;
        }
        // If this is a resumed order (restored from history), UPDATE it in place
        // rather than creating a duplicate pending order.
        if (isResumedOrder && pendingOrderId) {
            const idToSave = pendingOrderId;
            updateOrderMutation.mutate(
                { id: idToSave, payload: buildCartPayload() },
                {
                    onSuccess: (res) => {
                        const orderNum = res.order_number ?? res.order?.order_number ?? "";
                        toast.success(`Order ${orderNum} updated — find it in Sales History.`);
                        // Dismiss so auto-resume never re-attaches this order on return
                        addDismissedPendingOrderId(idToSave);
                        clearCart();
                        qc.invalidateQueries({ queryKey: ["pos-sales"] });
                        qc.invalidateQueries({ queryKey: ["orders"] });
                    },
                },
            );
            return;
        }
        saveOrderMutation.mutate(buildCartPayload(), {
            onSuccess: (res) => {
                const orderNum = res.order_number ?? res.order?.order_number ?? "";
                // Dismiss AND clear cart so the sessionStorage draft never carries
                // pendingOrderId back into the auto-resume effect on return.
                // Without clearCart() the draft rehydrates with pendingOrderId set,
                // bypassing the dismissed-order guard and re-showing the banner.
                if (res.order_id) {
                    addDismissedPendingOrderId(res.order_id);
                    toast.success(`Order ${orderNum} saved — find it in Sales History.`);
                }
                clearCart();
                qc.invalidateQueries({ queryKey: ["pos-sales"] });
                qc.invalidateQueries({ queryKey: ["orders"] });
            },
        });
    }, [selectedOutletId, cart, pendingOrderId, pendingOrderCartSig, pendingOrderData,
        isResumedOrder, buildCartPayload, saveOrderMutation, updateOrderMutation, clearCart, qc, toast]);

    // Step 2 mutation - record payment against existing order
    const payMutation = useMutation({
        mutationFn: ({ orderId, data }: { orderId: number; data: FormData | object }) =>
            posApi.recordPosPay(orderId, data as any),
        onSuccess: (res) => {
            setShowPaymentModal(false);
            setPendingOrderId(null);
            setPendingOrderData(null);

            if (res.needs_approval) {
                // Non-integrated payment - show invoice (not receipt), order is on hold
                toast.success("Payment submitted - awaiting admin approval.");
                setReceiptNeedsApproval(true);
                setReceiptSale(res.order);
            } else if (res.payment_status === "paid") {
                toast.success("Sale complete!");
                setReceiptNeedsApproval(false);
                setReceiptSale(res.order);
            } else {
                toast.success(res.message ?? "Payment recorded.");
                setReceiptNeedsApproval(false);
                setReceiptSale(res.order);
            }

            setCart([]);
            setCartDiscType("none");
            setCartDiscVal(0);
            setAttachedCustomer(null);
            setMobilePanel("products");
            clearCartDraft();
            qc.invalidateQueries({ queryKey: ["pos-register", selectedOutletId] });
            qc.invalidateQueries({ queryKey: ["pos-products", selectedOutletId] });
            // Refresh the Orders lists (POS/Online/WhatsApp Orders) so the sale
            // that was just completed appears immediately instead of after a
            // manual reload — the list is keyed on ["orders"].
            qc.invalidateQueries({ queryKey: ["orders"] });
            qc.invalidateQueries({ queryKey: ["pos-sales"] });
        },
        onError: (err: { message: string }) => toast.error(err.message),
    });

    // Called by PaymentModal - payment details are ready, post them
    const handleCharge = useCallback(
        (modalPayments: ModalSplitPayment[], depositAmount?: number, proofFile?: File) => {
            if (!pendingOrderId) return;

            const payments = modalPayments.map((p) => ({
                method:        p.method === "__other__" ? "other" : p.method,
                amount:        p.amount,
                reference:     p.reference,
                cash_received: p.cashReceived,
            }));

            if (proofFile) {
                const fd = new FormData();

                // Laravel's array validator requires indexed multipart fields:
                // payments[0][method], payments[0][amount], etc.
                // Sending a JSON string for 'payments' fails array validation.
                payments.forEach((pmt, i) => {
                    fd.append(`payments[${i}][method]`,  pmt.method);
                    fd.append(`payments[${i}][amount]`,  String(pmt.amount));
                    if (pmt.reference)     fd.append(`payments[${i}][reference]`,     pmt.reference);
                    if (pmt.cash_received) fd.append(`payments[${i}][cash_received]`, String(pmt.cash_received));
                });

                if (depositAmount !== undefined) {
                    fd.append("is_deposit",     "true");
                    fd.append("deposit_amount", String(depositAmount));
                }

                // Append file last - Axios detects FormData and sets multipart/form-data
                // automatically when no Content-Type override is present.
                fd.append("proof_of_payment", proofFile);

                payMutation.mutate({ orderId: pendingOrderId, data: fd });
            } else {
                payMutation.mutate({
                    orderId: pendingOrderId,
                    data: {
                        payments,
                        ...(depositAmount !== undefined ? { is_deposit: true, deposit_amount: depositAmount } : {}),
                    },
                });
            }
        },
        [pendingOrderId, payMutation],
    );

    // Called by PaymentModal after STK push / Daraja confirms payment server-side
    const handleStkComplete = useCallback(() => {
        setShowPaymentModal(false);
        setPendingOrderId(null);
        if (pendingOrderData) {
            const orderNum = pendingOrderData.order?.order_number ?? pendingOrderData.order_number ?? "";
            toast.success("Payment confirmed — sale complete!");
            setReceiptNeedsApproval(false);
            setReceiptSale(pendingOrderData.order);
            setPendingOrderData(null);
            if (orderNum) setLastCompletedSale(orderNum);
        }
        setCart([]);
        setCartDiscType("none");
        setCartDiscVal(0);
        setAttachedCustomer(null);
        setMobilePanel("products");
        clearCartDraft();
        qc.invalidateQueries({ queryKey: ["pos-register", selectedOutletId] });
        qc.invalidateQueries({ queryKey: ["pos-products", selectedOutletId] });
    }, [pendingOrderData, selectedOutletId, qc, toast]);

    // "Charge" / "Resume Payment" button handler.
    //
    // Three cases:
    //   A) No pending order yet → create one, open modal
    //   B) Pending order exists, totals match → reopen modal against same order
    //   C) Pending order exists but cart changed (totals differ) →
    //      void the stale order, create a fresh one, open modal
    const handleOpenPayment = useCallback(() => {
        if (!selectedOutletId || cart.length === 0) return;

        const currentSig = cartSignature(cart);
        const cartChanged = pendingOrderCartSig !== "" && currentSig !== pendingOrderCartSig;

        if (pendingOrderId && !cartChanged) {
            // Case B - same cart items, reopen the payment modal against the same order
            setShowPaymentModal(true);
            return;
        }

        if (pendingOrderId && cartChanged && isResumedOrder) {
            // Case C-resumed - cart changed on a restored order: UPDATE it in place,
            // preserving the order number. No void, no duplicate order.
            setShowPaymentModal(true);
            updateOrderMutation.mutate({ id: pendingOrderId, payload: buildCartPayload() });
            return;
        }

        if (pendingOrderId && cartChanged && !isResumedOrder) {
            // Case C-fresh - cart changed on a freshly-created order.
            // Simply release the old order from this cart session - it stays
            // in the system as a pending order. Never auto-void in POS.
            setPendingOrderId(null);
            setPendingOrderData(null);
            setPendingOrderCartSig("");
            setIsResumedOrder(false);
        }

        // Case A or C-fresh - create a new order
        setShowPaymentModal(true);
        pendingOrderMutation.mutate(buildCartPayload());
    }, [selectedOutletId, cart, pendingOrderId, pendingOrderCartSig, isResumedOrder,
        buildCartPayload, pendingOrderMutation, updateOrderMutation]);

    const selectedOutlet = outlets.find((o) => o.id === selectedOutletId);
    // ── Effective currency / international flag ────────────────────────────────
    // Placed here because selectedOutlet must be declared first.
    const outletCurrency = selectedOutlet?.currency_code ?? "KES";
    const HOME_COUNTRY   = checkoutConfigData?.app_country ?? "KE";
    const isInternationalPosOrder = customerCountryCode !== "" && customerCountryCode !== HOME_COUNTRY;
    const countryObj      = allCountries.find((c: any) => c.code === customerCountryCode);
    const effectiveCurrency =
        customerCountryCode !== "" && countryObj?.default_currency_code
            ? (activeCurrencies.length === 0 || activeCurrencies.includes(countryObj.default_currency_code)
                ? countryObj.default_currency_code
                : outletCurrency)
            : outletCurrency;

    // ── Payment methods filtered by effective currency ─────────────────────────
    // A method is shown only if its supported_currencies is empty (all currencies)
    // or explicitly includes the current effective currency.
    const configuredMethodsForCurrency: ConfiguredMethod[] = configuredMethods.filter(
        (m: ConfiguredMethod) => {
            const supported = (m as any).supported_currencies as string[] | null | undefined;
            return !supported || supported.length === 0 || supported.includes(effectiveCurrency);
        }
    );

    // ── Loading ────────────────────────────────────────────────────────────────
    if (loadingOutlets) {
        return (
            <div className="flex items-center justify-center h-full">
                <Spinner size="lg" />
            </div>
        );
    }

    // ── Register closed gate ───────────────────────────────────────────────────
    if (selectedOutletId && !registerOpen) {
        return (
            <>
                <RegisterGate
                    outlets={outlets}
                    selectedOutletId={selectedOutletId}
                    onSelectOutlet={(id) => {
                        setSelectedOutletId(id);
                        clearCart();
                    }}
                    onOpenRegister={() => setShowRegisterModal(true)}
                />
                {showRegisterModal && (
                    <RegisterModal
                        outlets={outlets}
                        defaultOutletId={selectedOutletId}
                        mode="open"
                        onClose={() => setShowRegisterModal(false)}
                        onSuccess={() => {
                            setShowRegisterModal(false);
                            refetchRegister();
                        }}
                    />
                )}
            </>
        );
    }

    // ── POS Terminal ───────────────────────────────────────────────────────────
    return (
        <div
            className="flex flex-col h-full animate-fade-in"
            style={{ height: "calc(var(--vh, 1vh) * 100 - 120px)" }}
        >
            {/* ── Top bar ────────────────────────────────────────────────────── */}
            <div className="flex items-center gap-2 px-1 pb-3 shrink-0 flex-wrap">
                <div className="flex items-center gap-2 flex-1 min-w-0">
                    <OutletSwitcher
                        outlets={outlets}
                        selectedId={selectedOutletId}
                        onChange={(id) => {
                            setSelectedOutletId(id);
                            clearCart();
                        }}
                    />
                    <span className="flex items-center gap-1 text-2xs font-medium text-success px-2 py-0.5 bg-success-light rounded-full whitespace-nowrap shrink-0">
                        <span className="w-1.5 h-1.5 rounded-full bg-success animate-pulse" />
                        Open
                    </span>
                </div>

                <div className="flex items-center gap-1.5">
                    <button
                        onClick={() => setShowHistory(true)}
                        title="Sales History"
                        className="btn-secondary btn-sm gap-1.5 hidden sm:inline-flex"
                    >
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                        History
                    </button>
                    <button
                        onClick={() => setShowReturns(true)}
                        title="Returns"
                        className="btn-secondary btn-sm gap-1.5 hidden sm:inline-flex"
                    >
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"
                            />
                        </svg>
                        Returns
                    </button>
                    <button
                        onClick={() => setShowEod(true)}
                        className={clsx(
                            "btn-sm gap-1.5 hidden md:inline-flex",
                            eodSubmitted ? "btn-secondary text-success border-success/40" : "btn-secondary",
                        )}
                        title={eodSubmitted ? "EoD submitted ✓" : "End of Day report"}
                    >
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75z"
                            />
                        </svg>
                        EOD{eodSubmitted ? " ✓" : ""}
                    </button>
                    <button
                        onClick={() => setShowRegisterModal(true)}
                        className="btn-secondary btn-sm gap-1.5"
                    >
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                            />
                        </svg>
                        <span className="hidden sm:inline">Register</span>
                    </button>
                    {/* Mobile history shortcut */}
                    <button
                        onClick={() => setShowHistory(true)}
                        className="btn-ghost btn-icon btn-sm sm:hidden"
                    >
                        <svg
                            className="w-4 h-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                    </button>
                </div>
            </div>

            {/* ── Mobile tab switcher ──────────────────────────────────────────── */}
            <div className="flex sm:hidden gap-0 bg-surface-100 rounded-xl p-1 mb-3 shrink-0">
                <button
                    onClick={() => setMobilePanel("products")}
                    className={clsx(
                        "flex-1 py-2 rounded-lg text-xs font-semibold transition-all",
                        mobilePanel === "products"
                            ? "bg-white text-surface-900 shadow-sm"
                            : "text-surface-500",
                    )}
                >
                    Products
                </button>
                <button
                    onClick={() => setMobilePanel("cart")}
                    className={clsx(
                        "flex-1 py-2 rounded-lg text-xs font-semibold transition-all flex items-center justify-center gap-1.5",
                        mobilePanel === "cart"
                            ? "bg-white text-surface-900 shadow-sm"
                            : "text-surface-500",
                    )}
                >
                    Cart
                    {cartQty > 0 && (
                        <span
                            key={cartQty}
                            className="bg-brand-500 text-white text-2xs rounded-full w-4 h-4 flex items-center justify-center font-bold animate-[bounce_0.3s_ease]"
                        >
                            {cartQty > 9 ? "9+" : cartQty}
                        </span>
                    )}
                </button>
            </div>

            {/* ── Main two-panel layout ─────────────────────────────────────────── */}
            <div className="flex gap-4 flex-1 min-h-0">
                {/* LEFT: Products */}
                <div
                    className={clsx(
                        "flex flex-col card overflow-hidden flex-1",
                        mobilePanel === "cart" ? "hidden sm:flex" : "flex",
                    )}
                >
                    {/* Sale complete banner — shown after Paystack/STK auto-complete */}
                    {lastCompletedSale && (
                        <div className="mx-4 mt-3 flex items-center gap-3 bg-success-light border border-success/30 rounded-xl px-3 py-2.5 text-xs text-success-dark">
                            <div className="w-6 h-6 rounded-full bg-success flex items-center justify-center shrink-0">
                                <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </div>
                            <span className="flex-1 font-medium">
                                Sale complete — <span className="font-mono">{lastCompletedSale}</span>
                            </span>
                            <button
                                onClick={() => setLastCompletedSale(null)}
                                className="text-success/60 hover:text-success-dark transition-colors shrink-0"
                                aria-label="Dismiss"
                            >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    )}

                    {/* Search + categories */}
                    <div className="px-4 pt-4 pb-3 border-b border-surface-100 space-y-2.5 shrink-0">
                        <div className="relative">
                            <div className="absolute left-3.5 top-1/2 -translate-y-1/2 text-surface-400 pointer-events-none">
                                {searching ? (
                                    <div className="w-4 h-4 border-2 border-brand-400 border-t-transparent rounded-full animate-spin" />
                                ) : (
                                    <svg
                                        className="w-4 h-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={2}
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
                                        />
                                    </svg>
                                )}
                            </div>
                            <input
                                ref={searchRef}
                                type="search"
                                placeholder="Search by name, SKU or scan barcode…"
                                value={searchInput}
                                onChange={(e) => handleSearch(e.target.value)}
                                className="input pl-10 pr-10 bg-white border-surface-300 focus:border-brand-400 shadow-sm"
                                autoFocus={window.innerWidth >= 640}
                            />
                            {searchInput && (
                                <button
                                    onClick={() => {
                                        setSearchInput("");
                                        setSearchQuery("");
                                    }}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-700 transition-colors"
                                    aria-label="Close"
                                >
                                    <svg
                                        className="w-4 h-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={2}
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </button>
                            )}
                        </div>

                        {!searchQuery && categories.length > 0 && (
                            <div className="flex gap-1.5 overflow-x-auto no-scrollbar pb-0.5">
                                <button
                                    onClick={() => setCategoryFilter(null)}
                                    className={clsx(
                                        "shrink-0 px-3 py-1.5 rounded-full text-xs font-medium border transition-all",
                                        categoryFilter === null
                                            ? "bg-brand-500 text-white border-brand-500"
                                            : "bg-white text-surface-600 border-surface-200 hover:border-brand-300",
                                    )}
                                >
                                    All
                                </button>
                                {categories.map((cat) => (
                                    <button
                                        key={cat.id}
                                        onClick={() =>
                                            setCategoryFilter(
                                                cat.id === categoryFilter
                                                    ? null
                                                    : cat.id,
                                            )
                                        }
                                        className={clsx(
                                            "shrink-0 px-3 py-1.5 rounded-full text-xs font-medium border transition-all whitespace-nowrap",
                                            categoryFilter === cat.id
                                                ? "bg-brand-500 text-white border-brand-500"
                                                : "bg-white text-surface-600 border-surface-200 hover:border-brand-300",
                                        )}
                                    >
                                        {cat.name}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Product grid */}
                    <div className="flex-1 overflow-y-auto p-4">
                        {loadingProducts ? (
                            <div className="flex items-center justify-center h-48">
                                <Spinner size="lg" />
                            </div>
                        ) : products.length === 0 ? (
                            <div className="flex flex-col items-center justify-center h-48 gap-3 text-surface-400">
                                <svg
                                    className="w-14 h-14"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={0.8}
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"
                                    />
                                </svg>
                                <p className="text-sm font-medium">
                                    {searchQuery
                                        ? "No products match your search"
                                        : "No products available"}
                                </p>
                                {searchQuery && (
                                    <p className="text-xs">
                                        Try a different name or SKU
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div
                                className="grid gap-3"
                                style={{
                                    gridTemplateColumns:
                                        "repeat(auto-fill, minmax(130px, 1fr))",
                                }}
                            >
                                {products.map((p) => (
                                    <ProductCard
                                        key={p.id}
                                        product={p}
                                        onAdd={handleProductClick}
                                        currency={effectiveCurrency}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* RIGHT: Cart - strict 3-zone layout: header, scroll-items, fixed-footer */}
                <div
                    className={clsx(
                        "flex flex-col overflow-hidden bg-white rounded-xl border border-surface-200 shadow-sm",
                        "w-full sm:w-[300px] xl:w-[320px] sm:shrink-0",
                        mobilePanel === "products" ? "hidden sm:flex" : "flex",
                    )}
                >
                    {/* ZONE 1 - Header: order title + customer search (fixed, never scrolls) */}
                    <div className="shrink-0 border-b border-surface-100">
                        {/* Title row */}
                        <div className="px-3 py-2.5 flex items-center justify-between">
                            <div className="flex items-center gap-1.5">
                                <span className="font-bold text-surface-900 text-sm">Order</span>
                                {cartQty > 0 && (
                                    <span className="bg-brand-500 text-white text-2xs font-bold rounded-full w-4 h-4 flex items-center justify-center">{cartQty > 99 ? "99+" : cartQty}</span>
                                )}
                                {hasMto && (
                                    <span className="flex items-center gap-0.5 text-2xs bg-purple-100 text-purple-700 font-bold px-1.5 py-0.5 rounded-full">
                                        <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                                        MTO
                                    </span>
                                )}
                                {raisedProdOrders.length > 0 && (
                                    <span className="text-2xs text-success font-semibold">✓ Orders raised</span>
                                )}
                            </div>
                            {cart.length > 0 && (
                                <button onClick={clearCart} className="text-2xs text-surface-400 hover:text-danger transition-colors">Clear</button>
                            )}
                        </div>
                        {/* Customer search - compact */}
                        <div className="px-3 pb-2">
                            <CustomerSearchPanel
                                attached={attachedCustomer}
                                onAttach={setAttachedCustomer}
                                onClear={() => setAttachedCustomer(null)}
                            />
                        </div>
                        {/* Country picker - for international POS orders */}
                        <div className="px-3 pb-2.5">
                            <CountryPickerPanel
                                value={customerCountryCode}
                                onChange={code => {
                                    setCustomerCountryCode(code);
                                    // If the cart order has already been created, reset it so
                                    // a new one is raised with the correct country/currency.
                                    if (pendingOrderId) {
                                        setPendingOrderId(null);
                                        setPendingOrderData(null);
                                        setPendingOrderCartSig("");
                                        setIsResumedOrder(false);
                                    }
                                }}
                                countries={allCountries}
                                activeCurrencies={activeCurrencies}
                                homeCountry={HOME_COUNTRY}
                            />
                        </div>
                    </div>

                    {/* ZONE 2 - Items scroll area (flex-1, all overflow here) */}
                    <div className="flex-1 min-h-0 overflow-y-auto">
                        {cart.length === 0 ? (
                            <div className="flex flex-col items-center justify-center h-full gap-3 text-surface-300 p-6">
                                <svg className="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={0.75}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"/>
                                </svg>
                                <div className="text-center">
                                    <p className="text-sm font-medium text-surface-400">Tap a product to add</p>
                                    <p className="text-xs text-surface-300 mt-0.5">Your order appears here</p>
                                </div>
                            </div>
                        ) : (
                            cart.map((item, idx) => (
                                <CartRow
                                    key={`${item.variant_id}-${idx}`}
                                    item={item}
                                    index={idx}
                                    onQty={updateQty}
                                    onRemove={removeItem}
                                    onDiscount={updateDiscount}
                                    onProduction={updateProduction}
                                    onMeasurement={updateMeasurement}
                                    onOpenMeasurements={openMeasurementSheet}
                                    onPriceOverride={updatePriceOverride}
                                    currency={effectiveCurrency}
                                />
                            ))
                        )}
                    </div>

                    {/* ZONE 3 - Totals + charge (fixed footer, never scrolls, always visible) */}
                    {cart.length > 0 && (
                        <div className="shrink-0 border-t border-surface-100 bg-white">
                            {/* Compact totals row */}
                            <div className="px-3 pt-2.5 pb-1 space-y-1">
                                {/* Cart-level discount control */}
                                <div className="flex items-center gap-2">
                                    <span className="text-2xs text-surface-400 flex-1">Order discount</span>
                                    <select
                                        value={cartDiscType}
                                        onChange={(e) => setCartDiscType(e.target.value as "none"|"flat"|"percent")}
                                        className="text-2xs border border-surface-200 rounded px-1.5 py-0.5 bg-white focus:outline-none focus:border-brand-400"
                                    >
                                        <option value="none">None</option>
                                        <option value="flat">Flat {effectiveCurrency}</option>
                                        <option value="percent">%</option>
                                    </select>
                                    {cartDiscType !== "none" && (
                                        <input
                                            type="number" min={0}
                                            value={cartDiscVal}
                                            onChange={(e) => setCartDiscVal(parseFloat(e.target.value) || 0)}
                                            className="w-16 text-2xs border border-surface-200 rounded px-1.5 py-0.5 focus:outline-none focus:border-brand-400"
                                        />
                                    )}
                                </div>

                                {/* Line items */}
                                <div className="flex justify-between text-2xs text-surface-500">
                                    <span>Subtotal</span>
                                    <span className="tabular-nums">{effectiveCurrency} {fmt(totals.subtotal)}</span>
                                </div>
                                {totals.discountAmount > 0 && (
                                    <div className="flex justify-between text-2xs text-warning-dark">
                                        <span>Discount</span>
                                        <span className="tabular-nums">-{effectiveCurrency} {fmt(totals.discountAmount)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between text-2xs text-surface-500">
                                    <span>Tax{taxInclusive ? " (incl.)" : ""}</span>
                                    <span className="tabular-nums">{taxInclusive ? "incl. " : ""}{effectiveCurrency} {fmt(totals.taxAmount)}</span>
                                </div>

                                {/* Shipping - compact toggle */}
                                <div className="flex justify-between text-2xs">
                                    <button
                                        onClick={() => setShowShippingPanel(v => !v)}
                                        className={clsx(
                                            "flex items-center gap-1 transition-colors",
                                            selectedShippingMethod ? "text-brand-600 font-medium" : "text-surface-400 hover:text-surface-600",
                                        )}
                                    >
                                        <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                                        {selectedShippingMethod
                                            ? <span className="truncate max-w-[120px]">{selectedShippingMethod.name}</span>
                                            : "Add shipping"}
                                        <svg className={clsx("w-2.5 h-2.5 shrink-0 transition-transform", showShippingPanel && "rotate-180")} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                    </button>
                                    {selectedShippingMethod && !showShippingPanel && shippingFeeFromMethod > 0 && (
                                        <span className="text-brand-600 font-medium tabular-nums">{effectiveCurrency} {fmt(shippingFeeFromMethod)}</span>
                                    )}
                                </div>

                                {/* Shipping panel - opens inline above the charge button */}
                                {showShippingPanel && (
                                    <div className="space-y-1 pt-0.5">
                                        {shippingMethods.length === 0 ? (
                                            <p className="text-2xs text-surface-400 italic">No shipping methods configured.</p>
                                        ) : (
                                            <>
                                                <button
                                                    onClick={() => { setSelectedShippingId(null); setShippingAddress(""); setShowShippingPanel(false); }}
                                                    className={clsx("w-full text-left text-2xs px-2 py-1.5 rounded-lg border transition-all",
                                                        !selectedShippingId ? "border-brand-300 bg-brand-50 text-brand-700" : "border-surface-100 text-surface-500 hover:border-surface-200")}
                                                >
                                                    No shipping / collect in-store
                                                </button>
                                                {shippingMethods.map(m => (
                                                    <button key={m.id}
                                                        onClick={() => { setSelectedShippingId(m.id); setShowShippingPanel(false); }}
                                                        className={clsx("w-full flex items-center justify-between text-2xs px-2 py-1.5 rounded-lg border transition-all",
                                                            selectedShippingId === m.id ? "border-brand-300 bg-brand-50 text-brand-700" : "border-surface-100 text-surface-600 hover:border-surface-200")}
                                                    >
                                                        <div className="min-w-0">
                                                            <p className="font-medium truncate">{m.name}</p>
                                                            {m.delivery_time && <p className="text-surface-400">{m.delivery_time}</p>}
                                                        </div>
                                                        <span className={clsx("font-bold shrink-0 ml-2", m.cost_type === "free" ? "text-success" : "")}>
                                                            {m.cost_type === "free" ? "Free" : `${effectiveCurrency} ${fmt(m.flat_rate)}`}
                                                        </span>
                                                    </button>
                                                ))}
                                            </>
                                        )}
                                        {selectedShippingId && (
                                            <input type="text" placeholder="Delivery address (optional)"
                                                value={shippingAddress} onChange={e => setShippingAddress(e.target.value)}
                                                className="input text-2xs py-1" />
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Total + charge button */}
                            <div className="px-3 pb-3">

                                {/* Resume banner - pending order exists from a previous session */}
                                {pendingOrderId && !showPaymentModal && !resumeBannerDismissed && (
                                    <div className="mb-2 rounded-xl bg-brand-50 border border-brand-200 px-3 py-2 text-2xs text-brand-700 flex items-center gap-2">
                                        <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                        <span className="flex-1">
                                            <span className="font-semibold">Order saved</span>
                                            {pendingOrderData?.order_number && (
                                                <> · #{pendingOrderData.order_number}</>
                                            )}
                                            {isResumedOrder
                                                ? " — press Make Payment to take payment"
                                                : " — press Save & Pay to take payment"}
                                        </span>
                                        <button
                                            onClick={() => setResumeBannerDismissed(true)}
                                            className="shrink-0 text-brand-400 hover:text-brand-700 transition-colors"
                                            aria-label="Dismiss"
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                )}

                                {/* Cart-mismatch warning - cart items changed after order was created */}
                                {pendingOrderId && !showPaymentModal && !mismatchBannerDismissed && pendingOrderCartSig !== "" && (
                                    cartSignature(cart) !== pendingOrderCartSig
                                ) && (
                                    <div className="mb-2 rounded-xl bg-warning-light border border-warning/40 px-3 py-2 text-2xs text-warning-dark flex items-start gap-2">
                                        <svg className="w-3.5 h-3.5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                                        <span className="flex-1">
                                            {isResumedOrder
                                                ? "Cart has changed. Pressing Save & Pay will update the existing order with the new items."
                                                : "Cart items have changed. Pressing Save & Pay will create a new order and release the old one."}
                                        </span>
                                        <button
                                            onClick={() => setMismatchBannerDismissed(true)}
                                            className="shrink-0 text-warning-dark/60 hover:text-warning-dark transition-colors mt-0.5"
                                            aria-label="Dismiss"
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                )}

                                <div className="flex justify-between items-center mb-2">
                                    <span className="font-bold text-surface-900 text-sm">Total</span>
                                    <div className="text-right">
                                        <span className="text-lg font-bold text-brand-600 tabular-nums">{effectiveCurrency} {fmt(totals.total)}</span>
                                        {isInternationalPosOrder && (
                                            <div className="flex items-center gap-1 justify-end mt-0.5">
                                                <span className="text-2xs font-semibold text-blue-600 flex items-center gap-0.5">
                                                    🌐 International order
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Action buttons — stacked on mobile, side-by-side on sm+ */}
                                <div className="flex flex-col sm:flex-row gap-2">
                                {/* Save & Pay — creates/resumes the order then opens the payment modal */}
                                <button
                                    onClick={() => { navigator.vibrate?.(40); handleOpenPayment(); }}
                                    disabled={pendingOrderMutation.isPending || payMutation.isPending || saveOrderMutation.isPending}
                                    className="btn-primary w-full py-4 sm:py-3 text-sm font-bold gap-2 rounded-xl shadow-sm shadow-brand-200 active:scale-[0.98] transition-all tap-target"
                                >
                                    {pendingOrderMutation.isPending ? (
                                        <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                    ) : (
                                        <>
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
                                            {pendingOrderId && !showPaymentModal
                                                ? (isResumedOrder ? "Make Payment" : "Resume Payment")
                                                : "Save & Pay"}
                                        </>
                                    )}
                                </button>

                                {/* Save Order — creates a pending order without opening payment */}
                                <button
                                    onClick={() => { navigator.vibrate?.(20); handleSaveOrder(); }}
                                    disabled={pendingOrderMutation.isPending || payMutation.isPending || saveOrderMutation.isPending}
                                    className="btn-secondary w-full py-2.5 sm:py-2 text-sm font-semibold gap-2 rounded-xl active:scale-[0.98] transition-all"
                                >
                                    {saveOrderMutation.isPending ? (
                                        <div className="w-4 h-4 border-2 border-surface-400 border-t-transparent rounded-full animate-spin" />
                                    ) : (
                                        <>
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M17 16v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2m9-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            Save Order
                                        </>
                                    )}
                                </button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Modals ──────────────────────────────────────────────────────── */}
            {measurementSheetIdx >= 0 && cart[measurementSheetIdx] && (
                <MeasurementSheet
                    item={cart[measurementSheetIdx]}
                    index={measurementSheetIdx}
                    onProduction={updateProduction}
                    onMeasurement={updateMeasurement}
                    onClose={closeMeasurementSheet}
                />
            )}

            {variantPicker && (
                <VariantPicker
                    product={variantPicker}
                    onSelect={(v) => addToCart(variantPicker, v)}
                    onClose={() => setVariantPicker(null)}
                    currency={effectiveCurrency}
                />
            )}

            {showPaymentModal && (
                <PaymentModal
                    total={totals.total}
                    currency={effectiveCurrency}
                    orderId={pendingOrderId ?? 0}
                    configuredMethods={configuredMethodsForCurrency}
                    onCharge={handleCharge}
                    onStkComplete={handleStkComplete}
                    onClose={() => {
                        // Simply close the modal - the pending order stays alive.
                        // The cashier can reopen it by pressing "Charge" again.
                        // The order is only voided when the cashier explicitly clears the cart.
                        setShowPaymentModal(false);
                    }}
                    isProcessing={payMutation.isPending}
                    isCreatingOrder={pendingOrderMutation.isPending}
                    taxInclusive={taxInclusive}
                    taxAmount={totals.taxAmount}
                />
            )}

            {receiptSale && (
                <ReceiptModal
                    sale={receiptSale}
                    outlet={selectedOutlet!}
                    requiresApproval={receiptNeedsApproval}
                    onClose={() => {
                        setReceiptSale(null);
                        setReceiptNeedsApproval(false);
                        setShowPaymentModal(false);
                    }}
                />
            )}

            {showRegisterModal && (
                <RegisterModal
                    outlets={outlets}
                    defaultOutletId={selectedOutletId}
                    mode={registerOpen ? "close" : "open"}
                    register={register}
                    eodSubmitted={eodSubmitted}
                    onClose={() => setShowRegisterModal(false)}
                    onSuccess={() => {
                        setShowRegisterModal(false);
                        refetchRegister();
                    }}
                    onRequestEod={() => {
                        setShowRegisterModal(false);
                        setShowEod(true);
                    }}
                />
            )}

            {showHistory && selectedOutletId && (
                <SalesHistoryDrawer
                    outletId={selectedOutletId}
                    outletName={selectedOutlet?.name ?? ""}
                    onClose={() => setShowHistory(false)}
                    onRestoreCart={(sale) => {
                        // ── 1. Rebuild cart items from the saved order ─────────────────
                        const restoredCart: ExtCartItem[] = sale.items.map((item) => {
                            // FIX 1: use persisted discount_type/value; fall back to flat
                            // heuristic for orders created before the migration.
                            const hasTypedDiscount = item.discount_type && item.discount_type !== "none";
                            const discountType  = hasTypedDiscount
                                ? item.discount_type
                                : (item.discount_amount > 0 ? "flat" as const : "none" as const);
                            const discountValue = hasTypedDiscount
                                ? (item.discount_value ?? item.discount_amount)
                                : item.discount_amount;

                            // FIX 4 + screenshot: restore measurement values.
                            // Post-migration: measurement_values is a JSON object — use directly.
                            // Pre-migration: parse them back out of the concatenated notes string
                            // so individual inputs are populated, not a single text blob.
                            const hasPersisted = item.measurement_values &&
                                Object.keys(item.measurement_values).length > 0;
                            const measurementValues: Record<string, string> = hasPersisted
                                ? (item.measurement_values as Record<string, string>)
                                : parseMeasurementsFromNotes(item.production_notes ?? "");

                            // Strip the measurement prefix from production_notes for pre-migration
                            // orders (it was concatenated inline as "Field: val, ... | extra notes").
                            const productionNotes = (() => {
                                const raw = item.production_notes ?? "";
                                if (hasPersisted) return raw;
                                return raw.includes(" | ") ? raw.split(" | ").slice(1).join(" | ") : "";
                            })();

                            return {
                                variant_id:         item.variant_id,
                                product_id:         (item as any).product_id ?? 0,
                                product_name:       item.product_name,
                                variant_name:       item.variant_name,
                                sku:                item.sku,
                                price:              item.unit_price,
                                                original_price:     (item as any).original_price ?? null,
                                                price_adjusted:     (item as any).price_adjusted ?? false,
                                quantity:           item.quantity,
                                discount_type:      discountType,
                                discount_value:     discountValue,
                                image_url:          null, // rehydrated below from catalogue
                                tax_rate:           item.tax_rate ?? 0,
                                tax_name:           item.tax_name ?? null,
                                is_production:      (item as any).is_production ?? false,
                                measurement_fields: undefined as any, // rehydrated below
                                measurement_values: measurementValues,
                                production_notes:   productionNotes,
                            };
                        });

                        setCart(restoredCart);

                        // ── 2. Async rehydration from the product catalogue ────────────
                        // Restores measurement_fields schema (for MTO sheet) and image_url.
                        if (selectedOutletId) {
                            const skus = restoredCart.map(i => i.sku).filter(Boolean).join(" ");
                            if (skus) {
                                get<{ data: PosProduct[] }>(
                                    "/v1/admin/pos/products/search",
                                    { params: { q: skus, outlet_id: String(selectedOutletId) } },
                                ).then((res) => {
                                    const fresh: PosProduct[] = res.data ?? [];
                                    setCart(prev => prev.map(ci => {
                                        const matched = fresh.find(p =>
                                            p.sku === ci.sku ||
                                            p.variants.some(v => v.sku === ci.sku)
                                        );
                                        if (!matched) return ci;
                                        const patch: Partial<ExtCartItem> = {
                                            image_url: matched.image_url ?? ci.image_url,
                                        };
                                        if (matched.is_producible && matched.measurements?.length) {
                                            patch.measurement_fields = matched.measurements;
                                        }
                                        return { ...ci, ...patch };
                                    }));
                                }).catch(() => { /* silently ignore */ });
                            }
                        }

                        // ── 3. Restore cart-level discount (FIX 2) ────────────────────
                        const cartDiscountType  = (sale as any).cart_discount_type  ?? "none";
                        const cartDiscountValue = (sale as any).cart_discount_value ?? 0;
                        if (cartDiscountType !== "none" && cartDiscountValue > 0) {
                            setCartDiscType(cartDiscountType);
                            setCartDiscVal(cartDiscountValue);
                        } else {
                            setCartDiscType("none");
                            setCartDiscVal(0);
                        }

                        // ── 4. Restore attached customer (FIX 3) ──────────────────────
                        if (sale.customer_name || (sale as any).customer_id) {
                            const nameParts = (sale.customer_name ?? "").trim().split(" ");
                            setAttachedCustomer({
                                id:         (sale as any).customer_id ?? 0,
                                first_name: nameParts[0] ?? "",
                                last_name:  nameParts.slice(1).join(" "),
                                phone:      sale.customer_phone ?? undefined,
                                email:      sale.customer_email ?? undefined,
                            });
                        } else {
                            setAttachedCustomer(null);
                        }

                        // ── 5. Mark as resumed and reattach the order ─────────────────
                        setIsResumedOrder(true);

                        // Un-dismiss so this specific order can be actively worked on.
                        // Removes only this ID — must NOT clear other dismissed orders
                        // that were saved earlier in the same session.
                        removeDismissedPendingOrderId(sale.id);

                        setPendingOrderId(sale.id);
                        setPendingOrderData({
                            order_id:     sale.id,
                            order_number: sale.order_number,
                            total_amount: sale.total,
                            order:        sale,
                        });
                        setPendingOrderCartSig(cartSignature(restoredCart));

                        // Reset banner dismiss so the resume banner shows for this order.
                        setResumeBannerDismissed(false);
                        setMismatchBannerDismissed(false);

                        setShowHistory(false);
                        toast.success(`Order ${sale.order_number} restored — you can add items or press Charge to pay.`);
                    }}
                />
            )}

            {showEod && selectedOutletId && register && (
                <UserEodModal
                    outletId={selectedOutletId}
                    outletName={selectedOutlet?.name ?? ""}
                    registerId={register.id}
                    onClose={() => setShowEod(false)}
                    onSubmitSuccess={() => {
                        setEodSubmitted(true);
                        setShowEod(false);
                        // Refetch register status so the server-side flag is synced
                        refetchRegister();
                    }}
                />
            )}

            {showReturns && selectedOutletId && (
                <PosReturnsModal
                    outletId={selectedOutletId}
                    outletName={selectedOutlet?.name ?? ""}
                    onClose={() => setShowReturns(false)}
                />
            )}
        </div>
    );
}