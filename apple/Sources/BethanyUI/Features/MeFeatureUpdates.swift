import SwiftUI
import BethanyKit

/// /updates — the feed (UpdatesPage.tsx): notices with a photo and a
/// must-read acknowledgement, shout-outs, birthday and anniversary posts,
/// events with an RSVP. Anyone may post a recognition; `updates.post`
/// (the feed's `can_post`) posts the rest and sees scheduled posts.
struct UpdatesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var kind = ""
    @State private var rows: [Record] = []
    @State private var page = 1
    @State private var hasMore = false
    @State private var meta: JSONValue = .null
    @State private var state: Loadable<[Record]> = .idle
    @State private var compose: ComposeKind?
    @State private var showScheduled = false

    private var canPost: Bool { meta["can_post"]?.bool ?? env.session.can("updates.post") }

    var body: some View {
        Page("Updates", subtitle: subtitle) {
            HStack {
                Button { compose = ComposeKind(kind: "recognition") } label: { Label("Recognise someone", systemImage: "star") }.buttonStyle(.bordered)
                if canPost { Button("New post") { compose = ComposeKind(kind: "update") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                Spacer()
                if canPost, let n = meta["scheduled"]?.int, n > 0 { Button("\(n) scheduled ›") { showScheduled = true }.font(Theme.Font.caption) }
            }
            ChipPicker(options: [("", "All"), ("update", "Updates"), ("recognition", "Recognitions"), ("event", "Events"), ("celebration", "Celebrations")], selection: $kind)
            LoadableView(state, retry: { Task { await load(reset: true) } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing here yet", message: canPost ? "Post the first update." : nil, symbol: "megaphone") }) { posts in
                LazyVStack(spacing: Theme.Space.m) {
                    ForEach(posts) { p in PostCard(post: p) { env.navigator.push(.updates(id: p.int("id"))) } }
                    if hasMore {
                        Button("Older posts") { Task { await load(reset: false) } }.buttonStyle(.bordered).frame(maxWidth: .infinity)
                    }
                }
            }
        }
        .task { if rows.isEmpty { await load(reset: true) } }
        .refreshable { await load(reset: true) }
        .onChange(of: kind) { _, _ in Task { await load(reset: true) } }
        .sheet(item: $compose) { k in ComposePostSheet(initialKind: k.kind, canPost: canPost) { compose = nil; Task { await load(reset: true) } } }
        .sheet(isPresented: $showScheduled) { ScheduledPostsSheet { Task { await load(reset: true) } } }
    }

    private var subtitle: String {
        let pending = meta["must_read_pending"]?.int ?? 0
        return pending > 0 ? "\(MeFormat.plural(pending, "must-read")) waiting for you." : "What is happening at Bethany House."
    }

    private func load(reset: Bool) async {
        let next = reset ? 1 : page + 1
        if reset { state = .loading(previous: rows.isEmpty ? nil : rows) }
        do {
            let json = try await env.session.client.get("/v1/admin/updates", query: ["page": String(next), "kind": kind.isEmpty ? nil : kind]).json ?? .null
            let fresh = json.records
            rows = reset ? fresh : rows.filter { r in !fresh.contains { $0.id == r.id } } + fresh
            page = next
            hasMore = json["has_more"]?.bool ?? false
            meta = json
            state = .loaded(rows)
        } catch let e as APIError { state = .failed(e, previous: rows.isEmpty ? nil : rows) }
        catch { state = .failed(APIError.transport(error.localizedDescription), previous: rows.isEmpty ? nil : rows) }
    }
}

/// Which composer to open; `sheet(item:)` wants an Identifiable.
struct ComposeKind: Identifiable { let kind: String; var id: String { kind } }

enum PostKind {
    static let label: [String: String] = ["update": "Update", "recognition": "Recognition", "celebration": "Celebration", "event": "Event"]
    static let icon: [String: String] = ["update": "📣", "recognition": "⭐", "celebration": "🎉", "event": "📅"]
    static func headline(_ p: Record) -> String {
        p.string("kind") == "recognition" ? "\(p["author"]?["name"]?.string ?? "Someone") recognised \(p["recipient"]?["name"]?.string ?? "")" : (p.string("title") ?? label[p.string("kind") ?? ""] ?? "Post")
    }
}

struct PostCard: View {
    let post: Record
    let open: () -> Void

    var body: some View {
        let kind = post.string("kind") ?? "update"
        let unread = post["me"]?["read_at"]?.isNull ?? true
        let needsAck = post.flag("must_read") && (post["me"]?["acknowledged_at"]?.isNull ?? true) && !post.flag("is_author")
        let person = kind == "celebration" ? post["recipient"] : post["author"]
        Button(action: open) {
            VStack(alignment: .leading, spacing: Theme.Space.s) {
                HStack(spacing: Theme.Space.m) {
                    PersonAvatar(name: person?["name"]?.string, url: person?["avatar_url"]?.string)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 4) {
                            Text(person?["name"]?.string ?? "Bethany House").font(Theme.Font.body.weight(.semibold)).lineLimit(1)
                            if kind == "recognition", let r = post["recipient"]?["name"]?.string { Text("recognised").foregroundStyle(Theme.textSecondary).font(Theme.Font.secondary); Text(r).font(Theme.Font.secondary.weight(.semibold)).lineLimit(1) }
                        }
                        Text("\(PostKind.icon[kind] ?? "") \(PostKind.label[kind] ?? kind) · \(MeFormat.dayTime(post.date("published_at")))\(post["outlet"]?["name"]?.string.map { " · \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                    Spacer()
                    if needsAck { Text("MUST READ").font(Theme.Font.caption).foregroundStyle(.white).padding(.horizontal, 8).padding(.vertical, 3).background(Theme.Palette.danger, in: Capsule()) }
                    else if unread { Circle().fill(Theme.Palette.brand).frame(width: 10, height: 10).accessibilityLabel("Unread") }
                }
                if let t = post.string("title") { Text(t).font(Theme.Font.sectionTitle) }
                if let b = post.string("body") { Text(b).font(Theme.Font.secondary).lineLimit(4) }
                if let pts = post.int("points"), pts > 0 { Text("+\(MeFormat.plural(pts, "point"))").font(Theme.Font.caption.weight(.semibold)).foregroundStyle(Theme.Palette.amber) }
                if kind == "event" {
                    Text("📅 \(MeFormat.dayTime(post.date("event_at")))\(post.string("event_place").map { " · \($0)" } ?? "")\(post["me"]?["rsvp"]?.string.map { " · you said \($0)" } ?? "")\((post.int("rsvp_yes_count") ?? 0) > 0 ? " · \(post.int("rsvp_yes_count") ?? 0) going" : "")").font(Theme.Font.secondary)
                }
                Text("\(post.flag("has_photo") ? "📷 photo · " : "")\(MeFormat.plural(post.int("comments_count") ?? 0, "comment"))\(post.flag("can_manage") ? " · \(post.int("reads_count") ?? 0) read" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            .padding(Theme.Space.l)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(needsAck ? Theme.Palette.danger.opacity(0.4) : Theme.line, lineWidth: needsAck ? 2 : 1))
            .foregroundStyle(Theme.text)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - One post: photo, RSVP, acknowledge, readers, comments

struct PostDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let id: Int
    @State private var post: Resource<Record>?
    @State private var photoURL: URL?
    @State private var comment = ""
    @State private var showReaders = false
    @State private var busy = false
    @State private var markedRead = false

    var body: some View {
        Page(post?.value.map(PostKind.headline) ?? "Post") {
            if let post {
                LoadableView(post.state, retry: { post.load() }) { p in content(p) }
            }
        }
        .task {
            if post == nil {
                let c = env.session.client, postId = id
                post = Resource {
                    let j = try await c.get("/v1/admin/updates/\(postId)").json
                    return Record(json: j?["post"] ?? .null)
                }
                post?.load()
            }
        }
        .onChange(of: post?.value?.id) { _, _ in Task { await afterLoad() } }
        .refreshable { await post?.reload() }
    }

    private func afterLoad() async {
        guard let p = post?.value else { return }
        if !markedRead, p["me"]?["read_at"]?.isNull ?? true {
            markedRead = true
            _ = try? await env.session.client.post("/v1/admin/updates/\(id)/read")
        }
        if p.flag("has_photo"), photoURL == nil, let s = try? await env.session.client.get("/v1/admin/updates/\(id)/photo").json?["url"]?.string {
            photoURL = URL(string: s)
        }
    }

    @ViewBuilder
    private func content(_ p: Record) -> some View {
        let kind = p.string("kind") ?? "update"
        let person = kind == "celebration" ? p["recipient"] : p["author"]
        SectionCard {
            HStack(spacing: Theme.Space.m) {
                PersonAvatar(name: person?["name"]?.string, url: person?["avatar_url"]?.string)
                VStack(alignment: .leading, spacing: 2) {
                    Text(person?["name"]?.string ?? "Bethany House").font(Theme.Font.body.weight(.semibold))
                    Text("\(PostKind.icon[kind] ?? "") \(PostKind.label[kind] ?? kind) · \(p.date("published_at") != nil ? MeFormat.dayTime(p.date("published_at")) : "scheduled \(MeFormat.dayTime(p.date("publish_at")))")\(p["outlet"]?["name"]?.string.map { " · \($0)" } ?? " · everyone")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
                Spacer()
                if p.flag("can_manage") {
                    ConfirmButton("Remove this post", message: "It disappears from everyone's feed.", destructive: true, action: { await remove() }) { Text("Remove").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
                }
            }
            if p.flag("has_photo") {
                if let photoURL {
                    AsyncImage(url: photoURL) { img in img.resizable().scaledToFit() } placeholder: { ProgressView().frame(maxWidth: .infinity, minHeight: 120) }
                        .frame(maxHeight: 380).clipShape(RoundedRectangle(cornerRadius: Theme.Radius.field))
                } else {
                    ProgressView().frame(maxWidth: .infinity, minHeight: 120)
                }
            }
            if let b = p.string("body") { Text(b).font(Theme.Font.body) }
            if let pts = p.int("points"), pts > 0 { Text("+\(MeFormat.plural(pts, "point"))").font(Theme.Font.secondary.weight(.semibold)).foregroundStyle(Theme.Palette.amber) }
            if kind == "event" { eventBox(p) }
            if p.flag("must_read") && !p.flag("is_author") {
                if let at = p["me"]?["acknowledged_at"]?.date {
                    NoticeText("You acknowledged this on \(MeFormat.dayTime(at)).", tone: .success)
                } else {
                    Button("I have read this") { Task { await acknowledge() } }.buttonStyle(.primary).disabled(busy)
                }
            }
            if let readers = p["readers"], readers.object != nil { readersBox(p, readers) }
        }
        commentsCard(p)
    }

    private func eventBox(_ p: Record) -> some View {
        VStack(alignment: .leading, spacing: Theme.Space.s) {
            Text("📅 \(MeFormat.dayTime(p.date("event_at")))\(p.string("event_place").map { " · \($0)" } ?? "")").font(Theme.Font.body.weight(.medium))
            HStack(spacing: Theme.Space.s) {
                ForEach([("yes", "I'll be there"), ("maybe", "Maybe"), ("no", "Can't make it")], id: \.0) { a, label in
                    let mine = p["me"]?["rsvp"]?.string == a
                    Button(label) { Task { await rsvp(a) } }
                        .font(Theme.Font.caption).foregroundStyle(mine ? .white : Theme.text)
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(mine ? Theme.Palette.brandDeep : Theme.card, in: Capsule())
                        .overlay(Capsule().stroke(mine ? Color.clear : Theme.line, lineWidth: 1))
                        .buttonStyle(.plain).disabled(busy)
                }
            }
            if let n = p.int("rsvp_yes_count"), n > 0 { Text("\(n) going").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
        }
        .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
        .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
    }

    private func readersBox(_ p: Record, _ readers: JSONValue) -> some View {
        let mustRead = p.flag("must_read")
        let read = readers["read"]?.array ?? [], acked = readers["acknowledged"]?.array ?? [], pending = readers["pending"]?.array ?? []
        let audience = readers["audience"]?.int ?? 0
        let rsvp = readers["rsvp"]
        let done = mustRead ? acked : read
        let notYet = mustRead ? read.filter { $0["acknowledged_at"]?.isNull ?? true } + pending : pending
        return VStack(alignment: .leading, spacing: Theme.Space.s) {
            Button { showReaders.toggle() } label: {
                HStack {
                    Text("\(done.count) of \(audience) \(mustRead ? "acknowledged" : "read")\(p.string("kind") == "event" ? " · \(rsvp?["yes"]?.int ?? 0) going, \(rsvp?["maybe"]?.int ?? 0) maybe, \(rsvp?["no"]?.int ?? 0) not" : "")").font(Theme.Font.secondary.weight(.medium))
                    Spacer()
                    Image(systemName: showReaders ? "chevron.up" : "chevron.down").font(.system(size: 11))
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if showReaders {
                HStack(alignment: .top, spacing: Theme.Space.l) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(mustRead ? "Acknowledged" : "Read").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        ForEach(Array(done.enumerated()), id: \.offset) { _, r in Text("\(r["name"]?.string ?? "")\(r["rsvp"]?.string.map { " · \($0)" } ?? "")").font(Theme.Font.caption) }
                        if done.isEmpty { Text("Nobody yet").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                    }
                    VStack(alignment: .leading, spacing: 2) {
                        Text(mustRead ? "Not yet" : "Not opened").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        ForEach(Array(notYet.enumerated()), id: \.offset) { _, r in Text("\(r["name"]?.string ?? "")\(mustRead && !(r["read_at"]?.isNull ?? true) ? " · opened" : "")").font(Theme.Font.caption) }
                    }
                }
            }
        }
        .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.field).stroke(Theme.line, lineWidth: 1))
    }

    private func commentsCard(_ p: Record) -> some View {
        let comments = p.records("comments")
        return SectionCard(MeFormat.plural(comments.count, "comment")) {
            ForEach(comments) { c in
                HStack(alignment: .top, spacing: Theme.Space.s) {
                    PersonAvatar(name: c["user"]?["name"]?.string, url: c["user"]?["avatar_url"]?.string, size: 28)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 4) {
                            Text(c["user"]?["name"]?.string ?? "").font(Theme.Font.caption.weight(.semibold))
                            Text("· \(MeFormat.dayTime(c.date("at")))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                            if c.flag("mine") || p.flag("can_manage") {
                                Button("remove") { Task { await removeComment(c) } }.font(Theme.Font.caption).foregroundStyle(Theme.textTertiary).buttonStyle(.plain)
                            }
                        }
                        Text(c.string("body") ?? "").font(Theme.Font.secondary)
                    }
                    .padding(Theme.Space.s).frame(maxWidth: .infinity, alignment: .leading)
                    .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                }
            }
            if p.flag("comments_enabled") {
                HStack {
                    TextField("Comment as \(env.session.me?.raw["first_name"]?.string ?? "you")…", text: $comment).textFieldStyle(.roundedBorder).onSubmit { Task { await send() } }
                    Button("Post") { Task { await send() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || comment.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            } else {
                Text("Comments are off on this post.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
    }

    // MARK: Actions

    private func call(_ work: () async throws -> String?) async {
        busy = true; defer { busy = false }
        do { if let m = try await work() { env.toasts.show(m) }; await post?.reload() } catch { env.toasts.error(error) }
    }

    private func acknowledge() async {
        await call { try await env.session.client.post("/v1/admin/updates/\(id)/acknowledge").json?["message"]?.string ?? "Acknowledged" }
    }

    private func rsvp(_ answer: String) async {
        await call { try await env.session.client.post("/v1/admin/updates/\(id)/rsvp", json: .object(["answer": .string(answer)])).json?["message"]?.string ?? "Saved" }
    }

    private func send() async {
        let body = comment.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        await call {
            _ = try await env.session.client.post("/v1/admin/updates/\(id)/comments", json: .object(["body": .string(body)]))
            comment = ""
            return nil
        }
    }

    private func removeComment(_ c: Record) async {
        await call { _ = try await env.session.client.delete("/v1/admin/updates/\(id)/comments/\(c.id)"); return nil }
    }

    private func remove() async {
        do {
            let r = try await env.session.client.delete("/v1/admin/updates/\(id)")
            env.toasts.show(r.json?["message"]?.string ?? "Removed")
            env.navigator.go(.updates())
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Compose (multipart, as updatesApi.create builds it: booleans as "1"/"0", photo file)

struct ComposePostSheet: View {
    @Environment(AppEnvironment.self) private var env
    let initialKind: String
    let canPost: Bool
    let onPosted: () -> Void
    @State private var kind = "update"
    @State private var title = ""
    @State private var body_ = ""
    @State private var mustRead = false
    @State private var commentsEnabled = true
    @State private var outletId: Int?
    @State private var schedule = false
    @State private var publishAt = Date().addingTimeInterval(3600)
    @State private var eventAt = Date().addingTimeInterval(86_400)
    @State private var eventPlace = ""
    @State private var recipientId: Int?
    @State private var points = ""
    @State private var photo: PickedPhoto?
    @State private var people: [Record] = []
    @State private var outlets = OutletChoices()
    @State private var busy = false

    private var ok: Bool {
        kind == "recognition" ? recipientId != nil && !body_.trimmingCharacters(in: .whitespaces).isEmpty : !title.trimmingCharacters(in: .whitespaces).isEmpty
    }

    var body: some View {
        MeSheet(kind == "recognition" ? "Recognise someone" : kind == "event" ? "New event" : "New update", primary: schedule && kind != "recognition" ? "Schedule" : "Post", disabled: !ok, busy: busy, action: { await save() }) {
            if canPost {
                ChipPicker(options: [("update", "📣 Update"), ("event", "📅 Event"), ("recognition", "⭐ Recognition")], selection: $kind)
            }
            if kind == "recognition" {
                FieldLabel("Who") {
                    Picker("Who", selection: $recipientId) {
                        Text("Choose a colleague…").tag(Int?.none)
                        ForEach(people) { p in Text(p.string("name") ?? "").tag(p.int("id")) }
                    }
                    .labelsHidden()
                }
                FieldLabel("What they did") { TextField("Stayed late to finish the wedding order…", text: $body_, axis: .vertical).lineLimit(3...8).textFieldStyle(.roundedBorder) }
                if canPost {
                    FieldLabel("Points (optional; payroll turns them into a bonus only when a point has a value)") { TextField("0–100", text: $points).textFieldStyle(.roundedBorder).decimalKeyboard().frame(maxWidth: 120) }
                }
            } else {
                FieldLabel("Title") { TextField("", text: $title).textFieldStyle(.roundedBorder) }
                FieldLabel("Details") { TextField("", text: $body_, axis: .vertical).lineLimit(4...10).textFieldStyle(.roundedBorder) }
                if kind == "event" {
                    FieldLabel("When") { DatePicker("When", selection: $eventAt).labelsHidden() }
                    FieldLabel("Where") { TextField("", text: $eventPlace).textFieldStyle(.roundedBorder) }
                }
                FieldLabel("Photo (optional, up to 5 MB)") { PhotoPickerField(label: "Choose photo", photo: $photo) }
                OutletPicker(choices: outlets, outletId: $outletId)
                if kind == "update" { Toggle("Must read (everyone presses \"I have read this\")", isOn: $mustRead) }
                Toggle("Allow comments", isOn: $commentsEnabled)
                Toggle("Schedule", isOn: $schedule)
                if schedule { DatePicker("Goes out", selection: $publishAt, in: Date()...) }
            }
        }
        .task {
            kind = initialKind
            await outlets.load(env)
            if let json = try? await env.session.client.get("/v1/admin/updates/people").json { people = json.records }
        }
    }

    private func save() async {
        busy = true; defer { busy = false }
        var parts: [APIClient.MultipartPart] = [.init(name: "kind", value: kind)]
        func add(_ name: String, _ value: String?) { if let v = value, !v.isEmpty { parts.append(.init(name: name, value: v)) } }
        if kind == "recognition" {
            add("recipient_id", recipientId.map(String.init))
            add("body", body_.trimmingCharacters(in: .whitespacesAndNewlines))
            if canPost, let p = Int(points) { add("points", String(p)) }
        } else {
            add("title", title.trimmingCharacters(in: .whitespacesAndNewlines))
            add("body", body_.trimmingCharacters(in: .whitespacesAndNewlines))
            add("outlet_id", outletId.map(String.init))
            parts.append(.init(name: "must_read", value: kind == "update" && mustRead ? "1" : "0"))
            parts.append(.init(name: "comments_enabled", value: commentsEnabled ? "1" : "0"))
            if schedule { add("publish_at", MeFormat.dateTimeString(publishAt)) }
            if kind == "event" { add("event_at", MeFormat.dateTimeString(eventAt)); add("event_place", eventPlace.trimmingCharacters(in: .whitespaces)) }
            if let photo { parts.append(.init(name: "photo", data: photo.data, filename: photo.filename, mimeType: photo.mimeType)) }
        }
        do {
            let r = try await env.session.client.send(.init(.post, "/v1/admin/updates", body: .multipart(parts)))
            env.toasts.show(r.json?["message"]?.string ?? "Posted")
            onPosted()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Scheduled posts (updates.post): post now or remove

struct ScheduledPostsSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onChanged: () -> Void
    @State private var list: Resource<[Record]>?

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    if let list {
                        LoadableView(list.state, retry: { list.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("Nothing scheduled", symbol: "calendar.badge.clock") }) { rows in
                            ListCard {
                                ForEach(rows) { p in
                                    ListRow {
                                        HStack {
                                            VStack(alignment: .leading, spacing: 2) {
                                                Text(p.string("title") ?? PostKind.label[p.string("kind") ?? ""] ?? "Post").font(Theme.Font.body.weight(.semibold))
                                                Text("Goes out \(MeFormat.dayTime(p.date("publish_at")))\(p["outlet"]?["name"]?.string.map { " · \($0)" } ?? "")\(p.flag("must_read") ? " · must read" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                            }
                                            Spacer()
                                            Button("Post now") { Task { await postNow(p) } }.font(Theme.Font.caption)
                                            Button("Remove") { Task { await remove(p) } }.font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                .padding(Theme.Space.l)
            }
            .background(Theme.canvas)
            .navigationTitle("Scheduled posts")
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
        }
        #if os(macOS)
        .frame(minWidth: 480, minHeight: 360)
        #endif
        .task {
            if list == nil {
                let c = env.session.client
                list = Resource { try await c.get("/v1/admin/updates/scheduled").json?.records ?? [] }
                list?.load()
            }
        }
    }

    private func postNow(_ p: Record) async {
        do {
            _ = try await env.session.client.put("/v1/admin/updates/\(p.id)", json: .object(["publish_at": .null]))
            env.toasts.show("Posted."); list?.load(); onChanged()
        } catch { env.toasts.error(error) }
    }

    private func remove(_ p: Record) async {
        do {
            let r = try await env.session.client.delete("/v1/admin/updates/\(p.id)")
            env.toasts.show(r.json?["message"]?.string ?? "Removed"); list?.load(); onChanged()
        } catch { env.toasts.error(error) }
    }
}
