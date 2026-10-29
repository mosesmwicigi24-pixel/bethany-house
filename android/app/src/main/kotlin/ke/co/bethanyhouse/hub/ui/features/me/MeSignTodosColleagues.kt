package ke.co.bethanyhouse.hub.ui.features.me

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Draw
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.Icon
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ke.co.bethanyhouse.hub.LocalEnv
import ke.co.bethanyhouse.hub.core.ApiError
import ke.co.bethanyhouse.hub.core.Record
import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.core.bool
import ke.co.bethanyhouse.hub.core.date
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
import ke.co.bethanyhouse.hub.ui.components.EmptyState
import ke.co.bethanyhouse.hub.ui.components.Field
import ke.co.bethanyhouse.hub.ui.components.Loadable
import ke.co.bethanyhouse.hub.ui.components.LoadableView
import ke.co.bethanyhouse.hub.ui.components.ModuleSearchField
import ke.co.bethanyhouse.hub.ui.components.Page
import ke.co.bethanyhouse.hub.ui.components.Resource
import ke.co.bethanyhouse.hub.ui.components.SectionCard
import ke.co.bethanyhouse.hub.ui.components.SegmentedTabs
import ke.co.bethanyhouse.hub.ui.components.SelectField
import ke.co.bethanyhouse.hub.ui.components.StatusBadge
import ke.co.bethanyhouse.hub.ui.components.ToggleRow
import ke.co.bethanyhouse.hub.ui.components.listRecords
import ke.co.bethanyhouse.hub.ui.hub
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull

// ── To sign (SignPage.tsx): what I have been asked to sign, and what I signed or declined ──

@Composable
internal fun SignScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val list = remember { Resource(scope) { env.client.get("/v1/admin/me/sign").json ?: JsonNull } }
    LaunchedEffect(Unit) { list.load() }
    val subtitle = list.value?.let { d -> val n = d["pending"].int ?: 0; if (n > 0) "$n waiting for your signature." else "Nothing waiting." }

    Page("To sign", subtitle = subtitle, refresh = { list.reload() }) {
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.listRecords.isEmpty() }, empty = { EmptyState("Nobody has asked you to sign anything", icon = Icons.Default.Draw) }) { d ->
            MeListCard {
                d.listRecords.forEach { r ->
                    MeListRow(onClick = { env.navigator.push(Route.Sign(r.intId)) }) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(r.string("title") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            val meta = buildString {
                                r.string("requested_by")?.let { append("From $it") }
                                if (r.status == "pending") r.date("due_at")?.let { append(" · by ${MeFormat.dayYear(it)}") }
                                r.date("signed_at")?.let { append(" · signed ${MeFormat.dayYear(it)}") }
                            }
                            Text(meta, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        }
                        StatusBadge(if (r.status == "pending") "Sign" else r.status, if (r.status == "pending") StatusTone.DANGER else StatusTone.forStatus(r.status))
                        Icon(Icons.Default.ChevronRight, contentDescription = null, tint = MaterialTheme.hub.textTertiary, modifier = Modifier.size(16.dp))
                    }
                }
            }
        }
    }
}

/**
 * One request: read the text or open the document, then sign with the pad
 * (`POST /me/sign/{id}/sign {signature: PNG data URL}`) or decline with a note.
 */
