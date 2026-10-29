import Foundation

/// A dynamic JSON value.
///
/// The Hub API has no response envelope and most payloads are ad-hoc
/// (docs/api/README.md). Typed models decode the fields they rely on and keep
/// the rest reachable through `JSONValue`, so a screen never loses a field the
/// console shows merely because the Swift model did not name it.
public enum JSONValue: Codable, Hashable, Sendable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case null
    case array([JSONValue])
    case object([String: JSONValue])

    public init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null; return }
        if let b = try? c.decode(Bool.self) { self = .bool(b); return }
        if let n = try? c.decode(Double.self) { self = .number(n); return }
        if let s = try? c.decode(String.self) { self = .string(s); return }
        if let a = try? c.decode([JSONValue].self) { self = .array(a); return }
        if let o = try? c.decode([String: JSONValue].self) { self = .object(o); return }
        throw DecodingError.dataCorruptedError(in: c, debugDescription: "Unsupported JSON value")
    }

    public func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let s): try c.encode(s)
        case .number(let n): try c.encode(n)
        case .bool(let b): try c.encode(b)
        case .null: try c.encodeNil()
        case .array(let a): try c.encode(a)
        case .object(let o): try c.encode(o)
        }
    }

    // MARK: Accessors

    public subscript(key: String) -> JSONValue? {
        if case .object(let o) = self { return o[key] }
        return nil
    }

    public subscript(index: Int) -> JSONValue? {
        if case .array(let a) = self, a.indices.contains(index) { return a[index] }
        return nil
    }

    public var string: String? {
        switch self {
        case .string(let s): return s
        case .number(let n): return n == n.rounded() && abs(n) < 1e15 ? String(Int64(n)) : String(n)
        case .bool(let b): return b ? "true" : "false"
        default: return nil
        }
    }

    public var int: Int? {
        switch self {
        case .number(let n): return Int(exactly: n.rounded())
        case .string(let s): return Int(s)
        case .bool(let b): return b ? 1 : 0
        default: return nil
        }
    }

    public var double: Double? {
        switch self {
        case .number(let n): return n
        case .string(let s): return Double(s)
        default: return nil
        }
    }

    public var bool: Bool? {
        switch self {
        case .bool(let b): return b
        case .number(let n): return n != 0
        case .string(let s): return ["1", "true", "yes"].contains(s.lowercased()) ? true : (["0", "false", "no", ""].contains(s.lowercased()) ? false : nil)
        default: return nil
        }
    }

    public var array: [JSONValue]? {
        if case .array(let a) = self { return a }
        return nil
    }

    public var object: [String: JSONValue]? {
        if case .object(let o) = self { return o }
        return nil
    }

    public var isNull: Bool {
        if case .null = self { return true }
        return false
    }

    /// Money fields arrive as decimal strings on some endpoints and numbers on
    /// others (audit backlog B12); either way they are never floats here.
    public var money: Money? { Money(json: self) }

    /// A date-time string, Nairobi wall-clock unless it carries a zone.
    public var date: Date? {
        guard let s = string else { return nil }
        return HubDates.parse(s)
    }

    public func decode<T: Decodable>(_ type: T.Type) -> T? {
        guard let data = try? JSONEncoder().encode(self) else { return nil }
        return try? HubJSON.decoder.decode(T.self, from: data)
    }
}

public enum HubJSON {
    public static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .custom { decoder in
            let c = try decoder.singleValueContainer()
            let s = try c.decode(String.self)
            guard let date = HubDates.parse(s) else {
                throw DecodingError.dataCorruptedError(in: c, debugDescription: "Unrecognised date: \(s)")
            }
            return date
        }
        return d
    }()

    public static let encoder: JSONEncoder = {
        let e = JSONEncoder()
        e.dateEncodingStrategy = .custom { date, encoder in
            var c = encoder.singleValueContainer()
            try c.encode(HubDates.iso8601.string(from: date))
        }
        return e
    }()
}

/// The clock: `app.timezone` is Africa/Nairobi and DB timestamps are Nairobi
/// wall-clock (CLAUDE.md appendix). A string without a zone is read in Nairobi,
/// never in the device's zone and never shifted to UTC.
public enum HubDates {
    public static let nairobi = TimeZone(identifier: "Africa/Nairobi")!

    public static let iso8601: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    private static let isoNoFraction: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    private static let wallClock: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = nairobi
        f.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return f
    }()

    private static let wallClockT: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = nairobi
        f.dateFormat = "yyyy-MM-dd'T'HH:mm:ss"
        return f
    }()

    private static let dateOnly: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = nairobi
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    public static func parse(_ s: String) -> Date? {
        iso8601.date(from: s)
            ?? isoNoFraction.date(from: s)
            ?? wallClock.date(from: String(s.prefix(19)))
            ?? wallClockT.date(from: String(s.prefix(19)))
            ?? dateOnly.date(from: s)
    }

    /// Calendar day in Nairobi as "yyyy-MM-dd", the form every report filter takes.
    public static func dayString(_ date: Date) -> String { dateOnly.string(from: date) }
}
