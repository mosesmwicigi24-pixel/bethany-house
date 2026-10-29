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
    public private(set) var realtime: RealtimeClient?
    public private(set) var offline: OfflineStore?

    public struct Config: Sendable {
        public var hubURL: URL
        public var reverbKey: String?
        public var reverbHost: String?
        public var reverbPort: Int
        public var reverbScheme: String

        /// Production defaults; a dev build points `hubURL` at a local stack.
        public init(hubURL: URL = URL(string: "https://hub.bethanyhouse.co.ke")!,
                    reverbKey: String? = nil, reverbHost: String? = nil, reverbPort: Int = 443, reverbScheme: String = "wss") {
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
        self.session = SessionStore(baseURL: config.hubURL.appendingPathComponent("api"))
        let push = PushRegistration(client: session.client, device: session.device)
        session.pushRegistrar = push
    }

    /// Console URL for a route, for sharing and for the bridge view.
    public func consoleURL(for route: Route) -> URL {
        config.hubURL.appendingPathComponent("admin" + route.consolePath)
    }

    public func start() async {
        await session.start()
        await sessionDidChange()
    }

    /// Call after sign-in/sign-out: realtime and offline follow the person.
    public func sessionDidChange() async {
        if let me = session.me, session.state == .signedIn {
            offline = OfflineStore(userId: me.id)
            if let key = config.reverbKey, let host = config.reverbHost {
                let rt = RealtimeClient(configuration: .init(appKey: key, host: host, port: config.reverbPort, scheme: config.reverbScheme), client: session.client)
                realtime = rt
                await rt.connect()
                await rt.subscribe("user.\(me.id)") { [weak self] event in
                    Task { @MainActor in self?.handleNotificationPushed(event) }
                }
            }
            connection.pendingCount = await offline?.pending().count ?? 0
        } else {
            await realtime?.disconnect()
            realtime = nil
            offline = nil
        }
    }

    public var unreadNotifications = 0

    private func handleNotificationPushed(_ event: RealtimeClient.Event) {
        guard event.name == ".notification.pushed" || event.name == "notification.pushed" else { return }
        unreadNotifications += 1
        if let title = event.payload?["title"]?.string { toasts.show(title, tone: .info) }
    }

    /// Replays queued offline operations when a connection returns.
    public func replayPending() async {
        guard let offline, connection.isOnline else { return }
        let result = await offline.replay(with: session.client)
        connection.pendingCount = await offline.pending().count
        if !result.sent.isEmpty { toasts.show("\(result.sent.count) saved change\(result.sent.count == 1 ? "" : "s") sent") }
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

    public init() {}

    public func go(_ route: Route) {
        if let item = NavCatalogue.item(for: route) {
            selection = item.route
            path = []
        } else if let parent = Navigator.parent(of: route) {
            selection = parent
            path = [route]
        } else {
            selection = route
            path = []
        }
    }

    public func push(_ route: Route) { path.append(route) }

    /// The sidebar entry a detail route belongs under.
    public static func parent(of route: Route) -> Route? {
        switch route {
        case .order: return .orders()
        case .shipment: return .shipments
        case .customer: return .customers
        case .handoff: return .orders()
        case .productionOrder: return .productionOrders
        case .adjustment: return .adjustments
        case .transfer: return .transfers
        case .purchaseOrder: return .purchaseOrders
        case .grn: return .goodsReceipt
        case .purchaseReturn: return .purchaseReturns
        case .expense: return .expenses
        case .product(let id, let new): return (id != nil || new) ? .products : nil
        case .people(let id): return id != nil ? .people() : nil
        case .updates(let id): return id != nil ? .updates() : nil
        case .handbook(let id): return id != nil ? .handbook() : nil
        case .courses(let id): return id != nil ? .courses() : nil
        case .sign(let id): return id != nil ? .sign() : nil
        case .comms(let id): return id != nil ? .comms() : nil
        default: return nil
        }
    }
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
