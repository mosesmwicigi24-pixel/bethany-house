import SwiftUI
import BethanyKit

/// Leave, team side (People PR 4): requests to decide, the month's calendar,
/// recording leave for someone, and the leave types (hr.edit).
struct LeaveAdminView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case requests, calendar, types }
    @State private var tab: Tab = .requests
    @State private var status = "pending"
    @State private var month = Day.firstOfMonth(Day.today())
    @State private var version = 0
    @State private var recording = false
    @State private var deciding: LeaveDecision?

    struct LeaveDecision: Identifiable { let id: Int; let approve: Bool; let summary: String }

    var body: some View {
        Page("Leave", subtitle: "Time off across the team") {
            HStack {
                Tabs(selection: $tab, items: [(.requests, "Requests"), (.calendar, "Calendar"), (.types, "Types")])
                Spacer()
                Button { recording = true } label: { Label("Record leave", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            switch tab {
            case .requests: requests
            case .calendar: calendar
            case .types: LeaveTypesPanel()
            }
        }
        .sheet(isPresented: $recording) { RecordLeaveSheet(userId: nil) { version += 1 } }
        .sheet(item: $deciding) { d in LeaveDecisionSheet(decision: d) { version += 1 } }
    }

    private var requests: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            OptionPicker("Status", $status, options: [("pending", "Pending"), ("approved", "Approved"), ("declined", "Declined"), ("cancelled", "Cancelled"), ("", "All")])
            RemoteJSON("/v1/admin/leave/requests", query: ["status": status.isEmpty ? nil : status], version: version, emptyTitle: "No \(status.isEmpty ? "" : status + " ")requests", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { r in LeaveRequestCard(request: r, reload: reload) { deciding = LeaveDecision(id: r.int("id") ?? 0, approve: $0, summary: leaveSummary(r)) } }
            }
        }
    }

    private var calendar: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack {
                Button { month = Day.firstOfMonth(Day.add(month, days: -1)) } label: { Image(systemName: "chevron.left") }
                Text(Day.format(month, "MMMM yyyy")).font(Theme.Font.sectionTitle)
                Button { month = Day.firstOfMonth(Day.add(Day.lastOfMonth(month), days: 1)) } label: { Image(systemName: "chevron.right") }
            }
            RemoteJSON("/v1/admin/leave/calendar", query: ["from": month, "to": Day.lastOfMonth(month)], version: version, emptyTitle: "Nobody is away this month", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
                let rows = (j["data"]?.records ?? []).sorted { ($0.string("starts_on") ?? "") < ($1.string("starts_on") ?? "") }
                ForEach(rows) { r in
                    SupportListRow(r["user"]?["name"]?.string ?? "—", subtitle: leaveSummary(r)) { StatusBadge(r.status) }
                }
            }
        }
    }
}

func leaveSummary(_ r: Record) -> String {
    let span = r.string("starts_on") == r.string("ends_on") ? Day.format(r.string("starts_on")) : "\(Day.format(r.string("starts_on"))) – \(Day.format(r.string("ends_on")))"
    return "\(r["type"]?["name"]?.string ?? "Leave") · \(span) · \(r["days"]?.string ?? "?") day(s)"
}

struct LeaveRequestCard: View {
    @Environment(AppEnvironment.self) private var env
    let request: Record
    let reload: () -> Void
    let decide: (Bool) -> Void

