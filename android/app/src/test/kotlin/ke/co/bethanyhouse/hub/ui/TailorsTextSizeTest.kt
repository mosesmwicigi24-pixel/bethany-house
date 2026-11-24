package ke.co.bethanyhouse.hub.ui

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(application = android.app.Application::class)
class TailorsTextSizeTest {
    private val context get() = ApplicationProvider.getApplicationContext<Context>()

    @Before fun clear() { context.getSharedPreferences("tailors_text_size", Context.MODE_PRIVATE).edit().clear().commit() }

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
}
