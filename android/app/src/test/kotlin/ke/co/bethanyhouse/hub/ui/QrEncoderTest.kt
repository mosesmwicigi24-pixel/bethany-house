package ke.co.bethanyhouse.hub.ui

import ke.co.bethanyhouse.hub.ui.auth.QrEncoder
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class QrEncoderTest {
    private fun finderAt(m: Array<BooleanArray>, x0: Int, y0: Int): Boolean {
        for (dy in 0..6) for (dx in 0..6) {
            val expected = dx == 0 || dx == 6 || dy == 0 || dy == 6 || (dx in 2..4 && dy in 2..4)
            if (m[y0 + dy][x0 + dx] != expected) return false
        }
        return true
    }

    @Test
    fun `otpauth uri encodes to a square matrix with three finder patterns`() {
        val uri = "otpauth://totp/Bethany%20House:person@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Bethany%20House"
        val m = QrEncoder.encode(uri)
        assertNotNull(m)
        val n = m!!.size
        assertTrue("version 1..10 sizes", n in 21..57 && (n - 17) % 4 == 0)
        assertTrue(m.all { it.size == n })
        assertTrue(finderAt(m, 0, 0))
        assertTrue(finderAt(m, n - 7, 0))
        assertTrue(finderAt(m, 0, n - 7))
        // Timing patterns alternate.
        for (i in 8 until n - 8) { assertEquals(i % 2 == 0, m[6][i]); assertEquals(i % 2 == 0, m[i][6]) }
        // Dark module.
        assertTrue(m[n - 8][8])
    }

    @Test
    fun `short text fits version 1 and long text picks a larger version`() {
        assertEquals(21, QrEncoder.encode("hello")!!.size)
        val long = "x".repeat(150)
        val m = QrEncoder.encode(long)!!
        assertTrue(m.size > 21)
    }

    @Test
    fun `text beyond version 10 at level M is refused rather than truncated`() {
        assertNull(QrEncoder.encode("x".repeat(300)))
    }
}
