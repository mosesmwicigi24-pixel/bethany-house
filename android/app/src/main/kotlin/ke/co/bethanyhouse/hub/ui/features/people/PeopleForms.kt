package ke.co.bethanyhouse.hub.ui.features.people

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Archive
import androidx.compose.material.icons.filled.Checklist
import androidx.compose.material.icons.filled.RemoveCircleOutline
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.get
import ke.co.bethanyhouse.hub.core.humanized
import ke.co.bethanyhouse.hub.core.int
import ke.co.bethanyhouse.hub.core.records
import ke.co.bethanyhouse.hub.core.str
import ke.co.bethanyhouse.hub.core.toRecords
import ke.co.bethanyhouse.hub.ui.Palette
import ke.co.bethanyhouse.hub.ui.Space
import ke.co.bethanyhouse.hub.ui.StatusTone
import ke.co.bethanyhouse.hub.ui.components.ConfirmButton
import ke.co.bethanyhouse.hub.ui.components.Day
import ke.co.bethanyhouse.hub.ui.components.DayField
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.FieldError
import ke.co.bethanyhouse.hub.ui.components.FormSheet
import ke.co.bethanyhouse.hub.ui.components.InfoSheet
import ke.co.bethanyhouse.hub.ui.components.J
import ke.co.bethanyhouse.hub.ui.components.KeyValueGrid
import ke.co.bethanyhouse.hub.ui.components.LoadingSkeleton
import ke.co.bethanyhouse.hub.ui.components.OutletChoice
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Pill
import ke.co.bethanyhouse.hub.ui.components.RemoteJson
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.SupportListRow
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.toastResult
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

/** Checklists & forms, the manager's side (People C4): today's overview per outlet, templates and the submissions log. */
@Composable
fun FormsManageScreen() {
    val env = LocalEnv.current
    var tab by remember { mutableStateOf("overview") }
    var date by remember { mutableStateOf(Day.today()) }
    var version by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<FormTemplateDraft?>(null) }
    var openSubmission by remember { mutableStateOf<Int?>(null) }
    Page("Checklists", subtitle = "Daily checklists and reports across the outlets") {
        SegmentedTabs(listOf("overview" to "Today", "templates" to "Templates", "submissions" to "Submissions"), tab) { tab = it }
        when (tab) {
            "overview" -> {
                DayField("Day", date, { date = it })
                RemoteJson("/v1/admin/forms/overview", query = mapOf("date" to date), version = version, emptyTitle = "No outlets with forms", isEmpty = { it["outlets"].records.isEmpty() }) { j, _ ->
                    j["outlets"].toRecords().forEach { o ->
                        SectionCard(o["outlet"]["name"].str ?: "Outlet") {
                            val s = o["summary"]
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                Pill("${s["done"].int ?: 0} done", StatusTone.SUCCESS)
                                Pill("${s["late"].int ?: 0} late", StatusTone.WARNING)
                                Pill("${s["missed"].int ?: 0} missed", StatusTone.DANGER)
                                Pill("${s["pending"].int ?: 0} pending", StatusTone.AMBER)
                                if ((s["skipped"].int ?: 0) > 0) Pill("${s["skipped"].int} skipped required", StatusTone.PINK)
                                Pill("${s["reports"].int ?: 0} reports", StatusTone.INFO)
                            }
                            o["checklists"].toRecords().forEach { c ->
                                Row(Modifier.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                        Text(c["template"]["name"].str ?: "Checklist", style = MaterialTheme.typography.bodyMedium)
                                        Text("due ${c["template"]["due_time"].str ?: "—"}" + (c["submission"]["user"]["name"].str?.let { " · by $it" } ?: ""), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    }
                                    StatusBadge(c["state"].str, stateTone(c["state"].str))
                                    c["submission"]["id"].int?.let { sid -> TextButton(onClick = { openSubmission = sid }) { Text("View") } }
                                }
                            }
                            o["reports"].toRecords().forEach { r ->
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                                    Column(Modifier.weight(1f)) {
                                        Text(r["template"]["name"].str ?: "Report", style = MaterialTheme.typography.bodyMedium)
                                        Text(r["user"]["name"].str ?: "", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                                    }
                                    StatusBadge(r.status)
                                    TextButton(onClick = { openSubmission = r.int("id") ?: 0 }) { Text("View") }
                                }
                            }
                        }
                    }
                }
            }
            "templates" -> {
                Button(onClick = { editing = FormTemplateDraft() }) { Text("New form") }
                RemoteJson("/v1/admin/forms/templates", version = version, emptyTitle = "No forms yet", isEmpty = { it["data"].records.isEmpty() }) { j, reload ->
                    j["data"].toRecords().forEach { t ->
                        val sub = "${t.string("kind")?.humanized ?: ""} · ${t["outlet"]["name"].str ?: "Every outlet"} · " + (if (t.string("schedule") == "daily") "daily" + (t.string("due_time")?.let { " by $it" } ?: "") else "on demand") + " · ${t["items"].records.size} items"
                        SupportListRow(t.string("name") ?: "Form", sub) {
                            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                    if (t["active"].bool == false) Pill("retired", StatusTone.NEUTRAL)
                                    if (t["requires_signature"].bool == true) Pill("signed", StatusTone.INFO)
                                }
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    TextButton(onClick = { editing = FormTemplateDraft.from(t) }) { Text("Edit") }
                                    if (t["active"].bool != false) ConfirmButton("Retire form", "It stops being due; past submissions stay.", destructive = true, action = {
                                        try { val r = env.client.delete("/v1/admin/forms/templates/${t.id}"); toastResult(env, r); reload() } catch (e: Exception) { env.toasts.error(e) }
                                    }) { Icon(Icons.Default.Archive, contentDescription = "Retire") }
                                }
                            }
                        }
                    }
                }
            }
            "submissions" -> FormSubmissionsList { openSubmission = it }
        }
    }
    editing?.let { d -> FormTemplateSheet(d, onDismiss = { editing = null }) { version += 1 } }
    openSubmission?.let { id -> FormSubmissionSheet(id) { openSubmission = null } }
}

