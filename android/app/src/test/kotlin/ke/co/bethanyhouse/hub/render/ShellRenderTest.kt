package ke.co.bethanyhouse.hub.render

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.core.app.ApplicationProvider
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SessionStore
import ke.co.bethanyhouse.hub.ui.HubTheme
import ke.co.bethanyhouse.hub.ui.shell.HubShell
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The whole signed-in app at the three window widths the shell adapts to:
 * phone (bottom bar), tablet portrait / multi-window (rail) and tablet
 * landscape or DeX (permanent sidebar). Also drives navigation through
 * the navigator the way a deep link or push tap does.
 */
@RunWith(RobolectricTestRunner::class)
@Config(application = TestHubApp::class)
class ShellRenderTest {
    @get:Rule val compose = createComposeRule()
    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    @Before
    fun signIn() {
        if (env.session.current.state != SessionStore.State.SignedIn) runBlocking { env.session.start() }
        assertEquals(SessionStore.State.SignedIn, env.session.current.state)
        env.navigator.selection = null
        env.navigator.path.clear()
    }

    private fun renderShell() {
        compose.setContent { HubTheme { CompositionLocalProvider(LocalEnv provides env) { HubShell() } } }
        settle()
    }

    private fun settle() = repeat(10) { Thread.sleep(40); compose.mainClock.advanceTimeBy(200); compose.waitForIdle() }

    private fun exists(matcher: androidx.compose.ui.test.SemanticsMatcher) = compose.onAllNodes(matcher, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    @Test
    @Config(qualifiers = "w411dp-h891dp")
    fun `phone shows the bottom bar with More`() {
        renderShell()
        assertTrue("bottom bar More tab", exists(hasText("More")))
        assertTrue("home lands on the dashboard", exists(hasText("Dashboard")))
    }

    @Test
    @Config(qualifiers = "w700dp-h1100dp")
    fun `tablet portrait shows the rail`() {
        renderShell()
        assertTrue("rail opens all pages", exists(hasContentDescription("All pages")))
    }

    @Test
    @Config(qualifiers = "w1280dp-h800dp")
    fun `tablet landscape shows the permanent sidebar with every group`() {
        renderShell()
        assertTrue("sidebar shows WORKSPACE", exists(hasText("WORKSPACE")))
        // The sidebar is a lazy list: scroll each lower group's page into view.
        listOf("POS", "Purchase Orders", "Payment Transactions", "Sign out").forEach {
            compose.onNode(androidx.compose.ui.test.hasScrollToNodeAction()).performScrollToNode(hasText(it))
            assertTrue("sidebar reaches $it", exists(hasText(it)))
        }
    }

    @Test
    @Config(qualifiers = "w411dp-h891dp")
    fun `a deep link to a detail page opens it with a back stack`() {
        renderShell()
        compose.runOnIdle { env.navigator.go(Route.Order(42)) }
        settle()
        assertEquals(Route.Order(42), env.navigator.current)
        assertTrue("back arrow shown", exists(hasContentDescription("Back")))
        compose.runOnIdle { env.navigator.pop() }
        settle()
        assertTrue("back on the orders list", env.navigator.path.isEmpty())
    }
}
