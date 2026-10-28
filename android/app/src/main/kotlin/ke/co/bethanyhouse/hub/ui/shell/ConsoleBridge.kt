package ke.co.bethanyhouse.hub.ui.shell

import android.annotation.SuppressLint
import android.content.Intent
import android.net.Uri
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.ui.hub

/**
 * The Hub console page for a route, signed in as the person, inside the app.
 *
 * This is the bridge for screens not yet ported to native views (see
 * [RouteScreen]). It is not the product: the shell, navigation, session,
 * push, location, offline and every ported module are native. The page gets
 * the bearer token through the console's own storage key before it loads,
 * the same contract the Expo shell used, and `window.BethanyNative` so the
 * console hides its install banner and reports the device id.
 */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun ConsoleBridge(route: Route) {
    val env = LocalEnv.current
    val url = env.consoleUrl(route)
    var token by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { token = env.session.bearerToken() }
    val t = token ?: return

    Box(Modifier.fillMaxSize()) {
        AndroidView(
            modifier = Modifier.fillMaxSize(),
            factory = { context ->
                WebView(context).apply {
                    settings.javaScriptEnabled = true
                    settings.domStorageEnabled = true
                    settings.userAgentString = settings.userAgentString + " " + env.device.userAgent
                    webViewClient = object : WebViewClient() {
                        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                            val target = request.url
                            val hubHost = Uri.parse(env.config.hubUrl).host
                            if (target.host == hubHost || target.scheme == "about" || target.scheme == "blob") {
                                val r = Route.fromConsolePath((target.path ?: "").removePrefix("/admin"))
                                if (request.hasGesture() && r != null && FeatureRegistry.isNative(r)) { env.navigator.go(r); return true }
                                return false
                            }
                            // M-Pesa, maps, PDFs served elsewhere: the system browser, as the shell did.
                            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, target).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                            return true
                        }
                        override fun onPageStarted(view: WebView, u: String?, favicon: android.graphics.Bitmap?) {
                            // The console reads its token from localStorage.bh_admin_token (client.ts).
                            view.evaluateJavascript(
                                "try { localStorage.setItem('bh_admin_token', '${t.replace("'", "")}'); } catch (e) {}" +
                                    "window.BethanyNative = { platform: 'android', appVersion: '${env.device.appVersion}', deviceId: '${env.device.id}', model: '${env.device.model.replace("'", "")}' };",
                                null,
                            )
                        }
                    }
                    loadUrl(url)
                }
            },
            update = { web -> if (web.url?.substringBefore('?') != url.substringBefore('?')) web.loadUrl(url) },
        )
        Text(
            "Console page",
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.hub.textTertiary,
            modifier = Modifier.align(Alignment.BottomEnd).padding(8.dp).background(MaterialTheme.hub.card.copy(alpha = 0.9f), CircleShape).padding(horizontal = 8.dp, vertical = 4.dp),
        )
    }
}
