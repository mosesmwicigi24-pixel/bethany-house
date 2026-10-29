import SwiftUI
import BethanyKit
#if os(iOS)
import UIKit
#endif

// Shared building blocks for the People, Messages, Setup and Marketing
// screens (Step 4): JSON builders, a remote-JSON view, form sheets, async
// buttons, pickers and file handling. Nothing here computes business data.

// MARK: - JSON builders

/// Short builders for request bodies. A typed figure goes out verbatim as the
/// person typed it (a numeric string the server validates), never as a float.
enum J {
    static func s(_ v: String?) -> JSONValue {
        guard let v, !v.isEmpty else { return .null }
        return .string(v)
    }
    static func sOpt(_ v: String?) -> JSONValue? {
        guard let v, !v.isEmpty else { return nil }
        return .string(v)
    }
    static func i(_ v: Int?) -> JSONValue { v.map { .number(Double($0)) } ?? .null }
    static func d(_ v: Double?) -> JSONValue { v.map { .number($0) } ?? .null }
    static func b(_ v: Bool) -> JSONValue { .bool(v) }
    static func ints(_ v: [Int]) -> JSONValue { .array(v.map { .number(Double($0)) }) }
    static func strs(_ v: [String]) -> JSONValue { .array(v.map { .string($0) }) }
    /// A number the person typed: sent as the digits they typed.
    static func typed(_ v: String) -> JSONValue {
        let t = v.trimmingCharacters(in: .whitespaces)
        return t.isEmpty ? .null : .string(t)
    }
    static func obj(_ pairs: [(String, JSONValue?)]) -> JSONValue {
        var o: [String: JSONValue] = [:]
        for (k, v) in pairs { if let v { o[k] = v } }
        return .object(o)
    }
}

extension JSONValue {
    /// `data` when wrapped, otherwise the value itself as a list. (`records`
    /// itself is defined in MeFeature.swift and shared by every module.)
    var listRecords: [Record] { (self["data"]?.array ?? array ?? []).map(Record.init(json:)) }
    var message: String? { self["message"]?.string }
}

extension APIClient {
    func json(_ path: String, query: [String: String?] = [:], background: Bool = false) async throws -> JSONValue {
        try await get(path, query: query, background: background).json ?? .null
    }
    func upload(_ path: String, parts: [MultipartPart]) async throws -> Response {
        try await send(Request(.post, path, body: .multipart(parts)))
    }
}

// MARK: - Dates

enum Day {
    static let nairobi = HubDates.nairobi
    static var calendar: Calendar { var c = Calendar(identifier: .gregorian); c.timeZone = nairobi; c.firstWeekday = 2; return c }
    static func today() -> String { HubDates.dayString(Date()) }
    static func string(_ d: Date) -> String { HubDates.dayString(d) }
    static func date(_ s: String) -> Date? { HubDates.parse(s) }
    static func add(_ s: String, days: Int) -> String {
        guard let d = date(s), let n = calendar.date(byAdding: .day, value: days, to: d) else { return s }
        return string(n)
    }
    static func monday(of s: String) -> String {
        guard let d = date(s) else { return s }
        let wd = calendar.component(.weekday, from: d) // 1 = Sunday
        let back = (wd + 5) % 7
        return add(s, days: -back)
    }
    static func firstOfMonth(_ s: String) -> String { String(s.prefix(7)) + "-01" }
    static func lastOfMonth(_ s: String) -> String {
        guard let d = date(firstOfMonth(s)), let r = calendar.range(of: .day, in: .month, for: d) else { return s }
        return String(s.prefix(7)) + "-" + String(format: "%02d", r.count)
    }
    static func format(_ s: String?, _ format: String = "EEE d MMM") -> String {
        guard let s, let d = date(s) else { return s ?? "—" }
        let f = DateFormatter(); f.timeZone = nairobi; f.locale = Locale(identifier: "en_KE"); f.dateFormat = format
        return f.string(from: d)
    }
    static func time(_ s: String?) -> String { format(s, "HH:mm") }
    static func dateTime(_ s: String?) -> String { format(s, "d MMM yyyy, HH:mm") }
    static func year(_ s: String) -> Int { Int(s.prefix(4)) ?? calendar.component(.year, from: Date()) }
    static func thisYear() -> Int { calendar.component(.year, from: Date()) }
}

