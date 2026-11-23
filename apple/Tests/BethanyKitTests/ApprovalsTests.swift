import XCTest
@testable import BethanyKit

/// The Approvals page's tabs and its Payment proofs rows.
final class ApprovalsTests: XCTestCase {
    private func perms(_ p: [String], owner: Bool = false) -> PermissionSet { PermissionSet(permissions: p, isSuperAdmin: owner) }

    // MARK: Tabs — the console's gates (ApprovalsPage.tsx), which are the server's

    func testEachTabOpensOnlyForTheKeyTheServerChecks() {
        XCTAssertEqual(ApprovalsTab.visible(for: perms([])), [.mine])
        XCTAssertEqual(ApprovalsTab.visible(for: perms(["payments.approve_international"])), [.mine, .paymentProofs])
        XCTAssertEqual(ApprovalsTab.visible(for: perms(["procurement.approve"])), [.inbox, .mine, .purchaseReturns])
        XCTAssertEqual(ApprovalsTab.visible(for: perms(["expenses.approve"])), [.inbox, .mine])
        XCTAssertEqual(ApprovalsTab.visible(for: perms([], owner: true)), ApprovalsTab.allCases)
        // Recording a payment is not approving one.
        XCTAssertFalse(ApprovalsTab.paymentProofs.isVisible(for: perms(["payments.record", "payments.upload_proof", "payments.view"])))
    }

    func testTheConsolesSigningKeysAreTheOnesThatOpenToSign() {
        XCTAssertEqual(ApprovalsTab.signingPermissions, ["procurement.approve", "inventory.approve", "expenses.approve", "approvals.finance_sign", "payments.void", "payments.reassign"])
        XCTAssertEqual(ApprovalsTab.initial(for: perms(["payments.void"])), .inbox)
        XCTAssertEqual(ApprovalsTab.initial(for: perms(["payments.approve_international"])), .mine)
    }

    // MARK: Rows — a response taken from a local Hub (scratch database, 2026-11-10)

    private let response = #"""
    {"data":[
     {"id":4,"payment_number":"PAY-72802366","payment_method":"bank_transfer","amount":"4500.00","currency_code":"KES","proof_of_payment_path":null,"proof_uploaded_at":null,"approval_status":"pending_review","requires_approval":true,"created_at":"2026-11-10 08:41:18","order_id":6,"order_number":"ORD-98750164","customer_name":"Proof KES","customer_email":null,"customer_phone":null,"customer_country_code":"KE","order_type":"pos","order_total":"4500.00","proof_url":null,"waiting_hours":2},
     {"id":3,"payment_number":"PAY-09521615","payment_method":"bank_transfer","amount":"100.00","currency_code":"USD","proof_of_payment_path":"payment-proofs/scratch.pdf","proof_uploaded_at":"2026-11-10 10:41:18","approval_status":"pending_review","requires_approval":true,"created_at":"2026-11-08 08:41:18","order_id":5,"order_number":"ORD-86136910","customer_name":"Proof USD","customer_email":null,"customer_phone":null,"customer_country_code":"US","order_type":"pos","order_total":"250.00","proof_url":"http://127.0.0.1:8077/api/v1/admin/payments/3/proof","waiting_hours":50}
    ],"meta":{"current_page":1,"last_page":1,"total":2},"pending_count":2}
    """#

    private func rows() throws -> [PendingPaymentProof] {
        PendingPaymentProof.rows(try HubJSON.decoder.decode(JSONValue.self, from: Data(response.utf8)))
    }

    func testADollarPaymentStaysInDollarsBesideItsOrderTotal() throws {
        let usd = try XCTUnwrap(rows().first { $0.id == 3 })
        XCTAssertEqual(usd.amount, Money(amount: 100, currency: "USD"))
        XCTAssertEqual(usd.orderTotal, Money(amount: 250, currency: "USD"), "never restated in shillings")
        XCTAssertEqual(usd.amount?.formatted(), "USD 100.00")
        XCTAssertEqual(usd.orderId, 5)
        XCTAssertEqual(usd.orderNumber, "ORD-86136910")
        XCTAssertTrue(usd.isInternational)
        XCTAssertTrue(usd.isUrgent, "50 hours waiting")
        XCTAssertTrue(usd.hasProof)
        XCTAssertEqual(usd.proofIssuer, "/v1/admin/payments/3/proof", "the signed-link issuer, not the host the server printed")
        XCTAssertEqual(usd.methodLabel, "Bank Transfer")
        // The Hub's wall-clock timestamps are Nairobi time (app.timezone).
        var cal = Calendar(identifier: .gregorian); cal.timeZone = HubDates.nairobi
        let created = try XCTUnwrap(usd.createdAt, "the waiting time needs created_at")
        XCTAssertEqual(cal.dateComponents([.year, .month, .day, .hour, .minute], from: created), DateComponents(year: 2026, month: 11, day: 8, hour: 8, minute: 41))
        XCTAssertNotNil(usd.proofUploadedAt)
    }

    func testAPaymentWithNoProofSaysSoAndIsNotInternationalInKenya() throws {
        let kes = try XCTUnwrap(rows().first { $0.id == 4 })
        XCTAssertFalse(kes.hasProof)
        XCTAssertNil(kes.proofIssuer)
        XCTAssertNil(kes.proofUploadedAt)
        XCTAssertFalse(kes.isInternational)
        XCTAssertFalse(kes.isUrgent)
        XCTAssertEqual(kes.amount, Money(amount: 4500, currency: "KES"))
    }

    func testTheServersOrderIsKeptAndUnreadableRowsAreLeftOut() throws {
        XCTAssertEqual(try rows().map(\.id), [4, 3])
        let partial = try HubJSON.decoder.decode(JSONValue.self, from: Data(#"{"data":[{"id":9},{"order_id":2},{"id":"10","order_id":"7","amount":"5"}]}"#.utf8))
        let r = PendingPaymentProof.rows(partial)
        XCTAssertEqual(r.map(\.id), [10], "a row without a payment or an order is not shown")
        XCTAssertEqual(r.first?.amount, Money(amount: 5, currency: "KES"))
        XCTAssertNil(r.first?.countryCode)
        XCTAssertFalse(r.first?.isInternational ?? true)
    }

    func testAnEmptyQueueIsEmpty() throws {
        XCTAssertEqual(PendingPaymentProof.rows(try HubJSON.decoder.decode(JSONValue.self, from: Data(#"{"data":[],"pending_count":0}"#.utf8))), [])
        XCTAssertEqual(PendingPaymentProof.rows(.null), [])
    }
}
