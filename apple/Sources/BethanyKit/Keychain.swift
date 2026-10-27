import Foundation
import Security

/// Keychain-backed secret storage (Phase 12: credentials never touch
/// UserDefaults or plain files). Items are device-only and unavailable until
/// first unlock, so a locked device cannot leak a session.
public struct Keychain: Sendable {
    public let service: String
    public let accessGroup: String?

    public init(service: String = "ke.co.bethanyhouse.hub", accessGroup: String? = nil) {
        self.service = service
        self.accessGroup = accessGroup
    }

    public enum KeychainError: Error { case unexpectedStatus(OSStatus) }

    private func baseQuery(_ account: String) -> [String: Any] {
        var q: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
        if let accessGroup { q[kSecAttrAccessGroup as String] = accessGroup }
        return q
    }

    public func set(_ data: Data, for account: String) throws {
        var q = baseQuery(account)
        SecItemDelete(q as CFDictionary)
        q[kSecValueData as String] = data
        q[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        let status = SecItemAdd(q as CFDictionary, nil)
        guard status == errSecSuccess else { throw KeychainError.unexpectedStatus(status) }
    }

    public func set(_ string: String, for account: String) throws {
        try set(Data(string.utf8), for: account)
    }

    public func data(for account: String) -> Data? {
        var q = baseQuery(account)
        q[kSecReturnData as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne
        var out: AnyObject?
        let status = SecItemCopyMatching(q as CFDictionary, &out)
        guard status == errSecSuccess else { return nil }
        return out as? Data
    }

    public func string(for account: String) -> String? {
        data(for: account).flatMap { String(data: $0, encoding: .utf8) }
    }

    public func remove(_ account: String) {
        SecItemDelete(baseQuery(account) as CFDictionary)
    }
}
