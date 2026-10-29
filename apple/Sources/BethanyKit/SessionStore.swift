import Foundation
import Observation

/// The signed-in session, shared by every screen (react-admin's
/// auth.store.ts and security.store.ts in one place).
///
/// Owns the bearer token (Keychain), the person and their effective
/// permissions, the session policy and its idle clock, token renewal, and the
/// PIN-lock and step-up dialogs the API client waits on. Everything the apps
/// show is derived from `me`; nothing here computes business data.
@MainActor
@Observable
public final class SessionStore: SessionAuth {
    public enum State: Equatable {
        case starting
        case signedOut
        case signingIn
        case twoFactor(userId: Int, challenge: String)
        case twoFactorSetup(userId: Int, setupToken: String)
        case signedIn
    }

    public private(set) var state: State = .starting
    public private(set) var me: Me?
    public private(set) var permissions: PermissionSet = .none
    public private(set) var tokenExpiresAt: Date?
    public private(set) var sessionStartedAt: Date?
    /// Set when the API is unreachable at start (startup problem screen).
    public private(set) var startupError: APIError?
    /// A PIN-locked session (423): the overlay is up until unlock.
    public private(set) var pinLocked = false
    /// A step-up challenge is on screen.
    public private(set) var stepUp: StepUpChallenge?
    /// A download was held for approval (403 download_approval_required).
    public var heldDownload: JSONValue?
    /// Presented when the person has been idle past the policy (client-side
    /// mirror of SessionPolicy, so the app can show the lock before the next 401).
    public private(set) var idleLockedOut = false

    public let client: APIClient
    public let auth: AuthService
    public let keychain: Keychain
    public let device: DeviceIdentity
    public var pushRegistrar: PushRegistration?

    private var token: String?
    private var pinWaiters: [CheckedContinuation<Bool, Never>] = []
    private var stepUpWaiters: [CheckedContinuation<Bool, Never>] = []
    private var lastActivity = Date()
    private var renewalTask: Task<Void, Never>?
    private var idleTask: Task<Void, Never>?

    private static let tokenAccount = "bh_admin_token"
    private static let meCache = "bh_me_cache"

    public init(baseURL: URL, keychain: Keychain = Keychain()) {
        self.keychain = keychain
        self.device = DeviceIdentity.current(keychain: keychain)
        self.client = APIClient(baseURL: baseURL, device: device)
        self.auth = AuthService(client: client)
        self.token = keychain.string(for: Self.tokenAccount)
        Task { await client.attach(auth: self) }
    }

    // MARK: Start

    /// Restores the session: a cached `me` renders at once (offline too), then
    /// `/me` confirms it. Only 401/403 from `/me` clear the session; an
    /// unreachable API keeps the cached session and shows the startup problem.
    public func start() async {
        guard token != nil else { state = .signedOut; return }
        if let cached = keychain.data(for: Self.meCache), let me = try? HubJSON.decoder.decode(Me.self, from: cached) {
            apply(me: me)
            state = .signedIn
        }
        do {
            let fresh = try await auth.me()
            apply(me: fresh)
            state = .signedIn
            startupError = nil
            scheduleRenewal()
            startIdleClock()
            await pushRegistrar?.register(after: self)
        } catch let e as APIError where e.status == 401 || e.status == 403 {
            clearSession()
        } catch let e as APIError {
            startupError = e
            if me == nil { state = .signedOut }
        } catch {
            startupError = APIError.transport(error.localizedDescription)
            if me == nil { state = .signedOut }
        }
    }

    // MARK: Sign-in

    public func signIn(email: String, password: String, remember: Bool) async throws {
        state = .signingIn
        do {
            try await handle(try await auth.login(email: email, password: password, remember: remember))
        } catch {
            state = .signedOut
            throw error
        }
    }

    public func verifyTwoFactor(code: String?, recoveryCode: String?) async throws {
        guard case .twoFactor(let uid, let challenge) = state else { return }
        try await handle(try await auth.verifyTwoFactor(userId: uid, challenge: challenge, code: code, recoveryCode: recoveryCode))
    }

    public func beginTwoFactorSetup() async throws -> AuthService.TwoFactorSetup {
        guard case .twoFactorSetup(let uid, let st) = state else { throw APIError(status: 0, message: "No setup in progress") }
        return try await auth.beginTwoFactorSetup(userId: uid, setupToken: st)
    }

    public func confirmTwoFactorSetup(code: String) async throws -> [String] {
        guard case .twoFactorSetup(let uid, let st) = state else { throw APIError(status: 0, message: "No setup in progress") }
        let result = try await auth.confirmTwoFactorSetup(userId: uid, setupToken: st, code: code)
        try await handle(result.outcome)
        return result.recoveryCodes
    }

    private func handle(_ outcome: AuthService.LoginOutcome) async throws {
        switch outcome {
        case .twoFactorRequired(let uid, let ch):
            state = .twoFactor(userId: uid, challenge: ch)
        case .twoFactorSetupRequired(let uid, let st):
            state = .twoFactorSetup(userId: uid, setupToken: st)
        case .session(let token, let me):
            self.token = token
            try? keychain.set(token, for: Self.tokenAccount)
            apply(me: me)
            // The login response does not carry expires_at; renew() reports it.
            tokenExpiresAt = nil
            sessionStartedAt = Date()
            state = .signedIn
            startupError = nil
            scheduleRenewal()
            startIdleClock()
            await pushRegistrar?.register(after: self)
        }
    }

    public func cancelSignIn() {
        state = .signedOut
    }

    // MARK: Session

    public func signOut() async {
        await pushRegistrar?.unregister(after: self)
        try? await auth.logout()
        clearSession()
    }

