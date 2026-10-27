import SwiftUI
import BethanyKit

/// Production configuration (stages, auto-assignees), the activity log and
/// the trash.

// MARK: - Production stages (GET /product-stages (array); POST; PUT; DELETE; PUT /reorder {stages: [ids]})

struct ProductionStagesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: StageDraft?
    @State private var order: [Record] = []

    var body: some View {
        Page("Production stages", subtitle: "The pipeline every production order moves through, in order") {
            HStack { Spacer(); Button { editing = StageDraft() } label: { Label("New stage", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            RemoteJSON("/v1/admin/product-stages", version: version) { j, reload in
                let rows = order.isEmpty ? j.listRecords : order
                ForEach(Array(rows.enumerated()), id: \.element.id) { i, s in
                    HStack {
                        Circle().fill(Color(hex: UInt32(s.string("color")?.dropFirst() ?? "757d72", radix: 16) ?? 0x757D72)).frame(width: 12, height: 12)
                        VStack(alignment: .leading, spacing: 1) {
                            Text("\(i + 1). \(s.string("name") ?? "")").font(Theme.Font.body)
                            if let d = s.string("description"), !d.isEmpty { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                        }
                        Spacer()
                        if s["is_active"]?.bool == false { Pill("inactive", .neutral) }
                        Button { move(rows, i, -1) } label: { Image(systemName: "arrow.up") }.disabled(i == 0)
                        Button { move(rows, i, 1) } label: { Image(systemName: "arrow.down") }.disabled(i == rows.count - 1)
                        Button("Edit") { editing = StageDraft(record: s) }
                        ConfirmButton("Delete stage", message: "A stage in use by active tasks cannot be deleted.", destructive: true, action: {
                            do { let r = try await env.session.client.delete("/v1/admin/product-stages/\(s.id)"); toastResult(env, r); order = []; reload() } catch { env.toasts.error(error) }
                        }) { Image(systemName: "trash") }
                    }
                    .padding(Theme.Space.m).background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                }
                if !order.isEmpty {
                    HStack {
                        Button("Discard order") { order = [] }
                        AsyncButton("Save order") {
                            let r = try await env.session.client.put("/v1/admin/product-stages/reorder", json: J.obj([("stages", J.ints(order.compactMap { $0.int("id") }))]))
                            toastResult(env, r); order = []; reload()
                        }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
            }
        }
        .sheet(item: $editing) { d in StageSheet(draft: d) { order = []; version += 1 } }
    }

    private func move(_ rows: [Record], _ i: Int, _ by: Int) {
        var r = rows
        let j = i + by
        guard r.indices.contains(j) else { return }
        r.swapAt(i, j)
        order = r
    }
}

struct StageDraft: Identifiable {
    var id: Int?; var name = ""; var description = ""; var color = "#3b82f6"; var active = true
    init() {}
    init(record s: Record) { id = s.int("id"); name = s.string("name") ?? ""; description = s.string("description") ?? ""; color = s.string("color") ?? "#3b82f6"; active = s["is_active"]?.bool ?? true }
}

struct StageSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: StageDraft
    let saved: () -> Void
    static let colours = [("#757d72", "Slate"), ("#3b82f6", "Blue"), ("#8b5cf6", "Violet"), ("#ec4899", "Pink"), ("#f59e0b", "Amber"), ("#10b981", "Green"), ("#ef4444", "Red"), ("#06b6d4", "Cyan")]
    var body: some View {
        EditSheet(draft.id == nil ? "New stage" : "Edit stage", canSave: !draft.name.isEmpty, save: {
            var pairs: [(String, JSONValue?)] = [("name", .string(draft.name)), ("description", J.s(draft.description)), ("color", .string(draft.color))]
            let r: APIClient.Response
            if let id = draft.id { pairs.append(("is_active", .bool(draft.active))); r = try await env.session.client.put("/v1/admin/product-stages/\(id)", json: J.obj(pairs)) }
            else { r = try await env.session.client.post("/v1/admin/product-stages", json: J.obj(pairs)) }
            toastResult(env, r); saved()
        }) { e in
            Field("Name", $draft.name, error: e?.errors["name"]?.first)
            Field("Description", $draft.description, multiline: true)
            OptionPicker("Colour", $draft.color, options: Self.colours)
            if draft.id != nil { ToggleRow("Active", $draft.active) }
        }
    }
}

// MARK: - Auto-assignees (GET /production/auto-assignees (array); POST {user_id, role_in_order, outlet_id}; DELETE)

struct AutoAssigneesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var adding = false
    var body: some View {
        Page("Auto-assignees", subtitle: "People added to every new production order automatically") {
            HStack { Spacer(); Button { adding = true } label: { Label("Add rule", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            RemoteJSON("/v1/admin/production/auto-assignees", version: version, emptyTitle: "No rules yet", isEmpty: { $0.listRecords.isEmpty }) { j, reload in
                ForEach(j.listRecords) { r in
                    SupportListRow("\(r["user"]?["first_name"]?.string ?? "") \(r["user"]?["last_name"]?.string ?? "")", subtitle: "\(r.string("role_in_order")?.humanized ?? "observer") · \(r["outlet"]?["name"]?.string ?? "every outlet")") {
                        HStack {
                            if r["is_active"]?.bool == false { Pill("inactive", .neutral) }
                            AsyncButton(role: .destructive) { let x = try await env.session.client.delete("/v1/admin/production/auto-assignees/\(r.id)"); toastResult(env, x); reload() } label: { Image(systemName: "trash") }
                        }
                    }
                }
            }
        }
        .sheet(isPresented: $adding) { AutoAssigneeSheet { version += 1 } }
    }
}

struct AutoAssigneeSheet: View {
    @Environment(AppEnvironment.self) private var env
    let saved: () -> Void
    @State private var user: Int?
    @State private var role = "observer"
    @State private var outlet: Int?
    var body: some View {
        EditSheet("Auto-assignee rule", canSave: user != nil, save: {
            let r = try await env.session.client.post("/v1/admin/production/auto-assignees", json: J.obj([("user_id", J.i(user)), ("role_in_order", .string(role)), ("outlet_id", J.i(outlet))]))
            toastResult(env, r); saved()
        }) { e in
            StaffPicker("Person", $user)
            Field("Role in the order", $role, placeholder: "observer, supervisor, qc…", error: e?.errors["role_in_order"]?.first)
            OutletChoice("Only for outlet", $outlet, allowAll: true)
        }
    }
}

// MARK: - Activity logs (super admin): GET /activity-logs (paginated), /requests, /integrity, /export (step-up), /{id}

struct ActivityLogsView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case changes, requests, integrity }
    @State private var tab: Tab = .changes
    @State private var list: PagedList<Record>?
    @State private var requests: PagedList<Record>?
    @State private var search = ""
    @State private var action = ""
    @State private var from = ""
    @State private var to = ""
    @State private var open: Record?
    @State private var share: URL?

    var body: some View {
        Page("Activity logs", subtitle: "The permanent record of every change and every staff API call") {
            HStack {
                Tabs(selection: $tab, items: [(.changes, "Changes"), (.requests, "Requests"), (.integrity, "Integrity")])
                Spacer()
                AsyncButton {
                    let (data, name, _) = try await env.session.client.download("/v1/admin/activity-logs/export", query: ["search": search.isEmpty ? nil : search, "action": action.isEmpty ? nil : action, "start_date": from.isEmpty ? nil : from, "end_date": to.isEmpty ? nil : to])
                    presentFile(env, data: data, name: name ?? "activity-logs.json", share: $share)
                } label: { Label("Export (step-up)", systemImage: "arrow.down.doc") }
            }
            switch tab {
            case .changes:
                HStack(alignment: .bottom) {
                    TextField("Search description, action or person", text: $search).textFieldStyle(.roundedBorder).onSubmit { Task { await reload() } }
                    Field("Action", $action)
                    Field("From (yyyy-mm-dd)", $from)
                    Field("To", $to)
                    Button("Apply") { Task { await reload() } }
                }
                if let list {
                    LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No entries", symbol: "doc.text.magnifyingglass") }) { rows in
                        LazyVStack(spacing: Theme.Space.s) {
                            ForEach(rows) { l in
                                Button { open = l } label: {
                                    SupportListRow(l.string("description") ?? l.string("action") ?? "", subtitle: "\(l.string("user_name") ?? "System") · \(l.string("action") ?? "")\(l.string("subject_type").map { " · \($0.split(separator: "\\").last.map(String.init) ?? $0) #\(l["subject_id"]?.int ?? 0)" } ?? "") · \(l.string("ip_address") ?? "")") { DateText(l.date("created_at")) }
                                }.buttonStyle(.plain)
                                .onAppear { if l.id == rows.last?.id { Task { await list.loadMore() } } }
                            }
                        }
                        Text("\(list.total) entries").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                }
            case .requests:
                if let requests {
                    LoadableView(requests.state, retry: { Task { await requests.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No requests logged", symbol: "network") }) { rows in
                        LazyVStack(spacing: Theme.Space.s) {
                            ForEach(rows) { r in
                                SupportListRow("\(r.string("method") ?? "") \(r.string("path") ?? "")", subtitle: "\(r.string("user_name") ?? "—") · \(r.int("status") ?? 0) · \(r["duration_ms"]?.int ?? 0) ms · \(r["rows_returned"]?.int.map { "\($0) rows · " } ?? "")\(r.string("ip_address") ?? "")") { DateText(r.date("occurred_at")) }
                                    .onAppear { if r.id == rows.last?.id { Task { await requests.loadMore() } } }
                            }
                        }
                    }
                }
            case .integrity:
                RemoteJSON("/v1/admin/activity-logs/integrity") { j, _ in
                    if let c = j["last_check"], !c.isNull {
                        SectionCard("Last verification") {
                            HStack { Pill(c["ok"]?.bool == true ? "ok" : "problem", c["ok"]?.bool == true ? .success : .danger); Text(Day.dateTime(c["checked_at"]?.string)).font(Theme.Font.secondary) }
                            Text(prettyJSON(c["details"])).font(Theme.Font.mono).foregroundStyle(Theme.textSecondary)
                        }
                    }
                    ForEach((j["tables"]?.object ?? [:]).keys.sorted(), id: \.self) { t in
                        let info = j["tables"]?[t] ?? .null
                        SectionCard(t) {
                            if let seal = info["last_seal"], !seal.isNull {
                                KeyValueGrid(pairs: [("Sealed to id", seal["last_id"]?.string ?? "—"), ("Rows", seal["row_count"]?.string ?? "—"), ("Hash", seal["hash"]?.string ?? "—"), ("Sealed at", Day.dateTime(seal["sealed_at"]?.string)), ("Unsealed rows", info["unsealed_rows"]?.string ?? "0")])
                            } else { Text("Never sealed · \(info["unsealed_rows"]?.int ?? 0) rows").font(Theme.Font.secondary) }
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                list = PagedList(client: env.session.client, path: "/v1/admin/activity-logs")
                requests = PagedList(client: env.session.client, path: "/v1/admin/activity-logs/requests")
                await reload(); await requests?.load()
            }
        }
        .sheet(item: $open) { l in
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                Text(l.string("description") ?? "Entry").font(Theme.Font.sectionTitle)
                KeyValueGrid(pairs: [("By", "\(l.string("user_name") ?? "System") \(l.string("user_email") ?? "")"), ("Action", l.string("action") ?? ""), ("Subject", "\(l.string("subject_type") ?? "") #\(l["subject_id"]?.int ?? 0)"), ("IP", l.string("ip_address") ?? "—"), ("Request", l.string("request_id") ?? "—"), ("When", Day.dateTime(l.string("created_at")))])
                ScrollView { Text(l["properties"]?.string ?? prettyJSON(l["properties"])).font(Theme.Font.mono).textSelection(.enabled) }
                HStack { Spacer(); Button("Close") { open = nil } }
            }
            .padding(Theme.Space.xl)
            #if os(macOS)
            .frame(minWidth: 520, minHeight: 420)
            #endif
        }
        .shareFile($share)
    }

    private func reload() async {
        list?.query = ["search": search.isEmpty ? nil : search, "action": action.isEmpty ? nil : action, "start_date": from.isEmpty ? nil : from, "end_date": to.isEmpty ? nil : to]
        await list?.load()
    }
}

// MARK: - Trash (GET /trash summary; /trash/{model}?search (data + meta); restore; restore-all; force delete; empty)

struct TrashView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var model = "products"
    @State private var search = ""
    @State private var version = 0
    static let models = [("products", "Products"), ("categories", "Categories"), ("users", "Users"), ("customers", "Customers")]

    var body: some View {
        Page("Trash", subtitle: "Deleted records that can still be restored") {
            RemoteJSON("/v1/admin/trash", version: version) { s, _ in
                HStack { ForEach(Self.models, id: \.0) { m in Button("\(m.1) (\(s["summary"]?[m.0]?.int ?? 0))") { model = m.0 }.buttonStyle(.bordered).tint(model == m.0 ? Theme.Palette.brand : Theme.Palette.neutral) } }
            }
            HStack {
                TextField("Search", text: $search).textFieldStyle(.roundedBorder).onSubmit { version += 1 }
                ConfirmButton("Restore all", message: "Every deleted \(model.dropLast()) comes back.", action: { do { let r = try await env.session.client.post("/v1/admin/trash/\(model)/restore-all"); toastResult(env, r); version += 1 } catch { env.toasts.error(error) } }) { Text("Restore all") }
                ConfirmButton("Empty trash", message: "Every deleted \(model.dropLast()) is gone for good.", destructive: true, action: { do { let r = try await env.session.client.delete("/v1/admin/trash/\(model)/empty"); toastResult(env, r); version += 1 } catch { env.toasts.error(error) } }) { Text("Empty").foregroundStyle(Theme.Palette.danger) }
            }
            RemoteJSON("/v1/admin/trash/\(model)", query: ["search": search.isEmpty ? nil : search, "per_page": "50"], version: version, emptyTitle: "Nothing in the trash", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { item in
                    SupportListRow(trashName(item), subtitle: "\(trashMeta(item)) · deleted \(Day.dateTime(item.string("deleted_at")))") {
                        HStack {
                            AsyncButton("Restore") { let r = try await env.session.client.post("/v1/admin/trash/\(model)/\(item.id)/restore"); toastResult(env, r); reload(); version += 1 }
                            ConfirmButton("Delete forever", message: "This cannot be undone.", destructive: true, action: { do { let r = try await env.session.client.delete("/v1/admin/trash/\(model)/\(item.id)"); toastResult(env, r); reload(); version += 1 } catch { env.toasts.error(error) } }) { Image(systemName: "trash") }
                        }
                    }
                }
                Text("\(j["meta"]?["total"]?.int ?? 0) in the trash").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
    }

    private func trashName(_ i: Record) -> String {
        switch model {
        case "products": return i["translations"]?.array?.first?["name"]?.string ?? i.string("sku") ?? "Product"
        case "categories": return i.string("name_en") ?? i.string("slug") ?? "Category"
        default: return "\(i.string("first_name") ?? "") \(i.string("last_name") ?? "")"
        }
    }
    private func trashMeta(_ i: Record) -> String {
        switch model {
        case "products": return "SKU \(i.string("sku") ?? "") · \(i.string("product_type") ?? "")"
        case "categories": return "Slug \(i.string("slug") ?? "")"
        case "customers": return "\(i.string("email") ?? "") · \(i.string("customer_number") ?? "")"
        default: return i.string("email") ?? ""
        }
    }
}
