package ke.co.bethanyhouse.hub.core

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.JsonElement

/**
 * Registers the FCM registration token with the Hub
 * (`POST /admin/push/subscribe-device`, native apps Step 2) and removes it on
 * sign-out. The token arrives from FirebaseMessaging (service `onNewToken` or
 * `getToken()`); registration waits until a session exists and re-runs on
 * every sign-in so the row always points at the person using the device.
 */
class PushRegistration(private val client: ApiClient, private val device: DeviceIdentity, private val store: SecureStore) {
    private val _token = MutableStateFlow(store.get(ACCOUNT))
    val token: StateFlow<String?> = _token.asStateFlow()
    @Volatile private var registeredToken: String? = null
    @Volatile var lastError: ApiError? = null
        private set

    fun didReceive(token: String) {
        _token.value = token
        store.set(ACCOUNT, token)
    }

    suspend fun register(session: SessionStore) {
        val t = _token.value ?: return
        if (session.current.state != SessionStore.State.SignedIn || t == registeredToken) return
        try {
            client.post("/v1/admin/push/subscribe-device", jsonOf("platform" to "android", "token" to t, "model" to device.model))
            registeredToken = t
            lastError = null
        } catch (e: ApiError) {
            lastError = e
        }
    }

    suspend fun unregister(session: SessionStore) {
        val t = registeredToken ?: _token.value ?: return
        runCatching { client.post("/v1/admin/push/unsubscribe-device", jsonOf("token" to t)) }
        registeredToken = null
    }

    companion object { private const val ACCOUNT = "bh_fcm_token" }
}

/** The payload a Hub push carries (FcmSender data map): `url` is a console path, `icon` the family. */
data class PushPayload(val title: String?, val body: String?, val route: Route?, val icon: String?, val data: Map<String, String>) {
    companion object {
        fun from(data: Map<String, String>, title: String? = null, body: String? = null) = PushPayload(
            title = title ?: data["title"],
            body = body ?: data["body"],
            route = data["url"]?.let(Route::fromConsolePath),
            icon = data["icon"],
            data = data,
        )
    }
}
