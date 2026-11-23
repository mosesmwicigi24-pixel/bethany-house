import XCTest
@testable import BethanyKit

/// Parity with the console's production rules (Production Cycles 5 and 10)
/// and Android's ProductionParityTest. Expected lists are written out by hand
/// from the clergy measurement sheet, not computed from `MeasurementSheet.order`.
final class MeasurementSheetTests: XCTestCase {
    private func obj(_ d: [String: JSONValue]) -> JSONValue { .object(d) }

    func testMeasurementsFollowTheClergySheetNotTheAlphabet() {
        let m = MeasurementSheet.of(obj([
            "full_length": .string("56"), "chest": .string("42"), "neck": .string("17.5"),
            "arm_hole": .string("9"), "shoulders": .string("18"), "sleeves": .string("25"),
        ]))
        XCTAssertEqual(m.body.map(\.0), ["neck", "shoulders", "sleeves", "arm_hole", "chest", "full_length"])
    }

    func testTheLadiesSheetReadsTopDown() {
        let m = MeasurementSheet.of(obj([
            "Blouse Length": .string("24"), "Hips": .string("40"), "Bodice": .string("36"),
            "Waist": .string("30"), "Neck": .string("14"), "Full Length": .string("54"),
        ]))
        XCTAssertEqual(m.body.map(\.0), ["Neck", "Bodice", "Waist", "Hips", "Blouse Length", "Full Length"])
    }

    func testGenderIsWhoItIsForNotAMeasurement() {
        let m = MeasurementSheet.of(obj(["gender": .string("ladies"), "hips": .string("40")]))
        XCTAssertEqual(m.gender, "ladies")
        XCTAssertEqual(m.body.map(\.0), ["hips"])
    }

    func testOlderSpellingsFindTheirPlaceAndUnknownFieldsComeAfter() {
        let m = MeasurementSheet.of(obj([
            "cape_drop": .string("10"), "length": .string("56"), "collar_style": .string("roman"), "shoulder": .string("18"),
        ]))
        XCTAssertEqual(m.body.map(\.0), ["shoulder", "length", "cape_drop", "collar_style"])
    }

    func testNumbersAndEmptiesAreHandled() {
        let m = MeasurementSheet.of(obj(["neck": .number(17.5), "chest": .string(""), "waist": .null]))
        XCTAssertEqual(m.body.map(\.0), ["neck"])
        XCTAssertEqual(m.body.first?.1, "17.5")
        XCTAssertNil(m.gender)
        XCTAssertTrue(MeasurementSheet.of(nil).body.isEmpty)
    }

    func testRowsPutWhoItIsForFirst() {
        let rows = MeasurementSheet.rows(obj(["hips": .string("40"), "gender": .string("ladies"), "neck": .string("14")]))
        XCTAssertEqual(rows.map(\.0), ["for", "neck", "hips"])
        XCTAssertEqual(rows.first?.1, "Ladies")
    }

    func testTheReworkReasonStartsFromTheLatestInspectionNote() {
        let notes = "Purple trim\n\nQC: First check: collar loose\n\nRework: Collar loose\n\nQC: One cassock: hem stitching uneven"
        XCTAssertEqual(MeasurementSheet.lastQcNote(notes), "One cassock: hem stitching uneven")
    }

    func testABareFailedIsNoReason() {
        XCTAssertNil(MeasurementSheet.lastQcNote("QC: Failed"))
        XCTAssertNil(MeasurementSheet.lastQcNote("QC: Passed"))
        XCTAssertNil(MeasurementSheet.lastQcNote("No inspection yet"))
        XCTAssertNil(MeasurementSheet.lastQcNote(nil))
    }
}
