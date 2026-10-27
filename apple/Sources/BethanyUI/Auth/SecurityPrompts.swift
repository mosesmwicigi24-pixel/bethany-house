import SwiftUI
import BethanyKit
import LocalAuthentication
import CoreImage.CIFilterBuiltins

/// The PIN lock overlay (423 pin_locked), the step-up dialog
/// (403 step_up_required), the idle sign-out notice and the biometric
/// re-lock. Mounted once by each app shell over everything else.
public struct SecurityPrompts: ViewModifier {
    @Environment(SessionStore.self) private var session

    public func body(content: Content) -> some View {
        content
            .overlay {
                if session.pinLocked {
                    PinLockOverlay()
                } else if session.idleLockedOut {
                    IdleLockedOutOverlay()
                }
            }
            .sheet(isPresented: Binding(get: { session.stepUp != nil }, set: { if !$0 { session.cancelStepUp() } })) {
                if let c = session.stepUp { StepUpDialog(challenge: c) }
            }
            .sheet(isPresented: Binding(get: { session.heldDownload != nil }, set: { if !$0 { session.heldDownload = nil } })) {
                if let held = session.heldDownload { DownloadHeldView(payload: held) }
            }
    }
}

public extension View {
    func securityPrompts() -> some View { modifier(SecurityPrompts()) }
}

struct PinLockOverlay: View {
    @Environment(SessionStore.self) private var session
    @State private var pin = ""
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        ZStack {
            Rectangle().fill(.ultraThinMaterial).ignoresSafeArea()
            VStack(spacing: Theme.Space.l) {
                Image(systemName: "lock.fill").font(.system(size: 30)).foregroundStyle(Theme.Palette.brand)
                Text("Session locked").font(Theme.Font.sectionTitle)
                Text("Enter your terminal PIN to continue, \(session.me?.name.split(separator: " ").first.map(String.init) ?? "")").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
                SecureField("PIN", text: $pin)
                    .textFieldStyle(.roundedBorder).font(Theme.Font.mono).frame(maxWidth: 160)
                    .onSubmit { Task { await unlock() } }
                    #if os(iOS)
                    .keyboardType(.numberPad)
                    #endif
                if let error { Text(error).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
                Button { Task { await unlock() } } label: { busy ? AnyView(ProgressView().controlSize(.small)) : AnyView(Text("Unlock")) }
                    .buttonStyle(.primary).frame(maxWidth: 200).disabled(busy || pin.count < 4).keyboardShortcut(.defaultAction)
                Button("Sign out instead") { Task { await session.abandonLock() } }.buttonStyle(.plain).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.brandDeep)
            }
            .padding(Theme.Space.xxl)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.sheet, style: .continuous))
            .shadow(radius: 24)
        }
    }

    private func unlock() async {
        busy = true
        defer { busy = false }
        error = nil
        do { try await session.unlock(pin: pin); pin = "" } catch let e as APIError { error = e.message } catch { self.error = error.localizedDescription }
    }
}

struct IdleLockedOutOverlay: View {
    @Environment(SessionStore.self) private var session
    var body: some View {
        ZStack {
            Rectangle().fill(.ultraThinMaterial).ignoresSafeArea()
            VStack(spacing: Theme.Space.l) {
                Image(systemName: "clock.badge.exclamationmark").font(.system(size: 30)).foregroundStyle(Theme.Palette.amber)
                Text("You've been away a while").font(Theme.Font.sectionTitle)
                Text("For your account's safety the session may have ended. Continue to check.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary).multilineTextAlignment(.center)
                Button("Continue") { Task { await session.didBecomeActive() } }.buttonStyle(.primary).frame(maxWidth: 200)
            }
            .padding(Theme.Space.xxl).frame(maxWidth: 380)
            .background(Theme.card, in: RoundedRectangle(cornerRadius: Theme.Radius.sheet, style: .continuous))
            .shadow(radius: 24)
        }
    }
}

/// "Confirm it's you": passkey (platform authenticator), TOTP or password,
/// as the server asked, with its fallback.
struct StepUpDialog: View {
    @Environment(SessionStore.self) private var session
    let challenge: StepUpChallenge
    @State private var method: StepUpChallenge.Method
    @State private var secret = ""
    @State private var error: String?
    @State private var busy = false

