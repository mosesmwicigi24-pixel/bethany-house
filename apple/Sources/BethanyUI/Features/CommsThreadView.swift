import SwiftUI
import BethanyKit

/// One conversation: messages (paged with `before`), realtime, the composer
/// with attachments, quick replies and entity tags, and per-message actions.
@MainActor
@Observable
final class ThreadStore {
    let channelId: Int
    private(set) var messages: [ChatMessage] = []
    private(set) var reads: [Int: Int] = [:]      // user_id → last_read_message_id
    private(set) var readerNames: [Int: String] = [:]
    private(set) var typing: [String] = []
    private(set) var onlineIds: Set<Int> = []
    private(set) var hasMore = false
    private(set) var state: Loadable<[ChatMessage]> = .idle
    var replyTo: ChatMessage?
    var editing: ChatMessage?
    private let env: AppEnvironment
    private var tokens: [(String, UUID)] = []
    private var typingTimers: [String: Task<Void, Never>] = [:]
    private var lastWhisper = Date.distantPast
    private var poller: Task<Void, Never>?

    init(env: AppEnvironment, channelId: Int) {
        self.env = env
        self.channelId = channelId
    }

    var me: Int? { env.session.me?.id }

    func load() async {
        state = .loading(previous: messages.isEmpty ? nil : messages)
        do {
            let j = try await env.session.client.json("/v1/admin/channels/\(channelId)/messages", query: ["per_page": "50"])
            messages = (j["messages"]?.array ?? []).map(ChatMessage.init(json:))
            hasMore = j["has_more"]?.bool ?? false
            applyReads(j["reads"])
            state = .loaded(messages)
            await markRead()
            await subscribe()
        } catch let e as APIError { state = .failed(e, previous: messages.isEmpty ? nil : messages) }
        catch { state = .failed(APIError.transport(error.localizedDescription), previous: nil) }
    }

    func loadOlder() async {
        guard hasMore, let oldest = messages.first?.id else { return }
        guard let j = try? await env.session.client.json("/v1/admin/channels/\(channelId)/messages", query: ["per_page": "50", "before": String(oldest)]) else { return }
        let older = (j["messages"]?.array ?? []).map(ChatMessage.init(json:))
        messages = older + messages
        hasMore = j["has_more"]?.bool ?? false
        state = .loaded(messages)
    }

    private func applyReads(_ v: JSONValue?) {
        for r in v?.array ?? [] {
            guard let uid = r["user_id"]?.int else { continue }
            reads[uid] = r["last_read_message_id"]?.int ?? 0
            readerNames[uid] = r["name"]?.string ?? ""
        }
    }

    /// Read by everyone else in the conversation (the sender's tick).
    func readByAll(_ m: ChatMessage) -> Bool {
        let others = reads.filter { $0.key != me }
        return !others.isEmpty && others.allSatisfy { $0.value >= m.id }
    }
    func readers(_ m: ChatMessage) -> [String] {
        reads.filter { $0.key != me && $0.value >= m.id }.compactMap { readerNames[$0.key] }.filter { !$0.isEmpty }
    }

    // MARK: Realtime (channel.{id}: .message.sent/.reaction.updated/.read.updated; presence: client-typing)

    private func subscribe() async {
        guard tokens.isEmpty else { return }
        guard let rt = env.realtime else {
            poller = Task { [weak self] in
                while !Task.isCancelled {
                    try? await Task.sleep(for: .seconds(20))
                    await self?.pollNew()
                }
            }
            return
        }
        let id = channelId
        let t1 = await rt.subscribe("channel.\(id)") { [weak self] event in
            Task { @MainActor in self?.handle(event) }
        }
        let t2 = await rt.subscribe("presence.channel.\(id)", presence: true) { [weak self] event in
            Task { @MainActor in self?.handlePresence(event) }
        }
        tokens = [("channel.\(id)", t1), ("presence.channel.\(id)", t2)]
    }

