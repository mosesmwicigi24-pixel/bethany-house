import Foundation

/// The signed-in person as `GET /admin/auth/me` returns them (openapi.json
/// `Me`). `permissions` is already the effective list: money report pages the
/// person may not open are stripped server-side by FinancialVisibility.
public struct Me: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let email: String
    public let userType: String?
    public let permissions: [String]
    public let roles: [RoleRef]
    public let isOwner: Bool
    public let moneyView: String?
    public let outlet: OutletRef?
    public let sessionPolicy: SessionPolicy?
    public let terminalPinSet: Bool
    public let twoFactorRequired: Bool
    public let twoFactorEnabled: Bool
    public let recoveryCodesLeft: Int?
    public let currentDevice: JSONValue?
    public let discountCapPercent: Double?
    public let promotionCapPercent: Double?
    public let avatarUrl: String?
    public let raw: JSONValue

    public var isSuperAdmin: Bool { roles.contains { $0.name == "super_admin" } }
    public var roleNames: [String] { roles.map(\.name) }

    public init(json: JSONValue) throws {
        guard let id = json["id"]?.int, let email = json["email"]?.string else {
            throw APIError(status: 0, message: "The sign-in response did not describe a person.")
        }
        self.id = id
        // `/auth/me` sends first_name and last_name, not `name` (verified
        // against a running backend, 2026-10-28); the console builds the
        // display name the same way (Sidebar.tsx). `name` stays a fallback.
        let full = [json["first_name"]?.string, json["last_name"]?.string]
            .compactMap { $0 }
            .filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }
            .joined(separator: " ")
        self.name = full.isEmpty ? (json["name"]?.string ?? "") : full
        self.email = email
        userType = json["user_type"]?.string
        permissions = json["permissions"]?.array?.compactMap(\.string) ?? []
        roles = (json["roles"]?.array ?? []).compactMap { r in
            if let n = r["name"]?.string { return RoleRef(id: r["id"]?.int, name: n, dataScope: r["data_scope"]?.string) }
            if let s = r.string { return RoleRef(id: nil, name: s, dataScope: nil) }
            return nil
        }
        isOwner = json["is_owner"]?.bool ?? false
        moneyView = json["money_view"]?.string
        outlet = json["outlet"].flatMap { o in
            guard let oid = o["id"]?.int else { return nil }
            return OutletRef(id: oid, name: o["name"]?.string ?? "Outlet", raw: o)
        }
        sessionPolicy = json["session_policy"].flatMap(SessionPolicy.init(json:))
        terminalPinSet = json["terminal_pin_set"]?.bool ?? false
        twoFactorRequired = json["two_factor_required"]?.bool ?? false
        twoFactorEnabled = json["two_factor_enabled"]?.bool ?? false
        recoveryCodesLeft = json["recovery_codes_left"]?.int
        currentDevice = json["current_device"]
        discountCapPercent = json["discount_cap_percent"]?.double
        promotionCapPercent = json["promotion_cap_percent"]?.double
        avatarUrl = json["avatar_url"]?.string ?? json["avatar"]?.string
        raw = json
    }

    // Codable through the raw JSON so the cached copy round-trips exactly.
    public init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        try self.init(json: try c.decode(JSONValue.self))
    }

    public func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        try c.encode(raw)
    }
}

public struct RoleRef: Codable, Hashable, Sendable {
    public let id: Int?
    public let name: String
    public let dataScope: String?
}

public struct OutletRef: Codable, Hashable, Sendable, Identifiable {
    public let id: Int
    public let name: String
    public let raw: JSONValue
}

/// What the console shows and plans around: the idle limit that bites first,
/// and whether it signs out or locks behind the terminal PIN.
public struct SessionPolicy: Codable, Hashable, Sendable {
    public enum OnIdle: String, Codable, Sendable { case signOut = "sign_out", pinLock = "pin_lock" }
    public let idleMinutes: Int
    public let absoluteMinutes: Int
    public let onIdle: OnIdle

    public init?(json: JSONValue) {
        guard let i = json["idle_minutes"]?.int, let a = json["absolute_minutes"]?.int else { return nil }
        idleMinutes = i
        absoluteMinutes = a
        onIdle = OnIdle(rawValue: json["on_idle"]?.string ?? "sign_out") ?? .signOut
    }

    public init(idleMinutes: Int, absoluteMinutes: Int, onIdle: OnIdle) {
        self.idleMinutes = idleMinutes
        self.absoluteMinutes = absoluteMinutes
        self.onIdle = onIdle
    }
}

/// Laravel's default paginator shape: `{data, current_page, last_page, per_page, total}`.
public struct Paginated<T: Decodable & Sendable>: Decodable, Sendable {
    public let data: [T]
    public let currentPage: Int
    public let lastPage: Int
    public let perPage: Int
    public let total: Int

    public var hasMore: Bool { currentPage < lastPage }
    public var nextPage: Int? { hasMore ? currentPage + 1 : nil }

    enum CodingKeys: String, CodingKey {
        case data, currentPage = "current_page", lastPage = "last_page", perPage = "per_page", total
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        data = try c.decode([T].self, forKey: .data)
        currentPage = try c.decodeIfPresent(Int.self, forKey: .currentPage) ?? 1
        lastPage = try c.decodeIfPresent(Int.self, forKey: .lastPage) ?? 1
        perPage = try c.decodeIfPresent(Int.self, forKey: .perPage) ?? data.count
        total = try c.decodeIfPresent(Int.self, forKey: .total) ?? data.count
    }

    public init(data: [T], currentPage: Int = 1, lastPage: Int = 1, perPage: Int = 20, total: Int? = nil) {
        self.data = data
        self.currentPage = currentPage
        self.lastPage = lastPage
        self.perPage = perPage
        self.total = total ?? data.count
    }
}

/// A row most list screens can render without a dedicated model: id, a
/// title, a status and the full payload for the detail view.
public struct Record: Identifiable, Hashable, Sendable, Decodable {
    public let id: String
    public let json: JSONValue

    public init(json: JSONValue) {
        self.json = json
        id = json["id"]?.string ?? json["uuid"]?.string ?? UUID().uuidString
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        self.init(json: try c.decode(JSONValue.self))
    }

    public subscript(key: String) -> JSONValue? { json[key] }
    public func string(_ key: String) -> String? { json[key]?.string }
    public func int(_ key: String) -> Int? { json[key]?.int }
    public func money(_ key: String, currency: String? = nil) -> Money? {
        json[key].flatMap { Money(json: $0, currency: currency ?? json["currency"]?.string ?? json["currency_code"]?.string ?? "KES") }
    }
    public func date(_ key: String) -> Date? { json[key]?.date }
    public var status: String? { json["status"]?.string }
}
