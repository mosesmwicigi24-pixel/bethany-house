import SwiftUI
import BethanyKit

/// The screen tour's way in (apple/UITests/ScreenTourTests.swift): a tiny
/// button that opens the next console path of the tour, exactly as a sidebar
/// click does, and reports the path it opened as its accessibility value.
///
/// The tour passes its list of paths at launch in BH_UITEST_ROUTES (one per
/// line). Earlier ways in failed on CI (tour runs of 2026-11-23): opening
/// `bethanyhub://` links relaunched the app, and a hidden text field stopped
/// taking keyboard focus on the iPad after the first screen. A button needs
/// neither.
///
/// Present only in a DEBUG build launched with BH_UITEST_ROUTES — never in a
/// release build and never for a person using the app.
struct UITestRouteField: ViewModifier {
    @Environment(AppEnvironment.self) private var env
    #if DEBUG
    /// Where the tour is; outlives the shell, which is rebuilt after a sign-in.
    @MainActor @Observable final class Cursor { static let shared = Cursor(); var index = -1 }
    @State private var cursor = Cursor.shared

    private static let paths: [String] = (ProcessInfo.processInfo.environment["BH_UITEST_ROUTES"] ?? "")
        .split(separator: "\n").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
    #endif

    func body(content: Content) -> some View {
        #if DEBUG
        if !Self.paths.isEmpty {
            content.overlay(alignment: .bottomLeading) {
                Button("next") {
                    guard cursor.index + 1 < Self.paths.count else { return }
                    cursor.index += 1
                    env.session.touch()   // a person's click; the button sits outside tracksActivity
                    if let route = Route.from(consolePath: Self.paths[cursor.index]) { env.navigator.go(route) }
                }
                .buttonStyle(.plain)
                .font(.system(size: 9))
                .frame(width: 44, height: 44)
                .contentShape(Rectangle())
                .opacity(0.05)
                .accessibilityIdentifier("uitest.next")
                .accessibilityValue(cursor.index >= 0 ? Self.paths[cursor.index] : "start")
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