func minutesText(_ m: Int?) -> String {
    guard let m else { return "—" }
    let h = m / 60, r = m % 60
    return r == 0 ? "\(h)h" : "\(h)h \(String(format: "%02d", r))m"
}

func initials(_ name: String) -> String {
    name.split(separator: " ").prefix(2).compactMap { $0.first.map(String.init) }.joined().uppercased()
}

// MARK: - Remote JSON

/// Fetches a JSON document and re-fetches when `query` or `path` changes.
/// `content` receives the value and a reload closure for after mutations.
struct RemoteJSON<Content: View>: View {
    @Environment(AppEnvironment.self) private var env
    let path: String
    let query: [String: String?]
    /// Bump to force a re-fetch after a mutation made elsewhere (a sheet).
    let version: Int
    let isEmpty: (JSONValue) -> Bool
    let emptyTitle: String
    let content: (JSONValue, @escaping () -> Void) -> Content
    @State private var resource: Resource<JSONValue>?

    init(_ path: String, query: [String: String?] = [:], version: Int = 0, emptyTitle: String = "Nothing here yet", isEmpty: @escaping (JSONValue) -> Bool = { _ in false }, @ViewBuilder content: @escaping (JSONValue, @escaping () -> Void) -> Content) {
        self.path = path
        self.query = query
        self.version = version
        self.isEmpty = isEmpty
        self.emptyTitle = emptyTitle
        self.content = content
    }

    var body: some View {
        Group {
            if let resource {
                LoadableView(resource.state, retry: { resource.load() }, isEmpty: isEmpty, empty: { EmptyState(emptyTitle) }) { json in
                    content(json) { resource.load() }
                }
            } else {
                LoadingSkeleton()
            }
        }
        .task(id: TaskKey(path: path, query: query, version: version)) {
            let client = env.session.client
            let p = path, q = query
            let r = Resource { try await client.json(p, query: q) }
            resource = r
            await r.reload()
        }
        .refreshable { await resource?.reload() }
    }

    private struct TaskKey: Equatable { let path: String; let query: [String: String?]; let version: Int }
}

// MARK: - Buttons & sheets

/// A button that runs an async action, shows progress, and reports failures as toasts.
struct AsyncButton<Label: View>: View {
    @Environment(AppEnvironment.self) private var env
    let role: ButtonRole?
    let action: () async throws -> Void
    let label: Label
    @State private var busy = false

    init(role: ButtonRole? = nil, action: @escaping () async throws -> Void, @ViewBuilder label: () -> Label) {
        self.role = role
        self.action = action
        self.label = label()
    }

    var body: some View {
        Button(role: role) {
            busy = true
            Task { @MainActor in
                defer { busy = false }
                do { try await action() } catch { env.toasts.error(error) }
            }
        } label: {
            if busy { ProgressView().controlSize(.small) } else { label }
        }
        .disabled(busy)
    }
}

extension AsyncButton where Label == Text {
    init(_ title: String, role: ButtonRole? = nil, action: @escaping () async throws -> Void) {
        self.init(role: role, action: action) { Text(title) }
    }
}

/// Toasts the server's `message` (or a fallback) after a write.
@MainActor
func toastResult(_ env: AppEnvironment, _ response: APIClient.Response, fallback: String = "Saved") {
    env.toasts.show(response.json?.message ?? fallback)
}

