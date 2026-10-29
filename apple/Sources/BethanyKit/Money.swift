import Foundation

/// A monetary amount as the server computed it.
///
/// Clients never sum, convert or round money (CLAUDE.md §6, docs/api/README.md).
/// `Money` exists so a figure can be displayed exactly as received: decimal
/// strings are parsed as `Decimal`, never as `Double`, and the currency rides
/// along so a KES and a USD figure can never be added by accident (there is
/// deliberately no `+`).
public struct Money: Hashable, Sendable {
    public let amount: Decimal
    public let currency: String

    public init(amount: Decimal, currency: String = "KES") {
        self.amount = amount
        self.currency = currency
    }

    /// From a JSON field: "1234.50", 1234.5 or 1234.
    public init?(json: JSONValue, currency: String = "KES") {
        switch json {
        case .string(let s):
            guard let d = Decimal(string: s.replacingOccurrences(of: ",", with: ""), locale: Locale(identifier: "en_US_POSIX")) else { return nil }
            self.init(amount: d, currency: currency)
        case .number(let n):
            // The server sent a JSON number; go through its decimal text so
            // 0.1 + 0.2 style binary noise never reaches the screen.
            guard let d = Decimal(string: String(format: "%.6f", n), locale: Locale(identifier: "en_US_POSIX")) else { return nil }
            self.init(amount: d, currency: currency)
        default:
            return nil
        }
    }

    public var isZero: Bool { amount == 0 }
    public var isNegative: Bool { amount < 0 }

    /// "KES 1,234.50" — the currency code first, as every Hub document prints it.
    public func formatted(decimals: Int = 2) -> String {
        let f = NumberFormatter()
        f.numberStyle = .decimal
        f.minimumFractionDigits = decimals
        f.maximumFractionDigits = decimals
        f.locale = Locale(identifier: "en_KE")
        let text = f.string(from: amount as NSDecimalNumber) ?? "\(amount)"
        return "\(currency) \(text)"
    }
}

/// The server's shape for a list of rows in several currencies
/// (`App\Support\MoneyByCurrency`): a KES total at each row's own reporting
/// rate, the native figures beside it, and the unconvertible ones named.
/// Never a sum across currencies.
public struct MoneyByCurrency: Sendable, Hashable {
    public struct Line: Sendable, Hashable {
        public let currency: String
        public let native: Money?
        public let kes: Money?
        public let raw: JSONValue
    }

    public let kesTotal: Money?
    public let byCurrency: [Line]
    public let unconverted: [JSONValue]

    public init?(json: JSONValue?) {
        guard let json, let o = json.object else { return nil }
        kesTotal = o["kes_total"].flatMap { Money(json: $0, currency: "KES") }
        byCurrency = (o["by_currency"]?.array ?? []).map { row in
            let code = row["currency"]?.string ?? row["code"]?.string ?? "?"
            return Line(
                currency: code,
                native: (row["native"] ?? row["total"] ?? row["amount"]).flatMap { Money(json: $0, currency: code) },
                kes: (row["kes"] ?? row["kes_total"]).flatMap { Money(json: $0, currency: "KES") },
                raw: row
            )
        }
        unconverted = o["unconverted"]?.array ?? []
    }
}
