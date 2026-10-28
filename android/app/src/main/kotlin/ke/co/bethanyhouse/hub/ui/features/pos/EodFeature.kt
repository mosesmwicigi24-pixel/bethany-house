package ke.co.bethanyhouse.hub.ui.features.pos

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.NightsStay
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.toMutableStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Omit
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OptionPicker
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.formatDate
import ke.co.bethanyhouse.hub.ui.hub
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * EoD review (EodReportsPage.tsx → pos/reports/eod-admin, pos.eod_review)
 * and EoD delivery settings (EodReportSettingsPage.tsx → pos/reports/eod-settings).
 */
internal object EodFeature {
    fun register() {
        FeatureRegistry.register(Route.EodReports) { EodReportsScreen() }
        FeatureRegistry.register(Route.EodSettings) { EodSettingsScreen() }
    }
}

// ── Reports list ─────────────────────────────────────────────────────────────

@Composable
private fun EodReportsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var from by remember { mutableStateOf(Day.add(Day.today(), -30)) }
    var to by remember { mutableStateOf(Day.today()) }
    var outletId by remember { mutableStateOf<Int?>(null) }
    var userId by remember { mutableStateOf<Int?>(null) }
    var page by remember { mutableStateOf(1) }
    var selectedId by remember { mutableStateOf<Int?>(null) }
    val result = remember { mutableStateOf<Resource<JsonElement>?>(null) }

    fun load() {
        val q = mapOf(
            "from" to from, "to" to to,
            "outlet_id" to outletId?.toString(), "user_id" to userId?.toString(),
            "page" to page.toString(), "per_page" to "20",
        )
        result.value = Resource(scope) { env.client.get("${PosApi.BASE}/reports/eod-admin", q).json ?: JsonNull }.also { it.load() }
    }
    LaunchedEffect(from, to, outletId, userId, page) { load() }

    Page("EoD Reports", subtitle = "Every cashier's end-of-day report, with the figures the Hub holds for that day", refresh = { result.value?.reload() }) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            DayField("From", from, { if (it.isNotEmpty()) { from = it; page = 1 } }, allowClear = false)
            DayField("To", to, { if (it.isNotEmpty() && it >= from) { to = it; page = 1 } }, allowClear = false)
        }
        val r = result.value?.value
        if (r != null) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m), verticalArrangement = Arrangement.spacedBy(Space.s)) {
            SelectField("Outlet", outletId, r["outlets"].records.mapNotNull { o -> o["id"].int?.let { it to (o["name"].str ?: "") } }, { outletId = it; page = 1 }, Modifier.widthIn(min = 160.dp, max = 260.dp), placeholder = "All outlets")
            SelectField("Cashier", userId, r["users"].records.mapNotNull { u -> u["id"].int?.let { it to (u["name"].str ?: "") } }, { userId = it; page = 1 }, Modifier.widthIn(min = 160.dp, max = 260.dp), placeholder = "All cashiers")
        }
        val res = result.value
        if (res != null) LoadableView(res.state, retry = { res.load() }, isEmpty = { it["data"].records.isEmpty() }, empty = { EmptyState("No reports in this range", icon = Icons.Default.NightsStay) }) { json ->
            Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
                json["data"].records.forEach { row -> EodReportRow(row) { selectedId = row["id"].int } }
                json["meta"]?.let { meta ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        Text("Showing ${meta["from"].str ?: "0"}–${meta["to"].str ?: "0"} of ${meta["total"].str ?: "0"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                        TextButton(onClick = { page = maxOf(1, page - 1) }, enabled = (meta["current_page"].int ?: 1) > 1) { Text("Previous") }
                        TextButton(onClick = { page += 1 }, enabled = (meta["current_page"].int ?: 1) < (meta["last_page"].int ?: 1)) { Text("Next") }
                    }
                }
            }
        }
    }
    selectedId?.let { id -> EodReportDetailSheet(id, onDismiss = { selectedId = null }) { result.value?.load() } }
}

