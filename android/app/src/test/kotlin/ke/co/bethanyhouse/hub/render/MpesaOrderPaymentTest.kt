package ke.co.bethanyhouse.hub.render

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ApplicationProvider
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubJson
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.ui.HubTheme
import ke.co.bethanyhouse.hub.ui.features.sales.RecordPaymentSheet
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The order page's "Record payment" with M-Pesa and a typed receipt asks
 * Safaricom first, as the console has since #522 (P2, 2026-11-05). Before,
 * the receipt was saved as paid without ever being checked.
 */
@RunWith(RobolectricTestRunner::class)
@Config(application = TestHubApp::class)
class MpesaOrderPaymentTest {
    @get:Rule val compose = createComposeRule()
    private val env get() = ApplicationProvider.getApplicationContext<TestHubApp>().env

    @Before fun reset() { MockHub.payments.clear(); MockHub.mpesaVerifyStatus = 202 }
    @After fun restore() { MockHub.mpesaVerifyStatus = 202 }

    private fun order(withMpesaRequest: Boolean) = Record(HubJson.parse(
        """{"id":9,"order_number":"ORD-9","currency_code":"KES","total_amount":"4500.00","payment_status":"pending",
           "payments":[${if (withMpesaRequest) """{"id":41,"payment_method":"mpesa","status":"pending","amount":"4500.00"}""" else ""}]}"""
    ))

    private fun settle() = repeat(10) { Thread.sleep(40); compose.mainClock.advanceTimeBy(200); compose.waitForIdle() }
    private fun exists(text: String) = compose.onAllNodes(hasText(text, substring = true), useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()
    private fun type(label: String, value: String) = compose.onNode(hasText(label) and hasSetTextAction(), useUnmergedTree = false).performTextInput(value)
    private fun press(text: String) = compose.onNode(hasText(text) and hasClickAction()).performSemanticsAction(SemanticsActions.OnClick)

    private fun open(order: Record) {
        compose.setContent { HubTheme { CompositionLocalProvider(LocalEnv provides env) { RecordPaymentSheet(order, {}, {}) } } }
        settle()
        type("Amount (KES)", "4500")
        type("Reference / receipt", "QHK7ABC123")
        settle()
    }

    @Test fun aReceiptIsCheckedWithSafaricomNotSavedAsPaid() {
        open(order(withMpesaRequest = true))
        assertTrue(exists("Check with Safaricom"))
        press("Check with Safaricom"); settle()

        assertEquals(1, MockHub.payments.size)
        val sent = MockHub.payments.single()
        assertTrue(sent, sent.contains("/orders/9/payments/41/verify-mpesa") && sent.contains("\"transaction_code\":\"QHK7ABC123\""))
    }

    @Test fun aCodeSafaricomDoesNotConfirmIsRecordedOnlyByTheExplicitChoice() {
        MockHub.mpesaVerifyStatus = 422
        open(order(withMpesaRequest = true))
        press("Check with Safaricom"); settle()

        assertEquals("nothing recorded yet", 1, MockHub.payments.size)
        assertTrue(exists("Record QHK7ABC123 without Safaricom confirmation"))
        press("Record QHK7ABC123 without Safaricom confirmation"); settle()

        assertEquals(2, MockHub.payments.size)
        assertTrue(MockHub.payments[1], Regex("POST /api/v1/admin/orders/9/payments \\{").containsMatchIn(MockHub.payments[1]))
        assertTrue(MockHub.payments[1].contains("\"reference\":\"QHK7ABC123\""))
    }

    @Test fun withNoMpesaRequestToCheckAgainstNothingIsSentUntilTheCashierChooses() {
        open(order(withMpesaRequest = false))
        press("Check with Safaricom"); settle()

        assertTrue(MockHub.payments.isEmpty())
        assertTrue(exists("no M-Pesa request on this order"))
        assertTrue(exists("Record QHK7ABC123 without Safaricom confirmation"))
    }
}
