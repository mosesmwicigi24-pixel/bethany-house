package ke.co.bethanyhouse.hub.ui.features.reports

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.OpenInNew
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiClient
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.SessionStore
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateField
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.JRow
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.fmtMoney
import ke.co.bethanyhouse.hub.ui.components.fmtNumber
import ke.co.bethanyhouse.hub.ui.components.jpairs
import ke.co.bethanyhouse.hub.ui.components.jrows
import ke.co.bethanyhouse.hub.ui.components.openFile
import ke.co.bethanyhouse.hub.ui.components.outletOptions
import ke.co.bethanyhouse.hub.ui.components.saveDocument
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.temporal.TemporalAdjusters
import java.util.Locale

// The shared report frame (react-admin/src/pages/reports/reportShared.tsx,
// apple ReportShell.swift): date presets and a custom range in Nairobi time,
// the outlet filter (reports/outlets), Export CSV (?export=csv on the page's
// own endpoint), Download PDF (/v1/admin/reports/pdf/{type}) and "Open PDF"
// in place of print, the "records behind this number" drill
// (reports/drill/{metric}) and the scheduled-report sheet (reports/schedules).
//
// Every figure a report shows is the server's (MetricEngine or
// ReportController). This file never aggregates, converts or rounds money.

// ── Date window ──────────────────────────────────────────────────────────────

/** `backendPeriod` is a preset MetricEngine::resolvePeriod knows by name; anything else goes as period=custom with dates. */
enum class DatePreset(val label: String, val backendPeriod: String?) {
    TODAY("Today", "today"),
    YESTERDAY("Yesterday", "yesterday"),
    THIS_WEEK("This week", null),
    LAST_WEEK("Last week", null),
    LAST_7_DAYS("Last 7 days", "last_7"),
    LAST_30_DAYS("Last 30 days", "last_30"),
    LAST_60_DAYS("Last 60 days", null),
    LAST_90_DAYS("Last 90 days", null),
    THIS_MONTH("This month", "this_month"),
    LAST_MONTH("Last month", "last_month"),
    THIS_QUARTER("This quarter", "this_quarter"),
    LAST_QUARTER("Last quarter", null),
    THIS_YEAR("This year", "this_year"),
    LAST_YEAR("Last year", null),
    CUSTOM("Custom range", null),
}

/**
 * The window every report reads, computed in Africa/Nairobi so a period
 * boundary never shifts three hours (CLAUDE.md appendix, clock). Weeks start
 * on Sunday, as the console's datePresetRange (dayjs startOf("week")) does.
 */
data class ReportWindow(
    val preset: DatePreset = DatePreset.THIS_MONTH,
    val customStart: LocalDate = HubDates.today(),
    val customEnd: LocalDate = HubDates.today(),
    val outletId: Int? = null,
) {
    /** [start, end] as calendar days (end inclusive). */
    val range: Pair<LocalDate, LocalDate>
        get() {
            val today = HubDates.today()
            fun weekStart(d: LocalDate) = d.with(TemporalAdjusters.previousOrSame(DayOfWeek.SUNDAY))
            fun quarterStart(d: LocalDate) = LocalDate.of(d.year, ((d.monthValue - 1) / 3) * 3 + 1, 1)
            return when (preset) {
                DatePreset.TODAY -> today to today
                DatePreset.YESTERDAY -> today.minusDays(1).let { it to it }
                DatePreset.THIS_WEEK -> weekStart(today) to today
                DatePreset.LAST_WEEK -> weekStart(today.minusWeeks(1)).let { it to it.plusDays(6) }
                DatePreset.LAST_7_DAYS -> today.minusDays(6) to today
                DatePreset.LAST_30_DAYS -> today.minusDays(29) to today
                DatePreset.LAST_60_DAYS -> today.minusDays(59) to today
                DatePreset.LAST_90_DAYS -> today.minusDays(89) to today
                DatePreset.THIS_MONTH -> today.withDayOfMonth(1) to today
                DatePreset.LAST_MONTH -> today.minusMonths(1).let { it.withDayOfMonth(1) to it.withDayOfMonth(it.lengthOfMonth()) }
                DatePreset.THIS_QUARTER -> quarterStart(today) to today
                DatePreset.LAST_QUARTER -> quarterStart(today.minusMonths(3)).let { it to it.plusMonths(3).minusDays(1) }
                DatePreset.THIS_YEAR -> today.withDayOfYear(1) to today
                DatePreset.LAST_YEAR -> today.minusYears(1).let { LocalDate.of(it.year, 1, 1) to LocalDate.of(it.year, 12, 31) }
                DatePreset.CUSTOM -> if (customStart <= customEnd) customStart to customEnd else customEnd to customStart
            }
        }

    val startString: String get() = HubDates.dayString(range.first)
    val endString: String get() = HubDates.dayString(range.second)

    /** ReportController spelling: start_date / end_date / outlet_id. */
    fun dateParams(extra: Map<String, String?> = emptyMap()): Map<String, String?> =
        mapOf("start_date" to startString, "end_date" to endString, "outlet_id" to outletId?.toString()) + extra

    /** MetricEngine spelling: a known preset by name, else period=custom with from/to. */
    fun periodParams(extra: Map<String, String?> = emptyMap()): Map<String, String?> {
        val q = mutableMapOf<String, String?>("outlet_id" to outletId?.toString())
        val p = preset.backendPeriod
        if (p != null) q["period"] = p else { q["period"] = "custom"; q["from"] = startString; q["to"] = endString }
        q.putAll(extra)
        return q
    }

    /** Only the outlet, for "as of now" endpoints. */
    fun outletParams(extra: Map<String, String?> = emptyMap()): Map<String, String?> = mapOf("outlet_id" to outletId?.toString()) + extra

    val description: String
        get() {
            val f = DateTimeFormatter.ofPattern("d MMM yyyy", Locale.UK)
            val (s, e) = range
            return if (s == e) s.format(f) else "${s.format(f)} – ${e.format(f)}"
        }
}

