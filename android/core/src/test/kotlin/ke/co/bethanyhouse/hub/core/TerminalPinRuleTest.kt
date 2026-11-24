package ke.co.bethanyhouse.hub.core

import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

class TerminalPinRuleTest {
    @Test fun acceptsNontrivialFourToSixDigitPins() {
        listOf("4821", "50483", "593172").forEach { assertNull(TerminalPinRule.problem(it)) }
    }
    @Test fun rejectsWeakOrMalformedPins() {
        listOf("", "123", "1234567", "1111", "1234", "9876", "١٢٣٤", "12a4").forEach { assertNotNull(TerminalPinRule.problem(it)) }
    }
}
