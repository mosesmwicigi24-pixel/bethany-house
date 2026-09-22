import { useState, type ReactNode } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { imprestApi, kes, type ImprestAccountView, type ImprestTopup, type SendInput } from "@/api/imprest";
import { usersApi } from "@/api/setup";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";

// The imprest — a petty-cash float (owner decisions 2026-09-22):
//   expenses paid from it come off the moment they are recorded;
//   anyone who records expenses can ask for a top-up when it runs low;
//   the super admin sends the money and says so;
//   the balance rises only when the custodian confirms what arrived.

const person = (p?: { first_name: string; last_name: string } | null) => (p ? `${p.first_name} ${p.last_name}`.trim() : "—");
const when = (d?: string | null) => (d ? new Date(d).toLocaleString("en-GB", { dateStyle: "medium", timeStyle: "short" }) : "—");
const METHODS: { v: SendInput["method"]; label: string }[] = [
    { v: "mpesa", label: "M-Pesa" }, { v: "cash", label: "Cash" }, { v: "bank_transfer", label: "Bank transfer" }, { v: "other", label: "Other" },
];
const TYPE_LABEL: Record<string, string> = {
    opening: "Opening balance", top_up: "Top-up received", expense: "Expense",
    expense_adjustment: "Expense adjusted", cash_returned: "Cash returned", count_adjustment: "Cash count adjustment",
};

function errMsg(e: any, fallback: string): string {
    const first = e?.errors && Object.values(e.errors)[0];
    return (Array.isArray(first) ? first[0] : null) ?? e?.message ?? fallback;
}

// ── a small modal form ──────────────────────────────────────────────────────

function FormModal({ open, title, onClose, onSubmit, busy, submitLabel, danger, children }: {
    open: boolean; title: string; onClose: () => void; onSubmit: () => void; busy: boolean;
    submitLabel: string; danger?: boolean; children: ReactNode;
}) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">Cancel</button>
                    <button onClick={onSubmit} disabled={busy} className={danger ? "btn-danger btn-sm" : "btn-primary btn-sm"}>
                        {busy && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        {submitLabel}
                    </button>
                </>
            }
        >
            <div className="space-y-3 text-sm">{children}</div>
        </Modal>
    );
}

function Money({ label, value, onChange, hint }: { label: string; value: string; onChange: (v: string) => void; hint?: string }) {
    return (
        <div>
            <label className="label">{label}</label>
            <div className="relative mt-1">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-surface-400">KES</span>
                <input className="input pl-11" inputMode="decimal" value={value} onChange={(e) => onChange(e.target.value.replace(/[^\d.]/g, ""))} />
            </div>
            {hint && <p className="mt-1 text-xs text-surface-400">{hint}</p>}
        </div>
    );
}

// ── setting up ──────────────────────────────────────────────────────────────

function Setup() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [name, setName] = useState("Petty cash — Sonalux Store");
    const [custodian, setCustodian] = useState("");
    const [float, setFloat] = useState("10000");
    const [opening, setOpening] = useState("");
    const [pct, setPct] = useState("20");
    const { data: staff } = useQuery({ queryKey: ["imprest-staff"], queryFn: () => usersApi.list({ per_page: "100", status: "active", exclude_type: "customer", sort_by: "first_name", sort_order: "asc" }) });

    const save = useMutation({
        mutationFn: () => imprestApi.setup({
            name, custodian_id: Number(custodian), float_amount: Number(float), opening_balance: Number(opening || 0), low_balance_percent: Number(pct),
        }),
        onSuccess: () => { toast.success("Imprest set up."); qc.invalidateQueries({ queryKey: ["imprest"] }); },
        onError: (e: any) => toast.error(errMsg(e, "Could not set it up.")),
    });

    return (
        <div className="card card-body max-w-xl space-y-4">
            <div>
                <h2 className="text-base font-semibold text-surface-900">Set up the imprest</h2>
                <p className="text-sm text-surface-500">
                    Count the cash in the box first — that is the opening balance. From then on, every expense asks
                    whether it was paid from the imprest.
                </p>
            </div>
            <div><label className="label">Name</label><input className="input mt-1" value={name} onChange={(e) => setName(e.target.value)} /></div>
            <div>
                <label className="label">Custodian — who holds the cash</label>
                <select className="input mt-1" value={custodian} onChange={(e) => setCustodian(e.target.value)}>
                    <option value="">Choose…</option>
                    {(staff?.data ?? []).map((u) => (
                        <option key={u.id} value={u.id}>{u.first_name} {u.last_name} — {u.email}</option>
                    ))}
                </select>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Money label="Standard float" value={float} onChange={setFloat} hint="What a full box holds" />
                <Money label="Cash in the box now" value={opening} onChange={setOpening} hint="Counted today — the opening balance" />
            </div>
            <div>
                <label className="label">Low-balance alert</label>
                <div className="mt-1 flex items-center gap-2">
                    <input className="input w-24" inputMode="numeric" value={pct} onChange={(e) => setPct(e.target.value.replace(/\D/g, ""))} />
                    <span className="text-xs text-surface-500">% of the float ({kes((Number(float) * Number(pct || 0)) / 100)})</span>
                </div>
            </div>
            <button onClick={() => save.mutate()} disabled={!custodian || !float || opening === "" || save.isPending} className="btn-primary">
                {save.isPending && <Spinner size="sm" className="mr-1.5" />} Set up the imprest
            </button>
        </div>
    );
}

