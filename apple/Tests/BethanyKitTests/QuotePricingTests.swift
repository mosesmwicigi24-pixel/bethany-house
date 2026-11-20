import XCTest
@testable import BethanyKit

/// A picked product enters a quotation at its price in the quotation's
/// currency. Fixtures are real answers of POST /quotations/reprice from a local
/// Hub on a scratch database (2026-11-20): product 4 has its own USD 171.50 and
/// KES 22,000; product 5 only KES 10; product 6 no price. Rates: USD 0.01,
/// ZMW 0.14 per KES (pricing).
final class QuotePricingTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }

    private lazy var usd = json(#"{"currency_code":"USD","lines":[{"key":"a","unit_price":171.5,"source":"catalogue"},{"key":"b","unit_price":0.1,"source":"converted"},{"key":"c","unit_price":0,"source":"converted"}],"summary":{"catalogue":1,"converted":2,"kept":0},"warnings":[]}"#)
    private lazy var zmw = json(#"{"currency_code":"ZMW","lines":[{"key":"a","unit_price":3080,"source":"converted"},{"key":"b","unit_price":1.4,"source":"converted"},{"key":"c","unit_price":0,"source":"converted"}],"summary":{"catalogue":0,"converted":3,"kept":0},"warnings":[]}"#)

    func testTheRequestSendsTheKESFigureNeverAsTheLinePrice() {
        let row = json(#"{"id":4,"sku":"SCRATCH-COPE","base_price":{"regular_price":"22000.00","sale_price":null}}"#)
        let body = QuotePricing.body(to: "USD", lines: [.init(key: "a", productId: "4", kesPrice: QuotePricing.kesPrice(of: row), name: "")])
        XCTAssertEqual(body["from"]?.string, "KES")
        XCTAssertEqual(body["to"]?.string, "USD")
        let line = body["lines"]?.array?.first
        XCTAssertEqual(line?["unit_price"]?.string, "22000.00")
        XCTAssertEqual(line?["product_id"]?.string, "4")
        XCTAssertEqual(line?["name"]?.string, "This line", "an unnamed line still names itself in a warning")
    }

    func testASalePriceIsPreferredAndNoPriceSendsZero() {
        XCTAssertEqual(QuotePricing.kesPrice(of: json(#"{"base_price":{"regular_price":"1000","sale_price":"800"}}"#))?.string, "800")
        XCTAssertNil(QuotePricing.kesPrice(of: json(#"{"base_price":null}"#)))
        let body = QuotePricing.body(to: "USD", lines: [.init(key: "c", productId: "6", kesPrice: nil, name: "Candle")])
        XCTAssertEqual(body["lines"]?.array?.first?["unit_price"], .number(0))
    }

    func testAUSDQuoteTakesTheOwnUSDPriceAndKeepsCents() {
        let p = QuotePricing.answer(usd)
        XCTAssertEqual(p["a"], .init(unitPrice: "171.5", kept: false), "the product's own USD price, not 22,000")
        XCTAssertEqual(p["b"], .init(unitPrice: "0.1", kept: false), "KES 10 at 0.01 is USD 0.10, not rounded to 0")
        XCTAssertEqual(p["c"]?.kept, false)
    }

    func testWithoutOwnPriceTheKESPriceIsConvertedAtThePricingRate() {
        // 22,000 × 0.14 = 3,080; 10 × 0.14 = 1.40 — worked by hand.
        let p = QuotePricing.answer(zmw)
        XCTAssertEqual(p["a"]?.unitPrice, "3080")
        XCTAssertEqual(p["b"]?.unitPrice, "1.4")
    }

    func testAKeptLineIsFlaggedAndAFailureNeverLeavesAFigure() {
        // Shape of the server's "kept" branch (QuotationController::reprice) — not captured:
        // no currency on the scratch Hub lacks a pricing rate.
        let kept = QuotePricing.answer(json(#"{"lines":[{"key":"x","unit_price":0,"source":"kept"}],"warnings":["Wafer has no EUR price."]}"#))
        XCTAssertEqual(kept["x"]?.kept, true)
        XCTAssertEqual(QuotePricing.failed, .init(unitPrice: "0", kept: true))
        XCTAssertEqual(QuotePricing.keptWarning(count: 1, currency: "USD"), "1 product has no USD price on the hub — type the price before sending.")
        XCTAssertEqual(QuotePricing.keptWarning(count: 2, currency: "ZMW"), "2 products have no ZMW price on the hub — type the price before sending.")
    }
}
