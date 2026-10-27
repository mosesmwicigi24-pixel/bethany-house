import SwiftUI
import BethanyKit

/// The weekly roster (People PR 3): a members × days grid per outlet with
/// open shifts, add/edit/remove, copy last week, publish, shift templates
/// and swap decisions. Labour cost is shown only as the server estimated it.
struct RosterView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var outlet: Int?
    @State private var week = Day.monday(of: Day.today())
    @State private var version = 0
    @State private var editing: ShiftDraft?
    @State private var showTemplates = false
    @State private var showSwaps = false

    var body: some View {
        Page("Roster", subtitle: "Week of \(Day.format(week, "d MMM yyyy"))") {
            HStack(alignment: .bottom, spacing: Theme.Space.s) {
                OutletChoice("Outlet", $outlet)
                Spacer()
                Button { week = Day.add(week, days: -7) } label: { Image(systemName: "chevron.left") }
                Button("This week") { week = Day.monday(of: Day.today()) }
                Button { week = Day.add(week, days: 7) } label: { Image(systemName: "chevron.right") }
            }
            if let outlet {
                RemoteJSON("/v1/admin/roster", query: ["outlet_id": String(outlet), "week": week], version: version) { j, reload in
                    let canManage = j["can_manage"]?.bool ?? env.session.can("roster.manage")
                    toolbar(j, outlet: outlet, canManage: canManage, reload: reload)
                    RosterGrid(week: j, canManage: canManage) { draft in editing = draft }
                    if let cost = j["cost"], !cost.isNull {
                        SectionCard("Labour cost (estimate)") {
                            HStack { Text("Total"); Spacer(); MoneyText(cost["total"].flatMap { Money(json: $0, currency: cost["currency"]?.string ?? "KES") }, emphasis: true) }
                            Text("\(cost["basis"]?.string ?? "")\((cost["uncosted_shifts"]?.int ?? 0) > 0 ? " · \(cost["uncosted_shifts"]?.int ?? 0) shifts without a pay structure" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                        }
                    }
                    let swaps = j["pending_swaps"]?.records ?? []
                    if !swaps.isEmpty {
                        SectionCard("Swap requests (\(swaps.count))") {
                            ForEach(swaps) { s in swapRow(s, canManage: canManage, reload: reload) }
                        }
                    }
                }
            } else {
                EmptyState("Choose an outlet", symbol: "building.2")
            }
        }
        .sheet(item: $editing) { d in ShiftSheet(draft: d, outletId: outlet ?? 0) { version += 1 } }
        .sheet(isPresented: $showTemplates) { ShiftTemplatesSheet(outletId: outlet ?? 0) }
    }

    @ViewBuilder
    private func toolbar(_ j: JSONValue, outlet: Int, canManage: Bool, reload: @escaping () -> Void) -> some View {
        HStack(spacing: Theme.Space.s) {
            Pill("\(j["open_count"]?.int ?? 0) open", .amber)
            Pill("\(j["draft_count"]?.int ?? 0) draft", .neutral)
            Spacer()
            if canManage {
                Button { editing = ShiftDraft(date: week) } label: { Label("Add shift", systemImage: "plus") }
                Button { editing = ShiftDraft(date: week, open: true) } label: { Label("Open shift", systemImage: "person.badge.plus") }
                Button("Templates") { showTemplates = true }
                AsyncButton("Copy last week") {
                    let r = try await env.session.client.post("/v1/admin/roster/copy-week", json: J.obj([("outlet_id", J.i(outlet)), ("from_week", .string(Day.add(week, days: -7))), ("to_week", .string(week))]))
                    toastResult(env, r); reload()
                }
                ConfirmButton("Publish week", message: "Everyone on the roster is told their shifts for this week.", action: {
                    do { let r = try await env.session.client.post("/v1/admin/roster/publish", json: J.obj([("outlet_id", J.i(outlet)), ("week", .string(week))])); toastResult(env, r); reload() } catch { env.toasts.error(error) }
                }) { Label("Publish", systemImage: "paperplane") }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    .disabled((j["draft_count"]?.int ?? 0) == 0)
            }
        }
    }

    private func swapRow(_ s: Record, canManage: Bool, reload: @escaping () -> Void) -> some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text("\(s["from_user"]?["name"]?.string ?? "—") → \(s["to_user"]?["name"]?.string ?? "anyone")").font(Theme.Font.body)
                if let sh = s["shift"], !sh.isNull { Text("\(Day.format(sh["date"]?.string)) \(sh["starts_at"]?.string ?? "")–\(sh["ends_at"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                if let n = s.string("note"), !n.isEmpty { Text("“\(n)”").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            }
            Spacer()
            if canManage {
                AsyncButton("Decline", role: .destructive) { let r = try await env.session.client.post("/v1/admin/roster/swaps/\(s.id)/decline"); toastResult(env, r); reload() }
                AsyncButton("Approve") { let r = try await env.session.client.post("/v1/admin/roster/swaps/\(s.id)/approve"); toastResult(env, r); reload() }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
    }
}

struct ShiftDraft: Identifiable {
    var id: Int?
    var userId: Int?
    var date: String
    var startsAt = "08:00"
    var endsAt = "17:00"
    var label = ""
    var notes = ""
    var publish = false
    var status: String? = nil

    init(date: String, open: Bool = false, userId: Int? = nil) {
        self.date = date
        self.userId = open ? nil : userId
    }
    init(shift s: Record) {
        id = s.int("id")
        userId = s.int("user_id")
        date = s.string("date") ?? Day.today()
        startsAt = s.string("starts_at") ?? "08:00"
        endsAt = s.string("ends_at") ?? "17:00"
        label = s.string("label") ?? ""
        notes = s.string("notes") ?? ""
        status = s.status
    }
}

/// members × days. Each cell lists that person's shifts; the last row is open shifts.
struct RosterGrid: View {
    let week: JSONValue
    let canManage: Bool
    let edit: (ShiftDraft) -> Void

    var body: some View {
        let days = week["days"]?.array?.compactMap(\.string) ?? []
        let members = week["members"]?.records ?? []
        let shifts = week["shifts"]?.records ?? []
        let holidays = week["holidays"]?.object ?? [:]
        let leave = week["leave"]?.object ?? [:]
        let hours = week["hours_by_user"]?.object ?? [:]
        ScrollView(.horizontal) {
            Grid(alignment: .topLeading, horizontalSpacing: 4, verticalSpacing: 4) {
                GridRow {
                    Text("").frame(width: 150)
                    ForEach(days, id: \.self) { d in
                        VStack(spacing: 2) {
                            Text(Day.format(d)).font(Theme.Font.caption.weight(.semibold))
                            if let h = holidays[d]?.string { Text(h).font(.system(size: 9)).foregroundStyle(Theme.Palette.pink).lineLimit(1) }
                        }
                        .frame(width: 150)
                    }
                }
                ForEach(members) { m in
                    GridRow {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(m.string("name") ?? "—").font(Theme.Font.secondary.weight(.semibold)).lineLimit(1)
                            Text("\(m.string("job_title") ?? "") · \(minutesText(hours[m.id]?.int ?? 0))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                        }
                        .frame(width: 150, alignment: .leading)
                        ForEach(days, id: \.self) { d in
                            cell(shifts.filter { $0.string("user_id") == m.id && $0.string("date") == d }, date: d, userId: m.int("id"), leave: leave[m.id]?[d])
                        }
                    }
                }
                GridRow {
                    Text("Open shifts").font(Theme.Font.secondary.weight(.semibold)).foregroundStyle(Theme.Palette.amber).frame(width: 150, alignment: .leading)
                    ForEach(days, id: \.self) { d in
                        cell(shifts.filter { $0["user_id"]?.isNull != false && $0.string("date") == d }, date: d, userId: nil, leave: nil, open: true)
                    }
                }
            }
            .padding(.bottom, 4)
        }
    }

    private func cell(_ list: [Record], date: String, userId: Int?, leave: JSONValue?, open: Bool = false) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            if let leave, !leave.isNull {
                Text("\(leave["type"]?.string ?? "Leave")\(leave["status"]?.string == "pending" ? " (pending)" : "")").font(.system(size: 10)).foregroundStyle(Theme.Palette.accent)
            }
            ForEach(list) { s in
                Button { if canManage { edit(ShiftDraft(shift: s)) } } label: {
                    VStack(alignment: .leading, spacing: 1) {
                        Text("\(s.string("starts_at") ?? "")–\(s.string("ends_at") ?? "")").font(Theme.Font.caption.weight(.semibold))
                        if let l = s.string("label"), !l.isEmpty { Text(l).font(.system(size: 10)).lineLimit(1) }
                    }
                    .padding(4)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background((s.status == "published" ? Theme.Palette.successSoft : (open ? Theme.Palette.amberSoft : Theme.Palette.neutralSoft)), in: RoundedRectangle(cornerRadius: 6))
                    .foregroundStyle(s.status == "published" ? Theme.Palette.success : Theme.Palette.ink)
                }
                .buttonStyle(.plain)
            }
            if canManage {
                Button { var d = ShiftDraft(date: date, open: open, userId: userId); d.date = date; edit(d) } label: { Image(systemName: "plus").font(.system(size: 10)) }
                    .buttonStyle(.plain).foregroundStyle(Theme.textTertiary)
            }
        }
        .frame(width: 150, minHeight: 44, alignment: .topLeading)
        .padding(3)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: 8))
    }
}

