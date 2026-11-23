import XCTest
@testable import BethanyKit

/// BOM line cost sources (#563). The fixture is a real GET products/8/bom
/// from a local Hub on a scratch database (2026-11-21): fabric last bought on
/// PO-OLD-1, buttons set by hand (CHG-1), lining with no cost on record.
final class BomCostTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    private lazy var bom = json(#"{"total_cost": 1048, "uncosted_count": 1, "items": [{"quantity": 2.5, "line_cost": 1000, "material": {"id": 1, "name": "White cotton fabric", "unit_of_measure": "m", "cost_per_unit": 400, "cost_source": {"kind": "purchase", "at": "2026-10-03 10:15:00", "ref": "PO-OLD-1", "by": null}}}, {"quantity": 8, "line_cost": 48, "material": {"id": 2, "name": "Shirt buttons", "unit_of_measure": "pcs", "cost_per_unit": 6, "cost_source": {"kind": "manual", "at": "2026-11-21 01:16:45", "ref": "CHG-1", "by": "Super Admin"}}}, {"quantity": 1.5, "line_cost": 0, "material": {"id": 3, "name": "Satin lining", "unit_of_measure": "m", "cost_per_unit": 0, "cost_source": null}}]}"#)
    private var items: [JSONValue] { bom["items"]?.array ?? [] }

    func testEachCostSaysWhereItCameFrom() {
        XCTAssertEqual(BomCost.sourceText(items[0]["material"]?["cost_source"]), "last bought 3 Oct · PO-OLD-1")
        XCTAssertEqual(BomCost.sourceText(items[1]["material"]?["cost_source"]), "set by Super Admin, 21 Nov")
        XCTAssertNil(BomCost.sourceText(items[2]["material"]?["cost_source"]))
        XCTAssertNil(BomCost.sourceText(json(#"{"kind":"unknown","at":null,"ref":null,"by":null}"#)), "an unknown origin says nothing")
    }

    func testANairobiMidnightStaysOnItsOwnDay() {
        // 00:30 in Nairobi is the previous day in UTC; the day shown is Nairobi's.
        XCTAssertEqual(BomCost.sourceText(json(#"{"kind":"manual","at":"2026-11-10 00:30:00","by":null}"#)), "set, 10 Nov")
    }

    func testTheTotalLeavesOutUncostedLinesAndSaysSo() {
        // 2.5 × 400 + 8 × 6 = 1,048, worked by hand; lining has no cost.
        XCTAssertEqual(bom["total_cost"]?.double, 1048)
        XCTAssertEqual(items.map(BomCost.hasCost), [true, true, false])
        XCTAssertEqual(BomCost.uncostedNote(bom["uncosted_count"]?.int ?? 0), "+ 1 material without a cost")
        XCTAssertEqual(BomCost.uncostedNote(2), "+ 2 materials without a cost")
        XCTAssertNil(BomCost.uncostedNote(0))
    }

    func testOnlyAPositiveCostCanBeSet() {
        XCTAssertEqual(BomCost.validCost(" 450.50 "), 450.5)
        XCTAssertNil(BomCost.validCost("0"))
        XCTAssertNil(BomCost.validCost("-3"))
        XCTAssertNil(BomCost.validCost(""))
    }
}
