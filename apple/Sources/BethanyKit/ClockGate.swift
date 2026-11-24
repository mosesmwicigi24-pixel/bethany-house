import Foundation

/// When the clock screen may offer Clock in / Clock out (owner and Codex,
/// 2026-11-24). The screen needs the last known status (it says whether the
/// person is clocked in and at which outlet), but losing the connection must
/// not lock them out: the punch then goes to the offline queue with the
/// phone's time as `recorded_at` and is sent once on reconnect
/// (`OfflineStore.send`). So the status is usable when it is on screen and
/// either fresh or only failed for being offline. Any other failure (an
/// error from the Hub) still blocks, as does having no status at all.
public enum ClockGate {
    public static func statusUsable(hasStatus: Bool, error: APIError?) -> Bool {
        guard hasStatus else { return false }
        guard let error else { return true }
        return error.isOffline
    }

    /// A forced punch (outside the geofence, with a reason) is never queued:
    /// the Hub must judge it live. It needs a fresh status.
    public static func forceAllowed(hasStatus: Bool, error: APIError?) -> Bool {
        hasStatus && error == nil
    }
}
