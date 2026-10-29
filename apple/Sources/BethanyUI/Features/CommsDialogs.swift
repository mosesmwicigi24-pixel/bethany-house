import SwiftUI
import BethanyKit

/// Sheets and the details column for Messages: new space (also from an
/// audience), new DM, the channel's members and settings, archive,
/// dismiss/undismiss for order threads, and the quick-replies manager.

/// POST /channels {name, description, is_private, member_ids}; GET /channels/audiences (attendance.view_team)
struct NewSpaceSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let store: CommsStore
    @State private var name = ""
    @State private var desc = ""
    @State private var isPrivate = false
    @State private var members: Set<Int> = []
    @State private var staff: [StaffOption] = []
    @State private var audiences: [JSONValue] = []
    @State private var filter = ""

    var body: some View {
        EditSheet("New space", saveLabel: "Create", canSave: !name.trimmingCharacters(in: .whitespaces).isEmpty, save: {
            let r = try await env.session.client.post("/v1/admin/channels", json: J.obj([
                ("name", .string(name)), ("description", J.sOpt(desc)), ("is_private", .bool(isPrivate)), ("member_ids", J.ints(Array(members))),
            ]))
            if let c = r.json?["channel"] { let ch = ChatChannel(json: c); store.upsert(ch); store.selectedId = ch.id }
            env.toasts.show("Space created")
        }) { e in
            Field("Name", $name, placeholder: "Front counter", error: e?.errors["name"]?.first)
            Field("Description", $desc)
            ToggleRow("Private (invite only)", $isPrivate)
            if !audiences.isEmpty {
                HStack {
                    Text("Add everyone").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                    ForEach(Array(audiences.enumerated()), id: \.offset) { _, a in
                        Button(a["label"]?.string ?? "") { for p in a["people"]?.array ?? [] { if let id = p["id"]?.int { members.insert(id) } } }.buttonStyle(.bordered).controlSize(.small)
                    }
                }
            }
            MemberChooser(staff: staff, selected: $members, filter: $filter)
        }
        .task {
            staff = (try? await fetchStaffOptions(env)) ?? []
            if env.session.can("attendance.view_team") { audiences = (try? await env.session.client.json("/v1/admin/channels/audiences"))?["audiences"]?.array ?? [] }
        }
    }
}

struct MemberChooser: View {
    let staff: [StaffOption]
    @Binding var selected: Set<Int>
    @Binding var filter: String
    var exclude: Set<Int> = []
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Members (\(selected.count))").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
            TextField("Filter people", text: $filter).textFieldStyle(.roundedBorder)
            ScrollView {
                VStack(alignment: .leading, spacing: 2) {
                    ForEach(staff.filter { !exclude.contains($0.id) && (filter.isEmpty || $0.name.localizedCaseInsensitiveContains(filter)) }) { s in
                        Toggle(isOn: Binding(get: { selected.contains(s.id) }, set: { if $0 { selected.insert(s.id) } else { selected.remove(s.id) } })) {
                            HStack { Text(s.name).font(Theme.Font.secondary); if let o = s.outlet { Text(o).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) } }
                        }
                        .toggleStyle(.checkbox)
                    }
                }
            }
            .frame(maxHeight: 220)
        }
    }
}

#if os(iOS)
extension ToggleStyle where Self == SwitchToggleStyle {
    /// macOS has `.checkbox`; iOS does not. The switch is the touch equivalent.
    static var checkbox: SwitchToggleStyle { SwitchToggleStyle() }
}
#endif

