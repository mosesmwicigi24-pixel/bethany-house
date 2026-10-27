import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { Link, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleApi, type PersonFile } from "@/api/people";
import { leaveApi } from "@/api/leave";
import { advancesApi, kes } from "@/api/payroll";
import { attendanceApi } from "@/api/attendance";
import { timesheetsApi } from "@/api/timesheets";
import { DocumentsCard, daysToExpiry } from "@/components/people/DocumentsCard";
import { LeaveCard } from "@/components/people/LeaveCard";
import { RecognitionsCard } from "@/components/people/RecognitionsCard";
import { SigningCard, TrainingCard } from "@/components/people/TrainingSigningCards";
import { SensitiveCard } from "@/components/people/SensitiveCard";
import { PayStructureCard } from "@/components/people/PayStructureCard";
import { EmergencyContactCard } from "@/components/people/EmergencyContactCard";
import { EditFileForm, draftOf, type FileDraft, type FileDraftKey } from "@/components/people/EditFileForm";
import { Avatar, CardHead, Chip, CopyButton, Field, Icon, WhatsAppIcon, ageYears, fmtDay, tenure, type IconName } from "@/components/people/fileKit";
import { ErrorState } from "@/components/ui/EmptyState";
import { usePermissions } from "@/hooks/usePermissions";
import { waNumber } from "@/lib/whatsapp";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * One person's employee file (People PR 2; redesigned 27 Oct 2026 — owner:
 * "improve the UI for this page … add every nuance that will improve the
 * feel").
 *
 * One page, read top to bottom: who they are, the four numbers a manager
 * reaches for, then each part of the file under a sticky section bar that
 * follows the scroll. What each viewer sees is unchanged — every card still
 * asks the server, and the stats strip shows only what the viewer's own
 * permissions already open elsewhere (leave on this file; advances on Payroll
 * with payroll.view; clock-ins on Attendance with attendance.view_team).
 */

const TYPE_LABEL = { permanent: "Permanent", contract: "Contract", casual: "Casual", intern: "Intern" } as const;
const PAY_LABEL = { mpesa: "M-Pesa", bank: "Bank", cash: "Cash" } as const;

const SECTIONS = [
    { id: "overview", label: "Overview" },
    { id: "employment", label: "Employment" },
    { id: "pay", label: "Pay & identifiers" },
    { id: "leave", label: "Leave" },
    { id: "documents", label: "Documents" },
    { id: "training", label: "Training & signatures" },
    { id: "recognitions", label: "Recognitions" },
] as const;
type SectionId = typeof SECTIONS[number]["id"];
const anchor = (id: SectionId) => `pf-${id}`;

function scrollToSection(id: SectionId) {
    document.getElementById(anchor(id))?.scrollIntoView({ behavior: "smooth", block: "start" });
}

// ─── Section bar with scroll-spy ──────────────────────────────────────────────

function SectionNav({ active, onPick }: { active: SectionId; onPick: (id: SectionId) => void }) {
    const bar = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const el = bar.current?.querySelector<HTMLElement>(`[data-section="${active}"]`);
        const box = bar.current;
        if (el && box) {
            const left = el.offsetLeft - 16, right = el.offsetLeft + el.offsetWidth + 16;
            if (left < box.scrollLeft) box.scrollTo({ left, behavior: "smooth" });
            else if (right > box.scrollLeft + box.clientWidth) box.scrollTo({ left: right - box.clientWidth, behavior: "smooth" });
        }
    }, [active]);
    return (
        <nav aria-label="Sections of this file"
            className="sticky top-0 z-20 -mx-3 md:-mx-8 2xl:-mx-10 px-3 md:px-8 2xl:px-10 py-2.5 bg-surface-canvas/85 backdrop-blur-md border-b border-line/80">
            <div ref={bar} className="flex gap-1 overflow-x-auto no-scrollbar">
                {SECTIONS.map(s => (
                    <a key={s.id} href={`#${anchor(s.id)}`} data-section={s.id} aria-current={active === s.id ? "true" : undefined}
                        onClick={e => { e.preventDefault(); onPick(s.id); }}
                        className={clsx("shrink-0 rounded-full px-3.5 py-2 text-sm font-medium transition-colors whitespace-nowrap",
                            active === s.id ? "bg-surface-900 text-white" : "text-surface-600 hover:bg-white hover:text-surface-900")}>
                        {s.label}
                    </a>
                ))}
            </div>
        </nav>
    );
}

