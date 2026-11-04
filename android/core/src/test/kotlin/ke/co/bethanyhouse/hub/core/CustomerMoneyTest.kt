package ke.co.bethanyhouse.hub.core

import java.math.BigDecimal
import kotlin.test.Test
import kotlin.test.assertEquals

/**
 * What a customer reads (owner, 2026-10-30): whole amounts without ".00",
 * cents only when there are some. The first cases are the server's own
 * (backend/tests/Feature/CustomerMoneyTest.php, CustomerMoney::format).
 */
class CustomerMoneyTest {
    private fun m(s: String, cur: String = "KES") = Money(BigDecimal(s), cur)

    @Test fun aWholeAmountShowsNoCents() {
        assertEquals("KES 4,500", m("4500.00").customerFormatted())
        assertEquals("ZAR 3,231", m("3231", "ZAR").customerFormatted())
        assertEquals("KES 0", m("0.00").customerFormatted())
        assertEquals("KES 1,000,000", m("1000000").customerFormatted())
    }

    @Test fun centsShowWhenThereAreSome() {
        assertEquals("KES 137.93", m("137.93").customerFormatted())
        assertEquals("USD 72.80", m("72.80", "USD").customerFormatted(), "an older amount with cents is never hidden")
    }

    @Test fun lessThanHalfACentReadsAsWholeAsOnTheServer() {
        assertEquals("KES 4,500", m("4500.004").customerFormatted())
        assertEquals("KES 4,500.01", m("4500.006").customerFormatted())
    }

    @Test fun negativesAndTheStaffFormatAreUnchanged() {
        assertEquals("KES -1,200", m("-1200.00").customerFormatted())
        assertEquals("KES 4,500.00", m("4500").formatted())
    }
}
