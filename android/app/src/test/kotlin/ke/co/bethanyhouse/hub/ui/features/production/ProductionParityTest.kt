package ke.co.bethanyhouse.hub.ui.features.production

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * Parity with the console's production rules (Production Cycles 5 and 10).
 * The expected lists are written out by hand from the clergy measurement
 * sheet, not computed from MeasurementSheet.ORDER, so a change to the order
 * has to change this test too.
 */
class ProductionParityTest {
    private fun json(s: String): JsonElement = Json.parseToJsonElement(s)

    @Test fun measurementsFollowTheClergySheetNotTheAlphabet() {
        // Keys arrive in no useful order; alphabetical would read Arm Hole, Chest, Full Length, Neck…
        val m = MeasurementSheet.of(json("""{"full_length":"56","chest":"42","neck":"17.5","arm_hole":"9","shoulders":"18","sleeves":"25"}"""))
        assertEquals(listOf("neck", "shoulders", "sleeves", "arm_hole", "chest", "full_length"), m.body.map { it.first })
    }

    @Test fun theLadiesSheetReadsTopDown() {
        val m = MeasurementSheet.of(json("""{"Blouse Length":"24","Hips":"40","Bodice":"36","Waist":"30","Neck":"14","Full Length":"54"}"""))
        assertEquals(listOf("Neck", "Bodice", "Waist", "Hips", "Blouse Length", "Full Length"), m.body.map { it.first })
    }

    @Test fun genderIsWhoItIsForNotAMeasurement() {
        val m = MeasurementSheet.of(json("""{"gender":"ladies","hips":"40"}"""))
        assertEquals("ladies", m.gender)
        assertEquals(listOf("hips" to "40"), m.body)
    }

    @Test fun olderSpellingsFindTheirPlaceAndCustomFieldsKeepTheirTypedOrder() {
        // "length" is Full Length, "shoulder" is Shoulders; the two custom fields come last, as typed.
        val m = MeasurementSheet.of(json("""{"cape_drop":"10","length":"56","collar_style":"roman","shoulder":"18"}"""))
        assertEquals(listOf("shoulder", "length", "cape_drop", "collar_style"), m.body.map { it.first })
    }

    @Test fun numbersAndEmptiesAreHandled() {
        val m = MeasurementSheet.of(json("""{"neck":17.5,"chest":"","waist":null}"""))
        assertEquals(listOf("neck" to "17.5"), m.body)
        assertNull(m.gender)
        assertEquals(emptyList<Pair<String, String>>(), MeasurementSheet.of(null).body)
    }

    @Test fun theReworkReasonStartsFromTheLatestInspectionNote() {
        val notes = "Purple trim\n\nQC: First check: collar loose\n\nRework: Collar loose\n\nQC: One cassock: hem stitching uneven"
        assertEquals("One cassock: hem stitching uneven", lastQcNote(notes))
    }

    @Test fun aBareFailedIsNoReason() {
        assertNull(lastQcNote("QC: Failed"))
        assertNull(lastQcNote("QC: Passed"))
        assertNull(lastQcNote("No inspection yet"))
        assertNull(lastQcNote(null))
    }
}