private fun stateTone(s: String?) = when (s) { "submitted" -> StatusTone.SUCCESS; "late" -> StatusTone.WARNING; "missed", "overdue" -> StatusTone.DANGER; "due" -> StatusTone.AMBER; else -> StatusTone.NEUTRAL }

private data class FormItemDraft(val uid: Long = System.nanoTime(), val key: String, val label: String, val type: String, val required: Boolean)

private data class FormTemplateDraft(
    val id: Int? = null, val name: String = "", val kind: String = "checklist", val outletId: Int? = null, val schedule: String = "daily",
    val weekdays: Set<Int> = setOf(1, 2, 3, 4, 5, 6), val dueTime: String = "09:00", val requiresSignature: Boolean = false, val active: Boolean = true,
    val items: List<FormItemDraft> = listOf(FormItemDraft(key = "item_1", label = "", type = "check", required = true)),
) {
    companion object {
        fun from(r: Record) = FormTemplateDraft(
            id = r.int("id"), name = r.string("name") ?: "", kind = r.string("kind") ?: "checklist", outletId = r["outlet"]["id"].int, schedule = r.string("schedule") ?: "none",
            weekdays = r["weekdays"].records.mapNotNull { it.int }.toSet().ifEmpty { setOf(1, 2, 3, 4, 5, 6) }, dueTime = r.string("due_time") ?: "",
            requiresSignature = r["requires_signature"].bool ?: false, active = r["active"].bool ?: true,
            items = r["items"].records.map { FormItemDraft(key = it["key"].str ?: "", label = it["label"].str ?: "", type = it["type"].str ?: "check", required = it["required"].bool ?: false) },
        )
    }
}

