import XCTest
@testable import BethanyKit

/// Customers overview analytics. The first fixture is the real answer of a
/// local Hub on a scratch database (2026-11-10, period=30).
final class CustomerAnalyticsTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    func testTheScratchHubsAnswer() {
        let a = CustomerAnalytics(json: json(#"{"period_days":30,"stats":{"total_customers":1,"new_customers":1,"active_customers":1},"segments":{"Repeat":1},"spend_brackets":{"10k-50k":1}}"#))
        XCTAssertEqual(a.periodDays, 30)
        XCTAssertEqual(a.activeCustomers, 1)
        XCTAssertEqual(a.vip, 0, "no VIP segment: zero, not missing")
        XCTAssertEqual(a.segments.map(\.name), ["Repeat"])
        XCTAssertEqual(a.spendBrackets.map(\.label), ["10k-50k"])
    }

    func testSegmentsLargestFirstAndBracketsByTheirLowerBound() {
        let a = CustomerAnalytics(json: json(#"{"stats":{},"segments":{"New":4,"VIP":2,"Repeat":7,"At risk":2},"spend_brackets":{"50k+":1,"<10k":9,"10k-50k":3,"1m+":1}}"#))
        XCTAssertEqual(a.segments.map(\.name), ["Repeat", "New", "At risk", "VIP"], "ties in name order")
        XCTAssertEqual(a.vip, 2)
        XCTAssertEqual(a.spendBrackets.map(\.label), ["<10k", "10k-50k", "50k+", "1m+"])
    }

    func testThePeriodIsTheDaysInTheRangeBothEndsCounted() {
        var cal = Calendar(identifier: .gregorian); cal.timeZone = HubDates.nairobi
        let d = { (s: String) in HubDates.parse(s)! }
        XCTAssertEqual(CustomerAnalytics.periodDays(from: d("2026-11-01 00:00:00"), to: d("2026-11-30 23:59:00"), calendar: cal), 30)
        XCTAssertEqual(CustomerAnalytics.periodDays(from: d("2026-11-10 08:00:00"), to: d("2026-11-10 09:00:00"), calendar: cal), 1)
        XCTAssertEqual(CustomerAnalytics.periodDays(from: d("2026-11-10 08:00:00"), to: d("2026-11-09 09:00:00"), calendar: cal), 1, "never below one")
    }
}
