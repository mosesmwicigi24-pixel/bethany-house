package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ChevronLeft
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.jsonOf
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.Avatar
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.DateStyle
import ke.co.bethanyhouse.hub.ui.components.DateText
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StaffPicker
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub

/** Leave, team side (People PR 4): requests to decide, the month's calendar, recording leave for someone, and the leave types (hr.edit). */
private data class LeaveDecision(val id: Int, val approve: Boolean, val summary: String)

@Composable
fun LeaveAdminScreen() {
    var tab by remember { mutableStateOf("requests") }
    var status by remember { mutableStateOf("pending") }
    var month by remember { mutableStateOf(Day.firstOfMonth(Day.today())) }
    var version by remember { mutableIntStateOf(0) }
    var recording by remember { mutableStateOf(false) }
    var deciding by remember { mutableStateOf<LeaveDecision?>(null) }
    Page("Leave", subtitle = "Time off across the team", actions = { Button(onClick = { recording = true }) { Text("Record leave") } }) {
        SegmentedTabs(listOf("requests" to "Requests", "calendar" to "Calendar", "types" to "Types"), tab) { tab = it }
        when (tab) {
            "requests" -> {
                PeopleChoice("Status", status, listOf("pending" to "Pending", "approved" to "Approved", "declined" to "Declined", "cancelled" to "Cancelled", "" to "All"), { status = it })
                RemoteJson("/v1/admin/leave/requests", query = mapOf("status" to status.ifEmpty { null }), version = version, emptyTitle = "No ${if (status.isEmpty()) "" else "$status "}requests", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
                    j["data"].toRecords().forEach { r -> LeaveRequestCard(r, reload) { approve -> deciding = LeaveDecision(r.int("id") ?: 0, approve, peopleLeaveSummary(r)) } }
                }
            }
            "calendar" -> {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton(onClick = { month = Day.firstOfMonth(Day.add(month, -1)) }) { Icon(Icons.Default.ChevronLeft, contentDescription = "Previous month") }
                    Text(Day.format(month, "MMMM yyyy"), style = MaterialTheme.typography.titleMedium)
                    IconButton(onClick = { month = Day.firstOfMonth(Day.add(Day.lastOfMonth(month), 1)) }) { Icon(Icons.Default.ChevronRight, contentDescription = "Next month") }
                }
                RemoteJson("/v1/admin/leave/calendar", query = mapOf("from" to month, "to" to Day.lastOfMonth(month)), version = version, emptyTitle = "Nobody is away this month", isEmpty = { it["data"].records.isEmpty() }) { j, _ ->
                    j["data"].toRecords().sortedBy { it.string("starts_on") ?: "" }.forEach { r ->
                        SupportListRow(r["user"]["name"].str ?: "—", peopleLeaveSummary(r)) { StatusBadge(r.status) }
                    }
                }
            }
            "types" -> LeaveTypesPanel()
        }
    }
    if (recording) RecordLeaveSheet(userId = null, onDismiss = { recording = false }) { version += 1 }
    deciding?.let { d -> LeaveDecisionSheet(d, onDismiss = { deciding = null }) { version += 1 } }
}

@Composable
private fun LeaveRequestCard(request: Record, reload: () -> Unit, decide: (Boolean) -> Unit) {
    val env = LocalEnv.current
    SectionCard {
        Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Avatar(request["user"]["name"].str ?: "?")
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(request["user"]["name"].str ?: "—", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                Text(peopleLeaveSummary(request), style = MaterialTheme.typography.bodySmall)
                request.string("reason")?.takeIf { it.isNotEmpty() }?.let { Text("“$it”", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                val clash = request["roster_clash"].records
                if (clash.isNotEmpty()) Text("Rostered on ${clash.mapNotNull { it["date"].str?.let { d -> Day.format(d) } }.joinToString(", ")}", style = MaterialTheme.typography.labelSmall, color = Palette.warning)
                request.string("decided_by")?.let { by -> Text("${request.status?.humanized ?: ""} by $by" + (request.string("decision_note")?.let { " — $it" } ?: ""), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                if (request["evidence_document_id"].int != null) Pill("evidence attached", StatusTone.INFO)
            }
            Column(horizontalAlignment = Alignment.End) { StatusBadge(request.status); DateText(request.date("created_at"), DateStyle.RELATIVE) }
        }
        if (request.status == "pending" && (request["can_decide"].bool ?: env.session.current.can("leave.approve"))) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                OutlinedButton(onClick = { decide(false) }) { Text("Decline") }
                Button(onClick = { decide(true) }) { Text("Approve") }
            }
        } else if (request.status == "approved" || request.status == "pending") {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                ConfirmButton("Cancel leave", "The days go back to the person's balance.", destructive = true, action = {
                    try { val r = env.client.delete("/v1/admin/leave/requests/${request.id}", jsonOf()); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) }
                }) { Text("Cancel leave") }
            }
        }
    }
}

