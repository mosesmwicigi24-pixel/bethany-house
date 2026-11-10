import Foundation

/// Sign-in safety on the profile page (owner-approved slice 6, 2026-11-10):
/// two-step sign-in, recovery codes, passkeys and the terminal PIN, as the
/// console does them (ProfilePage.tsx security tab, PasskeysCard.tsx,
/// TerminalPinForm.tsx, lib/webauthn.ts). The server decides every one of
/// these and asks for step-up where it must; these only shape requests and
/// give the person the server's own rule before they send.
public enum TwoFactor {
    /// POST auth/2fa/enable → {secret_key, qr_code_url}: the key to add to an authenticator app.
    public static func setup(_ json: JSONValue) -> (secret: String, otpauthURL: String)? {
        guard let s = json["secret_key"]?.string, !s.isEmpty else { return nil }
        return (s, json["qr_code_url"]?.string ?? "")
    }

    /// `required|string|size:6` on the server; authenticator codes are digits.
    public static func isCode(_ s: String) -> Bool {
        let t = s.trimmingCharacters(in: .whitespaces)
        return t.count == 6 && t.allSatisfy(\.isNumber)
    }

    /// POST auth/2fa/confirm or auth/2fa/recovery-codes → the codes to keep.
    public static func recoveryCodes(_ json: JSONValue) -> [String] { (json["recovery_codes"]?.array ?? []).compactMap(\.string) }
}

public enum TerminalPinRule {
    /// The server's rule word for word (App\Services\Auth\TerminalPin::problem): nil when acceptable.
    public static func problem(_ pin: String) -> String? {
        guard (4...6).contains(pin.count), pin.allSatisfy({ $0.isASCII && $0.isNumber }) else { return "The PIN must be 4 to 6 digits." }
        if Set(pin).count == 1 { return "The PIN cannot be one digit repeated." }
        if "01234567890123".contains(pin) || "98765432109876".contains(pin) { return "The PIN cannot be a run of consecutive digits." }
        return nil
    }
}

/// POST profile/passkeys/options → {options}: what a new passkey is made for.
public struct PasskeyCreationOptions: Sendable {
    public let challenge: Data
    public let relyingParty: String
    public let userId: Data
    public let userName: String

    public init?(json: JSONValue) {
        let o = json["options"] ?? json
        guard let c = o["challenge"]?.string.flatMap(Data.init(base64URL:)),
              let rp = o["rp"]?["id"]?.string,
              let uid = o["user"]?["id"]?.string.flatMap(Data.init(base64URL:)) else { return nil }
        challenge = c; relyingParty = rp; userId = uid
        userName = o["user"]?["name"]?.string ?? ""
    }

    /// The credential as the server reads it (lib/webauthn.ts credentialToJson): every binary field base64url.
    public static func credentialJSON(credentialID: Data, clientDataJSON: Data, attestationObject: Data) -> JSONValue {
        .object([
            "id": .string(credentialID.base64URL),
            "rawId": .string(credentialID.base64URL),
            "type": .string("public-key"),
            "authenticatorAttachment": .string("platform"),
            "clientExtensionResults": .object([:]),
            "response": .object([
                "clientDataJSON": .string(clientDataJSON.base64URL),
                "attestationObject": .string(attestationObject.base64URL),
                "transports": .array([.string("internal"), .string("hybrid")]),
            ]),
        ])
    }
}

/// One row of GET profile/passkeys.
public struct PasskeyRow: Identifiable, Hashable, Sendable {
    public let id: Int
    public let name: String
    public let createdAt: Date?
    public let lastUsedAt: Date?
    public init?(json: JSONValue) {
        guard let id = json["id"]?.int else { return nil }
        self.id = id
        name = json["name"]?.string ?? "Passkey"
        createdAt = json["created_at"]?.date
        lastUsedAt = json["last_used_at"]?.date
    }
    public static func rows(_ json: JSONValue) -> [PasskeyRow] { (json["data"]?.array ?? []).compactMap(PasskeyRow.init(json:)) }
}

/// WebAuthn's base64url, without padding.
public extension Data {
    init?(base64URL: String) {
        var s = base64URL.replacingOccurrences(of: "-", with: "+").replacingOccurrences(of: "_", with: "/")
        while s.count % 4 != 0 { s += "=" }
        self.init(base64Encoded: s)
    }

    var base64URL: String {
        base64EncodedString().replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "=", with: "")
    }
}
