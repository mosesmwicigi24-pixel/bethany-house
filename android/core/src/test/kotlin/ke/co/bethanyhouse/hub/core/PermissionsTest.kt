package ke.co.bethanyhouse.hub.core

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

class PermissionsTest {
    private val admin = PermissionSet(setOf("orders.view", "pos.access"), false)
    private val owner = PermissionSet(emptySet(), true)

    @Test fun gateAllowsExactlyAsTheConsole() {
        assertTrue(NavGate.OPEN.allows(PermissionSet.NONE))
        assertTrue(NavGate.perm("orders.view").allows(admin))
        assertFalse(NavGate.perm("payroll.view").allows(admin))
        assertTrue(NavGate.anyOf("payroll.view", "pos.access").allows(admin))
        assertFalse(NavGate.allOf("orders.view", "settings.edit").allows(admin))
        assertTrue(NavGate.allOf("orders.view", "pos.access").allows(admin))
        assertFalse(NavGate.SUPER_ADMIN.allows(admin), "no permission can grant a role-gated page")
        assertTrue(NavGate.SUPER_ADMIN.allows(owner))
        assertTrue(NavGate.perm("anything").allows(owner), "super admin passes every gate")
    }

    @Test fun floorWorkerIsAWorkerWhoDoesNotRunTheFloor() {
        assertTrue(PermissionSet(setOf("production.worker", "production.view"), false).isFloorWorker)
        assertFalse(PermissionSet(setOf("production.worker", "production.confirm_order"), false).isFloorWorker)
        assertFalse(PermissionSet(setOf("production.worker", "production.manage_assignees"), false).isFloorWorker)
        assertFalse(PermissionSet(setOf("production.worker"), true).isFloorWorker)
    }

    @Test fun homePathFollowsTheOwnerApprovedOrder() {
        assertEquals(Route.ProductionMyTasks, HomePath.resolve(PermissionSet(setOf("production.worker"), false)))
        assertEquals(Route.Dashboard, HomePath.resolve(PermissionSet(setOf("dashboard.view", "production.worker", "production.confirm_order"), false)))
        assertEquals(Route.Dashboard, HomePath.resolve(owner))
        assertEquals(Route.Comms(), HomePath.resolve(PermissionSet(setOf("orders.view"), false)))
        assertEquals(Route.Approvals, HomePath.resolve(PermissionSet(setOf("expenses.approve"), false)))
    }

    @Test fun sidebarGatesMatchTheConsoleSlugs() {
        fun gate(r: Route) = NavCatalogue.item(r)!!.gate
        assertEquals(NavGate.perm("dashboard.view"), gate(Route.Dashboard))
        assertEquals(NavGate.perm("pos.access"), gate(Route.Pos))
        assertEquals(NavGate.anyOf("pos.access", "pos.tills_view_all", "pos.reconcile", "pos.till_correction"), gate(Route.Tills))
        assertEquals(NavGate.allOf("pos.access", "settings.edit"), gate(Route.EodSettings))
        assertEquals(NavGate.anyOf("production.submit_qc", "production.approve_qc"), gate(Route.Qc))
        assertEquals(NavGate.SUPER_ADMIN, gate(Route.DiscountLimits))
        assertEquals(NavGate.SUPER_ADMIN, gate(Route.Database))
        assertEquals(NavGate.OPEN, gate(Route.Holidays))
        assertEquals(NavGate.OPEN, gate(Route.Comms()))
        assertEquals(NavGate.perm("reports.financial"), gate(Route.Report(Route.ReportPage.FINANCE)))
        assertEquals(NavGate.perm("reports.data_quality"), gate(Route.Report(Route.ReportPage.DATA_QUALITY)))
        assertEquals(18, NavCatalogue.approvalsGate.anyOf.size)
        val me = NavCatalogue.groups.first { it.label == "Me" }
        assertEquals(14, me.items.size)
        assertTrue(me.items.all { it.gate.isOpen })
    }

    @Test fun phoneTabsFollowBottomTabBar() {
        assertEquals(listOf("Home", "Messages", "Me", "Orders"), NavCatalogue.phoneTabs(PermissionSet(setOf("orders.view"), false), Route.Dashboard).map { it.label })
        assertEquals(listOf("My Tasks", "Messages", "Me"), NavCatalogue.phoneTabs(PermissionSet(setOf("production.worker", "production.view"), false), Route.ProductionMyTasks).map { it.label })
        assertEquals(listOf("Home", "Messages", "Me", "Production", "Orders"), NavCatalogue.phoneTabs(PermissionSet(setOf("production.view", "production.confirm_order", "orders.view"), false), Route.Dashboard).map { it.label })
    }

    @Test fun consolePathsRoundTrip() {
        for (item in NavCatalogue.allItems) {
            assertEquals(item.route, Route.fromConsolePath(item.route.consolePath), item.label)
            assertEquals(item.route, Route.fromConsolePath("/admin" + item.route.consolePath), "with /admin prefix: ${item.label}")
        }
        assertEquals(Route.Order(42), Route.fromConsolePath("/sales/orders/42"))
        assertEquals(Route.MyLeave, Route.fromConsolePath("/admin/me/leave?tab=x"))
        assertEquals(Route.Report(Route.ReportPage.FINANCE), Route.fromConsolePath("/reports/financial"))
        assertEquals(Route.Report(Route.ReportPage.SIGNALS), Route.fromConsolePath("/intelligence"))
        assertEquals(Route.Product(new = true), Route.fromConsolePath("/catalogue/products/new"))
        assertEquals(null, Route.fromConsolePath("/nowhere"))
    }
}