/** POST/PUT /forms/templates — {name, kind, outlet_id, schedule, weekdays, due_time, requires_signature, active, items[]} */
@Composable
private fun FormTemplateSheet(initial: FormTemplateDraft, onDismiss: () -> Unit, saved: () -> Unit) {
    val env = LocalEnv.current
    var d by remember { mutableStateOf(initial) }
    FormSheet(if (d.id == null) "New form" else "Edit form", onDismiss, canSave = d.name.isNotEmpty() && d.items.isNotEmpty(), save = {
        val body = J.obj(
            "name" to J.s(d.name), "kind" to J.s(d.kind), "outlet_id" to J.i(d.outletId), "schedule" to J.s(d.schedule),
            "weekdays" to (if (d.schedule == "daily") J.ints(d.weekdays.sorted()) else JsonNull),
            "due_time" to (if (d.schedule == "daily") J.s(d.dueTime) else JsonNull),
            "requires_signature" to J.b(d.requiresSignature), "active" to J.b(d.active),
            "items" to JsonArray(d.items.map { J.obj("key" to J.s(it.key), "label" to J.s(it.label), "type" to J.s(it.type), "required" to J.b(it.required)) }),
        )
        val id = d.id
        val response = if (id != null) env.client.put("/v1/admin/forms/templates/$id", body) else env.client.post("/v1/admin/forms/templates", body)
        toastResult(env, response)
        saved()
    }) { e ->
        Field("Name", d.name, { d = d.copy(name = it) }, error = e?.field("name"))
        PeopleChoice("Kind", d.kind, listOf("checklist" to "Checklist (due on a schedule)", "report" to "Report (filled when needed)"), { d = d.copy(kind = it) })
        OutletChoice("Outlet", d.outletId, { d = d.copy(outletId = it) }, allowAll = true)
        PeopleChoice("Schedule", d.schedule, listOf("daily" to "Daily", "none" to "No schedule"), { d = d.copy(schedule = it) })
        if (d.schedule == "daily") {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                (1..7).forEach { day ->
                    val on = d.weekdays.contains(day)
                    OutlinedButton(onClick = { d = d.copy(weekdays = if (on) d.weekdays - day else d.weekdays + day) }, colors = ButtonDefaults.outlinedButtonColors(contentColor = if (on) Palette.brand else Palette.neutral)) { Text(listOf("Mo", "Tu", "We", "Th", "Fr", "Sa", "Su")[day - 1]) }
                }
            }
            Field("Due by (HH:mm)", d.dueTime, { d = d.copy(dueTime = it) }, placeholder = "09:00", error = e?.field("due_time"))
        }
        ToggleRow("Requires a signature", d.requiresSignature, { d = d.copy(requiresSignature = it) })
        ToggleRow("Active", d.active, { d = d.copy(active = it) })
        Text("Items", style = MaterialTheme.typography.titleMedium)
        d.items.forEach { item ->
            fun update(x: FormItemDraft) { d = d.copy(items = d.items.map { if (it.uid == item.uid) x else it }) }
            Column(verticalArrangement = Arrangement.spacedBy(Space.xs)) {
                Field("Label", item.label, { update(item.copy(label = it)) })
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    PeopleChoice("Type", item.type, listOf("check" to "Check", "text" to "Text", "number" to "Number", "photo" to "Photo"), { update(item.copy(type = it)) }, Modifier.weight(1f))
                    Text("Req.", style = MaterialTheme.typography.labelSmall)
                    Switch(checked = item.required, onCheckedChange = { update(item.copy(required = it)) })
                    IconButton(onClick = { d = d.copy(items = d.items.filterNot { it.uid == item.uid }) }) { Icon(Icons.Default.RemoveCircleOutline, contentDescription = "Remove", tint = Palette.danger) }
                }
            }
        }
        FieldError(e?.field("items"))
        TextButton(onClick = { d = d.copy(items = d.items + FormItemDraft(key = "item_${d.items.size + 1}", label = "", type = "check", required = true)) }) { Text("Add item") }
    }
}

