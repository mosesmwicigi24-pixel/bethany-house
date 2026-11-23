import Foundation

/// Hand-over control for a paid till sale (OrderDetailPage.tsx "Awaiting
/// dispatch authorization"; PosController::authorizeDispatch). The server
/// refuses a cancelled, unpaid or already dispatched order and checks
/// `orders.authorize_dispatch`; this decides what the order page shows.
public enum OrderDispatch {
    public static let permission = "orders.authorize_dispatch"

    public static func isDispatched(_ order: JSONValue) -> Bool {
        guard let d = order["dispatched_at"] else { return false }
        return !d.isNull
    }

    /// A till sale, paid in full, not dispatched and not closed.
    public static func isAwaiting(_ order: JSONValue) -> Bool {
        order["order_type"]?.string == "pos"
            && order["payment_status"]?.string == "paid"
            && !isDispatched(order)
            && !["cancelled", "voided", "refunded"].contains(order["status"]?.string ?? "")
    }
}

/// "Usually bought together" (GET pos/suggestions?product_ids[]=…): up to four
/// companion products from basket history. Advisory: an empty or failed
/// answer simply shows nothing (PosPage.tsx).
public struct TillSuggestion: Identifiable, Hashable, Sendable {
    public let productId: Int
    public let name: String
    public let sku: String?
    /// Share of the anchor's baskets that included this product, as the server rounds it.
    public let attachRatePct: Double
    public let anchorName: String
    public var id: Int { productId }

    public init?(json: JSONValue) {
        guard let pid = json["product_id"]?.int, let name = json["name"]?.string else { return nil }
        productId = pid
        self.name = name
        sku = json["sku"]?.string
        attachRatePct = json["attach_rate_pct"]?.double ?? 0
        anchorName = json["anchor_name"]?.string ?? ""
    }

    /// "Wafer bread · 42%" — the chip's text on the console.
    public var label: String { "\(name) · \(Int(attachRatePct.rounded()))%" }

    /// What a search for this product should ask for: the SKU when it has one.
    public var searchTerm: String { (sku?.isEmpty == false ? sku : nil) ?? name }

    /// The distinct positive product ids in the cart, in ascending order (the request key).
    public static func cartProductIds(_ ids: [Int]) -> [Int] { Array(Set(ids.filter { $0 > 0 })).sorted() }

    /// The request's query. The client's query is a dictionary, so the ids go as
    /// indexed keys (`product_ids[0]=4`), which Laravel reads as the same array.
    public static func query(_ productIds: [Int]) -> [String: String?] {
        Dictionary(uniqueKeysWithValues: cartProductIds(productIds).enumerated().map { ("product_ids[\($0.offset)]", String($0.element)) })
    }

    /// The response's suggestions, less anything already in the cart (it may have changed in flight).
    public static func rows(_ response: JSONValue, excluding inCart: [Int]) -> [TillSuggestion] {
        let held = Set(inCart)
        return (response["suggestions"]?.array ?? []).compactMap(TillSuggestion.init(json:)).filter { !held.contains($0.productId) }
    }
}
