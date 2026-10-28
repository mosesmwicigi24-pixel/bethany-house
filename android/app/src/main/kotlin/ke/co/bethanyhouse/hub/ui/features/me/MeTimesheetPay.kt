package ke.co.bethanyhouse.hub.ui.features.me

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ChevronLeft
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Description
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.HubDates
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.obj
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DecimalField
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import java.math.BigDecimal
import java.time.LocalDate
import java.time.temporal.ChronoUnit

// ── My hours (TimesheetPage.tsx): this period day by day, Submit, and "fix?" per day ──

@Composable
internal fun MyTimesheetScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val c = env.client
    var date by remember { mutableStateOf(HubDates.today()) }
    val sheet = remember { Resource(scope) { c.get("/v1/admin/me/timesheet", mapOf("date" to HubDates.dayString(date))).json ?: JsonNull } }
    val fixes = remember { Resource(scope) { c.get("/v1/admin/me/timesheet/fixes", background = true).json ?: JsonNull } }
    var note by remember { mutableStateOf("") }
    var fixDate by remember { mutableStateOf<String?>(null) }
    var fixMessage by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { fixes.load() }
    LaunchedEffect(date) { sheet.reload() }

    val period = sheet.value["period"]
    val periodSubtitle = period?.takeIf { !it.isNull }?.let { "${MeFormat.day(MeFormat.date(it["starts_on"].str))} – ${MeFormat.day(MeFormat.date(it["ends_on"].str))}" }

    /** A weekly period is 7 days long; anything longer steps by a month. */
    fun move(dir: Long) {
        val a = period["starts_on"].str?.let { HubDates.parse(it) }; val b = period["ends_on"].str?.let { HubDates.parse(it) }
        val weekly = a != null && b != null && ChronoUnit.DAYS.between(a, b) < 8
        date = if (weekly) date.plusDays(7 * dir) else date.plusMonths(dir)
    }
    suspend fun submit() {
        busy = true
        try {
            val r = c.post("/v1/admin/me/timesheet/submit", jsonOf("date" to HubDates.dayString(date), "note" to note.trim().ifEmpty { ke.co.bethanyhouse.hub.core.Omit }))
            env.toasts.show(r.json["message"].str ?: "Submitted")
            note = ""
            sheet.reload()
        } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun requestFix() {
        val fd = fixDate ?: return
        busy = true
        try {
            val r = c.post("/v1/admin/me/timesheet/fixes", jsonOf("date" to fd, "message" to fixMessage.trim()))
            env.toasts.show(r.json["message"].str ?: "Sent to your manager")
            fixDate = null; fixMessage = ""
            fixes.reload()
        } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }

    Page("My hours", subtitle = periodSubtitle, refresh = { sheet.reload(); fixes.reload() }) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
            IconButton(onClick = { move(-1) }) { Icon(Icons.Default.ChevronLeft, contentDescription = "Previous period") }
            IconButton(onClick = { move(1) }) { Icon(Icons.Default.ChevronRight, contentDescription = "Next period") }
        }
        LoadableView(sheet.state, retry = { sheet.load() }) { d ->
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                StatusBadge(sheetLabel(d["status"].str) + (d["approved_by"].str?.let { " by $it" } ?: ""), StatusTone.forStatus(d["status"].str))
                if (d["period"]["status"].str == "locked") StatusBadge("Period locked", StatusTone.SUCCESS)
            }
            if (d["status"].str == "sent_back") d["sent_back_note"].str?.let { MeNotice("Your manager sent this back: $it", StatusTone.WARNING) }
            val t = d["totals"]
            TileGrid(minWidth = 130.dp) {
                StatTile("Regular", MeFormat.hm(t["regular_minutes"].int), modifier = TileModifier)
                StatTile("Overtime", MeFormat.hm(t["overtime_minutes"].int), modifier = TileModifier)
                StatTile("Rest days", MeFormat.hm(t["rest_day_minutes"].int), modifier = TileModifier)
                StatTile("Holidays", MeFormat.hm(t["holiday_minutes"].int), modifier = TileModifier)
                StatTile("Paid leave", "${t["paid_leave_days"].str ?: "0"} d", modifier = TileModifier)
                StatTile("Unpaid leave", "${t["unpaid_leave_days"].str ?: "0"} d", modifier = TileModifier)
                StatTile("Days worked", t["days_worked"].str ?: "0", modifier = TileModifier)
            }
            TimesheetDays(d, fixes.value) { fixDate = it; fixMessage = "" }
            fixDate?.let { fd ->
                SectionCard {
                    Text("Something wrong on ${MeFormat.day(MeFormat.date(fd))}?", style = MaterialTheme.typography.titleMedium)
                    Text("Say what happened (forgot to clock out, phone was off, wrong outlet). Your manager corrects the punch and you are told.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    OutlinedTextField(value = fixMessage, onValueChange = { fixMessage = it }, placeholder = { Text("I forgot to clock out; I left at 17:10") }, modifier = Modifier.fillMaxWidth())
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                        OutlinedButton(onClick = { fixDate = null }, modifier = Modifier.weight(1f)) { Text("Cancel") }
                        Button(onClick = { scope.launch { requestFix() } }, enabled = !busy && fixMessage.trim().length >= 3, modifier = Modifier.weight(1f)) { Text("Send to my manager") }
                    }
                }
            }
            if (d["can_submit"].bool == true) SectionCard {
                Text("Checked your days? Submit them to your manager. A wrong punch is corrected by the manager in Attendance; say so in the note.", style = MaterialTheme.typography.bodySmall)
                OutlinedTextField(value = note, onValueChange = { note = it }, placeholder = { Text("Note to your manager (optional)") }, modifier = Modifier.fillMaxWidth())
                Button(onClick = { scope.launch { submit() } }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text("Submit my hours") }
            }
            TextButton(onClick = { env.navigator.go(Route.Clock) }, modifier = Modifier.fillMaxWidth()) { Text("Back to the clock", style = MaterialTheme.typography.labelSmall) }
        }
    }
}

