package ke.co.bethanyhouse.hub.ui

import ke.co.bethanyhouse.hub.core.HubJson
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.TableColumn
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtNumber
import ke.co.bethanyhouse.hub.ui.components.fmtPct
import ke.co.bethanyhouse.hub.ui.components.initials
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.minutesText
import ke.co.bethanyhouse.hub.ui.components.personName
import kotlinx.serialization.json.JsonNull
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Test

class KitTest {
    @Test
    fun `J builders omit null and send typed figures verbatim`() {
        val o = J.obj("a" to J.sOpt(""), "b" to J.sOpt("x"), "n" to J.typed(" 12.50 "), "d" to J.decimal("1,250.75"))
        assertFalse("blank string is omitted", o.containsKey("a"))
        assertEquals("\"x\"", o["b"].toString())
        assertEquals("\"12.50\"", o["n"].toString())
        assertEquals("\"1250.75\"", o["d"].toString())
        assertNull(J.decimal("abc"))
    }

    @Test
    fun `day helpers stay in yyyy-MM-dd and Nairobi terms`() {
        assertEquals("2026-10-26", Day.monday("2026-10-28"))
        assertEquals("2026-10-01", Day.firstOfMonth("2026-10-28"))
        assertEquals("2026-10-31", Day.lastOfMonth("2026-10-28"))
        assertEquals("2026-02-28", Day.lastOfMonth("2026-02-10"))
        assertEquals("2026-11-02", Day.add("2026-10-28", 5))
        assertEquals("—", Day.format(null))
        assertEquals("14:05", Day.time("2026-10-28 14:05:00"))
    }

    @Test
    fun `table cells print the server figure at the row currency`() {
        val rows = jrows(HubJson.parse("""[{"name":"Mara","total":"1250.5","currency":"USD","share":12.345,"status":"paid","qty":"3"}]"""))
        val row: JRow = rows.single()
        assertEquals("USD 1,250.50", TableColumn("Total", "total", TableColumn.Kind.MONEY).text(row))
        assertEquals("KES 1,250.50", TableColumn("Total", "total", TableColumn.Kind.MONEY, fixedCurrency = "KES").text(row))
        assertEquals("12.3%", TableColumn("Share", "share", TableColumn.Kind.PCT).text(row))
        assertEquals("Paid", TableColumn("Status", "status", TableColumn.Kind.STATUS).text(row))
        assertEquals("3", TableColumn("Qty", "qty", TableColumn.Kind.INT).text(row))
        assertEquals("—", TableColumn("Missing", "nope", TableColumn.Kind.MONEY).text(row))
    }

    @Test
    fun `formatting helpers`() {
        assertEquals("1,234", fmtNumber(1234.4))
        assertEquals("1,234.40", fmtNumber(1234.4, 2))
        assertEquals("—", fmtPct(null))
        assertEquals("—", fmtMoney(JsonNull))
        assertEquals("2h 05m", minutesText(125))
        assertEquals("3h", minutesText(180))
        assertEquals("MW", initials("Moses Wanjiru Mwicigi"))
        assertEquals("Ann Lee", personName(HubJson.parse("""{"first_name":"Ann","last_name":"Lee"}""")))
        assertEquals("Ann", personName(HubJson.parse("""{"name":"Ann"}""")))
        assertNull(personName(JsonNull))
    }
}
