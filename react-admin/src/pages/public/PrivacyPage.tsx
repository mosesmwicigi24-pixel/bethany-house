/**
 * Privacy policy for the Hub and its store app (People PR 8). Public, no
 * login: the app stores need a URL. Plain words; what we read, why, for how
 * long, and who sees it.
 */
export default function PrivacyPage() {
    const updated = "26 October 2026";
    return (
        <div className="min-h-screen bg-surface-50 text-surface-900">
            <div className="mx-auto w-full max-w-2xl px-4 py-10 space-y-6">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">Bethany House</p>
                    <h1 className="text-2xl font-bold">Privacy policy — Bethany House Hub</h1>
                    <p className="text-sm text-surface-500 mt-1">For staff using the Hub in a browser, as an installed web app, or through the Bethany House Hub app. Last updated {updated}.</p>
                </div>
                <Section title="Who this is for">
                    The Hub is Bethany House's internal system for its own staff. You use it because you work for Bethany House; there are no public accounts. Bethany House Ltd, Nairobi, Kenya is the controller of the data in it.
                </Section>
                <Section title="What we read and why">
                    <ul className="list-disc pl-5 space-y-1">
                        <li><b>Location</b> — only while the clock screen is open, to confirm you are at the shop when you clock in or out. We keep the position and its accuracy for each punch so a manager can review it. The app never reads location in the background. On Android, the app also records whether a fake-location app supplied the position.</li>
                        <li><b>Your employee file</b> — name, contact details, job, hire date, identifiers for statutory filings (ID, KRA PIN, NSSF, SHIF), bank or M-Pesa details for pay, emergency contact, documents you or HR upload. Identifiers and pay details are encrypted at rest and shown only to HR and finance after they confirm it is them, and every reveal is recorded.</li>
                        <li><b>Work records</b> — hours, shifts, leave, timesheets, payslips, advances. These exist to pay you correctly and to meet Kenyan employment and tax law.</li>
                        <li><b>Your device</b> — a random id per browser or app install, the device type, and the app version, so you can see and remove the devices signed in to your account and so a punch can be tied to a known device.</li>
                        <li><b>Notifications</b> — a push token for your browser or phone, used only to tell you about your shifts, leave, pay and messages. Remove it by signing out or turning notifications off.</li>
                    </ul>
                </Section>
                <Section title="What we do not do">
                    No advertising, no sale of data, no third-party analytics or tracking, no location outside the clock screen, no reading of your contacts, photos or other apps. Push notifications pass through Expo's service (for the app) or your browser's push service; those carry the message, not your data.
                </Section>
                <Section title="Who sees it">
                    You see your own records. Your manager sees your attendance, shifts, leave and timesheet. HR sees employee files. Finance sees pay. The super admin can see everything, and every look at sensitive data is in the audit trail. Statutory filings go to KRA, NSSF and SHA as the law requires.
                </Section>
                <Section title="How long">
                    Employment and pay records are kept for the period Kenyan law requires after you leave (at least seven years for payroll and tax). Punch locations are kept with the attendance record. Device ids and push tokens are removed when you sign out of that device or remove it from your account.
                </Section>
                <Section title="Your choices">
                    Decline location and you can still use everything except clocking in from the phone; ask your manager to record a punch. Decline notifications and you will see the same information when you open the Hub. To see, correct or export your records, or to ask a question about this policy, write to <a className="text-brand-600 underline" href="mailto:info@bethanyhouse.co.ke">info@bethanyhouse.co.ke</a>.
                </Section>
            </div>
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="rounded-2xl bg-white border border-line shadow-sm p-5">
            <h2 className="text-base font-semibold mb-2">{title}</h2>
            <div className="text-sm text-surface-700 leading-relaxed">{children}</div>
        </section>
    );
}