    private func handle(_ event: RealtimeClient.Event) {
        let name = event.name.hasPrefix(".") ? String(event.name.dropFirst()) : event.name
        guard let p = event.payload else { return }
        switch name {
        case "message.sent":
            let m = ChatMessage(json: p)
            guard !messages.contains(where: { $0.id == m.id }) else { return }
            messages.append(m)
            state = .loaded(messages)
            if m.userId != me { Task { await markRead() } }
        case "reaction.updated":
            guard let mid = p["message_id"]?.int, let i = messages.firstIndex(where: { $0.id == mid }), let r = p["reactions"] else { return }
            messages[i] = messages[i].with(["reactions": r])
            state = .loaded(messages)
        case "read.updated":
            guard let uid = p["user_id"]?.int, let last = p["last_read_message_id"]?.int else { return }
            reads[uid] = max(reads[uid] ?? 0, last)
        default: break
        }
    }

    private func handlePresence(_ event: RealtimeClient.Event) {
        switch event.name {
        case "pusher_internal:subscription_succeeded":
            onlineIds = Set(event.payload?["presence"]?["ids"]?.array?.compactMap(\.int) ?? [])
        case "pusher_internal:member_added":
            if let id = event.payload?["user_id"]?.int { onlineIds.insert(id) }
        case "pusher_internal:member_removed":
            if let id = event.payload?["user_id"]?.int { onlineIds.remove(id) }
        case "client-typing":
            guard let name = event.payload?["name"]?.string, event.payload?["id"]?.int != me else { return }
            if !typing.contains(name) { typing.append(name) }
            typingTimers[name]?.cancel()
            typingTimers[name] = Task { [weak self] in
                try? await Task.sleep(for: .seconds(2.5))
                guard !Task.isCancelled else { return }
                self?.typing.removeAll { $0 == name }
            }
        default: break
        }
    }

    private func pollNew() async {
        guard let j = try? await env.session.client.json("/v1/admin/channels/\(channelId)/messages", query: ["per_page": "50"], background: true) else { return }
        let fresh = (j["messages"]?.array ?? []).map(ChatMessage.init(json:))
        var changed = false
        for m in fresh where !messages.contains(where: { $0.id == m.id }) { messages.append(m); changed = true }
        applyReads(j["reads"])
        if changed { state = .loaded(messages) }
    }

    func whisperTyping() async {
        guard Date().timeIntervalSince(lastWhisper) > 2, let rt = env.realtime, let m = env.session.me else { return }
        lastWhisper = Date()
        await rt.whisper("presence.channel.\(channelId)", event: "typing", data: ["id": .number(Double(m.id)), "name": .string(m.name.split(separator: " ").first.map(String.init) ?? m.name)])
    }

    func stop() async {
        poller?.cancel()
        guard let rt = env.realtime else { return }
        for (ch, t) in tokens { await rt.unsubscribe(ch, token: t) }
        tokens = []
    }

    // MARK: Actions

    func markRead() async {
        _ = try? await env.session.client.post("/v1/admin/channels/\(channelId)/read")
    }

    func send(body: String) async throws {
        let r = try await env.session.client.post("/v1/admin/channels/\(channelId)/messages", json: J.obj([("body", .string(body)), ("reply_to_id", J.i(replyTo?.id))]))
        if let m = r.json?["message"] { let msg = ChatMessage(json: m); if !messages.contains(where: { $0.id == msg.id }) { messages.append(msg); state = .loaded(messages) } }
        replyTo = nil
    }

    func edit(_ m: ChatMessage, body: String) async throws {
        let r = try await env.session.client.patch("/v1/admin/channels/\(channelId)/messages/\(m.id)", json: J.obj([("body", .string(body))]))
        if let j = r.json?["message"], let i = messages.firstIndex(where: { $0.id == m.id }) { messages[i] = ChatMessage(json: j); state = .loaded(messages) }
        editing = nil
    }

    func delete(_ m: ChatMessage) async throws {
        _ = try await env.session.client.delete("/v1/admin/channels/\(channelId)/messages/\(m.id)")
        messages.removeAll { $0.id == m.id }
        state = .loaded(messages)
    }

