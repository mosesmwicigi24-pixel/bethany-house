package ke.co.bethanyhouse.hub.ui.features.production

import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import ke.co.bethanyhouse.hub.ui.HubTheme
import kotlinx.serialization.json.Json
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.time.LocalDate

@RunWith(RobolectricTestRunner::class)
@Config(application = android.app.Application::class, qualifiers = "w411dp-h891dp")
class CalendarMonthParityTest {
    @get:Rule val compose = createComposeRule()

    @Test fun `selecting a date filters the month and show month restores it`() {
        val orders = Json.parseToJsonElement("""[{"order_number":"A","product_name":"Shirt","due_date":"2026-11-20T10:00:00Z"},{"order_number":"B","product_name":"Coat","due_date":"2026-11-21T10:00:00Z"}]""").let { it as kotlinx.serialization.json.JsonArray }
        compose.setContent { HubTheme { CalendarMonth(orders, LocalDate.of(2026, 11, 1), true, {}, {}, {}, {}, null) } }
        compose.onNodeWithText("2 orders").assertExists()
        compose.onNodeWithContentDescription("20 November 2026, 1 due orders").performClick()
        compose.onNodeWithText("1 order").assertExists()
        compose.onNodeWithText("Shirt").assertExists()
        compose.onNodeWithText("Coat").assertDoesNotExist()
        compose.onNodeWithText("Show month").performClick()
        compose.onNodeWithText("2 orders").assertExists()
    }
}
