package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.dbl
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.DocumentButton
import ke.co.bethanyhouse.hub.ui.components.ErrorState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatTile
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.TileGrid
import ke.co.bethanyhouse.hub.ui.components.TileModifier
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.message
import ke.co.bethanyhouse.hub.ui.components.minutesText
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement

/**
 * Timesheets, team side (People PR 5): one pay group and period at a time,
 * each person's sheet to approve or send back, the period lock (step-up to
 * unlock), the CSV export and the pay groups.
 */
@Composable
fun TimesheetsScreen() {
    var tab by remember { mutableStateOf("sheets") }
    var groupId by remember { mutableStateOf<String?>(null) }
    var date by remember { mutableStateOf(Day.today()) }
    var version by remember { mutableIntStateOf(0) }
    var open by remember { mutableStateOf<Int?>(null) }
    var unlocking by remember { mutableStateOf(false) }
    Page("Timesheets", subtitle = "Hours per person, per pay period") {
        SegmentedTabs(listOf("sheets" to "Timesheets", "groups" to "Pay groups"), tab) { tab = it }
        if (tab == "groups") PayGroupsPanel()
        else RemoteJson("/v1/admin/timesheets", query = mapOf("pay_group_id" to groupId, "date" to date), version = version) { j, reload ->
            val groups = j["groups"].toRecords()
            val period = j["period"]
            PeopleChoice("Pay group", groupId ?: j["group"]["id"].str ?: "", groups.map { it.id to "${it.string("name") ?: ""} (${it.string("frequency") ?: ""})" }, { groupId = it })
            DayField("Period containing", date, { date = it })
            SectionCard {
                Text("${Day.format(period["starts_on"].str, "d MMM")} – ${Day.format(period["ends_on"].str, "d MMM yyyy")}", style = MaterialTheme.typography.titleMedium)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    StatusBadge(period["status"].str)
                    period["unlock_reason"].str?.let { Text("unlocked: $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                }
                val pid = period["id"].int
                if (j["can_lock"].bool == true && pid != null) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s)) {
                    if (period["status"].str == "locked") OutlinedButton(onClick = { unlocking = true }) { Text("Unlock…") }
                    else LockPeriodButton(pid, reload)
                    DocumentButton("Export CSV", "/v1/admin/timesheets/export", mapOf("pay_period_id" to pid.toString()), "timesheets-${period["starts_on"].str ?: ""}.csv")
                }
            }
            val s = j["summary"]
            TileGrid {
                StatTile("People", s["people"].str ?: "0", modifier = TileModifier)
                StatTile("Approved", s["approved"].str ?: "0", tone = StatusTone.SUCCESS, modifier = TileModifier)
                StatTile("Submitted", s["submitted"].str ?: "0", tone = StatusTone.INFO, modifier = TileModifier)
                StatTile("Flagged", s["flagged"].str ?: "0", tone = if ((s["flagged"].int ?: 0) > 0) StatusTone.WARNING else null, modifier = TileModifier)
            }
            j["rows"].toRecords().forEach { r ->
                val t = r["totals"]
                SupportListRow(r["user"]["name"].str ?: "—", "${r["user"]["job_title"].str ?: ""} · worked ${minutesText(t["worked_minutes"].int)} · OT ${minutesText(t["overtime_minutes"].int)} · ${t["days_worked"].int ?: 0} days", onClick = { open = r["user"]["id"].int ?: 0 }) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        if ((r.int("flags") ?: 0) > 0) Pill("${r.int("flags")} flags", StatusTone.WARNING)
                        if ((r.int("absences") ?: 0) > 0) Pill("${r.int("absences")} absent", StatusTone.DANGER)
                        StatusBadge(r.status, sheetTone(r.status))
                    }
                }
            }
        }
    }
    open?.let { uid -> TimesheetDetailSheet(uid, date, onDismiss = { open = null }) { version += 1 } }
    if (unlocking) UnlockPeriodSheet(date, onDismiss = { unlocking = false }) { version += 1 }
}

private fun sheetTone(s: String?) = when (s) { "approved" -> StatusTone.SUCCESS; "submitted" -> StatusTone.INFO; "sent_back" -> StatusTone.WARNING; else -> StatusTone.NEUTRAL }

