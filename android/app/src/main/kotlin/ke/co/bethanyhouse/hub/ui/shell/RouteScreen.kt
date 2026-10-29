package ke.co.bethanyhouse.hub.ui.shell

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.runtime.Composable
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.NavCatalogue
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.features.Features

/**
 * Resolves a [Route] to its screen. Every route the catalogue knows is
 * handled: the feature registry decides whether a native screen exists;
 * until one does, [ConsoleBridge] shows the same page of the Hub console
 * inside the app with the person's session, so no capability is missing
 * while modules are ported. docs/NATIVE_APPS_CHECKLIST.md tracks which
 * routes are native.
 */
@Composable
fun RouteScreen(route: Route) {
    val env = LocalEnv.current
    val gate = NavCatalogue.item(route)?.gate
    if (gate != null && !env.session.current.allows(gate)) {
        EmptyState(
            title = "You don't have access to this page",
            message = "Ask a manager if you think you should. Navigation follows permission; the server enforces it too.",
            icon = Icons.Default.Lock,
            actionLabel = "Go home",
        ) { env.navigator.go(env.session.current.home) }
        return
    }
    val builder = FeatureRegistry.builder(route)
    if (builder != null) builder(route) else ConsoleBridge(route)
}

/** Native screens register here; each module file adds its routes in [Features.registerAll]. */
object FeatureRegistry {
    private class Entry(val matches: (Route) -> Boolean, val build: @Composable (Route) -> Unit)
    private val entries = mutableListOf<Entry>()
    private var registered = false

    fun register(matches: (Route) -> Boolean, build: @Composable (Route) -> Unit) { entries += Entry(matches, build) }
    fun register(route: Route, build: @Composable (Route) -> Unit) = register({ it == route }, build)
    inline fun <reified R : Route> registerType(noinline build: @Composable (R) -> Unit) = register({ it is R }) { build(it as R) }

    fun builder(route: Route): (@Composable (Route) -> Unit)? { bootstrap(); return entries.firstOrNull { it.matches(route) }?.build }
    fun isNative(route: Route): Boolean { bootstrap(); return entries.any { it.matches(route) } }

    private fun bootstrap() {
        if (registered) return
        registered = true
        Features.registerAll()
    }
}
