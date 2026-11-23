import XCTest
@testable import BethanyKit

/// Clerk PINs. The fixture is the real answer of a local Hub on a scratch
/// database (2026-11-10, GET users/role/pos_clerk), trimmed.
final class ClerkPinsTests: XCTestCase {
    private func json(_ s: String) -> JSONValue { try! HubJSON.decoder.decode(JSONValue.self, from: Data(s.utf8)) }
    private lazy var clerks = json(#"{"data":[{"id":3,"first_name":"Evans","last_name":"Hermann","email":"probe.cashier@example.test","outlet":{"id":1,"code":"WST","name":"Westlands Store"}},{"id":9,"first_name":"Grace","last_name":"Atieno","outlet":{"id":2,"name":"Karen"}},{"id":10,"first_name":"New","last_name":"Clerk","outlet":null}]}"#)

    func testTheOwnerSeesEveryClerkAndAManagerOnlyTheirOutlets() {
        XCTAssertEqual(ClerkPins.rows(clerks, isSuperAdmin: true, myOutletId: nil).map(\.id), [3, 9, 10])
        let mine = ClerkPins.rows(clerks, isSuperAdmin: false, myOutletId: 1)
        XCTAssertEqual(mine.map(\.id), [3])
        XCTAssertEqual(mine.first?.name, "Evans Hermann")
        XCTAssertEqual(mine.first?.outletName, "Westlands Store")
        XCTAssertEqual(ClerkPins.rows(clerks, isSuperAdmin: false, myOutletId: nil), [], "a manager with no outlet sees no one")
    }

    func testOnlyTheOwnerOrAnOutletManagerIsOffered() {
        XCTAssertTrue(ClerkPins.isOffered(roles: [], isSuperAdmin: true))
        XCTAssertTrue(ClerkPins.isOffered(roles: ["outlet_manager"], isSuperAdmin: false))
        XCTAssertFalse(ClerkPins.isOffered(roles: ["pos_clerk", "supervisor"], isSuperAdmin: false))
    }
}
