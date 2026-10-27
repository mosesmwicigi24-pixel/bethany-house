import SwiftUI
import BethanyKit

/// Messages (Phase 3 channels): spaces, DMs and order/production threads.
/// macOS and iPad show three columns (channels | thread | details); iPhone
/// stacks them through the navigator (`.comms(channelId:)`).
@MainActor
public enum CommsFeature {
    public static func register() {
        FeatureRegistry.register({ if case .comms = $0 { return true } else { return false } }) { route in
            if case .comms(let id) = route { return AnyView(CommsView(initialChannelId: id)) }
            return AnyView(CommsView(initialChannelId: nil))
        }
    }
}

// MARK: - Models

struct ChatChannel: Identifiable, Hashable, Sendable {
    let json: JSONValue
    var id: Int { json["id"]?.int ?? 0 }
    var type: String { json["type"]?.string ?? "space" }
    var name: String { json["name"]?.string ?? "Conversation" }
    var description: String? { json["description"]?.string }
    var isPrivate: Bool { json["is_private"]?.bool ?? false }
    var contextType: String? { json["context_type"]?.string }
    var contextId: Int? { json["context_id"]?.int }
    var unread: Int { json["unread_count"]?.int ?? 0 }
    var lastActivity: Date? { json["last_activity_at"]?.date }
    var lastMessage: JSONValue? { json["last_message"].flatMap { $0.isNull ? nil : $0 } }
    var members: [JSONValue] { json["members"]?.array ?? [] }
    var memberCount: Int { json["members"]?.int ?? members.count }
    var isDM: Bool { type == "dm" }
    var isContext: Bool { contextType != nil }
    var symbol: String { isDM ? "person" : (isContext ? (contextType == "order" ? "bag" : "hammer") : (isPrivate ? "lock" : "number")) }
    var contextRoute: Route? {
        guard let cid = contextId else { return nil }
        switch contextType { case "order": return .order(id: cid); case "production_order": return .productionOrder(id: cid); default: return nil }
    }
    func with(_ edits: [String: JSONValue]) -> ChatChannel {
        var o = json.object ?? [:]
        for (k, v) in edits { o[k] = v }
        return ChatChannel(json: .object(o))
    }
}

struct ChatMessage: Identifiable, Hashable, Sendable {
    let json: JSONValue
    var id: Int { json["id"]?.int ?? 0 }
    var channelId: Int { json["channel_id"]?.int ?? 0 }
    var body: String { json["body"]?.string ?? "" }
    var type: String { json["type"]?.string ?? "text" }
    var userId: Int? { json["user"]?["id"]?.int }
    var userName: String { json["user"]?["name"]?.string ?? "System" }
    var createdAt: Date? { json["created_at"]?.date }
    var editedAt: Date? { json["edited_at"]?.date }
    var replyTo: JSONValue? { json["reply_to"].flatMap { $0.isNull ? nil : $0 } }
    var reactions: [String: [Int]] {
        var r: [String: [Int]] = [:]
        for (k, v) in json["reactions"]?.object ?? [:] { r[k] = v.array?.compactMap(\.int) ?? [] }
        return r
    }
    var linkedEntities: [JSONValue] { json["linked_entities"]?.array ?? [] }
    var entityPreviews: [String: JSONValue] { json["entity_previews"]?.object ?? [:] }
    func with(_ edits: [String: JSONValue]) -> ChatMessage {
        var o = json.object ?? [:]
        for (k, v) in edits { o[k] = v }
        return ChatMessage(json: .object(o))
    }

