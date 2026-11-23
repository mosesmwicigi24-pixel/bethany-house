import SwiftUI
import BethanyKit

/// Everything an app shell wires once and every screen reads from the
/// environment: the session, toasts, connection state, realtime, offline
/// store and the navigator.
@MainActor
@Observable
public final class AppEnvironment {
    public let session: SessionStore
    public let toasts = Toasts()
    public let connection = ConnectionMonitor()
    public let navigator = Navigator()
    public let biometrics = BiometricLock()
    /// Clock-in kiosk mode (People C2): its own token and client, apart from any person's session.
    public let kiosk: KioskStore
    public let kioskAPI: KioskAPI
    public private(set) var realtime: RealtimeClient?
    public private(set) var offline: OfflineStore?
    private var offlineUserId: Int?

    public struct Config: Sendable {
        public var hubURL: URL
        public var reverbKey: String?
        public var reverbHost: String?
        public var reverbPort: Int
        public var reverbScheme: String
        /// Bethany Hub or Tailors Hub (`AppFlavor`).
        public var flavor: AppFlavor

        /// Production defaults; a dev build points `hubURL` at a local stack.
        public init(hubURL: URL = URL(string: "https://hub.bethanyhouse.co.ke")!,
                    reverbKey: String? = nil, reverbHost: String? = nil, reverbPort: Int = 443, reverbScheme: String = "wss",
                    flavor: AppFlavor = .hub) {
            self.flavor = flavor
            self.hubURL = hubURL
            self.reverbKey = reverbKey
            self.reverbHost = reverbHost ?? hubURL.host
            self.reverbPort = reverbPort
            self.reverbScheme = reverbScheme
        }
    }

    public let config: Config

    public init(config: Config = Config()) {
        self.config = config
        self.session = SessionStore(baseURL: config.hubURL.appendingPathComponent("api"), flavor: config.flavor)
        let kioskStore = KioskStore()
        self.kiosk = kioskStore
        self.kioskAPI = KioskAPI(baseURL: config.hubURL.appendingPathComponent("api"), store: kioskStore, device: session.device)
        // Push is Bethany Hub's only: the server sends to one app identity
        // (APNS_BUNDLE_ID) and drops any other app's device token, so Tailors
        // Hub does not register until the server can address it.
        if config.flavor == .hub {
            session.pushRegistrar = PushRegistration(client: session.client, device: session.device)
        }
    }

    /// Console URL for a route, for sharing and for the bridge view.
    public func consoleURL(for route: Route) -> URL {
        config.hubURL.appendingPathComponent("admin" + route.consolePath)
    }

    /// Once per app run. Each window (Mac: ⌘N, a link; iPad: a second scene)
    /// runs its `.task`; a second start would restore the session again and
    /// open a second realtime connection without closing the first.
    public func start() async {
        guard !started else { return }
        started = true
        await session.start()
        await sessionDidChange()
    }
    @ObservationIgnored private var started = false

    /// Resolve the signed-in person's store even when the shell is still
    /// finishing its session-change observation.
    public func offlineStoreForCurrentUser() -> OfflineStore? {
        guard session.state == .signedIn, let me = session.me else { return nil }
        if offlineUserId != me.id || offline == nil {
            offline = OfflineStore(userId: me.id)
            offlineUserId = me.id
        }
        return offline
    }

    /// Call after sign-in/sign-out: realtime and offline follow the person.
    public func sessionDidChange() async {
        if let me = session.me, session.state == .signedIn {
            _ = offlineStoreForCurrentUser()
            if let key = config.reverbKey, let host = config.reverbHost {
                let rt = RealtimeClient(configuration: .init(appKey: key, host: host, port: config.reverbPort, scheme: config.reverbScheme), client: session.client)
                realtime = rt
                await rt.connect()
                await rt.subscribe("user.\(me.id)") { [weak self] event in
                    Task { @MainActor in self?.handleNotificationPushed(event) }
                }
            }
            await refreshPendingCount()
        } else {
            await realtime?.disconnect()
            realtime = nil
            offline = nil
            offlineUserId = nil
        }
    }

    public var unreadNotifications = 0
    /// People requests waiting on this manager (leave, swaps, advances, timesheet fixes) — the sidebar badge.
    public var peopleRequests = 0