function useScrollSpy(ready: boolean): [SectionId, (id: SectionId) => void] {
    const [active, setActive] = useState<SectionId>("overview");
    const lock = useRef(0);
    const seen = useRef(new Map<string, boolean>());
    useEffect(() => {
        if (!ready) return;
        const els = SECTIONS.map(s => document.getElementById(anchor(s.id))).filter((e): e is HTMLElement => !!e);
        const io = new IntersectionObserver(entries => {
            entries.forEach(e => seen.current.set(e.target.id, e.isIntersecting));
            if (Date.now() < lock.current) return;
            const first = SECTIONS.find(s => seen.current.get(anchor(s.id)));
            if (first) setActive(first.id);
        }, { rootMargin: "-72px 0px -55% 0px", threshold: 0 });
        els.forEach(e => io.observe(e));
        return () => io.disconnect();
    }, [ready]);
    const pick = useCallback((id: SectionId) => {
        lock.current = Date.now() + 900;          // let the smooth scroll land before the spy speaks again
        setActive(id);
        scrollToSection(id);
    }, []);
    return [active, pick];
}

function Section({ id, label, children }: { id: SectionId; label: string; children: ReactNode }) {
    return (
        <section id={anchor(id)} aria-label={label} className="scroll-mt-20 space-y-5">
            {children}
        </section>
    );
}

// ─── Hero ─────────────────────────────────────────────────────────────────────

function StatusChip({ person }: { person: PersonFile }) {
    const ended = person.profile.end_date && person.profile.end_date <= localToday();
    if (ended) return <Chip tone="danger">Left {fmtDay(person.profile.end_date)}</Chip>;
    if (person.status === "active") return <Chip tone="success"><span className="w-1.5 h-1.5 rounded-full bg-success-vivid" />Active</Chip>;
    return <Chip tone="neutral"><span className="w-1.5 h-1.5 rounded-full bg-surface-400" /><span className="capitalize">{person.status || "Inactive"}</span></Chip>;
}

function MoreMenu({ items }: { items: { label: string; icon: ReactNode; onClick?: () => void; href?: string; external?: boolean }[] }) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!open) return;
        const off = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false); };
        const esc = (e: KeyboardEvent) => { if (e.key === "Escape") setOpen(false); };
        document.addEventListener("mousedown", off);
        document.addEventListener("keydown", esc);
        return () => { document.removeEventListener("mousedown", off); document.removeEventListener("keydown", esc); };
    }, [open]);
    if (!items.length) return null;
    return (
        <div ref={box} className="relative">
            <button type="button" onClick={() => setOpen(o => !o)} aria-haspopup="menu" aria-expanded={open} aria-label="More actions"
                className="btn-secondary btn-sm !px-0 w-10 min-w-[40px]">
                <Icon name="dots" className="w-5 h-5" strokeWidth={2.4} />
            </button>
            {open && (
                <div role="menu" className="absolute right-0 top-full mt-2 z-30 w-60 card-raised py-2 animate-slide-down">
                    {items.map(it => it.href ? (
                        <a key={it.label} role="menuitem" href={it.href} target={it.external ? "_blank" : undefined} rel={it.external ? "noopener noreferrer" : undefined}
                            onClick={() => setOpen(false)} className="dropdown-item">{it.icon}{it.label}</a>
                    ) : (
                        <button key={it.label} role="menuitem" type="button" onClick={() => { setOpen(false); it.onClick?.(); }} className="dropdown-item">{it.icon}{it.label}</button>
                    ))}
                </div>
            )}
        </div>
    );
}

