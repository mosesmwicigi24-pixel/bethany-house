import XCTest
@testable import BethanyKit

/// Expected labels are the console's (OrderRow.tsx orderChannel), case by case.
final class OrderChannelLabelTests: XCTestCase {
    func testTheStoredBucketWins() {
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: "till", orderType: "online"), "POS")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: "web", orderType: nil), "Web")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: "quoted", orderType: "pos"), "Quoted")
    }

    func testLegacyRowsAreNamedByOrderType() {
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: nil, orderType: "pos"), "POS")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: nil, orderType: "online"), "Web")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: nil, orderType: "whatsapp"), "Chat")
    }

    func testAChatSaleNamesItsSource() {
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: "chat", orderType: nil, sourceChannel: "instagram"), "Instagram")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: "chat", orderType: nil, sourceChannel: "walk_in"), "Walk-in")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: "chat", orderType: nil, sourceChannel: "pigeon"), "Chat")
    }

    func testAnUnknownTypeIsShownAsItIsAndNothingIsADash() {
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: nil, orderType: "wholesale"), "wholesale")
        XCTAssertEqual(OrderChannelLabel.of(salesBucket: nil, orderType: nil), "—")
    }

    func testReadFromARow() throws {
        let row = try JSONDecoder().decode(JSONValue.self, from: Data(#"{"order_type":"whatsapp","source_channel":"whatsapp"}"#.utf8))
        XCTAssertEqual(OrderChannelLabel.of(row), "WhatsApp")
    }
}
