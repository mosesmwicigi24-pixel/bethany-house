import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleApi, type DialCountry } from "@/api/people";

/**
 * A phone typed the way people say it — country, then the number — and sent
 * to the server as +E.164. The server is the judge (App\Rules\CustomerPhone
 * over Phone::e164, the hub's one phone rule); this only composes the value
 * and catches the obvious slips before a round trip.
 *
 * Kenya is the default. The country list is the hub's own reference table
 * (GET /v1/countries, the same one the storefront reads); if it cannot be
 * loaded the field still works for Kenya and for any number typed with +.
 */

const KENYA: DialCountry = { code: "KE", name: "Kenya", phone_code: "+254", flag: "🇰🇪" };
/** Neighbours and the places staff family most often are, pinned to the top. */
const PINNED = ["KE", "UG", "TZ", "RW", "BI", "SS", "ET", "ZM", "CD"];

export function useDialCountries(): DialCountry[] {
    const { data } = useQuery({ queryKey: ["dial-countries"], queryFn: () => peopleApi.dialCountries(), staleTime: 24 * 3600_000, retry: false });
    return useMemo(() => {
        const rows = (data?.data ?? []).filter(c => c.phone_code && /^\+\d{1,4}$/.test(c.phone_code));
        if (!rows.some(c => c.code === "KE")) rows.unshift(KENYA);
        const pinned = PINNED.map(code => rows.find(r => r.code === code)).filter((r): r is DialCountry => !!r);
        const rest = rows.filter(r => !PINNED.includes(r.code)).sort((a, b) => a.name.localeCompare(b.name));
        return [...pinned, ...rest];
    }, [data]);
}

/** Which country a +number belongs to: the longest dialling code that prefixes it. */
export function countryOf(e164: string, countries: DialCountry[]): DialCountry | null {
    const digits = e164.replace(/\D/g, "");
    let best: DialCountry | null = null;
    for (const c of countries) {
        const code = (c.phone_code ?? "").replace(/\D/g, "");
        if (code && digits.startsWith(code) && (!best || code.length > (best.phone_code ?? "").replace(/\D/g, "").length)) best = c;
    }
    return best;
}

/** "+254722123456" → "+254 722 123 456". Anything not +digits is shown as stored. */
export function formatPhone(value: string | null | undefined, countries: DialCountry[]): string {
    if (!value) return "";
    if (!/^\+\d{6,15}$/.test(value)) return value;
    const c = countryOf(value, countries);
    const code = c?.phone_code?.replace(/\D/g, "") ?? (value.startsWith("+254") ? "254" : value.slice(1, 4));
    const rest = value.slice(1 + code.length);
    const groups = rest.length === 9 ? [rest.slice(0, 3), rest.slice(3, 6), rest.slice(6)] : rest.match(/.{1,3}/g) ?? [rest];
    return `+${code} ${groups.join(" ")}`;
}

/** The value to send: "" when empty, the number as typed when it already carries +, else code + national digits. */
export function composePhone(country: DialCountry, local: string): string {
    const t = local.trim();
    if (!t) return "";
    if (t.startsWith("+")) return t;
    if (/[A-Za-z]/.test(t)) return t;               // let the server say why
    let digits = t.replace(/\D/g, "");
    if (digits.startsWith("00")) return `+${digits.slice(2)}`;
    const code = (country.phone_code ?? "+254").replace(/\D/g, "");
    if (digits.startsWith(code) && digits.length > code.length + 6) return `+${digits}`;
    digits = digits.replace(/^0+/, "");
    return `+${code}${digits}`;
}

/** The slip a person would want pointed out before saving, or null. */
export function phoneProblem(value: string): string | null {
    const t = value.trim();
    if (!t) return null;
    if (/[A-Za-z]/.test(t)) return "Numbers only — a note does not belong here.";
    if (/[/,]/.test(t)) return "One number per contact.";
    const digits = t.replace(/\D/g, "");
    if (digits.startsWith("254") && digits.length !== 12) return "A Kenyan number has 9 digits after the 0 — like 0722 123 456.";
    if (digits.length < 8) return "That is not a full phone number.";
    if (digits.length > 15) return "That is too long for a phone number.";
    return null;
}

function splitValue(value: string, countries: DialCountry[]): { country: DialCountry; local: string } {
    const kenya = countries.find(c => c.code === "KE") ?? KENYA;
    if (!value) return { country: kenya, local: "" };
    if (/^\+\d+$/.test(value)) {
        const c = countryOf(value, countries);
        if (c) {
            const rest = value.slice(1 + (c.phone_code ?? "").replace(/\D/g, "").length);
            return { country: c, local: rest.length === 9 ? `${rest.slice(0, 3)} ${rest.slice(3, 6)} ${rest.slice(6)}` : rest };
        }
    }
    return { country: kenya, local: value };       // a legacy value: shown as stored, untouched until edited
}

export function PhoneField({ id, label, value, onChange, error, hint }: {
    id: string; label: string; value: string; onChange: (v: string) => void; error?: string | null; hint?: string;
}) {
    const countries = useDialCountries();
    // Seeded once from the stored value; after that the person drives it.
    const [state, setState] = useState(() => splitValue(value, countries.length ? countries : [KENYA]));
    const [touched, setTouched] = useState(false);
    const country = countries.find(c => c.code === state.country.code) ?? state.country;
    const composed = composePhone(country, state.local);
    const preview = touched && composed && !phoneProblem(composed) ? formatPhone(composed, countries) : null;
    const update = (next: { country: DialCountry; local: string }) => {
        setState(next); setTouched(true);
        onChange(composePhone(next.country, next.local));
    };
    const describedBy = clsx(error && `${id}-error`, (preview || hint) && `${id}-hint`) || undefined;

    return (
        <div>
            <label htmlFor={id} className="block text-xs font-semibold text-surface-700 mb-1.5">{label}</label>
            <div className={clsx("flex rounded-field border bg-field transition-colors focus-within:bg-white focus-within:ring-1",
                error ? "border-danger focus-within:ring-danger" : "border-line focus-within:border-brand-500 focus-within:ring-brand-500")}>
                <select aria-label={`${label}: country code`} value={country.code}
                    onChange={e => { const c = countries.find(x => x.code === e.target.value); if (c) update({ country: c, local: state.local }); }}
                    className="shrink-0 w-[118px] rounded-l-field border-0 border-r border-line bg-transparent pl-3 pr-7 text-sm text-surface-800 focus:outline-none focus:ring-0 min-h-[48px]">
                    {countries.map(c => <option key={c.code} value={c.code}>{c.flag ? `${c.flag} ` : ""}{c.phone_code}{c.code === country.code ? "" : ` ${c.name}`}</option>)}
                </select>
                <input id={id} type="tel" inputMode="tel" autoComplete="off" value={state.local}
                    onChange={e => update({ country, local: e.target.value })}
                    placeholder={country.code === "KE" ? "0722 123 456" : "Number"}
                    aria-invalid={!!error} aria-describedby={describedBy}
                    className="min-w-0 flex-1 rounded-r-field border-0 bg-transparent px-3 text-[15px] text-surface-900 placeholder:text-surface-400 focus:outline-none focus:ring-0 min-h-[48px]" />
            </div>
            {error ? <p id={`${id}-error`} className="field-error" role="alert">{error}</p>
                : preview ? <p id={`${id}-hint`} className="mt-1 text-2xs text-surface-500">Saved as <span className="font-medium text-surface-700 tabular-nums">{preview}</span></p>
                : hint ? <p id={`${id}-hint`} className="mt-1 text-2xs text-surface-500">{hint}</p> : null}
        </div>
    );
}
