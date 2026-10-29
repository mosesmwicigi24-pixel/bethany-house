package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.JsonElement

/**
 * The clock-in kiosk (People C2) on a shared tablet at the door.
 *
 * A manager with attendance.manage sets the tablet up from Attendance ›
 * Kiosks (with step-up). The Hub mints a token on the manager's account
 * with the single ability `kiosk`; the server fences it to `/kiosk/…`
 * (KioskTokensStayAtTheKiosk) and gives it its own lifetime
 * (security.sessions.kiosk_days). The app keeps it under its own key, apart
 * from any person's session, and signs the manager out of the tablet.
 *
 * Nothing on the tablet decides who may punch: every punch carries the
 * person's own terminal PIN, which the server checks with its lock-out.
 */
class KioskStore(private val store: SecureStore) {
    private val _token = MutableStateFlow(store.get(TOKEN))
    val token: StateFlow<String?> = _token.asStateFlow()

    /** Why the tablet last stopped being a kiosk, shown once on the sign-in screen. */
    private val _ended = MutableStateFlow<String?>(null)
    val ended: StateFlow<String?> = _ended.asStateFlow()

    val isKiosk: Boolean get() = _token.value != null

    fun enter(token: String) {
        store.set(TOKEN, token)
        _token.value = token
        _ended.value = null
    }

    /** Forgets the token; `reason` is shown to whoever picks up the tablet next. */
    fun clear(reason: String? = null) {
        store.remove(TOKEN)
        _token.value = null
        _ended.value = reason
    }

    fun acknowledgeEnded() { _ended.value = null }

    companion object { const val TOKEN = "bh_kiosk_token" }
}

/** One person on the board, as `GET /kiosk/board` sends them. */
data class KioskPerson(
    val id: Int,
    val name: String,
    val initials: String,
    val hasPin: Boolean,
    /** "in", "break" or "out". */
    val state: String,
    val since: java.time.Instant?,
    /** The outlet they are clocked in at, when it is not this kiosk's. */
    val elsewhere: String?,
) {
    val isIn: Boolean get() = state != "out"

    companion object {
        fun from(j: JsonElement): KioskPerson? {
            val id = j["id"].int ?: return null
            return KioskPerson(
                id = id,
                name = j["name"].str ?: "",
                initials = j["initials"].str ?: "",
                hasPin = j["has_pin"].bool ?: false,
                state = j["state"].str ?: "out",
                since = j["since"].date,
                elsewhere = j["elsewhere"].str,
            )
        }
    }
}

data class KioskBoard(val kioskName: String, val outlet: String?, val people: List<KioskPerson>, val serverTime: java.time.Instant?) {
    val inNow: Int get() = people.count { it.isIn }

    companion object {
        fun from(j: JsonElement?): KioskBoard = KioskBoard(
            kioskName = j["kiosk"]["name"].str ?: "Kiosk",
            outlet = j["kiosk"]["outlet"].str,
            people = j["people"].records.mapNotNull(KioskPerson::from),
            serverTime = j["server_time"].date,
        )
    }
}

/**
 * The three kiosk endpoints, on their own client so the kiosk token never
 * mixes with a person's session. A 401 (the kiosk was removed on the Hub,
 * or it reached kiosk_days) forgets the token and the tablet goes back to
 * the set-up screen. The kiosk never waits on a PIN lock or a step-up.
 */
class KioskApi(baseUrl: okhttp3.HttpUrl, device: DeviceIdentity, private val kiosk: KioskStore) {
    val client = ApiClient(baseUrl, device).also { c ->
        c.auth = object : SessionAuth {
            override suspend fun bearerToken(): String? = kiosk.token.value
            override suspend fun sessionExpired() = kiosk.clear(ENDED_ON_HUB)
            override suspend fun awaitPinUnlock(): Boolean = false
            override suspend fun awaitStepUp(challenge: StepUpChallenge): Boolean = false
            override suspend fun downloadHeld(payload: JsonElement?) {}
        }
    }

    suspend fun board(): KioskBoard = KioskBoard.from(client.get("$BASE/board", background = true).json)

    /**
     * `POST /kiosk/punch {user_id, pin, action: in|out, photo?}`. The photo is
     * a `data:image/jpeg;base64,…` URL (the server keeps JPEGs up to 450 KB).
     * Returns the server's message ("Amina clocked in at 08:02.").
     */
    suspend fun punch(userId: Int, pin: String, clockIn: Boolean, photoDataUrl: String?): String {
        val r = client.post("$BASE/punch", jsonOf(
            "user_id" to userId,
            "pin" to pin,
            "action" to if (clockIn) "in" else "out",
            "photo" to (photoDataUrl ?: Omit),
        ))
        return r.json["message"].str ?: if (clockIn) "Clocked in." else "Clocked out."
    }

    /** `DELETE /kiosk/token`: the tablet leaves kiosk mode and its token dies on the Hub. */
    suspend fun leave() {
        try { client.delete("$BASE/token") } catch (_: ApiError) { /* already gone */ }
        kiosk.clear(null)
    }

    companion object {
        const val BASE = "/v1/admin/kiosk"
        const val ENDED_ON_HUB = "This tablet is no longer a clock-in kiosk: it was removed on the Hub or reached its time limit. A manager can set it up again from People › Attendance › Kiosks."
    }
}
