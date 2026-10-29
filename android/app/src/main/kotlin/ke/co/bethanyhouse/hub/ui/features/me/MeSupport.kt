package ke.co.bethanyhouse.hub.ui.features.me

import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.graphics.Paint
import android.media.ExifInterface
import android.util.Base64
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.drag
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Photo
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.SubcomposeAsyncImage
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.initials
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.components.rememberFilePicker
import ke.co.bethanyhouse.hub.ui.components.rememberPhotoCapture
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.time.Instant
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/* Shared pieces of the Me module (MeFeature.swift's support views): formatting, list rows, notices, sheets, the signature pad, photo picking, outlet choice. */

// ── Formatting (Nairobi wall-clock, as the console prints it) ────────────────

internal object MeFormat {
    private fun fmt(pattern: String): DateTimeFormatter = DateTimeFormatter.ofPattern(pattern, Locale.UK).withZone(HubDates.nairobi)

    /** "2h 05m" — minutes as the console's `hm()`. */
    fun hm(minutes: Int?): String {
        minutes ?: return "—"
        val h = minutes / 60; val m = minutes % 60
        return if (m != 0) "${h}h ${m.toString().padStart(2, '0')}m" else "${h}h"
    }

    /** "2 h 14 m" / "14 m" — ClockPage's `fmtMinutes`. */
    fun clockMinutes(minutes: Int?): String {
        minutes ?: return "—"
        val h = minutes / 60; val m = minutes % 60
        return if (h > 0) "$h h $m m" else "$m m"
    }

    fun time(d: Instant?): String = d?.let { fmt("HH:mm").format(it) } ?: ""
    fun day(d: Instant?): String = d?.let { fmt("EEE d MMM").format(it) } ?: ""
    fun longDay(d: Instant?): String = d?.let { fmt("EEEE d MMMM").format(it) } ?: ""
    fun dayYear(d: Instant?): String = d?.let { fmt("d MMM yyyy").format(it) } ?: ""
    fun dayTime(d: Instant?): String = d?.let { fmt("d MMM · HH:mm").format(it) } ?: ""
    fun weekday(d: Instant?): String = d?.let { fmt("EEE").format(it) } ?: ""
    fun dayNumber(d: Instant?): String = d?.let { fmt("d").format(it) } ?: ""

    /** "Today 09:12" / "Yesterday 17:40" / "Mon 3 Nov 08:00" — ClockPage's `fmtWhen`. */
    fun whenAt(d: Instant?): String {
        d ?: return "—"
        val day = d.atZone(HubDates.nairobi).toLocalDate()
        val today = HubDates.today()
        val dayPart = when (day) { today -> "Today"; today.minusDays(1) -> "Yesterday"; else -> day(d) }
        return "$dayPart ${time(d)}"
    }

    /** Parses an API date ("2026-10-27", "2026-10-27 08:00:00" or ISO) for display. */
    fun date(s: String?): Instant? = s?.let(HubDates::parse)

    /** "yyyy-MM-dd" in Nairobi, what every date field takes. */
    fun dayString(d: Instant): String = HubDates.dayString(d)

    /** "yyyy-MM-dd HH:mm" in Nairobi, what `due_at`, `event_at` and `publish_at` take. */
    fun dateTimeString(d: LocalDateTime): String = d.format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm"))

    fun nowLocal(): LocalDateTime = LocalDateTime.now(HubDates.nairobi)

    fun plural(n: Int, one: String, many: String? = null): String = "$n ${if (n == 1) one else (many ?: (one + "s"))}"
}

// ── Record helpers the Swift port leans on ───────────────────────────────────

internal fun Record.flag(key: String): Boolean = bool(key) ?: false
internal fun Record.double(key: String): Double? = json[key].dbl
internal fun Record.strings(key: String): List<String> = json[key].records.mapNotNull { it.str }
internal fun Record.records(key: String): List<Record> = json[key].records.map(::Record)
internal fun Record.record(key: String): Record? = json[key].obj?.let { Record(it) }

// ── Shared pieces ────────────────────────────────────────────────────────────