    func react(_ m: ChatMessage, emoji: String) async throws {
        let r = try await env.session.client.post("/v1/admin/channels/\(channelId)/messages/\(m.id)/react", json: J.obj([("emoji", .string(emoji))]))
        if let rx = r.json?["reactions"], let i = messages.firstIndex(where: { $0.id == m.id }) { messages[i] = messages[i].with(["reactions": rx]); state = .loaded(messages) }
    }

    func forward(_ m: ChatMessage, to channel: Int) async throws -> String {
        let r = try await env.session.client.post("/v1/admin/channels/\(channelId)/messages/\(m.id)/forward", json: J.obj([("to_channel_id", J.i(channel))]))
        return r.json?["message"]?["body"]?.string == nil ? "Forwarded" : "Forwarded"
    }

    func uploadAttachment(_ file: LocalFile) async throws -> JSONValue {
        try await env.session.client.upload("/v1/admin/channels/attachments", parts: [file.part]).json ?? .null
    }
}

// MARK: - Thread

struct ThreadView: View {
    @Environment(AppEnvironment.self) private var env
    @Bindable var store: CommsStore
    let channel: ChatChannel
    @State private var thread: ThreadStore?
    @State private var forwarding: ChatMessage?

    var body: some View {
        VStack(spacing: 0) {
            header
            Divider()
            if let thread {
                MessagesList(thread: thread, forwarding: $forwarding)
                if !thread.typing.isEmpty {
                    Text("\(thread.typing.joined(separator: ", ")) \(thread.typing.count == 1 ? "is" : "are") typing…").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).padding(.horizontal, Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
                }
                Divider()
                Composer(thread: thread)
            } else {
                LoadingSkeleton()
            }
        }
        .task(id: channel.id) {
            let t = ThreadStore(env: env, channelId: channel.id)
            thread = t
            await t.load()
            store.markRead(channel.id)
        }
        .onDisappear { Task { await thread?.stop() } }
        .sheet(item: $forwarding) { m in ForwardSheet(store: store, thread: thread, message: m) }
    }

    private var header: some View {
        HStack(spacing: Theme.Space.s) {
            Image(systemName: channel.symbol).foregroundStyle(Theme.Palette.brandDeep)
            VStack(alignment: .leading, spacing: 1) {
                Text(channel.name).font(Theme.Font.sectionTitle).lineLimit(1)
                if let d = channel.description, !d.isEmpty { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(1) }
                else if !channel.isDM { Text("\(channel.memberCount) members").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
            }
            Spacer()
            if let r = channel.contextRoute { Button { env.navigator.go(r) } label: { Label("Open record", systemImage: "arrow.up.right.square") } }
            Button { store.showDetails.toggle() } label: { Image(systemName: "info.circle") }
        }
        .padding(Theme.Space.m)
    }
}

struct MessagesList: View {
    let thread: ThreadStore
    @Binding var forwarding: ChatMessage?

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: Theme.Space.s) {
                    if thread.hasMore { Button("Load earlier messages") { Task { await thread.loadOlder() } }.font(Theme.Font.caption).frame(maxWidth: .infinity) }
                    if case .failed(let e, nil) = thread.state { ErrorState(e, retry: { Task { await thread.load() } }) }
                    ForEach(thread.messages) { m in
                        MessageRow(thread: thread, message: m, forward: { forwarding = m }).id(m.id)
                    }
                }
                .padding(Theme.Space.m)
            }
            .onChange(of: thread.messages.last?.id) { _, id in if let id { withAnimation { proxy.scrollTo(id, anchor: .bottom) } } }
            .onAppear { if let id = thread.messages.last?.id { proxy.scrollTo(id, anchor: .bottom) } }
        }
    }
}

struct MessageRow: View {
    @Environment(AppEnvironment.self) private var env
    let thread: ThreadStore
    let message: ChatMessage
    let forward: () -> Void
    @State private var share: URL?
    static let quickEmoji = ["👍", "❤️", "🙏", "😂", "🎉", "✅"]