private fun sheetLabel(s: String?): String = when (s) { "draft" -> "Not submitted"; "submitted" -> "Submitted"; "sent_back" -> "Sent back"; "approved" -> "Approved"; else -> s ?: "—" }

/** Totals and days (TimesheetDays.tsx). */
@Composable
private fun TimesheetDays(d: JsonElement, fixes: JsonElement?, askFix: (String) -> Unit) {
    val flags = d["flags"].records
    val absences = d["absences"].records
    val fixByDate: Map<String, String> = fixes["data"].records.mapNotNull { f -> val dt = f["date"].str; val st = f["status"].str; if (dt != null && st != null) dt to st else null }.let { pairs -> val m = LinkedHashMap<String, String>(); pairs.forEach { (k, v) -> m.putIfAbsent(k, v) }; m }
    val todayStr = HubDates.dayString(HubDates.today())
    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        if (flags.isNotEmpty()) MeNotice("${MeFormat.plural(flags.size, "punch", "punches")} need attention before approval: ${flags.joinToString("; ") { "${it["date"].str ?: ""} (${it["why"].str ?: ""})" }}", StatusTone.DANGER)
        if (absences.isNotEmpty()) MeNotice("Rostered but no punch: ${absences.joinToString(", ") { "${it["date"].str ?: ""} ${it["shift"].str ?: ""}" }}", StatusTone.WARNING)
        MeListCard {
            d["days"].records.forEach { day ->
                val cls = day["class"].str
                val worked = day["worked_minutes"].int ?: 0
                val idle = worked == 0 && day["leave"].isNull
                val dateStr = day["date"].str ?: ""
                val textColor = if (idle) MaterialTheme.hub.textTertiary else MaterialTheme.hub.text
                Column(Modifier.background(when (cls) { "rest" -> Palette.neutralSoft.copy(alpha = 0.4f); "holiday" -> Palette.amberSoft.copy(alpha = 0.4f); else -> Color.Transparent })) {
                    Row(Modifier.fillMaxWidth().padding(horizontal = Space.l, vertical = Space.m), horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                        Text(MeFormat.day(MeFormat.date(dateStr)), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium, color = textColor, modifier = Modifier.width(76.dp))
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            DayDescription(day, textColor)
                            Row(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalAlignment = Alignment.CenterVertically) {
                                day["highlight"].str?.let { StatusBadge(it, StatusTone.WARNING) }
                                val ex = extra(day)
                                if (ex.isNotEmpty()) Text(ex, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                            }
                        }
                        Text(if (worked > 0) MeFormat.hm(worked) else "", style = MaterialTheme.typography.bodySmall, color = textColor, textAlign = TextAlign.End, modifier = Modifier.width(56.dp))
                        if (dateStr <= todayStr) {
                            val f = fixByDate[dateStr]
                            if (f != null) Text(if (f == "requested") "fix asked" else f, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                            else Text("fix?", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary, modifier = Modifier.clickable { askFix(dateStr) })
                        }
                    }
                    HorizontalDivider(color = MaterialTheme.hub.line, modifier = Modifier.padding(start = Space.l))
                }
            }
        }
        d["group"].obj?.let { g -> Text(groupWords(g, d["snapshot"].bool ?: false), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
    }
}

@Composable
private fun DayDescription(day: JsonElement, textColor: Color) {
    val h = day["holiday"].str
    if (h != null) { Text(h, style = MaterialTheme.typography.bodySmall, color = Palette.amber, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis); return }
    val l = day["leave"].str
    if (l != null) { Text(l, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis); return }
    val punches = day["punches"].records
    if (punches.isNotEmpty()) {
        Text(punches.joinToString(", ") { p -> "${p["in"].str ?: ""}–${p["out"].str ?: "…"}${when (p["status"].str) { "flagged" -> " ⚠"; "active" -> " (open)"; else -> "" }}" }, style = MaterialTheme.typography.bodySmall, color = textColor, maxLines = 1, overflow = TextOverflow.Ellipsis)
        return
    }
    val rostered = day["rostered"].records.mapNotNull { it.str }
    if (rostered.isNotEmpty()) { Text("rostered ${rostered.joinToString(", ")}, no punch", style = MaterialTheme.typography.bodySmall, color = Palette.amber, maxLines = 1, overflow = TextOverflow.Ellipsis); return }
    Text(if (day["class"].str == "rest") "rest day" else "", style = MaterialTheme.typography.bodySmall, color = textColor)
}

private fun extra(day: JsonElement): String {
    val ot = day["overtime_minutes"].int ?: 0
    if (ot > 0) return "OT ${MeFormat.hm(ot)}"
    if ((day["rest_day_minutes"].int ?: 0) > 0) return "rest ×"
    if ((day["holiday_minutes"].int ?: 0) > 0) return "holiday ×"
    val b = day["unpaid_break_minutes"].int ?: 0
    if (b > 0) return "−${b}m lunch"
    return ""
}

private fun groupWords(g: JsonElement, snapshot: Boolean): String {
    var s = "${g["name"].str ?: ""}: "
    val daily = g["overtime_after_daily_hours"].str
    if (daily != null) s += "overtime after $daily h a day"
    g["overtime_after_weekly_hours"].str?.let { w -> s += "${if (daily != null) ", " else "overtime "}after $w h a week" }
    s += " at ×${g["overtime_multiplier"].str ?: ""}; rest days ×${g["rest_day_multiplier"].str ?: ""}; holidays ×${g["holiday_multiplier"].str ?: ""}"
    val r = g["round_to_minutes"].int ?: 0
    if (r > 1) s += "; punches rounded to $r min"
    s += ". ${if (snapshot) "Figures as approved." else "Live figures until approved."}"
    return s
}

// ── My payslips (PayslipsPage.tsx): every approved run I was on; the PDF asks for a step-up ──

@Composable
internal fun MyPayslipsScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val lines = remember { Resource(scope) { env.client.get("/v1/admin/me/payslips").json.listRecords } }
    var open by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { lines.load() }

    Page("My payslips", subtitle = "Bethany House", refresh = { lines.reload() }) {
        LoadableView(lines.state, retry = { lines.load() }, isEmpty = { it.isEmpty() }, empty = { EmptyState("No payslips yet", "One appears here when a pay run you were on is approved.", Icons.Default.Description) }) { rows ->
            rows.forEach { l ->
                val run = l["run"]
                val paid = !l["paid_at"].isNull
                SectionCard {
                    Row(Modifier.fillMaxWidth().clickable { open = if (open == l.id) null else l.id }, verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text("${MeFormat.dayYear(MeFormat.date(run["starts_on"].str))} – ${MeFormat.dayYear(MeFormat.date(run["ends_on"].str))}", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            Text("${run["reference"].str ?: ""}${if (paid) " · paid ${MeFormat.day(l.date("paid_at"))} by ${l.string("payment_method") ?: ""}" else " · not yet paid"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            MoneyText(l.money("net", "KES"), emphasis = true)
                            StatusBadge(if (paid) "paid" else "approved")
                        }
                    }
                    if (open == l.id) {
                        HorizontalDivider(color = MaterialTheme.hub.line)
                        PayslipItems(l)
                        DocumentButton("Download PDF", "/v1/admin/me/payslips/${l.id}/pdf", suggestedName = "payslip-${run["reference"].str ?: l.id}.pdf", modifier = Modifier.fillMaxWidth())
                    }
                }
            }
        }
    }
}

/** One payslip's lines: earnings, deductions, net — the same list the PDF prints (PayslipItems.tsx). Every figure is the server's, in KES. */
@Composable
private fun PayslipItems(line: Record) {
    val items = line.records("items")
    val earnings = items.filter { it.string("kind") == "earning" }
    val deductions = items.filter { it.string("kind") == "deduction" }
    val hours = line["hours"]
    Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
        earnings.forEach { i -> PayRow(i.string("label") ?: "", i.money("amount", "KES")) }
        HorizontalDivider(color = MaterialTheme.hub.line)
        PayRow("Gross pay", line.money("gross", "KES"), strong = true)
        deductions.forEach { i -> PayRow(i.string("label") ?: "", i.money("amount", "KES")) }
        Box(Modifier.fillMaxWidth().height(2.dp).background(MaterialTheme.hub.text))
        PayRow("Net pay", line.money("net", "KES"), strong = true)
        Text(
            "Hours: regular ${MeFormat.hm(hours["regular_minutes"].int)}, overtime ${MeFormat.hm(hours["overtime_minutes"].int)}, rest days ${MeFormat.hm(hours["rest_day_minutes"].int)}, holidays ${MeFormat.hm(hours["holiday_minutes"].int)} · paid leave ${hours["paid_leave_days"].str ?: "0"} d, unpaid ${hours["unpaid_leave_days"].str ?: "0"} d, days worked ${hours["days_worked"].str ?: "0"}. Taxable pay ${line.money("taxable", "KES")?.formatted() ?: "—"}. Employer NSSF ${line.money("nssf_employer", "KES")?.formatted() ?: "—"} and Housing Levy ${line.money("housing_employer", "KES")?.formatted() ?: "—"} are not deducted from you.",
            style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary,
        )
    }
}

@Composable
private fun PayRow(label: String, amount: Money?, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, style = MaterialTheme.typography.bodySmall, fontWeight = if (strong) FontWeight.SemiBold else FontWeight.Normal, color = if (strong) MaterialTheme.hub.text else MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
        if (amount != null && amount.isNegative) Text("(${Money(amount.amount.negate(), amount.currency).formatted()})", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary)
        else MoneyText(amount, emphasis = strong)
    }
}

