import SwiftUI
import BethanyKit

/// People (the manager's side): overview, requests queue, attendance,
/// checklists, roster, timesheets, leave, employee files and payroll.
/// Every figure is the server's; the screens only present and act.
@MainActor
public enum PeopleFeature {
    public static func register() {
        FeatureRegistry.register(.peopleOverview) { _ in AnyView(PeopleOverviewView()) }
        FeatureRegistry.register(.peopleRequests) { _ in AnyView(PeopleRequestsView()) }
        FeatureRegistry.register(.attendance) { _ in AnyView(AttendanceView()) }
        FeatureRegistry.register(.formsManage) { _ in AnyView(FormsManageView()) }
        FeatureRegistry.register(.roster) { _ in AnyView(RosterView()) }
        FeatureRegistry.register(.timesheets) { _ in AnyView(TimesheetsView()) }
        FeatureRegistry.register(.leave) { _ in AnyView(LeaveAdminView()) }
        FeatureRegistry.register({ if case .people = $0 { return true } else { return false } }) { route in
            if case .people(let id?) = route { return AnyView(PersonFileView(personId: id)) }
            return AnyView(PeopleListView())
        }
        FeatureRegistry.register(.payroll) { _ in AnyView(PayrollView()) }
    }
}

// MARK: - Overview (GET /people/overview)

struct PeopleOverviewView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var resource: Resource<JSONValue>?

    var body: some View {
        Page("People", subtitle: "Who is in, who is late, what is waiting") {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }) { o in
                    let now = o["now"] ?? .null
                    TileGrid(minWidth: 150) {
                        StatTile("In now", value: now["in_count"]?.string ?? "0", hint: "\(now["on_break"]?.int ?? 0) on break")
                        StatTile("Late", value: "\(now["late"]?.array?.count ?? 0)", tone: (now["late"]?.array?.count ?? 0) > 0 ? .warning : nil)
                        StatTile("Absent", value: "\(now["absent"]?.array?.count ?? 0)", tone: (now["absent"]?.array?.count ?? 0) > 0 ? .danger : nil)
                        StatTile("On leave", value: "\(now["on_leave"]?.array?.count ?? 0)")
                        StatTile("Open shifts", value: o["week"]?["open_shifts"]?.string ?? "0", hint: "this week")
                        StatTile("Flagged punches", value: o["week"]?["flagged_punches"]?.string ?? "0", hint: "this week", tone: (o["week"]?["flagged_punches"]?.int ?? 0) > 0 ? .warning : nil)
                    }
                    HStack(spacing: Theme.Space.s) {
                        Button { env.navigator.go(.peopleRequests) } label: { Label("Requests (\(o["requests"]?["count"]?.int ?? 0))", systemImage: "tray.full") }
                        Button { env.navigator.go(.roster) } label: { Label("Roster", systemImage: "calendar.badge.clock") }
                        Button { env.navigator.go(.attendance) } label: { Label("Attendance", systemImage: "location") }
                        if env.session.can("attendance.view_team") { Button { env.navigator.go(.timesheets) } label: { Label("Timesheets", systemImage: "tablecells") } }
                    }
                    .buttonStyle(.bordered).tint(Theme.Palette.brandDeep)

                    peopleSection("Clocked in", now["in"]?.records, empty: "Nobody is clocked in right now") { r in
                        VStack(alignment: .trailing, spacing: 2) {
                            Text("since \(Day.time(r.string("since")))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            HStack(spacing: 4) {
                                if r["on_break"]?.bool == true { Pill("on break", .pink) }
                                if r["left_site_at"]?.string != nil { Pill("left site", .warning) }
                                ForEach(r["flags"]?.array?.compactMap(\.string) ?? [], id: \.self) { Pill($0, .warning) }
                            }
                        }
                    }
                    peopleSection("Late", now["late"]?.records, empty: nil) { r in
                        Text("\(r.int("minutes_late") ?? 0) min late · \(r.string("shift") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.Palette.warning)
                    }
                    peopleSection("Absent", now["absent"]?.records, empty: nil) { r in
                        Text(r.string("shift") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                    }
                    peopleSection("Expected later", now["expected"]?.records, empty: nil) { r in
                        Text(r.string("shift") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    peopleSection("On leave", now["on_leave"]?.records, empty: nil) { r in
                        Text("\(r.string("type") ?? "Leave") · until \(Day.format(r.string("until")))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                    if let p = o["period"], !p.isNull {
                        SectionCard("Pay period") {
                            KeyValueGrid(pairs: [
                                ("Period", "\(Day.format(p["starts_on"]?.string)) – \(Day.format(p["ends_on"]?.string))"),
                                ("Status", p["status"]?.string?.humanized ?? "—"),
                                ("Days left", p["days_left"]?.string ?? "—"),
                                ("Timesheets", "\(p["approved"]?.int ?? 0) approved · \(p["submitted"]?.int ?? 0) submitted · \(p["people"]?.int ?? 0) people"),
                            ])
                        }
                    }
                    if let rules = o["rules"] {
                        Text("Location: \(rules["location_mode"]?.string?.humanized ?? "—") · Clock-in without a shift: \(rules["clock_in_without_shift"]?.string?.humanized ?? "—")")
                            .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                }
            }
        }
        .task {
            if resource == nil {
                let c = env.session.client
                resource = Resource { try await c.json("/v1/admin/people/overview", background: true) }
                resource?.load()
            }
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(30))
                await resource?.reload()
            }
        }
        .refreshable { await resource?.reload() }
    }

    @ViewBuilder
    private func peopleSection<T: View>(_ title: String, _ rows: [Record]?, empty: String?, @ViewBuilder trailing: @escaping (Record) -> T) -> some View {
        let list = rows ?? []
        if !list.isEmpty || empty != nil {
            SectionCard("\(title) (\(list.count))") {
                if list.isEmpty, let empty {
                    Text(empty).font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary)
                }
                ForEach(list) { r in
                    HStack(spacing: Theme.Space.m) {
                        Avatar(name: r["person"]?["name"]?.string ?? "?")
                        VStack(alignment: .leading, spacing: 2) {
                            Text(r["person"]?["name"]?.string ?? "—").font(Theme.Font.body)
                            if let o = r.string("outlet") { Text(o).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        }
                        Spacer()
                        trailing(r)
                    }
                    .padding(.vertical, 2)
                }
            }
        }
    }
}

