import SwiftUI
import BethanyKit

extension EnvironmentValues {
    /// The path hosting this screen when the shell owns separate tab stacks.
    @Entry var currentRoutePath: Binding<[Route]>? = nil
}

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
        if !env.session.flavor.allows(route) {
            NotInThisAppView()
        } else if let gate = NavCatalogue.gate(for: route), !env.session.allows(gate) {
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

/// A screen Tailors Hub does not carry (reached by a link, a push tap or a
/// button inside another screen). Never the website fallback.
struct NotInThisAppView: View {
    @Environment(AppEnvironment.self) private var env
    var body: some View {
        EmptyState("This page is in Bethany Hub", message: "\(env.session.flavor.appName) has the workshop, your time and pay, and messages. Open Bethany Hub for everything else.", symbol: "rectangle.on.rectangle.slash", actionLabel: "Go home") {
            env.navigator.go(env.session.home)
        }
    }
}

/// Signed in to Tailors Hub without production access.
struct WorkshopOnlyView: View {
    @Environment(AppEnvironment.self) private var env
    var body: some View {
        EmptyState("Tailors Hub is for the workshop", message: "Your account has no production work. Sign in to Bethany Hub instead; it has everything your role opens.", symbol: "scissors", actionLabel: "Sign out") {
            Task { await env.signOutNow() }
        }
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
