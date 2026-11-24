import SwiftUI
import BethanyKit
import UniformTypeIdentifiers
import PhotosUI

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
    @State private var photoItem: PhotosPickerItem?
    @State private var uploadingPhoto = false
    @State private var showPassword = false
    @State private var showEdit = false
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
        .onChange(of: photoItem) { _, item in
            guard let item else { return }
            Task { await uploadPhoto(item) }
        }
        .sheet(isPresented: $showPassword) { ChangePasswordView() }
        .sheet(isPresented: $showEdit) { EditProfileSheet() }
    }

    @ViewBuilder
    private var profileTab: some View {
            if let me = env.session.me {
                SectionCard {
                    HStack(spacing: Theme.Space.l) {
                        PersonAvatar(name: me.name, url: me.avatarUrl, size: 64)
                        VStack(alignment: .leading, spacing: 4) {
                            Text(me.name).font(Theme.Font.display(20))
                            Text(me.email).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                            if let outlet = me.outlet { Text(outlet.name).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary) }
                        }
                        Spacer(minLength: 0)
                    }
                    Button { showEdit = true } label: { Label("Edit details & preferences", systemImage: "pencil") }
                        .buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    PhotosPicker(selection: $photoItem, matching: .images) {
                        Label(uploadingPhoto ? "Uploading photo…" : "Change photo", systemImage: "camera")
                    }
                    .buttonStyle(.bordered).disabled(uploadingPhoto)
                }
                SignInSafetySection(twoFactorOn: me.twoFactorEnabled, pinSet: me.terminalPinSet, changePassword: { showPassword = true })

            }
    }

    private func uploadPhoto(_ item: PhotosPickerItem) async {
        uploadingPhoto = true
        defer { uploadingPhoto = false; photoItem = nil }
        do {
            guard let original = try await item.loadTransferable(type: Data.self) else {
                env.toasts.show("Could not read this photo. Please try another.", tone: .warning); return
            }
            #if os(macOS)
            guard let image = NSImage(data: original), let tiff = image.tiffRepresentation,
                  let bitmap = NSBitmapImageRep(data: tiff), let data = bitmap.representation(using: .jpeg, properties: [.compressionFactor: 0.8]) else {
                env.toasts.show("Could not prepare this photo. Please try another.", tone: .warning); return
            }
            #else
            guard let image = UIImage(data: original), let data = image.jpegData(compressionQuality: 0.8) else {
                env.toasts.show("Could not prepare this photo. Please try another.", tone: .warning); return
            }
            #endif
            guard data.count <= 2 * 1024 * 1024 else { env.toasts.show("The photo must be 2 MB or smaller. Please choose a smaller photo.", tone: .warning); return }
            let file = SalesPickedFile(data: data, filename: "avatar.jpg", mimeType: "image/jpeg")
            let response = try await env.session.client.send(.init(.post, "/v1/admin/profile/avatar", body: .multipart([file.part(named: "avatar")])))
            toastResult(env, response, fallback: "Photo updated")
            await env.session.refreshMe()
        } catch { env.toasts.error(error) }
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