/** The period and outlet follow the reader from page to page (reportShared useDateRange remembers them for the session), and the open drill. */
object ReportFilters {
    var window: ReportWindow by mutableStateOf(ReportWindow())
    var outlets: List<Pair<Int, String>> by mutableStateOf(emptyList())
        private set
    private var outletsLoaded = false
    var drill: DrillRequest? by mutableStateOf(null)

    suspend fun loadOutletList(client: ApiClient) {
        if (outletsLoaded) return
        outlets = outletOptions(client, reports = true)
        outletsLoaded = true
        val id = window.outletId
        if (id != null && outlets.none { it.first == id }) window = window.copy(outletId = null)
    }

    fun outletName(id: Int?): String = id?.let { i -> outlets.firstOrNull { it.first == i }?.second } ?: "All outlets"
}

data class DrillRequest(val metric: String, val title: String, val bucket: String? = null)

// ── Export permissions ────────────────────────────────────────────────────────

/** May this person take a file out of this page (ReportPages::canExport)? */
internal fun SessionStore.Snapshot.canExportReport(page: Route.ReportPage): Boolean {
    if (!can(page.permission)) return false
    if (can("reports.export")) return true
    return (page == Route.ReportPage.INVENTORY || page == Route.ReportPage.PROCUREMENT) && can("reports.export_supply")
}

// ── Shell ────────────────────────────────────────────────────────────────────

/** CSV export description: the page endpoint that answers `?export=csv`. */
data class CSVExport(val path: String, val query: Map<String, String?>, val name: String)

/** Bumped by pull-to-refresh so every [ReportData] on the page re-fetches. */
internal val LocalReportVersion = compositionLocalOf { 0 }