@Composable
internal fun SignDetailScreen(id: Int) {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val c = env.client
    val request = remember { Resource(scope) { Record(c.get("/v1/admin/me/sign/$id").json["request"] ?: JsonNull) } }
    var signature by remember { mutableStateOf<String?>(null) }
    var declining by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { request.load() }

    suspend fun sign() {
        val sig = signature ?: return
        busy = true
        try { val r = c.post("/v1/admin/me/sign/$id/sign", jsonOf("signature" to sig)); env.toasts.show(r.json["message"].str ?: "Signed"); env.navigator.go(Route.Sign()) } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun decline() {
        busy = true
        try { val r = c.post("/v1/admin/me/sign/$id/decline", jsonOf("note" to note.trim())); env.toasts.show(r.json["message"].str ?: "Sent"); env.navigator.go(Route.Sign()) } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }

    Page(request.value?.string("title") ?: "Sign", refresh = { request.reload() }) {
        LoadableView(request.state, retry = { request.load() }) { r ->
            SectionCard {
                Text("${r.string("requested_by")?.let { "Asked by $it on ${MeFormat.dayYear(r.date("created_at"))}" } ?: ""}${r.date("due_at")?.let { " · by ${MeFormat.dayYear(it)}" } ?: ""}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                if (r.string("kind") == "document") {
                    val url = r["document_link"]["url"].str
                    if (url != null) TextButton(onClick = { openExternal(context, if (url.startsWith("/")) env.config.hubUrl.trimEnd('/') + url else url) }) {
                        Icon(Icons.Default.Description, contentDescription = null, modifier = Modifier.size(18.dp)); Text(" Open the document to read it", fontWeight = FontWeight.Medium)
                    } else Text("The document is no longer on your file.", color = Palette.danger)
                } else {
                    Box(Modifier.fillMaxWidth().heightIn(max = 320.dp).background(MaterialTheme.hub.field, MaterialTheme.shapes.small).padding(Space.m).verticalScroll(rememberScrollState())) {
                        SelectionContainer { Text(r.string("body") ?: "", style = MaterialTheme.typography.bodyMedium) }
                    }
                }
                if (r.status == "pending") {
                    if (declining) {
                        MeFieldLabel("Why are you not signing?") { OutlinedTextField(value = note, onValueChange = { note = it }, minLines = 3, modifier = Modifier.fillMaxWidth()) }
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(Space.s, Alignment.End)) {
                            OutlinedButton(onClick = { declining = false }) { Text("Back") }
                            Button(onClick = { scope.launch { decline() } }, enabled = !busy && note.trim().length >= 3) { Text("Send") }
                        }
                    } else {
                        Text("Your signature", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                        SignaturePad(signature, { signature = it })
                        Text("By signing you confirm you have read and agree to the above. A signing record goes on your file.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            TextButton(onClick = { declining = true }) { Text("I can't sign this", color = Palette.danger, style = MaterialTheme.typography.labelSmall) }
                            Spacer(Modifier.weight(1f))
                            Button(onClick = { scope.launch { sign() } }, enabled = !busy && signature != null) { Text("Sign") }
                        }
                    }
                } else {
                    MeNotice(
                        when (r.status) {
                            "signed" -> "Signed on ${MeFormat.dayYear(r.date("signed_at"))}. The record is among your documents."
                            "declined" -> "Declined: ${r.string("note") ?: ""}"
                            else -> "Cancelled by HR."
                        },
                        if (r.status == "signed") StatusTone.SUCCESS else StatusTone.NEUTRAL,
                    )
                }
            }
        }
    }
}

// ── To-dos (TodosPage.tsx): mine, the ones I gave, and the team's for a manager ──

@Composable
internal fun TodosScreen() {
    val env = LocalEnv.current
    val coScope = rememberCoroutineScope()
    var todoScope by remember { mutableStateOf("mine") }
    var status by remember { mutableStateOf("open") }
    val list = remember { Resource(coScope) { env.client.get("/v1/admin/todos", mapOf("scope" to todoScope, "status" to status)).json ?: JsonNull } }
    var adding by remember { mutableStateOf(false) }
    var doing by remember { mutableStateOf<Record?>(null) }
    var doneNote by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val canGive = list.value["can_give"].bool ?: false
    LaunchedEffect(todoScope, status) { list.reload() }

    suspend fun update(t: Record, fields: JsonElement) {
        val r = env.client.patch("/v1/admin/todos/${t.id}", fields)
        env.toasts.show(r.json["message"].str ?: "Saved")
        list.reload()
    }
    suspend fun updateSafely(t: Record, fields: JsonElement) {
        busy = true
        try { update(t, fields) } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    suspend fun remove(t: Record) {
        try { env.client.delete("/v1/admin/todos/${t.id}"); list.reload() } catch (e: ApiError) { env.toasts.error(e) }
    }

    val subtitle = list.value?.let { d -> val overdue = d["overdue"].int ?: 0; val open = d["open"].int ?: 0; if (overdue > 0) "$overdue overdue · $open open" else "$open open" }

    Page("To-dos", subtitle = subtitle, refresh = { list.reload() }) {
        Column(verticalArrangement = Arrangement.spacedBy(Space.s)) {
            SegmentedTabs(listOf("mine" to "Mine", "given" to "I gave") + (if (canGive) listOf("team" to "The team") else emptyList()), todoScope) { todoScope = it }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Space.s)) {
                Box(Modifier.weight(1f)) { SegmentedTabs(listOf("open" to "open", "done" to "done", "all" to "all"), status) { status = it } }
                Button(onClick = { adding = true }) { Text(if (canGive) "New to-do" else "Add") }
            }
        }
        LoadableView(list.state, retry = { list.load() }, isEmpty = { it.listRecords.isEmpty() }, empty = { EmptyState("Nothing here", icon = Icons.Default.CheckCircle) }) { d ->
            MeListCard {
                d.listRecords.forEach { t ->
                    TodoRow(t, todoScope, busy,
                        onToggle = { if ((t.status ?: "open") == "open") { doneNote = ""; doing = t } else coScope.launch { updateSafely(t, jsonOf("status" to "open")) } },
                        onCancel = { coScope.launch { updateSafely(t, jsonOf("status" to "cancelled")) } },
                        onRemove = { coScope.launch { remove(t) } })
                }
            }
        }
    }
    if (adding) AddTodoSheet(canGive, onDismiss = { adding = false }, onDone = { adding = false; list.load() })
    doing?.let { t ->
        MeSheet(t.string("title") ?: "Done", primary = "Done", onDismiss = { doing = null }, action = {
            update(t, jsonOf("status" to "done", "done_note" to doneNote.trim().ifEmpty { null }))
            doing = null
        }) {
            val who = t["creator"]["name"].str?.let { if (!t.flag("gave")) "; $it will see it" else "" } ?: ""
            MeFieldLabel("Anything to say about it? (optional$who)") { OutlinedTextField(value = doneNote, onValueChange = { doneNote = it }, minLines = 3, modifier = Modifier.fillMaxWidth()) }
        }
    }
}