/// POST /roster/shifts, PUT/DELETE /roster/shifts/{id}
struct ShiftSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @State var draft: ShiftDraft
    let outletId: Int
    let saved: () -> Void
    @State private var templates: [Record] = []

    var body: some View {
        EditSheet(draft.id == nil ? (draft.userId == nil ? "Add open shift" : "Add shift") : "Edit shift", save: {
            let response: APIClient.Response
            if let id = draft.id {
                response = try await env.session.client.put("/v1/admin/roster/shifts/\(id)", json: J.obj([
                    ("user_id", J.i(draft.userId)), ("date", .string(draft.date)), ("starts_at", .string(draft.startsAt)), ("ends_at", .string(draft.endsAt)),
                    ("label", J.s(draft.label)), ("notes", J.s(draft.notes)),
                ]))
            } else {
                response = try await env.session.client.post("/v1/admin/roster/shifts", json: J.obj([
                    ("outlet_id", J.i(outletId)), ("user_id", J.i(draft.userId)), ("date", .string(draft.date)), ("starts_at", .string(draft.startsAt)), ("ends_at", .string(draft.endsAt)),
                    ("label", J.s(draft.label)), ("notes", J.s(draft.notes)), ("publish", .bool(draft.publish)),
                ]))
            }
            toastResult(env, response)
            saved()
        }) { e in
            if !templates.isEmpty {
                HStack {
                    Text("Template").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    ForEach(templates) { t in
                        Button(t.string("name") ?? "") { draft.startsAt = t.string("starts_at") ?? draft.startsAt; draft.endsAt = t.string("ends_at") ?? draft.endsAt; if let l = t.string("label") { draft.label = l } }
                            .buttonStyle(.bordered).controlSize(.small)
                    }
                }
            }
            StaffPicker("Person", $draft.userId, allowNone: true)
            DayField("Date", $draft.date)
            HStack { Field("Starts (HH:mm)", $draft.startsAt, error: e?.errors["starts_at"]?.first); Field("Ends (HH:mm)", $draft.endsAt, error: e?.errors["ends_at"]?.first) }
            Field("Label", $draft.label, placeholder: "Morning till")
            Field("Notes", $draft.notes, multiline: true)
            if draft.id == nil { ToggleRow("Publish straight away", $draft.publish) }
            if let s = draft.status { HStack { Text("Status").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); StatusBadge(s) } }
            if let id = draft.id {
                ConfirmButton("Remove shift", message: "A published shift is cancelled and the person is told.", destructive: true, action: {
                    do { let r = try await env.session.client.delete("/v1/admin/roster/shifts/\(id)"); toastResult(env, r); saved(); dismiss() } catch { env.toasts.error(error) }
                }) { Label("Remove shift", systemImage: "trash").foregroundStyle(Theme.Palette.danger) }
            }
        }
        .task { templates = (try? await env.session.client.json("/v1/admin/roster/templates", query: ["outlet_id": String(outletId)]))?["data"]?.records ?? [] }
    }
}

