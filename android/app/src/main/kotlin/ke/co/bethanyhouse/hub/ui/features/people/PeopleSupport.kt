package ke.co.bethanyhouse.hub.ui.features.people

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.AppEnvironment
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.saveDocument
import ke.co.bethanyhouse.hub.ui.components.shareFile
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonElement
import java.net.URLDecoder

/**
 * People (the manager's side): overview, requests queue, attendance,
 * checklists, roster, timesheets, leave, employee files and payroll.
 * Every figure is the server's; the screens only present and act.
 */
object PeopleFeature {
    fun register() {
        FeatureRegistry.register(Route.PeopleOverview) { PeopleOverviewScreen() }
        FeatureRegistry.register(Route.PeopleRequests) { PeopleRequestsScreen() }
        FeatureRegistry.register(Route.Attendance) { AttendanceScreen() }
        FeatureRegistry.register(Route.FormsManage) { FormsManageScreen() }
        FeatureRegistry.register(Route.Roster) { RosterScreen() }
        FeatureRegistry.register(Route.Timesheets) { TimesheetsScreen() }
        FeatureRegistry.register(Route.Leave) { LeaveAdminScreen() }
        FeatureRegistry.register({ it is Route.People }) { r ->
            val id = (r as Route.People).id
            if (id != null) PersonFileScreen(id) else PeopleListScreen()
        }
        FeatureRegistry.register(Route.Payroll) { PayrollScreen() }
    }
}

// ── Small helpers private to the People and Messages module ─────────────────

/** A select whose "" option (if any) is the placeholder, as the Apple OptionPicker shows it. */
@Composable
internal fun PeopleChoice(label: String, value: String, options: List<Pair<String, String>>, onChange: (String) -> Unit, modifier: Modifier = Modifier, error: String? = null) {
    val none = options.firstOrNull { it.first.isEmpty() }?.second ?: "Choose…"
    Column(modifier.fillMaxWidth()) {
        SelectField(label, value.ifEmpty { null }, options.filter { it.first.isNotEmpty() }, { onChange(it ?: "") }, Modifier.fillMaxWidth(), placeholder = none)
        FieldError(error)
    }
}

/** An Int? select (leave types, documents, pay groups). */
@Composable
internal fun PeopleIntChoice(label: String, value: Int?, options: List<Pair<Int, String>>, onChange: (Int?) -> Unit, placeholder: String = "Choose…", modifier: Modifier = Modifier) {
    SelectField(label, value, options, onChange, modifier.fillMaxWidth(), placeholder = placeholder)
}

/** KES as stored; payroll, advances and pay structures are KES figures the server computed. */
internal fun peopleKes(v: JsonElement?): Money? = Money.from(v, "KES")

internal fun peopleRateLabel(code: String): String = when (code) {
    "paye" -> "PAYE"; "nssf" -> "NSSF"; "shif" -> "SHIF"; "housing_levy" -> "Housing Levy"; else -> code.humanized
}

internal fun peopleLeaveSummary(r: Record): String {
    val span = if (r.string("starts_on") == r.string("ends_on")) Day.format(r.string("starts_on")) else "${Day.format(r.string("starts_on"))} – ${Day.format(r.string("ends_on"))}"
    return "${r["type"]["name"].str ?: "Leave"} · $span · ${r["days"].str ?: "?"} day(s)"
}

/** "yyyy-MM-dd" day plus "HH:mm" in Nairobi → the ISO offset string the API expects. */
internal fun peopleIsoDateTime(day: String, time: String): String? {
    val d = Day.parse(day) ?: return null
    val t = runCatching { java.time.LocalTime.parse(time.trim().take(5)) }.getOrNull() ?: return null
    return java.time.format.DateTimeFormatter.ISO_OFFSET_DATE_TIME.format(d.atTime(t).atZone(ke.co.bethanyhouse.hub.core.HubDates.nairobi))
}

// ── Signed files (link issuers answer {url, expires_at}; the file itself has no credentials) ──

/**
 * Resolves an issuer path (`/api/...` or an absolute Hub URL) through the API
 * (bearer, step-up) to a signed link, then downloads the bytes without
 * credentials; a plain non-Hub URL is fetched directly. The Apple
 * `SignedFiles.fetch`, which the shared kit does not provide.
 */
