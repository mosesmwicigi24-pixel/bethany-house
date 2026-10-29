package ke.co.bethanyhouse.hub

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SessionStore
import ke.co.bethanyhouse.hub.ui.HubTheme
import ke.co.bethanyhouse.hub.ui.auth.SignInScreen
import ke.co.bethanyhouse.hub.ui.shell.HubShell
import kotlinx.coroutines.launch

/**
 * The single activity. It hosts the whole Compose tree, resolves deep links
 * (bethanyhub://, https://hub…/admin/…, push taps) to a [Route] and hands
 * them to the navigator once a session exists. FragmentActivity so the
 * BiometricPrompt can attach.
 */
class MainActivity : FragmentActivity() {
    private val env: AppEnvironment get() = AppEnvironment.of(this)
    private var pendingRoute: Route? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        val splash = installSplashScreen()
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        splash.setKeepOnScreenCondition { env.session.current.state == SessionStore.State.Starting }
        pendingRoute = routeFrom(intent)
        setContent {
            HubTheme {
                CompositionLocalProvider(LocalEnv provides env) {
                    Root()
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        routeFrom(intent)?.let { deliver(it) }
    }

    private fun routeFrom(intent: Intent?): Route? {
        intent ?: return null
        intent.getStringExtra(EXTRA_ROUTE_PATH)?.let { p -> return Route.fromConsolePath(p.removePrefix("/admin")) }
        val data = intent.data ?: return null
        val path = when (data.scheme) {
            "bethanyhub" -> "/" + (data.host ?: "") + (data.path ?: "")
            else -> (data.path ?: "").removePrefix("/admin")
        }
        return Route.fromConsolePath(path.ifBlank { "/" })
    }

    private fun deliver(route: Route) {
        if (env.session.current.state == SessionStore.State.SignedIn) env.navigator.go(route) else pendingRoute = route
    }

    @Composable
    private fun Root() {
        // A kiosk tablet shows only the clock-in board, whatever session exists.
        val kioskToken by env.kiosk.token.collectAsStateWithLifecycle()
        if (kioskToken != null) { ke.co.bethanyhouse.hub.ui.kiosk.KioskScreen(); return }
        val snapshot by env.session.snapshot.collectAsStateWithLifecycle()
        LaunchedEffect(Unit) { env.start() }
        LaunchedEffect(snapshot.state) {
            env.sessionDidChange()
            if (snapshot.state == SessionStore.State.SignedIn) {
                launch { env.push.register(env.session) }
                pendingRoute?.let { env.navigator.go(it); pendingRoute = null }
            }
        }
        when (snapshot.state) {
            SessionStore.State.Starting -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            SessionStore.State.SignedIn -> HubShell()
            else -> SignInScreen()
        }
    }

    companion object { const val EXTRA_ROUTE_PATH = "route_path" }
}
