package ke.co.bethanyhouse.hub.core

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.longOrNull
import java.time.Instant
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * Dynamic JSON access. The Hub API has no response envelope and most payloads
 * are ad-hoc (docs/api/README.md): typed models read the fields they rely on
 * and keep the rest reachable through [JsonElement], so a screen never loses a
 * field the console shows merely because the Kotlin model did not name it.
 */
object HubJson {
    val json: Json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        coerceInputValues = true
        explicitNulls = false
        encodeDefaults = true
    }

    fun parse(text: String): JsonElement = json.parseToJsonElement(text)
    fun parseOrNull(text: String): JsonElement? = runCatching { json.parseToJsonElement(text) }.getOrNull()
}

// ── Accessors (null-safe everywhere) ─────────────────────────────────────────

operator fun JsonElement?.get(key: String): JsonElement? = (this as? JsonObject)?.get(key)
operator fun JsonElement?.get(index: Int): JsonElement? = (this as? JsonArray)?.getOrNull(index)

val JsonElement?.isNull: Boolean get() = this == null || this is JsonNull
val JsonElement?.obj: JsonObject? get() = this as? JsonObject
val JsonElement?.arr: List<JsonElement>? get() = (this as? JsonArray)?.toList()

val JsonElement?.str: String?
    get() = when (val p = this as? JsonPrimitive) {
        null -> null
        else -> if (p is JsonNull) null else p.content
    }

val JsonElement?.int: Int?
    get() {
        val p = this as? JsonPrimitive ?: return null
        if (p is JsonNull) return null
        p.longOrNull?.let { return it.toInt() }
        p.doubleOrNull?.let { return Math.round(it).toInt() }
        return p.content.toIntOrNull() ?: if (p.booleanOrNull == true) 1 else if (p.booleanOrNull == false) 0 else null
    }

val JsonElement?.long: Long?
    get() {
        val p = this as? JsonPrimitive ?: return null
        if (p is JsonNull) return null
        return p.longOrNull ?: p.doubleOrNull?.let { Math.round(it) } ?: p.content.toLongOrNull()
    }

val JsonElement?.dbl: Double?
    get() {
        val p = this as? JsonPrimitive ?: return null
        if (p is JsonNull) return null
        return p.doubleOrNull ?: p.content.toDoubleOrNull()
    }

val JsonElement?.bool: Boolean?
    get() {
        val p = this as? JsonPrimitive ?: return null
        if (p is JsonNull) return null
        p.booleanOrNull?.let { return it }
        p.longOrNull?.let { return it != 0L }
        return when (p.content.lowercase()) {
            "1", "true", "yes" -> true
            "0", "false", "no", "" -> false
            else -> null
        }
    }

/** Money fields arrive as decimal strings or numbers; either way never a float here. */
val JsonElement?.money: Money? get() = Money.from(this)

/** A date-time string, Nairobi wall-clock unless it carries a zone. */
val JsonElement?.date: Instant? get() = this.str?.let(HubDates::parse)

/** A list of objects, or empty. */
val JsonElement?.records: List<JsonElement> get() = this.arr ?: emptyList()

// ── Builders ─────────────────────────────────────────────────────────────────

fun jsonOf(vararg pairs: Pair<String, Any?>): JsonObject = JsonObject(
    pairs.filter { it.second !== Omit }.associate { (k, v) -> k to toJson(v) }
)

/** Marker: leave this key out of the object entirely. */
object Omit

fun toJson(v: Any?): JsonElement = when (v) {
    null -> JsonNull
    is JsonElement -> v
    is String -> JsonPrimitive(v)
    is Boolean -> JsonPrimitive(v)
    is Int -> JsonPrimitive(v)
    is Long -> JsonPrimitive(v)
    is Double -> JsonPrimitive(v)
    is Float -> JsonPrimitive(v)
    is java.math.BigDecimal -> JsonPrimitive(v.toPlainString())
    is Money -> JsonPrimitive(v.amount.toPlainString())
    is Map<*, *> -> JsonObject(v.entries.associate { (k, value) -> k.toString() to toJson(value) })
    is Iterable<*> -> JsonArray(v.map { toJson(it) })
    is Array<*> -> JsonArray(v.map { toJson(it) })
    else -> JsonPrimitive(v.toString())
}

/**
 * The clock: `app.timezone` is Africa/Nairobi and DB timestamps are Nairobi
 * wall-clock (CLAUDE.md appendix). A string without a zone is read in Nairobi,
 * never in the device's zone and never shifted to UTC.
 */
object HubDates {
    val nairobi: ZoneId = ZoneId.of("Africa/Nairobi")
    private val wallClock = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")
    private val wallClockT = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss")
    private val dayOnly = DateTimeFormatter.ofPattern("yyyy-MM-dd")

    fun parse(s: String): Instant? {
        val t = s.trim()
        if (t.isEmpty()) return null
        runCatching { return OffsetDateTime.parse(t).toInstant() }
        runCatching { return Instant.parse(t) }
        val head = t.take(19)
        runCatching { return LocalDateTime.parse(head, wallClock).atZone(nairobi).toInstant() }
        runCatching { return LocalDateTime.parse(head, wallClockT).atZone(nairobi).toInstant() }
        runCatching { return LocalDate.parse(t.take(10), dayOnly).atStartOfDay(nairobi).toInstant() }
        return null
    }

    /** Calendar day in Nairobi as "yyyy-MM-dd", the form every report filter takes. */
    fun dayString(instant: Instant): String = instant.atZone(nairobi).toLocalDate().format(dayOnly)
    fun dayString(date: LocalDate): String = date.format(dayOnly)
    fun today(): LocalDate = LocalDate.now(nairobi)
    fun iso(instant: Instant): String = DateTimeFormatter.ISO_OFFSET_DATE_TIME.format(instant.atZone(nairobi))
}

/** Human labels for API status strings: "pending_approval" → "Pending approval". */
val String.humanized: String
    get() = replace('_', ' ').replace('-', ' ').replaceFirstChar { it.uppercase() }
