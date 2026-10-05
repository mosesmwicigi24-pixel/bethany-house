/**
 * The business runs on Nairobi time (backend `app.timezone` = Africa/Nairobi).
 * A date-only column (due_date, fitting_date, …) is serialized by Laravel as
 * that day's midnight in Nairobi, expressed in UTC: 11 Oct → "2026-10-10T21:00:00.000000Z".
 * Cutting that string to 10 characters gives the PREVIOUS day, and a form that
 * pre-fills with it saves the date one day early every time.
 */
export const BUSINESS_TIME_ZONE = "Africa/Nairobi";

const ymd = new Intl.DateTimeFormat("en-CA", {
    timeZone: BUSINESS_TIME_ZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
});

/**
 * "YYYY-MM-DD" for a date input, read on the business calendar whatever the
 * device's own time zone. Accepts a bare "YYYY-MM-DD" unchanged; returns ""
 * for empty or unparseable input.
 */
export function toBusinessDateInput(value: string | null | undefined): string {
    if (!value) return "";
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? "" : ymd.format(d);
}
