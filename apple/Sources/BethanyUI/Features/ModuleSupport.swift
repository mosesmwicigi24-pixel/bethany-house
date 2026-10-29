import SwiftUI
import BethanyKit
import PhotosUI

// Shared building blocks for the Production, Inventory and Procurement
// modules: a paginated record list that understands both paginator shapes
// the API uses, prompt sheets, pickers, an audit-log section, name helpers
// and a photo attachment control. Nothing here computes money.

// MARK: - RecordList

/// A list endpoint that answers either `{data, meta: {current_page, last_page}}`
/// (most module controllers) or Laravel's bare paginator `{data, current_page, …}`
/// or a plain array. Anything beside `data` (stats, summary, reason_codes) is
/// kept in `extras` so headers can read it.
@MainActor
@Observable
final class RecordList {
    private(set) var items: [Record] = []
    private(set) var state: Loadable<[Record]> = .idle
    private(set) var extras: JSONValue = .null
    private(set) var total = 0
    private(set) var hasMore = false
    private(set) var isLoadingMore = false
    var query: [String: String?]
    var perPage = 25

    private let client: APIClient
    private let path: String
    private var page = 1
    private var token = UUID()

    init(client: APIClient, path: String, query: [String: String?] = [:], perPage: Int = 25) {
        self.client = client
        self.path = path
        self.query = query
        self.perPage = perPage
    }

    func load() async {
        let my = UUID()
        token = my
        state = .loading(previous: items.isEmpty ? nil : items)
        do {
            let r = try await fetch(page: 1)
            guard token == my else { return }
            items = r.rows
            extras = r.extras
            total = r.total
            page = 1
            hasMore = r.hasMore
            state = .loaded(items)
        } catch let e as APIError {
            guard token == my else { return }
            state = .failed(e, previous: items.isEmpty ? nil : items)
        } catch {
            guard token == my else { return }
            state = .failed(APIError.transport(error.localizedDescription), previous: items.isEmpty ? nil : items)
        }
    }

    func loadMore() async {
        guard hasMore, !isLoadingMore else { return }
        isLoadingMore = true
        defer { isLoadingMore = false }
        if let r = try? await fetch(page: page + 1) {
            page += 1
            items.append(contentsOf: r.rows)
            hasMore = r.hasMore
            state = .loaded(items)
        }
    }

    func replace(_ record: Record) {
        if let i = items.firstIndex(where: { $0.id == record.id }) { items[i] = record; state = .loaded(items) }
    }

    private struct PageResult { let rows: [Record]; let extras: JSONValue; let total: Int; let hasMore: Bool }

    private func fetch(page: Int) async throws -> PageResult {
        var q = query
        q["page"] = String(page)
        q["per_page"] = String(perPage)
        let json = try await client.get(path, query: q).json ?? .null
        if let arr = json.array {
            return PageResult(rows: arr.map(Record.init(json:)), extras: .null, total: arr.count, hasMore: false)
        }
        var data = json["data"] ?? .null
        var pager = json["meta"] ?? json
        if let nested = data["data"], nested.array != nil { pager = data; data = nested }
        let rows = (data.array ?? []).map(Record.init(json:))
        let current = pager["current_page"]?.int ?? page
        let last = pager["last_page"]?.int ?? current
        var extras = json.object ?? [:]
        extras.removeValue(forKey: "data")
        return PageResult(rows: rows, extras: .object(extras), total: pager["total"]?.int ?? rows.count, hasMore: current < last)
    }
}

/// Renders a `RecordList` with the shared states and infinite scroll.
struct RecordListView<Row: View>: View {
    let list: RecordList
    let emptyTitle: String
    let emptySymbol: String
    @ViewBuilder let row: (Record) -> Row

    init(_ list: RecordList, emptyTitle: String, emptySymbol: String = "tray", @ViewBuilder row: @escaping (Record) -> Row) {
        self.list = list
        self.emptyTitle = emptyTitle
        self.emptySymbol = emptySymbol
        self.row = row
    }

    var body: some View {
        LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState(emptyTitle, symbol: emptySymbol) }) { items in
            LazyVStack(spacing: Theme.Space.s) {
                ForEach(items) { r in
                    row(r).onAppear { if r.id == items.last?.id { Task { await list.loadMore() } } }
                }
                if list.isLoadingMore { ProgressView().padding() }
            }
        }
    }
}

/// A tappable card row for list screens.
struct ModuleRowCard<Content: View>: View {
    let action: () -> Void
    @ViewBuilder let content: Content

    init(action: @escaping () -> Void, @ViewBuilder content: () -> Content) {
        self.action = action
        self.content = content()
    }

