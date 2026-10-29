package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
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
import androidx.compose.ui.unit.sp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Money
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.isNull
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.AsyncButton
import ke.co.bethanyhouse.hub.ui.components.AsyncTextButton
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.MoneyText
import ke.co.bethanyhouse.hub.ui.components.OutletChoice
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.StaffPicker
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.minutesText
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/**
 * The weekly roster (People PR 3): a members × days grid per outlet with
 * open shifts, add/edit/remove, copy last week, publish, shift templates
 * and swap decisions. Labour cost is shown only as the server estimated it.
 */
@Composable
fun RosterScreen() {
    val env = LocalEnv.current
    var outlet by remember { mutableStateOf<Int?>(null) }
    var week by remember { mutableStateOf(Day.monday(Day.today())) }
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<ShiftDraft?>(null) }
    var showTemplates by remember { mutableStateOf(false) }
    Page("Roster", subtitle = "Week of ${Day.format(week, "d MMM yyyy")}") {
        OutletChoice("Outlet", outlet, { outlet = it })
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { week = Day.add(week, -7) }) { Icon(Icons.Default.ChevronLeft, contentDescription = "Previous week") }
            TextButton(onClick = { week = Day.monday(Day.today()) }) { Text("This week") }
            IconButton(onClick = { week = Day.add(week, 7) }) { Icon(Icons.Default.ChevronRight, contentDescription = "Next week") }
        }
        val o = outlet
        if (o == null) EmptyState("Choose an outlet")
        else RemoteJson("/v1/admin/roster", query = mapOf("outlet_id" to o.toString(), "week" to week), version = version) { j, reload ->
            val canManage = j["can_manage"].bool ?: env.session.current.can("roster.manage")
            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                Pill("${j["open_count"].int ?: 0} open", StatusTone.AMBER)
                Pill("${j["draft_count"].int ?: 0} draft", StatusTone.NEUTRAL)
            }
            if (canManage) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s), verticalArrangement = Arrangement.spacedBy(Space.s), modifier = Modifier.fillMaxWidth()) {
                OutlinedButton(onClick = { editing = ShiftDraft(date = week) }) { Text("Add shift") }
                OutlinedButton(onClick = { editing = ShiftDraft(date = week, userId = null) }) { Text("Open shift") }
                OutlinedButton(onClick = { showTemplates = true }) { Text("Templates") }
                AsyncButton("Copy last week", outlined = true) {
                    val r = env.client.post("/v1/admin/roster/copy-week", J.obj("outlet_id" to J.i(o), "from_week" to J.s(Day.add(week, -7)), "to_week" to J.s(week)))
                    toastResult(env, r); reload()
                }
                ConfirmButton("Publish week", "Everyone on the roster is told their shifts for this week.", enabled = (j["draft_count"].int ?: 0) > 0, action = {
                    try { val r = env.client.post("/v1/admin/roster/publish", J.obj("outlet_id" to J.i(o), "week" to J.s(week))); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) }
                }) { Text("Publish") }
            }
            RosterGrid(j, canManage) { editing = it }
            val cost = j["cost"]
            if (!cost.isNull) SectionCard("Labour cost (estimate)") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Total", modifier = Modifier.weight(1f))
                    MoneyText(Money.from(cost["total"], cost["currency"].str ?: "KES"), emphasis = true)
                }
                val uncosted = cost["uncosted_shifts"].int ?: 0
                Text((cost["basis"].str ?: "") + (if (uncosted > 0) " · $uncosted shifts without a pay structure" else ""), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
            val swaps = j["pending_swaps"].toRecords()
            if (swaps.isNotEmpty()) SectionCard("Swap requests (${swaps.size})") {
                swaps.forEach { s ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("${s["from_user"]["name"].str ?: "—"} → ${s["to_user"]["name"].str ?: "anyone"}", style = MaterialTheme.typography.bodyMedium)
                        val sh = s["shift"]
                        if (!sh.isNull) Text("${Day.format(sh["date"].str)} ${sh["starts_at"].str ?: ""}–${sh["ends_at"].str ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        s.string("note")?.takeIf { it.isNotEmpty() }?.let { Text("“$it”", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                        if (canManage) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                            AsyncTextButton("Decline", color = Palette.danger) { val r = env.client.post("/v1/admin/roster/swaps/${s.id}/decline"); toastResult(env, r); reload() }
                            AsyncButton("Approve") { val r = env.client.post("/v1/admin/roster/swaps/${s.id}/approve"); toastResult(env, r); reload() }
                        }
                    }
                }
            }
        }
    }
    editing?.let { d -> ShiftSheet(d, outletId = outlet ?: 0, onDismiss = { editing = null }) { version += 1 } }
    if (showTemplates) ShiftTemplatesSheet(outletId = outlet ?: 0) { showTemplates = false }
}