    var body: some View {
        let mine = message.userId == thread.me
        let p = message.parsed
        HStack(alignment: .top, spacing: Theme.Space.s) {
            if !mine { Avatar(name: message.userName, size: 28) }
            VStack(alignment: mine ? .trailing : .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(message.userName).font(Theme.Font.caption.weight(.semibold))
                    DateText(message.createdAt, style: .time)
                    if message.editedAt != nil { Text("edited").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                }
                if let r = message.replyTo {
                    Text("↩ \(r["user_name"]?.string ?? ""): \(r["body"]?.string ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2)
                        .padding(6).background(Theme.Palette.neutralSoft, in: RoundedRectangle(cornerRadius: 8))
                }
                if message.type == "system" {
                    Text(p.text).font(Theme.Font.caption).italic().foregroundStyle(Theme.textTertiary)
                } else if !p.text.isEmpty {
                    Text(p.text).font(Theme.Font.body).textSelection(.enabled)
                        .padding(.horizontal, 12).padding(.vertical, 8)
                        .background(mine ? Theme.Palette.brand.opacity(0.14) : Theme.card, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                ForEach(Array(p.images.enumerated()), id: \.offset) { _, img in SignedImage(source: img.1, maxHeight: 220) }
                ForEach(Array(p.files.enumerated()), id: \.offset) { _, f in
                    AsyncButton { let data = try await SignedFiles.fetch(env, f.1); presentFile(env, data: data, name: f.0, share: $share) } label: { Label(f.0, systemImage: "doc") }
                        .font(Theme.Font.secondary)
                }
                ForEach(Array(message.linkedEntities.enumerated()), id: \.offset) { _, e in EntityChip(entity: e, preview: message.entityPreviews["\(e["type"]?.string ?? ""):\(e["id"]?.int ?? 0)"]) }
                reactions
                if mine, message.type != "system" {
                    let readers = thread.readers(message)
                    Text(thread.readByAll(message) ? "Read" : (readers.isEmpty ? "Sent" : "Read by \(readers.joined(separator: ", "))")).font(.system(size: 10)).foregroundStyle(thread.readByAll(message) ? Theme.Palette.success : Theme.textTertiary)
                }
            }
            .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
        }
        .contextMenu {
            Button { thread.replyTo = message } label: { Label("Reply", systemImage: "arrowshape.turn.up.left") }
            Menu("React") { ForEach(Self.quickEmoji, id: \.self) { e in Button(e) { Task { try? await thread.react(message, emoji: e) } } } }
            Button { forward() } label: { Label("Forward", systemImage: "arrowshape.turn.up.right") }
            if mine, message.type != "system" {
                Button { thread.editing = message } label: { Label("Edit", systemImage: "pencil") }
                Button(role: .destructive) { Task { try? await thread.delete(message) } } label: { Label("Delete", systemImage: "trash") }
            }
        }
        .shareFile($share)
    }

    @ViewBuilder
    private var reactions: some View {
        let rx = message.reactions.filter { !$0.value.isEmpty }.sorted { $0.key < $1.key }
        if !rx.isEmpty {
            HStack(spacing: 4) {
                ForEach(Array(rx.enumerated()), id: \.offset) { _, pair in
                    Button("\(pair.key) \(pair.value.count)") { Task { try? await thread.react(message, emoji: pair.key) } }
                        .buttonStyle(.bordered).controlSize(.mini)
                        .tint(pair.value.contains(thread.me ?? -1) ? Theme.Palette.brand : Theme.Palette.neutral)
                }
            }
        }
    }
}

/// `#[#ORD-1234](entity:order:1234)` → a chip that opens the record.
struct EntityChip: View {
    @Environment(AppEnvironment.self) private var env
    let entity: JSONValue
    let preview: JSONValue?
    var body: some View {
        let type = entity["type"]?.string ?? ""
        let id = entity["id"]?.int ?? 0
        let route: Route? = type == "order" ? .order(id: id) : (type == "production_order" ? .productionOrder(id: id) : nil)
        Button { if let route { env.navigator.go(route) } } label: {
            HStack(spacing: 6) {
                Image(systemName: type == "order" ? "bag" : "hammer")
                VStack(alignment: .leading, spacing: 1) {
                    Text(preview?["label"]?.string ?? entity["label"]?.string ?? "#\(id)").font(Theme.Font.caption.weight(.semibold))
                    if let p = preview { Text([p["subtitle"]?.string, p["meta"]?.string].compactMap { $0 }.joined(separator: " · ")).font(.system(size: 10)).foregroundStyle(Theme.textSecondary).lineLimit(1) }
                }
                if let s = preview?["status"]?.string { StatusBadge(s) }
                if preview?["is_overdue"]?.bool == true { Pill("overdue", .danger) }
            }
            .padding(.horizontal, 10).padding(.vertical, 6)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: 10))
            .overlay(RoundedRectangle(cornerRadius: 10).stroke(Theme.line))
        }
        .buttonStyle(.plain)
        .disabled(route == nil)
    }
}

// MARK: - Composer (POST /messages {body, reply_to_id}; attachments via POST /channels/attachments)

struct Composer: View {
    @Environment(AppEnvironment.self) private var env
    let thread: ThreadStore
    @State private var text = ""
    @State private var pending: [(LocalFile, JSONValue?)] = []
    @State private var uploading = false
    @State private var sending = false
    @State private var showQuick = false
    @State private var showEntities = false
    @FocusState private var focused: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            if let r = thread.replyTo {
                HStack { Text("Replying to \(r.userName): \(r.parsed.text)").font(Theme.Font.caption).lineLimit(1); Spacer(); Button { thread.replyTo = nil } label: { Image(systemName: "xmark.circle") }.buttonStyle(.plain) }
            }
            if let e = thread.editing {
                HStack { Text("Editing message").font(Theme.Font.caption); Spacer(); Button { thread.editing = nil; text = "" } label: { Image(systemName: "xmark.circle") }.buttonStyle(.plain) }
                    .onAppear { text = e.body }
            }
            if !pending.isEmpty {
                HStack {
                    ForEach(Array(pending.enumerated()), id: \.offset) { i, p in
                        HStack(spacing: 4) {
                            Image(systemName: p.1 == nil ? "arrow.up.circle.dotted" : "checkmark.circle")
                            Text(p.0.name).font(Theme.Font.caption).lineLimit(1)
                            Button { pending.remove(at: i) } label: { Image(systemName: "xmark") }.buttonStyle(.plain)
                        }
                        .padding(6).background(Theme.Palette.neutralSoft, in: Capsule())
                    }
                }
            }
            HStack(alignment: .bottom, spacing: 6) {
                AttachFileButton(label: "") { f in Task { await attach(f) } }.labelStyle(.iconOnly).disabled(pending.count >= 10)
                Button { showQuick = true } label: { Image(systemName: "text.badge.star") }.popover(isPresented: $showQuick) { QuickReplyPicker { text += (text.isEmpty ? "" : " ") + $0; showQuick = false } }
                Button { showEntities = true } label: { Image(systemName: "number") }.popover(isPresented: $showEntities) { EntityPicker { text += (text.isEmpty ? "" : " ") + $0 + " "; showEntities = false } }
                TextField("Message…", text: $text, axis: .vertical)
                    .textFieldStyle(.roundedBorder).lineLimit(1...6).focused($focused)
                    .onChange(of: text) { _, _ in Task { await thread.whisperTyping() } }
                    .onSubmit { Task { await send() } }
                Button { Task { await send() } } label: { Image(systemName: "paperplane.fill") }
                    .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    .disabled(sending || uploading || (text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && pending.isEmpty))
                    .keyboardShortcut(.return, modifiers: .command)
            }
        }
        .padding(Theme.Space.m)
    }

    private func attach(_ f: LocalFile) async {
        pending.append((f, nil))
        uploading = true
        defer { uploading = false }
        do {
            let att = try await thread.uploadAttachment(f)
            if let i = pending.firstIndex(where: { $0.0 == f }) { pending[i].1 = att }
        } catch {
            env.toasts.error(error)
            pending.removeAll { $0.0 == f }
        }
    }

    private func send() async {
        sending = true
        defer { sending = false }
        do {
            if let e = thread.editing {
                try await thread.edit(e, body: text)
                text = ""
                return
            }
            let attachmentMarkdown = pending.compactMap { p -> String? in
                guard let a = p.1, let url = a["url"]?.string else { return nil }
                let name = a["name"]?.string ?? p.0.name
                return a["is_image"]?.bool == true ? "![\(name)](\(url))" : "[📎 \(name)](\(url))"
            }.joined(separator: "\n")
            let body = [text.trimmingCharacters(in: .whitespacesAndNewlines), attachmentMarkdown].filter { !$0.isEmpty }.joined(separator: "\n")
            try await thread.send(body: body.isEmpty ? " " : body)
            text = ""
            pending = []
        } catch { env.toasts.error(error) }
    }
}

