/**
 * Privacy policy for the Hub and its store app. Public, no login: the app
 * stores need a URL. Plain words; what we read, why, for how long, who sees
 * it. Keep it in step with Setup › Attendance rules (People C1).
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
                <Section title="Location: what is read and when">
                    <ul className="list-disc pl-5 space-y-1">
                        <li><b>At clock-in and clock-out</b> we keep your position and how precise it was, to confirm you are at the shop. A manager can see it on each punch.</li>
                        <li><b>While you are clocked in</b>, the Bethany House Hub app records your position about every five minutes, or when you move about a hundred metres. This starts when you clock in and stops when you clock out. It never runs off shift, and the web app never does it at all. The app shows a notice on your phone while it is running.</li>
                        <li><b>Leaving the shop</b> while clocked in: if you are more than about 250 metres away for 15 minutes, the Hub tells you first. Depending on the rule the shop has set, the shift is then noted for your manager or clocked out at the time you left. You can always clock out yourself.</li>
                        <li><b>At the kiosk</b> (a tablet at the door): no location is read. You tap your name, type your PIN and the tablet takes one picture of you at the punch, which your manager can see on that punch. The picture is deleted after 90 days with the shift trail. If the tablet has no camera, the punch is recorded without one.</li>
                        <li><b>Fake-location apps</b>: on Android the app records whether another app supplied the position, and the punch is noted for your manager.</li>
                        <li><b>How long</b>: the trail of positions is deleted after 90 days. The positions on the clock-in and clock-out stay with the attendance record.</li>
                        <li><b>Who sees it</b>: you, your manager, HR and the super admin. Nobody else, and never outside Bethany House.</li>
                        <li><b>To stop it</b>: clock out, or turn location off for the app in your phone's settings. Without location you cannot clock in from the phone; ask your manager to record the punch.</li>
                    </ul>
                    <p className="mt-2">These numbers are the shop's current settings. If they change, this page is updated and the change is in the Hub's audit trail.</p>
                </Section>
                <Section title="What else we read and why">
                    <ul className="list-disc pl-5 space-y-1">
                        <li><b>Your employee file</b> — name, contact details, job, hire date, identifiers for statutory filings (ID, KRA PIN, NSSF, SHIF), bank or M-Pesa details for pay, emergency contact, documents you or HR upload. Identifiers and pay details are encrypted at rest and shown only to HR and finance after they confirm it is them, and every reveal is recorded.</li>
                        <li><b>Work records</b> — hours, shifts, leave, timesheets, payslips, advances. These exist to pay you correctly and to meet Kenyan employment and tax law.</li>
                        <li><b>Your device</b> — a random id per browser or app install, the device type, and the app version, so you can see and remove the devices signed in to your account and so a punch can be tied to a known device.</li>
                        <li><b>Checklists and reports</b> — what you fill in on an opening or closing list or an incident report, any photo you attach, and your signature where the form asks for one. Kept with the shop's records; seen by you and the outlet's managers.</li>
                        <li><b>Signatures and training</b> — when HR asks you to sign a document or a policy on your phone, the Hub keeps your signature, the time, the address it was signed from and a signing record on your employee file. Training courses record which lessons you read and your quiz scores, on your file.</li>
                        <li><b>Notifications</b> — a push token for your browser or phone, used only to tell you about your shifts, leave, pay and messages. Remove it by signing out or turning notifications off.</li>
                    </ul>
                </Section>
                <Section title="What we do not do">
                    No advertising, no sale of data, no third-party analytics or tracking, no location off shift, no reading of your contacts, photos or other apps. Push notifications pass through Expo's service (for the app) or your browser's push service; those carry the message, not your data. Map tiles on a manager's screen come from OpenStreetMap; the request carries the map area, not who you are.
                </Section>
                <Section title="Who sees it">
                    You see your own records. Your manager sees your attendance, shifts, leave and timesheet. HR sees employee files. Finance sees pay. The super admin can see everything, and every look at sensitive data is in the audit trail. Statutory filings go to KRA, NSSF and SHA as the law requires.
                </Section>
                <Section title="How long">
                    Employment and pay records are kept for the period Kenyan law requires after you leave (at least seven years for payroll and tax). Punch locations are kept with the attendance record; the on-shift trail and kiosk pictures for 90 days. Device ids and push tokens are removed when you sign out of that device or remove it from your account.
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
