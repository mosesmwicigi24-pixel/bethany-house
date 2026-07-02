/**
 * ReceiptModal.tsx
 *
 * Two modes, one component:
 *
 * RECEIPT mode  - narrow thermal receipt (80mm), shown only when the order is
 *                 fully paid. Features: item codes, qty×price layout, tax
 *                 breakdown table (S/Z codes), M-PESA TRN details, cash
 *                 tendered/change, amount in words, savings line, item count,
 *                 cashier name, QR code and KRA ETR block.
 *
 * INVOICE mode  - full A4-style document with an itemised payments table
 *                 showing each payment's method, amount, reference, and
 *                 approval status. Always available, including for orders
 *                 with pending-approval payments.
 *
 * The parent (PosPage) controls which is shown:
 *   - After a fully paid automated sale → receipt mode
 *   - After a pending-approval submission → invoice mode only
 *   - User can always switch to invoice mode from the receipt header
 */

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { posApi } from "@/api/pos";
import type { PosSale, Outlet } from "@/api/pos";
import { useToastStore } from "@/store/toast.store";
import { clsx } from "clsx";

interface Props {
    sale: PosSale;
    outlet: Outlet;
    onClose: () => void;
    /** When true the receipt tab is hidden - order has pending-approval payments */
    requiresApproval?: boolean;
}

// ── Formatting helpers ────────────────────────────────────────────────────────

const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });
const DASH32 = "─".repeat(32);
const DASH40 = "─".repeat(40);

/** Convert a number to English words (KES amounts) */
function numberToWords(amount: number): string {
    const ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine",
        "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen",
        "Eighteen", "Nineteen"];
    const tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];

    function below1000(n: number): string {
        if (n === 0) return "";
        if (n < 20) return ones[n];
        if (n < 100) return tens[Math.floor(n / 10)] + (n % 10 ? " " + ones[n % 10] : "");
        return ones[Math.floor(n / 100)] + " Hundred" + (n % 100 ? " And " + below1000(n % 100) : "");
    }

    const intPart = Math.floor(amount);
    const centsPart = Math.round((amount - intPart) * 100);

    if (intPart === 0 && centsPart === 0) return "Zero Shillings Only";

    let words = "";
    if (intPart >= 1_000_000) {
        words += below1000(Math.floor(intPart / 1_000_000)) + " Million ";
    }
    if (intPart >= 1_000) {
        words += below1000(Math.floor((intPart % 1_000_000) / 1_000)) + " Thousand ";
    }
    words += below1000(intPart % 1_000);
    words = words.trim();
    words += " Shilling" + (intPart !== 1 ? "s" : "");
    if (centsPart > 0) {
        words += " And " + below1000(centsPart) + " Cent" + (centsPart !== 1 ? "s" : "");
    }
    return words.trim() + " Only";
}

/** Group items by tax code to build the tax breakdown table */
function buildTaxBreakdown(items: PosSale["items"]) {
    const groups: Record<string, { label: string; vatable: number; vat: number }> = {};
    for (const item of items) {
        const rate = (item as any).tax_rate ?? 0;
        const code = rate > 0 ? "S" : "Z";
        const label = rate > 0 ? `Standard Rated (${rate}%)` : "Zero Rated";
        if (!groups[code]) groups[code] = { label, vatable: 0, vat: 0 };
        // If prices include tax, back-calculate the vatable amount
        const taxAmt = (item as any).tax_amount ?? 0;
        const subtotal = (item as any).subtotal ?? 0;
        groups[code].vatable += subtotal - taxAmt;
        groups[code].vat += taxAmt;
    }
    return Object.entries(groups).map(([code, g]) => ({ code, ...g }));
}

/** Format M-PESA phone for display: 2547XXXX453 */
function maskPhone(phone?: string | null): string {
    if (!phone) return "";
    const digits = phone.replace(/\D/g, "");
    if (digits.length < 6) return digits;
    return digits.slice(0, 4) + "XXXX" + digits.slice(-3);
}