    var body: some View {
        Button(action: action) {
            HStack(alignment: .top, spacing: Theme.Space.m) {
                content
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).padding(.top, 4)
            }
            .padding(Theme.Space.m)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - People & formatting

enum Names {
    /// "first_name last_name" from a user object, else `name`, else nil.
    static func person(_ json: JSONValue?) -> String? {
        guard let json else { return nil }
        if let n = json["name"]?.string, !n.isEmpty { return n }
        let full = [json["first_name"]?.string, json["last_name"]?.string].compactMap { $0 }.joined(separator: " ").trimmingCharacters(in: .whitespaces)
        return full.isEmpty ? (json.string) : full
    }
}

extension JSONValue {
    static func int(_ v: Int) -> JSONValue { .number(Double(v)) }
    static func int(_ v: Int?) -> JSONValue { v.map { .number(Double($0)) } ?? .null }
    static func str(_ v: String?) -> JSONValue { (v?.isEmpty == false) ? .string(v!) : .null }
    /// A decimal typed by a person (quantity, price): sent as a JSON number.
    static func decimal(_ text: String) -> JSONValue? {
        Double(text.replacingOccurrences(of: ",", with: "").trimmingCharacters(in: .whitespaces)).map { .number($0) }
    }
    /// A plain `{key: value}` dictionary of strings.
    static func stringMap(_ pairs: [(String, String)]) -> JSONValue {
        var o: [String: JSONValue] = [:]
        for (k, v) in pairs where !k.trimmingCharacters(in: .whitespaces).isEmpty { o[k.trimmingCharacters(in: .whitespaces)] = .string(v) }
        return .object(o)
    }
    /// Key/value rows of a `{k: v}` object, for specs and measurements.
    var stringPairs: [(String, String)] {
        (object ?? [:]).sorted { $0.key < $1.key }.map { ($0.key, $0.value.string ?? "") }
    }
}

/// A quantity exactly as the server sent it (ints or decimals), never re-rounded.
func quantityText(_ v: JSONValue?) -> String {
    guard let v else { return "—" }
    if let s = v.string { return s }
    return "—"
}

// MARK: - Prompts

/// A sheet that asks for one line of text (a reason, a note) before an action.
struct TextPromptSheet: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let placeholder: String
    let required: Bool
    let confirmLabel: String
    let destructive: Bool
    let action: (String) async -> Void
    @State private var text = ""
    @State private var busy = false

    init(_ title: String, placeholder: String = "Reason", required: Bool = true, confirmLabel: String = "Confirm", destructive: Bool = false, action: @escaping (String) async -> Void) {
        self.title = title
        self.placeholder = placeholder
        self.required = required
        self.confirmLabel = confirmLabel
        self.destructive = destructive
        self.action = action
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text(title).font(Theme.Font.sectionTitle)
            TextField(placeholder, text: $text, axis: .vertical).textFieldStyle(.roundedBorder).lineLimit(3...6)
            HStack {
                Spacer()
                Button("Cancel") { dismiss() }
                Button(confirmLabel, role: destructive ? .destructive : nil) {
                    busy = true
                    Task { await action(text.trimmingCharacters(in: .whitespacesAndNewlines)); busy = false; dismiss() }
                }
                .buttonStyle(.borderedProminent).tint(destructive ? Theme.Palette.danger : Theme.Palette.brand)
                .disabled(busy || (required && text.trimmingCharacters(in: .whitespaces).isEmpty))
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 380)
    }
}

/// Sheet chrome shared by the module forms: title, scrolling body, footer.
struct ModuleFormSheet<Content: View>: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let submitLabel: String
    let canSubmit: Bool
    let error: APIError?
    let submit: () async -> Void
    @ViewBuilder let content: Content
    @State private var busy = false

    init(_ title: String, submitLabel: String = "Save", canSubmit: Bool = true, error: APIError? = nil, submit: @escaping () async -> Void, @ViewBuilder content: () -> Content) {
        self.title = title
        self.submitLabel = submitLabel
        self.canSubmit = canSubmit
        self.error = error
        self.submit = submit
        self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text(title).font(Theme.Font.sectionTitle)
                Spacer()
                Button("Cancel") { dismiss() }
            }
            .padding(Theme.Space.l)
            Divider()
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    content
                    if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
                }
                .padding(Theme.Space.l)
            }
            Divider()
            HStack {
                Spacer()
                Button(submitLabel) { busy = true; Task { await submit(); busy = false } }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || !canSubmit)
            }
            .padding(Theme.Space.l)
        }
        .frame(minWidth: 420, idealWidth: 560, minHeight: 320)
        #if os(macOS)
        .frame(maxHeight: 720)
        #endif
    }
}