    init(challenge: StepUpChallenge) {
        self.challenge = challenge
        _method = State(initialValue: challenge.method)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("Confirm it's you").font(Theme.Font.sectionTitle)
            Text(challenge.reason ?? "This action needs you to confirm your identity.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            switch method {
            case .passkey:
                Button { Task { await passkey() } } label: { Label("Use passkey", systemImage: "person.badge.key") }
                    .buttonStyle(.primary)
            case .totp:
                TextField("Authenticator code", text: $secret).textFieldStyle(.roundedBorder).font(Theme.Font.mono).textContentType(.oneTimeCode).onSubmit { Task { await submit() } }
                Button("Confirm") { Task { await submit() } }.buttonStyle(.primary).disabled(busy || secret.count != 6)
            case .password:
                SecureField("Password", text: $secret).textFieldStyle(.roundedBorder).textContentType(.password).onSubmit { Task { await submit() } }
                Button("Confirm") { Task { await submit() } }.buttonStyle(.primary).disabled(busy || secret.isEmpty)
            }
            if let error { Text(error).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger) }
            HStack {
                if let fb = challenge.fallback, fb != method {
                    Button("Use \(fb == .password ? "password" : fb == .totp ? "authenticator code" : "passkey") instead") { method = fb; secret = "" }
                }
                Spacer()
                Button("Cancel") { session.cancelStepUp() }
            }
            .buttonStyle(.plain).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.brandDeep)
        }
        .padding(Theme.Space.xl)
        .frame(minWidth: 360)
        .task { if method == .passkey { await passkey() } }
    }

    private func submit() async {
        busy = true
        defer { busy = false }
        error = nil
        do {
            try await session.stepUp(password: method == .password ? secret : nil, code: method == .totp ? secret : nil)
        } catch let e as APIError { error = e.message } catch { self.error = error.localizedDescription }
    }

    private func passkey() async {
        busy = true
        defer { busy = false }
        error = nil
        do {
            try await PasskeyStepUp(client: session.client).perform()
            session.stepUpSucceeded()
        } catch {
            self.error = (error as? APIError)?.message ?? "Passkey didn't work here. Try another way."
            if let fb = challenge.fallback { method = fb }
        }
    }
}

struct DownloadHeldView: View {
    @Environment(SessionStore.self) private var session
    let payload: JSONValue
    var body: some View {
        VStack(alignment: .leading, spacing: Theme.Space.l) {
            Text("This download needs approval").font(Theme.Font.sectionTitle)
            Text(payload["message"]?.string ?? "An approver has to release this file before it can be downloaded. You'll be told when it is.")
                .font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary)
            HStack {
                Spacer()
                Button("OK") { session.heldDownload = nil }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
        .padding(Theme.Space.xl).frame(minWidth: 360)
    }
}

/// Biometric re-lock: after `BiometricLock.graceSeconds` in the background,
/// Face ID / Touch ID (or the device passcode) before the Hub shows again.
/// The server session is untouched; this is the device boundary only.
@MainActor
@Observable
public final class BiometricLock {
    public static let graceSeconds: TimeInterval = 5 * 60
    public private(set) var locked = false
    private var backgroundedAt: Date?

    public init() {}

    public func didEnterBackground() { backgroundedAt = Date() }

    public func willEnterForeground() {
        if let t = backgroundedAt, Date().timeIntervalSince(t) > Self.graceSeconds { locked = true }
        backgroundedAt = nil
    }

    public func unlock() async {
        let ctx = LAContext()
        ctx.localizedCancelTitle = "Sign out"
        var err: NSError?
        guard ctx.canEvaluatePolicy(.deviceOwnerAuthentication, error: &err) else { locked = false; return }
        do {
            let ok = try await ctx.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: "Unlock the Hub")
            locked = !ok
        } catch {
            locked = true
        }
    }
}

public struct BiometricLockOverlay: View {
    let lock: BiometricLock
    let signOut: () async -> Void
    public init(lock: BiometricLock, signOut: @escaping () async -> Void) { self.lock = lock; self.signOut = signOut }
    public var body: some View {
        if lock.locked {
            ZStack {
                Rectangle().fill(.regularMaterial).ignoresSafeArea()
                VStack(spacing: Theme.Space.l) {
                    Image(systemName: "faceid").font(.system(size: 40)).foregroundStyle(Theme.Palette.brand)
                    Text("Unlock the Hub").font(Theme.Font.sectionTitle)
                    Button("Unlock") { Task { await lock.unlock() } }.buttonStyle(.primary).frame(maxWidth: 200)
                    Button("Sign out") { Task { await signOut() } }.buttonStyle(.plain).font(Theme.Font.secondary)
                }
            }
            .task { await lock.unlock() }
        }
    }
}

// MARK: - Helpers

enum QRCode {
    static func image(for text: String) -> Image? {
        let filter = CIFilter.qrCodeGenerator()
        filter.message = Data(text.utf8)
        filter.correctionLevel = "M"
        guard let output = filter.outputImage else { return nil }
        let scaled = output.transformed(by: CGAffineTransform(scaleX: 8, y: 8))
        guard let cg = CIContext().createCGImage(scaled, from: scaled.extent) else { return nil }
        return Image(decorative: cg, scale: 1)
    }
}

enum Clipboard {
    static func copy(_ text: String) {
        #if os(macOS)
        NSPasteboard.general.clearContents()
        NSPasteboard.general.setString(text, forType: .string)
        #else
        UIPasteboard.general.string = text
        #endif
    }
}
