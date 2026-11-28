package ke.co.bethanyhouse.hub.ui

import android.content.Context
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.*
import org.junit.After
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(application = android.app.Application::class)
class TailorsTextSizeTest {
    @get:Rule val compose = createComposeRule()
    private val context get() = ApplicationProvider.getApplicationContext<Context>()

    @Before fun clear() { context.getSharedPreferences("tailors_text_size", Context.MODE_PRIVATE).edit().clear().commit() }
    @After fun restore() { context.getSharedPreferences("tailors_text_size", Context.MODE_PRIVATE).edit().clear().commit() }

    @Test fun `custom size persists and device setting can be restored`() {
        val first = TailorsTextSize(context)
        assertTrue(first.useDevice)
        first.chooseReference(36)
        val reopened = TailorsTextSize(context)
        assertEquals(36, reopened.reference)
        assertFalse(reopened.useDevice)
        reopened.followDevice(true)
        assertTrue(TailorsTextSize(context).useDevice)
        assertEquals(36, TailorsTextSize(context).reference)
    }

    @Test fun `reference stays in the supported range`() {
        val settings = TailorsTextSize(context)
        settings.chooseReference(1)
        assertEquals(9, settings.reference)
        settings.chooseReference(90)
        assertEquals(36, settings.reference)
    }

    @Test fun `custom text size updates app density and can return to device size`() {
        val settings = TailorsTextSize(context)
        var observed = 0f
        var deviceScale = 0f
        compose.setContent {
            deviceScale = LocalDensity.current.fontScale
            HubTheme(tailorsTextSize = settings) {
                val scale = LocalDensity.current.fontScale
                SideEffect { observed = scale }
            }
        }
        compose.runOnIdle { assertEquals(deviceScale, observed, .001f); settings.chooseReference(34) }
        compose.runOnIdle { assertEquals(2f, observed, .001f); settings.followDevice(true) }
        compose.runOnIdle { assertEquals(deviceScale, observed, .001f) }
    }
}
