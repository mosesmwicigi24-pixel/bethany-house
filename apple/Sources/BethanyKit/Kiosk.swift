import Foundation
import Observation

/// The clock-in kiosk (People C2) on a shared iPad at the door.
///
/// A manager with attendance.manage sets the tablet up from Attendance ›
/// Kiosks (with step-up). The Hub mints a token on the manager's account
/// with the single ability `kiosk`; the server fences it to the kiosk
/// routes (KioskTokensStayAtTheKiosk) and gives it its own lifetime
/// (security.sessions.kiosk_days). The app keeps it under its own Keychain
/// account, apart from any person's session, and signs the manager out.
///
/// Nothing on the tablet decides who may punch: every punch carries the
/// person's own terminal PIN, which the server checks with its lock-out.
/// Mirrors the Android `KioskStore` / `KioskApi`.
@MainActor
@Observable
public final class KioskStore {
    public static let account = "bh_kiosk_token"
    public static let endedOnHub = "This tablet is no longer a clock-in kiosk: it was removed on the Hub or reached its time limit. A manager can set it up again from People › Attendance › Kiosks."

    public private(set) var token: String?
    /// Why the tablet last stopped being a kiosk, shown once on the sign-in screen.
    public private(set) var ended: String?
    private let keychain: Keychain

    public init(keychain: Keychain = Keychain()) {
        self.keychain = keychain
        self.token = keychain.string(for: Self.account)
    }

    public var isKiosk: Bool { token != nil }

    public func enter(_ token: String) {
        try? keychain.set(token, for: Self.account)
        self.token = token
        ended = nil
    }

    /// Forgets the token; `reason` is shown to whoever picks up the tablet next.
    public func clear(reason: String? = nil) {
        keychain.remove(Self.account)
        token = nil
        ended = reason
    }

    public func acknowledgeEnded() { ended = nil }
}

/// One person on the board, as `GET /kiosk/board` sends them.
public struct KioskPerson: Identifiable, Hashable, Sendable {
    public let id: Int
    public let name: String
    public let initials: String
    public let hasPin: Bool
    /// "in", "break" or "out".
    public let state: String
    public let since: Date?
    /// The outlet they are clocked in at, when it is not this kiosk's.
    public let elsewhere: String?

    public var isIn: Bool { state != "out" }

    public init?(json: JSONValue) {
        guard let id = json["id"]?.int else { return nil }
        self.id = id
        name = json["name"]?.string ?? ""
        initials = json["initials"]?.string ?? ""
        hasPin = json["has_pin"]?.bool ?? false
        state = json["state"]?.string ?? "out"
        since = json["since"]?.date
        elsewhere = json["elsewhere"]?.string
    }
}

public struct KioskBoard: Sendable, Hashable {
    public let kioskName: String
    public let outlet: String?
    public let people: [KioskPerson]
    public var inNow: Int { people.filter(\.isIn).count }

    public init(json: JSONValue?) {
        kioskName = json?["kiosk"]?["name"]?.string ?? "Kiosk"
        outlet = json?["kiosk"]?["outlet"]?.string
        people = (json?["people"]?.array ?? []).compactMap(KioskPerson.init(json:))
    }
}

/// The three kiosk endpoints on their own client, so the kiosk token never
/// mixes with a person's session. A 401 (removed on the Hub, or past
/// kiosk_days) forgets the token and the tablet returns to set-up. The
/// kiosk never waits on a PIN lock or a step-up.
public final class KioskAPI: SessionAuth, @unchecked Sendable {
    public static let base = "/v1/admin/kiosk"
    private let store: KioskStore
    public let client: APIClient

    @MainActor
    public init(baseURL: URL, store: KioskStore, device: DeviceIdentity = .current()) {
        self.store = store
        self.client = APIClient(baseURL: baseURL, device: device)
    }

    /// The client is an actor, so attaching is async; doing it before every call
    /// (an idempotent assignment) means no request can go out without the token.
    private func ready() async { await client.attach(auth: self) }

    public func bearerToken() async -> String? { await MainActor.run { store.token } }
    public func sessionExpired() async { await MainActor.run { store.clear(reason: KioskStore.endedOnHub) } }
    public func awaitPinUnlock() async -> Bool { false }
    public func awaitStepUp(_ challenge: StepUpChallenge) async -> Bool { false }
    public func downloadHeld(_ payload: JSONValue?) async {}

    public func board() async throws -> KioskBoard {
        await ready()
        return KioskBoard(json: try await client.get("\(Self.base)/board", background: true).json)
    }

    /// `POST /kiosk/punch {user_id, pin, action: in|out, photo?}`; the photo is a
    /// `data:image/jpeg;base64,…` URL (the server keeps JPEGs up to 450 KB).
    /// Returns the server's message.
    public func punch(userId: Int, pin: String, clockIn: Bool, photoDataURL: String?) async throws -> String {
        var body: [String: JSONValue] = [
            "user_id": .number(Double(userId)),
            "pin": .string(pin),
            "action": .string(clockIn ? "in" : "out"),
        ]
        if let photoDataURL { body["photo"] = .string(photoDataURL) }
        await ready()
        let r = try await client.post("\(Self.base)/punch", json: .object(body))
        return r.json?["message"]?.string ?? (clockIn ? "Clocked in." : "Clocked out.")
    }

    /// `DELETE /kiosk/token`: the tablet leaves kiosk mode and its token dies on the Hub.
    public func leave() async {
        await ready()
        _ = try? await client.delete("\(Self.base)/token")
        await MainActor.run { store.clear(reason: nil) }
    }
}