/** GET /forms/submissions?outlet_id&from&to&status&template_id&page → {data, has_more, page} */
@Composable
private fun FormSubmissionsList(open: (Int) -> Unit) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    var outlet by remember { mutableStateOf<Int?>(null) }
    var status by remember { mutableStateOf("") }
    var from by remember { mutableStateOf(Day.add(Day.today(), -14)) }
    var to by remember { mutableStateOf(Day.today()) }
    var page by remember { mutableIntStateOf(1) }
    var rows by remember { mutableStateOf<List<Record>>(emptyList()) }
    var hasMore by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    suspend fun load() {
        loading = true
        try {
            val j = env.client.get("/v1/admin/forms/submissions", mapOf("outlet_id" to outlet?.toString(), "status" to status.ifEmpty { null }, "from" to from, "to" to to, "page" to page.toString())).json
            val fresh = j["data"].toRecords()
            rows = if (page == 1) fresh else rows + fresh
            hasMore = j["has_more"].bool ?: false
        } catch (e: Exception) { env.toasts.error(e) } finally { loading = false }
    }
    LaunchedEffect(Unit) { if (rows.isEmpty()) load() }
    Column(verticalArrangement = Arrangement.spacedBy(Space.m)) {
        OutletChoice("Outlet", outlet, { outlet = it }, allowAll = true)
        PeopleChoice("Status", status, listOf("" to "Any", "submitted" to "Done", "late" to "Late", "missed" to "Missed", "flagged" to "Flagged"), { status = it })
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s)) { DayField("From", from, { from = it }, Modifier.weight(1f)); DayField("To", to, { to = it }, Modifier.weight(1f)) }
        Button(onClick = { page = 1; rows = emptyList(); scope.launch { load() } }, modifier = Modifier.fillMaxWidth()) { Text("Apply") }
        if (rows.isEmpty() && !loading) EmptyState("No submissions in this range", icon = Icons.Default.Checklist)
        rows.forEach { s ->
            SupportListRow(s["template"]["name"].str ?: "Form", "${s["outlet"]["name"].str ?: ""} · ${s["user"]["name"].str ?: "—"} · ${Day.format(s.string("for_date"))}", onClick = { open(s.int("id") ?: 0) }) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (s["flagged"].bool == true) Pill("flagged", StatusTone.WARNING)
                    if ((s.int("skipped_required") ?: 0) > 0) Pill("${s.int("skipped_required")} skipped", StatusTone.PINK)
                    StatusBadge(s.status)
                }
            }
        }
        if (hasMore) OutlinedButton(onClick = { page += 1; scope.launch { load() } }, enabled = !loading) { Text("Load more") }
        if (loading) CircularProgressIndicator(Modifier.padding(Space.s))
    }
}

/** GET /forms/submissions/{id} → {submission: {answers, notes, items, photo_keys, has_signature}} */
@Composable
private fun FormSubmissionSheet(id: Int, onDismiss: () -> Unit) {
    val env = LocalEnv.current
    var sub by remember { mutableStateOf<JsonElement?>(null) }
    LaunchedEffect(id) { sub = runCatching { env.client.get("/v1/admin/forms/submissions/$id").json["submission"] }.getOrNull() }
    InfoSheet("Submission #$id", onDismiss) {
        val s = sub
        if (s == null) { LoadingSkeleton(); return@InfoSheet }
        KeyValueGrid(listOf(
            "Form" to (s["template"]["name"].str ?: "—"), "Outlet" to (s["outlet"]["name"].str ?: "—"), "By" to (s["user"]["name"].str ?: "—"),
            "For" to Day.format(s["for_date"].str), "Submitted" to (s["submitted_at"].str?.let { Day.dateTime(it) } ?: "—"),
            "Status" to (s["status"].str?.humanized ?: "—"), "Signed" to (if (s["has_signature"].bool == true) "Yes" else "No"),
        ))
        val answers = s["answers"]
        val photoKeys = s["photo_keys"].records.mapNotNull { it.str }
        SectionCard("Answers") {
            s["items"].records.forEach { it ->
                val key = it["key"].str ?: ""
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                    Text(it["label"].str ?: key, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                    if (it["type"].str == "photo") {
                        if (photoKeys.contains(key)) PeopleSignedImage("/api/v1/admin/forms/submissions/$id/file/$key", 140.dp, Modifier.weight(1f))
                        else Text("No photo", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
                    } else {
                        val v = answers[key]
                        Text(v.bool?.let { b -> if (b) "✓" else "✗" } ?: v.str ?: "—", style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.End)
                    }
                }
            }
        }
        s["notes"].str?.takeIf { it.isNotEmpty() }?.let { SectionCard("Notes") { Text(it, style = MaterialTheme.typography.bodySmall) } }
        if (s["has_signature"].bool == true) SectionCard("Signature") { PeopleSignedImage("/api/v1/admin/forms/submissions/$id/file/signature", 120.dp) }
    }
}
