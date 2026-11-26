package ke.co.bethanyhouse.hub.core

/**
 * Which app this build is. Both are built from the same code:
 *
 *  - [HUB] "Bethany Hub": the whole Hub, every screen a person's permissions open.
 *  - [TAILORS] "Tailors Hub": the workshop app for everyone who uses it
 *    (owner, 2026-11-23): production work and the management tools a
 *    person's permissions open, their own workday, and People
 *    administration, employee files and team payroll for those whose Hub
 *    permissions already open them.
 *    Signing in needs production access; anyone else is sent to Bethany Hub.
 *
 * What a flavour hides is presentation only. The API enforces every
 * permission on its own, so a hidden screen is never the control. The one
 * list of what Tailors Hub may show is [TailorsHub.allows]; menus, tabs,
 * search and the route guard all read it, so no screen can be reached by a
 * link, a push tap or a button inside another screen.
 *
 * Mirrors apple/Sources/BethanyKit/AppFlavor.swift.
 */
enum class AppFlavor(val appName: String) {
    HUB("Bethany Hub"),
    TAILORS("Tailors Hub");

    /** May this app show [route] at all (permission still applies on top)? */
    fun allows(route: Route): Boolean = this == HUB || TailorsHub.allows(route)

    /** May this person use this app? Bethany Hub: anyone signed in. */
    fun mayUse(subject: PermissionSet): Boolean = this == HUB || TailorsHub.signInGate.allows(subject)

    fun home(subject: PermissionSet): Route = if (this == HUB) HomePath.resolve(subject) else TailorsHub.home(subject)

    /** The menu (iPad/tablet sidebar, phone "More"), already filtered by permission. */
    fun groups(subject: PermissionSet): List<NavGroup> =
        (if (this == HUB) NavCatalogue.groups else TailorsHub.groups).mapNotNull { it.visible(subject) }

    val userMenu: List<NavItem> get() = NavCatalogue.userMenu.filter(::allowsItem)

    fun phoneTabs(subject: PermissionSet, home: Route): List<NavItem> =
        if (this == HUB) NavCatalogue.phoneTabs(subject, home) else TailorsHub.phoneTabs(subject, home)

    /** What search (the command palette) may offer. */
    fun searchable(subject: PermissionSet): List<NavItem> =
        NavCatalogue.allItems.filter { allowsItem(it) && it.gate.allows(subject) }

    private fun allowsItem(item: NavItem) = allows(item.route)
}

object TailorsHub {
    /** Workshop staff: tailors, QC inspectors, production managers. */
    val signInGate = NavGate.anyOf("production.worker", "production.view", "production.submit_qc", "production.approve_qc")

    /**
     * The whole of Tailors Hub (owner, 2026-11-23). Production work, with the
     * management screens each person's permissions open; the person's own
     * workday (clock, shifts, leave, hours, payslips, advances, updates,
     * training, checklists, handbook, documents to sign, to-dos, colleagues);
     * messages; profile; and, restored by the owner the same day, People
     * administration, employee files and team payroll for those whose Hub
     * permissions open them (each keeps its own gate: attendance.view_team,
     * forms.manage, hr.view, payroll.view; details inherit via
     * NavCatalogue.gate). Nothing that sells, buys, counts stock or reports
     * the business's money.
     */
    fun allows(route: Route): Boolean = when (route) {
        Route.ProductionMyTasks, Route.ProductionOrders, is Route.ProductionOrder, Route.Wip, Route.Qc, Route.ProductionCalendar,
        Route.Forms, is Route.Handbook, is Route.Sign, Route.Todos, Route.Colleagues,
        Route.Clock, Route.MyShifts, Route.MyLeave, Route.MyTimesheet, Route.MyPayslips, Route.MyAdvances,
        Route.PeopleOverview, Route.PeopleRequests, Route.Attendance, Route.FormsManage, Route.Roster, Route.Timesheets, Route.Leave,
        is Route.People, Route.Payroll,
        is Route.Comms, is Route.Updates, is Route.Courses, Route.Profile -> true
        else -> false
    }

    /** The sidebar's own groups, cut down to Tailors Hub's screens. */
    val groups: List<NavGroup> = NavCatalogue.groups.mapNotNull { g ->
        g.items.filter { allows(it.route) }.takeIf { it.isNotEmpty() }?.let { NavGroup(g.label, it) }
    }

    /** A tailor lands on My Tasks, a manager on the orders, an inspector on QC. */
    fun home(subject: PermissionSet): Route = when {
        subject.isFloorWorker -> Route.ProductionMyTasks
        subject.can("production.view") -> Route.ProductionOrders
        subject.canAny(listOf("production.submit_qc", "production.approve_qc")) -> Route.Qc
        subject.can("production.worker") -> Route.ProductionMyTasks
        else -> Route.Profile
    }

    /** Home, Calendar, Orders, WIP and Clock in (+ More), subject to existing gates. */
    fun phoneTabs(subject: PermissionSet, home: Route): List<NavItem> {
        val first = NavCatalogue.item(home)?.let { NavItem(if (home == Route.ProductionOrders) "Orders" else it.label, home, icon = it.icon) } ?: NavItem("Home", home, icon = "Home")
        val tabs = mutableListOf(first)
        NavCatalogue.item(Route.ProductionCalendar)?.takeIf { home != Route.ProductionCalendar && it.gate.allows(subject) }?.let { tabs += it }
        listOf(Route.ProductionOrders to "Orders", Route.Wip to "WIP").forEach { (route, label) ->
            NavCatalogue.item(route)?.takeIf { home != route && it.gate.allows(subject) }?.let { tabs += NavItem(label, route, icon = it.icon) }
        }
        tabs += NavItem("Clock in", Route.Clock, icon = NavCatalogue.item(Route.Clock)?.icon ?: "Schedule")
        return tabs
    }
}
