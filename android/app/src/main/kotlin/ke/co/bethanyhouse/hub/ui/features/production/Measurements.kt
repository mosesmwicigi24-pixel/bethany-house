package ke.co.bethanyhouse.hub.ui.features.production

import ke.co.bethanyhouse.hub.core.str
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/**
 * An order's measurements as the tailor reads them: the clergy measurement
 * sheet's order, top of the body down (custom fields after, in the order they
 * were typed), empty values dropped, and gender pulled out — it is who the
 * garment is for, not a body measurement.
 *
 * The same rule as the console's `orderMeasurements` (react-admin
 * productionUi.tsx, Production Cycle 5). The generic `stringPairs` sorts
 * keys alphabetically, which put "Arm Hole" first and "Neck" in the middle.
 */
internal object MeasurementSheet {
    /** Both sheets merged (men's and ladies'), top of the body down. */
    val ORDER = listOf(
        "neck", "shoulders", "sleeves", "wrist", "arm_hole", "upper_arm",
        "chest", "bodice", "stomach", "waist", "hips", "shirt_length", "blouse_length", "full_length",
    )

    /** Spellings seen on older orders and other forms. */
    private val ALIAS = mapOf(
        "hip" to "hips", "shoulder" to "shoulders", "sleeve" to "sleeves", "armhole" to "arm_hole",
        "sleeve_length" to "sleeves", "shoulder_width" to "shoulders", "length" to "full_length",
    )

    private fun normalise(key: String): String {
        val n = key.lowercase().trim().replace(Regex("[\\s-]+"), "_")
        return ALIAS[n] ?: n
    }

    fun rank(key: String): Int = ORDER.indexOf(normalise(key)).let { if (it == -1) ORDER.size else it }

    private fun isGender(key: String) = key.lowercase().replace(Regex("[^a-z]"), "") == "gender"

    data class Measured(val gender: String?, val body: List<Pair<String, String>>)

    fun of(record: JsonElement?): Measured {
        val entries = (record as? JsonObject)?.entries
            ?.mapNotNull { (k, v) -> v.str?.trim()?.takeIf { it.isNotEmpty() }?.let { k to it } }
            ?: emptyList()
        val gender = entries.firstOrNull { isGender(it.first) }?.second
        // sortedWith is stable: equal ranks (custom fields) keep their typed order.
        val body = entries.filterNot { isGender(it.first) }.sortedWith(compareBy { rank(it.first) })
        return Measured(gender, body)
    }
}
