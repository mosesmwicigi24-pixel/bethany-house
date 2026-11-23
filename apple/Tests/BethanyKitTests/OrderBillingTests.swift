import XCTest
@testable import BethanyKit

/// The order page's "Bill in" control and the owner's payment deletion,
/// decided as the console decides them. Fixtures are real order responses
/// from a local Hub on a scratch database (2026-11-10), trimmed to the
/// fields these rules read; "before" states were edited from them by hand
/// where noted.
final class OrderBillingTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }
    private let owner = PermissionSet(permissions: [], isSuperAdmin: true)
    private let clerk = PermissionSet(permissions: ["orders.edit", "orders.view"], isSuperAdmin: false)
    private let viewer = PermissionSet(permissions: ["orders.view"], isSuperAdmin: false)

    /// GET orders/7 after update-currency {country_code: ZM}: unpaid, billed in kwacha.
    private lazy var zambian = json(#"{"id":7,"order_number":"ORD-20964508","status":"confirmed","payment_status":"pending","currency_code":"ZMW","customer_country_code":"ZM","is_international":true,"total_amount":"0.00","payments":[]}"#)
    /// GET orders/5 after the owner deleted its only payment (payments/3/remove).
    private lazy var afterDeletion = json(#"{"id":5,"order_number":"ORD-86136910","status":"confirmed","payment_status":"pending","currency_code":"USD","customer_country_code":"US","is_international":false,"total_amount":"250.00","payments":[{"id":3,"payment_number":"PAY-09521615","status":"voided","requires_approval":true,"approval_status":"rejected","removed_at":"2026-11-10 11:21:12","removed_reason":"Recorded in error (scratch test)","currency_code":"USD","amount":"100.00","payment_method":"bank_transfer"}]}"#)

    // MARK: Bill in

    func testTheChoicesAreTheCustomersOwnCurrencyThenDollarsOnce() {
        XCTAssertEqual(OrderBilling.billCurrencyChoices(ownSaleCurrency: "ZMW"), ["ZMW", "USD"])
        XCTAssertEqual(OrderBilling.billCurrencyChoices(ownSaleCurrency: "USD"), ["USD"])
        XCTAssertEqual(OrderBilling.billCurrencyChoices(ownSaleCurrency: nil), ["USD"])
        XCTAssertEqual(OrderBilling.billCurrencyChoices(ownSaleCurrency: ""), ["USD"])
    }

    func testAnUnpaidOrderAbroadMayBeRebilledByAnyoneWhoEditsOrders() {
        XCTAssertFalse(OrderBilling.paymentStarted(zambian))
        XCTAssertTrue(OrderBilling.offersBillCurrency(zambian, ownSaleCurrency: "ZMW", permissions: clerk))
        XCTAssertTrue(OrderBilling.offersBillCurrency(zambian, ownSaleCurrency: "ZMW", permissions: owner))
        XCTAssertFalse(OrderBilling.offersBillCurrency(zambian, ownSaleCurrency: "ZMW", permissions: viewer), "orders.edit is the route's key")
    }

    func testNoControlWithoutARealChoiceOrForAKenyanOrder() {
        // A US customer has no sale currency of their own: dollars is the only choice.
        XCTAssertFalse(OrderBilling.offersBillCurrency(zambian, ownSaleCurrency: nil, permissions: owner))
        // The deletion fixture is not international.
        XCTAssertFalse(OrderBilling.offersBillCurrency(afterDeletion, ownSaleCurrency: "USD", permissions: owner))
    }

    func testOnceMoneyMovesOnlyTheOwnerMayRebill() {
        // Edited from the Zambian fixture: one settled payment.
        let paid = json(#"{"status":"confirmed","payment_status":"partial","is_international":true,"customer_country_code":"ZM","payments":[{"status":"paid"}]}"#)
        XCTAssertTrue(OrderBilling.paymentStarted(paid))
        XCTAssertFalse(OrderBilling.offersBillCurrency(paid, ownSaleCurrency: "ZMW", permissions: clerk))
        XCTAssertTrue(OrderBilling.offersBillCurrency(paid, ownSaleCurrency: "ZMW", permissions: owner))
        // A proof awaiting review counts as started, even with the order still "pending".
        let awaiting = json(#"{"payment_status":"pending","payments":[{"status":"pending","requires_approval":true,"approval_status":"pending_review"}]}"#)
        XCTAssertTrue(OrderBilling.paymentStarted(awaiting))
        // A deleted (voided, rejected) payment does not.
        XCTAssertFalse(OrderBilling.paymentStarted(afterDeletion))
    }

    func testAClosedOrderIsNeverRebilled() {
        for s in ["cancelled", "voided", "refunded"] {
            let closed = json(#"{"status":"\#(s)","payment_status":"pending","is_international":true,"customer_country_code":"ZM","payments":[]}"#)
            XCTAssertFalse(OrderBilling.offersBillCurrency(closed, ownSaleCurrency: "ZMW", permissions: owner), s)
        }
    }

    // MARK: Delete a payment (owner)

    func testOnlyTheOwnerDeletesAndADeletedPaymentIsNotDeletedTwice() {
        let live = json(#"{"id":9,"status":"paid","removed_at":null}"#)
        XCTAssertTrue(OrderBilling.mayDelete(live, permissions: owner))
        XCTAssertFalse(OrderBilling.mayDelete(live, permissions: clerk))
        let deleted = afterDeletion["payments"]![0]!
        XCTAssertTrue(OrderBilling.isRemoved(deleted))
        XCTAssertFalse(OrderBilling.mayDelete(deleted, permissions: owner))
        XCTAssertEqual(OrderBilling.deleteReasonMinimum, 5, "the server's min:5")
    }

    func testDeletedPaymentsAreHiddenUntilAskedFor() {
        XCTAssertEqual(OrderBilling.visiblePayments(afterDeletion, showDeleted: false).count, 0)
        XCTAssertEqual(OrderBilling.visiblePayments(afterDeletion, showDeleted: true).count, 1)
        XCTAssertEqual(OrderBilling.deletedCount(afterDeletion), 1)
        XCTAssertEqual(OrderBilling.deletedCount(zambian), 0)
        // A payment with no removed_at key at all (older responses) is live.
        XCTAssertFalse(OrderBilling.isRemoved(json(#"{"id":1,"status":"paid"}"#)))
    }
}
