import XCTest
@testable import BethanyKit

/// Payroll › People & pay and Commissions. Fixtures are real responses from
/// a local Hub on a scratch database (2026-11-10), taken after recording a
/// KES 1,500 commission for Wanjiru (a super admin's entry, approved at once)
/// and adding government deductions for Evans from 1 Dec 2026.
final class PayrollPeopleTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    private lazy var people = json(#"{"data":[{"id":3,"name":"Evans Hermann","salary":null,"deductions":{"applies":false,"since":null,"reason":null,"next":{"applies":true,"from":"2026-12-01"}},"advances_owed":0,"commission":{"owed":0,"pending":0},"last_paid":null},{"id":2,"name":"Wanjiru Kamau","salary":null,"deductions":{"applies":false,"since":null,"reason":null,"next":null},"advances_owed":0,"commission":{"owed":1500,"pending":0},"last_paid":null}],"can_edit":true}"#)

    private lazy var commissions = json(#"{"data":[{"id":1,"user":{"id":2,"name":"Wanjiru Kamau"},"transaction_ref":"ORD-74103291","order":{"id":17,"number":"ORD-74103291"},"amount":1500,"earned_on":"2026-11-09","note":"scratch","status":"approved","recorded_by":"Super Admin","approved_by":"Super Admin","approved_at":"2026-11-10T11:30:45+03:00","run":null,"paid_on":null,"void_reason":null,"can_approve":false,"can_void":true}],"totals":{"awaiting_approval":0,"owed":1500,"paid":0},"paye_applies":false,"can_record":true,"can_approve":true}"#)

    func testAScheduledStartIsShownBesideTheCurrentState() throws {
        let rows = PayPerson.rows(people)
        let evans = try XCTUnwrap(rows.first { $0.id == 3 })
        XCTAssertEqual(evans.deductionsLabel, "No deductions · contract", "the change starts on 1 Dec; it does not apply yet")
        XCTAssertEqual(evans.nextChangeLabel, "Start 1 Dec 2026")
        XCTAssertEqual(evans.deductionsAction, "Add government deductions")
        XCTAssertNil(evans.salary)
        XCTAssertEqual(PayPerson.enrolledCount(rows), 0)
    }

    func testOwedCommissionIsTheServersFigureInShillings() throws {
        let wanjiru = try XCTUnwrap(PayPerson.rows(people).first { $0.id == 2 })
        XCTAssertEqual(wanjiru.commissionOwed, Money(amount: 1500, currency: "KES"))
        XCTAssertTrue(wanjiru.commissionPending.isZero)
        XCTAssertNil(wanjiru.nextChangeLabel)
    }

    func testDeductionsThatApplyNameTheirDateAndOfferToStop() throws {
        let on = try XCTUnwrap(PayPerson(json: json(#"{"id":4,"name":"Amina","salary":{"base_amount":"42000.00","kind":"monthly","effective_from":"2026-01-01"},"deductions":{"applies":true,"since":"2026-03-01","next":{"applies":false,"from":"2027-01-01"}},"advances_owed":"2500.00","commission":{"owed":0,"pending":"300"},"last_paid":{"net":"38110.50","paid_on":"2026-10-31","method":"mpesa","run":"Oct 2026"}}"#)))
        XCTAssertEqual(on.deductionsLabel, "Deductions applied since 1 Mar 2026")
        XCTAssertEqual(on.nextChangeLabel, "Stop 1 Jan 2027")
        XCTAssertEqual(on.deductionsAction, "Stop government deductions")
        XCTAssertEqual(on.salary, Money(amount: 42000, currency: "KES"))
        XCTAssertEqual(on.lastPaidNet?.formatted(), "KES 38,110.50")
        XCTAssertEqual(on.advancesOwed, Money(amount: 2500, currency: "KES"))
        XCTAssertEqual(on.commissionPending, Money(amount: 300, currency: "KES"))
    }

    func testSearchIsByNameWhateverTheCase() {
        let rows = PayPerson.rows(people)
        XCTAssertEqual(PayPerson.filter(rows, query: "  wanj ").map(\.id), [2])
        XCTAssertEqual(PayPerson.filter(rows, query: "").count, 2)
        XCTAssertEqual(PayPerson.filter(rows, query: "zzz").count, 0)
    }

    func testCommissionsCarryTheServersTotalsAndPermissions() throws {
        let page = CommissionsPage(json: commissions)
        XCTAssertEqual(page.owed, Money(amount: 1500, currency: "KES"))
        XCTAssertTrue(page.awaitingApproval.isZero)
        XCTAssertTrue(page.paid.isZero)
        XCTAssertFalse(page.payeApplies)
        XCTAssertTrue(page.canRecord)
        XCTAssertTrue(page.canApprove)
        let c = try XCTUnwrap(page.rows.first)
        XCTAssertEqual(c.statusLabel, "Approved · next pay run")
        XCTAssertNil(c.statusDetail, "no run yet")
        XCTAssertFalse(c.canApprove, "already approved")
        XCTAssertTrue(c.canVoid)
        XCTAssertEqual(c.orderId, 17)
        XCTAssertEqual(c.amount?.formatted(), "KES 1,500.00")
    }

    func testStatusDetailFollowsTheConsole() throws {
        let paid = try XCTUnwrap(CommissionRow(json: json(#"{"id":2,"status":"paid","paid_on":"2026-11-30","run":"Nov 2026"}"#)))
        XCTAssertEqual(paid.statusDetail, "30 Nov 2026 · Nov 2026")
        let void = try XCTUnwrap(CommissionRow(json: json(#"{"id":3,"status":"void","void_reason":"order cancelled"}"#)))
        XCTAssertEqual(void.statusLabel, "Void")
        XCTAssertEqual(void.statusDetail, "order cancelled")
    }

    func testTheRecordDialogsOwnCheck() {
        XCTAssertTrue(CommissionRow.mayRecord(userId: 2, transactionRef: "OR", amount: 1, earnedOn: "2026-11-09"))
        XCTAssertFalse(CommissionRow.mayRecord(userId: nil, transactionRef: "ORD-1", amount: 1, earnedOn: "2026-11-09"))
        XCTAssertFalse(CommissionRow.mayRecord(userId: 2, transactionRef: " O ", amount: 1, earnedOn: "2026-11-09"))
        XCTAssertFalse(CommissionRow.mayRecord(userId: 2, transactionRef: "ORD-1", amount: 0, earnedOn: "2026-11-09"))
        XCTAssertFalse(CommissionRow.mayRecord(userId: 2, transactionRef: "ORD-1", amount: nil, earnedOn: "2026-11-09"))
        XCTAssertFalse(CommissionRow.mayRecord(userId: 2, transactionRef: "ORD-1", amount: 5, earnedOn: ""))
        XCTAssertEqual(CommissionRow.filters.map(\.value), ["open", "recorded", "approved", "paid", "void", ""])
    }

    func testDatesReadAsCalendarDates() {
        XCTAssertEqual(PayrollText.date("2026-12-01"), "1 Dec 2026")
        XCTAssertEqual(PayrollText.date("2026-12-01T00:00:00+03:00"), "1 Dec 2026")
        XCTAssertEqual(PayrollText.date("not a date"), "not a date")
    }
}
