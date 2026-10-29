package ke.co.bethanyhouse.hub.render

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performSemanticsAction
import androidx.test.core.app.ApplicationProvider
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubJson
import ke.co.bethanyhouse.hub.core.KioskApi
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.HubTheme
import ke.co.bethanyhouse.hub.ui.kiosk.KioskScreen
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The clock-in kiosk on a Samsung-tablet-sized screen against the mock Hub:
 * the board, the PIN pad, a punch, and a kiosk removed on the Hub.
 */
@RunWith(RobolectricTestRunner::class)
@Config(application = TestHubApp::class, qualifiers = "w1280dp-h800dp")
class KioskRenderTest {
    @get:Rule val compose = createComposeRule()
    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    @Before fun enterKiosk() { MockHub.kioskStatus = null; MockHub.punches.clear(); env.kiosk.enter("kiosk-test-token") }
    @After fun reset() { MockHub.kioskStatus = null; env.kiosk.clear(null) }

    private fun render() {
        compose.setContent { HubTheme { CompositionLocalProvider(LocalEnv provides env) { KioskScreen() } } }
        settle()
    }

    private fun settle() = repeat(12) { Thread.sleep(40); compose.mainClock.advanceTimeBy(200); compose.waitForIdle() }
    private fun exists(m: androidx.compose.ui.test.SemanticsMatcher) = compose.onAllNodes(m, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    @Test fun boardShowsTheOutletAndWhoIsIn() {
        render()
        assertTrue(exists(hasText("Westlands Store")))
        assertTrue(exists(hasText("Front door · 1 clocked in")))
        assertTrue(exists(hasText("Amina Njeri")))
        assertTrue(exists(hasText("Tap to clock in")))
        assertTrue(exists(hasText("In since 08:02")))
    }

    @Test fun aPunchSendsThePersonsPinAndShowsTheHubsReply() {
        render()
        compose.onNode(hasText("Amina Njeri"), useUnmergedTree = true).performClick(); settle()
        assertTrue("PIN pad", exists(hasText("Enter your PIN to clock in.")))
        listOf("4", "8", "2", "1").forEach { compose.onNode(hasContentDescription(it)).performClick() }
        compose.onNode(hasContentDescription("Clock in")).performClick(); settle()

        assertEquals(1, MockHub.punches.size)
        val body = HubJson.parse(MockHub.punches.single())
        assertEquals(7, body["user_id"].int)
        assertEquals("4821", body["pin"].str)
        assertEquals("in", body["action"].str)
        assertTrue("server message shown", exists(hasText("Amina clocked in at 08:02.")))
        assertTrue("back on the board", exists(hasText("Baraka Otieno")))
    }

    @Test fun okNeedsFourDigits() {
        render()
        compose.onNode(hasText("Amina Njeri"), useUnmergedTree = true).performClick(); settle()
        listOf("1", "2", "3").forEach { compose.onNode(hasContentDescription(it)).performClick() }
        compose.onNode(hasContentDescription("Clock in")).performClick(); settle()
        assertTrue(MockHub.punches.isEmpty())
    }

    @Test fun aKioskRemovedOnTheHubReturnsTheTabletToSetUp() {
        MockHub.kioskStatus = 401
        render()
        assertFalse(env.kiosk.isKiosk)
        assertEquals(KioskApi.ENDED_ON_HUB, env.kiosk.ended.value)
    }
}

/** A manager makes this tablet the kiosk from People › Attendance › Kiosks. */
@RunWith(RobolectricTestRunner::class)
@Config(application = TestHubApp::class, qualifiers = "w1280dp-h800dp")
class KioskSetUpTest {
    @get:Rule val compose = createComposeRule()
    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    private fun settle() = repeat(12) { Thread.sleep(40); compose.mainClock.advanceTimeBy(200); compose.waitForIdle() }

    @Test fun makingThisTabletAKioskKeepsTheTokenApartAndSignsTheManagerOut() {
        env.kiosk.clear(null)
        kotlinx.coroutines.runBlocking { env.session.start() }
        assertEquals(ke.co.bethanyhouse.hub.core.SessionStore.State.SignedIn, env.session.current.state)
        compose.setContent { HubTheme { CompositionLocalProvider(LocalEnv provides env) { ke.co.bethanyhouse.hub.ui.shell.RouteScreen(ke.co.bethanyhouse.hub.core.Route.Attendance) } } }
        settle()
        compose.onNode(hasText("Kiosks"), useUnmergedTree = true).performClick(); settle()
        compose.onAllNodes(hasText("Make this tablet a kiosk"), useUnmergedTree = true)[0].performClick(); settle()
        // The sheet has one text field (the outlet is a read-only select that defaults to the manager's outlet).
        compose.onAllNodes(androidx.compose.ui.test.hasSetTextAction()).fetchSemanticsNodes().also { assertTrue("a text field", it.isNotEmpty()) }
        val fields = compose.onAllNodes(androidx.compose.ui.test.hasSetTextAction())
        fields[fields.fetchSemanticsNodes().size - 1].performTextInput("Front door")
        settle()
        // The sheet's save button carries the same label as the opener; the last one is the sheet's.
        val save = compose.onAllNodes(hasText("Make this tablet a kiosk") and androidx.compose.ui.test.hasClickAction())
        // The sheet's button can sit below the fold on an 800 dp screen; call its click action rather than a touch.
        save[save.fetchSemanticsNodes().size - 1].performSemanticsAction(androidx.compose.ui.semantics.SemanticsActions.OnClick); settle()

        assertEquals("5|new-kiosk-token", env.kiosk.token.value)
        assertEquals(ke.co.bethanyhouse.hub.core.SessionStore.State.SignedOut, env.session.current.state)
        assertTrue("manager token gone from the store", env.secureStore.get("bh_admin_token") == null)
        env.kiosk.clear(null)
    }
}

