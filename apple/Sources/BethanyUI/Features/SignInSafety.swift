import SwiftUI
import BethanyKit
import CoreImage

// Sign-in safety (owner-approved slice 6, 2026-11-10): two-step sign-in,
// recovery codes, passkeys and the terminal PIN, as the console's profile
// security tab does them. The server decides each one and asks for step-up
// where it must; the client's step-up prompt answers and resends.

struct SignInSafetySection: View {
    @Environment(AppEnvironment.self) private var env
    let twoFactorOn: Bool
    let pinSet: Bool
    @State private var settingUp = false
    @State private var turningOff = false
    @State private var settingPin = false
    @State private var codes: [String]?
    @State private var passkeys: Resource<[PasskeyRow]>?
    @State private var addingPasskey = false

    var body: some View {
        SectionCard("Two-step sign-in") {
            if twoFactorOn {
                Text("On. You are asked for a code from your authenticator app when you sign in.").font(Theme.Font.secondary)
                HStack {
                    AsyncButton(action: {
                        let r = try await env.session.client.post("/v1/admin/auth/2fa/recovery-codes")
                        codes = TwoFactor.recoveryCodes(r.json ?? .null)
                    }) { Text("New recovery codes") }
                    Button("Turn off…", role: .destructive) { turningOff = true }
                }
            } else {
                Text("Off. Turn it on to be asked for a code from an authenticator app at sign-in.").font(Theme.Font.secondary)
                Button("Turn on…") { settingUp = true }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
        SectionCard("Passkeys") {
            Text("Sign in and confirm it's you with Touch ID or Face ID. Passkeys added here or in the console work in both.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            if let passkeys {
                LoadableView(passkeys.state, retry: { passkeys.load() }, isEmpty: { $0.isEmpty }, empty: { EmptyState("No passkeys yet", symbol: "person.badge.key") }) { rows in
                    ForEach(rows) { p in
                        HStack {
                            Image(systemName: "person.badge.key").foregroundStyle(Theme.textTertiary)
                            VStack(alignment: .leading, spacing: 1) {
                                Text(p.name).font(Theme.Font.body)
                                HStack(spacing: 4) {
                                    Text("Added"); DateText(p.createdAt, style: .date)
                                    if let used = p.lastUsedAt { Text("· last used"); DateText(used, style: .relative) }
                                }.font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            }
                            Spacer()
                            ConfirmButton("Remove", message: "Remove the passkey \(p.name)? You can add it again later.", destructive: true, action: {
                                do { let r = try await env.session.client.delete("/v1/admin/profile/passkeys/\(p.id)"); toastResult(env, r, fallback: "Passkey removed"); passkeys.load() } catch { env.toasts.error(error) }
                            }) { Text("Remove") }.font(Theme.Font.secondary)
                        }
                    }
                }
            }
            Button("Add a passkey…") { addingPasskey = true }
        }
        SectionCard("Terminal PIN") {
            Text(pinSet ? "Set. It unlocks a shared till without your password." : "Not set. A PIN unlocks a shared till without your password.").font(Theme.Font.secondary)
            Button(pinSet ? "Change PIN…" : "Set a PIN…") { settingPin = true }
        }
        .task {
            if passkeys == nil {
                let c = env.session.client
                passkeys = Resource { PasskeyRow.rows(try await c.get("/v1/admin/profile/passkeys").json ?? .null) }
                passkeys?.load()
            }
        }
        .sheet(isPresented: $settingUp) { TwoFactorSetupSheet { codes = $0 } }
        .sheet(isPresented: $turningOff) { TwoFactorOffSheet() }
        .sheet(isPresented: $settingPin) { TerminalPinSheet() }
        .sheet(isPresented: $addingPasskey) { AddPasskeySheet { passkeys?.load() } }
        .sheet(isPresented: Binding(get: { codes != nil }, set: { if !$0 { codes = nil } })) { RecoveryCodesSheet(codes: codes ?? []) }
    }
}

/// POST auth/2fa/enable → key and otpauth link; POST auth/2fa/confirm {code} → recovery codes.
struct TwoFactorSetupSheet: View {
    @Environment(AppEnvironment.self) private var env
    @Environment(\.dismiss) private var dismiss
    let onCodes: ([String]) -> Void
    @State private var setup: (secret: String, otpauthURL: String)?
    @State private var code = ""
    @State private var failure: String?

    var body: some View {
        EditSheet("Turn on two-step sign-in", saveLabel: "Confirm", canSave: setup != nil && TwoFactor.isCode(code), save: {
            let r = try await env.session.client.post("/v1/admin/auth/2fa/confirm", json: .object(["code": .string(code.trimmingCharacters(in: .whitespaces))]))
            env.toasts.show(r.json?["message"]?.string ?? "Two-factor authentication enabled.")
            await env.session.refreshMe()
            onCodes(TwoFactor.recoveryCodes(r.json ?? .null))
        }) { e in
            if let s = setup {
                Text("Add this account to your authenticator app, then enter the 6-digit code it shows.").font(Theme.Font.secondary)
                if let url = URL(string: s.otpauthURL), !s.otpauthURL.isEmpty { QRCodeView(text: url.absoluteString).frame(width: 180, height: 180) }
                DetailRow("Key") { Text(s.secret).font(Theme.Font.mono).textSelection(.enabled) }
                Field("Code", $code, placeholder: "123456", error: e?.errors["code"]?.first, mono: true)
            } else if let failure {
                Text(failure).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
            } else {
                ProgressView()
            }
        }
        .task {
            do { setup = TwoFactor.setup(try await env.session.client.post("/v1/admin/auth/2fa/enable").json ?? .null) }
            catch let e as APIError { failure = e.message } catch { failure = error.localizedDescription }
        }
    }
}

/// POST auth/2fa/disable {password}. A role that requires it is refused by the server, in its own words.
struct TwoFactorOffSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State private var password = ""
    var body: some View {
        EditSheet("Turn off two-step sign-in", saveLabel: "Turn off", canSave: !password.isEmpty, destructive: true, save: {
            let r = try await env.session.client.post("/v1/admin/auth/2fa/disable", json: .object(["password": .string(password)]))
            env.toasts.show(r.json?["message"]?.string ?? "Two-factor authentication disabled.")
            await env.session.refreshMe()
        }) { e in
            Text("Your sign-in will no longer ask for a code.").font(Theme.Font.secondary)
            SecureField("Your password", text: $password).textFieldStyle(.roundedBorder)
            if let m = e?.errors["password"]?.first { Text(m).font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
        }
    }
}

/// PUT profile/terminal-pin {pin, pin_confirmation, current_password}.
struct TerminalPinSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State private var pin = ""
    @State private var confirm = ""
    @State private var password = ""
    var body: some View {
        let problem = pin.isEmpty ? nil : TerminalPinRule.problem(pin)
        EditSheet("Terminal PIN", saveLabel: "Save PIN", canSave: !pin.isEmpty && problem == nil && pin == confirm && !password.isEmpty, save: {
            let r = try await env.session.client.put("/v1/admin/profile/terminal-pin", json: .object([
                "pin": .string(pin), "pin_confirmation": .string(confirm), "current_password": .string(password),
            ]))
            env.toasts.show(r.json?["message"]?.string ?? "Your PIN is saved.")
            await env.session.refreshMe()
        }) { e in
            SecureField("New PIN (4 to 6 digits)", text: $pin).textFieldStyle(.roundedBorder)
            if let m = problem ?? e?.errors["pin"]?.first { Text(m).font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
            SecureField("Repeat the PIN", text: $confirm).textFieldStyle(.roundedBorder)
            if !confirm.isEmpty, confirm != pin { Text("The two PINs differ.").font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
            SecureField("Your password", text: $password).textFieldStyle(.roundedBorder)
            if let m = e?.errors["current_password"]?.first { Text(m).font(Theme.Font.caption).foregroundStyle(Theme.Palette.danger) }
        }
    }
}

/// POST profile/passkeys/options, the device's passkey prompt, then POST profile/passkeys {name, credential}.
struct AddPasskeySheet: View {
    @Environment(AppEnvironment.self) private var env
    let done: () -> Void
    @State private var name = ""
    var body: some View {
        EditSheet("Add a passkey", saveLabel: "Continue", save: {
            let label = name.trimmingCharacters(in: .whitespaces).isEmpty ? defaultName : name.trimmingCharacters(in: .whitespaces)
            let message = try await PasskeyRegistration(client: env.session.client).register(name: label)
            env.toasts.show(message); done()
        }) { _ in
            Field("Name", $name, placeholder: defaultName)
            Text("Your device will ask for Touch ID, Face ID or its passcode. You may be asked to confirm it's you first.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
        }
    }
    private var defaultName: String {
        #if os(macOS)
        return "Mac"
        #else
        return UIDevice.current.userInterfaceIdiom == .pad ? "iPad" : "iPhone"
        #endif
    }
}

struct RecoveryCodesSheet: View {
    @Environment(\.dismiss) private var dismiss
    let codes: [String]
    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            Text("Recovery codes").font(Theme.Font.sectionTitle)
            Text("Each code signs you in once if you lose your authenticator. Keep them somewhere safe; they are not shown again.").font(Theme.Font.secondary)
            Text(codes.joined(separator: "\n")).font(Theme.Font.mono).textSelection(.enabled).padding(Theme.Space.s).background(Theme.card, in: RoundedRectangle(cornerRadius: 8))
            HStack { CopyButton("Copy", text: codes.joined(separator: "\n"), confirmation: "Codes copied"); Spacer(); Button("Done") { dismiss() }.buttonStyle(.borderedProminent) }
        }
        .padding(Theme.Space.xl).frame(minWidth: 360)
    }
}

/// The otpauth link as a QR code, drawn on the device (Core Image); nothing leaves it.
struct QRCodeView: View {
    let text: String
    var body: some View {
        if let image = Self.render(text) { image.interpolation(.none).resizable().scaledToFit() }
        else { Text(text).font(Theme.Font.caption).textSelection(.enabled) }
    }
    static func render(_ text: String) -> Image? {
        let filter = CIFilter(name: "CIQRCodeGenerator")
        filter?.setValue(Data(text.utf8), forKey: "inputMessage")
        filter?.setValue("M", forKey: "inputCorrectionLevel")
        guard let out = filter?.outputImage?.transformed(by: CGAffineTransform(scaleX: 8, y: 8)),
              let cg = CIContext().createCGImage(out, from: out.extent) else { return nil }
        #if os(macOS)
        return Image(nsImage: NSImage(cgImage: cg, size: NSSize(width: out.extent.width, height: out.extent.height)))
        #else
        return Image(uiImage: UIImage(cgImage: cg))
        #endif
    }
}
