import XCTest
import CoreLocation
@testable import BethanyKit

/// Location failures read as words, never "(kCLErrorDomain error 1.)" (UI tour run 11).
final class LocationWordsTests: XCTestCase {
    private func clError(_ code: CLError.Code) -> Error { NSError(domain: kCLErrorDomain, code: code.rawValue) }

    func testDeniedSaysHowToTurnItOn() {
        let w = LocationService.words(for: clError(.denied))
        XCTAssertTrue(w.contains("Settings"), w)
        XCTAssertFalse(w.contains("kCLErrorDomain"), w)
    }

    func testUnknownAndNetworkAreNamed() {
        XCTAssertTrue(LocationService.words(for: clError(.locationUnknown)).contains("can't tell where it is"))
        XCTAssertTrue(LocationService.words(for: clError(.network)).contains("network"))
    }

    func testOtherCodesKeepTheirNumberButNotTheDomain() {
        let w = LocationService.words(for: clError(.headingFailure))
        XCTAssertTrue(w.contains("(\(CLError.Code.headingFailure.rawValue))"), w)
        XCTAssertFalse(w.contains("kCLErrorDomain"), w)
    }

    func testOtherDomainsPassThrough() {
        let e = NSError(domain: "x", code: 7, userInfo: [NSLocalizedDescriptionKey: "plain"])
        XCTAssertEqual(LocationService.words(for: e), "plain")
    }
}
