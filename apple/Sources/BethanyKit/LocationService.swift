import Foundation
import CoreLocation
import Observation

/// Geofence maths, identical to the server's `TimeEntry::haversineMeters`
/// and the console's `distanceMeters` (react-admin/src/lib/useGeolocation.ts),
/// so the live "12 m from the shop" matches the server's verdict.
public enum Geofence {
    public static let earthRadiusMeters: Double = 6_371_000

    /// Great-circle distance in metres.
    public static func distanceMeters(lat1: Double, lng1: Double, lat2: Double, lng2: Double) -> Double {
        func rad(_ d: Double) -> Double { d * .pi / 180 }
        let dLat = rad(lat2 - lat1)
        let dLng = rad(lng2 - lng1)
        let a = pow(sin(dLat / 2), 2) + cos(rad(lat1)) * cos(rad(lat2)) * pow(sin(dLng / 2), 2)
        return earthRadiusMeters * 2 * atan2(sqrt(a), sqrt(1 - a))
    }

    public struct Verdict: Sendable, Hashable {
        /// Inside the zone (or no zone to judge against, which the server treats as inside).
        public let inside: Bool
        /// Just outside, within the phone's uncertainty (capped at the radius): accepted and flagged `edge`.
        public let nearEdge: Bool
    }

    /// Mirrors ClockPage.tsx: `distance <= radius` is inside; otherwise
    /// `distance <= radius + min(accuracy, radius)` is "near the edge".
    public static func verdict(distance: Double?, radius: Double?, accuracy: Double?) -> Verdict {
        guard let distance, let radius else { return Verdict(inside: true, nearEdge: false) }
        if distance <= radius { return Verdict(inside: true, nearEdge: false) }
        guard let accuracy else { return Verdict(inside: false, nearEdge: false) }
        return Verdict(inside: false, nearEdge: distance <= radius + min(accuracy, radius))
    }
}

/// One position fix, as the punch endpoints take it.
public struct LocationFix: Sendable, Hashable {
    public let latitude: Double
    public let longitude: Double
    /// Metres of uncertainty, rounded as the console sends it.
    public let accuracy: Double
    public let timestamp: Date

    public init(latitude: Double, longitude: Double, accuracy: Double, timestamp: Date) {
        self.latitude = latitude
        self.longitude = longitude
        self.accuracy = accuracy
        self.timestamp = timestamp
    }

    /// `points[]` row for POST /v1/admin/time-clock/trail (TimeClockController::trail).
    public var trailPoint: JSONValue {
        .object([
            "latitude": .number(latitude),
            "longitude": .number(longitude),
            "accuracy": .number(accuracy),
            "recorded_at": .string(HubDates.iso8601.string(from: timestamp)),
            "mocked": .bool(false),
        ])
    }
}

/// CoreLocation for the clock (and, on iOS, the on-shift trail).
///
/// Location is read only while the clock screen is open, except for the
/// background trail the attendance rules may ask for between clock-in and
/// clock-out. Every delegate callback hops to the main actor; the service
/// exposes plain values so screens never touch `CLLocation`.
@MainActor
@Observable
public final class LocationService: NSObject, CLLocationManagerDelegate {
    public enum Status: Sendable, Equatable {
        case idle, asking, denied, restricted, unavailable(String), locating, fixed
    }

    public private(set) var status: Status = .idle
    public private(set) var fix: LocationFix?
    public private(set) var authorization: CLAuthorizationStatus
    public private(set) var isUpdating = false
    /// Called on every fix while updating (the trail recorder subscribes here).
    public var onFix: (@MainActor (LocationFix) -> Void)?

    private let manager = CLLocationManager()
    private var authWaiters: [CheckedContinuation<CLAuthorizationStatus, Never>] = []
    private var fixWaiters: [CheckedContinuation<LocationFix?, Never>] = []
    private var oneShot = false

    public override init() {
        authorization = manager.authorizationStatus
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
    }

    public var isAuthorized: Bool {
        #if os(iOS)
        return authorization == .authorizedWhenInUse || authorization == .authorizedAlways
        #else
        return authorization == .authorized || authorization == .authorizedAlways
        #endif
    }

    public var isDenied: Bool { authorization == .denied || authorization == .restricted }

    // MARK: Authorization

    /// Asks once and waits for the answer. `always` (iOS) is for the trail.
    @discardableResult
    public func requestAuthorization(always: Bool = false) async -> CLAuthorizationStatus {
        if isDenied { return authorization }
        if isAuthorized && (!always || authorization == .authorizedAlways) { return authorization }
        status = .asking
        return await withCheckedContinuation { c in
            authWaiters.append(c)
            #if os(iOS)
            if always { manager.requestAlwaysAuthorization() } else { manager.requestWhenInUseAuthorization() }
            #else
            manager.requestWhenInUseAuthorization()
            #endif
            // iOS answers through the delegate only when the status changes; an
            // "Always" upgrade the person has already refused never calls back.
            Task { [weak self] in
                try? await Task.sleep(for: .seconds(45))
                await self?.timeOutAuthWaiters()
            }
        }
    }