/// A labelled field with its 422 message underneath.
struct ModuleField<Content: View>: View {
    let label: String
    let error: String?
    @ViewBuilder let content: Content
    init(_ label: String, error: String? = nil, @ViewBuilder content: () -> Content) {
        self.label = label
        self.error = error
        self.content = content()
    }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
            content
            FieldError(error)
        }
    }
}

/// `yyyy-MM-dd` in Nairobi for API date fields.
func apiDay(_ date: Date) -> String { HubDates.dayString(date) }

// MARK: - Pickers

/// Loads a reference list once (outlets, users, products) for a `Picker`.
@MainActor
@Observable
final class Lookup {
    private(set) var options: [Record] = []
    private(set) var loaded = false
    private let fetch: @Sendable () async throws -> [Record]
    init(_ fetch: @escaping @Sendable () async throws -> [Record]) { self.fetch = fetch }
    func load() async {
        guard !loaded else { return }
        options = (try? await fetch()) ?? []
        loaded = true
    }
    func label(for id: Int?) -> String? {
        guard let id else { return nil }
        return options.first { $0.int("id") == id }.map(Lookup.title)
    }
    static func title(_ r: Record) -> String {
        r["en_translation"]?["name"]?.string ?? r.string("name") ?? Names.person(r.json) ?? r.string("sku") ?? "#\(r.id)"
    }

    static func outlets(_ client: APIClient) -> Lookup {
        Lookup { try (await client.get("/v1/admin/outlets").json?["data"]?.decode([Record].self)) ?? [] }
    }
    /// Everyone a production stage can be assigned to (UserController::assignable).
    static func assignable(_ client: APIClient) -> Lookup {
        Lookup { try (await client.get("/v1/admin/users/assignable").json?["data"]?.decode([Record].self)) ?? [] }
    }
    static func products(_ client: APIClient, producibleOnly: Bool = false) -> Lookup {
        Lookup { try (await client.get("/v1/admin/products", query: ["per_page": "500", "is_producible": producibleOnly ? "1" : nil]).json?["data"]?.decode([Record].self)) ?? [] }
    }
    static func suppliers(_ client: APIClient) -> Lookup {
        Lookup { try (await client.get("/v1/admin/suppliers", query: ["per_page": "200", "status": "active"]).json?["data"]?.decode([Record].self)) ?? [] }
    }
    static func materials(_ client: APIClient) -> Lookup {
        Lookup { try (await client.get("/v1/admin/inventory/materials", query: ["per_page": "200", "is_active": "1"]).json?["data"]?.decode([Record].self)) ?? [] }
    }
}

struct LookupPicker: View {
    let label: String
    let lookup: Lookup
    @Binding var selection: Int?
    var allowNone = true

    var body: some View {
        Picker(label, selection: $selection) {
            if allowNone { Text("—").tag(Int?.none) }
            ForEach(lookup.options) { o in
                Text(Lookup.title(o)).tag(o.int("id"))
            }
        }
        .task { await lookup.load() }
    }
}

// MARK: - Audit log

/// `GET <path>` → `{logs: [{label, description, actor_name, created_at, properties}]}`.
struct ModuleAuditLog: View {
    @Environment(AppEnvironment.self) private var env
    let path: String
    @State private var logs: Resource<[Record]>?