/** POST /pay-periods/{id}/lock {force}: refused with a message when sheets are not all approved unless forced. */
@Composable
private fun LockPeriodButton(periodId: Int, reload: () -> Unit) {
    val env = LocalEnv.current
    var askForce by remember { mutableStateOf<String?>(null) }
    suspend fun lock(force: Boolean) {
        try { val r = env.client.post("/v1/admin/pay-periods/$periodId/lock", J.obj("force" to J.b(force))); toastResult(env, r); reload() }
        catch (e: ApiError) { if (e.status == 422 && !force) askForce = e.message else env.toasts.error(e) }
        catch (e: Exception) { env.toasts.error(e) }
    }
    ConfirmButton("Lock period", "Locked hours are what payroll pays. Nobody can submit or change a sheet afterwards.", action = { lock(false) }) { Text("Lock period") }
    askForce?.let { msg ->
        val scope = androidx.compose.runtime.rememberCoroutineScope()
        AlertDialog(
            onDismissRequest = { askForce = null },
            title = { Text("Lock anyway?") }, text = { Text(msg) },
            confirmButton = { TextButton(onClick = { askForce = null; scope.launch { lock(true) } }) { Text("Lock anyway", color = Palette.danger) } },
            dismissButton = { TextButton(onClick = { askForce = null }) { Text("Cancel") } },
        )
    }
}

/** POST /pay-periods/{id}/unlock {reason} — step-up. */
@Composable
private fun UnlockPeriodSheet(date: String, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var reason by remember { mutableStateOf("") }
    var periodId by remember { mutableStateOf<Int?>(null) }
    LaunchedEffect(Unit) { periodId = runCatching { env.client.get("/v1/admin/timesheets", mapOf("date" to date)).json["period"]["id"].int }.getOrNull() }
    FormSheet("Unlock period", onDismiss, saveLabel = "Unlock", canSave = reason.isNotEmpty() && periodId != null, destructive = true, save = {
        val r = env.client.post("/v1/admin/pay-periods/${periodId ?: 0}/unlock", J.obj("reason" to J.s(reason)))
        toastResult(env, r); done()
    }) { e ->
        Text("Unlocking asks you to confirm it is you, and the reason is kept on the period.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
        Field("Reason", reason, { reason = it }, error = e?.field("reason"), multiline = true)
    }
}

/** GET /timesheets/person/{user}?date → the sheet; approve / send back. */
@Composable
private fun TimesheetDetailSheet(userId: Int, date: String, onDismiss: () -> Unit, changed: () -> Unit) {
    val env = LocalEnv.current
    var sheet by remember { mutableStateOf<JsonElement?>(null) }
    var error by remember { mutableStateOf<ApiError?>(null) }
    var version by remember { mutableIntStateOf(0) }
    var sendBackNote by remember { mutableStateOf("") }
    var showSendBack by remember { mutableStateOf(false) }
    LaunchedEffect(version) {
        try { sheet = env.client.get("/v1/admin/timesheets/person/$userId", mapOf("date" to date)).json; error = null }
        catch (e: ApiError) { error = e } catch (e: Exception) { error = ApiError.transport(e.message ?: "Error") }
    }
    InfoSheet(sheet["person"]["name"].str ?: "Timesheet", onDismiss) {
        val s = sheet
        when {
            s != null -> {
                TimesheetBody(s)
                val canManage = s["can_manage"].bool ?: env.session.current.can("attendance.manage")
                if (canManage && s["period"]["status"].str != "locked") {
                    val approved = s["status"].str == "approved"
                    if (showSendBack) {
                        Field("Why it is going back", sendBackNote, { sendBackNote = it })
                        AsyncButton("Send back", destructive = true, enabled = sendBackNote.isNotEmpty()) {
                            val r = env.client.post("/v1/admin/timesheets/person/$userId/send-back", J.obj("date" to J.s(date), "note" to J.s(sendBackNote)))
                            env.toasts.show(r.json.message ?: "Sent back"); changed(); showSendBack = false; version += 1
                        }
                    } else OutlinedButton(onClick = { showSendBack = true }, enabled = !approved) { Text("Send back…") }
                    AsyncButton("Approve", enabled = !approved, modifier = Modifier.fillMaxWidth()) {
                        val r = env.client.post("/v1/admin/timesheets/person/$userId/approve", J.obj("date" to J.s(date)))
                        env.toasts.show(r.json.message ?: "Approved"); changed(); version += 1
                    }
                }
            }
            error != null -> ErrorState(error!!, retry = { version += 1 })
            else -> LoadingSkeleton()
        }
    }
}

/** The sheet's totals, days and flags — reused by the employee file. */
@Composable
internal fun TimesheetBody(sheet: JsonElement?) {
    val t = sheet["totals"]
    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            StatusBadge(sheet["status"].str)
            if (sheet["snapshot"].bool == true) Pill("snapshot", StatusTone.INFO)
            androidx.compose.foundation.layout.Spacer(Modifier.weight(1f))
            sheet["approved_by"].str?.let { Text("Approved by $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
        }
        sheet["sent_back_note"].str?.takeIf { it.isNotEmpty() }?.let { Text("Sent back: $it", style = MaterialTheme.typography.bodySmall, color = Palette.warning) }
        TileGrid {
            StatTile("Worked", minutesText(t["worked_minutes"].int), modifier = TileModifier)
            StatTile("Regular", minutesText(t["regular_minutes"].int), modifier = TileModifier)
            StatTile("Overtime", minutesText(t["overtime_minutes"].int), modifier = TileModifier)
            StatTile("Rest day", minutesText(t["rest_day_minutes"].int), modifier = TileModifier)
            StatTile("Holiday", minutesText(t["holiday_minutes"].int), modifier = TileModifier)
            StatTile("Days", "${t["days_worked"].int ?: 0}", hint = "${t["paid_leave_days"].str ?: "0"} paid · ${t["unpaid_leave_days"].str ?: "0"} unpaid leave", modifier = TileModifier)
        }
        sheet["days"].toRecords().forEach { d ->
            Row(verticalAlignment = Alignment.Top) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(Day.format(d.string("date")), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    Text(d.string("holiday") ?: d.string("leave") ?: d.string("class")?.humanized ?: "", style = MaterialTheme.typography.labelSmall, color = if (d.string("class") == "holiday") Palette.pink else MaterialTheme.hub.textSecondary)
                    d["punches"].toRecords().forEach { p ->
                        Text("${Day.time(p.string("in"))} – ${p.string("out")?.let { Day.time(it) } ?: "open"} · ${p.string("outlet") ?: ""}" + (if (p["flags"].records.isEmpty()) "" else " ⚑"), style = MaterialTheme.typography.labelSmall)
                    }
                }
                Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(minutesText(d.int("worked_minutes")), style = MaterialTheme.typography.bodyMedium)
                    if ((d.int("overtime_minutes") ?: 0) > 0) Text("OT ${minutesText(d.int("overtime_minutes"))}", style = MaterialTheme.typography.labelSmall, color = Palette.amber)
                    d.string("highlight")?.let { Pill(if (it == "over") "over schedule" else "under schedule", StatusTone.WARNING) }
                }
            }
            HorizontalDivider(color = MaterialTheme.hub.line)
        }
        val flags = sheet["flags"].toRecords()
        if (flags.isNotEmpty()) SectionCard("Flags") { flags.forEach { f -> Text("${Day.format(f.string("date"))}: ${f.string("why") ?: ""}", style = MaterialTheme.typography.bodySmall, color = Palette.warning) } }
        val absences = sheet["absences"].toRecords()
        if (absences.isNotEmpty()) SectionCard("Absences") { absences.forEach { a -> Text("${Day.format(a.string("date"))}: ${a.string("shift") ?: ""}", style = MaterialTheme.typography.bodySmall, color = Palette.danger) } }
    }
}

