import SwiftUI
import BethanyKit

// MARK: - To sign (SignPage.tsx): what I have been asked to sign, and what I signed or declined

struct SignView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: Resource<JSONValue>?

    var body: some View {
        Page("To sign", subtitle: subtitle) {
            if let list {
                LoadableView(list.state, retry: { list.load() }, isEmpty: { $0.records.isEmpty }, empty: { EmptyState("Nobody has asked you to sign anything", symbol: "signature") }) { d in
                    ListCard {
                        ForEach(d.records) { r in
                            Button { env.navigator.push(.sign(id: r.int("id"))) } label: {
                                ListRow {
                                    HStack {
                                        VStack(alignment: .leading, spacing: 2) {
                                            Text(r.string("title") ?? "").font(Theme.Font.body.weight(.semibold))
                                            Text("\(r.string("requested_by").map { "From \($0)" } ?? "")\(r.status == "pending" ? r.date("due_at").map { " · by \(MeFormat.dayYear($0))" } ?? "" : "")\(r.date("signed_at").map { " · signed \(MeFormat.dayYear($0))" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                        }
                                        Spacer()
                                        StatusBadge(r.status == "pending" ? "Sign" : r.status, tone: r.status == "pending" ? .danger : StatusTone.forStatus(r.status))
                                        Image(systemName: "chevron.right").font(.system(size: 11, weight: .semibold)).foregroundStyle(Theme.textTertiary)
                                    }
                                }
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
        .task {
            if list == nil {
                let c = env.session.client
                list = Resource { try await c.get("/v1/admin/me/sign").json ?? .null }
                list?.load()
            }
        }
        .refreshable { await list?.reload() }
    }

    private var subtitle: String? {
        guard let d = list?.value else { return nil }
        let n = d["pending"]?.int ?? 0
        return n > 0 ? "\(n) waiting for your signature." : "Nothing waiting."
    }
}

/// One request: read the text or open the document, then sign with the pad
/// (`POST /me/sign/{id}/sign {signature: PNG data URL}`) or decline with a note.
struct SignDetailView: View {
    @Environment(AppEnvironment.self) private var env
    let id: Int
    @State private var request: Resource<Record>?
    @State private var signature: String?
    @State private var declining = false
    @State private var note = ""
    @State private var busy = false

    var body: some View {
        Page(request?.value?.string("title") ?? "Sign") {
            if let request {
                LoadableView(request.state, retry: { request.load() }) { r in
                    SectionCard {
                        Text("\(r.string("requested_by").map { "Asked by \($0) on \(MeFormat.dayYear(r.date("created_at")))" } ?? "")\(r.date("due_at").map { " · by \(MeFormat.dayYear($0))" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        if r.string("kind") == "document" {
                            if let link = r["document_link"], let s = link["url"]?.string, let url = URL(string: s) {
                                Link(destination: url) { Label("Open the document to read it", systemImage: "doc") }.font(Theme.Font.body.weight(.medium))
                            } else {
                                Text("The document is no longer on your file.").foregroundStyle(Theme.Palette.danger)
                            }
                        } else {
                            ScrollView {
                                Text(r.string("body") ?? "").font(Theme.Font.body).textSelection(.enabled).frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .frame(maxHeight: 320).padding(Theme.Space.m)
                            .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
                        }
                        if r.status == "pending" {
                            if declining {
                                FieldLabel("Why are you not signing?") { TextField("", text: $note, axis: .vertical).lineLimit(3...6).textFieldStyle(.roundedBorder) }
                                HStack {
                                    Spacer()
                                    Button("Back") { declining = false }.buttonStyle(.bordered)
                                    Button("Send") { Task { await decline() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || note.trimmingCharacters(in: .whitespaces).count < 3)
                                }
                            } else {
                                Text("Your signature").font(Theme.Font.body.weight(.medium))
                                SignaturePad(dataURL: $signature)
                                Text("By signing you confirm you have read and agree to the above. A signing record goes on your file.").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                HStack {
                                    Button("I can't sign this") { declining = true }.font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                                    Spacer()
                                    Button("Sign") { Task { await sign() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || signature == nil)
                                }
                            }
                        } else {
                            NoticeText(r.status == "signed" ? "Signed on \(MeFormat.dayYear(r.date("signed_at"))). The record is among your documents." : r.status == "declined" ? "Declined: \(r.string("note") ?? "")" : "Cancelled by HR.", tone: r.status == "signed" ? .success : .neutral)
                        }
                    }
                }
            }
        }
        .task {
            if request == nil {
                let c = env.session.client, rid = id
                request = Resource {
                    let j = try await c.get("/v1/admin/me/sign/\(rid)").json
                    return Record(json: j?["request"] ?? .null)
                }
                request?.load()
            }
        }
        .refreshable { await request?.reload() }
    }

    private func sign() async {
        guard let signature else { return }
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/me/sign/\(id)/sign", json: .object(["signature": .string(signature)]))
            env.toasts.show(r.json?["message"]?.string ?? "Signed")
            env.navigator.go(.sign())
        } catch { env.toasts.error(error) }
    }

    private func decline() async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/me/sign/\(id)/decline", json: .object(["note": .string(note.trimmingCharacters(in: .whitespacesAndNewlines))]))
            env.toasts.show(r.json?["message"]?.string ?? "Sent")
            env.navigator.go(.sign())
        } catch { env.toasts.error(error) }
    }
}

// MARK: - To-dos (TodosPage.tsx): mine, the ones I gave, and the team's for a manager

struct TodosView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var scope = "mine"
    @State private var status = "open"
    @State private var list: Resource<JSONValue>?
    @State private var adding = false
    @State private var doing: Record?
    @State private var doneNote = ""
    @State private var busy = false

    private var canGive: Bool { list?.value?["can_give"]?.bool ?? false }

    var body: some View {
        Page("To-dos", subtitle: subtitle) {
            HStack {
                ChipPicker(options: [("mine", "Mine"), ("given", "I gave")] + (canGive ? [("team", "The team")] : []), selection: $scope)
                Spacer()
                Picker("", selection: $status) { Text("open").tag("open"); Text("done").tag("done"); Text("all").tag("all") }.pickerStyle(.segmented).frame(maxWidth: 200)
                Button(canGive ? "New to-do" : "Add") { adding = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
            if let list {
                LoadableView(list.state, retry: { list.load() }, isEmpty: { $0.records.isEmpty }, empty: { EmptyState("Nothing here", symbol: "checkmark.circle") }) { d in
                    ListCard { ForEach(d.records) { t in todoRow(t) } }
                }
            }
        }
        .task { if list == nil { await load() } }
        .onChange(of: scope) { _, _ in Task { await load() } }
        .onChange(of: status) { _, _ in Task { await load() } }
        .refreshable { await load() }
        .sheet(isPresented: $adding) { AddTodoSheet(canGive: canGive) { adding = false; Task { await load() } } }
        .sheet(item: $doing) { t in
            MeSheet(t.string("title") ?? "Done", primary: "Done", busy: busy, action: { await update(t, ["status": .string("done"), "done_note": doneNote.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? .null : .string(doneNote.trimmingCharacters(in: .whitespacesAndNewlines))]); doing = nil }) {
                FieldLabel("Anything to say about it? (optional\(t["creator"]?["name"]?.string.map { !t.flag("gave") ? "; \($0) will see it" : "" } ?? ""))") {
                    TextField("", text: $doneNote, axis: .vertical).lineLimit(3...6).textFieldStyle(.roundedBorder)
                }
            }
        }
    }

    private var subtitle: String? {
        guard let d = list?.value else { return nil }
        let overdue = d["overdue"]?.int ?? 0, open = d["open"]?.int ?? 0
        return overdue > 0 ? "\(overdue) overdue · \(open) open" : "\(open) open"
    }

    private func load() async {
        let c = env.session.client, s = scope, st = status
        list = Resource { try await c.get("/v1/admin/todos", query: ["scope": s, "status": st]).json ?? .null }
        await list?.reload()
    }

    private func todoRow(_ t: Record) -> some View {
        let st = t.status ?? "open"
        let mine = t.flag("mine"), gave = t.flag("gave"), overdue = t.flag("overdue")
        let creatorIsOwner = t["creator"]?["id"]?.int == t["user"]?["id"]?.int
        let canToggle = st == "open" || ((mine || gave) && st != "cancelled")
        return ListRow {
            HStack(alignment: .top, spacing: Theme.Space.m) {
                Button {
                    if st == "open" { doneNote = ""; doing = t } else { Task { await update(t, ["status": .string("open")]) } }
                } label: {
                    ZStack {
                        Circle().strokeBorder(st == "done" ? Theme.Palette.success : overdue ? Theme.Palette.danger : Theme.line, lineWidth: 2)
                        if st == "done" { Circle().fill(Theme.Palette.success); Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white) }
                    }
                    .frame(width: 24, height: 24)
                }
                .buttonStyle(.plain).disabled(!canToggle || busy).accessibilityLabel(st == "open" ? "Mark done" : "Reopen")
                VStack(alignment: .leading, spacing: 2) {
                    Text(t.string("title") ?? "").font(Theme.Font.body.weight(.medium)).strikethrough(st == "done")
                    if let d = t.string("details") { Text(d).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary) }
                    HStack(spacing: 0) {
                        Text("\(scope != "mine" ? (t["user"]?["name"]?.string.map { "\($0) · " } ?? "") : "")\(t["creator"]?["name"]?.string != nil && !gave && !creatorIsOwner ? "from \(t["creator"]?["name"]?.string ?? "") · " : "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        if let due = t.date("due_at") {
                            Text("\(overdue ? "overdue · " : "by ")\(MeFormat.dayTime(due))").font(Theme.Font.caption).foregroundStyle(overdue ? Theme.Palette.danger : Theme.textSecondary).fontWeight(overdue ? .semibold : .regular)
                        } else {
                            Text("no due time").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                        Text(st == "done" ? " · done \(MeFormat.dayTime(t.date("done_at")))\(t.string("done_note").map { " — \($0)" } ?? "")" : st == "cancelled" ? " · cancelled" : "").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    }
                }
                Spacer()
                if st == "open" && gave && !mine {
                    Button("Cancel") { Task { await update(t, ["status": .string("cancelled")]) } }.font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger)
                }
                if st != "open" && (gave || (mine && (t["creator"]?.isNull ?? true || creatorIsOwner))) {
                    Button("Remove") { Task { await remove(t) } }.font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            .opacity(st == "open" ? 1 : 0.6)
        }
    }

    private func update(_ t: Record, _ fields: [String: JSONValue]) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.patch("/v1/admin/todos/\(t.id)", json: .object(fields))
            env.toasts.show(r.json?["message"]?.string ?? "Saved")
            await load()
        } catch { env.toasts.error(error) }
    }

    private func remove(_ t: Record) async {
        do { _ = try await env.session.client.delete("/v1/admin/todos/\(t.id)"); await load() } catch { env.toasts.error(error) }
    }
}

/// New to-do (TodosController::store: title, details, due_at, user_id via todos/people).
struct AddTodoSheet: View {
    @Environment(AppEnvironment.self) private var env
    let canGive: Bool
    let onDone: () -> Void
    @State private var title = ""
    @State private var details = ""
    @State private var hasDue = false
    @State private var dueAt = Date().addingTimeInterval(3600)
    @State private var userId: Int?
    @State private var people: [Record] = []
    @State private var busy = false

    var body: some View {
        MeSheet("New to-do", primary: userId == nil ? "Add" : "Give", disabled: title.trimmingCharacters(in: .whitespaces).isEmpty, busy: busy, action: { await save() }) {
            FieldLabel("What") { TextField("", text: $title).textFieldStyle(.roundedBorder) }
            FieldLabel("Details (optional)") { TextField("", text: $details, axis: .vertical).lineLimit(3...6).textFieldStyle(.roundedBorder) }
            Toggle("By when", isOn: $hasDue)
            if hasDue { DatePicker("By when", selection: $dueAt).labelsHidden() }
            if canGive {
                Picker("For", selection: $userId) {
                    Text("Me").tag(Int?.none)
                    ForEach(people) { p in Text(p.string("name") ?? "").tag(p.int("id")) }
                }
            }
        }
        .task { if canGive, let j = try? await env.session.client.get("/v1/admin/todos/people").json { people = j.records } }
    }

    private func save() async {
        busy = true; defer { busy = false }
        let d = details.trimmingCharacters(in: .whitespacesAndNewlines)
        let body: JSONValue = .object([
            "title": .string(title.trimmingCharacters(in: .whitespacesAndNewlines)),
            "details": d.isEmpty ? .null : .string(d),
            "due_at": hasDue ? .string(MeFormat.dateTimeString(dueAt)) : .null,
            "user_id": userId.map { .number(Double($0)) } ?? .null,
        ])
        do {
            let r = try await env.session.client.post("/v1/admin/todos", json: body)
            env.toasts.show(r.json?["message"]?.string ?? "Added")
            onDone()
        } catch { env.toasts.error(error) }
    }
}

// MARK: - Colleagues (DirectoryPage.tsx): who is who, how to reach them, my own switches, start a DM

struct ColleaguesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var query = ""
    @State private var department = ""
    @State private var list: Resource<JSONValue>?
    @State private var busy = false

    var body: some View {
        Page("Colleagues", subtitle: "Everyone at Bethany House and how to reach them.") {
            HStack {
                TextField("Search by name, job or department…", text: $query).textFieldStyle(.roundedBorder)
                if let depts = list?.value?["departments"]?.array?.compactMap(\.string), !depts.isEmpty {
                    Picker("Department", selection: $department) {
                        Text("All departments").tag("")
                        ForEach(depts, id: \.self) { Text($0).tag($0) }
                    }
                    .labelsHidden()
                }
            }
            if let me = list?.value?["me"], me.object != nil { mySwitches(me) }
            if let list {
                LoadableView(list.state, retry: { list.load() }) { d in
                    let rows = d.records.filter { department.isEmpty || $0.string("department") == department }
                    if rows.isEmpty {
                        Text("Nobody matches.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).frame(maxWidth: .infinity)
                    }
                    TileGrid(minWidth: 300) { ForEach(rows) { c in card(c) } }
                }
            }
        }
        .task { if list == nil { await load() } }
        .onChange(of: query) { _, _ in Task { try? await Task.sleep(for: .milliseconds(300)); await load() } }
        .refreshable { await load() }
    }

    private func load() async {
        let c = env.session.client, q = query.trimmingCharacters(in: .whitespaces)
        list = Resource { try await c.get("/v1/admin/directory", query: ["q": q.isEmpty ? nil : q]).json ?? .null }
        await list?.reload()
    }

    private func mySwitches(_ me: JSONValue) -> some View {
        SectionCard {
            Text("What colleagues see about you:").font(Theme.Font.secondary.weight(.semibold))
            HStack(spacing: Theme.Space.l) {
                ForEach([("show_phone", "my phone"), ("show_email", "my email"), ("show_birthday", "my birthday")], id: \.0) { key, label in
                    Toggle(label, isOn: Binding(get: { me[key]?.bool ?? false }, set: { v in Task { await saveMine(key, v) } }))
                        .font(Theme.Font.secondary).disabled(busy)
                        #if os(macOS)
                        .toggleStyle(.checkbox)
                        #endif
                }
            }
            Text("Your name, job, department and outlet always show.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }

    private func saveMine(_ key: String, _ value: Bool) async {
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.put("/v1/admin/me/directory", json: .object([key: .bool(value)]))
            env.toasts.show(r.json?["message"]?.string ?? "Saved")
            await list?.reload()
        } catch { env.toasts.error(error) }
    }

    private func card(_ c: Record) -> some View {
        let isMe = c.flag("is_me")
        return HStack(alignment: .top, spacing: Theme.Space.m) {
            PersonAvatar(name: c.string("name"), url: c.string("avatar_url"), size: 48)
            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 6) {
                    Text(c.string("name") ?? "").font(Theme.Font.body.weight(.semibold)).lineLimit(1)
                    if isMe { StatusBadge("you", tone: .info) }
                }
                let line = [c.string("job_title"), c.string("department")].compactMap { $0 }.joined(separator: " · ")
                let outlets = c.strings("outlets")
                Text("\(line.isEmpty ? "—" : line)\(outlets.isEmpty ? "" : " · \(outlets.joined(separator: ", "))")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary).lineLimit(2)
                HStack(spacing: Theme.Space.m) {
                    if let phone = c.string("phone"), let u = URL(string: "tel:\(phone.filter { !$0.isWhitespace })") { Link("📞 \(phone)", destination: u) }
                    if let email = c.string("email"), let u = URL(string: "mailto:\(email)") { Link("✉️ \(email)", destination: u).lineLimit(1) }
                }
                .font(Theme.Font.caption)
                HStack(spacing: Theme.Space.m) {
                    if let b = c.string("birthday") { Text("🎂 \(b)").foregroundStyle(Theme.textSecondary) }
                    if let s = c.string("since") { Text("since \(s)").foregroundStyle(Theme.textTertiary) }
                }
                .font(Theme.Font.caption)
                if !isMe {
                    Button("Message ›") { Task { await message(c) } }.font(Theme.Font.caption.weight(.semibold)).disabled(busy)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(Theme.Space.l).frame(maxWidth: .infinity, alignment: .leading)
        .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: Theme.Radius.card, style: .continuous).stroke(isMe ? Theme.Palette.brandTint : Theme.line, lineWidth: 1))
    }

    /// Open or find the DM (`POST channels/dm {user_id}`) and go to it.
    private func message(_ c: Record) async {
        guard let uid = c.int("id") else { return }
        busy = true; defer { busy = false }
        do {
            let r = try await env.session.client.post("/v1/admin/channels/dm", json: .object(["user_id": .number(Double(uid))]))
            if let id = r.json?["channel"]?["id"]?.int { env.navigator.go(.comms(channelId: id)) }
        } catch { env.toasts.error(error) }
    }
}