/// GET /channels/quick-replies → {quick_replies: [{id, title, body}]}
struct QuickReplyPicker: View {
    @Environment(AppEnvironment.self) private var env
    let pick: (String) -> Void
    @State private var items: [Record] = []
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Quick replies").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            if items.isEmpty { Text("None saved yet").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            ForEach(items) { q in
                Button { pick(q.string("body") ?? "") } label: {
                    VStack(alignment: .leading, spacing: 1) { Text(q.string("title") ?? "").font(Theme.Font.secondary.weight(.semibold)); Text(q.string("body") ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2) }
                }.buttonStyle(.plain)
            }
        }
        .padding(Theme.Space.m).frame(width: 300)
        .task { items = (try? await env.session.client.json("/v1/admin/channels/quick-replies"))?["quick_replies"]?.records ?? [] }
    }
}

/// GET /channels/entity-search?q → {results: [{type, id, label, subtitle, status, meta}]}; inserts `#[label](entity:type:id)`.
struct EntityPicker: View {
    @Environment(AppEnvironment.self) private var env
    let pick: (String) -> Void
    @State private var q = ""
    @State private var results: [JSONValue] = []
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            TextField("Order number, customer or product", text: $q).textFieldStyle(.roundedBorder).onSubmit { Task { await search() } }
            ForEach(Array(results.enumerated()), id: \.offset) { _, r in
                Button { pick("#[\(r["label"]?.string ?? "")](entity:\(r["type"]?.string ?? ""):\(r["id"]?.int ?? 0))") } label: {
                    HStack { Text(r["label"]?.string ?? "").font(Theme.Font.secondary.weight(.semibold)); Text(r["subtitle"]?.string ?? "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Spacer(); StatusBadge(r["status"]?.string) }
                }.buttonStyle(.plain)
            }
        }
        .padding(Theme.Space.m).frame(width: 340)
    }
    private func search() async {
        results = (try? await env.session.client.json("/v1/admin/channels/entity-search", query: ["q": q]))?["results"]?.array ?? []
    }
}

/// POST /channels/{id}/messages/{msg}/forward {to_channel_id}
struct ForwardSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let store: CommsStore
    let thread: ThreadStore?
    let message: ChatMessage
    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            Text("Forward to").font(Theme.Font.sectionTitle)
            Text(message.parsed.text).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(3)
            List(store.channels.filter { $0.id != message.channelId }) { ch in
                AsyncButton {
                    guard let thread else { return }
                    _ = try await thread.forward(message, to: ch.id)
                    env.toasts.show("Forwarded to \(ch.name)")
                    dismiss()
                } label: { ChannelRow(channel: ch) }
                .buttonStyle(.plain)
            }
            HStack { Spacer(); Button("Cancel") { dismiss() } }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 420, minHeight: 420)
        #endif
    }
}