    /// The console's `parseBody`: images `![n](u)`, files `[📎 n](u)` / `[n](http…)`,
    /// entity tokens `#[label](entity:type:id)` lifted out of the text.
    struct Parsed { var text: String; var images: [(String, String)]; var files: [(String, String)] }
    var parsed: Parsed {
        var text = body
        var images: [(String, String)] = [], files: [(String, String)] = []
        func lift(_ pattern: String, into: (String, String) -> Void) {
            guard let re = try? NSRegularExpression(pattern: pattern) else { return }
            let ns = text as NSString
            var out = text
            for m in re.matches(in: text, range: NSRange(location: 0, length: ns.length)).reversed() {
                let name = ns.substring(with: m.range(at: 1)), url = ns.substring(with: m.range(at: 2))
                into(name, url)
                out = (out as NSString).replacingCharacters(in: m.range, with: "")
            }
            text = out
        }
        lift(#"!\[([^\]]*)\]\(([^)]+)\)"#) { images.append(($0, $1)) }
        lift(#"\[📎\s*([^\]]+)\]\(([^)]+)\)"#) { files.append(($0, $1)) }
        lift(#"\[([^\]]+)\]\((https?://[^)]+)\)"#) { files.append(($0, $1)) }
        if let re = try? NSRegularExpression(pattern: #"#\[([^\]]+)\]\(entity:[^)]+\)"#) {
            text = re.stringByReplacingMatches(in: text, range: NSRange(location: 0, length: (text as NSString).length), withTemplate: "")
        }
        return Parsed(text: text.trimmingCharacters(in: .whitespacesAndNewlines), images: images.reversed(), files: files.reversed())
    }
}

// MARK: - Store (GET /channels; realtime unread + last message)

@MainActor
@Observable
final class CommsStore {
    private(set) var channels: [ChatChannel] = []
    private(set) var state: Loadable<[ChatChannel]> = .idle
    var selectedId: Int?
    var showDetails = false
    private let env: AppEnvironment
    private var subscriptions: [Int: UUID] = [:]

    init(env: AppEnvironment) { self.env = env }

    var selected: ChatChannel? { channels.first { $0.id == selectedId } }
    var spaces: [ChatChannel] { channels.filter { !$0.isDM && !$0.isContext } }
    var dms: [ChatChannel] { channels.filter(\.isDM) }
    var threads: [ChatChannel] { channels.filter(\.isContext) }

    func load() async {
        state = .loading(previous: channels.isEmpty ? nil : channels)
        do {
            let j = try await env.session.client.json("/v1/admin/channels")
            channels = (j["channels"]?.array ?? []).map(ChatChannel.init(json:)).sorted { ($0.lastActivity ?? .distantPast) > ($1.lastActivity ?? .distantPast) }
            state = .loaded(channels)
            await watchAll()
        } catch let e as APIError { state = .failed(e, previous: channels.isEmpty ? nil : channels) }
        catch { state = .failed(APIError.transport(error.localizedDescription), previous: channels.isEmpty ? nil : channels) }
    }

    /// One subscription per listed channel keeps unread counts and the
    /// sidebar's last message live (the console re-fetches the list instead).
    private func watchAll() async {
        guard let rt = env.realtime else { return }
        for ch in channels where subscriptions[ch.id] == nil {
            let id = ch.id
            let token = await rt.subscribe("channel.\(id)") { [weak self] event in
                guard event.name == ".message.sent" || event.name == "message.sent", let payload = event.payload else { return }
                Task { @MainActor in self?.messageArrived(channelId: id, payload) }
            }
            subscriptions[id] = token
        }
    }

    private func messageArrived(channelId: Int, _ payload: JSONValue) {
        guard let i = channels.firstIndex(where: { $0.id == channelId }) else { return }
        let m = ChatMessage(json: payload)
        let mine = m.userId == env.session.me?.id
        let unread = (selectedId == channelId || mine) ? 0 : channels[i].unread + 1
        channels[i] = channels[i].with([
            "unread_count": .number(Double(unread)),
            "last_activity_at": .string(m.json["created_at"]?.string ?? HubDates.iso8601.string(from: Date())),
            "last_message": .object(["id": .number(Double(m.id)), "body": .string(m.body), "user_name": .string(m.userName), "created_at": .string(m.json["created_at"]?.string ?? "")]),
        ])
        channels.sort { ($0.lastActivity ?? .distantPast) > ($1.lastActivity ?? .distantPast) }
    }

    func markRead(_ id: Int) {
        guard let i = channels.firstIndex(where: { $0.id == id }) else { return }
        channels[i] = channels[i].with(["unread_count": .number(0)])
    }

    func upsert(_ ch: ChatChannel) {
        if let i = channels.firstIndex(where: { $0.id == ch.id }) { channels[i] = ch } else { channels.insert(ch, at: 0) }
        Task { await watchAll() }
    }

    func remove(_ id: Int) {
        channels.removeAll { $0.id == id }
        if selectedId == id { selectedId = nil }
    }

    func stop() async {
        guard let rt = env.realtime else { return }
        for (id, token) in subscriptions { await rt.unsubscribe("channel.\(id)", token: token) }
        subscriptions = [:]
    }
}

// MARK: - Root

struct CommsView: View {
    @Environment(AppEnvironment.self) private var env
    let initialChannelId: Int?
    @State private var store: CommsStore?
    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var hSize
    #endif

    private var isWide: Bool {
        #if os(macOS)
        return true
        #else
        return hSize == .regular
        #endif
    }

    var body: some View {
        Group {
            if let store {
                if isWide {
                    HStack(spacing: 0) {
                        ChannelListView(store: store).frame(width: 300)
                        Divider()
                        if let ch = store.selected {
                            ThreadView(store: store, channel: ch).id(ch.id)
                            if store.showDetails {
                                Divider()
                                ChannelDetailsPanel(store: store, channel: ch).frame(width: 300)
                            }
                        } else {
                            EmptyState("Pick a conversation", message: "Spaces, direct messages and order threads are on the left.", symbol: "bubble.left.and.bubble.right")
                        }
                    }
                } else if let ch = store.selected, initialChannelId != nil {
                    ThreadView(store: store, channel: ch).id(ch.id)
                } else {
                    ChannelListView(store: store)
                }
            } else {
                LoadingSkeleton()
            }
        }
        .background(Theme.canvas)
        .navigationTitle(store?.selected.map { isWide ? "Messages" : $0.name } ?? "Messages")
        .task {
            if store == nil {
                let s = CommsStore(env: env)
                s.selectedId = initialChannelId
                store = s
                await s.load()
                if s.selected == nil, let id = initialChannelId, let j = try? await env.session.client.json("/v1/admin/channels/\(id)")["channel"] {
                    s.upsert(ChatChannel(json: j))
                }
            }
        }
        .onDisappear { Task { await store?.stop() } }
        .onChange(of: initialChannelId) { _, new in store?.selectedId = new }
    }
}

// MARK: - Channel list

struct ChannelListView: View {
    @Environment(AppEnvironment.self) private var env
    @Bindable var store: CommsStore
    @State private var query = ""
    @State private var hits: [JSONValue] = []
    @State private var searching = false
    @State private var newSpace = false
    @State private var newDM = false
    @State private var quickReplies = false

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                TextField("Search messages", text: $query).textFieldStyle(.roundedBorder).onSubmit { Task { await search() } }
                Menu {
                    Button { newSpace = true } label: { Label("New space", systemImage: "number") }
                    Button { newDM = true } label: { Label("New direct message", systemImage: "person") }
                    Button { quickReplies = true } label: { Label("Quick replies", systemImage: "text.badge.star") }
                } label: { Image(systemName: "square.and.pencil") }
                .menuStyle(.borderlessButton).fixedSize()
            }
            .padding(Theme.Space.m)
            if !query.isEmpty {
                searchResults
            } else {
                LoadableView(store.state, retry: { Task { await store.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No conversations yet", message: "Start a space or message a colleague.", symbol: "bubble.left") }) { _ in
                    List(selection: $store.selectedId) {
                        section("Spaces", store.spaces)
                        section("Direct messages", store.dms)
                        section("Order & production threads", store.threads)
                    }
                    .listStyle(.sidebar)
                }
            }
        }
        .sheet(isPresented: $newSpace) { NewSpaceSheet(store: store) }
        .sheet(isPresented: $newDM) { NewDMSheet(store: store) }
        .sheet(isPresented: $quickReplies) { QuickRepliesSheet() }
        .refreshable { await store.load() }
    }

    @ViewBuilder
    private func section(_ title: String, _ list: [ChatChannel]) -> some View {
        if !list.isEmpty {
            Section(title) {
                ForEach(list) { ch in
                    Button { open(ch.id) } label: { ChannelRow(channel: ch) }
                        .buttonStyle(.plain)
                        .tag(ch.id)
                }
            }
        }
    }

    private var searchResults: some View {
        List {
            if searching { ProgressView() }
            if hits.isEmpty, !searching { Text("Press return to search").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            ForEach(Array(hits.enumerated()), id: \.offset) { _, h in
                Button { if let id = h["channel_id"]?.int { query = ""; open(id) } } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(store.channels.first { $0.id == h["channel_id"]?.int }?.name ?? "Conversation").font(Theme.Font.secondary.weight(.semibold))
                        Text(h["snippet"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2)
                        DateText(h["created_at"]?.date, style: .relative)
                    }
                }.buttonStyle(.plain)
            }
        }
        .listStyle(.plain)
    }

    private func search() async {
        guard query.count >= 2 else { return }
        searching = true
        defer { searching = false }
        hits = (try? await env.session.client.json("/v1/admin/channels/search", query: ["q": query]))?["hits"]?.array ?? []
    }

    private func open(_ id: Int) {
        if LayoutProbe.isWide { store.selectedId = id } else { env.navigator.push(.comms(channelId: id)) }
    }
}

struct ChannelRow: View {
    let channel: ChatChannel
    var body: some View {
        HStack(spacing: Theme.Space.s) {
            if channel.isDM { Avatar(name: channel.name, size: 30) } else {
                Image(systemName: channel.symbol).frame(width: 30, height: 30).background(Theme.Palette.neutralSoft, in: RoundedRectangle(cornerRadius: 8)).foregroundStyle(Theme.Palette.neutral)
            }
            VStack(alignment: .leading, spacing: 2) {
                Text(channel.name).font(channel.unread > 0 ? Theme.Font.body.weight(.semibold) : Theme.Font.body).lineLimit(1)
                if let lm = channel.lastMessage {
                    Text("\(lm["user_name"]?.string.map { "\($0): " } ?? "")\(ChatMessage(json: ["body": .string(lm["body"]?.string ?? "")]).parsed.text)").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1)
                }
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 2) {
                DateText(channel.lastActivity, style: .relative)
                if channel.unread > 0 { Text("\(channel.unread)").font(Theme.Font.caption).foregroundStyle(.white).padding(.horizontal, 6).padding(.vertical, 2).background(Theme.Palette.brand, in: Capsule()) }
            }
        }
        .padding(.vertical, 2)
        .contentShape(Rectangle())
    }
}
