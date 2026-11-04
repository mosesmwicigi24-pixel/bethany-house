package ke.co.bethanyhouse.hub.render

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.core.app.ApplicationProvider
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubJson
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.ui.HubTheme
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.features.pos.PosSale
import ke.co.bethanyhouse.hub.ui.features.pos.ReceiptBody
import ke.co.bethanyhouse.hub.ui.features.pos.ReceiptHtml
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.math.BigDecimal

/**
 * The till receipt reads as the customer's documents do since #531:
 * "KES 4,500", cents only when there are some. Staff screens keep ".00".
 */
@RunWith(RobolectricTestRunner::class)
@Config(application = TestHubApp::class)
class CustomerMoneyRenderTest {
    @get:Rule val compose = createComposeRule()
    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    private val sale = PosSale(HubJson.parse("""{"id":9,"order_number":"POS-0009","currency_code":"KES","subtotal":"4500.00","tax_amount":"620.69","total":"4500.00","amount_paid":"4500.00","outstanding":"0.00",
        "items":[{"product_name":"Cope","quantity":3,"unit_price":"1500.00","subtotal":"4500.00"}],
        "payments":[{"payment_method":"cash","amount":"4500.00","status":"paid","cash_received":"5000.00","change_given":"500.00"}]}"""))

    private fun exists(text: String) = compose.onAllNodes(hasText(text), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    @Test fun theReceiptShowsWholeAmountsWithoutCents() {
        compose.setContent { HubTheme { CompositionLocalProvider(LocalEnv provides env) { ReceiptBody(sale, null) } } }
        compose.waitForIdle()
        assertTrue(exists("KES 4,500"))
        assertTrue(exists("3 × KES 1,500"))
        assertTrue(exists("KES 500"))
        assertFalse(exists("KES 4,500.00"))
    }

    @Test fun thePrintedReceiptFollowsTheSameRule() {
        val html = ReceiptHtml.render(sale, null)
        assertTrue(html.contains("KES 4,500<"))
        assertFalse(html.contains("4,500.00"))
    }

    @Test fun staffScreensKeepTheirCents() {
        compose.setContent { HubTheme { MoneyText(Money(BigDecimal("4500"), "KES")) } }
        compose.waitForIdle()
        assertTrue(exists("KES 4,500.00"))
    }
}