/// A modal form with Cancel/Save, a busy state and the server's field errors.
struct EditSheet<Content: View>: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let title: String
    let saveLabel: String
    let canSave: Bool
    let destructive: Bool
    let save: () async throws -> Void
    let content: (APIError?) -> Content
    @State private var error: APIError?
    @State private var busy = false

    init(_ title: String, saveLabel: String = "Save", canSave: Bool = true, destructive: Bool = false, save: @escaping () async throws -> Void, @ViewBuilder content: @escaping (APIError?) -> Content) {
        self.title = title
        self.saveLabel = saveLabel
        self.canSave = canSave
        self.destructive = destructive
        self.save = save
        self.content = content
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text(title).font(Theme.Font.sectionTitle)
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) { content(error) }
                    .padding(.vertical, 2)
            }
            if let e = error, e.errors.isEmpty || e.firstFieldError == nil {
                Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
            }
            HStack {
                Spacer()
                Button("Cancel") { dismiss() }
                Button(saveLabel, role: destructive ? .destructive : nil) { Task { await submit() } }
                    .buttonStyle(.borderedProminent).tint(destructive ? Theme.Palette.danger : Theme.Palette.brand)
                    .disabled(busy || !canSave)
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 460, idealWidth: 520, minHeight: 200, maxHeight: 720)
        #endif
    }

    private func submit() async {
        busy = true
        defer { busy = false }
        do {
            try await save()
            dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}

struct Field: View {
    let label: String
    @Binding var text: String
    var placeholder = ""
    var error: String? = nil
    var multiline = false
    var mono = false

    init(_ label: String, _ text: Binding<String>, placeholder: String = "", error: String? = nil, multiline: Bool = false, mono: Bool = false) {
        self.label = label
        _text = text
        self.placeholder = placeholder
        self.error = error
        self.multiline = multiline
        self.mono = mono
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            if multiline {
                TextField(placeholder, text: $text, axis: .vertical).lineLimit(3...8).textFieldStyle(.roundedBorder).font(mono ? Theme.Font.mono : Theme.Font.body)
            } else {
                TextField(placeholder, text: $text).textFieldStyle(.roundedBorder).font(mono ? Theme.Font.mono : Theme.Font.body)
            }
            FieldError(error)
        }
    }
}

struct SecureFieldRow: View {
    let label: String
    @Binding var text: String
    var error: String? = nil
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            SecureField("", text: $text).textFieldStyle(.roundedBorder)
            FieldError(error)
        }
    }
}

struct OptionPicker: View {
    let label: String
    @Binding var selection: String
    let options: [(String, String)]   // (value, label)
    init(_ label: String, _ selection: Binding<String>, options: [(String, String)]) {
        self.label = label
        _selection = selection
        self.options = options
    }
    init(_ label: String, _ selection: Binding<String>, values: [String]) {
        self.init(label, selection, options: values.map { ($0, $0.humanized) })
    }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Picker("", selection: $selection) {
                ForEach(options, id: \.0) { Text($0.1).tag($0.0) }
            }
            .labelsHidden()
            .pickerStyle(.menu)
        }
    }
}

struct ToggleRow: View {
    let label: String
    @Binding var on: Bool
    init(_ label: String, _ on: Binding<Bool>) { self.label = label; _on = on }
    var body: some View {
        Toggle(label, isOn: $on).toggleStyle(.switch).font(Theme.Font.body)
    }
}

/// "yyyy-MM-dd" text bound to a DatePicker in Nairobi time.
struct DayField: View {
    let label: String
    @Binding var day: String
    init(_ label: String, _ day: Binding<String>) { self.label = label; _day = day }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            DatePicker("", selection: Binding(get: { Day.date(day) ?? Date() }, set: { day = Day.string($0) }), displayedComponents: .date)
                .labelsHidden()
                .environment(\.timeZone, Day.nairobi)
        }
    }
}

