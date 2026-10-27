import SwiftUI
import BethanyKit

/// Sign-in, the second step and the staged 2FA setup — one flow driven by
/// `SessionStore.state`, the same three shapes the console handles.
public struct SignInFlow: View {
    @Environment(SessionStore.self) private var session

    public init() {}

    public var body: some View {
        ZStack {
            Theme.canvas.ignoresSafeArea()
            ScrollView {
                VStack(spacing: Theme.Space.xl) {
                    Brand()
                    Group {
                        switch session.state {
                        case .twoFactor:
                            TwoFactorView()
                        case .twoFactorSetup:
                            TwoFactorSetupView()
                        default:
                            SignInForm()
                        }
                    }
                    .padding(Theme.Space.xl)
                    .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.sheet, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: Theme.Radius.sheet, style: .continuous).stroke(Theme.line))
                    .frame(maxWidth: 420)
                    if let e = session.startupError {
                        Label(e.isOffline ? "Bethany House can't be reached right now." : e.message, systemImage: "wifi.slash")
                            .font(Theme.Font.secondary).foregroundStyle(Theme.Palette.warning)
                    }
                }
                .padding(Theme.Space.xl)
                .frame(maxWidth: .infinity)
            }
        }
    }
}

struct Brand: View {
    var body: some View {
        VStack(spacing: 6) {
            Image(systemName: "house.lodge").font(.system(size: 36)).foregroundStyle(Theme.Palette.brand)
            Text("Bethany House Hub").font(Theme.Font.display(22))
            Text("Staff sign-in").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
        }
        .padding(.top, Theme.Space.xxl)
    }
}

struct SignInForm: View {
    @Environment(SessionStore.self) private var session
    @State private var email = ""
    @State private var password = ""
    @State private var remember = true
    @State private var error: APIError?
    @State private var busy = false
    @State private var showReset = false
    @FocusState private var focus: Field?
    enum Field { case email, password }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            VStack(alignment: .leading, spacing: 6) {
                Text("Email").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                TextField("you@bethanyhouse.co.ke", text: $email)
                    .textFieldStyle(.roundedBorder)
                    .textContentType(.username)
                    .focused($focus, equals: .email)
                    .onSubmit { focus = .password }
                    #if os(iOS)
                    .keyboardType(.emailAddress).textInputAutocapitalization(.never).autocorrectionDisabled()
                    #endif
                FieldError(error?.errors["email"]?.first)
            }
            VStack(alignment: .leading, spacing: 6) {
                Text("Password").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                SecureField("Password", text: $password)
                    .textFieldStyle(.roundedBorder)
                    .textContentType(.password)
                    .focused($focus, equals: .password)
                    .onSubmit { Task { await submit() } }
                FieldError(error?.errors["password"]?.first)
            }
            Toggle("Keep me signed in on this device", isOn: $remember).font(Theme.Font.secondary)
            if let error, error.errors.isEmpty {
                Text(error.status == 423 ? error.message : (error.status == 429 ? "Too many attempts. Wait \(error.retryAfterSeconds ?? 60) seconds." : error.message))
                    .font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
            }
            Button { Task { await submit() } } label: {
                if busy { ProgressView().controlSize(.small).tint(.white) } else { Text("Sign in") }
            }
            .buttonStyle(.primary)
            .disabled(busy || email.isEmpty || password.isEmpty)
            .keyboardShortcut(.defaultAction)
            Button("Forgot password?") { showReset = true }
                .buttonStyle(.plain).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.brandDeep)
        }
        .onAppear { focus = .email }
        .sheet(isPresented: $showReset) { ForgotPasswordView(email: email) }
    }

    private func submit() async {
        busy = true
        defer { busy = false }
        error = nil
        do {
            try await session.signIn(email: email.trimmingCharacters(in: .whitespaces), password: password, remember: remember)
        } catch let e as APIError {
            error = e
        } catch {
            self.error = APIError.transport(error.localizedDescription)
        }
    }
}

