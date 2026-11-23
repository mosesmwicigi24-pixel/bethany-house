import Foundation

/// One entry of GET activity-logs/record/{type}/{id} — the super admin's
/// "History" on an order, customer or product (components/audit/AuditParts.tsx
/// RecordHistory). `properties` arrives as a JSON *string*; its `changes` are
/// the field-level old → new values.
public struct RecordHistoryEntry: Identifiable, Hashable, Sendable {
    public struct Change: Hashable, Sendable {
        public let field: String
        public let old: String
        public let new: String
    }

    public let id: Int
    public let action: String
    public let who: String
    public let description: String
    public let createdAt: Date?
    public let ip: String?
    public let changes: [Change]

    public init?(json: JSONValue) {
        guard let id = json["id"]?.int else { return nil }
        self.id = id
        action = json["action"]?.string ?? json["event"]?.string ?? "updated"
        let name = json["user_name"]?.string?.trimmingCharacters(in: .whitespaces) ?? ""
        who = name.isEmpty ? "System" : name
        description = json["description"]?.string ?? ""
        createdAt = json["created_at"]?.date
        ip = json["ip_address"]?.string
        var props = json["properties"]
        if let s = props?.string, let d = s.data(using: .utf8) { props = try? HubJSON.decoder.decode(JSONValue.self, from: d) }
        if case .object(let c)? = props?["changes"] {
            changes = c.keys.sorted().map { k in
                Change(field: k, old: Self.text(c[k]?["old"]), new: Self.text(c[k]?["new"]))
            }
        } else { changes = [] }
    }

    static func text(_ v: JSONValue?) -> String {
        switch v {
        case .none, .some(.null): return "—"
        case .some(.string(let s)): return s.isEmpty ? "—" : s
        case .some(let other): return other.string ?? "\(other)"
        }
    }

    public static func page(_ json: JSONValue) -> (entries: [RecordHistoryEntry], page: Int, lastPage: Int) {
        ((json["data"]?.array ?? []).compactMap(RecordHistoryEntry.init(json:)), json["current_page"]?.int ?? 1, json["last_page"]?.int ?? 1)
    }
}
