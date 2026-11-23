import XCTest
import BethanyKit

/// The screen tour: signs in to a throwaway Hub (apple/UITests/Fixtures, CI
/// job "UI tour" in .github/workflows/apple-ui.yml), then opens every screen in
/// the navigation catalogue and the fixture's record pages through the app's
/// own `bethanyhub://` links. Each screen is kept as a screenshot attachment,
/// and a screen that shows "Something went wrong" or "Can't reach Bethany
/// House" fails the run, named.
///
/// It looks; it never presses a button that changes a record. TEST DATA ONLY:
/// the account and its password exist only in the CI job's database.
final class ScreenTourTests: XCTestCase {
    private static let env = ProcessInfo.processInfo.environment
    private static let hub = env["UITEST_HUB_URL"] ?? "http://127.0.0.1:8000"
    private static let email = env["UITEST_EMAIL"] ?? "ui-tour@bethanyhouse.test"
    private static let password = env["UITEST_PASSWORD"] ?? "ui-tour-only-2026"

    /// Every sidebar entry (deduplicated by path), then the fixture's records:
    /// order 2 (web, with a production job), production job 1 (materials
    /// partly bought), purchase order 1 (the job's draft), product 3 (the shirt
    /// with a bill of materials).
    static var screens: [(name: String, path: String)] {
        var seen = Set<String>()
        var out: [(String, String)] = []
        for group in NavCatalogue.groups {
            for item in group.items where seen.insert(item.route.consolePath).inserted {
                out.append(("\(group.label) – \(item.label)", item.route.consolePath))
            }
        }
        let records: [(String, Route)] = [
            ("Record – Order 2", .order(id: 2)),
            ("Record – Production order 1", .productionOrder(id: 1)),
            ("Record – Purchase order 1", .purchaseOrder(id: 1)),
            ("Record – Product 3 (BOM)", .product(id: 3)),
        ]
        for (name, route) in records { out.append((name, route.consolePath)) }
        return out
    }

    override func setUp() {
        continueAfterFailure = true
    }

    @MainActor
    func testEveryScreen() throws {
        let app = XCUIApplication()
        app.launchEnvironment["HUB_URL"] = Self.hub
        app.launchEnvironment["BH_UITEST_ROUTES"] = "1"
        #if os(iOS)
        // The iPad as a workstation: landscape, sidebar beside the screen.
        XCUIDevice.shared.orientation = .landscapeLeft
        #endif
        app.launch()
        dismissSystemAlerts()
        signIn(app)
        snap(app, "000 Home after sign-in")

        var problems: [String] = []
        for (i, screen) in Self.screens.enumerated() {
            go(app, screen.path)
            settle(app)
            dismissSystemAlerts()
            snap(app, String(format: "%03d %@", i + 1, screen.name))
            for text in ["Something went wrong", "Can't reach Bethany House"] where app.staticTexts[text].exists {
                problems.append("\(screen.name) (\(screen.path)): \(text)")
            }
            // Signed out mid-tour is a failure (the first run found one), and
            // the tour signs in again so the remaining screens are still seen.
            if app.textFields["signin.email"].exists {
                problems.append("\(screen.name) (\(screen.path)): signed out")
                signIn(app)
            }
        }
        XCTAssertTrue(problems.isEmpty, "Screens that failed to load:\n" + problems.joined(separator: "\n"))
    }

    // MARK: - Steps

    /// Navigate in the running app through the DEBUG-only route field
    /// (BethanyUI UITestRouteField), as a sidebar click would.
    @MainActor
    private func go(_ app: XCUIApplication, _ path: String) {
        let field = app.textFields["uitest.route"]
        guard field.waitForExistence(timeout: 10) else { return }
        // The field gives up focus after each navigation (so the iPad keyboard
        // does not cover the screenshot); tap until it has focus again, then type.
        for _ in 0..<4 {
            field.click_or_tap()
            if field.hasKeyboardFocus { break }
            Thread.sleep(forTimeInterval: 0.5)
        }
        guard field.hasKeyboardFocus else {
            XCTFail("could not focus the route field for \(path)")
            return
        }
        field.typeText(path + "\n")
    }

    @MainActor
    private func signIn(_ app: XCUIApplication) {
        let email = app.textFields["signin.email"]
        guard email.waitForExistence(timeout: 30) else {
            // Already signed in from an earlier run on this simulator.
            return
        }
        snap(app, "000 Sign in")
        email.tap()
        email.typeText(Self.email)
        let password = app.secureTextFields["signin.password"]
        password.tap()
        password.typeText(Self.password)
        app.buttons["signin.submit"].tap()
        let gone = NSPredicate(format: "exists == false")
        expectation(for: gone, evaluatedWith: email)
        waitForExpectations(timeout: 30)
        settle(app)
        dismissSystemAlerts()
    }

    /// Wait for the screen to finish loading: a short pause, then until no
    /// spinner shows (10 s at most — a screen still spinning is shot as it is).
    @MainActor
    private func settle(_ app: XCUIApplication) {
        Thread.sleep(forTimeInterval: 2)
        let deadline = Date().addingTimeInterval(10)
        while Date() < deadline, app.activityIndicators.count + app.progressIndicators.count > 0 {
            Thread.sleep(forTimeInterval: 0.5)
        }
    }

    @MainActor
    private func snap(_ app: XCUIApplication, _ name: String) {
        let shot = XCTAttachment(screenshot: app.screenshot())
        shot.name = name
        shot.lifetime = .keepAlways
        add(shot)
    }

    /// Notifications, location: answer the system's permission questions.
    @MainActor
    private func dismissSystemAlerts() {
        #if os(iOS)
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for _ in 0..<3 {
            let alert = springboard.alerts.firstMatch
            guard alert.waitForExistence(timeout: 1) else { return }
            for label in ["Allow", "Allow While Using App", "OK", "Don’t Allow"] where alert.buttons[label].exists {
                alert.buttons[label].tap()
                break
            }
        }
        #endif
    }
}

private extension XCUIElement {
    var hasKeyboardFocus: Bool { (value(forKey: "hasKeyboardFocus") as? Bool) ?? false }

    func click_or_tap() {
        #if os(macOS)
        click()
        #else
        tap()
        #endif
    }
}