    var body: some View {
        SectionCard("Audit log") {
            if let logs {
                LoadableView(logs.state, retry: { logs.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No activity recorded", symbol: "doc.text.magnifyingglass") }) { rows in
                    ForEach(rows) { l in
                        VStack(alignment: .leading, spacing: 2) {
                            HStack {
                                Text(l.string("label") ?? l.string("event")?.humanized ?? "Event").font(Theme.Font.body.weight(.semibold))
                                Spacer()
                                DateText(l.date("created_at"), style: .dateTime)
                            }
                            if let d = l.string("description"), !d.isEmpty { Text(d).font(Theme.Font.secondary) }
                            Text(l.string("actor_name") ?? "System").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        .padding(.vertical, 4)
                        Divider()
                    }
                }
            }
        }
        .task {
            if logs == nil {
                let c = env.session.client, p = path
                logs = Resource { try (await c.get(p).json?["logs"]?.decode([Record].self)) ?? [] }
                logs?.load()
            }
        }
    }
}

/// Document revisions (`{current_revision, revisions: [{revision, reason, changes, by, created_at}]}`).
struct RevisionsSection: View {
    @Environment(AppEnvironment.self) private var env
    let path: String
    @State private var res: Resource<JSONValue>?

    var body: some View {
        SectionCard("Revisions") {
            if let res {
                LoadableView(res.state, retry: { res.load() }) { json in
                    let revs = json["revisions"]?.array ?? []
                    Text("Current: Rev \(json["current_revision"]?.int ?? 0)").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                    if revs.isEmpty { Text("Issued as first written.").font(Theme.Font.secondary).foregroundStyle(Theme.textTertiary) }
                    ForEach(Array(revs.enumerated()), id: \.offset) { _, r in
                        VStack(alignment: .leading, spacing: 2) {
                            HStack { Text("Rev \(r["revision"]?.int ?? 0)").font(Theme.Font.body.weight(.semibold)); Spacer(); DateText(r["created_at"]?.date) }
                            Text(r["reason"]?.string ?? "").font(Theme.Font.secondary)
                            ForEach(Array((r["changes"]?.array ?? []).enumerated()), id: \.offset) { _, c in
                                Text("• \(c.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            if let by = r["by"]?.string { Text(by).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
        }
        .task {
            if res == nil {
                let c = env.session.client, p = path
                res = Resource { try await c.get(p).json ?? .null }
                res?.load()
            }
        }
    }
}

// MARK: - Key/value editor

struct KeyValueRow: Identifiable, Hashable { let id = UUID(); var key: String; var value: String }

struct KeyValueEditor: View {
    let title: String
    @Binding var rows: [KeyValueRow]
    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text(title).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).textCase(.uppercase)
            ForEach($rows) { $row in
                HStack {
                    TextField("Field", text: $row.key).textFieldStyle(.roundedBorder)
                    TextField("Value", text: $row.value).textFieldStyle(.roundedBorder)
                    Button { rows.removeAll { $0.id == row.id } } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain).foregroundStyle(Theme.Palette.danger)
                }
            }
            Button { rows.append(KeyValueRow(key: "", value: "")) } label: { Label("Add row", systemImage: "plus") }.font(Theme.Font.secondary)
        }
    }
}

// MARK: - Photos

/// Picks a photo and hands back its bytes for a multipart upload.
struct PhotoAttachButton: View {
    let label: String
    let onPicked: (Data, String) async -> Void
    @State private var item: PhotosPickerItem?
    @State private var busy = false

    init(_ label: String = "Add photo", onPicked: @escaping (Data, String) async -> Void) {
        self.label = label
        self.onPicked = onPicked
    }

    var body: some View {
        PhotosPicker(selection: $item, matching: .images) {
            if busy { ProgressView().controlSize(.small) } else { Label(label, systemImage: "camera") }
        }
        .disabled(busy)
        .onChange(of: item) { _, new in
            guard let new else { return }
            busy = true
            Task {
                if let data = try? await new.loadTransferable(type: Data.self) { await onPicked(data, "photo.jpg") }
                item = nil
                busy = false
            }
        }
    }
}

extension APIClient {
    /// One-file multipart upload with optional text fields.
    func uploadFile(_ path: String, file: Data, field: String, filename: String, mimeType: String = "image/jpeg", fields: [String: String] = [:]) async throws -> Response {
        var parts = [MultipartPart(name: field, data: file, filename: filename, mimeType: mimeType)]
        for (k, v) in fields { parts.append(MultipartPart(name: k, value: v)) }
        return try await send(Request(.post, path, body: .multipart(parts)))
    }
}

// MARK: - Small views

struct StatsRow: View {
    let tiles: [(String, String, StatusTone?)]
    var body: some View {
        TileGrid(minWidth: 140) {
            ForEach(Array(tiles.enumerated()), id: \.offset) { _, t in StatTile(t.0, value: t.1, tone: t.2) }
        }
    }
}

struct PriorityBadge: View {
    let priority: String?
    var body: some View {
        let tone: StatusTone = switch priority ?? "" {
        case "urgent": .danger
        case "high": .warning
        case "low": .neutral
        default: .info
        }
        StatusBadge(priority ?? "normal", tone: tone)
    }
}

struct ModuleSearchField: View {
    @Binding var text: String
    let placeholder: String
    let onSubmit: () -> Void
    var body: some View {
        HStack {
            Image(systemName: "magnifyingglass").foregroundStyle(Theme.textTertiary)
            TextField(placeholder, text: $text).textFieldStyle(.plain).onSubmit(onSubmit)
            if !text.isEmpty { Button { text = ""; onSubmit() } label: { Image(systemName: "xmark.circle.fill") }.buttonStyle(.plain).foregroundStyle(Theme.textTertiary) }
        }
        .padding(.horizontal, 12).frame(height: 38)
        .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line, lineWidth: 1))
    }
}

/// Whether the viewer may see unit cost / COGS figures (products.view_cost).
extension SessionStore {
    var canViewCost: Bool { can("products.view_cost") }
}