@Composable
fun ReportShell(
    page: Route.ReportPage,
    subtitle: String? = null,
    csv: ((ReportWindow) -> CSVExport)? = null,
    /** The /reports/pdf/{type} segment when this page prints one. */
    pdfType: String? = null,
    /** report_type for schedules (sales, customers, inventory, financial, production, procurement). */
    scheduleType: String? = null,
    /** Hide the date controls on "as of now" pages. */
    dated: Boolean = true,
    content: @Composable ColumnScope.(ReportWindow) -> Unit,
) {
    val env = LocalEnv.current
    if (!env.session.current.can(page.permission)) {
        EmptyState(
            title = "This report isn't for your role",
            message = "${page.title} is visible only to the roles FinancialVisibility allows. Ask a manager if you think you should see it.",
            icon = Icons.Default.Lock,
            actionLabel = "Go home",
        ) { env.navigator.go(env.session.current.home) }
        return
    }
    var scheduling by remember { mutableStateOf(false) }
    var version by remember { mutableIntStateOf(0) }
    val window = ReportFilters.window
    LaunchedEffect(Unit) { ReportFilters.loadOutletList(env.client) }

    CompositionLocalProvider(LocalReportVersion provides version) {
        Page(page.title, subtitle = subtitle ?: "${window.description} · ${ReportFilters.outletName(window.outletId)} · Africa/Nairobi", refresh = { version++ }) {
            ReportToolbar(page = page, dated = dated, csv = csv?.invoke(window), pdfType = pdfType, canSchedule = scheduleType != null) { scheduling = true }
            content(window)
            Text(
                "Figures are computed by the server (MetricEngine / reports); money is shown in the currency each payload names, converted to KES only where the server states a reporting rate. Nothing is summed on this device.",
                style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary,
            )
        }
    }
    ReportFilters.drill?.let { d -> DrillPanel(request = d, window = window) { ReportFilters.drill = null } }
    if (scheduling && scheduleType != null) SchedulesSheet(reportType = scheduleType, page = page, filters = window.dateParams()) { scheduling = false }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ReportToolbar(page: Route.ReportPage, dated: Boolean, csv: CSVExport?, pdfType: String?, canSchedule: Boolean, schedule: () -> Unit) {
    val env = LocalEnv.current
    val window = ReportFilters.window
    Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            if (dated) PeriodPicker(window.preset) { ReportFilters.window = window.copy(preset = it) }
            if (ReportFilters.outlets.size > 1 || window.outletId != null) {
                SelectField("Outlet", window.outletId, ReportFilters.outlets, { ReportFilters.window = window.copy(outletId = it) }, Modifier.width(220.dp), placeholder = "All outlets")
            }
        }
        if (dated && window.preset == DatePreset.CUSTOM) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                DateField("From", window.customStart, { it?.let { d -> ReportFilters.window = window.copy(customStart = d) } }, allowClear = false)
                DateField("To", window.customEnd, { it?.let { d -> ReportFilters.window = window.copy(customEnd = d) } }, allowClear = false)
            }
        }
        if (env.session.current.canExportReport(page)) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                if (csv != null) DocumentButton("Export CSV", path = csv.path, query = csv.query + ("export" to "csv"), suggestedName = csv.name)
                if (pdfType != null) {
                    val q = window.dateParams()
                    val name = "$pdfType-report-${window.startString}.pdf"
                    DocumentButton("Download PDF", path = "/v1/admin/reports/pdf/$pdfType", query = q, suggestedName = name)
                    OpenPdfButton(path = "/v1/admin/reports/pdf/$pdfType", query = q, suggestedName = name)
                }
                if (canSchedule) OutlinedButton(onClick = schedule) { Icon(Icons.Default.CalendarMonth, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Schedule") }
            }
        }
    }
}

@Composable
private fun PeriodPicker(preset: DatePreset, onChange: (DatePreset) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        OutlinedButton(onClick = { open = true }) { Text(preset.label) }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DatePreset.entries.forEach { p -> DropdownMenuItem(text = { Text(p.label) }, onClick = { open = false; onChange(p) }) }
        }
    }
}

/**
 * Android has no direct print of a remote PDF: the report is fetched through
 * the API client (so the download gate is honoured), saved and opened in the
 * system viewer, which offers print and share. Stands in for macOS "Print".
 */
@Composable
private fun OpenPdfButton(path: String, query: Map<String, String?>, suggestedName: String) {
    val env = LocalEnv.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    OutlinedButton(enabled = !busy, onClick = {
        busy = true
        scope.launch {
            try {
                val r = env.client.download(path, query.entries.mapNotNull { (k, v) -> v?.let { k to it } })
                val file = saveDocument(context, r, suggestedName)
                openFile(context, file, r.contentType ?: "application/pdf")
            } catch (e: ApiError) {
                if (e.status != 403) env.toasts.error(e) // 403 download_approval_required is shown by SecurityPrompts
            } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
        }
    }) {
        if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else { Icon(Icons.Default.OpenInNew, contentDescription = null, modifier = Modifier.size(16.dp)); Text(" Open PDF") }
    }
}

// ── Metric tiles ─────────────────────────────────────────────────────────────

