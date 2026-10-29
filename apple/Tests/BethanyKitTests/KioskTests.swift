import XCTest
@testable import BethanyKit

/// The kiosk board shape (`GET /kiosk/board`) and the kiosk token's own store.
/// Mirrors android/core/src/test/.../KioskTest.kt.
final class KioskTests: XCTestCase {
    private func json(_ s: String) -> JSONValue {
        try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8))
    }

    func testBoardDecodesTheTeamAndWhoIsIn() {
        let board = KioskBoard(json: json(#"{"kiosk":{"id":4,"name":"Front door","outlet_id":1,"outlet":"Westlands"},"people":[{"id":7,"name":"Amina Njeri","initials":"AN","has_pin":true,"state":"in","since":"2026-10-29T08:02:00+03:00","elsewhere":null},{"id":8,"name":"Baraka Otieno","initials":"BO","has_pin":false,"state":"out","since":null,"elsewhere":null},{"id":9,"name":"Chebet","initials":"C","has_pin":true,"state":"break","since":"2026-10-29T07:00:00+03:00","elsewhere":"CBD"}],"server_time":"2026-10-29T09:00:00+03:00"}"#))
        XCTAssertEqual(board.kioskName, "Front door")
        XCTAssertEqual(board.outlet, "Westlands")
        XCTAssertEqual(board.people.map(\.id), [7, 8, 9])
        XCTAssertEqual(board.inNow, 2, "in and on break both count as in")
        XCTAssertEqual(board.people.map(\.hasPin), [true, false, true])
        XCTAssertNotNil(board.people[0].since)
        XCTAssertEqual(board.people[2].elsewhere, "CBD")
    }

    func testAPersonWithoutAnIdIsSkipped() {
        let board = KioskBoard(json: json(#"{"kiosk":{"name":"Door"},"people":[{"name":"No id"}]}"#))
        XCTAssertTrue(board.people.isEmpty)
    }

    @MainActor
    func testTheKioskTokenLivesUnderItsOwnKeyAndEndsWithANote() {
        let keychain = Keychain(service: "ke.co.bethanyhouse.hub.tests.\(UUID().uuidString)")
        defer { keychain.remove(KioskStore.account) }
        let store = KioskStore(keychain: keychain)
        XCTAssertFalse(store.isKiosk)
        store.enter("9|kiosk")
        XCTAssertTrue(store.isKiosk)
        XCTAssertEqual(KioskStore(keychain: keychain).token, "9|kiosk", "survives a restart")
        store.clear(reason: KioskStore.endedOnHub)
        XCTAssertFalse(store.isKiosk)
        XCTAssertEqual(store.ended, KioskStore.endedOnHub)
        store.acknowledgeEnded()
        XCTAssertNil(store.ended)
        XCTAssertNil(KioskStore(keychain: keychain).token)
    }
}
