package ke.co.bethanyhouse.hub.core

import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import java.time.Instant

/**
 * The signed-in person as `GET /admin/auth/me` returns them (openapi.json
 * `Me`). `permissions` is already the effective list: money report pages the
 * person may not open are stripped server-side by FinancialVisibility.
 */
data class Me(val raw: JsonElement) {
    val id: Int = raw["id"].int ?: throw ApiError(0, "The sign-in response did not describe a person.")
    /**
     * `/auth/me` sends `first_name` and `last_name`, not `name` (verified
     * against the live controller); the console builds the display name the
     * same way (Sidebar.tsx). `name` is kept as a fallback for older payloads.
     */
    val name: String = listOfNotNull(raw["first_name"].str, raw["last_name"].str).filter { it.isNotBlank() }.joinToString(" ").ifBlank { raw["name"].str ?: "" }
    val email: String = raw["email"].str ?: ""
    val userType: String? = raw["user_type"].str
    val permissions: List<String> = raw["permissions"].records.mapNotNull { it.str }
    val roles: List<RoleRef> = raw["roles"].records.mapNotNull { r ->
        r["name"].str?.let { RoleRef(r["id"].int, it, r["data_scope"].str) } ?: r.str?.let { RoleRef(null, it, null) }
    }
    val isOwner: Boolean = raw["is_owner"].bool ?: false
    val moneyView: String? = raw["money_view"].str
    val outlet: OutletRef? = raw["outlet"].let { o -> o["id"].int?.let { OutletRef(it, o["name"].str ?: "Outlet", o!!) } }
    val sessionPolicy: SessionPolicy? = SessionPolicy.from(raw["session_policy"])
    val terminalPinSet: Boolean = raw["terminal_pin_set"].bool ?: false
    val twoFactorRequired: Boolean = raw["two_factor_required"].bool ?: false
    val twoFactorEnabled: Boolean = raw["two_factor_enabled"].bool ?: false
    val recoveryCodesLeft: Int? = raw["recovery_codes_left"].int
    val currentDevice: JsonElement? = raw["current_device"]
    val discountCapPercent: Double? = raw["discount_cap_percent"].dbl
    val promotionCapPercent: Double? = raw["promotion_cap_percent"].dbl
    val avatarUrl: String? = raw["avatar_url"].str ?: raw["avatar"].str

    val isSuperAdmin: Boolean get() = roles.any { it.name == "super_admin" }
    val roleNames: List<String> get() = roles.map { it.name }
    val firstName: String get() = name.substringBefore(' ')

    fun encode(): String = HubJson.json.encodeToString(JsonElement.serializer(), raw)

    companion object {
        fun decode(text: String): Me? = HubJson.parseOrNull(text)?.let { runCatching { Me(it) }.getOrNull() }
    }
}

data class RoleRef(val id: Int?, val name: String, val dataScope: String?)
data class OutletRef(val id: Int, val name: String, val raw: JsonElement)

/** The idle limit that bites first, and whether it signs out or locks behind the terminal PIN. */
data class SessionPolicy(val idleMinutes: Int, val absoluteMinutes: Int, val onIdle: OnIdle) {
    enum class OnIdle { SIGN_OUT, PIN_LOCK }
    companion object {
        fun from(json: JsonElement?): SessionPolicy? {
            val i = json["idle_minutes"].int ?: return null
            val a = json["absolute_minutes"].int ?: return null
            return SessionPolicy(i, a, if (json["on_idle"].str == "pin_lock") OnIdle.PIN_LOCK else OnIdle.SIGN_OUT)
        }
    }
}

/** Laravel's default paginator shape: `{data, current_page, last_page, per_page, total}`. */
data class Paginated<T>(val data: List<T>, val currentPage: Int, val lastPage: Int, val perPage: Int, val total: Int) {
    val hasMore: Boolean get() = currentPage < lastPage
    val nextPage: Int? get() = if (hasMore) currentPage + 1 else null

    companion object {
        /** Accepts a paginator, `{data: {data: [...]}}`, `{data: [...], meta: {...}}` or a bare array. */
        fun <T> from(json: JsonElement?, map: (JsonElement) -> T): Paginated<T> {
            json.arr?.let { rows -> return Paginated(rows.map(map), 1, 1, rows.size, rows.size) }
            val nested = json["data"]
            val meta = json["meta"] ?: (if (nested.arr != null) json else nested)
            val rows = nested.arr ?: nested["data"].arr ?: emptyList()
            val src = if (nested.arr == null && nested["data"].arr != null) nested else meta
            val current = src["current_page"].int ?: 1
            val last = src["last_page"].int ?: 1
            return Paginated(rows.map(map), current, last, src["per_page"].int ?: rows.size, src["total"].int ?: rows.size)
        }
    }
}

/**
 * A row most list screens can render without a dedicated model: id, a
 * status and the full payload for the detail view.
 */
data class Record(val json: JsonElement) {
    val id: String = json["id"].str ?: json["uuid"].str ?: java.util.UUID.randomUUID().toString()
    val intId: Int? get() = json["id"].int
    operator fun get(key: String): JsonElement? = json[key]
    fun string(key: String): String? = json[key].str
    fun int(key: String): Int? = json[key].int
    fun bool(key: String): Boolean? = json[key].bool
    fun date(key: String): Instant? = json[key].date
    fun money(key: String, currency: String? = null): Money? =
        Money.from(json[key], currency ?: json["currency"].str ?: json["currency_code"].str ?: "KES")
    val status: String? get() = json["status"].str
    val obj: JsonObject? get() = json.obj
}

fun JsonElement?.toRecords(): List<Record> = this.records.map(::Record)
