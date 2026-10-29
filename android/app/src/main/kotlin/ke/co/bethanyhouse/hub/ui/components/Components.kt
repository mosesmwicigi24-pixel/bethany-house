package ke.co.bethanyhouse.hub.ui.components

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

// ── Badges, money, dates ─────────────────────────────────────────────────────

@Composable
fun StatusBadge(status: String?, tone: StatusTone? = null, modifier: Modifier = Modifier) {
    val t = tone ?: StatusTone.forStatus(status)
    Text(
        text = (status ?: "—").humanized,
        style = MaterialTheme.typography.labelSmall,
        color = t.foreground,
        modifier = modifier.background(t.background, CircleShape).padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

/** Money exactly as the server sent it; never computed here. */
@Composable
fun MoneyText(money: Money?, emphasis: Boolean = false, modifier: Modifier = Modifier) {
    Text(
        text = money?.formatted() ?: "—",
        style = if (emphasis) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium,
        color = if (money?.isNegative == true) Palette.danger else MaterialTheme.hub.text,
        modifier = modifier,
    )
}

enum class DateStyle { DATE, DATE_TIME, TIME, RELATIVE }

fun formatDate(instant: Instant?, style: DateStyle = DateStyle.DATE_TIME): String {
    instant ?: return "—"
    val z = instant.atZone(HubDates.nairobi)
    return when (style) {
        DateStyle.DATE -> z.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(Locale.UK))
        DateStyle.DATE_TIME -> z.format(DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.UK))
        DateStyle.TIME -> z.format(DateTimeFormatter.ofPattern("HH:mm", Locale.UK))
        DateStyle.RELATIVE -> {
            val secs = Instant.now().epochSecond - instant.epochSecond
            when {
                secs < 60 -> "just now"
                secs < 3600 -> "${secs / 60} min ago"
                secs < 86_400 -> "${secs / 3600} h ago"
                secs < 7 * 86_400 -> "${secs / 86_400} d ago"
                else -> z.format(DateTimeFormatter.ofPattern("d MMM", Locale.UK))
            }
        }
    }
}

@Composable
fun DateText(instant: Instant?, style: DateStyle = DateStyle.DATE_TIME, modifier: Modifier = Modifier) {
    Text(formatDate(instant, style), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = modifier)
}

// ── States ───────────────────────────────────────────────────────────────────

@Composable
fun EmptyState(title: String, message: String? = null, icon: ImageVector = Icons.Default.Inbox, actionLabel: String? = null, action: (() -> Unit)? = null) {
    Box(Modifier.fillMaxSize().padding(Space.xxl), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(Space.m), modifier = Modifier.widthIn(max = 360.dp)) {
            Icon(icon, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.width(36.dp).height(36.dp))
            Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
            if (message != null) Text(message, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, textAlign = TextAlign.Center)
            if (action != null && actionLabel != null) Button(onClick = action) { Text(actionLabel) }
        }
    }
}

@Composable
fun ErrorState(error: ApiError, retry: (() -> Unit)? = null) {
    EmptyState(
        title = when {
            error.isOffline -> "Can't reach Bethany House"
            error.isDeleted -> "This record was deleted"
            else -> "Something went wrong"
        },
        message = if (error.isOffline) "Check the connection and try again. Anything you opened before is still available." else error.message,
        icon = if (error.isOffline) Icons.Default.WifiOff else Icons.Default.Warning,
        actionLabel = retry?.let { "Try again" },
        action = retry,
    )
}

@Composable
fun LoadingSkeleton(rows: Int = 6) {
    Column(Modifier.fillMaxWidth().padding(Space.l), verticalArrangement = Arrangement.spacedBy(Space.m)) {
        repeat(rows) { i ->
            Box(Modifier.height(18.dp).then(if (i % 3 == 0) Modifier.width(240.dp) else Modifier.fillMaxWidth()).background(MaterialTheme.hub.line, MaterialTheme.shapes.small))
        }
    }
}

@Composable
fun OfflineBanner() {
    Row(Modifier.padding(top = 8.dp).background(Palette.warningSoft, CircleShape).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Default.WifiOff, contentDescription = null, tint = Palette.warning, modifier = Modifier.width(14.dp))
        Spacer(Modifier.width(6.dp))
        Text("Offline — showing what was last loaded", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
    }
}

