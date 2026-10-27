import SwiftUI
import BethanyKit

/// Timesheets, team side (People PR 5): one pay group and period at a time,
/// each person's sheet to approve or send back, the period lock (step-up to
/// unlock), the CSV export and the pay groups.
struct TimesheetsView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case sheets, groups }
    @State private var tab: Tab = .sheets
    @State private var groupId: String?
    @State private var date = Day.today()
    @State private var version = 0
    @State private var open: IDRef?
    @State private var unlocking = false

    var body: some View {
        Page("Timesheets", subtitle: "Hours per person, per pay period") {
            Tabs(selection: $tab, items: [(.sheets, "Timesheets"), (.groups, "Pay groups")])
            if tab == .groups {
                PayGroupsPanel()
            } else {
                RemoteJSON("/v1/admin/timesheets", query: ["pay_group_id": groupId, "date": date], version: version) { j, reload in
                    header(j, reload: reload)
                    let s = j["summary"] ?? .null
                    TileGrid(minWidth: 130) {
                        StatTile("People", value: s["people"]?.string ?? "0")
                        StatTile("Approved", value: s["approved"]?.string ?? "0", tone: .success)
                        StatTile("Submitted", value: s["submitted"]?.string ?? "0", tone: .info)
                        StatTile("Flagged", value: s["flagged"]?.string ?? "0", tone: (s["flagged"]?.int ?? 0) > 0 ? .warning : nil)
                    }
                    ForEach(j["rows"]?.records ?? []) { r in
                        Button { open = IDRef(r["user"]?["id"]?.int ?? 0) } label: {
                            SupportListRow(r["user"]?["name"]?.string ?? "—", subtitle: "\(r["user"]?["job_title"]?.string ?? "") · worked \(minutesText(r["totals"]?["worked_minutes"]?.int)) · OT \(minutesText(r["totals"]?["overtime_minutes"]?.int)) · \(r["totals"]?["days_worked"]?.int ?? 0) days") {
                                HStack(spacing: 4) {
                                    if (r.int("flags") ?? 0) > 0 { Pill("\(r.int("flags") ?? 0) flags", .warning) }
                                    if (r.int("absences") ?? 0) > 0 { Pill("\(r.int("absences") ?? 0) absent", .danger) }
                                    StatusBadge(r.status, tone: sheetTone(r.status))
                                }
                            }
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
        .sheet(item: $open) { ref in TimesheetDetailSheet(userId: ref.id, date: date) { version += 1 } }
        .sheet(isPresented: $unlocking) { UnlockPeriodSheet(date: date) { version += 1 } }
    }

    private func sheetTone(_ s: String?) -> StatusTone {
        switch s { case "approved": return .success; case "submitted": return .info; case "sent_back": return .warning; default: return .neutral }
    }

    @ViewBuilder
    private func header(_ j: JSONValue, reload: @escaping () -> Void) -> some View {
        let groups = j["groups"]?.records ?? []
        let period = j["period"] ?? .null
        HStack(alignment: .bottom, spacing: Theme.Space.s) {
            OptionPicker("Pay group", Binding(get: { groupId ?? j["group"]?["id"]?.string ?? "" }, set: { groupId = $0 }), options: groups.map { ($0.id, "\($0.string("name") ?? "") (\($0.string("frequency") ?? ""))") })
            DayField("Period containing", $date)
            Spacer()
        }
        SectionCard {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(Day.format(period["starts_on"]?.string, "d MMM")) – \(Day.format(period["ends_on"]?.string, "d MMM yyyy"))").font(Theme.Font.sectionTitle)
                    HStack { StatusBadge(period["status"]?.string); if let r = period["unlock_reason"]?.string { Text("unlocked: \(r)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) } }
                }
                Spacer()
                if j["can_lock"]?.bool == true, let pid = period["id"]?.int {
                    if period["status"]?.string == "locked" {
                        Button("Unlock…") { unlocking = true }
                    } else {
                        LockPeriodButton(periodId: pid, reload: reload)
                    }
                    DocumentButton("Export CSV", path: "/v1/admin/timesheets/export", query: ["pay_period_id": String(pid)], suggestedName: "timesheets-\(period["starts_on"]?.string ?? "").csv")
                }
            }
        }
    }
}

/// POST /pay-periods/{id}/lock {force}: refused with a message when sheets are
/// not all approved unless forced.
struct LockPeriodButton: View {
    @Environment(AppEnvironment.self) private var env
    let periodId: Int
    let reload: () -> Void
    @State private var askForce = false
    @State private var message = ""

    var body: some View {
        ConfirmButton("Lock period", message: "Locked hours are what payroll pays. Nobody can submit or change a sheet afterwards.", action: { await lock(force: false) }) { Label("Lock period", systemImage: "lock") }
            .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            .confirmationDialog("Lock anyway?", isPresented: $askForce, titleVisibility: .visible) {
                Button("Lock anyway", role: .destructive) { Task { await lock(force: true) } }
                Button("Cancel", role: .cancel) {}
            } message: { Text(message) }
    }

    private func lock(force: Bool) async {
        do {
            let r = try await env.session.client.post("/v1/admin/pay-periods/\(periodId)/lock", json: J.obj([("force", .bool(force))]))
            toastResult(env, r); reload()
        } catch let e as APIError where e.status == 422 && !force {
            message = e.message; askForce = true
        } catch { env.toasts.error(error) }
    }
}

/// POST /pay-periods/{id}/unlock {reason} — step-up.
struct UnlockPeriodSheet: View {
    @Environment(AppEnvironment.self) private var env
    let date: String
    let done: () -> Void
    @State private var reason = ""
    @State private var periodId: Int?

    var body: some View {
        EditSheet("Unlock period", saveLabel: "Unlock", canSave: !reason.isEmpty && periodId != nil, destructive: true, save: {
            let r = try await env.session.client.post("/v1/admin/pay-periods/\(periodId ?? 0)/unlock", json: J.obj([("reason", .string(reason))]))
            toastResult(env, r); done()
        }) { e in
            Text("Unlocking asks you to confirm it is you, and the reason is kept on the period.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Field("Reason", $reason, error: e?.errors["reason"]?.first, multiline: true)
        }
        .task { periodId = try? await env.session.client.json("/v1/admin/timesheets", query: ["date": date])["period"]?["id"]?.int }
    }
}

/// GET /timesheets/person/{user}?date → the sheet; approve / send back.
struct TimesheetDetailSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let userId: Int
    let date: String
    let changed: () -> Void
    @State private var sheet: JSONValue?
    @State private var error: APIError?
    @State private var sendBackNote = ""
    @State private var showSendBack = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text(sheet?["person"]?["name"]?.string ?? "Timesheet").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            if let s = sheet {
                TimesheetBody(sheet: s)
                if s["can_manage"]?.bool ?? env.session.can("attendance.manage"), s["period"]?["status"]?.string != "locked" {
                    HStack {
                        Spacer()
                        if showSendBack {
                            TextField("Why it is going back", text: $sendBackNote).textFieldStyle(.roundedBorder)
                            AsyncButton("Send back", role: .destructive) {
                                let r = try await env.session.client.post("/v1/admin/timesheets/person/\(userId)/send-back", json: J.obj([("date", .string(date)), ("note", .string(sendBackNote))]))
                                env.toasts.show(r.json?.message ?? "Sent back"); changed(); await load()
                            }.disabled(sendBackNote.isEmpty)
                        } else {
                            Button("Send back…") { showSendBack = true }.disabled(s["status"]?.string == "approved")
                        }
                        AsyncButton("Approve") {
                            let r = try await env.session.client.post("/v1/admin/timesheets/person/\(userId)/approve", json: J.obj([("date", .string(date))]))
                            env.toasts.show(r.json?.message ?? "Approved"); changed(); await load()
                        }
                        .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(s["status"]?.string == "approved")
                    }
                }
            } else if let error { ErrorState(error, retry: { Task { await load() } }) } else { LoadingSkeleton() }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 620, minHeight: 540)
        #endif
        .task { await load() }
    }

    private func load() async {
        do { sheet = try await env.session.client.json("/v1/admin/timesheets/person/\(userId)", query: ["date": date]) }
        catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

/// The sheet's totals, days and flags — reused by the employee file.
struct TimesheetBody: View {
    let sheet: JSONValue
    var body: some View {
        let t = sheet["totals"] ?? .null
        ScrollView {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                HStack {
                    StatusBadge(sheet["status"]?.string)
                    if sheet["snapshot"]?.bool == true { Pill("snapshot", .info) }
                    Spacer()
                    if let a = sheet["approved_by"]?.string { Text("Approved by \(a)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
                if let n = sheet["sent_back_note"]?.string, !n.isEmpty { Text("Sent back: \(n)").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning) }
                TileGrid(minWidth: 120) {
                    StatTile("Worked", value: minutesText(t["worked_minutes"]?.int))
                    StatTile("Regular", value: minutesText(t["regular_minutes"]?.int))
                    StatTile("Overtime", value: minutesText(t["overtime_minutes"]?.int))
                    StatTile("Rest day", value: minutesText(t["rest_day_minutes"]?.int))
                    StatTile("Holiday", value: minutesText(t["holiday_minutes"]?.int))
                    StatTile("Days", value: "\(t["days_worked"]?.int ?? 0)", hint: "\(t["paid_leave_days"]?.double ?? 0) paid · \(t["unpaid_leave_days"]?.double ?? 0) unpaid leave")
                }
                ForEach(sheet["days"]?.records ?? []) { d in
                    HStack(alignment: .top) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(Day.format(d.string("date"))).font(Theme.Font.secondary.weight(.semibold))
                            Text(d.string("holiday") ?? d.string("leave") ?? d.string("class")?.humanized ?? "").font(Theme.Font.caption).foregroundStyle(d.string("class") == "holiday" ? Theme.Palette.pink : Theme.textSecondary)
                            ForEach(d["punches"]?.records ?? []) { p in
                                Text("\(Day.time(p.string("in"))) – \(p.string("out").map(Day.time) ?? "open") · \(p.string("outlet") ?? "")\((p["flags"]?.array ?? []).isEmpty ? "" : " ⚑")").font(Theme.Font.caption)
                            }
                        }
                        Spacer()
                        VStack(alignment: .trailing, spacing: 2) {
                            Text(minutesText(d.int("worked_minutes"))).font(Theme.Font.body).monospacedDigit()
                            if (d.int("overtime_minutes") ?? 0) > 0 { Text("OT \(minutesText(d.int("overtime_minutes")))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.amber) }
                            if let h = d.string("highlight") { Pill(h == "over" ? "over schedule" : "under schedule", .warning) }
                        }
                    }
                    .padding(.vertical, 4)
                    Divider()
                }
                let flags = sheet["flags"]?.records ?? []
                if !flags.isEmpty { SectionCard("Flags") { ForEach(flags) { f in Text("\(Day.format(f.string("date"))): \(f.string("why") ?? "")").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning) } } }
                let absences = sheet["absences"]?.records ?? []
                if !absences.isEmpty { SectionCard("Absences") { ForEach(absences) { a in Text("\(Day.format(a.string("date"))): \(a.string("shift") ?? "")").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) } } }
            }
        }
    }
}

/// GET/POST/PUT /pay-groups
struct PayGroupsPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: PayGroupDraft?

    var body: some View {
        HStack { Spacer(); if env.session.can("hr.edit") { Button { editing = PayGroupDraft() } label: { Label("New pay group", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) } }
        RemoteJSON("/v1/admin/pay-groups", version: version) { j, _ in
            ForEach(j["data"]?.records ?? []) { g in
                SupportListRow(g.string("name") ?? "", subtitle: "\(g.string("frequency") ?? "") · \(g["standard_hours_per_day"]?.string ?? "")h/day · OT ×\(g["overtime_multiplier"]?.string ?? "") after \(g["overtime_after_daily_hours"]?.string ?? "—")h/day, \(g["overtime_after_weekly_hours"]?.string ?? "—")h/week · rest ×\(g["rest_day_multiplier"]?.string ?? "") · holiday ×\(g["holiday_multiplier"]?.string ?? "") · round \(g["round_to_minutes"]?.string ?? "")m\(g["period_end_day"]?.int.map { " · period ends day \($0)" } ?? "")") {
                    HStack {
                        if g["is_default"]?.bool == true { Pill("default", .info) }
                        if g["is_active"]?.bool == false { Pill("inactive", .neutral) }
                        if env.session.can("hr.edit") { Button("Edit") { editing = PayGroupDraft(record: g) } }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in PayGroupSheet(draft: d) { version += 1 } }
    }
}

struct PayGroupDraft: Identifiable {
    var id: Int?
    var name = ""
    var frequency = "monthly"
    var standardHours = "8"
    var otDaily = ""
    var otWeekly = ""
    var otMultiplier = "1.5"
    var restMultiplier = "2"
    var holidayMultiplier = "2"
    var roundTo = "15"
    var periodEndDay = ""
    var payDateDay = ""
    var unpaidBreak = "0"
    var unpaidBreakAfter = ""
    var isDefault = false
    var isActive = true
    init() {}
    init(record g: Record) {
        id = g.int("id"); name = g.string("name") ?? ""; frequency = g.string("frequency") ?? "monthly"
        standardHours = g["standard_hours_per_day"]?.string ?? "8"; otDaily = g["overtime_after_daily_hours"]?.string ?? ""; otWeekly = g["overtime_after_weekly_hours"]?.string ?? ""
        otMultiplier = g["overtime_multiplier"]?.string ?? "1.5"; restMultiplier = g["rest_day_multiplier"]?.string ?? "2"; holidayMultiplier = g["holiday_multiplier"]?.string ?? "2"
        roundTo = g["round_to_minutes"]?.string ?? "15"; periodEndDay = g["period_end_day"]?.string ?? ""; payDateDay = g["pay_date_day"]?.string ?? ""
        unpaidBreak = g["unpaid_break_minutes"]?.string ?? "0"; unpaidBreakAfter = g["unpaid_break_after_hours"]?.string ?? ""
        isDefault = g["is_default"]?.bool ?? false; isActive = g["is_active"]?.bool ?? true
    }
}

struct PayGroupSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: PayGroupDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "New pay group" : "Edit pay group", canSave: !draft.name.isEmpty, save: {
            let body = J.obj([
                ("name", .string(draft.name)), ("frequency", .string(draft.frequency)),
                ("standard_hours_per_day", J.typed(draft.standardHours)), ("overtime_after_daily_hours", J.typed(draft.otDaily)), ("overtime_after_weekly_hours", J.typed(draft.otWeekly)),
                ("overtime_multiplier", J.typed(draft.otMultiplier)), ("rest_day_multiplier", J.typed(draft.restMultiplier)), ("holiday_multiplier", J.typed(draft.holidayMultiplier)),
                ("round_to_minutes", J.typed(draft.roundTo)), ("period_end_day", J.typed(draft.periodEndDay)), ("pay_date_day", J.typed(draft.payDateDay)),
                ("unpaid_break_minutes", J.typed(draft.unpaidBreak)), ("unpaid_break_after_hours", J.typed(draft.unpaidBreakAfter)),
                ("is_default", .bool(draft.isDefault)), ("is_active", .bool(draft.isActive)),
            ])
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/pay-groups/\(id)", json: body) } else { r = try await env.session.client.post("/v1/admin/pay-groups", json: body) }
            toastResult(env, r); saved()
        }) { e in
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            OptionPicker("Frequency", $draft.frequency, options: [("monthly", "Monthly"), ("weekly", "Weekly")])
            HStack { Field("Standard hours/day", $draft.standardHours); Field("Round to (minutes)", $draft.roundTo) }
            HStack { Field("Overtime after (h/day)", $draft.otDaily); Field("Overtime after (h/week)", $draft.otWeekly) }
            HStack { Field("Overtime ×", $draft.otMultiplier); Field("Rest day ×", $draft.restMultiplier); Field("Holiday ×", $draft.holidayMultiplier) }
            HStack { Field("Period ends on day", $draft.periodEndDay, placeholder: "10 = 11th–10th"); Field("Pay date day", $draft.payDateDay) }
            HStack { Field("Unpaid break (min)", $draft.unpaidBreak); Field("…after hours", $draft.unpaidBreakAfter) }
            ToggleRow("Default group", $draft.isDefault)
            ToggleRow("Active", $draft.isActive)
            if let f = e?.firstFieldError { FieldError(f) }
        }
    }
}
