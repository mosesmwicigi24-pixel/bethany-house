package ke.co.bethanyhouse.hub.render

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.core.app.ApplicationProvider
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.NavCatalogue
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SessionStore
import ke.co.bethanyhouse.hub.ui.HubTheme
import ke.co.bethanyhouse.hub.ui.shell.RouteScreen
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.ParameterizedRobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Renders every native route as a signed-in super admin against a Hub that
 * returns empty pages, and fails if any screen throws while composing,
 * loading or laying out. This is the empty-and-zero case for every screen
 * (CLAUDE.md §9) and the first proof that the agent-written modules run.
 * It does not check what the screens show with real data.
 */
@RunWith(ParameterizedRobolectricTestRunner::class)
@Config(application = TestHubApp::class, qualifiers = "w411dp-h891dp")
class RouteRenderTest(private val name: String, private val route: Route) {
    @get:Rule val compose = createComposeRule()

    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    @Before
    fun signIn() {
        if (env.session.current.state != SessionStore.State.SignedIn) runBlocking { env.session.start() }
        assertEquals(SessionStore.State.SignedIn, env.session.current.state)
    }

    @Test
    fun rendersWithoutCrashing() {
        val before = MockHub.requests.size
        compose.setContent {
            HubTheme { CompositionLocalProvider(LocalEnv provides env) { RouteScreen(route) } }
        }
        // Let the screen's loads go out to the mock Hub and their results come back.
        repeat(15) {
            Thread.sleep(40)
            compose.mainClock.advanceTimeBy(200)
            compose.waitForIdle()
        }
        // The screen drew something and asked the Hub for its data.
        val nodes = compose.onAllNodes(androidx.compose.ui.test.isRoot()).fetchSemanticsNodes().sumOf { countNodes(it) }
        println("RENDER $name nodes=$nodes requests=${MockHub.requests.drop(before).distinct()}")
        org.junit.Assert.assertTrue("$name drew nothing", nodes > 1)
        org.junit.Assert.assertTrue("$name made no request to the Hub", MockHub.requests.size > before)
    }

    private fun countNodes(n: androidx.compose.ui.semantics.SemanticsNode): Int = 1 + n.children.sumOf(::countNodes)

    companion object {
        @JvmStatic
        @ParameterizedRobolectricTestRunner.Parameters(name = "{0}")
        fun routes(): List<Array<Any>> {
            val sidebar = NavCatalogue.allItems.map { it.route }.filter { it != Route.Kiosk }
            val details = listOf(
                Route.Order(1), Route.Shipment(1), Route.Customer(1), Route.ProductionOrder(1), Route.Adjustment(1),
                Route.Transfer(1), Route.PurchaseOrder(1), Route.Grn(1), Route.PurchaseReturn(1), Route.Expense(1),
                Route.Product(1), Route.Product(null, new = true), Route.People(1), Route.Comms(1),
                Route.Updates(1), Route.Handbook(1), Route.Courses(1), Route.Sign(1),
            ) + Route.ReportPage.entries.map { Route.Report(it) } + Route.OrderChannel.entries.map { Route.Orders(it) }
            return (sidebar + details).distinct().map { arrayOf(it.toString(), it) }
        }
    }
}