    /// The sidebar's counts, as the console's Sidebar and Topbar fetch them every minute:
    /// GET notifications/unread-count and, for holders of attendance.view_team, GET people/requests/count.
    /// Background requests: they do not count as activity for the idle lock.
    public func refreshCounts() async {
        let c = session.client
        if let n = try? await c.get("/v1/admin/notifications/unread-count", background: true).json?["count"]?.int { unreadNotifications = n }
        if session.can("attendance.view_team") {
            if let n = try? await c.get("/v1/admin/people/requests/count", background: true).json?["count"]?.int { peopleRequests = n }
        } else { peopleRequests = 0 }
    }

    private func handleNotificationPushed(_ event: RealtimeClient.Event) {
        guard event.name == ".notification.pushed" || event.name == "notification.pushed" else { return }
        unreadNotifications += 1
        if let title = event.payload?["title"]?.string { toasts.show(title, tone: .info) }
    }

    /// The pending badge, from a checked read: an unreadable queue is flagged
    /// (`pendingUnreadable`), never shown as nothing waiting.
    public func refreshPendingCount() async {
        guard let offline else { connection.pendingCount = 0; connection.pendingUnreadable = false; return }
        do {
            connection.pendingCount = try await offline.pendingChecked().count
            connection.pendingUnreadable = false
        } catch {
            connection.pendingUnreadable = true
        }
    }

    /// Replays queued offline operations when a connection returns. Every
    /// send goes through `OfflineStore.send`, the same path as a screen's
    /// "Send now", so nothing is sent twice.
    public func replayPending() async {
        guard let offline, connection.isOnline else { return }
        let result = await offline.replay(with: session.client)
        await refreshPendingCount()
        if result.unreadable {
            toasts.show("Changes saved on this device could not be read, so they were not sent. Don't sign out; ask a manager for help.", tone: .danger)
        }
        if !result.sent.isEmpty { toasts.show("\(result.sent.count) saved change\(result.sent.count == 1 ? "" : "s") sent") }
        if !result.notCleared.isEmpty {
            toasts.show("\(result.notCleared.count) sent change\(result.notCleared.count == 1 ? " was" : "s were") received by the Hub but could not be cleared from this device. They will not be sent again.", tone: .warning)
        }
        for (op, e) in result.refused { toasts.show("\(op.kind.humanized) was refused: \(e.message)", tone: .danger) }
    }
}

/// Where the person is, per window. Routes are values, so deep links, push
/// taps, the command palette and the sidebar all go through `go(_:)`.
@MainActor
@Observable
public final class Navigator {
    public var selection: Route?
    public var path: [Route] = []
    public var searchPresented = false
    /// A record waiting for its section's stack to appear (see `go(_:)`).
    public private(set) var pending: Route?

    public init() {}

    public func go(_ route: Route) {
        pending = nil
        if let item = NavCatalogue.item(for: route) {
            selection = item.route
            path = []
        } else if let parent = Navigator.parent(of: route) {
            if selection == parent {
                path = [route]
            } else {
                // Another section: show it first, then open the record once
                // its stack is on screen (openPending). A stack created with
                // the record already in its path showed a blank page or the
                // record twice on the iPad (UI tour run 11).
                selection = parent
                path = []
                pending = route
            }
        } else {
            selection = route
            path = []
        }
    }

    public func push(_ route: Route) { path.append(route) }

    /// Called by the shell when the stack for `section` has appeared.
    public func openPending(in section: Route?) {
        guard let route = pending, section == selection else { return }
        pending = nil
        // Dropped if the person chose another section in the meantime.
        if Navigator.parent(of: route) == section { path = [route] }
    }

    /// The path binding for the stack showing `section`. A stack that is
    /// being replaced (the section changed) writes its own path back as it
    /// goes; that write is ignored, or it would wipe the record `go(_:)` just
    /// opened in the new section (UI tour, 2026-11-23: records reached from
    /// another section showed their list, every time on the iPad).
    public func pathBinding(for section: Route?) -> Binding<[Route]> {
        Binding(
            get: { self.path },
            set: { new in if self.selection == section { self.path = new } }
        )
    }

    /// The sidebar entry a detail route belongs under (`Route.parent`, BethanyKit).
    public static func parent(of route: Route) -> Route? { route.parent }
}

/// Touch every user interaction so the client-side idle clock matches the server's.
public struct ActivityTracking: ViewModifier {
    @Environment(SessionStore.self) private var session
    public func body(content: Content) -> some View {
        content.simultaneousGesture(TapGesture().onEnded { session.touch() })
    }
}

public extension View {
    func tracksActivity() -> some View { modifier(ActivityTracking()) }
}