internal data class ShiftDraft(
    val id: Int? = null,
    val userId: Int? = null,
    val date: String,
    val startsAt: String = "08:00",
    val endsAt: String = "17:00",
    val label: String = "",
    val notes: String = "",
    val publish: Boolean = false,
    val status: String? = null,
) {
    companion object {
        fun from(s: Record) = ShiftDraft(
            id = s.int("id"), userId = s.int("user_id"), date = s.string("date") ?: Day.today(),
            startsAt = s.string("starts_at") ?: "08:00", endsAt = s.string("ends_at") ?: "17:00",
            label = s.string("label") ?: "", notes = s.string("notes") ?: "", status = s.status,
        )
    }
}

/** members × days, scrolling sideways on a phone. Each cell lists that person's shifts; the last row is open shifts. */
@Composable
private fun RosterGrid(week: JsonElement?, canManage: Boolean, edit: (ShiftDraft) -> Unit) {
    val days = week["days"].records.mapNotNull { it.str }
    val members = week["members"].toRecords()
    val shifts = week["shifts"].toRecords()
    val holidays = week["holidays"]; val leave = week["leave"]; val hours = week["hours_by_user"]
    val cellW = 140.dp
    Column(Modifier.horizontalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            Spacer(Modifier.width(cellW))
            days.forEach { d ->
                Column(Modifier.width(cellW), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(Day.format(d), style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold)
                    holidays[d].str?.let { Text(it, fontSize = 9.sp, color = Palette.pink, maxLines = 1) }
                }
            }
        }
        members.forEach { m ->
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.Top) {
                Column(Modifier.width(cellW), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(m.string("name") ?: "—", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, maxLines = 1)
                    Text("${m.string("job_title") ?: ""} · ${minutesText(hours[m.id].int ?: 0)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 1)
                }
                days.forEach { d ->
                    RosterCell(shifts.filter { it.string("user_id") == m.id && it.string("date") == d }, d, m.int("id"), leave[m.id][d], false, canManage, cellW, edit)
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.Top) {
            Text("Open shifts", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, color = Palette.amber, modifier = Modifier.width(cellW))
            days.forEach { d ->
                RosterCell(shifts.filter { it["user_id"].isNull && it.string("date") == d }, d, null, null, true, canManage, cellW, edit)
            }
        }
    }
}

@Composable
private fun RosterCell(list: List<Record>, date: String, userId: Int?, leave: JsonElement?, open: Boolean, canManage: Boolean, width: androidx.compose.ui.unit.Dp, edit: (ShiftDraft) -> Unit) {
    Column(Modifier.width(width).heightIn(min = 44.dp).background(MaterialTheme.hub.card, MaterialTheme.shapes.extraSmall).border(1.dp, MaterialTheme.hub.line, MaterialTheme.shapes.extraSmall).padding(3.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        if (leave != null && !leave.isNull) Text("${leave["type"].str ?: "Leave"}${if (leave["status"].str == "pending") " (pending)" else ""}", fontSize = 10.sp, color = Palette.accent)
        list.forEach { s ->
            val published = s.status == "published"
            Column(
                Modifier.fillMaxWidth()
                    .background(if (published) Palette.successSoft else if (open) Palette.amberSoft else Palette.neutralSoft, MaterialTheme.shapes.extraSmall)
                    .clickable(enabled = canManage) { edit(ShiftDraft.from(s)) }
                    .padding(4.dp),
                verticalArrangement = Arrangement.spacedBy(1.dp),
            ) {
                Text("${s.string("starts_at") ?: ""}–${s.string("ends_at") ?: ""}", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = if (published) Palette.success else Palette.ink)
                s.string("label")?.takeIf { it.isNotEmpty() }?.let { Text(it, fontSize = 10.sp, maxLines = 1, color = if (published) Palette.success else Palette.ink) }
            }
        }
        if (canManage) Box(Modifier.clickable { edit(ShiftDraft(date = date, userId = if (open) null else userId)) }.padding(2.dp)) {
            Icon(Icons.Default.Add, contentDescription = "Add shift", tint = MaterialTheme.hub.textTertiary, modifier = Modifier.width(14.dp))
        }
    }
}

/** POST /roster/shifts, PUT/DELETE /roster/shifts/{id} */
@Composable
private fun ShiftSheet(initial: ShiftDraft, outletId: Int, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var draft by remember { mutableStateOf(initial) }
    var templates by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { templates = runCatching { env.client.get("/v1/admin/roster/templates", mapOf("outlet_id" to outletId.toString())).json["data"].toRecords() }.getOrDefault(emptyList()) }
    val title = if (draft.id == null) (if (draft.userId == null) "Add open shift" else "Add shift") else "Edit shift"
    FormSheet(title, onDismiss, save = {
        val id = draft.id
        val response = if (id != null) env.client.put("/v1/admin/roster/shifts/$id", J.obj(
            "user_id" to J.i(draft.userId), "date" to J.s(draft.date), "starts_at" to J.s(draft.startsAt), "ends_at" to J.s(draft.endsAt),
            "label" to J.s(draft.label), "notes" to J.s(draft.notes),
        )) else env.client.post("/v1/admin/roster/shifts", J.obj(
            "outlet_id" to J.i(outletId), "user_id" to J.i(draft.userId), "date" to J.s(draft.date), "starts_at" to J.s(draft.startsAt), "ends_at" to J.s(draft.endsAt),
            "label" to J.s(draft.label), "notes" to J.s(draft.notes), "publish" to J.b(draft.publish),
        ))
        toastResult(env, response)
        saved()
    }) { e ->
        if (templates.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.xs), verticalArrangement = Arrangement.spacedBy(Space.xs)) {
            Text("Template", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.padding(top = 10.dp))
            templates.forEach { t ->
                OutlinedButton(onClick = { draft = draft.copy(startsAt = t.string("starts_at") ?: draft.startsAt, endsAt = t.string("ends_at") ?: draft.endsAt, label = t.string("label") ?: draft.label) }) { Text(t.string("name") ?: "") }
            }
        }
        StaffPicker("Person", draft.userId, { draft = draft.copy(userId = it) }, allowNone = true)
        DayField("Date", draft.date, { draft = draft.copy(date = it) })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) {
            Field("Starts (HH:mm)", draft.startsAt, { draft = draft.copy(startsAt = it) }, Modifier.weight(1f), error = e?.field("starts_at"))
            Field("Ends (HH:mm)", draft.endsAt, { draft = draft.copy(endsAt = it) }, Modifier.weight(1f), error = e?.field("ends_at"))
        }
        Field("Label", draft.label, { draft = draft.copy(label = it) }, placeholder = "Morning till")
        Field("Notes", draft.notes, { draft = draft.copy(notes = it) }, multiline = true)
        if (draft.id == null) ToggleRow("Publish straight away", draft.publish, { draft = draft.copy(publish = it) })
        draft.status?.let { s -> Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) { Text("Status", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary); StatusBadge(s) } }
        draft.id?.let { id ->
            ConfirmButton("Remove shift", "A published shift is cancelled and the person is told.", destructive = true, action = {
                try { val r = env.client.delete("/v1/admin/roster/shifts/$id"); toastResult(env, r); saved(); onDismiss() } catch (ex: Exception) { env.toasts.error(ex) }
            }) { Text("Remove shift") }
        }
    }
}

