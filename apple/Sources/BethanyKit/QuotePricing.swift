import Foundation

/// A product enters a quotation at its price IN THE QUOTATION'S CURRENCY
/// (owner, 2026-11-14; console QuotationsPage.tsx `priceNewLines`, #566).
///
/// The product search answers the KES base price. That figure is never put on
/// a line: it is sent to POST /quotations/reprice `{from: "KES", to, lines}`,
/// and the line takes the server's answer — the product's own price in that
/// currency (`catalogue`), else its KES price at the configured pricing rate
/// (`converted`), else the figure is left and flagged (`kept`). If the request
/// fails, the line is set to 0 and flagged: never a shilling figure under
/// another currency's label.
public enum QuotePricing {
    public struct NewLine: Sendable {
        public let key: String
        public let productId: String?
        /// The search's KES base price (sale price, else list price), as sent to the server.
        public let kesPrice: JSONValue?
        public let name: String
        public init(key: String, productId: String?, kesPrice: JSONValue?, name: String) {
            self.key = key; self.productId = productId; self.kesPrice = kesPrice; self.name = name
        }
    }

    public struct Priced: Equatable, Sendable {
        public let unitPrice: String
        /// No price in this currency on the hub — the operator must type it.
        public let kept: Bool
    }

    /// The KES base price a product search row carries: an active sale price, else the list price.
    public static func kesPrice(of product: JSONValue) -> JSONValue? {
        let bp = product["base_price"]
        for k in ["sale_price", "regular_price"] { if let v = bp?[k], !v.isNull { return v } }
        return nil
    }

    public static func body(to currency: String, lines: [NewLine]) -> JSONValue {
        .object([
            "from": .string("KES"), "to": .string(currency),
            "lines": .array(lines.map { l in
                var o: [String: JSONValue] = [
                    "key": .string(l.key),
                    "unit_price": l.kesPrice ?? .number(0),
                    "name": .string(l.name.isEmpty ? "This line" : l.name),
                ]
                if let p = l.productId { o["product_id"] = .string(p) }
                return .object(o)
            }),
        ])
    }

    /// The server's answer, by line key.
    public static func answer(_ json: JSONValue) -> [String: Priced] {
        var out: [String: Priced] = [:]
        for l in json["lines"]?.array ?? [] {
            guard let key = l["key"]?.string else { continue }
            out[key] = Priced(unitPrice: l["unit_price"]?.string ?? "0", kept: l["source"]?.string == "kept")
        }
        return out
    }

    /// What a line becomes when the request fails: 0, flagged.
    public static let failed = Priced(unitPrice: "0", kept: true)

    public static func keptWarning(count: Int, currency: String) -> String {
        "\(count) product\(count == 1 ? " has" : "s have") no \(currency) price on the hub — type the price before sending."
    }
}
