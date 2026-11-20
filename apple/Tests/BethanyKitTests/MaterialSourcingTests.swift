import XCTest
@testable import BethanyKit

/// Material sourcing (#573). Fixtures are real answers from a local Hub on a
/// scratch database (2026-11-21), seeded as backend MaterialSourcingTest:
/// a shirt takes 2.5 m of fabric and 8 buttons; two shirts need 5 m and 16;
/// the store holds 1 m of fabric and 120 buttons. Raised with fabric split
/// 1 from store / 4 to buy, buttons from store.
final class MaterialSourcingTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    private lazy var needs = MaterialSourcing.requirements(json(#"{"has_bom":true,"materials":[{"material_id":1,"name":"White cotton fabric","unit":"m","required":5,"free":1},{"material_id":2,"name":"Shirt buttons","unit":"pcs","required":16,"free":120}]}"#))
    private var fabric: MaterialSourcing.Need { needs.needs[0] }
    private var buttons: MaterialSourcing.Need { needs.needs[1] }

    func testTheStoreIsChosenOnlyWhenItHasEnough() {
        XCTAssertTrue(needs.hasBom)
        let c = MaterialSourcing.defaultChoices(needs.needs)
        XCTAssertEqual(c[1]?.mode, .buy, "1 m free of 5 m needed")
        XCTAssertEqual(c[2]?.mode, .store, "120 free of 16 needed")
        XCTAssertNil(MaterialSourcing.problem(fabric, c[1]))
        XCTAssertEqual(MaterialSourcing.summary(needs.needs, c), "On raising: 1 material reserved from the store · 1 requested from procurement. Nothing is ordered until procurement approves it.")
    }

    func testTheStoreCannotGiveMoreThanIsFree() {
        XCTAssertEqual(MaterialSourcing.problem(fabric, .init(mode: .store)), "Only 1 m free in the store.")
        XCTAssertTrue(MaterialSourcing.hasProblem(needs.needs, [1: .init(mode: .store), 2: .init(mode: .store)]))
    }

    func testASplitTakesWhatIsFreeAndBuysTheRest() {
        XCTAssertEqual(MaterialSourcing.splitStart(fabric), "1")
        let choice = MaterialSourcing.Choice(mode: .split, store: "1")
        let s = MaterialSourcing.split(fabric, choice)
        XCTAssertEqual(s.store, 1); XCTAssertEqual(s.buy, 4)
        XCTAssertNil(MaterialSourcing.problem(fabric, choice))
        XCTAssertEqual(MaterialSourcing.outcome(fabric, choice), "1 m will be reserved for this job. 4 m will be requested from procurement.")
        XCTAssertEqual(MaterialSourcing.problem(fabric, .init(mode: .split, store: "")), "Enter how much comes from the store.")
        XCTAssertEqual(MaterialSourcing.problem(buttons, .init(mode: .split, store: "16")), "Less than 16 pcs, or choose From store.")
        XCTAssertFalse(MaterialSourcing.canSplit(.init(materialId: 9, name: "Lace", unit: "m", required: 3, free: 0)))
    }

    func testThePayloadIsWhatTheServerAcceptedOnTheScratchHub() {
        let body = MaterialSourcing.payload(needs.needs, [1: .init(mode: .split, store: "1"), 2: .init(mode: .store)])
        XCTAssertEqual(body, json(#"[{"material_id":1,"from_store":1,"to_buy":4},{"material_id":2,"from_store":16,"to_buy":0}]"#))
        XCTAssertEqual(MaterialSourcing.raisedMessage(json(#"{"message":"Production order raised.","production_order_id":1,"production_order_number":"PO-261121-GBUIM","materials_status":"waiting"}"#)),
                       "Production order PO-261121-GBUIM raised · materials requested from procurement")
    }

    func testTheJobShowsWhereEachMaterialStandsAndChangeStartsFromIt() {
        let job = MaterialSourcing.job(json(#"{"status":"waiting","materials":[{"allocation_id":1,"material_id":1,"name":"White cotton fabric","unit":"m","required":5,"issued":0,"reserved":1,"to_buy":4,"unsourced":0,"free":0},{"allocation_id":2,"material_id":2,"name":"Shirt buttons","unit":"pcs","required":16,"issued":0,"reserved":16,"to_buy":0,"unsourced":0,"free":104}]}"#))
        XCTAssertEqual(job.statusLabel, "Waiting for materials")
        // The job may draw on what is free plus what it already holds: fabric 0 + 1, buttons 104 + 16.
        XCTAssertEqual(job.outstanding.map(\.free), [1, 120])
        XCTAssertEqual(job.currentChoices[1], .init(mode: .split, store: "1"))
        XCTAssertEqual(job.currentChoices[2], .init(mode: .store))
        XCTAssertFalse(MaterialSourcing.hasProblem(job.outstanding, job.currentChoices), "re-saving the current decision is valid")
    }

    func testQuantitiesShowWithoutTrailingZeros() {
        XCTAssertEqual(MaterialSourcing.qty(2.5), "2.5")
        XCTAssertEqual(MaterialSourcing.qty(0.25), "0.25")
        XCTAssertEqual(MaterialSourcing.qty(1250), "1,250")
        XCTAssertEqual(MaterialSourcing.qty(16), "16")
    }
}
