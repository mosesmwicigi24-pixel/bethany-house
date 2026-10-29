package ke.co.bethanyhouse.hub.ui.components

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Download
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Route
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/** Opens a console path from server data (`action_url`) natively when it maps. */
@Composable
fun ActionLink(label: String, url: String?) {
    val env = LocalEnv.current
    val route = url?.let { Route.fromConsolePath(it.removePrefix("/admin")) } ?: return
    TextButton(onClick = { env.navigator.go(route) }) { Text(label) }
}

/**
 * Fetches a server document (PDF/CSV) from a download endpoint through the
 * API client (so the download gate's 403 hold is honoured) and hands it to
 * the system share sheet via the app's FileProvider.
 */
@Composable
fun DocumentButton(label: String, path: String, query: Map<String, String?> = emptyMap(), suggestedName: String, modifier: Modifier = Modifier) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    OutlinedButton(enabled = !busy, modifier = modifier, onClick = {
        busy = true
        scope.launch {
            try {
                val r = env.client.download(path, query.entries.mapNotNull { (k, v) -> v?.let { k to it } })
                val file = saveDocument(context, r, suggestedName)
                shareFile(context, file, r.contentType ?: "application/octet-stream")
            } catch (e: ApiError) {
                if (e.status != 403) env.toasts.error(e) // 403 download_approval_required is shown by SecurityPrompts
            } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
        }
    }) {
        if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else { Icon(Icons.Default.Download, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" $label") }
    }
}

suspend fun saveDocument(context: Context, response: ApiClient.Response, suggestedName: String): File = withContext(Dispatchers.IO) {
    val dir = File(context.cacheDir, "documents").apply { mkdirs() }
    val name = (response.filename ?: suggestedName).replace(Regex("[^A-Za-z0-9._-]"), "_")
    File(dir, name).apply { writeBytes(response.bytes) }
}

fun shareFile(context: Context, file: File, mime: String) {
    val uri: Uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val intent = Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    context.startActivity(Intent.createChooser(intent, file.name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}

fun openFile(context: Context, file: File, mime: String) {
    val uri: Uri = FileProvider.getUriForFile(context, context.packageName + ".files", file)
    val intent = Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
    runCatching { context.startActivity(intent) }.onFailure { shareFile(context, file, mime) }
}