// MARK: - Requests (GET /people/requests)

struct PeopleRequestsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var deciding: FixDecision?
    @State private var version = 0

    struct FixDecision: Identifiable { let id: Int; let decision: String; let summary: String }

    var body: some View {
        Page("Requests", subtitle: "Leave, swaps, advances and timesheet fixes waiting on a manager") {
            RemoteJSON("/v1/admin/people/requests", version: version, emptyTitle: "Nothing is waiting", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { json, _ in
                let items = json["data"]?.records ?? []
                let counts = json["counts"]?.object ?? [:]
                HStack(spacing: Theme.Space.s) {
                    ForEach(["leave", "swap", "advance", "fix"], id: \.self) { k in
                        Pill("\(kindLabel(k)): \(counts[k]?.int ?? 0)", .info)
                    }
                }
                ForEach(["fix", "leave", "swap", "advance"], id: \.self) { kind in
                    let rows = items.filter { $0.string("kind") == kind }
                    if !rows.isEmpty {
                        SectionCard(kindLabel(kind)) {
                            ForEach(rows) { r in requestRow(r) }
                        }
                    }
                }
            }
        }
        .sheet(item: $deciding) { d in
            FixDecisionSheet(decision: d) { deciding = nil; version += 1 }
        }
    }

    private func kindLabel(_ k: String) -> String {
        switch k { case "leave": return "Leave"; case "swap": return "Shift swaps"; case "advance": return "Advances"; case "fix": return "Timesheet fixes"; default: return k.humanized }
    }

    private func requestRow(_ r: Record) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Avatar(name: r.string("person") ?? "?", size: 28)
                VStack(alignment: .leading, spacing: 2) {
                    Text(r.string("person") ?? "—").font(Theme.Font.body.weight(.semibold))
                    Text(r.string("summary") ?? "").font(Theme.Font.secondary)
                    if let n = r.string("note"), !n.isEmpty { Text("“\(n)”").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                }
                Spacer()
                DateText(r.date("asked_at"), style: .relative)
            }
            HStack {
                Spacer()
                if r.string("kind") == "fix" {
                    if env.session.can("attendance.manage") {
                        Button("Decline") { deciding = FixDecision(id: r.int("id") ?? 0, decision: "decline", summary: r.string("summary") ?? "") }
                        Button("Mark corrected") { deciding = FixDecision(id: r.int("id") ?? 0, decision: "approve", summary: r.string("summary") ?? "") }
                            .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                    Button("Open attendance") { env.navigator.go(.attendance) }
                } else {
                    Button("Open") {
                        switch r.string("kind") {
                        case "leave": env.navigator.go(.leave)
                        case "swap": env.navigator.go(.roster)
                        case "advance": env.navigator.go(.payroll)
                        default: if let h = r.string("href"), let route = Route.from(consolePath: h) { env.navigator.go(route) }
                        }
                    }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
        }
        .padding(.vertical, 6)
    }
}

/// POST /people/requests/fixes/{id}/{approve|decline} {note}
struct FixDecisionSheet: View {
    @Environment(AppEnvironment.self) private var env
    let decision: PeopleRequestsView.FixDecision
    let done: () -> Void
    @State private var note = ""

    var body: some View {
        EditSheet(decision.decision == "approve" ? "Mark corrected" : "Decline fix request", saveLabel: decision.decision == "approve" ? "Mark corrected" : "Decline", destructive: decision.decision != "approve", save: {
            let r = try await env.session.client.post("/v1/admin/people/requests/fixes/\(decision.id)/\(decision.decision)", json: J.obj([("note", J.sOpt(note))]))
            toastResult(env, r)
            done()
        }) { e in
            Text(decision.summary).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            Text(decision.decision == "approve" ? "Correct the hours on the Attendance screen first; this tells the person it is done." : "The person is told their request was declined.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Field("Note to the person", $note, error: e?.errors["note"]?.first, multiline: true)
        }
    }
}
