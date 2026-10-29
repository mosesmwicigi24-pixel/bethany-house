import Foundation
import Observation

/// Registers the APNs device token with the Hub
/// (`POST /admin/push/subscribe-device`, native apps Step 2) and removes it
/// on sign-out. The token arrives from the app delegate; registration waits
/// until a session exists and re-runs on every sign-in so the row always
/// points at the person using the device.
@MainActor
@Observable
public final class PushRegistration {
    public private(set) var deviceToken: String?
    public private(set) var registeredToken: String?
    public private(set) var lastError: APIError?

    private let client: APIClient
    private let device: DeviceIdentity
    private static let account = "bh_apns_token"
    private let keychain: Keychain

    public init(client: APIClient, device: DeviceIdentity, keychain: Keychain = Keychain()) {
        self.client = client
        self.device = device
        self.keychain = keychain
        self.deviceToken = keychain.string(for: Self.account)
    }

    /// From `application(_:didRegisterForRemoteNotificationsWithDeviceToken:)`.
    public func didReceive(deviceToken data: Data) {
        let hex = data.map { String(format: "%02x", $0) }.joined()
        deviceToken = hex
        try? keychain.set(hex, for: Self.account)
    }

    public func register(after session: SessionStore) async {
        guard session.state == .signedIn, let token = deviceToken, token != registeredToken else { return }
        do {
            _ = try await client.post("/v1/admin/push/subscribe-device", json: .object([
                "platform": .string(device.platform.rawValue),
                "token": .string(token),
                "model": .string(device.model),
            ]))
            registeredToken = token
            lastError = nil
        } catch let e as APIError {
            lastError = e
        } catch {
            lastError = APIError.transport(error.localizedDescription)
        }
    }

    public func unregister(after session: SessionStore) async {
        guard let token = registeredToken ?? deviceToken else { return }
        _ = try? await client.post("/v1/admin/push/unsubscribe-device", json: .object(["token": .string(token)]))
        registeredToken = nil
    }
}

/// The payload a Hub push carries (ApnsSender): `url` is a console path,
/// `icon` the notification family, `data` the extras.
public struct PushPayload: Sendable {
    public let title: String?
    public let body: String?
    public let route: Route?
    public let icon: String?
    public let data: JSONValue?

    public init(userInfo: [AnyHashable: Any]) {
        let aps = userInfo["aps"] as? [String: Any]
        let alert = aps?["alert"] as? [String: Any]
        title = alert?["title"] as? String
        body = alert?["body"] as? String
        icon = userInfo["icon"] as? String
        route = (userInfo["url"] as? String).flatMap(Route.from(consolePath:))
        if let d = userInfo["data"], let json = try? JSONSerialization.data(withJSONObject: d) {
            data = try? HubJSON.decoder.decode(JSONValue.self, from: json)
        } else {
            data = nil
        }
    }
}