    var body: some View {
        SectionCard {
            HStack(alignment: .top) {
                Avatar(name: request["user"]?["name"]?.string ?? "?")
                VStack(alignment: .leading, spacing: 3) {
                    Text(request["user"]?["name"]?.string ?? "—").font(Theme.Font.body.weight(.semibold))
                    Text(leaveSummary(request)).font(Theme.Font.secondary)
                    if let reason = request.string("reason"), !reason.isEmpty { Text("“\(reason)”").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    if let clash = request["roster_clash"]?.array, !clash.isEmpty {
                        Text("Rostered on \(clash.compactMap { $0["date"]?.string.map { Day.format($0) } }.joined(separator: ", "))").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
                    }
                    if let by = request.string("decided_by") { Text("\(request.status?.humanized ?? "") by \(by)\(request.string("decision_note").map { " — \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    if request["evidence_document_id"]?.int != nil { Pill("evidence attached", .info) }
                }
                Spacer()
                VStack(alignment: .trailing) {
                    StatusBadge(request.status)
                    DateText(request.date("created_at"), style: .relative)
                }
            }
            if request.status == "pending", request["can_decide"]?.bool ?? env.session.can("leave.approve") {
                HStack {
                    Spacer()
                    Button("Decline") { decide(false) }
                    Button("Approve") { decide(true) }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            } else if request.status == "approved" || request.status == "pending" {
                HStack {
                    Spacer()
                    ConfirmButton("Cancel leave", message: "The days go back to the person's balance.", destructive: true, action: {
                        do { let r = try await env.session.client.delete("/v1/admin/leave/requests/\(request.id)", json: .object([:])); toastResult(env, r); reload() } catch { env.toasts.error(error) }
                    }) { Text("Cancel leave").font(Theme.Font.secondary) }
                }
            }
        }
    }
}

/// POST /leave/requests/{id}/approve|decline {note}
struct LeaveDecisionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let decision: LeaveAdminView.LeaveDecision
    let done: () -> Void
    @State private var note = ""
    var body: some View {
        EditSheet(decision.approve ? "Approve leave" : "Decline leave", saveLabel: decision.approve ? "Approve" : "Decline", destructive: !decision.approve, save: {
            let r = try await env.session.client.post("/v1/admin/leave/requests/\(decision.id)/\(decision.approve ? "approve" : "decline")", json: J.obj([("note", J.sOpt(note))]))
            toastResult(env, r)
            if let clash = r.json?["roster_clash"]?.array, !clash.isEmpty { env.toasts.show("Approved — \(clash.count) rostered shift(s) now clash", tone: .warning) }
            done()
        }) { e in
            Text(decision.summary).font(Theme.Font.secondary)
            Field("Note to the person", $note, error: e?.errors["note"]?.first, multiline: true)
        }
    }
}

/// POST /leave/requests {user_id, leave_type_id, starts_on, ends_on, reason} — recorded as approved by the manager.
struct RecordLeaveSheet: View {
    @Environment(AppEnvironment.self) private var env
    let userId: Int?
    let saved: () -> Void
    @State private var person: Int?
    @State private var typeId: Int?
    @State private var types: [Record] = []
    @State private var from = Day.today()
    @State private var to = Day.today()
    @State private var reason = ""

    var body: some View {
        EditSheet("Record leave", canSave: person != nil && typeId != nil, save: {
            let r = try await env.session.client.post("/v1/admin/leave/requests", json: J.obj([
                ("user_id", J.i(person)), ("leave_type_id", J.i(typeId)), ("starts_on", .string(from)), ("ends_on", .string(to)), ("reason", J.sOpt(reason)),
            ]))
            toastResult(env, r); saved()
        }) { e in
            if userId == nil { StaffPicker("Person", $person) }
            VStack(alignment: .leading, spacing: 4) {
                Text("Type").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                Picker("", selection: $typeId) {
                    Text("Choose…").tag(Int?.none)
                    ForEach(types) { t in Text(t.string("name") ?? "").tag(t.int("id")) }
                }.labelsHidden().pickerStyle(.menu)
            }
            HStack { DayField("From", $from); DayField("To", $to) }
            FieldError(e?.errors["ends_on"]?.first ?? e?.errors["starts_on"]?.first)
            Field("Reason", $reason, multiline: true)
        }
        .task {
            person = userId
            types = (try? await env.session.client.json("/v1/admin/leave/types"))?["data"]?.records.filter { $0["is_active"]?.bool != false } ?? []
        }
    }
}

/// GET /leave/types; PUT /leave/types/{id} (hr.edit)
struct LeaveTypesPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: Record?
    var body: some View {
        RemoteJSON("/v1/admin/leave/types", version: version) { j, _ in
            ForEach(j["data"]?.records ?? []) { t in
                SupportListRow("\(t.string("name") ?? "") (\(t.string("code") ?? ""))", subtitle: "\(t["days_per_year"]?.string.map { "\($0) days/year" } ?? "no cap") · \(t.string("accrual") == "monthly" ? "accrues monthly" : "available up front") · \(t["is_paid"]?.bool == true ? "paid" : "unpaid") · \(t["counts_calendar_days"]?.bool == true ? "calendar days" : "working days")\(t["evidence_after_days"]?.int.map { " · evidence after \($0) days" } ?? "")") {
                    HStack {
                        if t["is_active"]?.bool == false { Pill("inactive", .neutral) }
                        if env.session.can("hr.edit") { Button("Edit") { editing = t } }
                    }
                }
            }
        }
        .sheet(item: $editing) { t in LeaveTypeSheet(type: t) { version += 1 } }
    }
}

struct LeaveTypeSheet: View {
    @Environment(AppEnvironment.self) private var env
    let type: Record
    let saved: () -> Void
    @State private var name: String
    @State private var days: String
    @State private var accrual: String
    @State private var paid: Bool
    @State private var calendarDays: Bool
    @State private var evidenceAfter: String
    @State private var active: Bool

    init(type: Record, saved: @escaping () -> Void) {
        self.type = type; self.saved = saved
        _name = State(initialValue: type.string("name") ?? "")
        _days = State(initialValue: type["days_per_year"]?.string ?? "")
        _accrual = State(initialValue: type.string("accrual") ?? "front")
        _paid = State(initialValue: type["is_paid"]?.bool ?? true)
        _calendarDays = State(initialValue: type["counts_calendar_days"]?.bool ?? false)
        _evidenceAfter = State(initialValue: type["evidence_after_days"]?.string ?? "")
        _active = State(initialValue: type["is_active"]?.bool ?? true)
    }

    var body: some View {
        EditSheet("Edit \(type.string("code") ?? "leave type")", save: {
            let r = try await env.session.client.put("/v1/admin/leave/types/\(type.id)", json: J.obj([
                ("name", .string(name)), ("days_per_year", J.typed(days)), ("accrual", .string(accrual)), ("is_paid", .bool(paid)),
                ("counts_calendar_days", .bool(calendarDays)), ("evidence_after_days", J.typed(evidenceAfter)), ("is_active", .bool(active)),
            ]))
            toastResult(env, r); saved()
        }) { e in
            Field("Name", $name, error: e?.errors["name"]?.first)
            Field("Days per year (blank = no cap)", $days, error: e?.errors["days_per_year"]?.first)
            OptionPicker("Accrual", $accrual, options: [("front", "All available up front"), ("monthly", "Accrues monthly")])
            ToggleRow("Paid", $paid)
            ToggleRow("Counts calendar days (not working days)", $calendarDays)
            Field("Evidence required after (days)", $evidenceAfter)
            ToggleRow("Active", $active)
        }
    }
}
