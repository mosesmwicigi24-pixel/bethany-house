import SwiftUI
import BethanyKit

/// The screen tour's way in (apple/UITests/ScreenTourTests.swift): a one-line
/// field that takes a console path and navigates to it in the running app,
/// exactly as a sidebar click does. Opening `bethanyhub://` links from a UI
/// test hands them to Launch Services, which relaunched the app on both CI
/// runners instead of delivering the link (tour runs of 2026-11-23).
///
/// Present only in a DEBUG build launched with BH_UITEST_ROUTES=1 — never in a
/// release build and never for a person using the app.
struct UITestRouteField: ViewModifier {
    @Environment(AppEnvironment.self) private var env
    @State private var path = ""
    @FocusState private var focused: Bool

    func body(content: Content) -> some View {
        #if DEBUG
        if ProcessInfo.processInfo.environment["BH_UITEST_ROUTES"] == "1" {
            content.overlay(alignment: .topTrailing) {
                TextField("route", text: $path)
                    .font(.system(size: 9))
                    .frame(width: 120)
                    .opacity(0.05)
                    .accessibilityIdentifier("uitest.route")
                    .focused($focused)
                    .onSubmit {
                        if let route = Route.from(consolePath: path) { env.navigator.go(route) }
                        path = ""
                        focused = false   // the keyboard must not cover the screenshot
                    }
            }
        } else {
            content
        }
        #else
        content
        #endif
    }
}

extension View {
    func uiTestRouteField() -> some View { modifier(UITestRouteField()) }
}
