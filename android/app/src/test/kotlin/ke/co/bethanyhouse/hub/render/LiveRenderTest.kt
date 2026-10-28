package ke.co.bethanyhouse.hub.render

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.isRoot
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
import org.junit.Assert.assertFalse
import org.junit.Assume.assumeTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.ParameterizedRobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Every native route against a running backend with seeded records (the
 * real response shapes, not mocks). A screen fails if it shows one of the
 * shared error states — what a contract mismatch, a 4xx/5xx or a parse
 * failure turns into on screen. Skipped unless [LiveHub] is configured.
 */
@RunWith(ParameterizedRobolectricTestRunner::class)
@Config(application = TestHubApp::class, qualifiers = "w411dp-h891dp")
class LiveRenderTest(private val name: String, private val route: Route) {
    @get:Rule val compose = createComposeRule()
    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    @Before
    fun signIn() {
        assumeTrue("set -PhubE2eUrl and -PhubE2eToken to run", LiveHub.url != null)
        if (env.session.current.state != SessionStore.State.SignedIn) runBlocking { env.session.start() }
        assertEquals(SessionStore.State.SignedIn, env.session.current.state)
    }

    @Test
    fun rendersRealDataWithoutAnErrorState() {
        compose.setContent { HubTheme { CompositionLocalProvider(LocalEnv provides env) { RouteScreen(route) } } }
        repeat(30) { Thread.sleep(60); compose.mainClock.advanceTimeBy(200); compose.waitForIdle() }
        val texts = compose.onAllNodes(isRoot()).fetchSemanticsNodes().flatMap(::texts)
        println("LIVE $name :: " + texts.take(40).joinToString(" | ").take(1200))
        val errors = texts.filter { t -> ERROR_TITLES.any { t.startsWith(it) } }
        assertFalse("$name shows an error state: $errors :: ${texts.take(12)}", errors.isNotEmpty())
    }

    private fun texts(n: SemanticsNode): List<String> =
        (n.config.getOrNull(SemanticsProperties.Text)?.map { it.text } ?: emptyList()) + n.children.flatMap(::texts)

    companion object {
        private val ERROR_TITLES = listOf("Something went wrong", "Can't reach Bethany House", "This record was deleted")

        @JvmStatic
        @ParameterizedRobolectricTestRunner.Parameters(name = "{0}")
        fun routes(): List<Array<Any>> {
            val sidebar = NavCatalogue.allItems.map { it.route }.filter { it != Route.Kiosk }
            // Only detail records the fixture seeds (orders 1–3, customer 1, product 1).
            val details = listOf(Route.Order(1), Route.Order(2), Route.Order(3), Route.Customer(1), Route.Product(1)) +
                Route.ReportPage.entries.map { Route.Report(it) } + Route.OrderChannel.entries.map { Route.Orders(it) }
            return (sidebar + details).distinct().map { arrayOf(it.toString(), it) }
        }
    }
}
