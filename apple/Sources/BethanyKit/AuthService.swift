import Foundation

/// The sign-in and session endpoints (openapi.json, tag `auth`).
public struct AuthService: Sendable {
    public let client: APIClient
    public init(client: APIClient) { self.client = client }

    /// `POST /admin/auth/login` answers with one of three shapes.
    public enum LoginOutcome: Sendable, Hashable {
        case session(token: String, me: Me)
        case twoFactorRequired(userId: Int, challenge: String)
        case twoFactorSetupRequired(userId: Int, setupToken: String)
    }

    public func login(email: String, password: String, remember: Bool) async throws -> LoginOutcome {
        let r = try await client.post("/v1/admin/auth/login", json: .object([
            "email": .string(email), "password": .string(password), "remember_me": .bool(remember),
        ]))
        return try Self.outcome(from: r.json)
    }

    static func outcome(from json: JSONValue?) throws -> LoginOutcome {
        guard let json else { throw APIError(status: 0, message: "Empty sign-in response") }
        if json["requires_2fa"]?.bool == true, let uid = json["user_id"]?.int, let ch = json["challenge"]?.string {
            return .twoFactorRequired(userId: uid, challenge: ch)
        }
        if json["requires_2fa_setup"]?.bool == true, let uid = json["user_id"]?.int, let st = json["setup_token"]?.string {
            return .twoFactorSetupRequired(userId: uid, setupToken: st)
        }
        guard let token = json["token"]?.string, let user = json["user"] else {
            throw APIError(status: 0, message: json["message"]?.string ?? "Unexpected sign-in response")
        }
        return .session(token: token, me: try Me(json: user))
    }

    /// Second step: a TOTP code, or a recovery code (each works once).
    public func verifyTwoFactor(userId: Int, challenge: String, code: String?, recoveryCode: String?) async throws -> LoginOutcome {
        var body: [String: JSONValue] = ["user_id": .number(Double(userId)), "challenge": .string(challenge)]
        if let code { body["code"] = .string(code) }
        if let recoveryCode { body["recovery_code"] = .string(recoveryCode) }
        let r = try await client.post("/v1/admin/auth/2fa/verify", json: .object(body))
        return try Self.outcome(from: r.json)
    }

    /// Staged 2FA: fetch the secret and otpauth URL to show as a QR code.
    public struct TwoFactorSetup: Sendable, Hashable {
        public let secret: String
        public let otpauthUrl: String?
        public let raw: JSONValue
    }

    public func beginTwoFactorSetup(userId: Int, setupToken: String) async throws -> TwoFactorSetup {
        let r = try await client.post("/v1/admin/auth/2fa/setup", json: .object(["user_id": .number(Double(userId)), "setup_token": .string(setupToken)]))
        let j = r.json ?? .null
        return TwoFactorSetup(secret: j["secret"]?.string ?? "", otpauthUrl: j["otpauth_url"]?.string ?? j["qr_url"]?.string, raw: j)
    }

    public struct TwoFactorSetupResult: Sendable {
        public let outcome: LoginOutcome
        public let recoveryCodes: [String]
    }

    public func confirmTwoFactorSetup(userId: Int, setupToken: String, code: String) async throws -> TwoFactorSetupResult {
        let r = try await client.post("/v1/admin/auth/2fa/setup/confirm", json: .object([
            "user_id": .number(Double(userId)), "setup_token": .string(setupToken), "code": .string(code),
        ]))
        let codes = r.json?["recovery_codes"]?.array?.compactMap(\.string) ?? []
        return TwoFactorSetupResult(outcome: try Self.outcome(from: r.json), recoveryCodes: codes)
    }

    public func me(background: Bool = false) async throws -> Me {
        let r = try await client.get("/v1/admin/auth/me", background: background)
        guard let user = r.json?["user"] else { throw APIError(status: 0, message: "Unexpected /me response") }
        return try Me(json: user)
    }

    public func logout() async throws {
        _ = try await client.post("/v1/admin/auth/logout")
    }

    public struct Renewal: Sendable, Hashable {
        public let token: String
        public let expiresAt: Date
        public let sessionStartedAt: Date?
        public let policy: SessionPolicy?
    }

    /// `POST /admin/auth/renew` — a replacement token for the same session.
    /// 409 means not renewable (kiosk token, no expiry): sign in again.
    public func renew() async throws -> Renewal {
        let r = try await client.post("/v1/admin/auth/renew")
        guard let j = r.json, let token = j["token"]?.string, let exp = j["expires_at"]?.date else {
            throw APIError(status: 0, message: "Unexpected renew response")
        }
        return Renewal(token: token, expiresAt: exp, sessionStartedAt: j["session_started_at"]?.date, policy: j["session_policy"].flatMap(SessionPolicy.init(json:)))
    }

    public func unlockWithPin(_ pin: String) async throws {
        _ = try await client.post("/v1/admin/auth/pin/unlock", json: .object(["pin": .string(pin)]))
    }

    /// Step-up with password or TOTP (passkey step-up goes through `PasskeyStepUp`).
    public func stepUp(password: String?, code: String?) async throws {
        var body: [String: JSONValue] = [:]
        if let password { body["password"] = .string(password) }
        if let code { body["code"] = .string(code) }
        _ = try await client.post("/v1/admin/auth/step-up", json: .object(body))
    }

    public func forgotPassword(email: String) async throws -> String {
        let r = try await client.post("/v1/admin/auth/forgot-password", json: .object(["email": .string(email)]))
        return r.json?["message"]?.string ?? "If that address exists, a reset link is on its way."
    }

    public func resetPassword(email: String, token: String, password: String) async throws {
        _ = try await client.post("/v1/admin/auth/reset-password", json: .object([
            "email": .string(email), "token": .string(token), "password": .string(password), "password_confirmation": .string(password),
        ]))
    }
}