/** A person's avatar: their photo, or initials on brand tint. */
@Composable
internal fun PersonAvatar(name: String?, url: String?, size: Dp = 36.dp) {
    val env = LocalEnv.current
    val resolved = url?.takeIf { it.isNotBlank() }?.let { if (it.startsWith("/")) env.config.hubUrl.trimEnd('/') + it else it }
    val initialsView: @Composable () -> Unit = {
        Box(Modifier.fillMaxSize().background(Palette.brand.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
            Text(initials(name?.takeIf { it.isNotBlank() } ?: "BH"), fontSize = (size.value * 0.36f).sp, fontWeight = FontWeight.Bold, color = Palette.brandDeep)
        }
    }
    Box(Modifier.size(size).clip(CircleShape)) {
        if (resolved == null) initialsView()
        else SubcomposeAsyncImage(model = resolved, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize(), loading = { initialsView() }, error = { initialsView() })
    }
}

/** A card of rows divided by hairlines (the console's `divide-y` lists). */
@Composable
internal fun MeListCard(content: @Composable ColumnScope.() -> Unit) {
    Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.large)) {
        Column(Modifier.clip(MaterialTheme.shapes.large)) { content() }
    }
}

@Composable
internal fun MeListRow(onClick: (() -> Unit)? = null, enabled: Boolean = true, content: @Composable RowScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().then(if (onClick != null) Modifier.clickable(enabled = enabled, onClick = onClick) else Modifier)) {
        Row(Modifier.fillMaxWidth().padding(horizontal = Space.l, vertical = Space.m), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s), content = content)
        HorizontalDivider(color = MaterialTheme.hub.line, modifier = Modifier.padding(start = Space.l))
    }
}

/** A label + hint row that navigates (MePage's list of links). */
@Composable
internal fun MeLinkRow(label: String, hint: String?, route: Route) {
    val env = LocalEnv.current
    MeListRow(onClick = { env.navigator.go(route) }) {
        Text(label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
        if (hint != null) Text(hint, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp))
    }
}

/** A small tinted notice line (the console's rounded `bg-*-light` paragraphs). */
@Composable
internal fun MeNotice(text: String, tone: StatusTone = StatusTone.INFO, modifier: Modifier = Modifier) {
    Text(text, style = MaterialTheme.typography.bodySmall, color = tone.foreground, modifier = modifier.fillMaxWidth().background(tone.background, MaterialTheme.shapes.small).padding(horizontal = Space.m, vertical = Space.s))
}

/** "TODAY'S CHECKLISTS" — a small uppercase group heading. */
@Composable
internal fun MeSectionLabel(text: String) {
    Text(text.uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
}

/** The frame every Me form sheet uses: a title, Cancel, one primary action. `action` throws ApiError to keep the sheet open. */
@Composable
internal fun MeSheet(title: String, primary: String, onDismiss: () -> Unit, disabled: Boolean = false, action: suspend () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    FormSheet(title, onDismiss, saveLabel = primary, canSave = !disabled, save = action) { content() }
}

/** A labelled field group inside a sheet. */
@Composable
internal fun MeFieldLabel(label: String, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        content()
    }
}

/** A date and time in Nairobi wall-clock, picked in two steps (calendar, then clock). */
@Composable
internal fun MeDateTimeField(label: String, value: LocalDateTime, onChange: (LocalDateTime) -> Unit) {
    var pickDate by remember { mutableStateOf(false) }
    var pickTime by remember { mutableStateOf(false) }
    OutlinedButton(onClick = { pickDate = true }, modifier = Modifier.fillMaxWidth()) {
        Text("$label: ${value.format(DateTimeFormatter.ofPattern("EEE d MMM yyyy · HH:mm", Locale.UK))}")
    }
    if (pickDate) {
        val state = rememberDatePickerState(initialSelectedDateMillis = value.toLocalDate().atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli())
        DatePickerDialog(
            onDismissRequest = { pickDate = false },
            confirmButton = {
                TextButton(onClick = {
                    pickDate = false
                    state.selectedDateMillis?.let { ms -> onChange(LocalDateTime.of(Instant.ofEpochMilli(ms).atZone(ZoneOffset.UTC).toLocalDate(), value.toLocalTime())) }
                    pickTime = true
                }) { Text("Next") }
            },
            dismissButton = { TextButton(onClick = { pickDate = false }) { Text("Cancel") } },
        ) { DatePicker(state = state) }
    }
    if (pickTime) {
        val ts = rememberTimePickerState(initialHour = value.hour, initialMinute = value.minute, is24Hour = true)
        AlertDialog(
            onDismissRequest = { pickTime = false },
            title = { Text(label) },
            text = { TimePicker(state = ts) },
            confirmButton = { TextButton(onClick = { pickTime = false; onChange(value.withHour(ts.hour).withMinute(ts.minute)) }) { Text("OK") } },
            dismissButton = { TextButton(onClick = { pickTime = false }) { Text("Cancel") } },
        )
    }
}