/** GET/POST/DELETE /roster/templates */
@Composable
private fun ShiftTemplatesSheet(outletId: Int, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var version by remember { mutableIntStateOf(0) }
    var name by remember { mutableStateOf("") }
    var starts by remember { mutableStateOf("08:00") }
    var ends by remember { mutableStateOf("17:00") }
    var label by remember { mutableStateOf("") }
    var here by remember { mutableStateOf(true) }
    val canManage = env.session.current.can("roster.manage")
    InfoSheet("Shift templates", onDismiss) {
        RemoteJson("/v1/admin/roster/templates", query = mapOf("outlet_id" to outletId.toString()), version = version, emptyTitle = "No templates yet", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
            j["data"].toRecords().forEach { t ->
                SupportListRow(t.string("name") ?: "", "${t.string("starts_at") ?: ""}–${t.string("ends_at") ?: ""}" + (t.string("label")?.let { " · $it" } ?: "") + (if (t["outlet_id"].isNull) " · every outlet" else "")) {
                    if (canManage) AsyncTextButton("Delete", color = Palette.danger) { val r = env.client.delete("/v1/admin/roster/templates/${t.id}"); toastResult(env, r); reload() }
                }
            }
        }
        if (canManage) {
            Text("New template", style = MaterialTheme.typography.titleMedium)
            Field("Name", name, { name = it }, placeholder = "Morning")
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { Field("Starts", starts, { starts = it }, Modifier.weight(1f)); Field("Ends", ends, { ends = it }, Modifier.weight(1f)) }
            Field("Label", label, { label = it })
            ToggleRow("Only this outlet", here, { here = it })
            AsyncButton("Add template", enabled = name.isNotEmpty()) {
                val r = env.client.post("/v1/admin/roster/templates", J.obj("outlet_id" to (if (here) J.i(outletId) else JsonNull), "name" to J.s(name), "starts_at" to J.s(starts), "ends_at" to J.s(ends), "label" to J.s(label)))
                toastResult(env, r); name = ""; version += 1
            }
        }
    }
}
