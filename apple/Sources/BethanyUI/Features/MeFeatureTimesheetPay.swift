import SwiftUI
import BethanyKit

// MARK: - My hours (TimesheetPage.tsx): this period day by day, Submit, and "fix?" per day

struct MyTimesheetView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var date = Date()
    @State private var sheet: Resource<JSONValue>?
    @State private var fixes: Resource<JSONValue>?
    @State private var note = ""
    @State private var fixDate: String?
    @State private var fixMessage = ""
    @State private var busy = false

    var body: some View {
        Page("My hours", subtitle: periodSubtitle) {
            HStack {
                Spacer()
                Button { move(-1) } label: { Image(systemName: "chevron.left") }.accessibilityLabel("Previous period")
                Button { move(1) } label: { Image(systemName: "chevron.right") }.accessibilityLabel("Next period")
            }
            if let sheet {
                LoadableView(sheet.state, retry: { sheet.load() }) { d in
                    HStack(spacing: Theme.Space.s) {
                        StatusBadge(sheetLabel(d["status"]?.string) + (d["approved_by"]?.string.map { " by \($0)" } ?? ""), tone: StatusTone.forStatus(d["status"]?.string))
                        if d["period"]?["status"]?.string == "locked" { StatusBadge("Period locked", tone: .success) }
                    }
                    if d["status"]?.string == "sent_back", let n = d["sent_back_note"]?.string {
                        NoticeText("Your manager sent this back: \(n)", tone: .warning)
                    }
                    totals(d["totals"] ?? .null)
                    days(d)
                    if let fixDate {
                        SectionCard {
                            Text("Something wrong on \(MeFormat.day(MeFormat.date(fixDate)))?").font(Theme.Font.sectionTitle)
                            Text("Say what happened (forgot to clock out, phone was off, wrong outlet). Your manager corrects the punch and you are told.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            TextField("I forgot to clock out; I left at 17:10", text: $fixMessage).textFieldStyle(.roundedBorder)
                            HStack {
                                Button("Cancel") { self.fixDate = nil }.buttonStyle(.bordered).frame(maxWidth: .infinity)
                                Button("Send to my manager") { Task { await requestFix() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).frame(maxWidth: .infinity)
                                    .disabled(busy || fixMessage.trimmingCharacters(in: .whitespaces).count < 3)
                            }
                        }
                    }
                    if d["can_submit"]?.bool == true {
                        SectionCard {
                            Text("Checked your days? Submit them to your manager. A wrong punch is corrected by the manager in Attendance; say so in the note.").font(Theme.Font.secondary)
                            TextField("Note to your manager (optional)", text: $note).textFieldStyle(.roundedBorder)
                            Button("Submit my hours") { Task { await submit() } }.buttonStyle(.primary).disabled(busy)
                        }
                    }
                    Button("Back to the clock") { env.navigator.go(.clock) }.font(Theme.Font.caption).frame(maxWidth: .infinity)
                }
            }
        }
        .task {
            if sheet == nil {
                let c = env.session.client
                fixes = Resource { try await c.get("/v1/admin/me/timesheet/fixes", background: true).json ?? .null }
                fixes?.load()
                await load()
            }
        }
        .refreshable { await sheet?.reload(); await fixes?.reload() }
    }

    private var periodSubtitle: String? {
        guard let p = sheet?.value?["period"] else { return nil }
        return "\(MeFormat.day(MeFormat.date(p["starts_on"]?.string))) – \(MeFormat.day(MeFormat.date(p["ends_on"]?.string)))"
    }

    private func sheetLabel(_ s: String?) -> String {
        switch s { case "draft": return "Not submitted"; case "submitted": return "Submitted"; case "sent_back": return "Sent back"; case "approved": return "Approved"; default: return s ?? "—" }
    }

    /// A weekly period is 7 days long; anything longer steps by a month.
    private func move(_ dir: Int) {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = HubDates.nairobi
        var weekly = false
        if let p = sheet?.value?["period"], let a = MeFormat.date(p["starts_on"]?.string), let b = MeFormat.date(p["ends_on"]?.string) {
            weekly = b.timeIntervalSince(a) < 8 * 86_400
        }
        date = (weekly ? cal.date(byAdding: .day, value: 7 * dir, to: date) : cal.date(byAdding: .month, value: dir, to: date)) ?? date
        Task { await load() }
    }

    private func load() async {
        let c = env.session.client
        let d = MeFormat.dayString(date)
        sheet = Resource { try await c.get("/v1/admin/me/timesheet", query: ["date": d]).json ?? .null }
        await sheet?.reload()
    }

    // MARK: Totals and days (TimesheetDays.tsx)

    private func totals(_ t: JSONValue) -> some View {
        TileGrid(minWidth: 130) {
            StatTile("Regular", value: MeFormat.hm(t["regular_minutes"]?.int))
            StatTile("Overtime", value: MeFormat.hm(t["overtime_minutes"]?.int))
            StatTile("Rest days", value: MeFormat.hm(t["rest_day_minutes"]?.int))
            StatTile("Holidays", value: MeFormat.hm(t["holiday_minutes"]?.int))
            StatTile("Paid leave", value: "\(t["paid_leave_days"]?.string ?? "0") d")
            StatTile("Unpaid leave", value: "\(t["unpaid_leave_days"]?.string ?? "0") d")
            StatTile("Days worked", value: t["days_worked"]?.string ?? "0")
        }
    }

    private func days(_ d: JSONValue) -> some View {
        let flags = d["flags"]?.array ?? []
        let absences = d["absences"]?.array ?? []
        let fixPairs: [(String, String)] = (fixes?.value?["data"]?.array ?? []).compactMap { f -> (String, String)? in
            guard let dt = f["date"]?.string, let st = f["status"]?.string else { return nil }
            return (dt, st)
        }
        let fixByDate: [String: String] = Dictionary(fixPairs, uniquingKeysWith: { a, _ in a })
        let todayStr = MeFormat.dayString(Date())
        return VStack(alignment: .leading, spacing: Theme.Space.m) {
            if !flags.isEmpty {
                NoticeText("\(MeFormat.plural(flags.count, "punch", "punches")) need attention before approval: \(flags.map { "\($0["date"]?.string ?? "") (\($0["why"]?.string ?? ""))" }.joined(separator: "; "))", tone: .danger)
            }
            if !absences.isEmpty {
                NoticeText("Rostered but no punch: \(absences.map { "\($0["date"]?.string ?? "") \($0["shift"]?.string ?? "")" }.joined(separator: ", "))", tone: .warning)
            }
            ListCard {
                ForEach(Array((d["days"]?.array ?? []).enumerated()), id: \.offset) { _, day in
                    let cls = day["class"]?.string
                    let worked = day["worked_minutes"]?.int ?? 0
                    let idle = worked == 0 && (day["leave"]?.isNull ?? true)
                    let dateStr = day["date"]?.string ?? ""
                    ListRow {
                        HStack(spacing: Theme.Space.s) {
                            Text(MeFormat.day(MeFormat.date(dateStr))).font(Theme.Font.secondary.weight(.medium)).frame(width: 76, alignment: .leading).monospacedDigit()
                            dayDescription(day).font(Theme.Font.secondary).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                            if let h = day["highlight"]?.string { StatusBadge(h, tone: .warning) }
                            Text(worked > 0 ? MeFormat.hm(worked) : "").font(Theme.Font.secondary).monospacedDigit().frame(width: 56, alignment: .trailing)
                            Text(extra(day)).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).frame(width: 70, alignment: .trailing)
                            if dateStr <= todayStr {
                                if let f = fixByDate[dateStr] {
                                    Text(f == "requested" ? "fix asked" : f).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                                } else {
                                    Button("fix?") { fixDate = dateStr; fixMessage = "" }.font(Theme.Font.caption).accessibilityLabel("Request a fix for \(dateStr)")
                                }
                            }
                        }
                        .foregroundStyle(idle ? Theme.textTertiary : Theme.text)
                    }
                    .background(cls == "rest" ? Theme.Palette.neutralSoft.opacity(0.4) : cls == "holiday" ? Theme.Palette.amberSoft.opacity(0.4) : Color.clear)
                }
            }
            if let g = d["group"], g.object != nil {
                Text(groupWords(g, snapshot: d["snapshot"]?.bool ?? false)).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
    }

    private func dayDescription(_ day: JSONValue) -> Text {
        if let h = day["holiday"]?.string { return Text(h).foregroundStyle(Theme.Palette.amber).fontWeight(.semibold) }
        if let l = day["leave"]?.string { return Text(l).foregroundStyle(Theme.textSecondary) }
        let punches = day["punches"]?.array ?? []
        if !punches.isEmpty {
            return Text(punches.map { p in "\(p["in"]?.string ?? "")–\(p["out"]?.string ?? "…")\(p["status"]?.string == "flagged" ? " ⚠" : p["status"]?.string == "active" ? " (open)" : "")" }.joined(separator: ", "))
        }
        let rostered = day["rostered"]?.array?.compactMap(\.string) ?? []
        if !rostered.isEmpty { return Text("rostered \(rostered.joined(separator: ", ")), no punch").foregroundStyle(Theme.Palette.amber) }
        return Text(day["class"]?.string == "rest" ? "rest day" : "")
    }

    private func extra(_ day: JSONValue) -> String {
        if let ot = day["overtime_minutes"]?.int, ot > 0 { return "OT \(MeFormat.hm(ot))" }
        if (day["rest_day_minutes"]?.int ?? 0) > 0 { return "rest ×" }
        if (day["holiday_minutes"]?.int ?? 0) > 0 { return "holiday ×" }
        if let b = day["unpaid_break_minutes"]?.int, b > 0 { return "−\(b)m lunch" }
        return ""
    }

    private func groupWords(_ g: JSONValue, snapshot: Bool) -> String {
        var s = "\(g["name"]?.string ?? ""): "
        if let d = g["overtime_after_daily_hours"]?.string { s += "overtime after \(d) h a day" }
        if let w = g["overtime_after_weekly_hours"]?.string { s += "\(g["overtime_after_daily_hours"]?.string != nil ? ", " : "overtime ")after \(w) h a week" }
        s += " at ×\(g["overtime_multiplier"]?.string ?? ""); rest days ×\(g["rest_day_multiplier"]?.string ?? ""); holidays ×\(g["holiday_multiplier"]?.string ?? "")"
        if let r = g["round_to_minutes"]?.int, r > 1 { s += "; punches rounded to \(r) min" }
        s += ". \(snapshot ? "Figures as approved." : "Live figures until approved.")"
        return s
    }

    // MARK: Actions

    private func submit() async {
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["date": .string(MeFormat.dayString(date))]
        let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
        if !n.isEmpty { body["note"] = .string(n) }
        do {
            let r = try await env.session.client.post("/v1/admin/me/timesheet/submit", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Submitted")
            note = ""
            await sheet?.reload()
        } catch { env.toasts.error(error) }
    }

    private func requestFix() async {
        guard let fixDate else { return }
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/me/timesheet/fixes", json: .object(["date": .string(fixDate), "message": .string(fixMessage.trimmingCharacters(in: .whitespacesAndNewlines))]))
            env.toasts.show(r.json?["message"]?.string ?? "Sent to your manager")
            self.fixDate = nil; fixMessage = ""
            await fixes?.reload()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - My payslips (PayslipsPage.tsx): every approved run I was on; the PDF asks for a step-up

struct MyPayslipsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var lines: Resource<[Record]>?
    @State private var open: String?

    var body: some View {
        Page("My payslips", subtitle: "Bethany House") {
            if let lines {
                LoadableView(lines.state, retry: { lines.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No payslips yet", message: "One appears here when a pay run you were on is approved.", symbol: "doc.text") }) { rows in
                    ForEach(rows) { l in payslipCard(l) }
                }
            }
        }
        .task {
            if lines == nil {
                let c = env.session.client
                lines = Resource { try await c.get("/v1/admin/me/payslips").json?.records ?? [] }
                lines?.load()
            }
        }
        .refreshable { await lines?.reload() }
    }

    private func payslipCard(_ l: Record) -> some View {
        let run = l["run"]
        let paid = l.string("paid_at") != nil && !(l["paid_at"]?.isNull ?? true)
        return SectionCard {
            Button { open = open == l.id ? nil : l.id } label: {
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("\(MeFormat.dayYear(MeFormat.date(run?["starts_on"]?.string))) – \(MeFormat.dayYear(MeFormat.date(run?["ends_on"]?.string)))").font(Theme.Font.body.weight(.semibold))
                        Text("\(run?["reference"]?.string ?? "")\(paid ? " · paid \(MeFormat.day(l.date("paid_at"))) by \(l.string("payment_method") ?? "")" : " · not yet paid")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    Spacer()
                    VStack(alignment: .trailing, spacing: 2) {
                        MoneyText(l.money("net", currency: "KES"), emphasis: true)
                        StatusBadge(paid ? "paid" : "approved")
                    }
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if open == l.id {
                Divider()
                PayslipItems(line: l)
                DocumentButton("Download PDF", path: "/v1/admin/me/payslips/\(l.id)/pdf", suggestedName: "payslip-\(run?["reference"]?.string ?? l.id).pdf")
                    .buttonStyle(.bordered).frame(maxWidth: .infinity)
            }
        }
    }
}

/// One payslip's lines: earnings, deductions, net — the same list the PDF
/// prints (PayslipItems.tsx). Every figure is the server's, in KES.
struct PayslipItems: View {
    let line: Record
    var body: some View {
        let items = line.records("items")
        let earnings = items.filter { $0.string("kind") == "earning" }
        let deductions = items.filter { $0.string("kind") == "deduction" }
        let hours = line["hours"]
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            ForEach(Array(earnings.enumerated()), id: \.offset) { _, i in row(i.string("label") ?? "", i.money("amount", currency: "KES")) }
            Divider()
            row("Gross pay", line.money("gross", currency: "KES"), strong: true)
            if !deductions.isEmpty {
                ForEach(Array(deductions.enumerated()), id: \.offset) { _, i in row(i.string("label") ?? "", i.money("amount", currency: "KES")) }
            }
            Rectangle().fill(Theme.text).frame(height: 2)
            row("Net pay", line.money("net", currency: "KES"), strong: true)
            Text("Hours: regular \(MeFormat.hm(hours?["regular_minutes"]?.int)), overtime \(MeFormat.hm(hours?["overtime_minutes"]?.int)), rest days \(MeFormat.hm(hours?["rest_day_minutes"]?.int)), holidays \(MeFormat.hm(hours?["holiday_minutes"]?.int)) · paid leave \(hours?["paid_leave_days"]?.string ?? "0") d, unpaid \(hours?["unpaid_leave_days"]?.string ?? "0") d, days worked \(hours?["days_worked"]?.string ?? "0"). Taxable pay \(line.money("taxable", currency: "KES")?.formatted() ?? "—"). Employer NSSF \(line.money("nssf_employer", currency: "KES")?.formatted() ?? "—") and Housing Levy \(line.money("housing_employer", currency: "KES")?.formatted() ?? "—") are not deducted from you.")
                .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
        }
    }

    private func row(_ label: String, _ amount: Money?, strong: Bool = false) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(label).font(Theme.Font.secondary).fontWeight(strong ? .semibold : .regular).foregroundStyle(strong ? Theme.text : Theme.textSecondary)
            Spacer()
            if let amount, amount.isNegative {
                Text("(\(Money(amount: -amount.amount, currency: amount.currency).formatted()))").font(Theme.Font.secondary).monospacedDigit().foregroundStyle(Theme.textSecondary)
            } else {
                MoneyText(amount, emphasis: strong)
            }
        }
    }
}

// MARK: - Advances & loans (AdvancesPage.tsx): ask, see the decision, watch the balance come down

struct MyAdvancesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var data: Resource<JSONValue>?
    @State private var asking = false
    @State private var kind = "advance"
    @State private var amount = ""
    @State private var instalment = ""
    @State private var reason = ""
    @State private var busy = false

    var body: some View {
        Page("Advances & loans", subtitle: "Bethany House") {
            if let data {
                LoadableView(data.state, retry: { data.load() }) { d in
                    let rows = d.records
                    let waiting = rows.contains { $0.status == "requested" }
                    if !asking && !waiting {
                        HStack { Spacer(); Button("Ask for one") { asking = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                    }
                    if let bal = d["open_balance"].flatMap({ Money(json: $0, currency: "KES") }), !bal.isZero {
                        SectionCard {
                            HStack(spacing: 4) { Text("Still to repay:").font(Theme.Font.secondary); MoneyText(bal, emphasis: true) }
                            Text("It comes off your pay each run until it is cleared.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                    }
                    if asking { askCard }
                    if rows.isEmpty && !asking {
                        SectionCard { Text("Nothing yet. Ask for an advance on this period's pay or a loan repaid over several runs.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                    }
                    ForEach(rows) { a in advanceCard(a) }
                    Button("My payslips") { env.navigator.go(.myPayslips) }.font(Theme.Font.caption).frame(maxWidth: .infinity)
                }
            }
        }
        .task {
            if data == nil {
                let c = env.session.client
                data = Resource { try await c.get("/v1/admin/me/advances").json ?? .null }
                data?.load()
            }
        }
        .refreshable { await data?.reload() }
    }

    private var askCard: some View {
        SectionCard {
            Picker("Kind", selection: $kind) { Text("Advance").tag("advance"); Text("Loan").tag("loan") }.pickerStyle(.segmented)
            Text(kind == "advance" ? "An advance on this period's pay. The whole amount comes off your next payslip." : "A loan repaid in instalments, one per pay run, until cleared.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            FieldLabel("Amount (KES)") { TextField("", text: $amount).textFieldStyle(.roundedBorder).decimalKeyboard() }
            if kind == "loan" {
                FieldLabel("Repay per pay run (KES)") { TextField("Payroll may change this", text: $instalment).textFieldStyle(.roundedBorder).decimalKeyboard() }
            }
            FieldLabel("Why") { TextField("School fees, rent, medical…", text: $reason).textFieldStyle(.roundedBorder) }
            HStack {
                Button("Cancel") { asking = false }.buttonStyle(.bordered).frame(maxWidth: .infinity)
                Button("Send request") { Task { await submit() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).frame(maxWidth: .infinity)
                    .disabled(busy || Decimal(string: amount) == nil || reason.trimmingCharacters(in: .whitespaces).isEmpty)
            }
        }
    }

    private static let label: [String: String] = ["requested": "Waiting", "approved": "Approved", "paid": "Paid out", "settled": "Settled", "declined": "Declined", "cancelled": "Cancelled"]

    private func advanceCard(_ a: Record) -> some View {
        SectionCard {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 4) { Text(a.string("kind") == "loan" ? "Loan" : "Advance").font(Theme.Font.body.weight(.semibold)); Text("·"); MoneyText(a.money("amount", currency: "KES")) }
                    Text("\(a.string("reason") ?? "")\(a.date("requested_at").map { " · asked \(MeFormat.day($0))" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
                Spacer()
                StatusBadge(Self.label[a.status ?? ""] ?? a.status, tone: StatusTone.forStatus(a.status))
            }
            if let n = a.string("decision_note") { Text("Payroll: \(n)").font(Theme.Font.caption) }
            if a.status == "paid" {
                HStack(spacing: 4) {
                    Text("Paid out \(MeFormat.day(a.date("paid_at"))) by \(a.string("payment_method") ?? ""). Balance").font(Theme.Font.caption)
                    MoneyText(a.money("balance", currency: "KES"))
                    if a.string("kind") == "loan" { Text("·").font(Theme.Font.caption); MoneyText(a.money("instalment", currency: "KES")); Text("per run").font(Theme.Font.caption) }
                }
            }
            let repayments = a.records("repayments")
            if !repayments.isEmpty {
                VStack(spacing: 2) {
                    ForEach(repayments) { r in
                        HStack {
                            Text("\(r.string("kind") == "payroll" ? "Off payslip \(r.string("reference") ?? "")" : "Repaid in cash\(r.string("reference").map { " · \($0)" } ?? "")") · \(r.string("on") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            Spacer()
                            MoneyText(r.money("amount", currency: "KES"))
                        }
                    }
                }
            }
            if a.status == "requested" {
                ConfirmButton("Withdraw request", message: "Payroll will not see this request any more.", destructive: true, action: { await cancel(a) }) { Text("Withdraw request").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
            }
        }
    }

    private func submit() async {
        guard let amt = Decimal(string: amount, locale: Locale(identifier: "en_US_POSIX")) else { return }
        busy = true; defer { busy = false }
        var body: [String: JSONValue] = ["kind": .string(kind), "amount": .string("\(amt)"), "reason": .string(reason.trimmingCharacters(in: .whitespacesAndNewlines))]
        if kind == "loan", let inst = Decimal(string: instalment, locale: Locale(identifier: "en_US_POSIX")) { body["instalment"] = .string("\(inst)") }
        do {
            let r = try await env.session.client.post("/v1/admin/me/advances", json: .object(body))
            env.toasts.show(r.json?["message"]?.string ?? "Request sent")
            asking = false; amount = ""; instalment = ""; reason = ""; kind = "advance"
            await data?.reload()
        } catch { env.toasts.error(error) }
    }

    private func cancel(_ a: Record) async {
        do {
            let r = try await env.session.client.delete("/v1/admin/me/advances/\(a.id)")
            env.toasts.show(r.json?["message"]?.string ?? "Withdrawn")
            await data?.reload()
        } catch { env.toasts.error(error) }
    }
}

extension View {
    /// Decimal keypad on iOS; nothing on macOS.
    @ViewBuilder func decimalKeyboard() -> some View {
        #if os(iOS)
        self.keyboardType(.decimalPad)
        #else
        self
        #endif
    }
}
