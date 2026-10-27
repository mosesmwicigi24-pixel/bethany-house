import SwiftUI
import BethanyKit

/// Roles (GET/POST/PUT/DELETE /roles, POST /roles/{id}/permissions|duplicate —
/// writes need roles.edit + step-up) and the permission matrix (GET /permissions).
struct RolesView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var editing: RoleDraft?
    @State private var matrixFor: Record?

    var body: some View {
        Page("Roles", subtitle: "Who may do what. Navigation follows permission; the server enforces every one.") {
            if env.session.can("roles.edit") {
                HStack { Spacer(); Button { editing = RoleDraft() } label: { Label("New role", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            RemoteJSON("/v1/admin/roles", version: version) { j, reload in
                ForEach(j["data"]?.records ?? []) { r in
                    SupportListRow("\(r.string("display_name") ?? r.string("name") ?? "") (\(r.string("name") ?? ""))", subtitle: "\(r.string("description") ?? "") · \(r["users_count"]?.int ?? 0) people · \(r["permissions"]?.array?.count ?? 0) permissions · \(r.string("user_type") ?? "")") {
                        HStack {
                            if r["is_system"]?.bool == true { Pill("system", .info) }
                            if r["is_active"]?.bool == false { Pill("inactive", .neutral) }
                            Button("Permissions") { matrixFor = r }
                            if env.session.can("roles.edit") {
                                Button("Edit") { editing = RoleDraft(record: r) }
                                AsyncButton { let x = try await env.session.client.post("/v1/admin/roles/\(r.id)/duplicate"); toastResult(env, x); reload() } label: { Image(systemName: "doc.on.doc") }
                                if r["is_system"]?.bool != true {
                                    ConfirmButton("Delete role", message: "People holding it lose it.", destructive: true, action: {
                                        do { let x = try await env.session.client.delete("/v1/admin/roles/\(r.id)"); toastResult(env, x); reload() } catch { env.toasts.error(error) }
                                    }) { Image(systemName: "trash") }
                                }
                            }
                        }
                    }
                }
            }
        }
        .sheet(item: $editing) { d in RoleSheet(draft: d) { version += 1 } }
        .sheet(item: $matrixFor) { r in PermissionMatrixSheet(role: r) { version += 1 } }
    }
}

struct RoleDraft: Identifiable {
    var id: Int?
    var name = ""
    var displayName = ""
    var description = ""
    var userType = "staff"
    var active = true
    init() {}
    init(record r: Record) { id = r.int("id"); name = r.string("name") ?? ""; displayName = r.string("display_name") ?? ""; description = r.string("description") ?? ""; userType = r.string("user_type") ?? "staff"; active = r["is_active"]?.bool ?? true }
}

struct RoleSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: RoleDraft
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "New role" : "Edit role", canSave: !draft.displayName.isEmpty && (draft.id != nil || !draft.name.isEmpty), save: {
            var pairs: [(String, JSONValue?)] = [("display_name", .string(draft.displayName)), ("description", J.s(draft.description)), ("user_type", .string(draft.userType)), ("is_active", .bool(draft.active))]
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/roles/\(id)", json: J.obj(pairs)) }
            else { pairs.append(("name", .string(draft.name))); r = try await env.session.client.post("/v1/admin/roles", json: J.obj(pairs)) }
            toastResult(env, r); saved()
        }) { e in
            if draft.id == nil { Field("Slug (e.g. floor_supervisor)", $draft.name, error: e?.errors["name"]?.first) }
            Field("Display name", $draft.displayName, error: e?.errors["display_name"]?.first)
            Field("Description", $draft.description, multiline: true)
            OptionPicker("User type", $draft.userType, options: [("staff", "Staff"), ("system", "System"), ("customer", "Customer")])
            ToggleRow("Active", $draft.active)
            Text("Saving asks you to confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
}

/// GET /permissions → {data: [{group, permissions: [{id, name, display_name, description}]}]}; POST /roles/{id}/permissions {permissions: [ids]}
struct PermissionMatrixSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let role: Record
    let saved: () -> Void
    @State private var groups: [Record] = []
    @State private var selected: Set<Int> = []
    @State private var filter = ""

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Text("Permissions · \(role.string("display_name") ?? "")").font(Theme.Font.sectionTitle); Spacer(); Text("\(selected.count) granted").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary); Button("Close") { dismiss() } }
            TextField("Filter", text: $filter).textFieldStyle(.roundedBorder)
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    ForEach(groups) { g in
                        let perms = (g["permissions"]?.records ?? []).filter { filter.isEmpty || ($0.string("name") ?? "").localizedCaseInsensitiveContains(filter) || ($0.string("display_name") ?? "").localizedCaseInsensitiveContains(filter) }
                        if !perms.isEmpty {
                            SectionCard(g.string("group") ?? "General") {
                                HStack {
                                    Button("All") { for p in perms { if let id = p.int("id") { selected.insert(id) } } }.font(Theme.Font.caption)
                                    Button("None") { for p in perms { if let id = p.int("id") { selected.remove(id) } } }.font(Theme.Font.caption)
                                }
                                ForEach(perms) { p in
                                    let id = p.int("id") ?? 0
                                    Toggle(isOn: Binding(get: { selected.contains(id) }, set: { if $0 { selected.insert(id) } else { selected.remove(id) } })) {
                                        VStack(alignment: .leading, spacing: 1) {
                                            Text(p.string("display_name") ?? p.string("name") ?? "").font(Theme.Font.secondary)
                                            Text("\(p.string("name") ?? "")\(p.string("description").map { " — \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                                        }
                                    }
                                    .toggleStyle(.checkbox).disabled(!env.session.can("roles.edit"))
                                }
                            }
                        }
                    }
                }
            }
            if env.session.can("roles.edit") {
                HStack {
                    Spacer()
                    AsyncButton("Save permissions (asks you to confirm it's you)") {
                        let r = try await env.session.client.post("/v1/admin/roles/\(role.id)/permissions", json: J.obj([("permissions", J.ints(Array(selected)))]))
                        toastResult(env, r); saved(); dismiss()
                    }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                }
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 620, minHeight: 600)
        #endif
        .task {
            groups = (try? await env.session.client.json("/v1/admin/permissions"))?["data"]?.records ?? []
            selected = Set(role["permissions"]?.array?.compactMap { $0["id"]?.int } ?? [])
        }
    }
}