    private func timeOutAuthWaiters() {
        guard !authWaiters.isEmpty else { return }
        let w = authWaiters
        authWaiters.removeAll()
        if status == .asking { status = isAuthorized ? .idle : .denied }
        for c in w { c.resume(returning: authorization) }
    }

    // MARK: Fixes

    /// Continuous fixes while a screen is open; the first from a cold GPS is
    /// often hundreds of metres wide and tightens over the next seconds.
    public func startUpdating() async {
        let auth = await requestAuthorization()
        guard !(auth == .denied || auth == .restricted) else { status = .denied; return }
        isUpdating = true
        status = fix == nil ? .locating : .fixed
        manager.startUpdatingLocation()
    }

    public func stopUpdating() {
        guard isUpdating else { return }
        isUpdating = false
        manager.stopUpdatingLocation()
        if status == .locating { status = .idle }
    }

    /// One fix, or nil when location is off or nothing arrives in time.
    public func currentFix(timeout: Duration = .seconds(20)) async -> LocationFix? {
        if let fix, Date().timeIntervalSince(fix.timestamp) < 5 { return fix }
        let auth = await requestAuthorization()
        guard !(auth == .denied || auth == .restricted) else { status = .denied; return nil }
        if !isUpdating {
            oneShot = true
            status = .locating
            manager.startUpdatingLocation()
        }
        let waited: LocationFix? = await withCheckedContinuation { c in
            fixWaiters.append(c)
            Task { [weak self] in
                try? await Task.sleep(for: timeout)
                await self?.timeOutFixWaiters()
            }
        }
        return waited
    }

    private func timeOutFixWaiters() {
        guard !fixWaiters.isEmpty else { return }
        let w = fixWaiters
        fixWaiters.removeAll()
        if oneShot { oneShot = false; manager.stopUpdatingLocation(); if status == .locating { status = .unavailable("No fix yet") } }
        for c in w { c.resume(returning: nil) }
    }

    #if os(iOS)
    /// The on-shift trail: keeps delivering fixes with the app in the
    /// background. Requires the `location` background mode and "Always"
    /// authorization; stops when `stopBackgroundTrail()` is called at clock-out.
    public func startBackgroundTrail(distanceMeters: Double) async {
        let auth = await requestAuthorization(always: true)
        guard auth == .authorizedAlways || auth == .authorizedWhenInUse else { status = .denied; return }
        manager.allowsBackgroundLocationUpdates = true
        manager.pausesLocationUpdatesAutomatically = false
        manager.showsBackgroundLocationIndicator = true
        manager.distanceFilter = max(10, distanceMeters)
        manager.desiredAccuracy = kCLLocationAccuracyNearestTenMeters
        isUpdating = true
        status = fix == nil ? .locating : .fixed
        manager.startUpdatingLocation()
    }

    public func stopBackgroundTrail() {
        manager.allowsBackgroundLocationUpdates = false
        manager.distanceFilter = kCLDistanceFilterNone
        manager.desiredAccuracy = kCLLocationAccuracyBest
        isUpdating = false
        manager.stopUpdatingLocation()
        status = .idle
    }
    #endif

    // MARK: CLLocationManagerDelegate (nonisolated; values hop to the main actor)

    nonisolated public func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let auth = manager.authorizationStatus
        Task { @MainActor in self.authorizationChanged(auth) }
    }

    nonisolated public func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let fixes = locations.map { l in
            LocationFix(latitude: l.coordinate.latitude, longitude: l.coordinate.longitude, accuracy: max(0, l.horizontalAccuracy.rounded()), timestamp: l.timestamp)
        }
        Task { @MainActor in self.received(fixes) }
    }

    nonisolated public func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        let message = error.localizedDescription
        let denied = (error as? CLError)?.code == .denied
        Task { @MainActor in self.failed(message, denied: denied) }
    }

    private func authorizationChanged(_ auth: CLAuthorizationStatus) {
        authorization = auth
        if auth == .notDetermined { return }
        if auth == .denied { status = .denied } else if auth == .restricted { status = .restricted }
        let w = authWaiters
        authWaiters.removeAll()
        for c in w { c.resume(returning: auth) }
    }

    private func received(_ fixes: [LocationFix]) {
        guard let last = fixes.last else { return }
        fix = last
        status = .fixed
        if let onFix { for f in fixes { onFix(f) } }
        if !fixWaiters.isEmpty {
            let w = fixWaiters
            fixWaiters.removeAll()
            for c in w { c.resume(returning: last) }
        }
        if oneShot && !isUpdating { oneShot = false; manager.stopUpdatingLocation() }
    }

    private func failed(_ message: String, denied: Bool) {
        status = denied ? .denied : .unavailable(message)
        if denied {
            let w = authWaiters
            authWaiters.removeAll()
            for c in w { c.resume(returning: .denied) }
        }
        if !fixWaiters.isEmpty && (denied || fix == nil) {
            let w = fixWaiters
            fixWaiters.removeAll()
            for c in w { c.resume(returning: nil) }
        }
    }
}
