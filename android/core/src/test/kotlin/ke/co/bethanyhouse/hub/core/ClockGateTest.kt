package ke.co.bethanyhouse.hub.core

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ClockGateTest {
    @Test fun normalPunchRequiresAStatusButWorksAfterOfflineFailure() {
        assertTrue(ClockGate.statusUsable(true, null))
        assertTrue(ClockGate.statusUsable(true, ApiError.transport("offline")))
        assertFalse(ClockGate.statusUsable(false, null))
        assertFalse(ClockGate.statusUsable(false, ApiError.transport("offline")))
    }

    @Test fun serverErrorsBlockAndOverridesRequireFreshStatus() {
        assertFalse(ClockGate.statusUsable(true, ApiError(500, "Hub error")))
        assertFalse(ClockGate.statusUsable(true, ApiError(401, "Signed out")))
        assertTrue(ClockGate.forceAllowed(true, null))
        assertFalse(ClockGate.forceAllowed(true, ApiError.transport("offline")))
        assertFalse(ClockGate.forceAllowed(true, ApiError(500, "Hub error")))
        assertFalse(ClockGate.forceAllowed(false, null))
    }
}
