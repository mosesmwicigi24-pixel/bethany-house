// Neema (AI agent) — leads, enquiries, conversions and revenue from the
// WhatsApp/Messenger agent. Moved out of Sales into Customers & Neema
// (reports consolidation, 2026-10-01): engagement belongs with customers.
// Leads carry no outlet, so lead counts are business-wide; order figures
// honour the report's outlet filter.

import dayjs from "dayjs";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import type { NeemaSalesReport } from "@/api/reports";
import {
    KPI_GRID,
    TH,
    TH_R,
    CHART_COLORS,
    SectionHeader,
    KpiCard,
    TableWrapper,
    ProgressBar,
    fmtPct,
} from "./reportShared";

// The agent's sales funnel for the period: leads captured, how many became an
// order within 14 days (matched by phone — read conversion as a floor, since a
// lead who ordered under a different number is not counted), WhatsApp-channel
// revenue under the SAME rule as the ledger, contact growth and message volume
// per messaging platform.

const PLATFORM_LABEL: Record<string, string> = {
    whatsapp: "WhatsApp",
    messenger: "Messenger",
    instagram: "Instagram",
    facebook: "Facebook",
};

const LEAD_STATUSES = ["new", "assigned", "quoted", "won", "lost"] as const;
const LEAD_STATUS_LABEL: Record<string, string> = {
    new: "New",
    assigned: "Assigned",
    quoted: "Quoted",
    won: "Won",
    lost: "Lost",
};

export function NeemaTab({
    query,
}: {
    query: { data?: NeemaSalesReport; isLoading: boolean };
}) {
    const d = query.data;
    if (query.isLoading)
        return (
            <div className="flex justify-center py-12">
                <Spinner />
            </div>
        );
    if (!d) return null;

    const newContacts = d.contacts.reduce((a, c) => a + c.new_contacts, 0);
    const maxStatus = Math.max(
        ...LEAD_STATUSES.map((s) => d.leads.by_status[s] ?? 0),
        1,
    );
    const maxIntent = Math.max(...d.leads.by_intent.map((i) => i.count), 1);

    return (
        <div className="space-y-6">
            {/* KPI row */}
            <div className={KPI_GRID}>
                <KpiCard
                    label="Leads"
                    value={d.leads.total}
                    sub="captured by Neema in this period"
                />
                <KpiCard
                    label="Lead → Order"
                    value={fmtPct(d.lead_conversion.conversion_rate)}
                    sub={`${d.lead_conversion.converted} of ${d.leads.total} ordered within ${d.lead_conversion.window_days} days · ${fmtKes(d.lead_conversion.revenue)}`}
                />
                <KpiCard
                    label="WhatsApp Revenue"
                    value={fmtKes(d.whatsapp_sales.revenue)}
                    sub={`${d.whatsapp_sales.orders} orders · ${fmtKes(d.whatsapp_sales.paid)} paid`}
                />
                <KpiCard
                    label="New Contacts"
                    value={newContacts}
                    sub="first seen this period, all platforms"
                />
            </div>

            {/* Leads funnel: status mix + top intents */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="card p-5">
                    <SectionHeader title="Leads by Status" />
                    {d.leads.total === 0 ? (
                        <p className="text-sm text-surface-500">
                            No leads in this range.
                        </p>
                    ) : (
                        <div className="space-y-3 mt-2">
                            {LEAD_STATUSES.map((s, i) => (
                                <div key={s}>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-surface-700">
                                            {LEAD_STATUS_LABEL[s]}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {d.leads.by_status[s] ?? 0}
                                        </span>
                                    </div>
                                    <ProgressBar
                                        value={d.leads.by_status[s] ?? 0}
                                        max={maxStatus}
                                        color={
                                            CHART_COLORS[
                                                i % CHART_COLORS.length
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                            <p className="text-xs text-surface-500 pt-2 border-t border-line">
                                Won rate: {fmtPct(d.leads.won_rate)} of leads in
                                this period.
                            </p>
                        </div>
                    )}
                </div>

                <div className="card p-5">
                    <SectionHeader title="Top Intents" />
                    {d.leads.by_intent.length === 0 ? (
                        <p className="text-sm text-surface-500">
                            No leads in this range.
                        </p>
                    ) : (
                        <div className="space-y-3 mt-2">
                            {d.leads.by_intent.map((row, i) => (
                                <div key={row.intent}>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-surface-700 capitalize">
                                            {row.intent.replace(/_/g, " ")}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {row.count}
                                        </span>
                                    </div>
                                    <ProgressBar
                                        value={row.count}
                                        max={maxIntent}
                                        color={
                                            CHART_COLORS[
                                                i % CHART_COLORS.length
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Contacts per platform */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Contacts by Platform" />
                    <p className="text-xs text-surface-500 -mt-2">
                        New = first seen in this period. Active = messaged in
                        this period. Matched = linked to a hub customer by
                        phone.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Platform</th>
                                <th className={TH_R}>New</th>
                                <th className={TH_R}>Active</th>
                                <th className={TH_R}>All-time Contacts</th>
                                <th className={TH_R}>Matched</th>
                                <th className={TH_R}>Match Rate</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {d.contacts.map((c) => (
                                <tr
                                    key={c.channel}
                                    className="hover:bg-surface-50/50 transition-colors"
                                >
                                    <td className="px-4 py-3 font-medium text-surface-900">
                                        {PLATFORM_LABEL[c.channel] ?? c.channel}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.new_contacts}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.active_contacts}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                        {c.contacts}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                        {c.matched}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.match_rate == null
                                            ? "—"
                                            : fmtPct(c.match_rate)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>

            {/* Message volume per platform */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Message Volume by Platform" />
                    <p className="text-xs text-surface-500 -mt-2">
                        Period figures come from daily snapshots
                        {d.message_volume.daily_since
                            ? ` collected since ${dayjs(d.message_volume.daily_since).format("D MMM YYYY")}`
                            : ""}
                        . Daily message history collects from deploy onward —
                        the totals on the right are all-time.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Platform</th>
                                <th className={TH_R}>Messages (period)</th>
                                <th className={TH_R}>Inbound (period)</th>
                                <th className={TH_R}>Messages (all-time)</th>
                                <th className={TH_R}>Inbound (all-time)</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {d.message_volume.channels.map((c) => (
                                <tr
                                    key={c.channel}
                                    className="hover:bg-surface-50/50 transition-colors"
                                >
                                    <td className="px-4 py-3 font-medium text-surface-900">
                                        {PLATFORM_LABEL[c.channel] ?? c.channel}
                                    </td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                        {c.period.messages}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.period.inbound}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-500">
                                        {c.all_time.messages}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-500">
                                        {c.all_time.inbound}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>

            <p className="text-xs text-surface-400">
                Lead → order conversion is matched by phone number and is a
                floor, not an exact figure — a lead who ordered under a
                different number is not counted. WhatsApp revenue follows the
                same channel rule as the Channels tab, so the numbers agree.
            </p>
        </div>
    );
}

// ─── Collections tab ──────────────────────────────────────────────────────────
// The collections funnel: every shilling promised but not collected, staged —
// open quotes, stalled deposits (money already down, then silence — the
// warmest calls in the business), and unpaid balances. Balance figures come
// from the same MetricEngine base as the executive Overview, so the two
// surfaces always agree. Each row carries a one-click WhatsApp follow-up;
// order rows fetch the order's public payment link first so the customer can
// settle straight from the chat.