/** A MetricEngine figure `{current, previous, series}`; `drill` opens the records behind it. Money is KES at the reporting rate (DRILL_DEFINITIONS). */
@Composable
fun MetricTile(label: String, metric: JsonElement?, money: Boolean = true, drill: String? = null, hint: String? = null, modifier: Modifier = ReportTile) {
    val cur = metric["current"]
    val prev = metric["previous"]
    val value = if (money) fmtMoney(cur, "KES") else (cur.str ?: "—")
    val prevText = prev?.takeUnless { it.isNull }?.let { if (money) fmtMoney(it, "KES") else (it.str ?: "—") }
    val tile: @Composable () -> Unit = { StatTile(label, value = value, hint = hint ?: prevText?.let { "Previous period: $it" }, modifier = modifier) }
    if (drill != null) DrillLink(metric = drill, title = label) { tile() } else tile()
}

/** Wraps a tile so a tap opens the records behind it. */
@Composable
fun DrillLink(metric: String, title: String, bucket: String? = null, label: @Composable () -> Unit) {
    Box(Modifier.clickable { ReportFilters.drill = DrillRequest(metric, title, bucket) }) { label() }
}

/** The tile width report grids use so they reflow on a phone and a tablet. */
val ReportTile: Modifier = Modifier.width(170.dp)

// ── Drill-down ───────────────────────────────────────────────────────────────

/**
 * The records behind a figure: GET reports/drill/{metric} with the page's
 * own window. The backend states what the number is (`definition`) and
 * where each row may lead (`links`, already permission-filtered).
 */
@Composable
private fun DrillPanel(request: DrillRequest, window: ReportWindow, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var page by remember(request) { mutableIntStateOf(1) }
    val resource = remember(request) {
        Resource(scope) {
            val q = window.periodParams(mapOf("page" to page.toString(), "bucket" to request.bucket))
            env.client.get("/v1/admin/reports/drill/${request.metric}", q).json ?: JsonObject(emptyMap())
        }
    }
    LaunchedEffect(resource, page) { resource.reload() }
    val total = resource.value["total"].int
    InfoSheet("${request.title} · ${total?.let { "$it record${if (it == 1) "" else "s"}" } ?: "Loading…"}", onDismiss) {
        Text("${window.description} · ${ReportFilters.outletName(window.outletId)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        resource.value["definition"].str?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
        LoadableView(resource.state, retry = { resource.load() }, isEmpty = { it["rows"].records.isEmpty() }, empty = { EmptyState("No records for these filters", icon = Icons.Default.Search) }) { json ->
            Column { jrows(json["rows"]).forEach { r -> DrillRow(r, onDismiss); HorizontalDivider(color = MaterialTheme.hub.line) } }
        }
        val per = resource.value["per_page"].int ?: 0
        if (total != null && per > 0 && total > per) {
            val pages = (total + per - 1) / per
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                TextButton(enabled = page > 1, onClick = { page -= 1 }) { Text("Previous") }
                Text("$page / $pages", style = MaterialTheme.typography.labelSmall)
                TextButton(enabled = page < pages, onClick = { page += 1 }) { Text("Next") }
            }
        }
    }
}