/** Renders a [Loadable] with the shared states; `content` gets the value. */
@Composable
fun <T> LoadableView(
    state: Loadable<T>,
    retry: (() -> Unit)? = null,
    isEmpty: (T) -> Boolean = { false },
    empty: (@Composable () -> Unit)? = null,
    content: @Composable (T) -> Unit,
) {
    when (state) {
        Loadable.Idle -> LoadingSkeleton()
        is Loadable.Loading -> if (state.previous == null) LoadingSkeleton() else content(state.previous)
        is Loadable.Failed -> if (state.previous == null) ErrorState(state.error, retry) else Column {
            if (state.error.isOffline) Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { OfflineBanner() }
            content(state.previous)
        }
        is Loadable.Loaded -> if (isEmpty(state.value) && empty != null) empty() else content(state.value)
    }
}

// ── Layout ───────────────────────────────────────────────────────────────────

@Composable
fun SectionCard(title: String? = null, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Surface(
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.hub.card,
        modifier = modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.large),
    ) {
        Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(Space.m)) {
            if (title != null) Text(title, style = MaterialTheme.typography.titleMedium)
            content()
        }
    }
}

@Composable
fun DetailRow(label: String, value: String?) {
    DetailRow(label) { Text(value ?: "—", style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.End) }
}

@Composable
fun DetailRow(label: String, value: @Composable () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.widthIn(min = 120.dp))
        Spacer(Modifier.width(Space.m))
        Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) { value() }
    }
}

@Composable
fun StatTile(label: String, value: String, hint: String? = null, tone: StatusTone? = null, modifier: Modifier = Modifier) {
    Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card, modifier = modifier.border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.large)) {
        Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(label.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            Text(value, style = MaterialTheme.typography.displaySmall, color = tone?.foreground ?: MaterialTheme.hub.text, maxLines = 1)
            if (hint != null) Text(hint, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary)
        }
    }
}

@Composable
fun FieldError(message: String?) {
    if (message != null) Text(message, style = MaterialTheme.typography.labelSmall, color = Palette.danger)
}

/** Confirmation before anything the person cannot undo. */
@Composable
fun ConfirmButton(
    title: String,
    message: String,
    destructive: Boolean = false,
    enabled: Boolean = true,
    action: suspend () -> Unit,
    label: @Composable () -> Unit,
) {
    var showing by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Button(
        onClick = { showing = true },
        enabled = enabled && !busy,
        colors = if (destructive) ButtonDefaults.buttonColors(containerColor = Palette.danger) else ButtonDefaults.buttonColors(),
    ) { if (busy) CircularProgressIndicator(Modifier.width(16.dp).height(16.dp), strokeWidth = 2.dp) else label() }
    if (showing) AlertDialog(
        onDismissRequest = { showing = false },
        title = { Text(title) },
        text = { Text(message) },
        confirmButton = { TextButton(onClick = { showing = false; busy = true; scope.launch { try { action() } finally { busy = false } } }) { Text(title, fontWeight = FontWeight.SemiBold, color = if (destructive) Palette.danger else MaterialTheme.colorScheme.primary) } },
        dismissButton = { TextButton(onClick = { showing = false }) { Text("Cancel") } },
    )
}

/** Toasts (toast.store.ts): one place to confirm an action or report an error. */
class Toasts {
    data class Toast(val id: Long, val text: String, val tone: StatusTone)
    val items = androidx.compose.runtime.mutableStateListOf<Toast>()
    private var counter = 0L

    fun show(text: String, tone: StatusTone = StatusTone.SUCCESS) {
        val t = Toast(++counter, text, tone)
        items += t
        kotlinx.coroutines.MainScope().launch { kotlinx.coroutines.delay(4000); items.remove(t) }
    }

    fun error(e: Throwable) {
        if (e is ApiError) show(e.firstFieldError ?: e.message, StatusTone.DANGER) else show(e.message ?: "Something went wrong", StatusTone.DANGER)
    }
}

@Composable
fun ToastHost(toasts: Toasts) {
    Column(Modifier.padding(Space.l), verticalArrangement = Arrangement.spacedBy(8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        toasts.items.forEach { t ->
            AnimatedVisibility(visible = true) {
                Surface(shape = CircleShape, color = t.tone.background, shadowElevation = 4.dp) {
                    Text(t.text, style = MaterialTheme.typography.bodySmall, color = t.tone.foreground, modifier = Modifier.padding(horizontal = 14.dp, vertical = 10.dp))
                }
            }
        }
    }
}
