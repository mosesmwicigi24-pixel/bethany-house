import XCTest
@testable import BethanyKit

/// What a customer reads (owner, 2026-10-30): whole amounts without ".00",
/// cents only when there are some. The first cases are the server's own
/// (backend/tests/Feature/CustomerMoneyTest.php, CustomerMoney::format).
/// Mirrors android/core/src/test/.../CustomerMoneyTest.kt.
final class CustomerMoneyTests: XCTestCase {
    private func m(_ s: String, _ cur: String = "KES") -> Money {
        Money(amount: Decimal(string: s, locale: Locale(identifier: "en_US_POSIX"))!, currency: cur)
    }

    func testAWholeAmountShowsNoCents() {
        XCTAssertEqual(m("4500.00").customerFormatted(), "KES 4,500")
        XCTAssertEqual(m("3231", "ZAR").customerFormatted(), "ZAR 3,231")
        XCTAssertEqual(m("0.00").customerFormatted(), "KES 0")
        XCTAssertEqual(m("1000000").customerFormatted(), "KES 1,000,000")
    }

    func testCentsShowWhenThereAreSome() {
        XCTAssertEqual(m("137.93").customerFormatted(), "KES 137.93")
        XCTAssertEqual(m("72.80", "USD").customerFormatted(), "USD 72.80", "an older amount with cents is never hidden")
    }

    func testLessThanHalfACentReadsAsWholeAsOnTheServer() {
        XCTAssertEqual(m("4500.004").customerFormatted(), "KES 4,500")
        XCTAssertEqual(m("4500.006").customerFormatted(), "KES 4,500.01")
    }

    func testNegativesAndTheStaffFormatAreUnchanged() {
        XCTAssertEqual(m("-1200.00").customerFormatted(), "KES -1,200")
        XCTAssertEqual(m("4500").formatted(), "KES 4,500.00")
    }
}
