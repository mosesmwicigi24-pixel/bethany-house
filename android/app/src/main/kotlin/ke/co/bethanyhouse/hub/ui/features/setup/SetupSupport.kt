package ke.co.bethanyhouse.hub.ui.features.setup

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.arr
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.message
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import java.util.Locale

// Small helpers private to the Setup and Marketing module. Nothing here
// computes business data; they only shape what the server sent.

/** Phase 3C: a write may answer with `proposal`/`proposals` (waits for a signature) instead of applying. */
internal fun setupProposalToast(env: AppEnvironment, r: ApiClient.Response) {
    val j = r.json
    val proposed = !j["proposal"].isNull || (j["proposals"].arr?.isNotEmpty() == true)
    if (proposed) env.toasts.show(j.message ?: "Proposed — applies once signed", StatusTone.INFO) else toastResult(env, r)
}

/** "#rrggbb" → Color, or null when the string is not a colour. */
internal fun setupHexColor(s: String?): Color? {
    val hex = s?.trim()?.removePrefix("#") ?: return null
    if (hex.length != 6) return null
    val v = hex.toLongOrNull(16) ?: return null
    return Color(0xFF000000L or v)
}

/** A file size the way a person reads it. */
internal fun setupBytes(bytes: Long?): String {
    bytes ?: return "—"
    if (bytes < 1024) return "$bytes B"
    val units = arrayOf("KB", "MB", "GB", "TB")
    var v = bytes.toDouble(); var i = -1
    while (v >= 1024 && i < units.lastIndex) { v /= 1024; i++ }
    return String.format(Locale.UK, "%.1f %s", v, units[i])
}

/** A country name for an ISO code, in the app's locale; the code itself when unknown. */
internal fun setupCountryName(code: String?): String {
    code ?: return "Unknown"
    val n = Locale("", code).getDisplayCountry(Locale.UK)
    return if (n.isNullOrEmpty() || n == code) code else n
}

internal val AppEnvironment.setupPermissions get() = session.current.permissions

@Composable
internal fun SetupHeading(text: String) = Text(text, style = MaterialTheme.typography.titleMedium)

@Composable
internal fun SetupCaption(text: String, color: Color = MaterialTheme.hub.textTertiary) = Text(text, style = MaterialTheme.typography.labelSmall, color = color)

@Composable
internal fun SetupSecondary(text: String, color: Color = MaterialTheme.hub.textSecondary) = Text(text, style = MaterialTheme.typography.bodySmall, color = color)

@Composable
internal fun SetupStepUpNote(text: String = "Saving asks you to confirm it is you.") = SetupCaption(text)

/** A checkbox row (the roles matrix, clearable tables, role lists). */
@Composable
internal fun SetupCheckRow(checked: Boolean, enabled: Boolean = true, onChange: (Boolean) -> Unit, label: @Composable () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(enabled = enabled) { onChange(!checked) }.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        Checkbox(checked = checked, onCheckedChange = onChange, enabled = enabled)
        Column(Modifier.weight(1f)) { label() }
    }
}

/** A small icon action that runs a suspend call and reports failures as toasts. */
@Composable
internal fun SetupIconAction(icon: ImageVector = Icons.Default.Delete, description: String, tint: Color = Palette.danger, enabled: Boolean = true, action: suspend () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    IconButton(enabled = enabled && !busy, onClick = { busy = true; scope.launch { try { action() } catch (e: Exception) { env.toasts.error(e) } finally { busy = false } } }) {
        if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Icon(icon, contentDescription = description, tint = tint)
    }
}

/** A "key: value" map of text fields on a form, kept as Compose state. */
internal class SetupForm(initial: Map<String, String> = emptyMap()) {
    private val values = androidx.compose.runtime.mutableStateMapOf<String, String>().apply { putAll(initial) }
    operator fun get(k: String): String = values[k] ?: ""
    operator fun set(k: String, v: String) { values[k] = v }
    fun keys(): Set<String> = values.keys.toSet()
}
