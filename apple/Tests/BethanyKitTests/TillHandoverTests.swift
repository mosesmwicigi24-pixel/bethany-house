import XCTest
@testable import BethanyKit

/// Dispatch authorisation and "Usually bought together". Fixtures are real
/// responses from a local Hub on a scratch database (2026-11-10).
final class TillHandoverTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    // GET orders/17 before and after POST pos/sales/17/dispatch.
    private lazy var before = json(#"{"id": 17, "order_type": "pos", "status": "confirmed", "payment_status": "paid", "dispatched_at": null}"#)
    private lazy var after = json(#"{"id": 17, "order_type": "pos", "status": "completed", "payment_status": "paid", "dispatched_at": "2026-11-10T08:26:46.000000Z"}"#)

    func testAPaidTillSaleAwaitsDispatchUntilItIsReleased() {
        XCTAssertTrue(OrderDispatch.isAwaiting(before))
        XCTAssertFalse(OrderDispatch.isDispatched(before))
        XCTAssertFalse(OrderDispatch.isAwaiting(after), "the server refuses a second dispatch")
        XCTAssertTrue(OrderDispatch.isDispatched(after))
        XCTAssertNotNil(after["dispatched_at"]?.date, "the released-on time must read, microseconds and Z included")
    }

    func testOnlyAFullyPaidOpenTillSaleAwaitsDispatch() {
        XCTAssertFalse(OrderDispatch.isAwaiting(json(#"{"order_type":"pos","status":"confirmed","payment_status":"partial","dispatched_at":null}"#)))
        XCTAssertFalse(OrderDispatch.isAwaiting(json(#"{"order_type":"online","status":"confirmed","payment_status":"paid","dispatched_at":null}"#)), "web orders ship; they are not handed over at a till")
        for s in ["cancelled", "voided", "refunded"] {
            XCTAssertFalse(OrderDispatch.isAwaiting(json(#"{"order_type":"pos","status":"\#(s)","payment_status":"paid"}"#)), s)
        }
        XCTAssertEqual(OrderDispatch.permission, "orders.authorize_dispatch", "the route's middleware key")
    }

    // GET pos/suggestions?product_ids[]=4 after five Cope+Stole baskets.
    private lazy var suggestions = json(#"{"suggestions":[{"product_id":5,"name":"dignissimos-veniam-assumenda-consequatur-voluptas-dolorem-perspiciatis","sku":"SCRATCH-STOLE","attach_rate_pct":100,"avg_value":500,"anchor_name":"ea-recusandae-qui-praesentium-a","price":null}]}"#)

    func testASuggestionReadsAsTheConsoleChipAndSearchesBySku() throws {
        let s = try XCTUnwrap(TillSuggestion.rows(suggestions, excluding: [4]).first)
        XCTAssertEqual(s.productId, 5)
        XCTAssertEqual(s.label, "dignissimos-veniam-assumenda-consequatur-voluptas-dolorem-perspiciatis · 100%")
        XCTAssertEqual(s.searchTerm, "SCRATCH-STOLE")
        XCTAssertEqual(s.anchorName, "ea-recusandae-qui-praesentium-a")
    }

    func testWhatIsAlreadyInTheCartIsNeverSuggested() {
        XCTAssertEqual(TillSuggestion.rows(suggestions, excluding: [4, 5]), [], "the cart changed while the request was in flight")
        XCTAssertEqual(TillSuggestion.rows(json(#"{"suggestions":[]}"#), excluding: []), [], "the scratch Hub's answer with no basket history")
        XCTAssertEqual(TillSuggestion.rows(.null, excluding: []), [], "a failed call shows nothing")
    }

    func testTheRequestKeyIsTheCartsDistinctProductsInOrder() {
        XCTAssertEqual(TillSuggestion.cartProductIds([7, 3, 7, 0, -1, 3]), [3, 7])
        XCTAssertEqual(TillSuggestion.cartProductIds([]), [])
        // Checked against the scratch Hub: product_ids[0]=4&product_ids[1]=6 answers as product_ids[]=4.
        XCTAssertEqual(TillSuggestion.query([6, 4, 6]), ["product_ids[0]": "4", "product_ids[1]": "6"])
        XCTAssertTrue(TillSuggestion.query([]).isEmpty)
    }

    func testANameStandsInForAMissingSku() throws {
        let s = try XCTUnwrap(TillSuggestion(json: json(#"{"product_id":9,"name":"Wafer bread","sku":null,"attach_rate_pct":41.6,"anchor_name":"Cope"}"#)))
        XCTAssertEqual(s.searchTerm, "Wafer bread")
        XCTAssertEqual(s.label, "Wafer bread · 42%")
    }
}