    private func clearSession() {
        renewalTask?.cancel()
        idleTask?.cancel()
        token = nil
        keychain.remove(Self.tokenAccount)
        keychain.remove(Self.meCache)
        me = nil
        permissions = .none
        tokenExpiresAt = nil
        pinLocked = false
        stepUp = nil
        idleLockedOut = false
        state = .signedOut
        for w in pinWaiters { w.resume(returning: false) }
        pinWaiters.removeAll()
        for w in stepUpWaiters { w.resume(returning: false) }
        stepUpWaiters.removeAll()
    }

    public func refreshMe(background: Bool = true) async {
        guard let fresh = try? await auth.me(background: background) else { return }
        apply(me: fresh)
    }

    private func apply(me: Me) {
        self.me = me
        permissions = PermissionSet(me: me)
        if let data = try? HubJSON.encoder.encode(me) { try? keychain.set(data, for: Self.meCache) }
    }

    public var home: Route { HomePath.resolve(for: permissions) }

    public func can(_ permission: String) -> Bool { permissions.can(permission) }
    public func allows(_ gate: NavGate) -> Bool { gate.allows(permissions) }

    // MARK: Activity, idle, renewal

    /// Called by the apps on every user interaction (tap, key, scroll).
    public func touch() {
        lastActivity = Date()
    }

    /// Mirrors SessionPolicy on the client so a clerk sees the PIN lock, and
    /// an admin the sign-out, at the same moment the server would apply them,
    /// instead of on the next failed request.
    private func startIdleClock() {
        idleTask?.cancel()
        guard let policy = me?.sessionPolicy else { return }
        idleTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(15))
                guard let self else { return }
                let idle = Date().timeIntervalSince(self.lastActivity) / 60
                if idle > Double(policy.idleMinutes) {
                    if policy.onIdle == .pinLock {
                        self.pinLocked = true
                    } else {
                        self.idleLockedOut = true
                    }
                }
            }
        }
    }

    /// Renew before expiry: at 75% of the remaining life, and on foreground
    /// when less than a day remains. The server caps the new expiry at the
    /// role's absolute limit, so this never extends a session the policy would end.
    private func scheduleRenewal() {
        renewalTask?.cancel()
        renewalTask = Task { [weak self] in
            // First renewal soon after sign-in learns the real expiry.
            try? await Task.sleep(for: .seconds(5))
            while !Task.isCancelled {
                guard let self else { return }
                await self.renewNow()
                let wait = Self.renewalDelay(expiresAt: self.tokenExpiresAt, now: Date())
                try? await Task.sleep(for: .seconds(wait))
            }
        }
    }

    /// Seconds until the next renewal attempt. Exposed for tests.
    public static func renewalDelay(expiresAt: Date?, now: Date) -> TimeInterval {
        guard let expiresAt else { return 6 * 3600 }
        let remaining = expiresAt.timeIntervalSince(now)
        if remaining <= 0 { return 60 }
        return max(60, min(remaining * 0.75, 12 * 3600))
    }

    public func renewNow() async {
        guard state == .signedIn, !pinLocked else { return }
        do {
            let r = try await auth.renew()
            token = r.token
            try? keychain.set(r.token, for: Self.tokenAccount)
            tokenExpiresAt = r.expiresAt
            sessionStartedAt = r.sessionStartedAt ?? sessionStartedAt
        } catch let e as APIError where e.status == 409 {
            // Not renewable (kiosk token, no expiry): keep using it until it ends.
            renewalTask?.cancel()
        } catch {
            // Offline or transient: the loop tries again.
        }
    }

    /// Foreground hook: confirm the session and renew when it is close.
    public func didBecomeActive() async {
        touch()
        idleLockedOut = false
        guard state == .signedIn else { return }
        await refreshMe(background: true)
        if let exp = tokenExpiresAt, exp.timeIntervalSinceNow < 86_400 { await renewNow() }
    }

    // MARK: PIN lock & step-up (SessionAuth)

    nonisolated public func bearerToken() async -> String? { await token }

    nonisolated public func sessionExpired() async { await clearSession() }

    nonisolated public func awaitPinUnlock() async -> Bool {
        await withCheckedContinuation { c in
            Task { @MainActor in
                self.pinLocked = true
                self.pinWaiters.append(c)
            }
        }
    }

    nonisolated public func awaitStepUp(_ challenge: StepUpChallenge) async -> Bool {
        await withCheckedContinuation { c in
            Task { @MainActor in
                self.stepUp = challenge
                self.stepUpWaiters.append(c)
            }
        }
    }

    nonisolated public func downloadHeld(_ payload: JSONValue?) async {
        await MainActor.run { self.heldDownload = payload }
    }

    /// Terminal PIN entered on the lock overlay.
    public func unlock(pin: String) async throws {
        try await auth.unlockWithPin(pin)
        pinLocked = false
        touch()
        for w in pinWaiters { w.resume(returning: true) }
        pinWaiters.removeAll()
    }

    /// The person chose to sign out from the lock overlay instead.
    public func abandonLock() async {
        await signOut()
    }

    /// Step-up completed (password/TOTP here; passkey via PasskeyStepUp then `stepUpSucceeded`).
    public func stepUp(password: String?, code: String?) async throws {
        try await auth.stepUp(password: password, code: code)
        stepUpSucceeded()
    }

    public func stepUpSucceeded() {
        stepUp = nil
        for w in stepUpWaiters { w.resume(returning: true) }
        stepUpWaiters.removeAll()
    }

    public func cancelStepUp() {
        stepUp = nil
        for w in stepUpWaiters { w.resume(returning: false) }
        stepUpWaiters.removeAll()
    }
}