@Composable
private fun EodReportRow(row: JsonElement, onClick: () -> Unit) {
    Surface(shape = MaterialTheme.shapes.small, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.small).clickable(onClick = onClick)) {
        Row(Modifier.padding(Space.m), verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(row["user_name"].str ?: "", style = MaterialTheme.typography.bodyMedium)
                    Text(row["outlet_name"].str ?: "", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
                    if (!row["acknowledged_at"].isNull) StatusBadge("acknowledged", StatusTone.SUCCESS) else StatusBadge("to read", StatusTone.AMBER)
                }
                Text(
                    "${row["report_date"].str ?: ""} · ${row["order_count"].int ?: 0} orders · ${row["note_count"].int ?: 0} notes${if (row["has_sentiments"].bool == true) " · has a write-up" else ""}",
                    style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary,
                )
                val by = row["sales_by_currency"].records
                if (by.size > 1 || (by.isNotEmpty() && by.first()["currency_code"].str != "KES")) {
                    Text(by.joinToString(" · ") { "${it["currency_code"].str ?: ""} ${Money.from(it["amount"], it["currency_code"].str ?: "KES")?.formatted() ?: ""}" }, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                }
            }
            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                MoneyText(Money.from(row["total_sales"], "KES"))
                Text("paid ${Money.from(row["total_paid"], "KES")?.formatted() ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                Money.from(row["total_balance"], "KES")?.takeIf { !it.isZero }?.let { Text("owed ${it.formatted()}", style = MaterialTheme.typography.labelSmall, color = Palette.warning) }
                Text(row["money_basis"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
    }
}

// ── Report detail (eod-admin/{id}, acknowledge, comments) ────────────────────

/**
 * docs/NATIVE_APPS_CHECKLIST.md F4: the detail's totals are raw sums across
 * currencies while the list uses MoneyByCurrency. They are shown as plain
 * figures with a caption, never converted.
 */
@Composable
private fun EodReportDetailSheet(id: Int, onDismiss: () -> Unit, onChanged: () -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val report = remember(id) { Resource(scope) { env.client.get("${PosApi.BASE}/reports/eod-admin/$id").json["report"] ?: JsonNull } }
    var comment by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(report) { report.load() }

    /** A server figure with no currency stated by the endpoint: shown as sent, never converted. */
    fun plainFigure(v: JsonElement?): String = v?.let { Money.from(it, "") }?.let { posPlainAmount(it) } ?: "—"

    suspend fun acknowledge() {
        busy = true
        try {
            val r = env.client.post("${PosApi.BASE}/reports/eod-admin/$id/acknowledge").json
            env.toasts.show(r["message"].str ?: "Report acknowledged")
            report.reload(); onChanged()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    suspend fun send() {
        busy = true
        try {
            env.client.post("${PosApi.BASE}/reports/eod/$id/comments", jsonOf("body" to comment.trim()))
            comment = ""
            report.reload(); onChanged()
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    PosSheet("End of Day report", onDismiss = onDismiss, actions = { TextButton(onClick = onDismiss) { Text("Close") } }) {
        LoadableView(report.state, retry = { report.load() }) { r ->
            val isAuthor = r["user_id"].int == env.session.current.me?.id
            val acknowledged = !r["acknowledged_at"].isNull
            Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("${r["user_name"].str ?: ""} · ${r["outlet_name"].str ?: ""}", style = MaterialTheme.typography.titleMedium)
                        Text("${r["report_date"].str ?: ""} · submitted ${r["submitted_at"].date?.let { formatDate(it) } ?: "—"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    }
                    if (acknowledged) StatusBadge("acknowledged", StatusTone.SUCCESS)
                    else if (!isAuthor) Button(onClick = { scope.launch { acknowledge() } }, enabled = !busy) { if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Acknowledge") }
                }
                TileGrid(minWidth = 140.dp) {
                    StatTile("Orders", r["order_count"].str ?: "0", modifier = TileModifier)
                    StatTile("Sales", plainFigure(r["total_sales"]), modifier = TileModifier)
                    StatTile("Paid", plainFigure(r["total_paid"]), modifier = TileModifier)
                    StatTile("Balance", plainFigure(r["total_balance"]), modifier = TileModifier)
                }
                CaptionText("Figures as the Hub returns them for this report, in the orders' own currency. The list shows the shilling figure at the reporting rate.")
                val orders = r["orders"].records
                if (orders.isNotEmpty()) SectionCard("Orders") {
                    orders.forEach { o ->
                        Column(Modifier.padding(vertical = 3.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                Text(o["order_number"].str ?: "", style = MaterialTheme.typography.bodyMedium)
                                Text(o["customer_name"].str ?: "", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                                StatusBadge(o["payment_status"].str)
                                Text(plainFigure(o["total_amount"]), style = MaterialTheme.typography.bodyMedium)
                            }
                            Text("paid ${plainFigure(o["amount_paid"])} · balance ${plainFigure(o["balance"])}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            o["eod_note"].str?.takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.labelSmall, fontStyle = FontStyle.Italic) }
                        }
                    }
                }
                r["sentiments"].str?.takeIf { it.isNotBlank() }?.let { s -> SectionCard("How the day went") { Text(plainText(s), style = MaterialTheme.typography.bodyMedium) } }
                SectionCard("Conversation") {
                    val comments = r["comments"].records
                    if (comments.isEmpty()) CaptionText("No comments yet.")
                    comments.forEach { c -> EodComment(c) }
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        OutlinedTextField(value = comment, onValueChange = { comment = it }, placeholder = { Text(if (isAuthor) "Reply" else "Ask the cashier a question") }, singleLine = true, modifier = Modifier.weight(1f))
                        OutlinedButton(onClick = { scope.launch { send() } }, enabled = comment.isNotBlank() && !busy) { Text("Send") }
                    }
                }
            }
        }
    }
}

/** The write-up is stored as light HTML (bold, italic, bullets); shown as text here. */
private fun plainText(html: String): String {
    var s = html.replace(Regex("<br\\s*/?>"), "\n")
    s = s.replace(Regex("</p>|</div>|</li>"), "\n")
    s = s.replace("<li>", "• ")
    s = s.replace(Regex("<[^>]+>"), "")
    return s.replace("&nbsp;", " ").replace("&amp;", "&").replace("&lt;", "<").replace("&gt;", ">").trim()
}

// ── Delivery settings (GET/POST pos/reports/eod-settings, POST …/test) ──────

private val FREQUENCIES = listOf("daily" to "Daily", "weekly" to "Weekly", "off" to "Off")

@Composable
private fun EodSettingsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var loaded by remember { mutableStateOf(false) }
    var emailEnabled by remember { mutableStateOf(false) }
    var emailRecipients by remember { mutableStateOf("") }
    var emailFrequency by remember { mutableStateOf("daily") }
    var emailTime by remember { mutableStateOf("21:00") }
    var slackEnabled by remember { mutableStateOf(false) }
    var slackWebhook by remember { mutableStateOf("") }
    var slackFrequency by remember { mutableStateOf("daily") }
    var slackTime by remember { mutableStateOf("21:00") }
    var whatsappEnabled by remember { mutableStateOf(false) }
    var whatsappRecipients by remember { mutableStateOf("") }
    val outletIds = remember { mutableListOf<Int>().toMutableStateList() }
    var outlets by remember { mutableStateOf<List<PosOutlet>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    val canEdit = env.session.current.can("settings.edit")

    suspend fun load() {
        runCatching { env.client.get("${PosApi.BASE}/reports/eod-settings").json["settings"] }.getOrNull()?.let { s ->
            emailEnabled = s["email_enabled"].bool ?: false; emailRecipients = s["email_recipients"].str ?: ""
            emailFrequency = s["email_frequency"].str ?: "daily"; emailTime = s["email_time"].str ?: "21:00"
            slackEnabled = s["slack_enabled"].bool ?: false; slackWebhook = s["slack_webhook"].str ?: ""
            slackFrequency = s["slack_frequency"].str ?: "daily"; slackTime = s["slack_time"].str ?: "21:00"
            whatsappEnabled = s["whatsapp_enabled"].bool ?: false; whatsappRecipients = s["whatsapp_recipients"].str ?: ""
            outletIds.clear(); outletIds += s["outlet_ids"].records.mapNotNull { it.int }
        }
        outlets = runCatching { PosApi(env.client).outlets() }.getOrDefault(emptyList())
        loaded = true
    }
    LaunchedEffect(Unit) { load() }

    fun payload() = jsonOf(
        "email_enabled" to emailEnabled, "email_recipients" to emailRecipients, "email_frequency" to emailFrequency, "email_time" to emailTime,
        "slack_enabled" to slackEnabled, "slack_webhook" to slackWebhook, "slack_frequency" to slackFrequency, "slack_time" to slackTime,
        "whatsapp_enabled" to whatsappEnabled, "whatsapp_recipients" to whatsappRecipients,
        "outlet_ids" to outletIds.sorted(),
    )

    suspend fun save() {
        busy = true
        try {
            val r = env.client.post("${PosApi.BASE}/reports/eod-settings", payload())
            error = null
            env.toasts.show(r.json["message"].str ?: "EoD delivery settings saved")
        } catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") } finally { busy = false }
    }

    /** Live form values are sent as overrides so a test works before saving (testEodDelivery). */
    suspend fun test(channel: String) {
        busy = true
        try {
            val r = env.client.post("${PosApi.BASE}/reports/eod-settings/test", jsonOf(
                "channel" to channel,
                "email_recipients" to (if (channel == "email") emailRecipients else Omit),
                "slack_webhook" to (if (channel == "slack") slackWebhook else Omit),
                "whatsapp_recipients" to (if (channel == "whatsapp") whatsappRecipients else Omit),
            ))
            env.toasts.show(r.json["message"].str ?: "Test queued")
        } catch (e: Exception) { env.toasts.error(e) } finally { busy = false }
    }

    @Composable
    fun FrequencyRow(frequency: String, onFrequency: (String) -> Unit, time: String, onTime: (String) -> Unit) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
            OptionPicker("Frequency", frequency, FREQUENCIES, onFrequency, Modifier.widthIn(max = 220.dp))
            OutlinedTextField(value = time, onValueChange = onTime, label = { Text("HH:MM") }, singleLine = true, modifier = Modifier.widthIn(max = 110.dp))
            Text("Nairobi time", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary, modifier = Modifier.padding(top = 20.dp))
        }
    }

    Page("EoD Settings", subtitle = "When and where the consolidated end-of-day report is sent", refresh = { load() }) {
        if (!loaded) LoadingSkeleton() else {
            SectionCard("Email") {
                ToggleRow("Send by email", emailEnabled, { emailEnabled = it })
                OutlinedTextField(value = emailRecipients, onValueChange = { emailRecipients = it }, label = { Text("Recipients, comma-separated") }, modifier = Modifier.fillMaxWidth(), maxLines = 3)
                FieldError(error?.field("email_recipients"))
                FrequencyRow(emailFrequency, { emailFrequency = it }, emailTime) { emailTime = it }
                OutlinedButton(onClick = { scope.launch { test("email") } }, enabled = !busy && canEdit) { Text("Send a test email") }
            }
            SectionCard("Slack") {
                ToggleRow("Send to Slack", slackEnabled, { slackEnabled = it })
                OutlinedTextField(value = slackWebhook, onValueChange = { slackWebhook = it }, label = { Text("Incoming webhook URL") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                FieldError(error?.field("slack_webhook"))
                FrequencyRow(slackFrequency, { slackFrequency = it }, slackTime) { slackTime = it }
                OutlinedButton(onClick = { scope.launch { test("slack") } }, enabled = !busy && canEdit) { Text("Send a test to Slack") }
            }
            SectionCard("WhatsApp") {
                ToggleRow("Send by WhatsApp", whatsappEnabled, { whatsappEnabled = it })
                OutlinedTextField(value = whatsappRecipients, onValueChange = { whatsappRecipients = it }, label = { Text("Numbers (E.164), comma-separated") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                FieldError(error?.field("whatsapp_recipients"))
                OutlinedButton(onClick = { scope.launch { test("whatsapp") } }, enabled = !busy && canEdit) { Text("Send a WhatsApp test") }
            }
            SectionCard("Outlets (none selected = all)") {
                outlets.forEach { o -> ToggleRow(o.name, outletIds.contains(o.id), { on -> if (on) { if (!outletIds.contains(o.id)) outletIds += o.id } else outletIds.remove(o.id) }) }
                if (outlets.isEmpty()) CaptionText("Outlets load from pos/outlets (pos.access).")
            }
            ErrorText(error)
            Button(onClick = { scope.launch { save() } }, enabled = !busy && canEdit, modifier = Modifier.widthIn(max = 320.dp).fillMaxWidth()) {
                if (busy) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Save settings")
            }
            if (!canEdit) CaptionText("Viewing only: saving needs settings.edit.")
            Spacer(Modifier.size(Space.l))
        }
    }
}
