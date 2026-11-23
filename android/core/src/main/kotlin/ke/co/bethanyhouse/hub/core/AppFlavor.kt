package ke.co.bethanyhouse.hub.core

/**
 * Which app this build is. Both are built from the same code:
 *
 *  - [HUB] "Bethany Hub": the whole Hub, every screen a person's permissions open.
 *  - [TAILORS] "Tailors Hub": the workshop app (owner, 2026-11-23). Only the
 *    production screens, the person's own pay and time, and staff messages.
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
     * The whole of Tailors Hub. Production work; the person's own clock,
     * shifts, leave, hours and payslips; messages, updates and training; their
     * profile. Nothing that sells, buys, counts stock or reports money.
     */
    fun allows(route: Route): Boolean = when (route) {
        Route.ProductionMyTasks, Route.ProductionOrders, is Route.ProductionOrder, Route.Wip, Route.Qc, Route.ProductionCalendar,
        Route.Clock, Route.MyShifts, Route.MyLeave, Route.MyTimesheet, Route.MyPayslips,
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

    /** Home, Calendar (when permitted), Messages, Clock in (+ More). */
    fun phoneTabs(subject: PermissionSet, home: Route): List<NavItem> {
        val first = NavCatalogue.item(home)?.let { NavItem(it.label, home, icon = it.icon) } ?: NavItem("Home", home, icon = "Home")
        val tabs = mutableListOf(first)
        NavCatalogue.item(Route.ProductionCalendar)?.takeIf { home != Route.ProductionCalendar && it.gate.allows(subject) }?.let { tabs += it }
        tabs += NavItem("Messages", Route.Comms(), icon = "Forum")
        tabs += NavItem("Clock in", Route.Clock, icon = NavCatalogue.item(Route.Clock)?.icon ?: "Schedule")
        return tabs
    }
}
