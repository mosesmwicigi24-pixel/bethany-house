import XCTest
import BethanyKit

/// The screen tour: signs in to a throwaway Hub (apple/UITests/Fixtures, CI
/// job "UI tour" in .github/workflows/apple-ui.yml), then opens every screen in
/// the navigation catalogue and the fixture's record pages, one tap of the
/// app's DEBUG-only tour button at a time (BethanyUI UITestRouteField). Each screen is kept as a screenshot attachment,
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
        // The app walks this list, one screen per tap of its hidden "next" button.
        app.launchEnvironment["BH_UITEST_ROUTES"] = Self.screens.map(\.path).joined(separator: "\n")
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
            if !go(app, screen.path) {
                problems.append("\(screen.name) (\(screen.path)): the app did not open it")
            }
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

    /// Open the next screen in the running app through the DEBUG-only
    /// button (BethanyUI UITestRouteField), as a sidebar click would, and
    /// confirm the app says it opened `path`.
    @MainActor
    @discardableResult
    private func go(_ app: XCUIApplication, _ path: String) -> Bool {
        let next = app.buttons["uitest.next"]
        guard next.waitForExistence(timeout: 10) else {
            XCTFail("the tour button is missing (opening \(path))")
            return false
        }
        let opened = NSPredicate(format: "value == %@", path)
        func arrived(_ timeout: TimeInterval) -> Bool {
            XCTWaiter().wait(for: [XCTNSPredicateExpectation(predicate: opened, object: next)], timeout: timeout) == .completed
        }
        // A press can be lost (tour run 9: the iPad's first press was, and
        // every screen after it was one behind). The button's value says where
        // the app is, so press until it reaches `path`, never past it.
        let order = Self.screens.map(\.path)
        guard let target = order.firstIndex(of: path) else { return false }
        for attempt in 0..<4 {
            let at = (next.value as? String).flatMap { order.firstIndex(of: $0) } ?? -1
            if at == target { return true }
            if at > target { return false }
            if attempt % 2 == 0 {
                // The button's keyboard shortcut first: it needs no hit-testing.
                app.typeKey("]", modifierFlags: [.command, .option, .control])
            } else {
                next.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.5)).click_or_tap()
            }
            if arrived(3) { return true }
        }
        return arrived(1)
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

private extension XCUICoordinate {
    func click_or_tap() {
        #if os(macOS)
        click()
        #else
        tap()
        #endif
    }
}