// ── the account ─────────────────────────────────────────────────────────────

function Account({ a, isSuperAdmin }: { a: ImprestAccountView; isSuperAdmin: boolean }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { user } = useAuthStore();
    const { can } = usePermissions();
    // Anyone who records expenses may count the box; a difference waits for the super admin.
    const canCount = a.you_are_custodian || isSuperAdmin || can("expenses.create");
    const [modal, setModal] = useState<null | "request" | "send" | "direct" | "decline" | "receive" | "count" | "settings">(null);
    const [f, setF] = useState<Record<string, string>>({});
    const set = (k: string) => (v: string) => setF((p) => ({ ...p, [k]: v }));
    const refresh = () => ["imprest", "imprest-statement", "imprest-topups", "imprest-counts", "imprest-unresolved", "expenses"]
        .forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
    const done = (msg: string) => { toast.success(msg); setModal(null); setF({}); refresh(); };

    const open = a.open_topup;
    const pct = Math.max(0, Math.min(100, (Number(a.balance) / Number(a.float_amount)) * 100));

    const { data: unrep } = useQuery({ queryKey: ["imprest-unrep", a.id], queryFn: () => imprestApi.unreplenished(a.id), enabled: modal === "request" });
    const { data: staff } = useQuery({ queryKey: ["imprest-staff"], queryFn: () => usersApi.list({ per_page: "100", status: "active", exclude_type: "customer", sort_by: "first_name", sort_order: "asc" }), enabled: modal === "settings" });

    const run = useMutation({
        mutationFn: async (kind: string) => {
            switch (kind) {
                case "request": return imprestApi.requestTopup(a.id, { amount: f.amount ? Number(f.amount) : undefined, reason: f.reason || undefined });
                case "send": return imprestApi.send(open!.uuid, { amount: Number(f.amount), method: (f.method as SendInput["method"]) || "mpesa", reference: f.reference || undefined, note: f.note || undefined });
                case "direct": return imprestApi.sendDirect(a.id, { amount: Number(f.amount), method: (f.method as SendInput["method"]) || "mpesa", reference: f.reference || undefined, note: f.note || undefined });
                case "decline": return imprestApi.decline(open!.uuid, f.reason ?? "");
                case "receive": return imprestApi.receive(open!.uuid, { amount: Number(f.amount), note: f.note || undefined });
                case "count": return imprestApi.count(a.id, { counted_amount: Number(f.amount), note: f.note || undefined });
                case "settings": return imprestApi.update(a.id, {
                    ...(f.custodian ? { custodian_id: Number(f.custodian) } : {}),
                    ...(f.float ? { float_amount: Number(f.float) } : {}),
                    ...(f.pct ? { low_balance_percent: Number(f.pct) } : {}),
                });
                case "cancel": return imprestApi.cancelTopup(open!.uuid);
            }
        },
        onSuccess: (res: any, kind) => done(({
            request: "Top-up requested. The super admin has been told.",
            send: "Marked as sent. The custodian confirms when it arrives.",
            direct: "Marked as sent. The custodian confirms when it arrives.",
            decline: "Declined.", receive: "Received. The balance is updated.",
            count: res?.message ?? "Count recorded.", settings: "Saved.", cancel: "Request cancelled.",
        } as Record<string, string>)[kind as string] ?? "Done."),
        onError: (e: any) => toast.error(errMsg(e, "That did not go through.")),
    });

    const openModal = (m: NonNullable<typeof modal>, preset: Record<string, string> = {}) => { setF(preset); setModal(m); };

    return (
        <>
            {/* Balance */}
            <div className={clsx("card card-body space-y-4", a.is_low && "border-warning/50")}>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p className="text-xs text-surface-500">{a.name}</p>
                        <p className={clsx("text-3xl font-bold", a.is_low ? "text-warning" : "text-surface-900")}>{kes(a.balance)}</p>
                        <p className="text-xs text-surface-500">
                            of a {kes(a.float_amount)} float · held by {person(a.custodian)}{a.you_are_custodian ? " (you)" : ""}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {!open && (
                            <button onClick={() => openModal("request", { amount: a.suggested_topup })} className={a.is_low ? "btn-primary btn-sm" : "btn-secondary btn-sm"}>
                                Request top-up
                            </button>
                        )}
                        {canCount && <button onClick={() => openModal("count")} className="btn-secondary btn-sm">Count the cash</button>}
                        {isSuperAdmin && !open && <button onClick={() => openModal("direct", { amount: a.suggested_topup, method: "mpesa" })} className="btn-secondary btn-sm">Load money</button>}
                        {isSuperAdmin && <button onClick={() => openModal("settings")} className="btn-ghost btn-sm">Settings</button>}
                    </div>
                </div>
                <div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-surface-100">
                        <div className={clsx("h-full rounded-full", a.is_low ? "bg-warning" : "bg-brand-500")} style={{ width: `${pct}%` }} />
                    </div>
                    <p className="mt-1 text-xs text-surface-400">
                        Alert below {kes(a.low_threshold)} ({a.low_balance_percent}%).
                        {a.is_low && <span className="font-medium text-warning"> Below it now — ask for a top-up.</span>}
                    </p>
                </div>
            </div>

            {/* The top-up in progress */}
            {open && (
                <div className={clsx("card card-body space-y-2", open.status === "sent" ? "border-brand-200 bg-brand-50/40" : "border-warning/40 bg-warning-light/30")}>
                    {open.status === "pending" ? (
                        <>
                            <p className="text-sm font-semibold text-surface-900">Top-up requested — {kes(open.requested_amount)}</p>
                            <p className="text-xs text-surface-600">
                                by {person(open.requester)} · {when(open.created_at)} · balance then {kes(open.balance_at_request)}
                                {open.reason ? ` · “${open.reason}”` : ""}
                            </p>
                            <div className="flex flex-wrap gap-2 pt-1">
                                {isSuperAdmin && <button onClick={() => openModal("send", { amount: open.requested_amount ?? "", method: "mpesa" })} className="btn-primary btn-sm">Mark as sent</button>}
                                {isSuperAdmin && <button onClick={() => openModal("decline")} className="btn-secondary btn-sm text-danger">Decline</button>}
                                {open.requested_by === user?.id && <button onClick={() => run.mutate("cancel")} className="btn-ghost btn-sm">Cancel request</button>}
                                {!isSuperAdmin && <span className="text-xs text-surface-500 self-center">Waiting for the super admin.</span>}
                            </div>
                        </>
                    ) : (
                        <>
                            <p className="text-sm font-semibold text-surface-900">Top-up on its way — {kes(open.sent_amount)}</p>
                            <p className="text-xs text-surface-600">
                                sent by {person(open.sender)} via {METHODS.find((m) => m.v === open.sent_method)?.label ?? open.sent_method}
                                {open.sent_reference ? ` (${open.sent_reference})` : ""} · {when(open.sent_at)}
                            </p>
                            {a.you_are_custodian ? (
                                <button onClick={() => openModal("receive", { amount: open.sent_amount ?? "" })} className="btn-primary btn-sm">
                                    Confirm it arrived
                                </button>
                            ) : (
                                <p className="text-xs text-surface-500">The balance goes up when {person(a.custodian)} confirms it arrived.</p>
                            )}
                        </>
                    )}
                </div>
            )}

            {isSuperAdmin && <NeedsYou accountId={a.id} />}
            {a.can_read_book && <Statement accountId={a.id} />}
            <TopupHistory accountId={a.id} />

            {/* ── modals ── */}
            <FormModal open={modal === "request"} title="Request a top-up" onClose={() => setModal(null)} onSubmit={() => run.mutate("request")} busy={run.isPending} submitLabel="Send request">
                <Money label="Amount" value={f.amount ?? ""} onChange={set("amount")} hint={`Brings the box back to its ${kes(a.float_amount)} float`} />
                <div><label className="label">Note (optional)</label><input className="input mt-1" value={f.reason ?? ""} onChange={(e) => set("reason")(e.target.value)} placeholder="e.g. Deliveries this week" /></div>
                <div className="rounded-lg bg-surface-50 p-3">
                    <p className="text-xs font-medium text-surface-600">Spent since the last top-up{unrep ? ` — ${kes(unrep.total)}` : ""}</p>
                    <ul className="mt-1 max-h-40 space-y-0.5 overflow-auto text-xs text-surface-600">
                        {(unrep?.data ?? []).map((x) => (
                            <li key={x.id} className="flex justify-between gap-2"><span className="truncate">{x.reference_number} · {x.title}</span><span className="shrink-0">{kes(x.amount_kes)}</span></li>
                        ))}
                        {unrep && unrep.data.length === 0 && <li>Nothing yet.</li>}
                    </ul>
                </div>
            </FormModal>

            {(["send", "direct"] as const).map((k) => (
                <FormModal key={k} open={modal === k} title={k === "send" ? "Mark the top-up as sent" : "Load money into the imprest"} onClose={() => setModal(null)}
                    onSubmit={() => run.mutate(k)} busy={run.isPending} submitLabel="Mark as sent">
                    <p className="text-xs text-surface-500">Send the money first. The balance goes up when {person(a.custodian)} confirms it arrived.</p>
                    <Money label="Amount sent" value={f.amount ?? ""} onChange={set("amount")} />
                    <div>
                        <label className="label">How</label>
                        <select className="input mt-1" value={f.method ?? "mpesa"} onChange={(e) => set("method")(e.target.value)}>
                            {METHODS.map((m) => <option key={m.v} value={m.v}>{m.label}</option>)}
                        </select>
                    </div>
                    <div><label className="label">Reference (M-Pesa code)</label><input className="input mt-1 font-mono" value={f.reference ?? ""} onChange={(e) => set("reference")(e.target.value.toUpperCase())} /></div>
                    <div><label className="label">Note (optional)</label><input className="input mt-1" value={f.note ?? ""} onChange={(e) => set("note")(e.target.value)} /></div>
                </FormModal>
            ))}

            <FormModal open={modal === "decline"} title="Decline the top-up" onClose={() => setModal(null)} onSubmit={() => run.mutate("decline")} busy={run.isPending} submitLabel="Decline" danger>
                <label className="label">Tell them why</label>
                <textarea className="input min-h-[80px]" value={f.reason ?? ""} onChange={(e) => set("reason")(e.target.value)} />
            </FormModal>

            <FormModal open={modal === "receive"} title="Confirm the top-up arrived" onClose={() => setModal(null)} onSubmit={() => run.mutate("receive")} busy={run.isPending} submitLabel="Confirm received">
                <p className="text-xs text-surface-500">Enter what actually arrived. If it is different from what was sent, the super admin is told.</p>
                <Money label="Amount received" value={f.amount ?? ""} onChange={set("amount")} hint={open ? `Sent: ${kes(open.sent_amount)}` : undefined} />
                <div><label className="label">Note (optional)</label><input className="input mt-1" value={f.note ?? ""} onChange={(e) => set("note")(e.target.value)} placeholder="e.g. M-Pesa charge deducted" /></div>
            </FormModal>

            <FormModal open={modal === "count"} title="Count the cash" onClose={() => setModal(null)} onSubmit={() => run.mutate("count")} busy={run.isPending} submitLabel="Record count">
                <p className="text-xs text-surface-500">The books say {kes(a.balance)}. Any difference goes to the super admin to approve.</p>
                <Money label="Cash counted" value={f.amount ?? ""} onChange={set("amount")} />
                <div><label className="label">Note (optional)</label><input className="input mt-1" value={f.note ?? ""} onChange={(e) => set("note")(e.target.value)} /></div>
            </FormModal>

            <FormModal open={modal === "settings"} title="Imprest settings" onClose={() => setModal(null)} onSubmit={() => run.mutate("settings")} busy={run.isPending} submitLabel="Save">
                <div>
                    <label className="label">Custodian</label>
                    <select className="input mt-1" value={f.custodian ?? String(a.custodian?.id ?? "")} onChange={(e) => set("custodian")(e.target.value)}>
                        {(staff?.data ?? []).map((u) => <option key={u.id} value={u.id}>{u.first_name} {u.last_name}</option>)}
                    </select>
                </div>
                <Money label="Standard float" value={f.float ?? a.float_amount} onChange={set("float")} />
                <div><label className="label">Low-balance alert (%)</label><input className="input mt-1 w-24" value={f.pct ?? String(a.low_balance_percent)} onChange={(e) => set("pct")(e.target.value.replace(/\D/g, ""))} /></div>
                <p className="text-xs text-surface-400">The balance itself can only change through expenses, top-ups and approved cash counts.</p>
            </FormModal>
        </>
    );
}