/// A row of segmented tabs, shared by the tabbed screens.
struct Tabs<T: Hashable>: View {
    @Binding var selection: T
    let items: [(T, String)]
    var body: some View {
        Picker("", selection: $selection) {
            ForEach(items, id: \.0) { Text($0.1).tag($0.0) }
        }
        .pickerStyle(.segmented)
        .labelsHidden()
    }
}

/// A plain list card row with a title, subtitle and trailing content.
struct SupportListRow<Trailing: View>: View {
    let title: String
    let subtitle: String?
    let trailing: Trailing
    init(_ title: String, subtitle: String? = nil, @ViewBuilder trailing: () -> Trailing) {
        self.title = title
        self.subtitle = subtitle
        self.trailing = trailing()
    }
    var body: some View {
        HStack(alignment: .center, spacing: Theme.Space.m) {
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(Theme.Font.body).lineLimit(2)
                if let subtitle, !subtitle.isEmpty { Text(subtitle).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2) }
            }
            Spacer(minLength: 4)
            trailing
        }
        .padding(Theme.Space.m)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.field, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field, style: .continuous).stroke(Theme.line, lineWidth: 1))
    }
}

extension SupportListRow where Trailing == EmptyView {
    init(_ title: String, subtitle: String? = nil) { self.init(title, subtitle: subtitle) { EmptyView() } }
}

/// The "wide" test used to pick a multi-column layout.
struct LayoutProbe {
    static var isWide: Bool {
        #if os(macOS)
        return true
        #else
        return UIDevice.current.userInterfaceIdiom == .pad
        #endif
    }
}

// MARK: - Staff picker

struct StaffOption: Identifiable, Hashable, Sendable {
    let id: Int
    let name: String
    let outlet: String?
}

/// Everyone a thing can be assigned to (`GET /users/assignable`, open to all staff).
@MainActor
func fetchStaffOptions(_ env: AppEnvironment) async throws -> [StaffOption] {
    let j = try await env.session.client.json("/v1/admin/users/assignable")
    return j.listRecords.compactMap { r in
        guard let id = r.int("id") else { return nil }
        let name = [r.string("first_name"), r.string("last_name")].compactMap { $0 }.joined(separator: " ")
        return StaffOption(id: id, name: name.isEmpty ? (r.string("name") ?? "#\(id)") : name, outlet: r["outlet"]?["name"]?.string)
    }
}

struct StaffPicker: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    @Binding var selection: Int?
    var allowNone = false
    @State private var staff: [StaffOption] = []

    init(_ label: String, _ selection: Binding<Int?>, allowNone: Bool = false) {
        self.label = label
        _selection = selection
        self.allowNone = allowNone
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Picker("", selection: $selection) {
                if allowNone || selection == nil { Text(allowNone ? "Nobody (open)" : "Choose…").tag(Int?.none) }
                ForEach(staff) { s in Text(s.outlet.map { "\(s.name) · \($0)" } ?? s.name).tag(Int?.some(s.id)) }
            }
            .labelsHidden().pickerStyle(.menu)
        }
        .task { if staff.isEmpty { staff = (try? await fetchStaffOptions(env)) ?? [] } }
    }
}

struct OutletOption: Identifiable, Hashable, Sendable { let id: Int; let name: String; let raw: JSONValue }

@MainActor
func fetchOutletOptions(_ env: AppEnvironment) async throws -> [OutletOption] {
    let j = try await env.session.client.json("/v1/admin/outlets")
    return j.listRecords.compactMap { r in
        guard let id = r.int("id") else { return nil }
        return OutletOption(id: id, name: r.string("name") ?? "Outlet \(id)", raw: r.json)
    }
}

