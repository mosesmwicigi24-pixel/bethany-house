package ke.co.bethanyhouse.hub.ui.features.production

import ke.co.bethanyhouse.hub.core.Record
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
    @Test fun taskLanesReflectWorkAndInspection() {
        fun group(orderStatus: String, taskStatus: String, blocked: Boolean = false): TaskOrderGroup {
            val order = json("""{"status":"$orderStatus"}""")
            val task = Record(json("""{"status":"$taskStatus","stage":{"name":"Stitching"},"blocked_by_stage":${if (blocked) "\"Cutting\"" else "null"}}"""))
            return TaskOrderGroup(1, order, listOf(task))
        }
        assertEquals("In progress", taskLane(group("in_progress", "in_progress"), false))
        assertEquals("Ready to start", taskLane(group("pending", "pending"), false))
        assertEquals("Waiting", taskLane(group("pending", "pending", true), false))
        assertEquals("With Quality Control", taskLane(group("qc_pending", "completed"), false))
        assertEquals("Awaiting inspection · No action needed", taskSummary(group("qc_pending", "completed"), false))
        assertEquals("Finished stages", taskLane(group("completed", "completed"), true))
    }
    @Test fun calendarOpensOnlyAnUnambiguousExactOrderNumber() {
        val results = json("""{"data":[{"id":7,"order_number":"PRD-7"},{"id":70,"order_number":"PRD-70"}]}""")
        assertEquals(7, calendarOrderId(results, "PRD-7"))
        assertNull(calendarOrderId(results, "PRD-8"))
        assertNull(calendarOrderId(json("""{"data":[{"id":7,"order_number":"PRD-7"},{"id":8,"order_number":"PRD-7"}]}"""), "PRD-7"))
    }
}