// MARK: - Users (GET /users with filters; create/edit/status/reset/promote; sign-in security)

struct UsersView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var list: PagedList<Record>?
    @State private var search = ""
    @State private var status = ""
    @State private var role = ""
    @State private var roles: [Record] = []
    @State private var editing: UserDraft?
    @State private var security: Record?
    @State private var promoting: Record?

    var body: some View {
        Page("Users", subtitle: "Accounts, roles, outlets and sign-in security") {
            HStack(alignment: .bottom) {
                TextField("Search name, email or phone", text: $search).textFieldStyle(.roundedBorder).onSubmit { Task { await reload() } }
                OptionPicker("Status", $status, options: [("", "Any"), ("active", "Active"), ("inactive", "Inactive"), ("suspended", "Suspended")])
                OptionPicker("Role", $role, options: [("", "Any role")] + roles.map { ($0.string("name") ?? "", $0.string("display_name") ?? "") })
                Button("Apply") { Task { await reload() } }
                if env.session.can("users.create") { Button { editing = UserDraft() } label: { Label("New user", systemImage: "plus") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
            }
            if let list {
                LoadableView(list.state, retry: { Task { await list.load() } }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No users match", symbol: "person.slash") }) { rows in
                    LazyVStack(spacing: Theme.Space.s) {
                        ForEach(rows) { u in userRow(u) .onAppear { if u.id == rows.last?.id { Task { await list.loadMore() } } } }
                    }
                    Text("\(list.total) users").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
        }
        .task {
            if list == nil {
                list = PagedList(client: env.session.client, path: "/v1/admin/users", query: ["exclude_type": "customer", "sort_by": "first_name", "sort_order": "asc"])
                roles = (try? await env.session.client.json("/v1/admin/roles"))?["data"]?.records ?? []
                await reload()
            }
        }
        .sheet(item: $editing) { d in UserSheet(draft: d, roles: roles) { Task { await reload() } } }
        .sheet(item: $security) { u in UserSecuritySheet(user: u) }
        .sheet(item: $promoting) { u in PromoteSheet(user: u, roles: roles) { Task { await reload() } } }
    }

    private func reload() async {
        list?.query = ["exclude_type": "customer", "sort_by": "first_name", "sort_order": "asc", "search": search.isEmpty ? nil : search, "status": status.isEmpty ? nil : status, "role": role.isEmpty ? nil : role]
        await list?.load()
    }

    private func userRow(_ u: Record) -> some View {
        SupportListRow(u.string("name") ?? "\(u.string("first_name") ?? "") \(u.string("last_name") ?? "")", subtitle: "\(u.string("email") ?? "") · \((u["roles"]?.array ?? []).compactMap { $0["display_name"]?.string ?? $0["name"]?.string }.joined(separator: ", ")) · \(u["outlet"]?["name"]?.string ?? "no outlet") · last sign-in \(u.string("last_login_at").map(Day.dateTime) ?? "never")") {
            HStack {
                StatusBadge(u.status)
                if u["locked_at"]?.string != nil { Pill("locked", .danger) }
                if u["two_factor_enabled"]?.bool == true { Pill("2FA", .success) } else if u["must_setup_2fa"]?.bool == true { Pill("2FA pending", .warning) }
                Menu {
                    if env.session.can("users.edit") {
                        Button("Edit") { editing = UserDraft(record: u) }
                        Menu("Status") {
                            ForEach(["active", "inactive", "suspended"], id: \.self) { s in
                                Button(s.humanized) { Task { await setStatus(u, s) } }
                            }
                        }
                        AsyncButton("Send password reset") { let r = try await env.session.client.post("/v1/admin/users/\(u.id)/reset-password"); toastResult(env, r) }
                        if u.string("user_type") == "customer" { Button("Promote to staff") { promoting = u } }
                    }
                    Button("Sign-in security") { security = u }
                    if env.session.can("users.delete") {
                        Button("Delete", role: .destructive) { Task { do { let r = try await env.session.client.delete("/v1/admin/users/\(u.id)"); toastResult(env, r); await reload() } catch { env.toasts.error(error) } } }
                    }
                } label: { Image(systemName: "ellipsis.circle") }
            }
        }
    }

    private func setStatus(_ u: Record, _ s: String) async {
        do { let r = try await env.session.client.put("/v1/admin/users/\(u.id)/status", json: J.obj([("status", .string(s))])); toastResult(env, r); await reload() } catch { env.toasts.error(error) }
    }
}

struct UserDraft: Identifiable {
    var id: Int?
    var f: [String: String] = ["user_type": "staff", "status": "active"]
    var roleIds: Set<Int> = []
    var outletId: Int?
    var reportsTo: Int?
    var must2fa = false
    init() {}
    init(record u: Record) {
        id = u.int("id")
        for k in ["first_name", "last_name", "email", "phone", "user_type", "status"] { f[k] = u.string(k) ?? "" }
        roleIds = Set(u["roles"]?.array?.compactMap { $0["id"]?.int } ?? [])
        outletId = u["outlet"]?["id"]?.int
        reportsTo = u["reports_to"]?.int
        must2fa = u["must_setup_2fa"]?.bool ?? false
    }
}

/// POST /users, PUT /users/{id}. Roles go in `role_ids`; the server allows only a super admin to change them.
struct UserSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State var draft: UserDraft
    let roles: [Record]
    let saved: () -> Void
    var body: some View {
        EditSheet(draft.id == nil ? "New user" : "Edit user", canSave: !(draft.f["first_name"] ?? "").isEmpty && !(draft.f["email"] ?? "").isEmpty, save: {
            var pairs: [(String, JSONValue?)] = ["first_name", "last_name", "email", "phone", "user_type", "status"].map { ($0, J.s(draft.f[$0]) as JSONValue?) }
            pairs.append(("outlet_id", J.i(draft.outletId)))
            pairs.append(("reports_to", J.i(draft.reportsTo)))
            pairs.append(("must_setup_2fa", .bool(draft.must2fa)))
            if env.session.me?.isSuperAdmin == true || draft.id == nil { pairs.append(("role_ids", J.ints(Array(draft.roleIds)))) }
            if let p = draft.f["password"], !p.isEmpty { pairs.append(("password", .string(p))); pairs.append(("password_confirmation", J.s(draft.f["password_confirmation"]))) }
            let r: APIClient.Response
            if let id = draft.id { r = try await env.session.client.put("/v1/admin/users/\(id)", json: J.obj(pairs)) } else { r = try await env.session.client.post("/v1/admin/users", json: J.obj(pairs)) }
            toastResult(env, r); saved()
        }) { e in
            HStack { Field("First name", bind("first_name"), error: e?.errors["first_name"]?.first); Field("Last name", bind("last_name"), error: e?.errors["last_name"]?.first) }
            Field("Email", bind("email"), error: e?.errors["email"]?.first)
            Field("Phone", bind("phone"), error: e?.errors["phone"]?.first)
            HStack {
                OptionPicker("Type", bind("user_type"), options: [("staff", "Staff"), ("system", "System")])
                OptionPicker("Status", bind("status"), options: [("active", "Active"), ("inactive", "Inactive")] + (draft.id == nil ? [] : [("suspended", "Suspended")]))
            }
            OutletChoice("Outlet", $draft.outletId, allowAll: true)
            StaffPicker("Reports to", $draft.reportsTo, allowNone: true)
            ToggleRow("Must set up two-step sign-in", $draft.must2fa)
            SecureFieldRow(label: draft.id == nil ? "Password" : "New password (optional)", text: bind("password"), error: e?.errors["password"]?.first)
            SecureFieldRow(label: "Confirm password", text: bind("password_confirmation"))
            Text("Roles").font(Theme.Font.sectionTitle)
            if env.session.me?.isSuperAdmin != true { Text("Only a super administrator changes who holds which role.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
            ForEach(roles) { r in
                let id = r.int("id") ?? 0
                Toggle(isOn: Binding(get: { draft.roleIds.contains(id) }, set: { if $0 { draft.roleIds.insert(id) } else { draft.roleIds.remove(id) } })) {
                    Text("\(r.string("display_name") ?? "") (\(r.string("name") ?? ""))").font(Theme.Font.secondary)
                }.toggleStyle(.checkbox).disabled(env.session.me?.isSuperAdmin != true && draft.id != nil)
            }
        }
    }
    private func bind(_ k: String) -> Binding<String> { Binding(get: { draft.f[k] ?? "" }, set: { draft.f[k] = $0 }) }
}

/// POST /users/{id}/promote-to-staff {role_ids, outlet_id}
struct PromoteSheet: View {
    @Environment(AppEnvironment.self) private var env
    let user: Record
    let roles: [Record]
    let done: () -> Void
    @State private var roleIds: Set<Int> = []
    @State private var outlet: Int?
    var body: some View {
        EditSheet("Promote \(user.string("name") ?? "") to staff", saveLabel: "Promote", save: {
            let r = try await env.session.client.post("/v1/admin/users/\(user.id)/promote-to-staff", json: J.obj([("role_ids", J.ints(Array(roleIds))), ("outlet_id", J.i(outlet))]))
            toastResult(env, r); done()
        }) { _ in
            OutletChoice("Outlet", $outlet, allowAll: true)
            ForEach(roles) { r in
                let id = r.int("id") ?? 0
                Toggle(isOn: Binding(get: { roleIds.contains(id) }, set: { if $0 { roleIds.insert(id) } else { roleIds.remove(id) } })) { Text(r.string("display_name") ?? "").font(Theme.Font.secondary) }.toggleStyle(.checkbox)
            }
        }
    }
}

/// Another person's sign-in (UserSecurityController): sessions, devices, 2FA reset (step-up), PIN reset, unlock.
struct UserSecuritySheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let user: Record
    @State private var version = 0
    @State private var reason = ""

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack { Text("Sign-in security · \(user.string("name") ?? "")").font(Theme.Font.sectionTitle); Spacer(); Button("Close") { dismiss() } }
            ScrollView {
                VStack(alignment: .leading, spacing: Theme.Space.m) {
                    SectionCard("Account") {
                        HStack {
                            if user["locked_at"]?.string != nil {
                                Text("Locked\(user.string("lock_reason").map { ": \($0)" } ?? "")").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
                                AsyncButton("Unlock") { let r = try await env.session.client.post("/v1/admin/users/\(user.id)/unlock"); toastResult(env, r) }
                            } else { Text("Not locked").font(Theme.Font.secondary) }
                        }
                        HStack {
                            AsyncButton("Reset terminal PIN") { let r = try await env.session.client.post("/v1/admin/users/\(user.id)/terminal-pin/reset"); toastResult(env, r) }
                        }
                        HStack(alignment: .bottom) {
                            Field("Reason for 2FA reset", $reason)
                            ConfirmButton("Reset two-step sign-in", message: "They set it up again at their next sign-in. You will be asked to confirm it is you.", destructive: true, action: {
                                do { let r = try await env.session.client.post("/v1/admin/users/\(user.id)/2fa/reset", json: J.obj([("reason", .string(reason))])); toastResult(env, r) } catch { env.toasts.error(error) }
                            }) { Text("Reset 2FA") }.disabled(reason.isEmpty)
                        }
                    }
                    RemoteJSON("/v1/admin/users/\(user.id)/sessions", version: version) { j, reload in
                        SectionCard("Sessions") {
                            let rows = j["data"]?.records ?? []
                            if rows.isEmpty { Text("No sessions").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                            ForEach(rows) { s in
                                HStack {
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(s["device"]?["label"]?.string ?? s.string("agent") ?? "Session").font(Theme.Font.secondary)
                                        Text("\(s.string("ip") ?? "") · last used \(Day.dateTime(s.string("last_used")))\(s["locked"]?.bool == true ? " · PIN-locked" : "")").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                                    }
                                    Spacer()
                                    AsyncButton("Revoke") { let r = try await env.session.client.post("/v1/admin/users/\(user.id)/sessions/\(s.id)/revoke"); toastResult(env, r); reload() }
                                }
                            }
                            if !rows.isEmpty { AsyncButton("Revoke all", role: .destructive) { let r = try await env.session.client.post("/v1/admin/users/\(user.id)/sessions/revoke-all"); toastResult(env, r); reload() } }
                        }
                    }
                    RemoteJSON("/v1/admin/users/\(user.id)/devices", version: version) { j, reload in
                        SectionCard("Named devices") {
                            let rows = j["data"]?.records ?? []
                            if rows.isEmpty { Text("No devices").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                            ForEach(rows) { d in DeviceRowView(userId: user.id, device: d, reload: reload) }
                        }
                    }
                }
            }
        }
        .padding(Theme.Space.xl)
        #if os(macOS)
        .frame(minWidth: 560, minHeight: 520)
        #endif
    }
}

struct DeviceRowView: View {
    @Environment(AppEnvironment.self) private var env
    let userId: String
    let device: Record
    let reload: () -> Void
    @State private var name = ""
    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 1) {
                Text(device.string("label") ?? "Device").font(Theme.Font.secondary)
                Text("\(device.string("facts") ?? "") · \(device.int("active_sessions") ?? 0) sessions · last seen \(Day.dateTime(device.string("last_seen")))").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            Spacer()
            TextField("Rename", text: $name).textFieldStyle(.roundedBorder).frame(width: 140)
            AsyncButton("Name") { let r = try await env.session.client.put("/v1/admin/users/\(userId)/devices/\(device.id)", json: J.obj([("name", J.s(name))])); toastResult(env, r); reload() }.disabled(name.isEmpty)
            AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/users/\(userId)/devices/\(device.id)"); toastResult(env, r); reload() } label: { Image(systemName: "trash") }
        }
        .onAppear { name = device.string("name") ?? "" }
    }
}