struct OutletChoice: View {
    @Environment(AppEnvironment.self) private var env
    let label: String
    @Binding var selection: Int?
    var allowAll = false
    @State private var outlets: [OutletOption] = []
    init(_ label: String, _ selection: Binding<Int?>, allowAll: Bool = false) { self.label = label; _selection = selection; self.allowAll = allowAll }
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            Picker("", selection: $selection) {
                if allowAll || selection == nil { Text(allowAll ? "All outlets" : "Choose…").tag(Int?.none) }
                ForEach(outlets) { o in Text(o.name).tag(Int?.some(o.id)) }
            }
            .labelsHidden().pickerStyle(.menu)
        }
        .task {
            if outlets.isEmpty {
                outlets = (try? await fetchOutletOptions(env)) ?? []
                if selection == nil, !allowAll { selection = env.session.me?.outlet?.id ?? outlets.first?.id }
            }
        }
    }
}

// MARK: - Files

struct LocalFile: Sendable, Hashable {
    let name: String
    let data: Data
    let mimeType: String
    var part: APIClient.MultipartPart { APIClient.MultipartPart(name: "file", data: data, filename: name, mimeType: mimeType) }
    func part(named n: String) -> APIClient.MultipartPart { APIClient.MultipartPart(name: n, data: data, filename: name, mimeType: mimeType) }
}

enum FileAccess {
    static func read(_ url: URL) -> LocalFile? {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        guard let data = try? Data(contentsOf: url) else { return nil }
        return LocalFile(name: url.lastPathComponent, data: data, mimeType: mime(for: url.pathExtension))
    }
    static func mime(for ext: String) -> String {
        switch ext.lowercased() {
        case "jpg", "jpeg": return "image/jpeg"
        case "png": return "image/png"
        case "webp": return "image/webp"
        case "gif": return "image/gif"
        case "pdf": return "application/pdf"
        case "csv": return "text/csv"
        case "txt": return "text/plain"
        case "mp4", "m4v": return "video/mp4"
        case "mov": return "video/quicktime"
        case "m4a": return "audio/mp4"
        case "mp3": return "audio/mpeg"
        case "docx": return "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        case "xlsx": return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        default: return "application/octet-stream"
        }
    }
}

/// A button that picks one file from the system picker.
struct AttachFileButton: View {
    let label: String
    let onPick: (LocalFile) -> Void
    @State private var presented = false
    var body: some View {
        Button { presented = true } label: { Label(label, systemImage: "paperclip") }
            .fileImporter(isPresented: $presented, allowedContentTypes: [.item]) { result in
                if case .success(let url) = result, let f = FileAccess.read(url) { onPick(f) }
            }
    }
}

/// Saves bytes to a temp file and opens/shares them.
@MainActor
func presentFile(_ env: AppEnvironment, data: Data, name: String, share: Binding<URL?>) {
    let url = FileManager.default.temporaryDirectory.appendingPathComponent(name)
    do {
        try data.write(to: url)
        #if os(macOS)
        NSWorkspace.shared.open(url)
        #else
        share.wrappedValue = url
        #endif
    } catch { env.toasts.error(error) }
}

/// An image behind a signed-link issuer (`{url}`) or a plain URL.
/// Issuer paths are the `/api/...` endpoints that answer `{url, expires_at}`;
/// the file itself is then fetched with no credentials.
struct SignedImage: View {
    @Environment(AppEnvironment.self) private var env
    let source: String
    var maxHeight: CGFloat = 240
    @State private var image: Image?
    @State private var failed = false

    var body: some View {
        Group {
            if let image {
                image.resizable().scaledToFit().frame(maxHeight: maxHeight).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
            } else if failed {
                Label("Image unavailable", systemImage: "photo").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            } else {
                ProgressView().controlSize(.small).frame(height: 60)
            }
        }
        .task(id: source) { await load() }
    }

    private func load() async {
        do {
            let data = try await SignedFiles.fetch(env, source)
            #if os(macOS)
            if let n = NSImage(data: data) { image = Image(nsImage: n) } else { failed = true }
            #else
            if let u = UIImage(data: data) { image = Image(uiImage: u) } else { failed = true }
            #endif
        } catch { failed = true }
    }
}

