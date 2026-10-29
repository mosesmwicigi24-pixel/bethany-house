import Foundation
#if canImport(UIKit)
import UIKit
#endif

/// The stable per-install identity the Hub keys named devices on.
///
/// Sent as `X-Device-Id` on every request (App\Services\Auth\Devices) and
/// stored alongside a push registration. Kept in the Keychain so it survives
/// reinstalls on the same device but never leaves it.
public struct DeviceIdentity: Sendable {
    public let id: String
    public let platform: Platform
    public let model: String
    public let appVersion: String

    public enum Platform: String, Sendable {
        case ios, ipados, macos
    }

    private static let account = "bh_device_id"

    public static func current(keychain: Keychain = Keychain()) -> DeviceIdentity {
        let id: String
        if let existing = keychain.string(for: account), existing.range(of: "^[A-Za-z0-9-]{16,64}$", options: .regularExpression) != nil {
            id = existing
        } else {
            id = UUID().uuidString.lowercased()
            try? keychain.set(id, for: account)
        }
        return DeviceIdentity(id: id, platform: detectPlatform(), model: hardwareModel(), appVersion: appVersion())
    }

    public var userAgent: String {
        "BethanyHub/\(appVersion) (\(platform.rawValue); \(model))"
    }

    private static func detectPlatform() -> Platform {
        #if os(macOS)
        return .macos
        #else
        return UIDevice.current.userInterfaceIdiom == .pad ? .ipados : .ios
        #endif
    }

    private static func hardwareModel() -> String {
        var size = 0
        sysctlbyname("hw.model", nil, &size, nil, 0)
        var buf = [CChar](repeating: 0, count: max(size, 1))
        sysctlbyname("hw.model", &buf, &size, nil, 0)
        let hw = String(cString: buf)
        if !hw.isEmpty { return hw }
        #if os(macOS)
        return "Mac"
        #else
        return UIDevice.current.model
        #endif
    }

    private static func appVersion() -> String {
        let v = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "0"
        let b = Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "0"
        return "\(v)+\(b)"
    }
}