/// POST /channels/dm {user_id}
struct NewDMSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let store: CommsStore
    @State private var staff: [StaffOption] = []
    @State private var filter = ""

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Text("Message a colleague").font(Theme.Font.sectionTitle); Spacer(); Button("Cancel") { dismiss() } }
            TextField("Find a person", text: $filter).textFieldStyle(.roundedBorder)
            List(staff.filter { $0.id != env.session.me?.id && (filter.isEmpty || $0.name.localizedCaseInsensitiveContains(filter)) }) { s in
                AsyncButton {
                    let r = try await env.session.client.post("/v1/admin/channels/dm", json: J.obj([("user_id", J.i(s.id))]))
                    if let c = r.json?["channel"] { let ch = ChatChannel(json: c); store.upsert(ch); open(ch.id) }
                    dismiss()
                } label: { HStack { Avatar(name: s.name, size: 28); Text(s.name); Spacer(); if let o = s.outlet { Text(o).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) } } }
                .buttonStyle(.plain)
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 400, minHeight: 440)
        #endif
        .task { staff = (try? await fetchStaffOptions(env)) ?? [] }
    }

    private func open(_ id: Int) {
        if LayoutProbe.isWide { store.selectedId = id } else { env.navigator.push(.comms(channelId: id)) }
    }
}

// MARK: - Details column (GET /channels/{id}; PATCH; members; DELETE; dismiss)

struct ChannelDetailsPanel: View {
    @Environment(AppEnvironment.self) private var env
    @Bindable var store: CommsStore
    let channel: ChatChannel
    @State private var detail: ChatChannel?
    @State private var name = ""
    @State private var desc = ""
    @State private var adding = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: Theme.Space.m) {
                HStack { Text("Details").font(Theme.Font.sectionTitle); Spacer(); Button { store.showDetails = false } label: { Image(systemName: "xmark") }.buttonStyle(.plain) }
                let ch = detail ?? channel
                if ch.isDM {
                    SectionCard { Text("Direct message with \(ch.name)").font(Theme.Font.secondary) }
                } else {
                    SectionCard("About") {
                        Field("Name", $name)
                        Field("Description", $desc, multiline: true)
                        HStack {
                            AsyncButton("Save") {
                                let r = try await env.session.client.patch("/v1/admin/channels/\(ch.id)", json: J.obj([("name", .string(name)), ("description", J.s(desc))]))
                                if let c = r.json?["channel"] { let updated = ChatChannel(json: c); detail = updated; store.upsert(updated) }
                                env.toasts.show("Saved")
                            }
                            Spacer()
                            if !ch.isContext {
                                AsyncButton(ch.isPrivate ? "Make public" : "Make private") {
                                    let r = try await env.session.client.patch("/v1/admin/channels/\(ch.id)", json: J.obj([("is_private", .bool(!ch.isPrivate))]))
                                    if let c = r.json?["channel"] { let updated = ChatChannel(json: c); detail = updated; store.upsert(updated) }
                                }
                            }
                        }
                    }
                    SectionCard("Members (\(ch.members.count))") {
                        ForEach(Array(ch.members.enumerated()), id: \.offset) { _, m in
                            HStack {
                                Avatar(name: m["name"]?.string ?? "?", size: 24)
                                Text(m["name"]?.string ?? "").font(Theme.Font.secondary)
                                if m["role"]?.string == "admin" { Pill("admin", .info) }
                                Spacer()
                                if let uid = m["id"]?.int, uid != env.session.me?.id {
                                    AsyncButton(role: .destructive) {
                                        let r = try await env.session.client.delete("/v1/admin/channels/\(ch.id)/members/\(uid)")
                                        toastResult(env, r, fallback: "Removed"); await reload()
                                    } label: { Image(systemName: "minus.circle") }.buttonStyle(.plain)
                                }
                            }
                        }
                        Button { adding = true } label: { Label("Add members", systemImage: "person.badge.plus") }
                    }
                    if ch.isContext {
                        SectionCard("Thread") {
                            if let r = ch.contextRoute { Button { env.navigator.go(r) } label: { Label("Open the record", systemImage: "arrow.up.right.square") } }
                            AsyncButton("Hide from my list") {
                                _ = try await env.session.client.post("/v1/admin/channels/\(ch.id)/dismiss")
                                store.remove(ch.id); env.toasts.show("Hidden until the next message")
                            }
                        }
                    } else {
                        ConfirmButton("Archive space", message: "The conversation is closed for everyone. Messages are kept.", destructive: true, action: {
                            do { _ = try await env.session.client.delete("/v1/admin/channels/\(ch.id)"); store.remove(ch.id); env.toasts.show("Space archived") } catch { env.toasts.error(error) }
                        }) { Label("Archive space", systemImage: "archivebox").foregroundStyle(Theme.Palette.danger) }
                    }
                }
            }
            .padding(Theme.Space.m)
        }
        .background(Theme.canvas)
        .task(id: channel.id) { await reload() }
        .sheet(isPresented: $adding) { AddMembersSheet(channel: detail ?? channel) { await reload() } }
    }

    private func reload() async {
        if let j = try? await env.session.client.json("/v1/admin/channels/\(channel.id)")["channel"] {
            let ch = ChatChannel(json: j)
            detail = ch
            name = ch.name
            desc = ch.description ?? ""
        }
    }
}