// ── Pay groups (GET/POST/PUT /pay-groups) ────────────────────────────────────

@Composable
private fun PayGroupsPanel() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<PayGroupDraft?>(null) }
    val canEdit = env.session.current.can("hr.edit")
    if (canEdit) Button(onClick = { editing = PayGroupDraft() }) { Text("New pay group") }
    RemoteJson("/v1/admin/pay-groups", version = version) { j, _ ->
        j["data"].toRecords().forEach { g ->
            val sub = "${g.string("frequency") ?: ""} · ${g["standard_hours_per_day"].str ?: ""}h/day · OT ×${g["overtime_multiplier"].str ?: ""} after ${g["overtime_after_daily_hours"].str ?: "—"}h/day, ${g["overtime_after_weekly_hours"].str ?: "—"}h/week · rest ×${g["rest_day_multiplier"].str ?: ""} · holiday ×${g["holiday_multiplier"].str ?: ""} · round ${g["round_to_minutes"].str ?: ""}m" + (g["period_end_day"].int?.let { " · period ends day $it" } ?: "")
            SupportListRow(g.string("name") ?: "", sub) {
                Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (g["is_default"].bool == true) Pill("default", StatusTone.INFO)
                    if (g["is_active"].bool == false) Pill("inactive", StatusTone.NEUTRAL)
                    if (canEdit) TextButton(onClick = { editing = PayGroupDraft.from(g) }) { Text("Edit") }
                }
            }
        }
    }
    editing?.let { d -> PayGroupSheet(d, onDismiss = { editing = null }) { version += 1 } }
}