// ── Signature pad (Canvas → PNG data URL, as the console's SignaturePad) ─────

/**
 * Draws with the finger and hands back `data:image/png;base64,…` after every
 * stroke. Rasterised at 1× (dp, not px): the server caps the decoded PNG at
 * 220 KB and a 2 px pen on white stays far under that.
 */
@Composable
internal fun SignaturePad(dataUrl: String?, onChange: (String?) -> Unit, height: Dp = 140.dp) {
    val strokes = remember { mutableStateListOf<List<Offset>>() }
    var current by remember { mutableStateOf<List<Offset>>(emptyList()) }
    var size by remember { mutableStateOf(IntSize.Zero) }
    val density = LocalDensity.current.density
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Canvas(
            Modifier.fillMaxWidth().height(height).clip(MaterialTheme.shapes.small).background(Color.White).border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small)
                .onSizeChanged { size = it }
                .pointerInput(Unit) {
                    awaitEachGesture {
                        val down = awaitFirstDown()
                        down.consume()
                        var points = listOf(down.position)
                        current = points
                        drag(down.id) { change -> change.consume(); points = points + change.position; current = points }
                        strokes += points
                        current = emptyList()
                        onChange(renderSignature(strokes.toList(), size, density))
                    }
                },
        ) {
            (strokes + listOf(current)).forEach { s ->
                val first = s.firstOrNull() ?: return@forEach
                val path = Path().apply {
                    moveTo(first.x, first.y)
                    if (s.size == 1) lineTo(first.x + 0.5f, first.y)
                    s.drop(1).forEach { lineTo(it.x, it.y) }
                }
                drawPath(path, Color(0xFF111111), style = Stroke(width = 2f * density, cap = StrokeCap.Round, join = StrokeJoin.Round))
            }
        }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(if (strokes.isEmpty()) "Sign here with your finger" else "Signed", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
            TextButton(enabled = strokes.isNotEmpty(), onClick = { strokes.clear(); current = emptyList(); onChange(null) }) { Text("Clear") }
        }
    }
}

private fun renderSignature(strokes: List<List<Offset>>, size: IntSize, density: Float): String? {
    if (strokes.isEmpty() || size.width <= 0 || size.height <= 0) return null
    val w = max(1, (size.width / density).roundToInt()); val h = max(1, (size.height / density).roundToInt())
    val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
    val canvas = android.graphics.Canvas(bmp)
    canvas.drawColor(android.graphics.Color.WHITE)
    val paint = Paint().apply { color = 0xFF111111.toInt(); strokeWidth = 2f; style = Paint.Style.STROKE; strokeCap = Paint.Cap.ROUND; strokeJoin = Paint.Join.ROUND; isAntiAlias = true }
    strokes.forEach { s ->
        val first = s.firstOrNull() ?: return@forEach
        val p = android.graphics.Path()
        p.moveTo(first.x / density, first.y / density)
        if (s.size == 1) p.lineTo(first.x / density + 0.5f, first.y / density)
        s.drop(1).forEach { p.lineTo(it.x / density, it.y / density) }
        canvas.drawPath(p, paint)
    }
    val out = ByteArrayOutputStream()
    bmp.compress(Bitmap.CompressFormat.PNG, 100, out)
    bmp.recycle()
    return "data:image/png;base64," + Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP)
}

// ── Photo picking (library and camera) ───────────────────────────────────────

