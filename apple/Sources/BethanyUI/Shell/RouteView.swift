import SwiftUI
import BethanyKit

/// Resolves a `Route` to its screen. Every route the catalogue knows is
/// listed here; the feature registry decides whether a native screen exists
/// yet. Until one does, `ConsoleBridgeView` shows the same page of the Hub
/// console inside the app with the person's session, so no capability is
/// missing while modules are ported — the checklist in
/// docs/NATIVE_APPS_CHECKLIST.md tracks which routes are native.
public struct RouteView: View {
    @Environment(AppEnvironment.self) private var env
    let route: Route

    public init(_ route: Route) { self.route = route }

    public var body: some View {
        if let gate = NavCatalogue.item(for: route)?.gate, !env.session.allows(gate) {
            NoAccessView(route: route)
        } else if let screen = FeatureRegistry.screen(for: route) {
            screen
        } else {
            ConsoleBridgeView(route: route)
        }
    }
}

/// Native screens register here. `AnyView` keeps the registry a flat table;
/// each module file adds its routes in `FeatureRegistry.register()`.
@MainActor
public enum FeatureRegistry {
    public typealias Builder = @MainActor (Route) -> AnyView
    private static var builders: [(matches: (Route) -> Bool, build: Builder)] = []
    private static var registered = false

    public static func register(_ matches: @escaping (Route) -> Bool, _ build: @escaping Builder) {
        builders.append((matches, build))
    }

    public static func register(_ route: Route, _ build: @escaping Builder) {
        register({ $0 == route }, build)
    }

    public static func screen(for route: Route) -> AnyView? {
        bootstrap()
        return builders.first { $0.matches(route) }?.build(route)
    }

    public static func isNative(_ route: Route) -> Bool {
        bootstrap()
        return builders.contains { $0.matches(route) }
    }

    private static func bootstrap() {
        guard !registered else { return }
        registered = true
        Features.registerAll()
    }
}

struct NoAccessView: View {
    @Environment(AppEnvironment.self) private var env
    let route: Route
    var body: some View {
        EmptyState("You don't have access to this page", message: "Ask a manager if you think you should. Navigation follows permission; the server enforces it too.", symbol: "lock", actionLabel: "Go home") {
            env.navigator.go(env.session.home)
        }
    }
}