enum SignedFiles {
    /// Resolves an issuer URL/path through the API (bearer) to a signed link,
    /// then downloads the bytes without credentials. A plain non-Hub URL is
    /// fetched directly.
    @MainActor
    static func fetch(_ env: AppEnvironment, _ source: String) async throws -> Data {
        let hubHost = env.config.hubURL.host ?? ""
        var apiPath: String?
        if source.hasPrefix("/api/") { apiPath = String(source.dropFirst(4)) }
        else if let u = URL(string: source), u.host == hubHost || u.host == "localhost", u.path.hasPrefix("/api/") {
            apiPath = String(u.path.dropFirst(4)) + (u.query.map { "?\($0)" } ?? "")
        }
        if let apiPath {
            let (path, query) = split(apiPath)
            let r = try await env.session.client.get(path, query: query)
            if let link = r.json?["url"]?.string, let url = URL(string: link.replacingOccurrences(of: "http://", with: "https://")) {
                let (data, _) = try await URLSession.shared.data(from: url)
                return data
            }
            return r.data
        }
        guard let url = URL(string: source) else { throw APIError(status: 0, message: "Bad file link") }
        let (data, _) = try await URLSession.shared.data(from: url)
        return data
    }

    static func split(_ pathAndQuery: String) -> (String, [String: String?]) {
        guard let q = pathAndQuery.firstIndex(of: "?") else { return (pathAndQuery, [:]) }
        let path = String(pathAndQuery[..<q])
        var query: [String: String?] = [:]
        for pair in pathAndQuery[pathAndQuery.index(after: q)...].split(separator: "&") {
            let kv = pair.split(separator: "=", maxSplits: 1).map(String.init)
            query[kv[0].removingPercentEncoding ?? kv[0]] = kv.count > 1 ? (kv[1].removingPercentEncoding ?? kv[1]) : ""
        }
        return (path, query)
    }
}

/// A sheet-ready share wrapper for iOS; opens with NSWorkspace on macOS.
struct ShareFileModifier: ViewModifier {
    @Binding var url: URL?
    func body(content: Content) -> some View {
        #if os(iOS)
        content.sheet(item: $url) { u in ShareSheet(items: [u]) }
        #else
        content
        #endif
    }
}

extension View {
    func shareFile(_ url: Binding<URL?>) -> some View { modifier(ShareFileModifier(url: url)) }
}

// MARK: - Small pieces

/// An integer id as a sheet item.
struct IDRef: Identifiable, Hashable { let id: Int; init(_ id: Int) { self.id = id } }

struct KeyValueGrid: View {
    let pairs: [(String, String)]
    var body: some View {
        VStack(spacing: 0) {
            ForEach(Array(pairs.enumerated()), id: \.offset) { _, p in DetailRow(p.0, p.1) }
        }
    }
}

struct Pill: View {
    let text: String
    let tone: StatusTone
    init(_ text: String, _ tone: StatusTone = .neutral) { self.text = text; self.tone = tone }
    var body: some View { StatusBadge(text, tone: tone) }
}

struct Avatar: View {
    let name: String
    var size: CGFloat = 32
    var body: some View {
        Text(initials(name))
            .font(.system(size: size * 0.38, weight: .semibold))
            .foregroundStyle(.white)
            .frame(width: size, height: size)
            .background(Theme.Palette.brandDeep, in: Circle())
    }
}

/// Pretty-prints a JSON value for read-only inspection (configs, properties).
func prettyJSON(_ v: JSONValue?) -> String {
    guard let v else { return "" }
    let e = JSONEncoder()
    e.outputFormatting = [.prettyPrinted, .sortedKeys]
    guard let d = try? e.encode(v), let s = String(data: d, encoding: .utf8) else { return "" }
    return s
}

func parseJSONObject(_ text: String) -> JSONValue? {
    guard let d = text.data(using: .utf8), let v = try? HubJSON.decoder.decode(JSONValue.self, from: d), v.object != nil else { return nil }
    return v
}
