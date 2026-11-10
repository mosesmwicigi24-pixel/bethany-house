import SwiftUI
import BethanyKit

// Payroll › People & pay and Payroll › Commissions (owner, 2026-11-03/04;
// react-admin/src/components/people/PeopleAndPay.tsx, CommissionsPanel.tsx).
// Every figure is the Hub's, in shillings. Who may do what comes from the
// server (`can_edit`, `can_record`, `can_approve`, each row's `can_*`), and
// the deductions switch and approvals ask for step-up there.

// MARK: - People & pay (GET payroll/people; POST payroll/people/{user}/deductions)

struct PayrollPeoplePanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var query = ""
    @State private var version = 0
    @State private var deductionsFor: PayPerson?
    @State private var commissionFor: PayPerson?

    var body: some View {
        RemoteJSON("/v1/admin/payroll/people", version: version, emptyTitle: "No one on payroll yet", isEmpty: { PayPerson.rows($0).isEmpty }) { j, _ in
            let all = PayPerson.rows(j)
            let canEdit = j["can_edit"]?.bool ?? false
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                HStack(alignment: .top) {
                    Text("Government deductions — PAYE, NSSF, SHIF (SHA) and the Housing Levy — apply only to people accounts add them for. \(PayPerson.enrolledCount(all)) of \(all.count) currently have them.")
                        .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    Spacer()
                    TextField("Search staff", text: $query).textFieldStyle(.roundedBorder).frame(maxWidth: 220)
                }
                let rows = PayPerson.filter(all, query: query)
                if rows.isEmpty { Text("No staff match.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                LazyVStack(spacing: Theme.Space.s) {
                    ForEach(rows) { p in row(p, canEdit: canEdit) }
                }
            }
        }
        .sheet(item: $deductionsFor) { p in DeductionsSheet(person: p) { version += 1 } }
        .sheet(item: $commissionFor) { p in RecordCommissionSheet(person: (p.id, p.name)) { version += 1 } }
    }

    private func row(_ p: PayPerson, canEdit: Bool) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    Button(p.name) { env.navigator.go(.people(id: p.id)) }.buttonStyle(.plain).font(Theme.Font.sectionTitle)
                    HStack(spacing: 6) {
                        StatusBadge(p.deductionsLabel, tone: p.deductionsApply ? .success : .neutral)
                        if let next = p.nextChangeLabel { Text(next).font(Theme.Font.caption).foregroundStyle(Theme.Palette.brand) }
                    }
                    Text(lastPaid(p)).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 4) {
                    if let s = p.salary {
                        MoneyText(s)
                        Text("\((p.salaryKind ?? "").humanized.lowercased())\(p.salaryFrom.map { " · from \(PayrollText.date($0))" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    } else {
                        Text("No salary set").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber)
                    }
                    Menu {
                        if canEdit { Button(p.deductionsAction, role: p.deductionsApply ? .destructive : nil) { deductionsFor = p } }
                        if canEdit { Button("Record commission") { commissionFor = p } }
                        Button(p.salary == nil ? "Set salary" : "Change salary") { env.navigator.go(.people(id: p.id)) }
                        Button("Open employee file") { env.navigator.go(.people(id: p.id)) }
                    } label: { Image(systemName: "ellipsis.circle") }
                    .accessibilityLabel("Actions for \(p.name)")
                }
            }
        }
    }

    /// "Last paid KES 38,110.50 · 31 Oct 2026 · mpesa · Oct 2026 · owes KES 2,500.00 · commission due KES 1,500.00"
    private func lastPaid(_ p: PayPerson) -> String {
        var parts: [String] = []
        if let net = p.lastPaidNet, let on = p.lastPaidOn {
            parts.append(["Last paid \(net.formatted())", PayrollText.date(on), p.lastPaidMethod, p.lastPaidRun].compactMap { $0 }.joined(separator: " · "))
        } else { parts.append("Not paid yet") }
        if !p.advancesOwed.isZero { parts.append("owes \(p.advancesOwed.formatted())") }
        if !p.commissionOwed.isZero { parts.append("commission due \(p.commissionOwed.formatted())") }
        if !p.commissionPending.isZero { parts.append("+\(p.commissionPending.formatted()) awaiting approval") }
        return parts.joined(separator: " · ")
    }
}

