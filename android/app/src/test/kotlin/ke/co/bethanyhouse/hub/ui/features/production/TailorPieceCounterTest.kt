package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import ke.co.bethanyhouse.hub.ui.HubTheme
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(application = android.app.Application::class, qualifiers = "w411dp-h891dp")
class TailorPieceCounterTest {
    @get:Rule val compose = createComposeRule()

    @Test fun `draft does not write and failed save keeps the draft`() {
        val sent = mutableListOf<Int>()
        compose.setContent { HubTheme { TailorPieceCounter("Pieces completed", 1, 3, null, true) { sent += it; false } } }
        compose.onNodeWithText("Update count").performClick()
        compose.onNodeWithText("+").performClick()
        assertEquals(emptyList<Int>(), sent)
        compose.onNodeWithText("Save count").performClick()
        compose.waitForIdle()
        assertEquals(listOf(2), sent)
        compose.onNodeWithText("Save count").assertExists()
        compose.onNodeWithText("Cancel").performClick()
        compose.onNodeWithText("Update count").assertExists()
    }

    @Test fun `saved count closes the draft and queued count is identified`() {
        compose.setContent { HubTheme { TailorPieceCounter("Pieces completed", 1, 3, 2, true) { true } } }
        compose.onNodeWithText("Saved here · Waiting to sync").assertExists()
        compose.onNodeWithText("Update count").performClick()
        compose.onNodeWithText("+").performClick()
        compose.onNodeWithText("Save count").performClick()
        compose.waitForIdle()
        compose.onNodeWithText("Save count").assertDoesNotExist()
        compose.onNodeWithText("Update count").assertExists()
    }
}