// ── what waits on the super admin ───────────────────────────────────────────

function NeedsYou({ accountId }: { accountId: number }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { data: unresolved } = useQuery({ queryKey: ["imprest-unresolved"], queryFn: () => imprestApi.unresolved() });
    const { data: counts } = useQuery({ queryKey: ["imprest-counts", accountId], queryFn: () => imprestApi.counts(accountId) });
    const pendingCounts = (counts?.data ?? []).filter((c) => c.status === "pending");

    const act = useMutation({
        mutationFn: (fn: () => Promise<unknown>) => fn(),
        onSuccess: () => { toast.success("Done."); ["imprest", "imprest-unresolved", "imprest-counts", "imprest-statement", "expenses"].forEach((k) => qc.invalidateQueries({ queryKey: [k] })); },
        onError: (e: any) => toast.error(errMsg(e, "That did not go through.")),
    });

    if (!(unresolved?.length || pendingCounts.length)) return null;

    return (
        <div className="card divide-y divide-surface-100">
            <p className="px-4 py-3 text-sm font-semibold text-surface-900">Needs your decision</p>
            {(unresolved ?? []).map((e) => (
                <div key={e.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="text-sm">
                        <Link to={`/expenses/${e.id}`} className="font-medium text-surface-800 hover:underline">{e.reference_number} · {e.title}</Link>
                        <p className="text-xs text-surface-500">{kes(e.amount_kes)} left the imprest and the expense was {e.status}. Was the cash returned?</p>
                    </div>
                    <div className="flex gap-2">
                        <button onClick={() => act.mutate(() => imprestApi.resolve(e.id, "returned"))} className="btn-primary btn-sm">Cash returned</button>
                        <button onClick={() => act.mutate(() => imprestApi.resolve(e.id, "written_off"))} className="btn-secondary btn-sm">Write off</button>
                    </div>
                </div>
            ))}
            {pendingCounts.map((c) => (
                <div key={c.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="text-sm">
                        <p className="font-medium text-surface-800">Cash count differs by {kes(c.variance)}</p>
                        <p className="text-xs text-surface-500">
                            {person(c.counter)} counted {kes(c.counted_amount)}; the books said {kes(c.book_balance)} · {when(c.created_at)}{c.note ? ` · “${c.note}”` : ""}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button onClick={() => act.mutate(() => imprestApi.decideCount(c.id, true))} className="btn-primary btn-sm">Approve adjustment</button>
                        <button onClick={() => act.mutate(() => imprestApi.decideCount(c.id, false))} className="btn-secondary btn-sm">Reject</button>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── the book ────────────────────────────────────────────────────────────────

function Statement({ accountId }: { accountId: number }) {
    const [page, setPage] = useState(1);
    const { data, isLoading } = useQuery({ queryKey: ["imprest-statement", accountId, page], queryFn: () => imprestApi.statement(accountId, page) });
    return (
        <div className="card overflow-x-auto">
            <p className="px-4 pt-4 pb-2 text-sm font-semibold text-surface-900">Statement</p>
            <table className="w-full text-sm">
                <thead className="bg-surface-50 text-xs text-surface-500">
                    <tr>
                        <th className="px-4 py-2 text-left font-medium">When</th>
                        <th className="px-4 py-2 text-left font-medium">What</th>
                        <th className="px-4 py-2 text-left font-medium">By</th>
                        <th className="px-4 py-2 text-right font-medium">In / out</th>
                        <th className="px-4 py-2 text-right font-medium">Balance</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-surface-100">
                    {isLoading && <tr><td colSpan={5} className="px-4 py-3 text-surface-400">Loading…</td></tr>}
                    {(data?.data ?? []).map((r) => (
                        <tr key={r.id} className="align-top">
                            <td className="px-4 py-2 text-xs text-surface-500 whitespace-nowrap">{when(r.created_at)}</td>
                            <td className="px-4 py-2">
                                <p className="text-surface-800">{TYPE_LABEL[r.type] ?? r.type}</p>
                                {r.expense ? (
                                    <Link to={`/expenses/${r.expense.id}`} className="text-xs text-brand-600 hover:underline">{r.expense.reference_number} · {r.expense.title}</Link>
                                ) : r.note ? <p className="text-xs text-surface-500">{r.note}</p> : null}
                            </td>
                            <td className="px-4 py-2 text-xs text-surface-600">{person(r.creator)}</td>
                            <td className={clsx("px-4 py-2 text-right font-medium whitespace-nowrap", Number(r.amount) < 0 ? "text-danger" : "text-success")}>
                                {Number(r.amount) < 0 ? "−" : "+"}{kes(Math.abs(Number(r.amount)))}
                            </td>
                            <td className="px-4 py-2 text-right text-surface-700 whitespace-nowrap">{kes(r.balance_after)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between px-4 py-3 text-xs">
                    <button className="btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>← Newer</button>
                    <span className="text-surface-400">Page {data.current_page} of {data.last_page}</span>
                    <button className="btn-ghost btn-sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Older →</button>
                </div>
            )}
        </div>
    );
}

function TopupHistory({ accountId }: { accountId: number }) {
    const { data } = useQuery({ queryKey: ["imprest-topups", accountId], queryFn: () => imprestApi.topups(accountId) });
    const rows = (data?.data ?? []).filter((t) => t.status !== "pending" && t.status !== "sent");
    if (!rows.length) return null;
    const line = (t: ImprestTopup) =>
        t.status === "received"
            ? `${kes(t.received_amount)} received by ${person(t.receiver)} · ${when(t.received_at)}${Number(t.received_amount) !== Number(t.sent_amount) ? ` (sent ${kes(t.sent_amount)})` : ""}`
            : t.status === "declined" ? `Declined by ${person(t.decliner)}: ${t.decline_reason}` : "Cancelled by the requester";
    return (
        <div className="card divide-y divide-surface-100">
            <p className="px-4 py-3 text-sm font-semibold text-surface-900">Top-ups</p>
            {rows.map((t) => (
                <div key={t.uuid} className="px-4 py-2 text-sm">
                    <p className="text-surface-800">{line(t)}</p>
                    <p className="text-xs text-surface-400">
                        {t.requester ? `Asked by ${person(t.requester)} · ${when(t.created_at)}` : "Loaded directly"}
                        {t.sent_reference ? ` · ${t.sent_reference}` : ""}
                    </p>
                </div>
            ))}
        </div>
    );
}

// ── page ────────────────────────────────────────────────────────────────────

export default function ImprestPage() {
    const { data, isLoading } = useQuery({ queryKey: ["imprest"], queryFn: () => imprestApi.summary() });

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header">
                <h1 className="page-title">Imprest</h1>
                <p className="page-subtitle">
                    The petty-cash float. Expenses paid from it come off as they are recorded; top-ups count only once the
                    custodian confirms they arrived.
                </p>
            </div>
            {isLoading && <p className="text-sm text-surface-400">Loading…</p>}
            {data && data.accounts.length === 0 && (data.can_set_up ? <Setup /> : (
                <div className="card card-body text-sm text-surface-600">The imprest hasn't been set up yet. The super admin sets it up.</div>
            ))}
            {data?.accounts.map((a) => <Account key={a.id} a={a} isSuperAdmin={data.is_super_admin} />)}
        </div>
    );
}