/// POST payroll/people/{user}/deductions {applies, effective_from, reason} — step-up on the server.
struct DeductionsSheet: View {
    @Environment(AppEnvironment.self) private var env
    let person: PayPerson
    let done: () -> Void
    @State private var from = PayrollDates.firstOfNextMonth()
    @State private var reason: String

    init(person: PayPerson, done: @escaping () -> Void) {
        self.person = person; self.done = done
        _reason = State(initialValue: person.deductionsApply ? "Back on contract" : "Made permanent")
    }

    var body: some View {
        let adding = !person.deductionsApply
        EditSheet(adding ? "Add government deductions · \(person.name)" : "Stop government deductions · \(person.name)",
                  saveLabel: adding ? "Add all four" : "Stop all four",
                  canSave: reason.trimmingCharacters(in: .whitespaces).count >= PayPerson.deductionsReasonMinimum && !from.isEmpty,
                  destructive: !adding, save: {
            let r = try await env.session.client.post("/v1/admin/payroll/people/\(person.id)/deductions", json: .object([
                "applies": .bool(adding), "effective_from": .string(from), "reason": .string(reason.trimmingCharacters(in: .whitespaces)),
            ]))
            toastResult(env, r); done()
        }) { _ in
            Text(adding
                 ? "PAYE, NSSF, SHIF (SHA) and the Housing Levy will be deducted on every pay run whose period ends on or after this date, and the employer's NSSF and Housing Levy added to the labour cost."
                 : "No PAYE, NSSF, SHIF (SHA) or Housing Levy on pay runs whose period ends on or after this date. Kenyan law requires these for employees; stop them only for someone on a contract.")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            DayField("From", $from)
            Field("Reason (kept in the audit log)", $reason)
            Text("Payslips already approved keep what applied to them. You will be asked to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

enum PayrollDates {
    /// The first day of next month in Nairobi, as the console's dialog proposes.
    static func firstOfNextMonth() -> String {
        let cal = Day.calendar
        let start = cal.date(from: cal.dateComponents([.year, .month], from: Date())) ?? Date()
        return Day.string(cal.date(byAdding: .month, value: 1, to: start) ?? start)
    }
}

// MARK: - Commissions (GET payroll/commissions?status; POST …/{id}/approve | void {reason}; POST …/paye {applies})

struct CommissionsPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var status = "open"
    @State private var version = 0
    @State private var recording = false
    @State private var voiding: CommissionRow?

    var body: some View {
        RemoteJSON("/v1/admin/payroll/commissions", query: ["status": status.isEmpty ? nil : status], version: version) { j, _ in
            let page = CommissionsPage(json: j)
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                HStack {
                    Text("PAYE on commission:").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    StatusBadge(page.payeApplies ? "On" : "Off", tone: page.payeApplies ? .info : .neutral)
                    if page.canApprove {
                        AsyncButton(action: {
                            let r = try await env.session.client.post("/v1/admin/payroll/commissions/paye", json: .object(["applies": .bool(!page.payeApplies)]))
                            toastResult(env, r); version += 1
                        }) { Text(page.payeApplies ? "Turn off" : "Turn on") }
                    }
                    Spacer()
                    Picker("Show", selection: $status) { ForEach(CommissionRow.filters, id: \.value) { Text($0.label).tag($0.value) } }.frame(maxWidth: 260)
                    if page.canRecord { Button("Record commission") { recording = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                }
                TileGrid(minWidth: 160) {
                    StatTile("Awaiting approval", value: page.awaitingApproval.formatted(), tone: .warning)
                    StatTile("Owed · next pay run", value: page.owed.formatted(), tone: .info)
                    StatTile("Paid", value: page.paid.formatted(), tone: .success)
                }
                if page.rows.isEmpty { Text("No commissions here yet.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                LazyVStack(spacing: Theme.Space.s) { ForEach(page.rows) { c in row(c) } }
            }
        }
        .sheet(isPresented: $recording) { RecordCommissionSheet(person: nil) { version += 1 } }
        .sheet(item: $voiding) { c in VoidCommissionSheet(commission: c) { version += 1 } }
    }

    private func row(_ c: CommissionRow) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(c.userName).font(Theme.Font.sectionTitle)
                    HStack(spacing: 6) {
                        if let oid = c.orderId { Button(c.transactionRef) { env.navigator.go(.order(id: oid)) }.buttonStyle(.plain).foregroundStyle(Theme.Palette.brand) }
                        else { Text(c.transactionRef) }
                        if let e = c.earnedOn { Text("· earned \(PayrollText.date(e))").foregroundStyle(Theme.textSecondary) }
                    }.font(Theme.Font.secondary)
                    if let n = c.note, !n.isEmpty { Text(n).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).lineLimit(1) }
                    HStack(spacing: 6) {
                        StatusBadge(c.statusLabel, tone: c.status == "recorded" ? .warning : c.status == "approved" ? .info : c.status == "paid" ? .success : .neutral)
                        if let d = c.statusDetail { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    }
                    Text("Recorded by \(c.recordedBy ?? "—")" + (c.approvedBy.map { " · approved by \($0)" } ?? "")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                Spacer()
                VStack(alignment: .trailing, spacing: 4) {
                    MoneyText(c.amount, emphasis: true)
                    Menu {
                        if c.canApprove {
                            Button("Approve") { Task {
                                do { let r = try await env.session.client.post("/v1/admin/payroll/commissions/\(c.id)/approve"); toastResult(env, r); version += 1 } catch { env.toasts.error(error) }
                            } }
                        }
                        if c.canVoid { Button("Void", role: .destructive) { voiding = c } }
                        if let uid = c.userId { Button("Open employee file") { env.navigator.go(.people(id: uid)) } }
                    } label: { Image(systemName: "ellipsis.circle") }
                    .accessibilityLabel("Actions for \(c.transactionRef)")
                }
            }
        }
    }
}

/// POST payroll/commissions {user_id, transaction_ref, amount, earned_on, note?} — from the tab or a person's menu.
struct RecordCommissionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let person: (id: Int, name: String)?
    let done: () -> Void
    @State private var user: Int?
    @State private var ref = ""
    @State private var amount = ""
    @State private var earnedOn = Day.today()
    @State private var note = ""

    init(person: (id: Int, name: String)?, done: @escaping () -> Void) {
        self.person = person; self.done = done
        _user = State(initialValue: person?.id)
    }

    var body: some View {
        let value = Decimal(string: amount.replacingOccurrences(of: ",", with: ""), locale: Locale(identifier: "en_US_POSIX"))
        EditSheet(person.map { "Record commission · \($0.name)" } ?? "Record commission", saveLabel: "Record",
                  canSave: CommissionRow.mayRecord(userId: user, transactionRef: ref, amount: value, earnedOn: earnedOn), save: {
            let r = try await env.session.client.post("/v1/admin/payroll/commissions", json: J.obj([
                ("user_id", J.i(user)), ("transaction_ref", .string(ref.trimmingCharacters(in: .whitespaces))), ("amount", J.typed(amount)),
                ("earned_on", .string(earnedOn)), ("note", J.sOpt(note.trimmingCharacters(in: .whitespaces))),
            ]))
            toastResult(env, r); done()
        }) { e in
            if person == nil { StaffPicker("Person", $user) }
            Field("Transaction ID", $ref, error: e?.errors["transaction_ref"]?.first)
            Field("Commission (KES)", $amount, error: e?.errors["amount"]?.first)
            DayField("Date earned", $earnedOn)
            Field("Note (optional)", $note)
        }
    }
}

/// POST payroll/commissions/{id}/void {reason}
struct VoidCommissionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let commission: CommissionRow
    let done: () -> Void
    @State private var reason = ""

    var body: some View {
        EditSheet("Void commission · \(commission.transactionRef)", saveLabel: "Void",
                  canSave: reason.trimmingCharacters(in: .whitespaces).count >= CommissionRow.voidReasonMinimum, destructive: true, save: {
            let r = try await env.session.client.post("/v1/admin/payroll/commissions/\(commission.id)/void", json: .object(["reason": .string(reason.trimmingCharacters(in: .whitespaces))]))
            toastResult(env, r); done()
        }) { _ in
            DetailRow("Amount") { MoneyText(commission.amount) }
            Field("Reason (e.g. order cancelled)", $reason)
        }
    }
}
