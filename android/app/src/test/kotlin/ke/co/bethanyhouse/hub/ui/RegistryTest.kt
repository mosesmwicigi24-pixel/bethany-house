package ke.co.bethanyhouse.hub.ui

import ke.co.bethanyhouse.hub.core.NavCatalogue
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Pins the claim in docs/NATIVE_APPS_ANDROID.md: every page the sidebar can
 * show has a native screen, and only the documented exceptions fall back to
 * the console bridge.
 */
class RegistryTest {
    /** Routes deliberately left to the console bridge (same list as the Apple apps). */
    private val bridged = setOf<Route>(Route.Kiosk)

    @Test
    fun `every sidebar and user-menu page is native`() {
        val missing = NavCatalogue.allItems.map { it.route }.filter { it !in bridged && !FeatureRegistry.isNative(it) }
        assertEquals("Routes without a native screen: $missing", emptyList<Route>(), missing)
    }

    @Test
    fun `detail routes resolve natively`() {
        val details = listOf(
            Route.Order(1), Route.Shipment(1), Route.Customer(1), Route.ProductionOrder(1), Route.Adjustment(1),
            Route.Transfer(1), Route.PurchaseOrder(1), Route.Grn(1), Route.PurchaseReturn(1), Route.Expense(1),
            Route.Product(1), Route.Product(null, new = true), Route.People(1), Route.Comms(1),
            Route.Updates(1), Route.Handbook(1), Route.Courses(1), Route.Sign(1),
        ) + Route.ReportPage.entries.map { Route.Report(it) } + Route.OrderChannel.entries.map { Route.Orders(it) }
        val missing = details.filterNot(FeatureRegistry::isNative)
        assertEquals("Detail routes without a native screen: $missing", emptyList<Route>(), missing)
    }

    @Test
    fun `order handoff stays on the console bridge`() {
        assertFalse(FeatureRegistry.isNative(Route.Handoff(1)))
        assertTrue(FeatureRegistry.isNative(Route.Order(1)))
    }
}
