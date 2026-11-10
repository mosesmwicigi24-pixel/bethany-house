import SwiftUI
import BethanyKit

/// Profile & security (/settings/profile): the person, their sessions and
/// named devices, password, and sign-in safety (2FA status, recovery codes).
@MainActor
enum ProfileFeature {
    static func register() {
        FeatureRegistry.register(.profile) { _ in AnyView(ProfileView()) }
    }
}

struct ProfileView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var sessions: Resource<[Record]>?
    @State private var devices: Resource<[ProfileDevice]>?
    @State private var showPassword = false
    @State private var showEdit = false
    @State private var renaming: ProfileDevice?
    /// The console's profile tabs, less 2FA/passkeys/PIN (their own slice): see docs/MIGRATION_PROGRESS.md.
    enum Tab: Hashable { case profile, employment, activity }
    @State private var tab: Tab = .profile

    var body: some View {
        Page("My profile") {
            Tabs(selection: $tab, items: [(.profile, "Profile & security"), (.employment, "My employment"), (.activity, "My activity")]).frame(maxWidth: 520)
            switch tab {
            case .employment: MyEmploymentPanel()
            case .activity: MyActivityPanel()
            case .profile: profileTab
            }
        }
        .task {
            let c = env.session.client
            if sessions == nil {
                sessions = Resource { try (await c.get("/v1/admin/profile/sessions").json?["data"]?.decode([Record].self)) ?? [] }
                devices = Resource { ProfileDevice.rows(try await c.get("/v1/admin/profile/devices").json ?? .null) }
                sessions?.load(); devices?.load()
            }
        }
        .sheet(isPresented: $showPassword) { ChangePasswordView() }
        .sheet(isPresented: $showEdit) { EditProfileSheet() }
        .sheet(item: $renaming) { d in RenameDeviceSheet(device: d) { devices?.load() } }
    }

    @ViewBuilder
    private var profileTab: some View {
            if let me = env.session.me {
                SectionCard {
                    HStack(spacing: Theme.Space.l) {
                        Image(systemName: "person.crop.circle.fill").font(.system(size: 44)).foregroundStyle(Theme.Palette.brand)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(me.name).font(Theme.Font.display(20))
                            Text(me.email).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                            HStack { ForEach(me.roleNames, id: \.self) { StatusBadge($0, tone: .info) } }
                        }
                        Spacer()
                    }
                    if let outlet = me.outlet { DetailRow("Outlet", outlet.name) }
                    if let policy = me.sessionPolicy {
                        DetailRow("Session limits", "Idle \(policy.idleMinutes) min (\(policy.onIdle == .pinLock ? "PIN lock" : "sign out")), \(policy.absoluteMinutes / 60) h absolute")
                    }
                    if let exp = env.session.tokenExpiresAt { DetailRow("This sign-in renews until") { DateText(exp) } }
                    Button("Edit details & preferences…") { showEdit = true }.font(Theme.Font.secondary)
                }

                SectionCard("Sign-in safety") {
                    DetailRow("Two-step sign-in") {
                        StatusBadge(me.twoFactorEnabled ? "on" : (me.twoFactorRequired ? "required" : "off"), tone: me.twoFactorEnabled ? .success : (me.twoFactorRequired ? .warning : .neutral))
                    }
                    if me.twoFactorEnabled, let left = me.recoveryCodesLeft {
                        DetailRow("Recovery codes left", "\(left)")
                    }
                    DetailRow("Terminal PIN") { StatusBadge(me.terminalPinSet ? "set" : "not set", tone: me.terminalPinSet ? .success : .neutral) }
                    Button("Change password…") { showPassword = true }
                    Text("Two-step setup, passkeys and the terminal PIN are managed from Setup › Profile in the console until their native screens ship; passkeys registered there already work for step-up here.")
                        .font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }

                if let sessions {
                    SectionCard("Sessions") {
                        LoadableView(sessions.state, retry: { sessions.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No other sessions", symbol: "desktopcomputer") }) { rows in
                            ForEach(rows) { s in
                                HStack {
                                    Image(systemName: s["is_current"]?.bool == true ? "checkmark.circle.fill" : "desktopcomputer").foregroundStyle(s["is_current"]?.bool == true ? Theme.Palette.success : Theme.textTertiary)
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(s.string("device") ?? s.string("agent") ?? "Session").font(Theme.Font.body).lineLimit(1)
                                        Text("\(s.string("ip") ?? "") · last used \(s.string("last_used") ?? "—")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                    }
                                    Spacer()
                                    if s["is_current"]?.bool != true {
                                        Button("Revoke") { Task { await revoke(s.id) } }.font(Theme.Font.secondary)
                                    }
                                }
                                .padding(.vertical, 4)
                            }
                            ConfirmButton("Revoke all other sessions", message: "Every other device will be signed out.", destructive: true, action: { await revokeAll() }) { Text("Revoke all other sessions") }
                        }
                    }
                }

                if let devices {
                    SectionCard("Named devices") {
                        LoadableView(devices.state, retry: { devices.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No named devices yet", symbol: "iphone") }) { rows in
                            ForEach(rows) { d in
                                HStack {
                                    Image(systemName: "iphone").foregroundStyle(Theme.textTertiary)
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(d.name ?? "Not named").font(Theme.Font.body).italic(d.name == nil)
                                        Text([d.label, d.lastSeen.map { "last seen \($0)" }, d.nameSource == "admin" ? d.namedBy.map { "named by \($0)" } : nil].compactMap { $0 }.joined(separator: " · "))
                                            .font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                                    }
                                    Spacer()
                                    if d.isCurrent { StatusBadge("this device", tone: .success) }
                                    if d.canRename { Button(d.name == nil ? "Name it" : "Rename") { renaming = d }.font(Theme.Font.secondary) }
                                    if d.canRemove {
                                        ConfirmButton("Remove", message: "Remove \(d.label)? Its sessions end, and the next sign-in on it is treated as a new device.", destructive: true, action: { await remove(d) }) { Text("Remove") }
                                            .font(Theme.Font.secondary)
                                    }
                                }
                                .padding(.vertical, 4)
                            }
                        }
                    }
                }
            }
    }

    private func remove(_ d: ProfileDevice) async {
        do { let r = try await env.session.client.delete("/v1/admin/profile/devices/\(d.id)"); toastResult(env, r, fallback: "Device removed"); devices?.load() } catch { env.toasts.error(error) }
    }

    private func revoke(_ id: String) async {
        do { _ = try await env.session.client.post("/v1/admin/profile/sessions/\(id)/revoke"); env.toasts.show("Session revoked"); sessions?.load() } catch { env.toasts.error(error) }
    }

    private func revokeAll() async {
        do { _ = try await env.session.client.post("/v1/admin/profile/sessions/revoke-all"); env.toasts.show("Other sessions revoked"); sessions?.load() } catch { env.toasts.error(error) }
    }
}

struct ChangePasswordView: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    @State private var current = ""
    @State private var new = ""
    @State private var confirm = ""
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Change password").font(Theme.Font.sectionTitle)
            SecureField("Current password", text: $current).textFieldStyle(.roundedBorder)
            FieldError(error?.errors["current_password"]?.first)
            SecureField("New password", text: $new).textFieldStyle(.roundedBorder)
            FieldError(error?.errors["password"]?.first)
            SecureField("Confirm new password", text: $confirm).textFieldStyle(.roundedBorder)
            if let e = error, e.errors.isEmpty { Text(e.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            HStack {
                Spacer()
                Button("Cancel") { dismiss() }
                Button("Change") { Task { await submit() } }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || new.isEmpty || new != confirm)
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 380)
    }

    private func submit() async {
        busy = true
        defer { busy = false }
        do {
            _ = try await env.session.client.post("/v1/admin/profile/password", json: .object([
                "current_password": .string(current), "password": .string(new), "password_confirmation": .string(confirm),
            ]))
            env.toasts.show("Password changed")
            dismiss()
        } catch let e as APIError { error = e } catch { self.error = APIError.transport(error.localizedDescription) }
    }
}