internal data class MePickedPhoto(val data: ByteArray, val filename: String, val mimeType: String = "image/jpeg")

/** Re-encodes a picked photo as JPEG under ~2 MB so a checklist or post photo uploads over a shop connection (server limits 8 MB / 5 MB). */
internal fun jpegForUpload(bytes: ByteArray, maxDimension: Int = 1600): ByteArray? {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
    if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null
    var sample = 1
    while (max(bounds.outWidth, bounds.outHeight) / (sample * 2) >= maxDimension) sample *= 2
    var bmp = BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample }) ?: return null
    val rotation = runCatching {
        when (ExifInterface(ByteArrayInputStream(bytes)).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f; ExifInterface.ORIENTATION_ROTATE_180 -> 180f; ExifInterface.ORIENTATION_ROTATE_270 -> 270f; else -> 0f
        }
    }.getOrDefault(0f)
    val scale = min(1f, maxDimension.toFloat() / max(bmp.width, bmp.height).coerceAtLeast(1))
    if (scale < 1f || rotation != 0f) {
        val m = Matrix().apply { postScale(scale, scale); if (rotation != 0f) postRotate(rotation) }
        bmp = Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height, m, true)
    }
    val out = ByteArrayOutputStream()
    bmp.compress(Bitmap.CompressFormat.JPEG, 80, out)
    return out.toByteArray()
}

@Composable
internal fun MePhotoPickerField(label: String, photo: MePickedPhoto?, onChange: (MePickedPhoto?) -> Unit) {
    val context = LocalContext.current
    val hasCamera = remember { context.packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY) }
    val pick = rememberFilePicker(arrayOf("image/*")) { f -> jpegForUpload(f.bytes)?.let { onChange(MePickedPhoto(it, "photo-${System.currentTimeMillis() / 1000}.jpg")) } }
    val capture = rememberPhotoCapture { f -> jpegForUpload(f.bytes)?.let { onChange(MePickedPhoto(it, "camera-${System.currentTimeMillis() / 1000}.jpg")) } }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
        OutlinedButton(onClick = pick) { Icon(Icons.Default.Photo, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" " + if (photo == null) label else "Change photo") }
        if (hasCamera) OutlinedButton(onClick = capture) { Icon(Icons.Default.CameraAlt, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Camera") }
        if (photo != null) Row(verticalAlignment = Alignment.CenterVertically) {
            Text(photo.filename, style = MaterialTheme.typography.labelSmall, color = Palette.success, maxLines = 1, overflow = TextOverflow.Ellipsis)
            IconButton(onClick = { onChange(null) }, modifier = Modifier.size(28.dp)) { Icon(Icons.Default.Close, contentDescription = "Remove photo", tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp)) }
        }
    }
}

// ── Outlet choice for posts, articles and courses ("For: Everyone / outlet") ──

/** Outlets to address a post to. `/v1/admin/outlets` needs `outlets.view`; otherwise the person's own outlets from the time clock stand in. */
internal class MeOutletChoices(private val env: AppEnvironment) {
    var outlets: List<Pair<Int, String>> by mutableStateOf(emptyList())
        private set
    private var loaded = false

    suspend fun load() {
        if (loaded) return
        loaded = true
        val path = if (env.session.current.can("outlets.view")) "/v1/admin/outlets" else "/v1/admin/time-clock/outlets"
        val json: JsonElement? = runCatching { env.client.get(path).json }.getOrNull() ?: return
        outlets = json.listRecords.mapNotNull { r -> val id = r.intId; val name = r.string("name"); if (id != null && name != null) id to name else null }
    }
}

@Composable
internal fun MeOutletPicker(choices: MeOutletChoices, outletId: Int?, onChange: (Int?) -> Unit) {
    SelectField("For", outletId, choices.outlets, onChange, Modifier.fillMaxWidth(), placeholder = "Everyone")
}

/** Opens a web link (a document on file) in the browser. */
internal fun openExternal(context: android.content.Context, url: String) {
    runCatching { context.startActivity(android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(url)).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

internal fun LocalDate.atNairobiStart(): Instant = atStartOfDay(HubDates.nairobi).toInstant()
