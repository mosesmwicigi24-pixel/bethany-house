/**
 * Channel Engagement — how often customers reach us on each platform.
 * Full page under Intelligence. WhatsApp/Messenger/Instagram/Facebook come from
 * Neema (synced nightly, matched by phone); Webpage from site_visits.
 */
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { intelligenceApi, type ChannelStat } from "@/api/intelligence";
import { Spinner } from "@/components/ui/Spinner";

const fmtNum = (n: number) => new Intl.NumberFormat("en-KE").format(n);

const CHANNEL_META: Record<string, { label: string; emoji: string }> = {
    whatsapp:  { label: "WhatsApp",  emoji: "🟢" },
    messenger: { label: "Messenger", emoji: "💬" },
    instagram: { label: "Instagram", emoji: "📸" },
    facebook:  { label: "Facebook",  emoji: "👍" },
    web:       { label: "Webpage",   emoji: "🌐" },
};

export default function ChannelEngagementPage() {
    const navigate = useNavigate();
    const { data, isLoading } = useQuery({
        queryKey: ["intelligence", "channels"],
        queryFn:  intelligenceApi.channelEngagement,
        staleTime: 5 * 60_000,
    });

    const channels = data?.channels ?? [];
    const top      = data?.top_customers ?? [];
    const maxMsg   = Math.max(1, ...channels.map(c => c.messages));
    const connected = data?.summary?.connected_channels ?? 0;

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header">
                <h1 className="page-title">Channel Engagement</h1>
                <p className="page-subtitle">How often customers reach you across WhatsApp, Messenger, Instagram, Facebook and the website.</p>
            </div>

            {isLoading ? <div className="py-16 flex justify-center"><Spinner /></div> : (
                <>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-white rounded-2xl border border-surface-200 p-5">
                            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Connected</p>
                            <p className="text-2xl font-bold text-surface-900 mt-1">{connected}<span className="text-base text-surface-400">/5</span></p>
                            <p className="text-xs text-surface-400 mt-0.5">platforms with activity</p>
                        </div>
                        <div className="bg-white rounded-2xl border border-surface-200 p-5">
                            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Messages</p>
                            <p className="text-2xl font-bold text-surface-900 mt-1">{fmtNum(data?.summary?.message_channels ?? 0)}</p>
                            <p className="text-xs text-surface-400 mt-0.5">across messaging channels</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="bg-white rounded-2xl border border-surface-200 overflow-hidden">
                            <div className="px-5 py-3 border-b border-surface-100">
                                <h2 className="font-semibold text-surface-900 text-sm">By platform</h2>
                            </div>
                            <div className="divide-y divide-surface-50">
                                {channels.map((c: ChannelStat) => {
                                    const meta = CHANNEL_META[c.channel] ?? { label: c.channel, emoji: "•" };
                                    return (
                                        <div key={c.channel} className={clsx("flex items-center gap-3 px-5 py-3.5", !c.connected && "opacity-55")}>
                                            <span className="text-xl shrink-0" aria-hidden>{meta.emoji}</span>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between gap-2">
                                                    <p className="text-sm font-medium text-surface-900">{meta.label}</p>
                                                    {c.connected ? (
                                                        <p className="text-sm font-bold text-surface-900 shrink-0">{fmtNum(c.messages)}
                                                            <span className="text-xs font-normal text-surface-400"> {c.channel === "web" ? "visits" : "msgs"}</span></p>
                                                    ) : (
                                                        <span className="text-2xs font-semibold text-surface-400 uppercase tracking-wide shrink-0">Not connected</span>
                                                    )}
                                                </div>
                                                {c.connected && (
                                                    <div className="mt-1.5 h-2 rounded-full bg-surface-100 overflow-hidden">
                                                        <div className="h-full rounded-full bg-emerald-500" style={{ width: `${(c.messages / maxMsg) * 100}%` }} />
                                                    </div>
                                                )}
                                                {c.connected && c.channel !== "web" && (
                                                    <p className="text-xs text-surface-400 mt-1">{fmtNum(c.customers)} customers engaged</p>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="px-5 py-3 border-t border-surface-100 text-2xs text-surface-400">
                                Instagram lights up once its Neema ingestion carries traffic — no further setup needed.
                            </div>
                        </div>

                        <div className="bg-white rounded-2xl border border-surface-200 overflow-hidden">
                            <div className="px-5 py-3 border-b border-surface-100">
                                <h2 className="font-semibold text-surface-900 text-sm">Most engaged customers</h2>
                            </div>
                            {top.length === 0 ? (
                                <p className="px-5 py-10 text-center text-sm text-surface-400">No matched customers yet.</p>
                            ) : (
                                <div className="divide-y divide-surface-50">
                                    {top.map(t => (
                                        <div key={t.customer_id}
                                             className="flex items-center gap-3 px-5 py-3 hover:bg-surface-50/50 cursor-pointer transition-colors"
                                             onClick={() => navigate(`/sales/customers/${t.customer_id}`)}>
                                            <div className="w-8 h-8 rounded-full bg-surface-200 flex items-center justify-center text-xs font-bold text-surface-600 shrink-0">
                                                {(t.name || "?").charAt(0).toUpperCase()}
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium text-surface-900 truncate">{t.name || "—"}</p>
                                                <p className="text-xs text-surface-400">{t.channels.map(ch => CHANNEL_META[ch]?.label ?? ch).join(" · ")}</p>
                                            </div>
                                            <p className="text-xs font-semibold text-surface-700 shrink-0">{fmtNum(t.messages)} msgs</p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <p className="text-xs text-surface-400 px-1">
                        Synced nightly from Neema, matched to customers by phone number. Webpage traffic is anonymous visit counts.
                    </p>
                </>
            )}
        </div>
    );
}