@Composable
private fun DrillRow(r: JRow, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.Top) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(r.str("ref") ?: r.str("order_number") ?: "#${r.str("id") ?: ""}", style = MaterialTheme.typography.bodyMedium.copy(fontFamily = FontFamily.Monospace))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                DateText((r["at"] ?: r["date"]).date, DateStyle.DATE)
                (r.str("who") ?: r.str("customer"))?.takeIf { it.isNotEmpty() }?.let { Text("· $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                r.str("detail")?.takeIf { it.isNotEmpty() }?.let { Text("· ${it.humanized}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                jpairs(r["links"]).forEach { link ->
                    val route = link.value.str?.let { Route.fromConsolePath(it) }
                    if (route != null) TextButton(onClick = { onDismiss(); env.navigator.go(route) }) { Text(link.key.humanized + " →", style = MaterialTheme.typography.labelSmall) }
                }
            }
        }
        if (r["amount"] != null && !r["amount"].isNull) {
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(fmtMoney(r["amount"], "KES"), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                val cur = r.str("currency")
                if (cur != null && cur != "KES" && r["amount_original"] != null) Text(fmtMoney(r["amount_original"], cur), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
    }
}

// ── Schedules ────────────────────────────────────────────────────────────────

/** reports/schedules: list (filtered to this report_type), create, delete. */
@Composable
private fun SchedulesSheet(reportType: String, page: Route.ReportPage, filters: Map<String, String?>, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { Resource(scope) { jrows(env.client.get("/v1/admin/reports/schedules").json["schedules"]).filter { it.str("report_type") == reportType } } }
    LaunchedEffect(Unit) { list.load() }
    var name by remember { mutableStateOf("") }
    var frequency by remember { mutableStateOf("weekly") }
    var format by remember { mutableStateOf("csv") }
    var recipients by remember { mutableStateOf("") }

    FormSheet("Scheduled reports · ${page.title}", onDismiss, saveLabel = "Save schedule", canSave = name.isNotEmpty() && recipients.isNotEmpty(), save = {
        val emails = recipients.split(",").map { it.trim() }.filter { it.isNotEmpty() }
        val f = JsonObject(filters.mapNotNull { (k, v) -> v?.let { k to JsonPrimitive(it) } }.toMap())
        env.client.post("/v1/admin/reports/schedules", JsonObject(mapOf(
            "name" to JsonPrimitive(name), "report_type" to JsonPrimitive(reportType), "frequency" to JsonPrimitive(frequency),
            "recipients" to JsonArray(emails.map { JsonPrimitive(it) }), "format" to JsonPrimitive(format), "filters" to f, "is_active" to JsonPrimitive(true),
        )))
        env.toasts.show("Schedule saved")
    }) { error ->
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.isEmpty() }, empty = { Text("No schedules for this report", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textTertiary) }) { rows ->
            Column {
                rows.forEach { s ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(s.str("name") ?: "Schedule", style = MaterialTheme.typography.bodyMedium)
                            Text("${(s.str("frequency") ?: "").humanized} · ${(s.str("format") ?: "").uppercase()} · ${s["recipients"].records.mapNotNull { it.str }.joinToString(", ")}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        s.str("id")?.let { id ->
                            ConfirmButton("Delete schedule", "This report will no longer be mailed.", destructive = true, action = {
                                try { env.client.delete("/v1/admin/reports/schedules/$id"); list.reload() } catch (e: ApiError) { env.toasts.error(e) }
                            }) { Icon(Icons.Default.Delete, contentDescription = "Delete") }
                        }
                    }
                }
            }
        }
        HorizontalDivider(color = MaterialTheme.hub.line)
        Text("New schedule", style = MaterialTheme.typography.titleMedium)
        Field("Name", name, { name = it }, placeholder = "e.g. Weekly sales to finance", error = error?.field("name"))
        OptionPicker("Frequency", frequency, listOf("daily" to "Daily", "weekly" to "Weekly", "monthly" to "Monthly"), { frequency = it.ifEmpty { "weekly" } })
        OptionPicker("Format", format, listOf("csv" to "CSV", "pdf" to "PDF"), { format = it.ifEmpty { "csv" } })
        Field("Recipients (comma-separated emails)", recipients, { recipients = it }, placeholder = "finance@bethanyhouse.co.ke", error = error?.field("recipients") ?: error?.field("recipients.0"))
    }
}

// ── Report fetch helper ──────────────────────────────────────────────────────

/** A JSON resource keyed on the window: re-fetches when the query changes or the page is pulled to refresh. */
@Composable
fun ReportData(path: String, query: Map<String, String?>, content: @Composable ColumnScope.(JsonElement) -> Unit) {
    val version = LocalReportVersion.current
    RemoteJson(path, query, version) { json, _ ->
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.l)) { content(json) }
    }
}

/** Foreign-currency honesty notes the sales endpoints carry: converted rows (in the KES totals at the reporting rate) and excluded ones (no rate). */
@Composable
fun CurrencyNotes(json: JsonElement?) {
    val converted = jrows(json["converted_currencies"])
    val excluded = jrows(json["excluded_currencies"])
    if (converted.isEmpty() && excluded.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        converted.forEach { r ->
            Text("Included at the reporting rate: ${r.int("orders") ?: 0} orders · ${fmtMoney(r["total_native"], r.str("currency_code") ?: "?")} → ${fmtMoney(r["total_kes"], "KES")} (rate ${fmtNumber(r.dbl("rate"), 2)})", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
        }
        excluded.forEach { r ->
            Text("Not in the totals (no reporting rate): ${r.int("orders") ?: 0} orders · ${fmtMoney(r["total"], r.str("currency_code") ?: "?")}", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
        }
    }
}