/// GET/POST/DELETE /roster/templates
struct ShiftTemplatesSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let outletId: Int
    @State private var version = 0
    @State private var name = ""
    @State private var starts = "08:00"
    @State private var ends = "17:00"
    @State private var label = ""
    @State private var here = true

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            HStack { Text("Shift templates").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            RemoteJSON("/v1/admin/roster/templates", query: ["outlet_id": String(outletId)], version: version, emptyTitle: "No templates yet", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { t in
                    SupportListRow(t.string("name") ?? "", subtitle: "\(t.string("starts_at") ?? "")–\(t.string("ends_at") ?? "")\(t.string("label").map { " · \($0)" } ?? "")\(t["outlet_id"]?.isNull == false ? "" : " · every outlet")") {
                        if env.session.can("roster.manage") {
                            AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/roster/templates/\(t.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
                        }
                    }
                }
            }
            if env.session.can("roster.manage") {
                Divider()
                Text("New template").font(Theme.Font.sectionTitle)
                Field("Name", $name, placeholder: "Morning")
                HStack { Field("Starts", $starts); Field("Ends", $ends) }
                Field("Label", $label)
                ToggleRow("Only this outlet", $here)
                AsyncButton("Add template") {
                    let r = try await env.session.client.post("/v1/admin/roster/templates", json: J.obj([("outlet_id", here ? J.i(outletId) : .null), ("name", .string(name)), ("starts_at", .string(starts)), ("ends_at", .string(ends)), ("label", J.s(label))]))
                    toastResult(env, r); name = ""; version += 1
                }
                .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(name.isEmpty)
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 480, minHeight: 420)
        #endif
    }
}