/** POST /leave/requests/{id}/approve|decline {note} */
@Composable
private fun LeaveDecisionSheet(d: LeaveDecision, onDismiss: () -> Unit, done: () -> Unit) {
    val env = LocalEnv.current
    var note by remember { mutableStateOf("") }
    FormSheet(if (d.approve) "Approve leave" else "Decline leave", onDismiss, saveLabel = if (d.approve) "Approve" else "Decline", destructive = !d.approve, save = {
        val r = env.client.post("/v1/admin/leave/requests/${d.id}/${if (d.approve) "approve" else "decline"}", J.obj("note" to J.sOpt(note)))
        toastResult(env, r)
        val clash = r.json["roster_clash"].records
        if (clash.isNotEmpty()) env.toasts.show("Approved — ${clash.size} rostered shift(s) now clash", StatusTone.WARNING)
        done()
    }) { e ->
        Text(d.summary, style = MaterialTheme.typography.bodySmall)
        Field("Note to the person", note, { note = it }, error = e?.field("note"), multiline = true)
    }
}

/** POST /leave/requests {user_id, leave_type_id, starts_on, ends_on, reason} — recorded as approved by the manager. */
@Composable
internal fun RecordLeaveSheet(userId: Int?, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var person by remember { mutableStateOf(userId) }
    var typeId by remember { mutableStateOf<Int?>(null) }
    var types by remember { mutableStateOf<List<Record>>(emptyList()) }
    var from by remember { mutableStateOf(Day.today()) }
    var to by remember { mutableStateOf(Day.today()) }
    var reason by remember { mutableStateOf("") }
    LaunchedEffect(Unit) { types = runCatching { env.client.get("/v1/admin/leave/types").json["data"].toRecords().filter { it["is_active"].bool != false } }.getOrDefault(emptyList()) }
    FormSheet("Record leave", onDismiss, canSave = person != null && typeId != null, save = {
        val r = env.client.post("/v1/admin/leave/requests", J.obj("user_id" to J.i(person), "leave_type_id" to J.i(typeId), "starts_on" to J.s(from), "ends_on" to J.s(to), "reason" to J.sOpt(reason)))
        toastResult(env, r); saved()
    }) { e ->
        if (userId == null) StaffPicker("Person", person, { person = it })
        PeopleIntChoice("Type", typeId, types.mapNotNull { t -> t.intId?.let { it to (t.string("name") ?: "") } }, { typeId = it })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { DayField("From", from, { from = it }, Modifier.weight(1f)); DayField("To", to, { to = it }, Modifier.weight(1f)) }
        FieldError(e?.field("ends_on") ?: e?.field("starts_on"))
        Field("Reason", reason, { reason = it }, multiline = true)
    }
}

/** GET /leave/types; PUT /leave/types/{id} (hr.edit) */
@Composable
private fun LeaveTypesPanel() {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<Record?>(null) }
    RemoteJson("/v1/admin/leave/types", version = version) { j, _ ->
        j["data"].toRecords().forEach { t ->
            val sub = (t["days_per_year"].str?.let { "$it days/year" } ?: "no cap") + " · " + (if (t.string("accrual") == "monthly") "accrues monthly" else "available up front") + " · " + (if (t["is_paid"].bool == true) "paid" else "unpaid") + " · " + (if (t["counts_calendar_days"].bool == true) "calendar days" else "working days") + (t["evidence_after_days"].int?.let { " · evidence after $it days" } ?: "")
            SupportListRow("${t.string("name") ?: ""} (${t.string("code") ?: ""})", sub) {
                Column(horizontalAlignment = Alignment.End) {
                    if (t["is_active"].bool == false) Pill("inactive", StatusTone.NEUTRAL)
                    if (env.session.current.can("hr.edit")) TextButton(onClick = { editing = t }) { Text("Edit") }
                }
            }
        }
    }
    editing?.let { t -> LeaveTypeSheet(t, onDismiss = { editing = null }) { version += 1 } }
}

@Composable
private fun LeaveTypeSheet(type: Record, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var name by remember { mutableStateOf(type.string("name") ?: "") }
    var days by remember { mutableStateOf(type["days_per_year"].str ?: "") }
    var accrual by remember { mutableStateOf(type.string("accrual") ?: "front") }
    var paid by remember { mutableStateOf(type["is_paid"].bool ?: true) }
    var calendarDays by remember { mutableStateOf(type["counts_calendar_days"].bool ?: false) }
    var evidenceAfter by remember { mutableStateOf(type["evidence_after_days"].str ?: "") }
    var active by remember { mutableStateOf(type["is_active"].bool ?: true) }
    FormSheet("Edit ${type.string("code") ?: "leave type"}", onDismiss, save = {
        val r = env.client.put("/v1/admin/leave/types/${type.id}", J.obj(
            "name" to J.s(name), "days_per_year" to J.typed(days), "accrual" to J.s(accrual), "is_paid" to J.b(paid),
            "counts_calendar_days" to J.b(calendarDays), "evidence_after_days" to J.typed(evidenceAfter), "is_active" to J.b(active),
        ))
        toastResult(env, r); saved()
    }) { e ->
        Field("Name", name, { name = it }, error = e?.field("name"))
        Field("Days per year (blank = no cap)", days, { days = it }, error = e?.field("days_per_year"))
        PeopleChoice("Accrual", accrual, listOf("front" to "All available up front", "monthly" to "Accrues monthly"), { accrual = it })
        ToggleRow("Paid", paid, { paid = it })
        ToggleRow("Counts calendar days (not working days)", calendarDays, { calendarDays = it })
        Field("Evidence required after (days)", evidenceAfter, { evidenceAfter = it })
        ToggleRow("Active", active, { active = it })
    }
}
