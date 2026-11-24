import XCTest
@testable import BethanyKit

/// Clock in / out must stay available offline when the last status is on
/// screen, so the tap reaches the offline queue (owner, 2026-11-24).
final class ClockGateTests: XCTestCase {
    private let offline = APIError.transport("The Internet connection appears to be offline.")
    private let serverError = APIError(status: 500, message: "Server error")
    private let unauthorised = APIError(status: 401, message: "Session expired")

    func testAFreshStatusAllowsAPunch() {
        XCTAssertTrue(ClockGate.statusUsable(hasStatus: true, error: nil))
    }

    func testLosingTheConnectionKeepsThePunchAvailableForTheQueue() {
        XCTAssertTrue(offline.isOffline)
        XCTAssertTrue(ClockGate.statusUsable(hasStatus: true, error: offline),
                      "a failed refresh while offline must not grey out Clock in")
    }

    func testNoStatusAtAllStillBlocks() {
        XCTAssertFalse(ClockGate.statusUsable(hasStatus: false, error: nil))
        XCTAssertFalse(ClockGate.statusUsable(hasStatus: false, error: offline),
                       "without a status the app cannot tell clock-in from clock-out")
    }

    func testAnErrorFromTheHubStillBlocks() {
        XCTAssertFalse(ClockGate.statusUsable(hasStatus: true, error: serverError))
        XCTAssertFalse(ClockGate.statusUsable(hasStatus: true, error: unauthorised))
    }

    func testAForcedPunchNeedsAFreshStatusBecauseItIsNeverQueued() {
        XCTAssertTrue(ClockGate.forceAllowed(hasStatus: true, error: nil))
        XCTAssertFalse(ClockGate.forceAllowed(hasStatus: true, error: offline))
        XCTAssertFalse(ClockGate.forceAllowed(hasStatus: false, error: nil))
    }
}
