import Foundation

/// The order page's two owner-era money controls, decided exactly as the
/// console decides them (react-admin/src/pages/sales/orders/OrderDetailPage.tsx:
/// BillCurrencyControl, DeletePaymentModal and the payment list). The Hub
/// checks every one of these again; this only decides what the page offers.
public enum OrderBilling {
    /// Has money started to move on the order? Then a change of billing
    /// currency is the super admin's, with a reason (OrderController::billCurrency).
    public static func paymentStarted(_ order: JSONValue) -> Bool {
        if (order["payment_status"]?.string ?? "pending") != "pending" { return true }
        return (order["payments"]?.array ?? []).contains { p in
            ["paid", "processing", "confirmed"].contains(p["status"]?.string ?? "")
                || (p["requires_approval"]?.bool == true && p["approval_status"]?.string == "pending_review")
        }
    }

    /// The console's "dead" states for this control.
    public static func isClosed(_ order: JSONValue) -> Bool {
        ["cancelled", "voided", "refunded"].contains(order["status"]?.string ?? "")
    }

    /// The currencies an order may be billed in: the customer's own sale
    /// currency (when their country has one) and USD, in that order, once each.
    public static func billCurrencyChoices(ownSaleCurrency: String?) -> [String] {
        var out: [String] = []
        for c in [ownSaleCurrency, "USD"].compactMap({ $0 }).filter({ !$0.isEmpty }) where !out.contains(c) { out.append(c) }
        return out
    }

    /// Whether the page offers the "Bill in" control at all.
    public static func offersBillCurrency(_ order: JSONValue, ownSaleCurrency: String?, permissions p: PermissionSet) -> Bool {
        guard order["is_international"]?.bool == true,
              let country = order["customer_country_code"]?.string, !country.isEmpty,
              billCurrencyChoices(ownSaleCurrency: ownSaleCurrency).count >= 2,
              !isClosed(order), p.can("orders.edit") else { return false }
        return !paymentStarted(order) || p.isSuperAdmin
    }

    /// After payment has started the server wants a reason; the console asks for three characters or more.
    public static let billCurrencyReasonMinimum = 3

    /// The owner deletes a payment softly (voided, hidden, kept): super admin only, never twice.
    public static func mayDelete(_ payment: JSONValue, permissions p: PermissionSet) -> Bool {
        p.isSuperAdmin && isRemoved(payment) == false
    }

    /// The console asks for five characters or more before it sends a deletion.
    public static let deleteReasonMinimum = 5

    public static func isRemoved(_ payment: JSONValue) -> Bool {
        guard let r = payment["removed_at"] else { return false }
        return !r.isNull
    }

    /// The payments the list shows: deleted ones only when asked for.
    public static func visiblePayments(_ order: JSONValue, showDeleted: Bool) -> [JSONValue] {
        (order["payments"]?.array ?? []).filter { showDeleted || !isRemoved($0) }
    }

    public static func deletedCount(_ order: JSONValue) -> Int {
        (order["payments"]?.array ?? []).filter(isRemoved).count
    }
}
