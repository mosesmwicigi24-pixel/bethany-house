import Foundation

/// Which app this build is. Both are built from the same code:
///
/// - `hub` "Bethany Hub": the whole Hub, every screen a person's permissions open.
/// - `tailors` "Tailors Hub": the workshop app for everyone who uses it
///   (owner, 2026-11-23): production work and the management tools a
///   person's permissions open, their own workday, and People
///   administration, employee files and team payroll for those whose Hub
///   permissions already open them.
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

    /// The whole of Tailors Hub (owner, 2026-11-23). Production work, with
    /// the management screens each person's permissions open; the person's
    /// own workday (clock, shifts, leave, hours, payslips, advances, updates,
    /// training, checklists, handbook, documents to sign, to-dos,
    /// colleagues); messages; profile; and, restored by the owner the same
    /// day, People administration, employee files and team payroll for those
    /// whose Hub permissions open them (each keeps its own gate:
    /// attendance.view_team, forms.manage, hr.view, payroll.view; details
    /// inherit via `NavCatalogue.gate(for:)`). Nothing that sells, buys,
    /// counts stock or reports the business's money.
    public static func allows(_ route: Route) -> Bool {
        switch route {
        case .productionMyTasks, .productionOrders, .productionOrder, .wip, .qc, .productionCalendar,
             .forms, .handbook, .sign, .todos, .colleagues,
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

    /// Orders, WIP, My Tasks and Clock in (+ More), subject to existing gates.
    public static func phoneTabs(for subject: PermissionSet, home: Route) -> [NavItem] {
        var tabs: [NavItem] = []
        for (route, label) in [(Route.productionOrders, "Orders"), (.wip, "WIP"), (.productionMyTasks, "My Tasks")] {
            if let item = NavCatalogue.item(for: route), item.gate.allows(subject) {
                tabs.append(NavItem(label, route, icon: item.icon))
            }
        }
        tabs.append(NavItem("Clock in", .clock, icon: NavCatalogue.item(for: .clock)?.icon ?? "clock"))
        return tabs
    }
}
