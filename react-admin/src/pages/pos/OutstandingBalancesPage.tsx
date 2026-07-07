/**
 * OutstandingBalancesPage.tsx
 *
 * Receivables: POS orders that are part-paid (deposit/partial) with money still
 * owed. Shows total, paid, and the outstanding balance per order.
 *
 * Route: /pos/outstanding-balances   Permission: pos.access
 */

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { get } from "@/api/client";
import { Spinner } from "@/components/ui/Spinner";

interface BalanceRow {
    id: number;
    order_number: string;
    customer_name: string | null;
    customer_phone: string | null;
    outlet_name: string | null;
    payment_status: string;
    currency_code: string;
    total_amount: number;
    amount_paid: number;
    balance: number;
    balance_due_date: string | null;
    created_at: string | null;
}

interface Paginated {
    data: BalanceRow[];
    current_page: number;
    last_page: number;
    total: number;
}

const fmt = (n: number, currency = "KES") =>
    `${currency} ${Number(n).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`;

export default function OutstandingBalancesPage() {
    const [search, setSearch] = useState("");
    const [page, setPage] = useState(1);
    const perPage = 25;

    const { data, isLoading, isError } = useQuery({
        queryKey: ["pos-outstanding-balances", search, page],
        queryFn: () =>
            get<Paginated>("/v1/admin/pos/outstanding-balances", {
                params: {
                    ...(search ? { search } : {}),
                    page: String(page),
                    per_page: String(perPage),
                },
            }),
        placeholderData: (prev) => prev,
    });

    const rows = data?.data ?? [];
    const lastPage = data?.last_page ?? 1;
    const totalOwed = rows.reduce((sum, r) => sum + r.balance, 0);

    return (
        <div className="flex flex-col h-full min-w-0 overflow-hidden">
            {/* Header */}
            <div className="px-4 sm:px-6 pt-5 pb-4 border-b border-surface-100">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-lg sm:text-xl font-bold text-surface-900">Outstanding Balances</h1>
                        <p className="text-xs text-surface-400 mt-0.5">
                            Part-paid POS orders with money still owed
                        </p>
                    </div>
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                        placeholder="Search order # or customer…"
                        className="input w-full sm:w-72"
                    />
                </div>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-auto p-4 sm:p-6">
                {isLoading ? (
                    <div className="flex justify-center py-16"><Spinner /></div>
                ) : isError ? (
                    <p className="text-center text-danger py-16">Failed to load outstanding balances.</p>
                ) : rows.length === 0 ? (
                    <p className="text-center text-surface-400 py-16">No outstanding balances. 🎉</p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-surface-100">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-50 text-surface-500 text-xs">
                                <tr>
                                    <th className="text-left font-medium px-4 py-2.5">Order</th>
                                    <th className="text-left font-medium px-4 py-2.5">Customer</th>
                                    <th className="text-left font-medium px-4 py-2.5">Outlet</th>
                                    <th className="text-right font-medium px-4 py-2.5">Total</th>
                                    <th className="text-right font-medium px-4 py-2.5">Paid</th>
                                    <th className="text-right font-medium px-4 py-2.5">Balance</th>
                                    <th className="text-left font-medium px-4 py-2.5">Due</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-100">
                                {rows.map((r) => (
                                    <tr key={r.id} className="hover:bg-surface-50">
                                        <td className="px-4 py-2.5 font-medium text-surface-900">{r.order_number}</td>
                                        <td className="px-4 py-2.5 text-surface-600">
                                            {r.customer_name ?? "—"}
                                            {r.customer_phone
                                                ? <span className="block text-2xs text-surface-400">{r.customer_phone}</span>
                                                : null}
                                        </td>
                                        <td className="px-4 py-2.5 text-surface-500">{r.outlet_name ?? "—"}</td>
                                        <td className="px-4 py-2.5 text-right text-surface-600">{fmt(r.total_amount, r.currency_code)}</td>
                                        <td className="px-4 py-2.5 text-right text-success-dark">{fmt(r.amount_paid, r.currency_code)}</td>
                                        <td className="px-4 py-2.5 text-right font-semibold text-danger">{fmt(r.balance, r.currency_code)}</td>
                                        <td className="px-4 py-2.5 text-surface-500">{r.balance_due_date ?? "—"}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {rows.length > 0 && (
                    <div className="flex items-center justify-between mt-4 text-xs text-surface-500">
                        <span>Owed on this page: <strong className="text-danger">{fmt(totalOwed)}</strong></span>
                        <div className="flex items-center gap-2">
                            <button
                                disabled={page <= 1}
                                onClick={() => setPage((p) => p - 1)}
                                className="btn-ghost px-2 py-1 disabled:opacity-40 disabled:cursor-not-allowed">
                                Prev
                            </button>
                            <span>Page {page} / {lastPage}</span>
                            <button
                                disabled={page >= lastPage}
                                onClick={() => setPage((p) => p + 1)}
                                className="btn-ghost px-2 py-1 disabled:opacity-40 disabled:cursor-not-allowed">
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