@Composable
private fun TodoRow(t: Record, scope: String, busy: Boolean, onToggle: () -> Unit, onCancel: () -> Unit, onRemove: () -> Unit) {
    val st = t.status ?: "open"
    val mine = t.flag("mine"); val gave = t.flag("gave"); val overdue = t.flag("overdue")
    val creatorIsOwner = t["creator"]["id"].int == t["user"]["id"].int
    val canToggle = st == "open" || ((mine || gave) && st != "cancelled")
    MeListRow {
        Row(Modifier.fillMaxWidth().alpha(if (st == "open") 1f else 0.6f), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.Top) {
            Box(
                Modifier.padding(top = 2.dp).size(24.dp).border(2.dp, if (st == "done") Palette.success else if (overdue) Palette.danger else MaterialTheme.hub.line, CircleShape)
                    .background(if (st == "done") Palette.success else Color.Transparent, CircleShape).clickable(enabled = canToggle && !busy, onClick = onToggle),
                contentAlignment = Alignment.Center,
            ) { if (st == "done") Icon(Icons.Default.Check, contentDescription = "Reopen", tint = Color.White, modifier = Modifier.size(14.dp)) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(t.string("title") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium, textDecoration = if (st == "done") TextDecoration.LineThrough else null)
                t.string("details")?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                FlowRow {
                    val creator = t["creator"]["name"].str
                    val prefix = (if (scope != "mine") t["user"]["name"].str?.let { "$it · " } ?: "" else "") + (if (creator != null && !gave && !creatorIsOwner) "from $creator · " else "")
                    if (prefix.isNotEmpty()) Text(prefix, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    val due = t.date("due_at")
                    if (due != null) Text("${if (overdue) "overdue · " else "by "}${MeFormat.dayTime(due)}", style = MaterialTheme.typography.labelSmall, color = if (overdue) Palette.danger else MaterialTheme.hub.textSecondary, fontWeight = if (overdue) FontWeight.SemiBold else FontWeight.Normal)
                    else Text("no due time", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                    val tail = when (st) { "done" -> " · done ${MeFormat.dayTime(t.date("done_at"))}${t.string("done_note")?.let { " — $it" } ?: ""}"; "cancelled" -> " · cancelled"; else -> "" }
                    if (tail.isNotEmpty()) Text(tail, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary)
                }
            }
            if (st == "open" && gave && !mine) TextButton(onClick = onCancel, enabled = !busy) { Text("Cancel", color = Palette.danger, style = MaterialTheme.typography.labelSmall) }
            if (st != "open" && (gave || (mine && (t["creator"].isNull || creatorIsOwner)))) TextButton(onClick = onRemove) { Text("Remove", color = MaterialTheme.hub.textTertiary, style = MaterialTheme.typography.labelSmall) }
        }
    }
}

/** New to-do (TodosController::store: title, details, due_at, user_id via todos/people). */
@Composable
private fun AddTodoSheet(canGive: Boolean, onDismiss: () -> Unit, onDone: () -> Unit) {
    val env = LocalEnv.current
    var title by remember { mutableStateOf("") }
    var details by remember { mutableStateOf("") }
    var hasDue by remember { mutableStateOf(false) }
    var dueAt by remember { mutableStateOf(MeFormat.nowLocal().plusHours(1)) }
    var userId by remember { mutableStateOf<Int?>(null) }
    var people by remember { mutableStateOf<List<Record>>(emptyList()) }
    LaunchedEffect(Unit) { if (canGive) try { people = env.client.get("/v1/admin/todos/people").json.listRecords } catch (e: ApiError) { env.toasts.error(e) } }

    suspend fun save() {
        val json = jsonOf(
            "title" to title.trim(),
            "details" to details.trim().ifEmpty { null },
            "due_at" to if (hasDue) MeFormat.dateTimeString(dueAt) else null,
            "user_id" to userId,
        )
        val r = env.client.post("/v1/admin/todos", json)
        env.toasts.show(r.json["message"].str ?: "Added")
        onDone()
    }

    MeSheet("New to-do", primary = if (userId == null) "Add" else "Give", onDismiss = onDismiss, disabled = title.isBlank(), action = { save() }) {
        Field("What", title, { title = it })
        Field("Details (optional)", details, { details = it }, multiline = true)
        ToggleRow("By when", hasDue, { hasDue = it })
        if (hasDue) MeDateTimeField("By when", dueAt) { dueAt = it }
        if (canGive) SelectField("For", userId, people.mapNotNull { p -> p.intId?.let { it to (p.string("name") ?: "") } }, { userId = it }, Modifier.fillMaxWidth(), placeholder = "Me")
    }
}

// ── Colleagues (DirectoryPage.tsx): who is who, how to reach them, my own switches, start a DM ──

@Composable
internal fun ColleaguesScreen() {
    val env = LocalEnv.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    var department by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val list = remember { Resource(scope) { env.client.get("/v1/admin/directory", mapOf("q" to query.trim().ifEmpty { null })).json ?: JsonNull } }
    LaunchedEffect(query) { if (list.state != Loadable.Idle) delay(300); list.reload() }

    suspend fun saveMine(key: String, value: Boolean) {
        busy = true
        try { val r = env.client.put("/v1/admin/me/directory", jsonOf(key to value)); env.toasts.show(r.json["message"].str ?: "Saved"); list.reload() } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }
    /** Open or find the DM (`POST channels/dm {user_id}`) and go to it. */
    suspend fun message(c: Record) {
        val uid = c.intId ?: return
        busy = true
        try {
            val r = env.client.post("/v1/admin/channels/dm", jsonOf("user_id" to uid))
            r.json["channel"]["id"].int?.let { env.navigator.go(Route.Comms(channelId = it)) }
        } catch (e: ApiError) { env.toasts.error(e) } finally { busy = false }
    }

    Page("Colleagues", subtitle = "Everyone at Bethany House and how to reach them.", refresh = { list.reload() }) {
        ModuleSearchField(query, { query = it }, "Search by name, job or department…", onSubmit = { list.load() })
        val depts = list.value["departments"].records.mapNotNull { it.str }
        if (depts.isNotEmpty()) SelectField("Department", department.ifEmpty { null }, depts.map { it to it }, { department = it ?: "" }, Modifier.fillMaxWidth(), placeholder = "All departments")
        list.value["me"].obj?.let { me ->
            SectionCard {
                Text("What colleagues see about you:", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.l)) {
                    listOf("show_phone" to "my phone", "show_email" to "my email", "show_birthday" to "my birthday").forEach { (key, label) ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Checkbox(checked = me[key].bool ?: false, onCheckedChange = { v -> scope.launch { saveMine(key, v) } }, enabled = !busy)
                            Text(label, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
                Text("Your name, job, department and outlet always show.", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary)
            }
        }
        LoadableView(list.state, retry = { list.load() }) { d ->
            val rows = d.listRecords.filter { department.isEmpty() || it.string("department") == department }
            if (rows.isEmpty()) Text("Nobody matches.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.hub.textSecondary, modifier = Modifier.fillMaxWidth())
            rows.forEach { c ->
                val isMe = c.flag("is_me")
                Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.hub.card, modifier = Modifier.fillMaxWidth().border(1.dp, if (isMe) Palette.brandTint else MaterialTheme.hub.line, MaterialTheme.shapes.large)) {
                    Row(Modifier.padding(Space.l), horizontalArrangement = Arrangement.spacedBy(Space.m), verticalAlignment = Alignment.Top) {
                        PersonAvatar(c.string("name"), c.string("avatar_url"), size = 48.dp)
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(c.string("name") ?: "", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                if (isMe) StatusBadge("you", StatusTone.INFO)
                            }
                            val line = listOfNotNull(c.string("job_title"), c.string("department")).joinToString(" · ")
                            val outlets = c.strings("outlets")
                            Text("${line.ifEmpty { "—" }}${if (outlets.isEmpty()) "" else " · ${outlets.joinToString(", ")}"}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                                c.string("phone")?.let { phone ->
                                    Text("📞 $phone", style = MaterialTheme.typography.labelSmall, color = Palette.info, modifier = Modifier.clickable { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + phone.filter { !it.isWhitespace() }))) } })
                                }
                                c.string("email")?.let { email ->
                                    Text("✉️ $email", style = MaterialTheme.typography.labelSmall, color = Palette.info, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.clickable { runCatching { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:$email"))) } })
                                }
                            }
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.m)) {
                                c.string("birthday")?.let { Text("🎂 $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textSecondary) }
                                c.string("since")?.let { Text("since $it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.hub.textTertiary) }
                            }
                            if (!isMe) TextButton(onClick = { scope.launch { message(c) } }, enabled = !busy, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { Text("Message ›", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold) }
                        }
                    }
                }
            }
        }
    }
}