function Hero({ person, canAttendance, onEdit, editing, menu }: {
    person: PersonFile; canAttendance: boolean; onEdit: () => void; editing: boolean; menu: ReactNode;
}) {
    const p = person.profile;
    const t = tenure(p.hire_date, p.end_date);
    return (
        <div className="card overflow-hidden">
            <div aria-hidden className="h-20 md:h-24 bg-gradient-to-r from-brand-100 via-brand-50 to-amber-50" />
            <div className="px-5 md:px-7 pb-6">
                <div className="flex flex-col lg:flex-row lg:items-start gap-4 lg:gap-6">
                    <div className="-mt-10 md:-mt-12 w-fit rounded-full ring-4 ring-white bg-white shadow-card">
                        <Avatar name={person.name} url={person.avatar_url} size="xl" />
                    </div>
                    <div className="min-w-0 flex-1 lg:pt-4">
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <h1 className="font-display text-2xl md:text-[28px] font-extrabold tracking-tight text-surface-900 leading-tight">{person.name}</h1>
                            <StatusChip person={person} />
                        </div>
                        <p className="mt-1 text-[15px] text-surface-600">{p.job_title ?? <span className="text-surface-400">No job title yet</span>}</p>
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {p.department && <Chip icon="briefcase" tone="brand">{p.department}</Chip>}
                            {person.outlets.map(o => <Chip key={o.id} icon="store">{o.name}</Chip>)}
                            <Chip>{TYPE_LABEL[p.employment_type]}</Chip>
                            {t && <Chip icon="calendar" tone={t.future ? "amber" : "neutral"}>{t.future ? t.words : `${t.words} · since ${fmtDay(p.hire_date)}`}</Chip>}
                        </div>
                        <dl className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-surface-600">
                            {person.email && (
                                <div className="flex items-center gap-1.5 min-w-0"><dt className="sr-only">Email</dt><Icon name="mail" className="w-4 h-4 text-surface-400" />
                                    <dd className="flex items-center min-w-0"><a href={`mailto:${person.email}`} className="truncate hover:text-brand-700 hover:underline">{person.email}</a><CopyButton value={person.email} label="Copy email" /></dd></div>
                            )}
                            {person.phone && (
                                <div className="flex items-center gap-1.5"><dt className="sr-only">Phone</dt><Icon name="phone" className="w-4 h-4 text-surface-400" />
                                    <dd className="flex items-center"><a href={`tel:${person.phone.replace(/[^\d+]/g, "")}`} className="tabular-nums hover:text-brand-700 hover:underline">{person.phone}</a><CopyButton value={person.phone} label="Copy phone number" /></dd></div>
                            )}
                            {person.reports_to && (
                                <div className="flex items-center gap-1.5"><dt className="text-surface-400">Reports to</dt>
                                    <dd><Link to={`/people/${person.reports_to.id}`} className="font-medium text-surface-800 hover:text-brand-700 hover:underline">{person.reports_to.name}</Link></dd></div>
                            )}
                        </dl>
                    </div>
                    <div className="flex items-center gap-2 lg:pt-4 shrink-0">
                        {canAttendance && <Link to="/settings/attendance" className="btn-secondary btn-sm"><Icon name="clock" className="w-4 h-4" />Attendance</Link>}
                        {person.can.edit && !editing && <button onClick={onEdit} className="btn-primary btn-sm"><Icon name="edit" className="w-4 h-4" />Edit file</button>}
                        {menu}
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Quick stats ──────────────────────────────────────────────────────────────

function Stat({ icon, tone, label, value, sub, onClick, to, loading }: {
    icon: IconName; tone: string; label: string; value: ReactNode; sub?: ReactNode; onClick?: () => void; to?: string; loading?: boolean;
}) {
    const inner = (
        <>
            <span className={clsx("w-10 h-10 rounded-control flex items-center justify-center shrink-0", tone)}><Icon name={icon} className="w-5 h-5" /></span>
            <span className="min-w-0 flex-1 text-left">
                <span className="block text-2xs font-semibold uppercase tracking-wider text-surface-500">{label}</span>
                {loading ? <span className="block skeleton h-6 w-20 mt-1" /> : <span className="block text-xl font-extrabold tracking-tight text-surface-900 tabular-nums truncate">{value}</span>}
                {sub && !loading && <span className="block text-xs text-surface-500 truncate">{sub}</span>}
            </span>
            <Icon name="chevronRight" className="w-4 h-4 text-surface-300 group-hover:text-surface-500 transition-colors" />
        </>
    );
    const cls = "group card flex items-center gap-3 p-4 text-left hover:shadow-card-md transition-shadow focus-visible:ring-2 focus-visible:ring-brand-500";
    return to ? <Link to={to} className={cls}>{inner}</Link> : <button type="button" onClick={onClick} className={cls}>{inner}</button>;
}

function whenWords(iso: string): string {
    const d = new Date(iso);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    const diff = Math.round((today.getTime() - day.getTime()) / 86_400_000);
    const time = d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
    if (diff === 0) return `Today, ${time}`;
    if (diff === 1) return `Yesterday, ${time}`;
    return `${d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" })}, ${time}`;
}

function StatsStrip({ person, onPick }: { person: PersonFile; onPick: (id: SectionId) => void }) {
    const { can } = usePermissions();
    const year = new Date().getFullYear();
    const leave = useQuery({ queryKey: ["person-leave", person.id, year], queryFn: () => leaveApi.forPerson(person.id, year) });
    const showAdvances = can("payroll.view");
    const showClock = can("attendance.view_team");
    const advances = useQuery({ queryKey: ["advances", "paid"], queryFn: () => advancesApi.list("paid"), enabled: showAdvances, staleTime: 60_000 });
    const clock = useQuery({ queryKey: ["person-last-clock", person.id], queryFn: () => attendanceApi.entries({ user_id: String(person.id), per_page: "5" }), enabled: showClock, staleTime: 60_000 });

    const annual = useMemo(() => {
        const tracked = leave.data?.balances.filter(b => b.available !== null) ?? [];
        return tracked.find(b => /annual/i.test(b.type.code) || /annual/i.test(b.type.name)) ?? tracked[0] ?? null;
    }, [leave.data]);
    const open = (advances.data?.data ?? []).filter(a => a.user?.id === person.id && a.status === "paid");
    const owed = open.reduce((s, a) => s + (a.balance ?? 0), 0);
    const expiring = person.documents.filter(d => { const n = daysToExpiry(d); return n !== null && n <= 60; });
    const expired = expiring.filter(d => (daysToExpiry(d) ?? 0) < 0).length;
    const last = clock.data?.data?.[0] ?? null;

    return (
        <div className={clsx("grid grid-cols-1 sm:grid-cols-2 gap-3", (showAdvances && showClock) ? "min-[1400px]:grid-cols-4" : (showAdvances || showClock) ? "xl:grid-cols-3" : "")}>
            <Stat icon="sun" tone="bg-amber-50 text-amber-700" label={annual ? `${annual.type.name} left` : "Leave left"} loading={leave.isLoading}
                value={annual ? <>{annual.available}<span className="text-sm font-semibold text-surface-500"> day{annual.available === 1 ? "" : "s"}</span></> : "—"}
                sub={annual ? `of ${annual.entitled + annual.adjusted} for ${year} · ${annual.used} used` : "No yearly allowance set"}
                onClick={() => onPick("leave")} />
            {showAdvances && (
                <Stat icon="wallet" tone="bg-success-50 text-success-700" label="Advances owed" loading={advances.isLoading}
                    value={advances.isError ? "—" : open.length ? `KES ${kes(owed)}` : "None"}
                    sub={advances.isError ? "Could not load" : open.length ? `${open.length} open advance${open.length === 1 ? "" : "s"}` : "Nothing outstanding"}
                    to="/payroll?tab=advances" />
            )}
            <Stat icon="file" tone={expired ? "bg-danger-50 text-danger-700" : expiring.length ? "bg-warning-50 text-warning-700" : "bg-info-50 text-info-700"}
                label="Documents" value={expiring.length ? `${expiring.length} expiring` : `${person.documents.length} on file`}
                sub={expired ? `${expired} already expired` : expiring.length ? "within 60 days" : person.documents.length ? "None expiring soon" : "Nothing uploaded yet"}
                onClick={() => onPick("documents")} />
            {showClock && (
                <Stat icon="clock" tone="bg-info-50 text-info-700" label="Last clock-in" loading={clock.isLoading}
                    value={last ? whenWords(last.clock_in_at) : "—"}
                    sub={last ? (!last.clock_out_at ? <span className="inline-flex items-center gap-1 text-success-700"><span className="w-1.5 h-1.5 rounded-full bg-success-vivid animate-pulse-soft" />On the clock now{last.outlet ? ` · ${last.outlet.name}` : ""}</span> : last.outlet?.name ?? "") : clock.isError ? "Could not load" : "No clock-ins in your outlets"}
                    to="/settings/attendance" />
            )}
        </div>
    );
}

// ─── Read views ───────────────────────────────────────────────────────────────

function Group({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <h4 className="text-xs font-bold text-surface-900 mb-3">{title}</h4>
            <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-x-6 gap-y-4">{children}</dl>
        </div>
    );
}

function localToday(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function EmploymentView({ person, payGroupName, onAdd }: { person: PersonFile; payGroupName: string; onAdd?: (k: FileDraftKey) => void }) {
    const p = person.profile;
    const t = tenure(p.hire_date, p.end_date);
    const age = ageYears(p.date_of_birth);
    const add = (k: FileDraftKey) => (onAdd ? () => onAdd(k) : undefined);
    const bank = p.bank_name ? [p.bank_name, p.bank_branch, p.bank_account_name].filter(Boolean).join(" · ") : null;
    return (
        <div className="card">
            <CardHead icon="briefcase" title="Employment"
                subtitle={p.updated_at ? `File last updated ${fmtDay(p.updated_at)}` : "Nothing filled in yet."}
                actions={onAdd ? <button onClick={() => onAdd("job_title")} className="btn-ghost btn-sm"><Icon name="edit" className="w-3.5 h-3.5" />Edit</button> : undefined} />
            <div className="card-body">
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 lg:divide-x lg:divide-line">
                    <Group title="Role">
                        <Field label="Job title" value={p.job_title} onAdd={add("job_title")} />
                        <Field label="Department" value={p.department} onAdd={add("department")} />
                        <Field label="Employment type" value={TYPE_LABEL[p.employment_type]} />
                        <Field label="Pay group" value={payGroupName} />
                    </Group>
                    <div className="lg:pl-8"><Group title="Dates">
                        <Field label="Hire date" value={p.hire_date ? <>{fmtDay(p.hire_date)}{t && !t.future && <span className="text-surface-500"> · {t.words}</span>}</> : null} onAdd={add("hire_date")} />
                        <Field label="End date" value={p.end_date ? fmtDay(p.end_date) : null} emptyText="None — still with us" />
                        <Field label="Date of birth" value={p.date_of_birth ? <>{fmtDay(p.date_of_birth)}{age !== null && <span className="text-surface-500"> · {age} yrs</span>}</> : null} onAdd={add("date_of_birth")} />
                    </Group></div>
                    <div className="lg:pl-8"><Group title="Pay">
                        <Field label="Pay method" value={p.pay_method ? PAY_LABEL[p.pay_method] : null} onAdd={add("pay_method")} />
                        <Field label="Bank" value={bank ? <>{bank}{person.masked.bank_account_no && <span className="block font-mono text-xs text-surface-500 mt-0.5">Account {person.masked.bank_account_no}</span>}</> : null}
                            onAdd={add("bank_name")} emptyText={p.pay_method === "bank" ? "Bank not set" : "Not set"} />
                    </Group></div>
                </div>
                {(p.notes || onAdd) && (
                    <div className="mt-7 pt-6 border-t border-line">
                        <Field label="Notes" value={p.notes ? <span className="whitespace-pre-wrap text-surface-800">{p.notes}</span> : null} onAdd={add("notes")} emptyText="No notes" />
                    </div>
                )}
            </div>
        </div>
    );
}

function PlacementCard({ person }: { person: PersonFile }) {
    const p = person.profile;
    return (
        <div className="card h-full">
            <CardHead icon="user" tone="neutral" title="At a glance" subtitle="Where they work and what they can open in the hub." />
            <div className="card-body">
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                    <Field label="Reports to" value={person.reports_to ? <Link to={`/people/${person.reports_to.id}`} className="font-medium hover:text-brand-700 hover:underline">{person.reports_to.name}</Link> : null} emptyText="No one set" />
                    <Field label="Since" value={p.hire_date ? fmtDay(p.hire_date) : null} emptyText="Hire date not set" />
                    <Field className="sm:col-span-2" label={person.outlets.length === 1 ? "Outlet" : "Outlets"} value={person.outlets.length ? <span className="flex flex-wrap gap-1.5">{person.outlets.map(o => <Chip key={o.id} icon="store">{o.name}</Chip>)}</span> : null} emptyText="No outlet" />
                    <Field className="sm:col-span-2" label="Hub roles" value={person.roles.length ? <span className="flex flex-wrap gap-1.5">{person.roles.map(r => <Chip key={r} icon="shield" tone="info"><span className="capitalize">{r.replace(/_/g, " ")}</span></Chip>)}</span> : null} emptyText="No role" />
                </dl>
            </div>
        </div>
    );
}

// ─── Loading ──────────────────────────────────────────────────────────────────

function FileSkeleton() {
    return (
        <div className="space-y-5" aria-busy="true" aria-label="Loading the employee file">
            <div className="skeleton h-5 w-24 rounded-full" />
            <div className="card overflow-hidden">
                <div className="h-20 md:h-24 bg-surface-100" />
                <div className="px-5 md:px-7 pb-6 flex flex-col lg:flex-row gap-5">
                    <div className="-mt-10 md:-mt-12 w-20 h-20 md:w-24 md:h-24 rounded-full ring-4 ring-white skeleton" />
                    <div className="flex-1 space-y-3 lg:pt-4"><div className="skeleton h-7 w-56" /><div className="skeleton h-4 w-40" /><div className="flex gap-2"><div className="skeleton h-7 w-24 rounded-full" /><div className="skeleton h-7 w-32 rounded-full" /><div className="skeleton h-7 w-20 rounded-full" /></div></div>
                </div>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">{[0, 1, 2, 3].map(i => <div key={i} className="card p-4 flex gap-3"><div className="skeleton w-10 h-10 rounded-control" /><div className="flex-1 space-y-2"><div className="skeleton h-3 w-20" /><div className="skeleton h-5 w-24" /></div></div>)}</div>
            <div className="skeleton h-11 w-full rounded-full" />
            <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">{[0, 1].map(i => <div key={i} className="card p-6 space-y-4"><div className="skeleton h-5 w-40" /><div className="skeleton h-20 w-full rounded-2xl" /></div>)}</div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function PersonPage() {
    const { id } = useParams();
    const personId = Number(id);
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const { data, isLoading, error, refetch } = useQuery({ queryKey: ["person", personId], queryFn: () => peopleApi.show(personId), enabled: Number.isFinite(personId), retry: false });
    const { data: payGroups } = useQuery({ queryKey: ["pay-groups"], queryFn: () => timesheetsApi.groups() });
    const person: PersonFile | undefined = data?.person;

    const [editing, setEditing] = useState(false);
    const [focus, setFocus] = useState<FileDraftKey | undefined>();
    const [serverErrors, setServerErrors] = useState<ApiError["errors"]>();
    const [docSignal, setDocSignal] = useState(0);
    const [signSignal, setSignSignal] = useState(0);
    const departments = useQuery({ queryKey: ["people-departments"], queryFn: () => peopleApi.list({ per_page: "5" }), enabled: editing, staleTime: 300_000 });
    const [active, pick] = useScrollSpy(!!person);

    // A different person in the same page instance starts clean.
    useEffect(() => { setEditing(false); setServerErrors(undefined); }, [personId]);

    const save = useMutation({
        mutationFn: (d: FileDraft) => peopleApi.update(personId, d),
        onSuccess: r => {
            qc.setQueryData(["person", personId], { person: r.person });
            qc.invalidateQueries({ queryKey: ["people"] });
            setEditing(false); setServerErrors(undefined);
            toast.success(r.message);
            requestAnimationFrame(() => scrollToSection("employment"));
        },
        onError: (e: ApiError) => { setServerErrors(e.errors); toast.error(e.message); },
    });
    const refresh = () => qc.invalidateQueries({ queryKey: ["person", personId] });

    const openEditor = (k?: FileDraftKey) => {
        setFocus(k); setServerErrors(undefined); setEditing(true);
        requestAnimationFrame(() => pick("employment"));
    };

    if (isLoading || (!person && !error)) return <FileSkeleton />;
    if (error || !person) {
        const e = error as ApiError | null;
        return (
            <div className="space-y-4">
                <Link to="/people" className="btn-ghost btn-sm text-xs"><Icon name="arrowLeft" className="w-3.5 h-3.5" />People</Link>
                <div className="card"><ErrorState title={e?.status === 403 ? "Not in your outlets" : e?.status === 404 ? "No such person" : "Could not open this file"} description={e?.message} onRetry={e?.status && e.status < 500 ? undefined : () => void refetch()} /></div>
            </div>
        );
    }

    const p = person.profile;
    const payGroupName = payGroups?.data.find(g => g.id === p.pay_group_id)?.name ?? "Default group";
    const wa = waNumber(person.phone);
    const first = person.first_name || person.name.split(" ")[0];
    const menuItems = [
        { label: "Copy link to this file", icon: <Icon name="link" className="w-4 h-4 text-surface-400" />, onClick: () => { void navigator.clipboard?.writeText(window.location.href).then(() => toast.success("Link copied."), () => toast.error("Could not copy the link.")); } },
        ...(person.phone ? [{ label: `Call ${first}`, icon: <Icon name="phone" className="w-4 h-4 text-surface-400" />, href: `tel:${person.phone.replace(/[^\d+]/g, "")}` }] : []),
        ...(wa ? [{ label: `WhatsApp ${first}`, icon: <WhatsAppIcon className="w-4 h-4 text-success-600" />, href: `https://wa.me/${wa}`, external: true }] : []),
        ...(person.email ? [{ label: `Email ${first}`, icon: <Icon name="mail" className="w-4 h-4 text-surface-400" />, href: `mailto:${person.email}` }] : []),
        ...(person.can.edit ? [
            { label: "Add a document", icon: <Icon name="upload" className="w-4 h-4 text-surface-400" />, onClick: () => { setDocSignal(n => n + 1); pick("documents"); } },
            { label: "Ask to sign", icon: <Icon name="pen" className="w-4 h-4 text-surface-400" />, onClick: () => { setSignSignal(n => n + 1); pick("training"); } },
        ] : []),
    ];
    const contactSave = async (body: Parameters<typeof peopleApi.update>[1]) => {
        if (person.can.edit) {
            const r = await peopleApi.update(personId, body);
            qc.setQueryData(["person", personId], { person: r.person });
            return r.person;
        }
        // Their own file, without hr.edit: saved through My file, then re-read as this viewer.
        const r = await peopleApi.updateMyFile(body);
        void qc.invalidateQueries({ queryKey: ["my-file"] });
        await refresh();
        return r.person;
    };

    return (
        <div className="space-y-5 animate-fade-in">
            <Link to="/people" className="inline-flex items-center gap-1.5 text-sm font-medium text-surface-500 hover:text-surface-900">
                <Icon name="arrowLeft" className="w-4 h-4" />People
            </Link>

            <Hero person={person} canAttendance={can("attendance.view_team")} editing={editing} onEdit={() => openEditor()} menu={<MoreMenu items={menuItems} />} />

            <StatsStrip person={person} onPick={pick} />

            <SectionNav active={active} onPick={pick} />

            <Section id="overview" label="Overview">
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-5 items-stretch">
                    <EmergencyContactCard person={person} canEdit={!!person.can.edit_contact} save={contactSave} titleId="pf-emergency-title" />
                    <PlacementCard person={person} />
                </div>
            </Section>

            <Section id="employment" label="Employment">
                {editing ? (
                    <EditFileForm initial={draftOf(p)} payGroups={payGroups?.data ?? []} departments={departments.data?.departments ?? []}
                        maskedAccount={person.masked.bank_account_no} focus={focus} saving={save.isPending} serverErrors={serverErrors}
                        onSave={d => save.mutate(d)} onClose={() => { setEditing(false); setServerErrors(undefined); }} />
                ) : (
                    <EmploymentView person={person} payGroupName={payGroupName} onAdd={person.can.edit ? openEditor : undefined} />
                )}
            </Section>

            <Section id="pay" label="Pay and identifiers">
                <div className={clsx("grid grid-cols-1 gap-5 items-stretch", person.can.view_sensitive && "xl:grid-cols-2")}>
                    <SensitiveCard
                        masked={person.masked}
                        canView={person.can.view_sensitive}
                        canEdit={person.can.edit_sensitive}
                        fetchFull={async () => (await peopleApi.sensitive(personId)).sensitive}
                        save={person.can.edit_sensitive ? async d => { const r = await peopleApi.updateSensitive(personId, d); void refresh(); return r.sensitive; } : undefined}
                    />
                    <PayStructureCard userId={personId} canView={person.can.view_sensitive} />
                </div>
            </Section>

            <Section id="leave" label="Leave">
                <LeaveCard userId={personId} />
            </Section>

            <Section id="documents" label="Documents">
                <DocumentsCard
                    documents={person.documents}
                    openSignal={docSignal}
                    fetchBlob={docId => peopleApi.fetchDocumentBlob(personId, docId)}
                    onUpload={person.can.edit ? async (file, kind, title, expiresAt) => { const r = await peopleApi.addDocument(personId, file, kind, title, expiresAt); toast.success(r.message); await refresh(); } : undefined}
                    onRemove={person.can.edit ? async d => { try { const r = await peopleApi.removeDocument(personId, d.id); toast.success(r.message); await refresh(); } catch (e) { toast.error((e as ApiError)?.message ?? "Could not remove it."); } } : undefined}
                />
            </Section>

            <Section id="training" label="Training and signatures">
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-5 items-stretch">
                    <TrainingCard courses={person.courses ?? []} />
                    <SigningCard userId={personId} signing={person.signing ?? []} documents={person.documents} canAsk={person.can.edit} onChanged={() => void refresh()} openSignal={signSignal} />
                </div>
            </Section>

            <Section id="recognitions" label="Recognitions">
                <RecognitionsCard recognitions={person.recognitions ?? []} points={person.recognition_points ?? 0} />
            </Section>

            {/* Room for the last section to scroll under the bar. */}
            <div aria-hidden className="h-[30vh]" />
        </div>
    );
}
