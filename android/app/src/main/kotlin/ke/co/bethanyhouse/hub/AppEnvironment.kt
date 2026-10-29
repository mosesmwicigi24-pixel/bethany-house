package ke.co.bethanyhouse.hub

import android.app.Application
import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ConnectionMonitor
import ke.co.bethanyhouse.hub.core.DeviceIdentity
import ke.co.bethanyhouse.hub.core.LocationService
import ke.co.bethanyhouse.hub.core.OfflineStore
import ke.co.bethanyhouse.hub.core.PushRegistration
import ke.co.bethanyhouse.hub.core.RealtimeClient
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SecureStore
import ke.co.bethanyhouse.hub.core.SessionStore
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.toHubBaseUrl
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Toasts
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Everything the app wires once and every screen reads from the
 * composition: the session, toasts, connection state, realtime, offline
 * store, location, biometrics and the navigator. Constructor injection
 * throughout; this is the one place that news objects up.
 */
class AppEnvironment(
    val app: Application,
    hubUrl: String = BuildConfig.HUB_URL,
    val secureStore: SecureStore = SecureStore(app),
) {
    data class Config(val hubUrl: String, val reverbKey: String?, val reverbHost: String?, val reverbPort: Int, val reverbScheme: String)

    val config = Config(hubUrl, BuildConfig.REVERB_KEY.ifBlank { null }, BuildConfig.REVERB_HOST.ifBlank { null }, BuildConfig.REVERB_PORT, BuildConfig.REVERB_SCHEME)
    val device = DeviceIdentity.current(secureStore, BuildConfig.VERSION_NAME + "+" + BuildConfig.VERSION_CODE)
    val client = ApiClient(config.hubUrl.toHubBaseUrl(), device)
    val session = SessionStore(client, secureStore)
    val push = PushRegistration(client, device, secureStore).also { session.pushRegistrar = it }
    val toasts = Toasts()
    val connection = ConnectionMonitor(app)
    val location = LocationService(app)
    val navigator = Navigator()
    /** Clock-in kiosk mode (People C2): its own token and client, apart from any person's session. */
    val kiosk = ke.co.bethanyhouse.hub.core.KioskStore(secureStore)
    val kioskApi = ke.co.bethanyhouse.hub.core.KioskApi(config.hubUrl.toHubBaseUrl(), device, kiosk)
    val biometrics = BiometricLock()
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    var realtime: RealtimeClient? = null
        private set
    var offline: OfflineStore? = null
        private set
    var unreadNotifications by mutableIntStateOf(0)
    var pendingCount by mutableIntStateOf(0)
        private set

    fun consoleUrl(route: Route): String = config.hubUrl.trimEnd('/') + "/admin" + route.consolePath

    suspend fun start() {
        session.start()
        sessionDidChange()
    }

    /** After sign-in/sign-out: realtime and offline follow the person. */
    suspend fun sessionDidChange() {
        val s = session.current
        val me = s.me
        if (me != null && s.state == SessionStore.State.SignedIn) {
            val store = OfflineStore(app, me.id)
            offline = store
            pendingCount = store.pending().size
            val key = config.reverbKey; val host = config.reverbHost
            if (key != null && host != null && realtime == null) {
                val rt = RealtimeClient(RealtimeClient.Configuration(key, host, config.reverbPort, config.reverbScheme), client)
                realtime = rt
                rt.connect()
                rt.subscribe("user.${me.id}") { event ->
                    if (event.name.endsWith("notification.pushed")) scope.launch {
                        unreadNotifications += 1
                        event.payload["title"].str?.let { toasts.show(it, StatusTone.INFO) }
                    }
                }
            }
        } else {
            realtime?.disconnect(); realtime = null
            offline = null
            pendingCount = 0
        }
    }

    /** Replays queued offline operations when a connection returns. */
    suspend fun replayPending() {
        val store = offline ?: return
        if (!connection.isOnline.value) return
        val result = store.replay(client)
        pendingCount = store.pending().size
        if (result.sent.isNotEmpty()) toasts.show("${result.sent.size} saved change${if (result.sent.size == 1) "" else "s"} sent")
        result.refused.forEach { (op, e) -> toasts.show("${op.kind.humanized} was refused: ${e.message}", StatusTone.DANGER) }
    }

    fun observeLifecycle() {
        ProcessLifecycleOwner.get().lifecycle.addObserver(object : DefaultLifecycleObserver {
            override fun onStart(owner: LifecycleOwner) {
                // A kiosk is a shared tablet: no one's biometric re-lock applies to it.
                if (!kiosk.isKiosk) biometrics.willEnterForeground()
                scope.launch { session.didBecomeActive(); replayPending() }
            }
            override fun onStop(owner: LifecycleOwner) { biometrics.didEnterBackground() }
        })
    }

    companion object {
        fun of(context: Context): AppEnvironment = (context.applicationContext as HubApplication).env
    }
}

val LocalEnv = staticCompositionLocalOf<AppEnvironment> { error("No AppEnvironment") }

/** Where the person is; routes are values so deep links, push taps, search and the nav all go through [go]. */
class Navigator {
    var selection: Route? by mutableStateOf(null)
    val path = androidx.compose.runtime.mutableStateListOf<Route>()
    var searchPresented by mutableStateOf(false)
    var drawerOpen by mutableStateOf(false)

    fun go(route: Route) {
        val parent = route.parent
        if (ke.co.bethanyhouse.hub.core.NavCatalogue.item(route) != null) { selection = route; path.clear() }
        else if (parent != null) { selection = parent; path.clear(); path += route }
        else { selection = route; path.clear() }
        drawerOpen = false
    }

    fun push(route: Route) { path += route }
    fun pop(): Boolean = if (path.isNotEmpty()) { path.removeAt(path.lastIndex); true } else false
    val current: Route? get() = path.lastOrNull() ?: selection
}

/** Biometric re-lock after five minutes in the background (device boundary only; the server session is untouched). */
class BiometricLock {
    var locked by mutableStateOf(false)
    private var backgroundedAt: Long? = null
    fun didEnterBackground() { backgroundedAt = System.currentTimeMillis() }
    fun willEnterForeground() {
        val t = backgroundedAt
        if (t != null && System.currentTimeMillis() - t > GRACE_MS) locked = true
        backgroundedAt = null
    }
    fun unlocked() { locked = false }
    companion object { const val GRACE_MS = 5 * 60 * 1000L }
}
