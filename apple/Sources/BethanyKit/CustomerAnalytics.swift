import Foundation

/// GET reports/customers/analytics?period=<days>&outlet_id= — the overview's
/// "Active customers", "VIP customers", segments and spend brackets
/// (react-admin/src/pages/reports/CustomersReportPage.tsx). Counts of people,
/// not money: the brackets' bounds are the server's labels, shown as given.
public struct CustomerAnalytics: Sendable {
    public let periodDays: Int
    public let totalCustomers: Int
    public let newCustomers: Int
    public let activeCustomers: Int
    /// (segment, customers), largest first; ties keep the server's name order.
    public let segments: [(name: String, count: Int)]
    public let spendBrackets: [(label: String, count: Int)]

    public init(json: JSONValue) {
        periodDays = json["period_days"]?.int ?? 0
        let s = json["stats"]
        totalCustomers = s?["total_customers"]?.int ?? 0
        newCustomers = s?["new_customers"]?.int ?? 0
        activeCustomers = s?["active_customers"]?.int ?? 0
        segments = Self.counts(json["segments"]).sorted { $0.1 != $1.1 ? $0.1 > $1.1 : $0.0 < $1.0 }.map { (name: $0.0, count: $0.1) }
        // Brackets are ranges, not a ranking: lowest bound first ("<10k", "10k-50k", "50k+").
        spendBrackets = Self.counts(json["spend_brackets"]).sorted { Self.lowerBound($0.0) < Self.lowerBound($1.0) }.map { (label: $0.0, count: $0.1) }
    }

    public var vip: Int { segments.first { $0.name == "VIP" }?.count ?? 0 }

    private static func counts(_ j: JSONValue?) -> [(String, Int)] {
        guard case .object(let o)? = j else { return [] }
        return o.compactMap { k, v in v.int.map { (k, $0) } }.sorted { $0.0 < $1.0 }
    }

    /// A bracket's lower bound from its label: "<10k" → 0, "10k-50k" → 10 000, "50k+" → 50 000.
    static func lowerBound(_ label: String) -> Double {
        if label.hasPrefix("<") { return 0 }
        var digits = "", rest = Substring(label)
        while let c = rest.first, c.isNumber || c == "." { digits.append(c); rest = rest.dropFirst() }
        guard let n = Double(digits) else { return .greatestFiniteMagnitude }
        switch rest.first?.lowercased() { case "k": return n * 1_000; case "m": return n * 1_000_000; default: return n }
    }

    /// The console's `period`: days in the chosen range, both ends counted, at least 1.
    public static func periodDays(from start: Date, to end: Date, calendar: Calendar) -> Int {
        let a = calendar.startOfDay(for: start), b = calendar.startOfDay(for: end)
        return max(1, (calendar.dateComponents([.day], from: a, to: b).day ?? 0) + 1)
    }
}