/// POST /channels/{id}/members {user_id, role} — one call per person, as the console does.
struct AddMembersSheet: View {
    @Environment(AppEnvironment.self) private var env
    let channel: ChatChannel
    let done: () async -> Void
    @State private var staff: [StaffOption] = []
    @State private var selected: Set<Int> = []
    @State private var filter = ""

    var body: some View {
        EditSheet("Add members", saveLabel: "Add \(selected.count)", canSave: !selected.isEmpty, save: {
            for uid in selected { _ = try await env.session.client.post("/v1/admin/channels/\(channel.id)/members", json: J.obj([("user_id", J.i(uid))])) }
            env.toasts.show("Added \(selected.count)")
            await done()
        }) { _ in
            MemberChooser(staff: staff, selected: $selected, filter: $filter, exclude: Set(channel.members.compactMap { $0["id"]?.int }))
        }
        .task { staff = (try? await fetchStaffOptions(env)) ?? [] }
    }
}

// MARK: - Quick replies (GET/POST/PATCH/DELETE /channels/quick-replies)

struct QuickRepliesSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @State private var version = 0
    @State private var editingId: Int?
    @State private var title = ""
    @State private var body_ = ""

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Text("Quick replies").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            Text("Saved messages everyone can insert. {order}, {customer} and {name} are filled in when used.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            RemoteJSON("/v1/admin/channels/quick-replies", version: version, emptyTitle: "None yet", isEmpty: { ($0["quick_replies"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["quick_replies"]?.records ?? []) { q in
                    SupportListRow(q.string("title") ?? "", subtitle: q.string("body")) {
                        HStack {
                            Button("Edit") { editingId = q.int("id"); title = q.string("title") ?? ""; body_ = q.string("body") ?? "" }
                            AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/channels/quick-replies/\(q.id)"); toastResult(env, r, fallback: "Deleted"); reload() } label: { Image(systemName: "trash") }
                        }
                    }
                }
            }
            Divider()
            Text(editingId == nil ? "New quick reply" : "Edit quick reply").font(Theme.Font.sectionTitle)
            Field("Title", $title)
            Field("Message", $body_, multiline: true)
            HStack {
                if editingId != nil { Button("Cancel edit") { editingId = nil; title = ""; body_ = "" } }
                Spacer()
                AsyncButton(editingId == nil ? "Save" : "Update") {
                    let body = J.obj([("title", .string(title)), ("body", .string(body_))])
                    if let id = editingId { _ = try await env.session.client.patch("/v1/admin/channels/quick-replies/\(id)", json: body) }
                    else { _ = try await env.session.client.post("/v1/admin/channels/quick-replies", json: body) }
                    editingId = nil; title = ""; body_ = ""; version += 1
                }
                .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(title.isEmpty || body_.isEmpty)
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 480, minHeight: 480)
        #endif
    }
}
