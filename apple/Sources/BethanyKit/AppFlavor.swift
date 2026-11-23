import Foundation

/// Which app this build is. Both are built from the same code:
///
/// - `hub` "Bethany Hub": the whole Hub, every screen a person's permissions open.
/// - `tailors` "Tailors Hub": the workshop app, staff workday and
///   permission-gated People screens.
///   Signing in needs production access; anyone else is sent to Bethany Hub.
///
/// What a flavour hides is presentation only. The API enforces every
/// permission on its own, so a hidden screen is never the control. The one
/// list of what Tailors Hub may show is `TailorsHub.allows`; menus, tabs,
/// search and the route guard all read it, so no screen can be reached by a
/// link, a push tap or a button inside another screen.
///
/// Mirrors android/core/.../AppFlavor.kt.
public enum AppFlavor: String, Sendable {
    case hub, tailors

    public var appName: String { self == .hub ? "Bethany Hub" : "Tailors Hub" }

    /// May this app show `route` at all (permission still applies on top)?
    public func allows(_ route: Route) -> Bool { self == .hub || TailorsHub.allows(route) }

    /// May this person use this app? Bethany Hub: anyone signed in.
    public func mayUse(_ subject: PermissionSet) -> Bool { self == .hub || TailorsHub.signInGate.allows(subject) }

    public func home(for subject: PermissionSet) -> Route {
        self == .hub ? HomePath.resolve(for: subject) : TailorsHub.home(for: subject)
    }

    /// The menu (iPad/Mac sidebar, phone "More"), already filtered by permission.
    public func groups(for subject: PermissionSet) -> [NavGroup] {
        (self == .hub ? NavCatalogue.groups : TailorsHub.groups).compactMap { $0.visible(for: subject) }
    }

    public var userMenu: [NavItem] { NavCatalogue.userMenu.filter { allows($0.route) } }

    public func phoneTabs(for subject: PermissionSet, home: Route) -> [NavItem] {
        self == .hub ? NavCatalogue.phoneTabs(for: subject, home: home) : TailorsHub.phoneTabs(for: subject, home: home)
    }

    /// What search (the command palette) may offer.
    public func searchable(for subject: PermissionSet) -> [NavItem] {
        NavCatalogue.allItems.filter { allows($0.route) && $0.gate.allows(subject) }
    }
}

public enum TailorsHub {
    /// Workshop staff: tailors, QC inspectors, production managers.
    public static let signInGate = NavGate.anyOf(["production.worker", "production.view", "production.submit_qc", "production.approve_qc"])

    /// Workshop, staff workday and People screens. Catalogue gates still
    /// restrict team, HR and payroll pages to permitted users.
    public static func allows(_ route: Route) -> Bool {
        switch route {
        case .productionMyTasks, .productionOrders, .productionOrder, .wip, .qc, .productionCalendar,
             .me, .forms, .handbook, .sign, .todos, .colleagues,
             .clock, .myShifts, .myLeave, .myTimesheet, .myPayslips, .myAdvances,
             .peopleOverview, .peopleRequests, .attendance, .formsManage, .roster, .timesheets, .leave, .people, .payroll,
             .comms, .updates, .courses, .profile:
            return true
        default:
            return false
        }
    }

    /// The sidebar's own groups, cut down to Tailors Hub's screens.
    public static let groups: [NavGroup] = NavCatalogue.groups.compactMap { g in
        let items = g.items.filter { allows($0.route) }
        return items.isEmpty ? nil : NavGroup(g.label, items)
    }

    /// A tailor lands on My Tasks, a manager on the orders, an inspector on QC.
    public static func home(for subject: PermissionSet) -> Route {
        if subject.isFloorWorker { return .productionMyTasks }
        if subject.can("production.view") { return .productionOrders }
        if subject.canAny(["production.submit_qc", "production.approve_qc"]) { return .qc }
        if subject.can("production.worker") { return .productionMyTasks }
        return .profile
    }

    /// Home, Calendar (when permitted), Messages, Clock in (+ More).
    public static func phoneTabs(for subject: PermissionSet, home: Route) -> [NavItem] {
        let first = NavCatalogue.item(for: home).map { NavItem($0.label, home, icon: $0.icon) } ?? NavItem("Home", home, icon: "house")
        var tabs = [first]
        if home != .productionCalendar, let cal = NavCatalogue.item(for: .productionCalendar), cal.gate.allows(subject) { tabs.append(cal) }
        tabs.append(NavItem("Messages", .comms(), icon: "bubble.left.and.bubble.right"))
        tabs.append(NavItem("Clock in", .clock, icon: NavCatalogue.item(for: .clock)?.icon ?? "clock"))
        return tabs
    }
}