internal suspend fun peopleFetchSigned(env: AppEnvironment, source: String): ByteArray {
    val hubHost = runCatching { java.net.URI(env.config.hubUrl).host }.getOrNull() ?: ""
    var apiPath: String? = null
    if (source.startsWith("/api/")) apiPath = source.drop(4)
    else {
        val u = runCatching { java.net.URI(source) }.getOrNull()
        if (u != null && (u.host == hubHost || u.host == "localhost") && (u.path ?: "").startsWith("/api/")) apiPath = u.path.drop(4) + (u.rawQuery?.let { "?$it" } ?: "")
    }
    if (apiPath != null) {
        val q = apiPath.indexOf('?')
        val path = if (q < 0) apiPath else apiPath.substring(0, q)
        val query = if (q < 0) emptyList() else apiPath.substring(q + 1).split("&").filter { it.isNotEmpty() }.map { pair ->
            val kv = pair.split("=", limit = 2)
            URLDecoder.decode(kv[0], "UTF-8") to (kv.getOrNull(1)?.let { URLDecoder.decode(it, "UTF-8") } ?: "")
        }
        val r = env.client.get(path, query)
        val link = r.json["url"].str ?: return r.bytes
        return peopleFetchPlain(env, link.replace("http://", "https://"))
    }
    return peopleFetchPlain(env, source)
}

private suspend fun peopleFetchPlain(env: AppEnvironment, url: String): ByteArray = withContext(Dispatchers.IO) {
    val call = env.client.okHttp.newCall(okhttp3.Request.Builder().url(url).build())
    call.execute().use { r ->
        if (!r.isSuccessful) throw ApiError(r.code, "Could not fetch the file (${r.code})")
        r.body?.bytes() ?: ByteArray(0)
    }
}

/** Saves signed-file bytes under `name` and opens the share sheet (the Apple `presentFile`). */
internal suspend fun peoplePresentBytes(context: android.content.Context, bytes: ByteArray, name: String, mime: String = "application/octet-stream") {
    val file = saveDocument(context, ApiClient.Response(200, emptyMap(), bytes), name)
    shareFile(context, file, mime)
}

private fun peopleMime(name: String): String = when (name.substringAfterLast('.', "").lowercase()) {
    "pdf" -> "application/pdf"; "png" -> "image/png"; "jpg", "jpeg" -> "image/jpeg"; "gif" -> "image/gif"; "webp" -> "image/webp"
    "csv" -> "text/csv"; "txt" -> "text/plain"; "doc" -> "application/msword"; "docx" -> "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    "xls" -> "application/vnd.ms-excel"; "xlsx" -> "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    else -> "application/octet-stream"
}

/** A button that fetches a signed file and hands it to the share sheet. */
@Composable
internal fun PeopleSignedFileButton(label: String?, source: String, name: String, icon: ImageVector? = null, enabled: Boolean = true) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    val run = {
        busy = true
        scope.launch {
            try { peoplePresentBytes(context, peopleFetchSigned(env, source), name, peopleMime(name)) }
            catch (e: ApiError) { if (e.status != 403) env.toasts.error(e) }
            catch (e: Exception) { env.toasts.error(e) }
            finally { busy = false }
        }
        Unit
    }
    if (label == null && icon != null) IconButton(onClick = run, enabled = enabled && !busy) { if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Icon(icon, contentDescription = name) }
    else OutlinedButton(onClick = run, enabled = enabled && !busy) {
        if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
        else { if (icon != null) Icon(icon, contentDescription = null, modifier = Modifier.size(16.dp)); Text(if (icon != null) " ${label ?: ""}" else (label ?: "")) }
    }
}

/** An image behind a signed-link issuer or a plain URL (the Apple `SignedImage`). */
@Composable
internal fun PeopleSignedImage(source: String, maxHeight: Dp = 240.dp, modifier: Modifier = Modifier) {
    val env = LocalEnv.current
    var bitmap by remember(source) { mutableStateOf<android.graphics.Bitmap?>(null) }
    var failed by remember(source) { mutableStateOf(false) }
    LaunchedEffect(source) {
        try {
            val bytes = peopleFetchSigned(env, source)
            val b = withContext(Dispatchers.Default) { BitmapFactory.decodeByteArray(bytes, 0, bytes.size) }
            if (b == null) failed = true else bitmap = b
        } catch (_: Exception) { failed = true }
    }
    val b = bitmap
    when {
        b != null -> Image(b.asImageBitmap(), contentDescription = null, contentScale = ContentScale.Fit, modifier = modifier.fillMaxWidth().heightIn(max = maxHeight).clip(MaterialTheme.shapes.small))
        failed -> Text("Image unavailable", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        else -> CircularProgressIndicator(Modifier.size(20.dp).height(60.dp), strokeWidth = 2.dp)
    }
}