struct TwoFactorView: View {
    @Environment(SessionStore.self) private var session
    @State private var code = ""
    @State private var recovery = false
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Two-step sign-in").font(Theme.Font.sectionTitle)
            Text(recovery ? "Enter one of your recovery codes. Each works once." : "Enter the 6-digit code from your authenticator app.")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            TextField(recovery ? "Recovery code" : "123456", text: $code)
                .textFieldStyle(.roundedBorder)
                .font(Theme.Font.mono)
                .textContentType(.oneTimeCode)
                .onSubmit { Task { await submit() } }
                #if os(iOS)
                .keyboardType(recovery ? .asciiCapable : .numberPad)
                #endif
            if let error { Text(error.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            Button { Task { await submit() } } label: {
                if busy { ProgressView().controlSize(.small).tint(.white) } else { Text("Verify") }
            }
            .buttonStyle(.primary).disabled(busy || code.count < (recovery ? 6 : 6)).keyboardShortcut(.defaultAction)
            HStack {
                Button(recovery ? "Use authenticator code" : "Use a recovery code") { recovery.toggle(); code = "" }
                Spacer()
                Button("Back") { session.cancelSignIn() }
            }
            .buttonStyle(.plain).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.brandDeep)
        }
    }

    private func submit() async {
        busy = true
        defer { busy = false }
        error = nil
        do {
            try await session.verifyTwoFactor(code: recovery ? nil : code, recoveryCode: recovery ? code : nil)
        } catch let e as APIError { error = e } catch { error = APIError.transport(error.localizedDescription) }
    }
}

struct TwoFactorSetupView: View {
    @Environment(SessionStore.self) private var session
    @State private var setup: AuthService.TwoFactorSetup?
    @State private var code = ""
    @State private var recoveryCodes: [String] = []
    @State private var error: APIError?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Set up two-step sign-in").font(Theme.Font.sectionTitle)
            Text("Your role requires it. Add the Hub to an authenticator app, then enter the code it shows.")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            if let setup {
                if let url = setup.otpauthUrl, let img = QRCode.image(for: url) {
                    img.resizable().interpolation(.none).scaledToFit().frame(width: 180, height: 180).frame(maxWidth: .infinity)
                }
                HStack {
                    Text(setup.secret).font(Theme.Font.mono).textSelection(.enabled)
                    Spacer()
                    Button("Copy") { Clipboard.copy(setup.secret) }.font(Theme.Font.secondary)
                }
                TextField("123456", text: $code).textFieldStyle(.roundedBorder).font(Theme.Font.mono).textContentType(.oneTimeCode)
                    .onSubmit { Task { await confirm() } }
                if let error { Text(error.message).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
                Button { Task { await confirm() } } label: {
                    if busy { ProgressView().controlSize(.small).tint(.white) } else { Text("Turn on and sign in") }
                }
                .buttonStyle(.primary).disabled(busy || code.count != 6).keyboardShortcut(.defaultAction)
            } else if let error {
                ErrorState(error) { Task { await begin() } }
            } else {
                ProgressView().frame(maxWidth: .infinity)
            }
            Button("Back") { session.cancelSignIn() }.buttonStyle(.plain).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.brandDeep)
        }
        .task { await begin() }
        .sheet(isPresented: .constant(!recoveryCodes.isEmpty)) {
            RecoveryCodesView(codes: recoveryCodes) { recoveryCodes = [] }
        }
    }

    private func begin() async {
        error = nil
        do { setup = try await session.beginTwoFactorSetup() } catch let e as APIError { error = e } catch { error = APIError.transport(error.localizedDescription) }
    }

    private func confirm() async {
        busy = true
        defer { busy = false }
        error = nil
        do { recoveryCodes = try await session.confirmTwoFactorSetup(code: code) } catch let e as APIError { error = e } catch { error = APIError.transport(error.localizedDescription) }
    }
}

struct RecoveryCodesView: View {
    let codes: [String]
    let done: () -> Void
    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Your recovery codes").font(Theme.Font.sectionTitle)
            Text("Keep these somewhere safe. Each one signs you in once if you lose your authenticator.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            VStack(alignment: .leading, spacing: 4) {
                ForEach(codes, id: \.self) { Text($0).font(Theme.Font.mono) }
            }
            .padding(Theme.Space.m).frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.field, in: RoundedRectangle(cornerRadius: Theme.Radius.field))
            HStack {
                Button("Copy all") { Clipboard.copy(codes.joined(separator: "\n")) }
                Spacer()
                Button("I've saved them", action: done).buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 360)
    }
}

struct ForgotPasswordView: View {
    @Environment(SessionStore.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State var email: String
    @State private var message: String?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Reset your password").font(Theme.Font.sectionTitle)
            TextField("Email", text: $email).textFieldStyle(.roundedBorder)
            if let message { Text(message).font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
            HStack {
                Button("Cancel") { dismiss() }
                Spacer()
                Button("Send reset link") {
                    busy = true
                    Task {
                        do { message = try await session.auth.forgotPassword(email: email) } catch { message = (error as? APIError)?.message ?? error.localizedDescription }
                        busy = false
                    }
                }
                .buttonStyle(.borderedProminent).tint(Theme.Palette.brand).disabled(busy || email.isEmpty)
            }
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 360)
    }
}
