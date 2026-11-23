package ke.co.bethanyhouse.hub.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Tailors Hub (owner, 2026-11-23): workshop staff only; production, own time
 * and pay, messages, updates, training, profile. Expected lists are written
 * out by hand, not derived from TailorsHub.allows.
 */
class AppFlavorTest {
    private fun person(vararg p: String) = PermissionSet(p.toSet(), isSuperAdmin = false)
    private val tailor = person("production.worker", "production.view")
    private val inspector = person("production.submit_qc", "production.view")
    private val manager = person("production.view", "production.manage_assignees", "production.confirm_order", "production.worker")
    private val cashier = person("pos.access", "orders.view", "customers.view")
    private val finance = person("reports.financial", "payments.transactions", "expenses.view")
    private val owner = PermissionSet(emptySet(), isSuperAdmin = true)

    @Test fun workshopStaffMayUseTailorsHubOthersMayNot() {
        assertTrue(AppFlavor.TAILORS.mayUse(tailor))
        assertTrue(AppFlavor.TAILORS.mayUse(inspector))
        assertTrue(AppFlavor.TAILORS.mayUse(manager))
        assertTrue(AppFlavor.TAILORS.mayUse(person("production.approve_qc")))
        assertFalse(AppFlavor.TAILORS.mayUse(cashier))
        assertFalse(AppFlavor.TAILORS.mayUse(finance))
        assertFalse(AppFlavor.TAILORS.mayUse(PermissionSet.NONE))
        assertTrue("the owner can open it to test", AppFlavor.TAILORS.mayUse(owner))
        assertTrue(AppFlavor.HUB.mayUse(cashier))
    }

    @Test fun tailorsHubIsTheWorkshopAndTheirOwnWorkday() {
        val shown = listOf(
            Route.ProductionMyTasks, Route.ProductionOrders, Route.ProductionOrder(7), Route.Wip, Route.Qc, Route.ProductionCalendar,
            Route.Clock, Route.MyShifts, Route.MyLeave, Route.MyTimesheet, Route.MyPayslips, Route.MyAdvances,
            Route.Forms, Route.Handbook(), Route.Handbook(2), Route.Sign(), Route.Sign(3), Route.Todos, Route.Colleagues,
            Route.Comms(), Route.Comms(3), Route.Updates(), Route.Updates(2), Route.Courses(), Route.Courses(4), Route.Profile,
        )
        shown.forEach { assertTrue("$it should be in Tailors Hub", AppFlavor.TAILORS.allows(it)) }
        val hidden = listOf(
            Route.Dashboard, Route.Pos, Route.Orders(), Route.Order(1), Route.Customers, Route.Customer(1), Route.Balances, Route.Tills,
            Route.Bom, Route.StockLevels, Route.Materials, Route.PurchaseOrders, Route.Expenses, Route.PaymentTransactions,
            Route.Report(Route.ReportPage.FINANCE), Route.Report(Route.ReportPage.PRODUCTION), Route.Users, Route.Roles,
            Route.Approvals, Route.Notifications, Route.Downloads, Route.MyDownloads, Route.Me,
            // People administration, employee files and team payroll stay in Bethany Hub (owner, 2026-11-23).
            Route.PeopleOverview, Route.PeopleRequests, Route.Attendance, Route.FormsManage, Route.Roster, Route.Timesheets, Route.Leave,
            Route.People(), Route.People(5), Route.Payroll,
        )
        hidden.forEach { assertFalse("$it must not be in Tailors Hub", AppFlavor.TAILORS.allows(it)) }
        hidden.forEach { assertTrue("Bethany Hub keeps $it", AppFlavor.HUB.allows(it)) }
    }

    /** A production manager who may also run HR and payroll in Bethany Hub gets the workshop tools in Tailors Hub, never People or payroll. */
    @Test fun widerHubPermissionsDoNotTurnTailorsHubIntoTheHub() {
        val manager = person("production.view", "production.manage_assignees", "production.confirm_order", "production.approve_qc",
            "attendance.view_team", "hr.view", "payroll.view", "forms.manage")
        val groups = AppFlavor.TAILORS.groups(manager)
        assertFalse(groups.any { it.label == "People" })
        val routes = groups.flatMap { it.items }.map { it.route }
        assertTrue(routes.containsAll(listOf(Route.ProductionOrders, Route.Wip, Route.Qc, Route.MyPayslips)))
        listOf(Route.Payroll, Route.People(), Route.PeopleOverview, Route.Roster, Route.Timesheets, Route.Leave, Route.FormsManage)
            .forEach { assertFalse("$it", AppFlavor.TAILORS.allows(it)) }
        assertTrue("Bethany Hub still has People for them", AppFlavor.HUB.groups(manager).any { it.label == "People" })
    }

    @Test fun everyoneLandsOnTheirOwnWorkshopPage() {
        assertEquals(Route.ProductionMyTasks, AppFlavor.TAILORS.home(person("production.worker")))
        assertEquals(Route.ProductionMyTasks, AppFlavor.TAILORS.home(tailor))
        assertEquals(Route.ProductionOrders, AppFlavor.TAILORS.home(manager))
        assertEquals(Route.Qc, AppFlavor.TAILORS.home(person("production.submit_qc")))
        assertEquals(Route.ProductionOrders, AppFlavor.TAILORS.home(owner))
        assertEquals("Bethany Hub's home is unchanged", HomePath.resolve(cashier), AppFlavor.HUB.home(cashier))
    }

    @Test fun theMenuNeverOffersAScreenOutsideTailorsHub() {
        val labels = AppFlavor.TAILORS.groups(owner).flatMap { g -> g.items.map { "${g.label}/${it.label}" } }
        assertEquals(
            listOf(
                "Workspace/Messages",
                "Me/Updates", "Me/Checklists", "Me/Handbook", "Me/Training", "Me/To sign", "Me/To-dos", "Me/Colleagues",
                "Me/Clock in", "Me/My shifts", "Me/My leave", "Me/My hours", "Me/My payslips", "Me/My advances",
                "Production/My Tasks", "Production/Production Orders", "Production/Work In Progress", "Production/Quality Control", "Production/Calendar",
            ),
            labels,
        )
        assertEquals(listOf("My profile"), AppFlavor.TAILORS.userMenu.map { it.label })
        assertTrue(AppFlavor.TAILORS.searchable(owner).all { TailorsHub.allows(it.route) })
        assertTrue(AppFlavor.TAILORS.searchable(owner).none { it.route == Route.Pos })
    }

    @Test fun aTailorsTabsAreHerTasksCalendarMessagesAndClock() {
        val tabs = AppFlavor.TAILORS.phoneTabs(tailor, AppFlavor.TAILORS.home(tailor))
        assertEquals(listOf(Route.ProductionMyTasks, Route.ProductionCalendar, Route.Comms(), Route.Clock), tabs.map { it.route })
        tabs.forEach { assertTrue(AppFlavor.TAILORS.allows(it.route)) }
        val noCalendar = AppFlavor.TAILORS.phoneTabs(person("production.worker"), Route.ProductionMyTasks)
        assertEquals(listOf(Route.ProductionMyTasks, Route.Comms(), Route.Clock), noCalendar.map { it.route })
    }

    @Test fun theSessionCarriesItsApp() {
        val s = SessionStore.Snapshot(permissions = cashier, flavor = AppFlavor.TAILORS)
        assertFalse(s.mayUseApp)
        assertTrue(SessionStore.Snapshot(permissions = cashier).mayUseApp)
        assertEquals(Route.ProductionMyTasks, SessionStore.Snapshot(permissions = tailor, flavor = AppFlavor.TAILORS).home)
    }
}
