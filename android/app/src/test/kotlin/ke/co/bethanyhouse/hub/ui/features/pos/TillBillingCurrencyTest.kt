package ke.co.bethanyhouse.hub.ui.features.pos

import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.DeviceIdentity
import ke.co.bethanyhouse.hub.core.HubJson
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toHubBaseUrl
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The till bills a customer abroad in their currency (P1, 2026-11-05).
 *
 * The defect: the cart was priced in shillings and the sale named only the
 * country, so a KES 2,500 dress sold to a US customer was billed USD 2,500.
 * The Hub's USD price for it is USD 25 (pricing rate 0.01); the till must
 * carry that price, say it is in USD, and drop a line it cannot price.
 */
class TillBillingCurrencyTest {
    private lateinit var server: MockWebServer
    private lateinit var scope: CoroutineScope
    private lateinit var session: PosSession

    private val dressKes = """{"id":1,"name":"Kitenge Dress","sku":"SKU-1","variants":[{"id":11,"sku":"VAR-1","price":2500,"currency":"KES","stock":40,"is_default":true,"tax_rate":0}]}"""

    @Before fun setUp() {
        server = MockWebServer().apply {
            dispatcher = object : Dispatcher() {
                override fun dispatch(request: RecordedRequest): MockResponse {
                    val url = request.requestUrl!!
                    val body = when {
                        url.encodedPath.endsWith("/v1/countries") ->
                            """{"data":[{"code":"KE","name":"Kenya","sale_currency":"KES","is_active":true},{"code":"US","name":"United States","sale_currency":"USD","is_active":true},{"code":"ZM","name":"Zambia","sale_currency":"ZMW","is_active":true}]}"""
                        url.encodedPath.endsWith("/v1/settings/currencies") ->
                            """{"data":[{"code":"KES","sellable":true},{"code":"USD","sellable":true},{"code":"ZMW","sellable":true}]}"""
                        url.encodedPath.endsWith("/pos/products/search") -> when (url.queryParameter("currency")) {
                            "USD" -> """{"data":[{"id":1,"name":"Kitenge Dress","sku":"SKU-1","variants":[{"id":11,"sku":"VAR-1","price":25,"currency":"USD","stock":40,"is_default":true,"tax_rate":0}]}]}"""
                            "ZMW" -> """{"data":[{"id":1,"name":"Kitenge Dress","sku":"SKU-1","variants":[{"id":11,"sku":"VAR-1","price":null,"price_unavailable":true,"currency":"ZMW","stock":40,"is_default":true,"tax_rate":0}]}]}"""
                            else -> """{"data":[$dressKes]}"""
                        }
                        else -> """{"data":[]}"""
                    }
                    return MockResponse().setHeader("Content-Type", "application/json").setBody(body)
                }
            }
            start()
        }
        val client = ApiClient(server.url("/").toString().trimEnd('/').toHubBaseUrl(), DeviceIdentity("0123456789abcdef0123", "Galaxy Tab S9", "2.0.0+1"))
        scope = CoroutineScope(SupervisorJob() + Dispatchers.Unconfined)
        session = PosSession(PosApi(client), null, null, scope)
        session.outlet = PosOutlet.from(HubJson.parse("""{"id":1,"name":"Westlands","code":"WL","currency_code":"KES"}"""))
        val dress = PosProduct.from(HubJson.parse(dressKes))!!
        session.add(dress, dress.variants.first(), false)
    }

    @After fun tearDown() { scope.cancel(); server.shutdown() }

    private fun line() = session.cartPayload()["items"].records.single()

    @Test fun aShillingSaleStatesItsLinesAreInShillings() {
        assertEquals("KES", session.pricingCurrency)
        assertEquals("KES", line()["price_currency"].str)
        assertEquals("2500", line()["unit_price"].str)
        assertFalse(session.cartPayload().containsKey("currency_code"))
    }

    @Test fun aCustomerInTheUsIsBilledTheDollarPriceNotTheShillingFigure() = runBlocking {
        session.loadBillingLists()
        val removed = session.setCountry("US")

        assertTrue(removed.isEmpty())
        assertEquals("USD", session.pricingCurrency)
        assertEquals("25", line()["unit_price"].str)
        assertEquals("USD", line()["price_currency"].str)
        assertEquals("US", session.cartPayload()["customer_country_code"].str)
        assertFalse("their country's default needs no stated choice", session.cartPayload().containsKey("currency_code"))
    }

    @Test fun aLineTheHubCannotPriceInTheChosenCurrencyIsRemovedAndNamed() = runBlocking {
        session.loadBillingLists()
        session.setCountry("US")
        assertTrue(session.billsAbroad)
        assertTrue("ZMW" in session.billingOptions)

        val removed = session.chooseBillCurrency("ZMW")

        assertEquals(listOf("Kitenge Dress"), removed)
        assertTrue(session.cart.isEmpty())
        assertEquals("ZMW", session.cartPayload()["currency_code"].str)
    }

    @Test fun choosingTheirCountrysCurrencyAgainClearsTheChoice() = runBlocking {
        session.loadBillingLists()
        session.setCountry("US")
        session.chooseBillCurrency("USD")

        assertEquals("", session.billCurrency)
        assertEquals("USD", line()["price_currency"].str)
    }
}
