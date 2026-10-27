import XCTest
@testable import BethanyKit

final class GeofenceTests: XCTestCase {
    func testSamePointIsZeroMetres() {
        XCTAssertEqual(Geofence.distanceMeters(lat1: -1.2921, lng1: 36.8219, lat2: -1.2921, lng2: 36.8219), 0, accuracy: 0.0001)
    }

    func testOneDegreeOfLatitudeIsAboutOneHundredElevenKilometres() {
        // Haversine on a 6 371 000 m sphere, the server's constant.
        let d = Geofence.distanceMeters(lat1: 0, lng1: 0, lat2: 1, lng2: 0)
        XCTAssertEqual(d, 111_194.9, accuracy: 1)
    }

    func testNairobiToMombasaMatchesTheServersFormula() {
        // Independent figure: the same haversine computed by hand with R = 6 371 km.
        let d = Geofence.distanceMeters(lat1: -1.2921, lng1: 36.8219, lat2: -4.0435, lng2: 39.6682)
        XCTAssertEqual(d, 439_937, accuracy: 500)
    }

    func testVerdictInsideEdgeAndOutside() {
        XCTAssertEqual(Geofence.verdict(distance: 40, radius: 100, accuracy: 10), .init(inside: true, nearEdge: false))
        XCTAssertEqual(Geofence.verdict(distance: 100, radius: 100, accuracy: 10), .init(inside: true, nearEdge: false), "on the line is inside")
        XCTAssertEqual(Geofence.verdict(distance: 108, radius: 100, accuracy: 10), .init(inside: false, nearEdge: true))
        XCTAssertEqual(Geofence.verdict(distance: 111, radius: 100, accuracy: 10), .init(inside: false, nearEdge: false))
    }

    func testEdgeAllowanceIsCappedAtTheRadius() {
        // A 500 m uncertainty does not stretch a 100 m zone beyond 200 m (min(accuracy, radius)).
        XCTAssertEqual(Geofence.verdict(distance: 199, radius: 100, accuracy: 500).nearEdge, true)
        XCTAssertEqual(Geofence.verdict(distance: 201, radius: 100, accuracy: 500).nearEdge, false)
    }

    func testNoZoneCountsAsInside() {
        XCTAssertEqual(Geofence.verdict(distance: nil, radius: nil, accuracy: 5), .init(inside: true, nearEdge: false))
        XCTAssertEqual(Geofence.verdict(distance: 50, radius: nil, accuracy: 5).inside, true)
    }

    func testTrailPointCarriesTheKeysTheServerValidates() {
        let fix = LocationFix(latitude: -1.5, longitude: 36.5, accuracy: 12, timestamp: Date(timeIntervalSince1970: 1_700_000_000))
        let p = fix.trailPoint
        XCTAssertEqual(p["latitude"]?.double, -1.5)
        XCTAssertEqual(p["longitude"]?.double, 36.5)
        XCTAssertEqual(p["accuracy"]?.double, 12)
        XCTAssertEqual(p["mocked"]?.bool, false)
        XCTAssertNotNil(p["recorded_at"]?.date)
    }
}