private data class PayGroupDraft(
    val id: Int? = null, val name: String = "", val frequency: String = "monthly", val standardHours: String = "8",
    val otDaily: String = "", val otWeekly: String = "", val otMultiplier: String = "1.5", val restMultiplier: String = "2", val holidayMultiplier: String = "2",
    val roundTo: String = "15", val periodEndDay: String = "", val payDateDay: String = "", val unpaidBreak: String = "0", val unpaidBreakAfter: String = "",
    val isDefault: Boolean = false, val isActive: Boolean = true,
) {
    companion object {
        fun from(g: Record) = PayGroupDraft(
            id = g.int("id"), name = g.string("name") ?: "", frequency = g.string("frequency") ?: "monthly",
            standardHours = g["standard_hours_per_day"].str ?: "8", otDaily = g["overtime_after_daily_hours"].str ?: "", otWeekly = g["overtime_after_weekly_hours"].str ?: "",
            otMultiplier = g["overtime_multiplier"].str ?: "1.5", restMultiplier = g["rest_day_multiplier"].str ?: "2", holidayMultiplier = g["holiday_multiplier"].str ?: "2",
            roundTo = g["round_to_minutes"].str ?: "15", periodEndDay = g["period_end_day"].str ?: "", payDateDay = g["pay_date_day"].str ?: "",
            unpaidBreak = g["unpaid_break_minutes"].str ?: "0", unpaidBreakAfter = g["unpaid_break_after_hours"].str ?: "",
            isDefault = g["is_default"].bool ?: false, isActive = g["is_active"].bool ?: true,
        )
    }
}

@Composable
private fun PayGroupSheet(initial: PayGroupDraft, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var d by remember { mutableStateOf(initial) }
    FormSheet(if (d.id == null) "New pay group" else "Edit pay group", onDismiss, canSave = d.name.isNotEmpty(), save = {
        val body = J.obj(
            "name" to J.s(d.name), "frequency" to J.s(d.frequency),
            "standard_hours_per_day" to J.typed(d.standardHours), "overtime_after_daily_hours" to J.typed(d.otDaily), "overtime_after_weekly_hours" to J.typed(d.otWeekly),
            "overtime_multiplier" to J.typed(d.otMultiplier), "rest_day_multiplier" to J.typed(d.restMultiplier), "holiday_multiplier" to J.typed(d.holidayMultiplier),
            "round_to_minutes" to J.typed(d.roundTo), "period_end_day" to J.typed(d.periodEndDay), "pay_date_day" to J.typed(d.payDateDay),
            "unpaid_break_minutes" to J.typed(d.unpaidBreak), "unpaid_break_after_hours" to J.typed(d.unpaidBreakAfter),
            "is_default" to J.b(d.isDefault), "is_active" to J.b(d.isActive),
        )
        val id = d.id
        val r = if (id != null) env.client.put("/v1/admin/pay-groups/$id", body) else env.client.post("/v1/admin/pay-groups", body)
        toastResult(env, r); saved()
    }) { e ->
        Field("Name", d.name, { d = d.copy(name = it) }, error = e?.field("name"))
        PeopleChoice("Frequency", d.frequency, listOf("monthly" to "Monthly", "weekly" to "Weekly"), { d = d.copy(frequency = it) })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { Field("Standard hours/day", d.standardHours, { d = d.copy(standardHours = it) }, Modifier.weight(1f)); Field("Round to (minutes)", d.roundTo, { d = d.copy(roundTo = it) }, Modifier.weight(1f)) }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { Field("Overtime after (h/day)", d.otDaily, { d = d.copy(otDaily = it) }, Modifier.weight(1f)); Field("Overtime after (h/week)", d.otWeekly, { d = d.copy(otWeekly = it) }, Modifier.weight(1f)) }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { Field("Overtime ×", d.otMultiplier, { d = d.copy(otMultiplier = it) }, Modifier.weight(1f)); Field("Rest day ×", d.restMultiplier, { d = d.copy(restMultiplier = it) }, Modifier.weight(1f)); Field("Holiday ×", d.holidayMultiplier, { d = d.copy(holidayMultiplier = it) }, Modifier.weight(1f)) }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { Field("Period ends on day", d.periodEndDay, { d = d.copy(periodEndDay = it) }, Modifier.weight(1f), placeholder = "10 = 11th–10th"); Field("Pay date day", d.payDateDay, { d = d.copy(payDateDay = it) }, Modifier.weight(1f)) }
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { Field("Unpaid break (min)", d.unpaidBreak, { d = d.copy(unpaidBreak = it) }, Modifier.weight(1f)); Field("…after hours", d.unpaidBreakAfter, { d = d.copy(unpaidBreakAfter = it) }, Modifier.weight(1f)) }
        ToggleRow("Default group", d.isDefault, { d = d.copy(isDefault = it) })
        ToggleRow("Active", d.isActive, { d = d.copy(isActive = it) })
        FieldError(e?.firstFieldError)
    }
}