// ── Advances & loans (AdvancesPage.tsx): ask, see the decision, watch the balance come down ──

private val advanceLabel = mapOf("requested" to "Waiting", "approved" to "Approved", "paid" to "Paid out", "settled" to "Settled", "declined" to "Declined", "cancelled" to "Cancelled")

@Composable
internal fun MyAdvancesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val data = remember { Resource(scope) { env.client.get("/v1/admin/me/advances").json ?: JsonNull } }
    var asking by remember { mutableStateOf(false) }
    var kind by remember { mutableStateOf("advance") }
    var amount by remember { mutableStateOf("") }
    var instalment by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { data.load() }

    suspend fun submit() {
        val amt = amount.trim().toBigDecimalOrNull() ?: return
        busy = true
        try {
            val inst = if (kind == "loan") instalment.trim().toBigDecimalOrNull() else null
            val r = env.client.post("/v1/admin/me/advances", jsonOf(
                "kind" to kind, "amount" to amt.toPlainString(), "reason" to reason.trim(),
                "instalment" to (inst?.toPlainString() ?: ke.co.bethanyhouse.hub.core.Omit),
            ))
            env.toasts.show(r.json["message"].str ?: "Request sent")
            asking = false; amount = ""; instalment = ""; reason = ""; kind = "advance"
            data.reload()
        } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun cancel(a: Record) {
        try { val r = env.client.delete("/v1/admin/me/advances/${a.id}"); env.toasts.show(r.json["message"].str ?: "Withdrawn"); data.reload() } catch (e: ApiError) { env.toasts.error(e) }
    }

    Page("Advances & loans", subtitle = "Bethany House", refresh = { data.reload() }) {
        LoadableView(data.state, retry = { data.load() }) { d ->
            val rows = d.listRecords
            val waiting = rows.any { it.status == "requested" }
            if (!asking && !waiting) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { Button(onClick = { asking = true }) { Text("Ask for one") } }
            val bal = Money.from(d["open_balance"], "KES")
            if (bal != null && !bal.isZero) SectionCard {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) { Text("Still to repay:", style = MaterialTheme.typography.bodySmall); MoneyText(bal, emphasis = true) }
                Text("It comes off your pay each run until it is cleared.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            if (asking) SectionCard {
                SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                    listOf("advance" to "Advance", "loan" to "Loan").forEachIndexed { i, (v, label) ->
                        SegmentedButton(selected = kind == v, onClick = { kind = v }, shape = SegmentedButtonDefaults.itemShape(i, 2)) { Text(label) }
                    }
                }
                Text(if (kind == "advance") "An advance on this period's pay. The whole amount comes off your next payslip." else "A loan repaid in instalments, one per pay run, until cleared.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                DecimalField("Amount (KES)", amount, { amount = it })
                if (kind == "loan") DecimalField("Repay per pay run (KES)", instalment, { instalment = it })
                Field("Why", reason, { reason = it }, placeholder = "School fees, rent, medical…")
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    OutlinedButton(onClick = { asking = false }, modifier = Modifier.weight(1f)) { Text("Cancel") }
                    Button(onClick = { scope.launch { submit() } }, enabled = !busy && amount.trim().toBigDecimalOrNull() != null && reason.isNotBlank(), modifier = Modifier.weight(1f)) { Text("Send request") }
                }
            }
            if (rows.isEmpty() && !asking) SectionCard { Text("Nothing yet. Ask for an advance on this period's pay or a loan repaid over several runs.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary) }
            rows.forEach { a -> AdvanceCard(a) { cancel(a) } }
            TextButton(onClick = { env.navigator.go(Route.MyPayslips) }, modifier = Modifier.fillMaxWidth()) { Text("My payslips", style = MaterialTheme.typography.labelSmall) }
        }
    }
}

@Composable
private fun AdvanceCard(a: Record, cancel: suspend () -> Unit) {
    SectionCard {
        Row(verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(if (a.string("kind") == "loan") "Loan" else "Advance", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                    Text("·")
                    MoneyText(a.money("amount", "KES"))
                }
                Text("${a.string("reason") ?: ""}${a.date("requested_at")?.let { " · asked ${MeFormat.day(it)}" } ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
            }
            StatusBadge(advanceLabel[a.status ?: ""] ?: a.status, StatusTone.forStatus(a.status))
        }
        a.string("decision_note")?.let { Text("Payroll: $it", style = MaterialTheme.typography.labelSmall) }
        if (a.status == "paid") FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text("Paid out ${MeFormat.day(a.date("paid_at"))} by ${a.string("payment_method") ?: ""}. Balance", style = MaterialTheme.typography.labelSmall)
            MoneyText(a.money("balance", "KES"))
            if (a.string("kind") == "loan") { Text("·", style = MaterialTheme.typography.labelSmall); MoneyText(a.money("instalment", "KES")); Text("per run", style = MaterialTheme.typography.labelSmall) }
        }
        val repayments = a.records("repayments")
        if (repayments.isNotEmpty()) Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
            repayments.forEach { r ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("${if (r.string("kind") == "payroll") "Off payslip ${r.string("reference") ?: ""}" else "Repaid in cash${r.string("reference")?.let { " · $it" } ?: ""}"} · ${r.string("on") ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.weight(1f))
                    MoneyText(r.money("amount", "KES"))
                }
            }
        }
        if (a.status == "requested") ConfirmButton("Withdraw request", "Payroll will not see this request any more.", destructive = true, action = cancel) { Text("Withdraw request", style = MaterialTheme.typography.labelSmall) }
    }
}