// ── QR Code canvas hook ───────────────────────────────────────────────────────

function QRCodeCanvas({ value, size = 80 }: { value: string; size?: number }) {
    // Uses the QR Server API - no npm package required.
    const src = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&margin=1&data=${encodeURIComponent(value)}`;
    return (
        <img
            src={src}
            alt="QR Code"
            width={size}
            height={size}
            style={{ imageRendering: "pixelated" }}
            onError={e => { (e.target as HTMLImageElement).style.display = "none"; }}
        />
    );
}

// ── Payment status badge (Invoice mode) ──────────────────────────────────────

function paymentStatusBadge(status: string, approvalStatus?: string | null) {
    if (approvalStatus === "pending_review")
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-semibold bg-amber-100 text-amber-800">⏳ Pending Approval</span>;
    if (approvalStatus === "rejected")
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-semibold bg-red-100 text-red-800">✕ Rejected</span>;
    if (status === "paid" || approvalStatus === "approved")
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-semibold bg-green-100 text-green-800">✓ Paid</span>;
    if (status === "pending")
        return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-semibold bg-surface-100 text-surface-600">Pending</span>;
    return <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-semibold bg-surface-100 text-surface-500">{status}</span>;
}

// ── Thermal Receipt ───────────────────────────────────────────────────────────

function ThermalReceipt({ sale, outlet }: { sale: PosSale; outlet: Outlet }) {
    const payments: any[] = (sale as any).payments ?? [];
    const taxBreakdown = buildTaxBreakdown(sale.items);
    const totalItems = sale.items.reduce((s, i) => s + i.quantity, 0);
    const currencyCode = (sale as any).currency_code ?? "KES";

    // Cash payment details
    const cashPayment = payments.find((p: any) => p.payment_method === "cash");
    const mpesaPayments = payments.filter((p: any) =>
        p.payment_method === "mpesa" || p.payment_method === "m_pesa"
    );
    const hasSplitPayment = payments.length > 1;

    // Savings = sum of item discounts
    const totalSavings = sale.items.reduce(
        (s, i) => s + (i.discount_amount ?? 0),
        sale.discount_amount ?? 0
    );

    // QR value - order number + outlet for KRA-style scanning
    const qrValue = `${sale.order_number}|${outlet.name}|${fmt(sale.total)}|${new Date(sale.created_at).toISOString()}`;

    return (
        <div
            id="pos-receipt"
            className="p-4 font-mono text-black space-y-2"
            style={{
                fontFamily: "'Courier New', Courier, monospace",
                fontSize: "11px",
                lineHeight: "1.4",
                width: "100%",
                maxWidth: "80mm",
                margin: "0 auto",
            }}
        >
            {/* ── Header ── */}
            <div style={{ textAlign: "center" }}>
                <p style={{ fontSize: "14px", fontWeight: "bold", letterSpacing: "0.1em", textTransform: "uppercase", margin: 0 }}>
                    Bethany House
                </p>
                <p style={{ fontWeight: "bold", margin: "2px 0 0" }}>{outlet.name}</p>
                {outlet.address && <p style={{ margin: "1px 0 0" }}>{outlet.address}</p>}
                {outlet.phone && <p style={{ margin: "1px 0 0" }}>Tel: {outlet.phone}</p>}
            </div>

            <p style={{ textAlign: "center", margin: "4px 0", borderTop: "1px dashed #000", borderBottom: "1px dashed #000", padding: "2px 0" }}>
                Sales Receipt
            </p>

            {/* ── Transaction info ── */}
            <div style={{ fontSize: "10px" }}>
                <div style={{ display: "flex", justifyContent: "space-between" }}>
                    <span>Date:</span>
                    <span>{new Date(sale.created_at).toLocaleString("en-KE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}</span>
                </div>
                {(sale as any).register_number && (
                    <div style={{ display: "flex", justifyContent: "space-between" }}>
                        <span>Register:</span>
                        <span>{(sale as any).register_number}</span>
                    </div>
                )}
                <div style={{ display: "flex", justifyContent: "space-between" }}>
                    <span>Receipt #:</span>
                    <span style={{ fontWeight: "bold" }}>{sale.order_number}</span>
                </div>
                {sale.customer_name && (
                    <div style={{ display: "flex", justifyContent: "space-between" }}>
                        <span>Customer:</span>
                        <span>{sale.customer_name}</span>
                    </div>
                )}
                {sale.cashier_name && (
                    <div style={{ display: "flex", justifyContent: "space-between" }}>
                        <span>Cashier:</span>
                        <span>{sale.cashier_name}</span>
                    </div>
                )}
            </div>

            <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "4px 0 2px" }} />

            {/* ── Items ── */}
            {/* Column headers */}
            <div style={{ display: "flex", fontSize: "9px", fontWeight: "bold", borderBottom: "1px solid #000", paddingBottom: "2px", marginBottom: "2px" }}>
                <span style={{ flex: "0 0 28px" }}>CODE</span>
                <span style={{ flex: 1 }}>DESCRIPTION</span>
                <span style={{ flex: "0 0 24px", textAlign: "center" }}>QTY</span>
                <span style={{ flex: "0 0 24px", textAlign: "center" }}>PRC</span>
                <span style={{ flex: "0 0 50px", textAlign: "right" }}>EXTENDED</span>
            </div>

            <div style={{ marginBottom: "4px" }}>
                {sale.items.map((item, i) => {
                    const sku = (item as any).sku ?? String(i + 1).padStart(3, "0");
                    const taxRate = (item as any).tax_rate ?? 0;
                    const taxCode = taxRate > 0 ? "S" : "Z";
                    return (
                        <div key={i} style={{ marginBottom: "4px" }}>
                            {/* Item row */}
                            <div style={{ display: "flex", alignItems: "flex-start" }}>
                                <span style={{ flex: "0 0 28px", fontSize: "9px", color: "#555" }}>{sku.slice(-6)}</span>
                                <span style={{ flex: 1, wordBreak: "break-word" }}>{item.product_name}{item.variant_name ? ` (${item.variant_name})` : ""}</span>
                                <span style={{ flex: "0 0 24px", textAlign: "center" }}>{item.quantity}</span>
                                <span style={{ flex: "0 0 24px", textAlign: "center" }}>{taxCode}</span>
                                <span style={{ flex: "0 0 50px", textAlign: "right" }}>{fmt(item.subtotal)}</span>
                            </div>
                            {/* Price detail line */}
                            <div style={{ paddingLeft: "28px", fontSize: "9px", color: "#555" }}>
                                {item.quantity} × {fmt(item.unit_price)}
                                {item.discount_amount > 0 && ` · DISC: -${fmt(item.discount_amount)}`}
                            </div>
                        </div>
                    );
                })}
            </div>

            <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "2px 0" }} />

            {/* ── Totals ── */}
            <div style={{ fontSize: "11px" }}>
                <div style={{ display: "flex", justifyContent: "space-between" }}>
                    <span>Subtotal</span><span>{fmt(sale.subtotal)}</span>
                </div>
                {sale.discount_amount > 0 && (
                    <div style={{ display: "flex", justifyContent: "space-between" }}>
                        <span>Discount</span><span>-{fmt(sale.discount_amount)}</span>
                    </div>
                )}
                {sale.tax_amount > 0 && (
                    <div style={{ display: "flex", justifyContent: "space-between" }}>
                        <span>Tax{(sale as any).prices_include_tax ? " (incl.)" : ""}</span>
                        <span>{fmt(sale.tax_amount)}</span>
                    </div>
                )}
                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", fontSize: "13px", borderTop: "1px dashed #000", marginTop: "3px", paddingTop: "3px" }}>
                    <span>Totals</span>
                    <span>{fmt(sale.total)}</span>
                </div>
            </div>

            <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "2px 0" }} />

            {/* ── Payment section ── */}
            <div style={{ fontSize: "11px" }}>
                {/* Split payments */}
                {hasSplitPayment ? (
                    <>
                        {payments.map((p: any, i: number) => (
                            <div key={i}>
                                <div style={{ display: "flex", justifyContent: "space-between" }}>
                                    <span style={{ textTransform: "uppercase" }}>{(p.payment_method ?? "other").replace(/_/g, " ")}</span>
                                    <span>{fmt(Number(p.amount))}</span>
                                </div>
                                {(p.payment_method === "mpesa" || p.payment_method === "m_pesa") && p.provider_reference && (
                                    <div style={{ paddingLeft: "8px", fontSize: "9px", color: "#555" }}>
                                        <div>TRN #: {p.provider_reference}</div>
                                        {(sale as any).customer_phone && (
                                            <div>Cell: {maskPhone((sale as any).customer_phone)}</div>
                                        )}
                                        <div>Amount: {fmt(Number(p.amount))}</div>
                                    </div>
                                )}
                                {p.payment_method === "cash" && (
                                    <>
                                        {(sale as any).cash_received && (sale as any).cash_received > 0 && (
                                            <div style={{ paddingLeft: "8px", fontSize: "10px" }}>
                                                <div style={{ display: "flex", justifyContent: "space-between" }}>
                                                    <span>Tendered</span><span>{fmt((sale as any).cash_received)}</span>
                                                </div>
                                                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold" }}>
                                                    <span>Change</span><span>{fmt((sale as any).change_given ?? 0)}</span>
                                                </div>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        ))}
                    </>
                ) : (
                    /* Single payment */
                    <>
                        {payments[0] && (
                            <>
                                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold" }}>
                                    <span>Tendered</span>
                                    <span>{fmt(sale.total)}</span>
                                </div>
                                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold" }}>
                                    <span>Change</span>
                                    <span>{fmt((sale as any).change_given ?? 0)}</span>
                                </div>
                                <div style={{ display: "flex", justifyContent: "space-between", marginTop: "2px" }}>
                                    <span style={{ textTransform: "uppercase" }}>{(payments[0].payment_method ?? "").replace(/_/g, " ")}</span>
                                    <span>{fmt(sale.total)}</span>
                                </div>
                            </>
                        )}
                        {!payments[0] && (sale as any).payment_method && (
                            <div style={{ display: "flex", justifyContent: "space-between" }}>
                                <span style={{ textTransform: "uppercase" }}>{((sale as any).payment_method ?? "").replace(/_/g, " ")}</span>
                                <span>{fmt(sale.total)}</span>
                            </div>
                        )}
                    </>
                )}

                {/* M-PESA details block (single mpesa payment) */}
                {!hasSplitPayment && mpesaPayments.length === 1 && mpesaPayments[0].provider_reference && (
                    <>
                        <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "4px 0" }} />
                        <div style={{ textAlign: "center", fontSize: "10px", marginBottom: "2px" }}>
                            ----Mobile Payment Information----
                        </div>
                        <div style={{ fontSize: "10px" }}>
                            <div style={{ display: "flex", justifyContent: "space-between" }}>
                                <span>TRN #</span>
                                <span style={{ fontWeight: "bold" }}>{mpesaPayments[0].provider_reference}</span>
                            </div>
                            {(sale as any).customer_phone && (
                                <div style={{ display: "flex", justifyContent: "space-between" }}>
                                    <span>Cell</span>
                                    <span>{maskPhone((sale as any).customer_phone)}</span>
                                </div>
                            )}
                            <div style={{ display: "flex", justifyContent: "space-between" }}>
                                <span>Amount</span>
                                <span>{fmt(sale.total)}</span>
                            </div>
                        </div>
                    </>
                )}

                {/* Cash tendered / change (single cash payment) */}
                {!hasSplitPayment && cashPayment && (sale as any).cash_received > 0 && (
                    <>
                        <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "4px 0" }} />
                        <div style={{ display: "flex", justifyContent: "space-between" }}>
                            <span>Cash Received</span>
                            <span>{currencyCode} {fmt((sale as any).cash_received)}</span>
                        </div>
                        <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold" }}>
                            <span>Change</span>
                            <span>{currencyCode} {fmt((sale as any).change_given ?? 0)}</span>
                        </div>
                    </>
                )}
            </div>

            <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "4px 0" }} />

            {/* ── Summary stats ── */}
            <div style={{ fontSize: "10px" }}>
                <div style={{ display: "flex", justifyContent: "space-between" }}>
                    <span>TOTAL ITEMS: {totalItems}</span>
                    <span>Prices incl. taxes where applicable</span>
                </div>
            </div>

            {/* ── Tax breakdown ── */}
            {taxBreakdown.length > 0 && (
                <>
                    <div style={{ fontSize: "10px", marginTop: "4px" }}>
                        <div style={{ fontWeight: "bold", marginBottom: "2px" }}>TAX DETAILS</div>
                        <div style={{ display: "flex", gap: "4px", fontWeight: "bold", borderBottom: "1px solid #000", paddingBottom: "1px", marginBottom: "2px" }}>
                            <span style={{ flex: "0 0 20px" }}>CODE</span>
                            <span style={{ flex: 1, textAlign: "right" }}>VATABLE AMT</span>
                            <span style={{ flex: "0 0 60px", textAlign: "right" }}>VAT AMT</span>
                        </div>
                        {taxBreakdown.map(({ code, vatable, vat }) => (
                            <div key={code} style={{ display: "flex", gap: "4px" }}>
                                <span style={{ flex: "0 0 20px" }}>{code}</span>
                                <span style={{ flex: 1, textAlign: "right" }}>{fmt(vatable)}</span>
                                <span style={{ flex: "0 0 60px", textAlign: "right" }}>{fmt(vat)}</span>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {/* ── Promotion / savings ── */}
            {totalSavings > 0 && (
                <>
                    <p style={{ textAlign: "center", borderBottom: "1px dashed #000", borderTop: "1px dashed #000", margin: "4px 0", padding: "2px 0", fontSize: "10px" }}>
                        =========== PROMOTION ===========
                    </p>
                    <p style={{ textAlign: "center", fontSize: "10px", fontWeight: "bold", margin: "2px 0" }}>
                        You have SAVED {currencyCode} {fmt(totalSavings)}
                    </p>
                    <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "4px 0", fontSize: "10px" }}>
                        =========== PROMOTION ===========
                    </p>
                </>
            )}

            {/* ── Amount in words ── */}
            <p style={{ fontSize: "10px", fontWeight: "bold", textAlign: "center", margin: "4px 0", textTransform: "uppercase" }}>
                {numberToWords(sale.total)}
            </p>

            {/* ── Cashier sign-off ── */}
            {sale.cashier_name && (
                <p style={{ textAlign: "center", fontSize: "10px", margin: "2px 0" }}>
                    You were served by: <strong>{sale.cashier_name.toUpperCase()}</strong>
                </p>
            )}

            <p style={{ textAlign: "center", fontSize: "10px", fontStyle: "italic", margin: "2px 0" }}>
                ---MORE SAVINGS. BETTER LIVING---
            </p>

            <p style={{ textAlign: "center", borderBottom: "1px dashed #000", margin: "4px 0" }} />

            {/* ── Footer message ── */}
            <p style={{ textAlign: "center", fontSize: "10px", lineHeight: "1.5", margin: "2px 0" }}>
                Thank you for shopping at Bethany House!<br />
                Goods once sold are not exchangeable<br />
                unless within 7 days with receipt.
            </p>

            {/* ── QR Code + KRA ETR block ── */}
            <div style={{ textAlign: "center", marginTop: "8px" }}>
                <QRCodeCanvas value={qrValue} size={80} />
                <div style={{ fontSize: "9px", marginTop: "4px", color: "#555" }}>
                    <div>Receipt No: {sale.order_number}</div>
                    {outlet.phone && <div>Outlet: {outlet.name}</div>}
                </div>
            </div>
        </div>
    );
}

// ── Full Invoice ──────────────────────────────────────────────────────────────

function FullInvoice({ sale, outlet }: { sale: PosSale; outlet: Outlet }) {
    const payments: any[] = (sale as any).payments ?? [];
    const hasPendingApproval = payments.some(
        (p: any) => p.approval_status === "pending_review"
    );

    return (
        <div
            id="pos-invoice"
            className="p-6 text-sm text-surface-900 space-y-5"
            style={{ fontFamily: "system-ui, sans-serif" }}
        >
            {/* Letterhead */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p className="text-lg font-bold tracking-tight">Bethany House</p>
                    <p className="text-surface-500 text-xs">{outlet.name}</p>
                    {outlet.address && <p className="text-surface-500 text-xs">{outlet.address}</p>}
                    {outlet.phone && <p className="text-surface-500 text-xs">Tel: {outlet.phone}</p>}
                </div>
                <div className="sm:text-right">
                    <p className="text-lg font-bold text-surface-900">INVOICE</p>
                    <p className="text-xs text-surface-500">#{sale.order_number}</p>
                    <p className="text-xs text-surface-400 mt-1">
                        {new Date(sale.created_at).toLocaleString("en-KE", { dateStyle: "long", timeStyle: "short" })}
                    </p>
                </div>
            </div>

            {/* Pending approval notice */}
            {hasPendingApproval && (
                <div className="bg-amber-50 border border-amber-300 rounded-xl px-4 py-3 text-xs text-amber-900">
                    <p className="font-bold">⏳ Payment Pending Approval</p>
                    <p className="mt-0.5">
                        This invoice has one or more payments awaiting administrator review.
                        A receipt will be issued once all payments have been approved.
                    </p>
                </div>
            )}

            {/* Bill to */}
            {sale.customer_name && (
                <div>
                    <p className="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-1">Bill To</p>
                    <p className="font-semibold">{sale.customer_name}</p>
                    {(sale as any).customer_phone && <p className="text-xs text-surface-500">{(sale as any).customer_phone}</p>}
                    {(sale as any).customer_email && <p className="text-xs text-surface-500">{(sale as any).customer_email}</p>}
                </div>
            )}

            {/* Items table */}
            <div>
                <p className="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-2">Items</p>
                <div className="overflow-x-auto">
                <table className="w-full text-xs border-collapse min-w-[400px]">
                    <thead>
                        <tr className="border-b-2 border-surface-200">
                            <th className="text-left py-1.5 font-semibold text-surface-600 w-16">Code</th>
                            <th className="text-left py-1.5 font-semibold text-surface-600">Item</th>
                            <th className="text-center py-1.5 font-semibold text-surface-600 w-10">Qty</th>
                            <th className="text-right py-1.5 font-semibold text-surface-600 w-16">Tax</th>
                            <th className="text-right py-1.5 font-semibold text-surface-600 w-20">Unit Price</th>
                            <th className="text-right py-1.5 font-semibold text-surface-600 w-20">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sale.items.map((item, i) => (
                            <tr key={i} className="border-b border-surface-100">
                                <td className="py-1.5 text-surface-400 font-mono text-2xs">
                                    {((item as any).sku ?? "").slice(-8) || `#${i + 1}`}
                                </td>
                                <td className="py-1.5">
                                    <p className="font-medium">{item.product_name}</p>
                                    {item.variant_name && <p className="text-surface-400">{item.variant_name}</p>}
                                    {item.discount_amount > 0 && (
                                        <p className="text-surface-400">Discount: -{fmt(item.discount_amount)}</p>
                                    )}
                                </td>
                                <td className="py-1.5 text-center">{item.quantity}</td>
                                <td className="py-1.5 text-right text-surface-400">
                                    {(item as any).tax_name
                                        ? <span title={(item as any).tax_name}>{(item as any).tax_rate ?? 0}%</span>
                                        : "-"
                                    }
                                </td>
                                <td className="py-1.5 text-right">{fmt(item.unit_price)}</td>
                                <td className="py-1.5 text-right font-medium">{fmt(item.subtotal)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                </div>
            </div>

            {/* Totals */}
            <div className="flex justify-end">
                <div className="w-56 space-y-1 text-xs">
                    <div className="flex justify-between">
                        <span className="text-surface-500">Subtotal</span>
                        <span>KES {fmt(sale.subtotal)}</span>
                    </div>
                    {sale.discount_amount > 0 && (
                        <div className="flex justify-between">
                            <span className="text-surface-500">Discount</span>
                            <span>-KES {fmt(sale.discount_amount)}</span>
                        </div>
                    )}
                    {sale.tax_amount > 0 && (
                        <div className="flex justify-between">
                            <span className="text-surface-500">
                                Tax{(sale as any).prices_include_tax ? " (incl.)" : ""}
                            </span>
                            <span>KES {fmt(sale.tax_amount)}</span>
                        </div>
                    )}
                    <div className="flex justify-between font-bold text-sm pt-1 border-t-2 border-surface-300">
                        <span>Total</span>
                        <span>KES {fmt(sale.total)}</span>
                    </div>
                </div>
            </div>

            {/* Payments table */}
            <div>
                <p className="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-2">Payments</p>
                {payments.length === 0 ? (
                    <p className="text-xs text-surface-400 italic">No payments recorded yet.</p>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full text-xs border-collapse min-w-[340px]">
                        <thead>
                            <tr className="border-b-2 border-surface-200">
                                <th className="text-left py-1.5 font-semibold text-surface-600">Method</th>
                                <th className="text-left py-1.5 font-semibold text-surface-600">Reference</th>
                                <th className="text-right py-1.5 font-semibold text-surface-600 w-24">Amount</th>
                                <th className="text-right py-1.5 font-semibold text-surface-600 w-32">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((p: any, i: number) => (
                                <tr key={i} className="border-b border-surface-100">
                                    <td className="py-1.5 capitalize font-medium">
                                        {(p.payment_method ?? "other").replace(/_/g, " ")}
                                    </td>
                                    <td className="py-1.5 text-surface-500 font-mono">
                                        {p.provider_reference ?? p.reference ?? "-"}
                                    </td>
                                    <td className="py-1.5 text-right font-medium">
                                        KES {fmt(Number(p.amount))}
                                    </td>
                                    <td className="py-1.5 text-right">
                                        {paymentStatusBadge(p.status, p.approval_status)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 border-surface-200">
                                <td colSpan={2} className="py-1.5 font-bold text-surface-700">Total Paid</td>
                                <td className="py-1.5 text-right font-bold">
                                    KES {fmt(payments.filter((p: any) => p.status === "paid").reduce((s: number, p: any) => s + Number(p.amount), 0))}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                )}
            </div>

            {/* Footer */}
            <p className="text-center text-surface-400 text-xs pt-2 border-t border-surface-100">
                Bethany House · {outlet.name} · Thank you for your business.
            </p>
        </div>
    );
}

// ── Main Modal ────────────────────────────────────────────────────────────────

export default function ReceiptModal({ sale, outlet, onClose, requiresApproval = false }: Props) {
    const toast = useToastStore();
    // Default to invoice mode if the order has pending approvals; otherwise receipt
    const [tab, setTab] = useState<"receipt" | "invoice">(requiresApproval ? "invoice" : "receipt");
    const [emailInput, setEmailInput] = useState("");
    const [showEmailInput, setShowEmailInput] = useState(false);

    const emailMutation = useMutation({
        mutationFn: (email: string) => posApi.emailReceipt(sale.id, email),
        onSuccess: () => { toast.success("Receipt emailed!"); setShowEmailInput(false); },
        onError: (err: { message: string }) => toast.error(err.message),
    });

    const handlePrint = () => {
        const printId = tab === "receipt" ? "pos-receipt" : "pos-invoice";
        const style = document.createElement("style");
        style.innerHTML = `
            @page {
                size: ${tab === "receipt" ? "80mm auto" : "A4"};
                margin: ${tab === "receipt" ? "4mm" : "12mm"};
            }
            @media print {
                html, body {
                    width: ${tab === "receipt" ? "80mm" : "210mm"};
                    height: auto !important;
                    background: white !important;
                }
                body * { visibility: hidden !important; }
                #${printId}, #${printId} * { visibility: visible !important; }
                #${printId} {
                    position: fixed;
                    left: 0;
                    top: 0;
                    ${tab === "receipt"
                        ? "width: 72mm; font-size: 11px !important; padding: 0 !important; margin: 0 !important; max-width: 72mm !important;"
                        : "width: 100%; padding: 24px;"}
                }
                .no-print { display: none !important; }
                /* Ensure dashed lines render on thermal printers */
                * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        `;
        document.head.appendChild(style);
        window.print();
        document.head.removeChild(style);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className={clsx(
                "bg-white rounded-2xl shadow-2xl animate-slide-up flex flex-col max-h-[90vh]",
                tab === "invoice" ? "w-full max-w-2xl" : "w-full max-w-sm"
            )}>
                {/* Header */}
                <div className="no-print px-5 py-4 border-b border-surface-100 flex flex-col gap-3 shrink-0 sm:flex-row sm:items-center sm:justify-between">
                    {/* Tab switcher */}
                    <div className="flex gap-1 bg-surface-100 rounded-xl p-1">
                        {!requiresApproval && (
                            <button
                                onClick={() => setTab("receipt")}
                                className={clsx("px-3 py-1.5 rounded-lg text-xs font-semibold transition-all",
                                    tab === "receipt" ? "bg-white text-surface-900 shadow-sm" : "text-surface-500 hover:text-surface-700")}>
                                🧾 Receipt
                            </button>
                        )}
                        <button
                            onClick={() => setTab("invoice")}
                            className={clsx("px-3 py-1.5 rounded-lg text-xs font-semibold transition-all",
                                tab === "invoice" ? "bg-white text-surface-900 shadow-sm" : "text-surface-500 hover:text-surface-700")}>
                            📄 Invoice
                        </button>
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-2 flex-wrap">
                        {tab === "receipt" && (
                            <button onClick={() => setShowEmailInput(v => !v)} className="btn-secondary btn-sm gap-1.5">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                                Email
                            </button>
                        )}
                        <button onClick={handlePrint} className="btn-secondary btn-sm gap-1.5">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
                            </svg>
                            Print
                        </button>
                        <button onClick={onClose} className="btn-ghost btn-icon btn-sm"
aria-label="Close">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                {/* Email input (receipt mode only) */}
                {showEmailInput && tab === "receipt" && (
                    <div className="no-print px-4 py-3 bg-surface-50 border-b border-surface-100 flex gap-2">
                        <input type="email" placeholder="customer@email.com" value={emailInput}
                            onChange={e => setEmailInput(e.target.value)} className="input flex-1 text-sm" />
                        <button onClick={() => emailMutation.mutate(emailInput)}
                            disabled={!emailInput || emailMutation.isPending} className="btn-primary btn-sm">
                            Send
                        </button>
                    </div>
                )}

                {/* Body */}
                <div className="overflow-y-auto flex-1">
                    {tab === "receipt" && !requiresApproval
                        ? <ThermalReceipt sale={sale} outlet={outlet} />
                        : <FullInvoice sale={sale} outlet={outlet} />
                    }
                </div>

                {/* Footer */}
                <div className="no-print p-4 border-t border-surface-100 shrink-0">
                    <button onClick={onClose} className="btn-primary w-full">
                        {requiresApproval ? "Done" : "New Sale"}
                    </button>
                </div>
            </div>
        </div>
    );
}